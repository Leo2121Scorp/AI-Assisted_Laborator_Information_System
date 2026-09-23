<?php
declare(strict_types=1);

/**
 * AI helpers: Isolation Forest + chat.
 *
 * This file talks to the Python AI service (anomaly checks on CBC) and to LLM APIs
 * (Groq first, NaraRouter as backup) for the "Ask AI" chat box.
 * If AI is down, encoding still works — we return a clear "unavailable" result instead of crashing.
 */

/**
 * Build a safe "AI unavailable" response so callers can keep going.
 *
 * @return array{ok:bool,is_anomaly:bool,score:?float,warning_message:?string,model_version:?string,raw:?array,error?:string}
 */
function ai_unavailable(string $error, ?string $detail = null): array
{
    return [
        'ok' => false,
        'is_anomaly' => false,
        'score' => null,
        'warning_message' => $detail ?: 'AI service unavailable — encode is saved; Isolation Forest was not run.',
        'model_version' => null,
        'raw' => null,
        'error' => $error,
    ];
}

/**
 * Turn a raw HTTP body into a PHP array.
 * Strips a UTF-8 BOM and, if needed, finds the first JSON object in messy text.
 */
function ai_decode_response_body($body): ?array
{
    if (!is_string($body) || $body === '') {
        return null;
    }
    // Some servers prepend a BOM; remove it so json_decode works
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    $data = json_decode($body, true);
    if (is_array($data)) {
        return $data;
    }
    // Fallback: extract {...} from a larger string (e.g. HTML error page wrapper)
    if (preg_match('/\{.*\}/s', $body, $m)) {
        $data = json_decode($m[0], true);
        return is_array($data) ? $data : null;
    }
    return null;
}

/** Shared curl options for AI HTTP calls. */
function ai_curl_opts(int $timeout): array
{
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
    ];
    // Prefer IPv4 when available — avoids some local Windows DNS quirks
    if (defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    return $opts;
}

/**
 * Simple HTTP GET (curl if available, otherwise file_get_contents).
 *
 * @return array{body:?string,status:int,error:?string}
 */
function ai_http_get(string $url, int $timeout): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, ai_curl_opts($timeout));
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno || $body === false) {
            return ['body' => null, 'status' => $status, 'error' => $error ?: 'curl_error'];
        }
        return ['body' => is_string($body) ? $body : null, 'status' => $status, 'error' => null];
    }

    // Fallback without curl (rare on production hosts)
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($body === false) {
        return ['body' => null, 'status' => $status, 'error' => 'http_get_failed'];
    }
    return ['body' => $body, 'status' => $status, 'error' => null];
}

// --- Local Python process helpers (dev machines) ---

/** Find a working Python command on this computer. */
function ai_find_python(): ?string
{
    $candidates = PHP_OS_FAMILY === 'Windows'
        ? ['py -3', 'py', 'python', 'python3']
        : ['python3', 'python'];
    foreach ($candidates as $bin) {
        $out = [];
        $code = 1;
        @exec($bin . ' --version 2>&1', $out, $code);
        if ($code === 0) {
            return $bin;
        }
    }
    return null;
}

/**
 * Try to start the local Python AI app once per PHP process.
 * Uses a short lock file so many page loads do not spawn many servers.
 */
function ai_try_start_local(): void
{
    static $attempted = false;
    if ($attempted) {
        return;
    }
    $attempted = true;

    $aiDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ai';
    $app = $aiDir . DIRECTORY_SEPARATOR . 'app.py';
    if (!is_file($app)) {
        return;
    }

    // Skip if we tried recently (within 90 seconds)
    $lock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ailab_iforest.start.lock';
    if (is_file($lock) && (time() - (int) filemtime($lock)) < 90) {
        return;
    }

    $python = ai_find_python();
    if ($python === null) {
        return;
    }

    @file_put_contents($lock, (string) time());

    if (PHP_OS_FAMILY === 'Windows') {
        $inner = 'cd /d ' . escapeshellarg($aiDir) . ' && ' . $python . ' app.py';
        @pclose(@popen('cmd /c start /B "" cmd /c ' . escapeshellarg($inner), 'r'));
        return;
    }

    @exec('cd ' . escapeshellarg($aiDir) . ' && ' . $python . ' app.py >/dev/null 2>&1 &');
}

/**
 * Call the Python Isolation Forest /predict endpoint.
 * Tries the configured URL and also local http://127.0.0.1:5001/predict.
 *
 * @return array{ok:bool,is_anomaly:bool,score:?float,warning_message:?string,model_version:?string,raw:?array,error?:string}
 */
function ai_predict(array $payload): array
{
    $timeout = (int) app_config('ai_timeout_seconds', 5);
    $configured = (string) app_config('ai_endpoint');
    $endpoints = [];
    if ($configured !== '') {
        $endpoints[] = $configured;
    }
    // Always try local first when the configured URL is not already local
    if (!preg_match('#://127\.0\.0\.1:5001(/|$)#', $configured)) {
        array_unshift($endpoints, 'http://127.0.0.1:5001/predict');
    }

    $last = ai_unavailable('ai_unreachable');
    foreach (array_unique($endpoints) as $endpoint) {
        if (!function_exists('curl_init')) {
            $last = ai_unavailable('curl_missing', 'PHP curl extension is required for Isolation Forest.');
            continue;
        }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, ai_curl_opts($timeout) + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $body === false) {
            $last = ai_unavailable($error ?: 'curl_error');
            continue;
        }

        $data = ai_decode_response_body($body);
        if (!is_array($data)) {
            $hint = $status > 0 ? "HTTP {$status}" : 'empty/non-JSON body';
            $last = ai_unavailable('invalid_json', "AI service unavailable ({$hint}) — Isolation Forest was not run.");
            continue;
        }

        if ($status >= 400 || empty($data['ok'])) {
            $last = [
                'ok' => false,
                'is_anomaly' => false,
                'score' => null,
                'warning_message' => $data['detail'] ?? 'AI service error — Isolation Forest was not run.',
                'model_version' => $data['model_version'] ?? null,
                'raw' => $data,
                'error' => $data['error'] ?? 'http_' . $status,
            ];
            // Retry next endpoint on network / server errors; stop on client errors
            if ($status === 0 || $status >= 500) {
                continue;
            }
            return $last;
        }

        return [
            'ok' => true,
            'is_anomaly' => !empty($data['is_anomaly']),
            'score' => isset($data['score']) && is_numeric($data['score']) ? (float) $data['score'] : null,
            'warning_message' => $data['warning_message'] ?? null,
            'model_version' => $data['model_version'] ?? null,
            'raw' => $data,
        ];
    }

    return $last;
}

/** Ping /health endpoints; true if any look healthy. */
function ai_health_ping(int $timeout = 2): bool
{
    $configured = (string) app_config('ai_health_endpoint');
    $endpoints = [];
    $local = 'http://127.0.0.1:' . (ailab_app_env('AI_PORT') ?: '5001') . '/health';
    $endpoints[] = $local;
    if ($configured !== '' && $configured !== $local) {
        $endpoints[] = $configured;
    }
    foreach (array_unique($endpoints) as $endpoint) {
        $res = ai_http_get($endpoint, $timeout);
        if ($res['body'] === null || $res['status'] !== 200) {
            continue;
        }
        $data = ai_decode_response_body($res['body']);
        if (is_array($data) && (!empty($data['ok']) || array_key_exists('model_loaded', $data))) {
            return true;
        }
    }
    return false;
}

/**
 * Check AI health; if down, try starting the local Python app once, then ping again.
 */
function ai_health(): bool
{
    if (ai_health_ping(3)) {
        return true;
    }
    ai_try_start_local();
    return ai_health_ping(5);
}

// --- LLM chat (Groq / backup) ---

/** True if at least one chat API key is set in config. */
function groq_configured(): bool
{
    return trim((string) app_config('groq_api_key', '')) !== ''
        || trim((string) app_config('backup_ai_api_key', '')) !== '';
}

/** @deprecated Use groq_configured() */
function openrouter_configured(): bool
{
    return groq_configured();
}

/**
 * Ordered LLM gateways: Groq first, NaraRouter backup second.
 *
 * @return list<array{name:string,api_key:string,base_url:string,model:string}>
 */
function ai_llm_providers(): array
{
    $providers = [];
    $primaryKey = trim((string) app_config('groq_api_key', ''));
    if ($primaryKey !== '') {
        $providers[] = [
            'name' => 'groq',
            'api_key' => $primaryKey,
            'base_url' => rtrim((string) app_config('groq_base_url', 'https://api.groq.com/openai/v1'), '/'),
            'model' => (string) app_config('groq_model', 'openai/gpt-oss-120b'),
        ];
    }
    $backupKey = trim((string) app_config('backup_ai_api_key', ''));
    if ($backupKey !== '') {
        $providers[] = [
            'name' => 'nararouter',
            'api_key' => $backupKey,
            'base_url' => rtrim((string) app_config('backup_ai_base_url', 'https://router.bynara.id/v1'), '/'),
            'model' => (string) app_config('backup_ai_model', 'auto/bynara'),
        ];
    }
    return $providers;
}

/**
 * Lab assistant chat via Python /chat (preferred) or direct LLM fallbacks.
 *
 * @param list<array{role:string,content:string}> $history
 * @return array{ok:bool,reply?:string,model?:string,provider?:string,error?:string,detail?:string}
 */
function ai_chat(string $message, array $history = [], ?string $role = null): array
{
    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'empty_message', 'detail' => 'Message is required.'];
    }

    $payload = [
        'message' => $message,
        'history' => array_values($history),
        'role' => $role ?? (user_role() ?? 'lab_staff'),
    ];

    // Prefer the Python chat service (same process as Isolation Forest)
    $viaService = ai_chat_via_service($payload);
    if (!empty($viaService['ok'])) {
        return $viaService;
    }

    // Fallback: call providers from PHP when Python chat is down
    if (groq_configured()) {
        $direct = llm_chat_direct($payload);
        if (!empty($direct['ok'])) {
            return $direct;
        }
        return [
            'ok' => false,
            'error' => $direct['error'] ?? 'chat_failed',
            'detail' => $direct['detail'] ?? 'Chat request failed.',
        ];
    }

    return [
        'ok' => false,
        'error' => $viaService['error'] ?? 'chat_unavailable',
        'detail' => $viaService['detail']
            ?? 'AI chat is unavailable. Start the Python AI service and set GROQ_API_KEY or BACKUP_AI_API_KEY.',
    ];
}

/**
 * POST to the Python /chat endpoint.
 *
 * @param array{message:string,history:list,role:string} $payload
 * @return array{ok:bool,reply?:string,model?:string,error?:string,detail?:string}
 */
function ai_chat_via_service(array $payload): array
{
    $endpoint = (string) app_config('ai_chat_endpoint');
    $timeout = (int) app_config('ai_chat_timeout_seconds', 45);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $body === false) {
        return ['ok' => false, 'error' => 'service_unreachable', 'detail' => $error ?: 'AI chat service unreachable'];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'invalid_json', 'detail' => 'Invalid chat response'];
    }
    if ($status >= 400 || empty($data['ok'])) {
        return [
            'ok' => false,
            'error' => $data['error'] ?? ('http_' . $status),
            'detail' => $data['detail'] ?? 'Chat service error',
        ];
    }

    return [
        'ok' => true,
        'reply' => (string) ($data['reply'] ?? ''),
        'model' => $data['model'] ?? null,
        'provider' => $data['provider'] ?? null,
    ];
}

/**
 * Try Groq then NaraRouter (OpenAI-compatible chat completions).
 *
 * @param array{message:string,history:list,role:string} $payload
 * @return array{ok:bool,reply?:string,model?:string,provider?:string,error?:string,detail?:string}
 */
function llm_chat_direct(array $payload): array
{
    $providers = ai_llm_providers();
    if ($providers === []) {
        return ['ok' => false, 'error' => 'llm_not_configured', 'detail' => 'No LLM API keys configured'];
    }

    // System prompt tells the model who it is helping and what not to invent
    $system = 'You are the AI assistant for an AI-Assisted Laboratory Information System (AI-LIS) '
        . 'used by Laboratory Managers and Medical Technologists. Help with LIS workflow, '
        . 'Isolation Forest soft warnings (advisory only), reference ranges, and lab operations. '
        . 'Do not invent patient results or replace clinical judgment. Keep answers concise. '
        . 'Caller role: ' . ($payload['role'] ?? 'lab_staff') . '.';

    $messages = [['role' => 'system', 'content' => $system]];
    // Keep only the last 12 history turns, and cap each message length
    foreach (array_slice($payload['history'] ?? [], -12) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $r = (string) ($item['role'] ?? '');
        $c = trim((string) ($item['content'] ?? ''));
        if (($r === 'user' || $r === 'assistant') && $c !== '') {
            $messages[] = ['role' => $r, 'content' => substr($c, 0, 4000)];
        }
    }
    $messages[] = ['role' => 'user', 'content' => substr((string) $payload['message'], 0, 4000)];

    $last = ['ok' => false, 'error' => 'all_providers_failed', 'detail' => 'All LLM providers failed'];
    $primary = null;
    foreach ($providers as $provider) {
        $last = llm_chat_completions($provider, $messages);
        if (
            empty($last['ok'])
            && ($provider['name'] ?? '') === 'groq'
            && llm_failure_is_retired_model($last)
            && ($provider['model'] ?? '') !== 'openai/gpt-oss-120b'
        ) {
            $provider['model'] = 'openai/gpt-oss-120b';
            $last = llm_chat_completions($provider, $messages);
        }
        if (!empty($last['ok'])) {
            return $last;
        }
        if (($provider['name'] ?? '') === 'groq') {
            $primary = $last;
        }
        // NaraRouter's "bind Telegram" gate is not an answer. Keep Groq's error instead.
        if (llm_failure_is_account_gate($last)) {
            continue;
        }
    }
    return llm_public_failure($primary ?? $last);
}

/** True when the gateway wants an account link instead of a chat reply. */
function llm_failure_is_account_gate(array $result): bool
{
    $blob = strtolower(($result['error'] ?? '') . ' ' . ($result['detail'] ?? ''));
    return str_contains($blob, 'telegram_required') || str_contains($blob, 'bind your telegram');
}

/** True when Groq says the configured model id is gone. */
function llm_failure_is_retired_model(array $result): bool
{
    $blob = strtolower(($result['error'] ?? '') . ' ' . ($result['detail'] ?? ''));
    return str_contains($blob, 'does not exist')
        || str_contains($blob, 'decommissioned')
        || str_contains($blob, 'model_not_found')
        || str_contains($blob, 'model_decommissioned');
}

/**
 * Short chat-bubble text. Hides raw gateway instructions such as Telegram binding.
 *
 * @param array{ok:bool,error?:string,detail?:string} $result
 * @return array{ok:bool,error?:string,detail?:string}
 */
function llm_public_failure(array $result): array
{
    $blob = strtolower(($result['error'] ?? '') . ' ' . ($result['detail'] ?? ''));
    if (llm_failure_is_account_gate($result)) {
        $result['error'] = 'backup_not_linked';
        $result['detail'] = 'The assistant could not reply. The backup AI account is not linked, and Groq did not answer.';
        return $result;
    }
    if (str_contains($blob, 'invalid api key') || str_contains($blob, 'invalid_api_key')) {
        $result['error'] = 'invalid_api_key';
        $result['detail'] = 'Groq rejected the API key. Update GROQ_API_KEY, then try again.';
        return $result;
    }
    if (llm_failure_is_retired_model($result)) {
        $result['error'] = 'model_unavailable';
        $result['detail'] = 'The configured Groq model is no longer available.';
        return $result;
    }
    return $result;
}

/** @deprecated Use llm_chat_direct() */
function openrouter_chat_direct(array $payload): array
{
    return llm_chat_direct($payload);
}

/**
 * One OpenAI-style /chat/completions request to a single provider.
 *
 * @param array{name:string,api_key:string,base_url:string,model:string} $provider
 * @param list<array{role:string,content:string}> $messages
 * @return array{ok:bool,reply?:string,model?:string,provider?:string,error?:string,detail?:string}
 */
function llm_chat_completions(array $provider, array $messages): array
{
    $timeout = (int) app_config('ai_chat_timeout_seconds', 45);
    $name = $provider['name'];

    $body = [
        'model' => $provider['model'],
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => 700,
    ];
    // gpt-oss spends the token budget on reasoning unless this is set low.
    if (str_starts_with((string) $provider['model'], 'openai/gpt-oss')) {
        $body['reasoning_effort'] = 'low';
        $body['max_completion_tokens'] = 700;
    }

    $ch = curl_init($provider['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $provider['api_key'],
            'HTTP-Referer: ' . (app_config('base_url') ?: 'https://ailab-lis.local'),
            'X-Title: AI-Assisted LIS',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $body === false) {
        return ['ok' => false, 'error' => 'curl_error', 'detail' => ($error ?: 'Request failed') . " ({$name})"];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'invalid_json', 'detail' => "Invalid response ({$name})"];
    }
    if ($status >= 400) {
        $detail = $data['error']['message'] ?? ($data['error'] ?? ('HTTP ' . $status));
        if (is_array($detail)) {
            $detail = json_encode($detail);
        }
        return ['ok' => false, 'error' => 'llm_http_error', 'detail' => (string) $detail . " ({$name})"];
    }

    $reply = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($reply === '') {
        return ['ok' => false, 'error' => 'empty_response', 'detail' => "No reply ({$name})"];
    }

    return [
        'ok' => true,
        'reply' => $reply,
        'model' => $data['model'] ?? $provider['model'],
        'provider' => $name,
    ];
}

/**
 * Isolation Forest was trained on hemoglobin in g/dL.
 * The encode form stores g/L (134 on the sheet is 13.4 for the model).
 *
 * @param array<string, mixed> $features
 * @return array<string, mixed>
 */
function cbc_features_for_model(array $features): array
{
    if (isset($features['HGB']) && is_numeric($features['HGB']) && (float) $features['HGB'] > 30) {
        $features['HGB'] = round((float) $features['HGB'] / 10, 2);
    }
    return $features;
}

/**
 * Names and units for the five CBC counts the Isolation Forest model uses.
 *
 * @return array<string, array{name:string, unit:string}>
 */
function explain_cbc_tests(): array
{
    return [
        'WBC' => ['name' => 'White cells', 'unit' => 'x10^9/L'],
        'RBC' => ['name' => 'Red cells', 'unit' => 'x10^12/L'],
        'HGB' => ['name' => 'Hemoglobin', 'unit' => 'g/L'],
        'HCT' => ['name' => 'Hematocrit', 'unit' => '%'],
        'PLT' => ['name' => 'Platelets', 'unit' => 'x10^9/L'],
    ];
}

/**
 * Reference range for one CBC analyte, or null if the catalog has no match.
 */
function explain_cbc_range(string $code, string $sex, int $age): ?array
{
    try {
        $stmt = db()->prepare(
            "SELECT rr.min_value, rr.max_value, rr.critical_low, rr.critical_high
             FROM reference_ranges rr
             JOIN lab_tests lt ON lt.id = rr.lab_test_id
             WHERE lt.panel_code = 'CBC' AND lt.test_code = ?
               AND rr.age_min <= ? AND rr.age_max >= ?
               AND (rr.sex = ? OR rr.sex = 'A')
             ORDER BY CASE WHEN rr.sex = ? THEN 1 WHEN rr.sex = 'A' THEN 2 ELSE 3 END
             LIMIT 1"
        );
        $stmt->execute([$code, $age, $age, $sex, $sex]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Plain-language band for an Isolation Forest score.
 * Lower / more negative means the panel looks more unusual.
 *
 * @return array{key:string, label:string, plain:string}
 */
function explain_score_story(?float $score, bool $isAnomaly, bool $aiOk): array
{
    if (!$aiOk) {
        return [
            'key' => 'offline',
            'label' => 'AI not reached',
            'plain' => 'The model did not answer, so this panel was not scored. The range check still ran. A person reviews the result either way.',
        ];
    }
    if ($score === null) {
        return [
            'key' => 'unknown',
            'label' => 'No score',
            'plain' => 'The service replied without a score. Review the numbers by hand.',
        ];
    }
    if (!$isAnomaly && $score >= 0.05) {
        return [
            'key' => 'normal',
            'label' => 'Looks typical',
            'plain' => 'Taken together, these numbers look like a typical CBC. No AI warning. A person still reviews before the report is released.',
        ];
    }
    if (!$isAnomaly) {
        return [
            'key' => 'borderline',
            'label' => 'Close to the edge',
            'plain' => 'This panel sits near the edge of typical, and the model did not flag it. A quick recheck of the entry is wise.',
        ];
    }
    if ($score >= -0.10) {
        return [
            'key' => 'mild',
            'label' => 'Mild warning',
            'plain' => 'The combination is a little unusual. Recheck the entry. This is a warning only — a person still approves or rejects.',
        ];
    }
    if ($score >= -0.25) {
        return [
            'key' => 'moderate',
            'label' => 'Clear warning',
            'plain' => 'The whole panel stands out from typical CBCs. Review the numbers and the clinical picture. The AI does not make the decision.',
        ];
    }
    return [
        'key' => 'severe',
        'label' => 'Strong warning',
        'plain' => 'This panel is far from typical CBCs. Check for a typing error, the wrong patient, or a truly extreme sample. A person must still approve or reject.',
    ];
}

/** Map a score onto a 0–100 meter. Left is unusual, right is typical. */
function explain_meter_percent(?float $score): float
{
    if ($score === null) {
        return 50.0;
    }
    $clamped = max(-0.45, min(0.30, $score));
    return round((($clamped + 0.45) / 0.75) * 100, 1);
}

/**
 * Where the "this panel" dot sits in the typical-cloud picture.
 * Farther from the center means more unusual. The path is always the same
 * direction so the picture stays easy to read.
 *
 * @return array{x:float, y:float, outside:bool}
 */
function explain_point_position(?float $score, bool $isAnomaly): array
{
    $clamped = max(-0.45, min(0.30, $score ?? 0.0));
    $t = (0.30 - $clamped) / 0.75;
    $radius = 12 + $t * 50;
    if ($isAnomaly) {
        $radius = max($radius, 48);
    }
    $angle = -0.85;
    return [
        'x' => round(50 + cos($angle) * $radius, 1),
        'y' => round(50 + sin($angle) * $radius, 1),
        'outside' => $isAnomaly || $radius > 42,
    ];
}

/**
 * Score one CBC panel for the visual explainer.
 * Does not save a result and does not approve anything.
 *
 * @param array<string, mixed> $features
 * @return array<string, mixed>
 */
function explain_cbc_panel(array $features, string $sex, int $age): array
{
    $sex = strtoupper(trim($sex));
    if (!in_array($sex, ['M', 'F'], true)) {
        return ['ok' => false, 'message' => 'Choose sex as M or F. The model uses sex together with the blood counts.'];
    }
    if ($age < 0 || $age > 120) {
        return ['ok' => false, 'message' => 'Age must be between 0 and 120.'];
    }

    $clean = [];
    foreach (explain_cbc_tests() as $code => $meta) {
        if (!isset($features[$code]) || $features[$code] === '' || !is_numeric($features[$code])) {
            return ['ok' => false, 'message' => $meta['name'] . ' (' . $code . ') needs a number.'];
        }
        $value = (float) $features[$code];
        if ($value < 0) {
            return ['ok' => false, 'message' => $meta['name'] . ' cannot be negative.'];
        }
        if ($value > 100000) {
            return ['ok' => false, 'message' => $meta['name'] . ' is too large to score.'];
        }
        $clean[$code] = $value;
    }

    $rules = [];
    foreach (explain_cbc_tests() as $code => $meta) {
        $value = $clean[$code];
        $range = explain_cbc_range($code, $sex, $age);
        $min = isset($range['min_value']) && $range['min_value'] !== null ? (float) $range['min_value'] : null;
        $max = isset($range['max_value']) && $range['max_value'] !== null ? (float) $range['max_value'] : null;
        $critLow = isset($range['critical_low']) && $range['critical_low'] !== null ? (float) $range['critical_low'] : null;
        $critHigh = isset($range['critical_high']) && $range['critical_high'] !== null ? (float) $range['critical_high'] : null;
        $flag = 'no_range';
        $note = 'No reference range stored for this age and sex.';
        if ($min !== null && $max !== null) {
            $critical = ($critLow !== null && $value < $critLow) || ($critHigh !== null && $value > $critHigh);
            $oor = $value < $min || $value > $max;
            if ($critical) {
                $flag = 'critical';
                $note = 'Past the critical limit.';
            } elseif ($oor) {
                $flag = 'oor';
                $note = $value < $min ? 'Below the reference range.' : 'Above the reference range.';
            } else {
                $flag = 'normal';
                $note = 'Inside the reference range.';
            }
        }
        $plot = ['marker' => 50.0, 'band_left' => 20.0, 'band_width' => 60.0];
        if ($min !== null && $max !== null && $max > $min) {
            $span = $max - $min;
            $lo = $min - $span * 0.55;
            $hi = $max + $span * 0.55;
            $plot = [
                'marker' => round(max(3, min(97, (($value - $lo) / ($hi - $lo)) * 100)), 1),
                'band_left' => round((($min - $lo) / ($hi - $lo)) * 100, 1),
                'band_width' => round((($max - $min) / ($hi - $lo)) * 100, 1),
            ];
        }
        $rules[] = [
            'code' => $code,
            'name' => $meta['name'],
            'unit' => $meta['unit'],
            'value' => $value,
            'min' => $min,
            'max' => $max,
            'flag' => $flag,
            'note' => $note,
            'marker' => $plot['marker'],
            'band_left' => $plot['band_left'],
            'band_width' => $plot['band_width'],
        ];
    }

    $ai = ai_predict([
        'features' => cbc_features_for_model($clean),
        'patient_sex' => $sex,
        'patient_age' => $age,
        'test_code' => 'CBC',
        'explain' => false,
    ]);
    $aiOk = !empty($ai['ok']);
    $isAnomaly = $aiOk && !empty($ai['is_anomaly']);
    $score = $aiOk ? $ai['score'] : null;
    $story = explain_score_story($score, $isAnomaly, $aiOk);

    return [
        'ok' => true,
        'saved' => false,
        'sex' => $sex,
        'age' => $age,
        'features' => $clean,
        'rules' => $rules,
        'ai_ok' => $aiOk,
        'is_anomaly' => $isAnomaly,
        'score' => $score,
        'warning_message' => $aiOk ? ($ai['warning_message'] ?? null) : ($ai['warning_message'] ?? null),
        'model_version' => $ai['model_version'] ?? null,
        'story' => $story,
        'meter' => explain_meter_percent($score),
        'point' => explain_point_position($score, $isAnomaly),
    ];
}
