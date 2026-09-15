<?php
declare(strict_types=1);

/**
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

function ai_decode_response_body($body): ?array
{
    if (!is_string($body) || $body === '') {
        return null;
    }
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    $data = json_decode($body, true);
    if (is_array($data)) {
        return $data;
    }
    if (preg_match('/\{.*\}/s', $body, $m)) {
        $data = json_decode($m[0], true);
        return is_array($data) ? $data : null;
    }
    return null;
}

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
    if (defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    return $opts;
}

/** @return array{body:?string,status:int,error:?string} */
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
 * Call Python Isolation Forest service.
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

function ai_health_ping(int $timeout = 2): bool
{
    $configured = (string) app_config('ai_health_endpoint');
    $endpoints = ['http://127.0.0.1:5001/health'];
    if ($configured !== '' && !preg_match('#://127\.0\.0\.1:5001(/|$)#', $configured)) {
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

function ai_health(): bool
{
    if (ai_health_ping(2)) {
        return true;
    }
    ai_try_start_local();
    return ai_health_ping(3);
}

function openrouter_configured(): bool
{
    return trim((string) app_config('openrouter_api_key', '')) !== ''
        || trim((string) app_config('backup_ai_api_key', '')) !== '';
}

/**
 * Ordered LLM gateways: OpenRouter first, NaraRouter backup second.
 *
 * @return list<array{name:string,api_key:string,base_url:string,model:string}>
 */
function ai_llm_providers(): array
{
    $providers = [];
    $primaryKey = trim((string) app_config('openrouter_api_key', ''));
    if ($primaryKey !== '') {
        $providers[] = [
            'name' => 'openrouter',
            'api_key' => $primaryKey,
            'base_url' => rtrim((string) app_config('openrouter_base_url', 'https://openrouter.ai/api/v1'), '/'),
            'model' => (string) app_config('openrouter_model', 'openai/gpt-4o-mini'),
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

    $viaService = ai_chat_via_service($payload);
    if (!empty($viaService['ok'])) {
        return $viaService;
    }

    // Fallback: call providers from PHP when Python chat is down
    if (openrouter_configured()) {
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
            ?? 'AI chat is unavailable. Start the Python AI service and set OPENROUTER_API_KEY or BACKUP_AI_API_KEY.',
    ];
}

/**
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
 * Try OpenRouter then NaraRouter (OpenAI-compatible).
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

    $system = 'You are the AI assistant for an AI-Assisted Laboratory Information System (AI-LIS) '
        . 'used by Laboratory Managers and Medical Technologists. Help with LIS workflow, '
        . 'Isolation Forest soft warnings (advisory only), reference ranges, and lab operations. '
        . 'Do not invent patient results or replace clinical judgment. Keep answers concise. '
        . 'Caller role: ' . ($payload['role'] ?? 'lab_staff') . '.';

    $messages = [['role' => 'system', 'content' => $system]];
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
    foreach ($providers as $provider) {
        $last = llm_chat_completions($provider, $messages);
        if (!empty($last['ok'])) {
            return $last;
        }
    }
    return $last;
}

/** @deprecated Use llm_chat_direct() */
function openrouter_chat_direct(array $payload): array
{
    return llm_chat_direct($payload);
}

/**
 * @param array{name:string,api_key:string,base_url:string,model:string} $provider
 * @param list<array{role:string,content:string}> $messages
 * @return array{ok:bool,reply?:string,model?:string,provider?:string,error?:string,detail?:string}
 */
function llm_chat_completions(array $provider, array $messages): array
{
    $timeout = (int) app_config('ai_chat_timeout_seconds', 45);
    $name = $provider['name'];

    $ch = curl_init($provider['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $provider['api_key'],
            'HTTP-Referer: ' . (app_config('base_url') ?: 'https://ailab-lis.local'),
            'X-Title: AI-Assisted LIS',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $provider['model'],
            'messages' => $messages,
            'temperature' => 0.4,
            'max_tokens' => 700,
        ]),
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
