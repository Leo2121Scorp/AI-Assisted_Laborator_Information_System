<?php
declare(strict_types=1);

/**
 * Call Python Isolation Forest service.
 *
 * @return array{ok:bool,is_anomaly:bool,score:?float,warning_message:?string,model_version:?string,raw:?array,error?:string}
 */
function ai_predict(array $payload): array
{
    $endpoint = app_config('ai_endpoint');
    $timeout = (int) app_config('ai_timeout_seconds', 5);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $body === false) {
        return [
            'ok' => false,
            'is_anomaly' => false,
            'score' => null,
            'warning_message' => 'AI service unavailable — manual review required.',
            'model_version' => null,
            'raw' => null,
            'error' => $error ?: 'curl_error',
        ];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'is_anomaly' => false,
            'score' => null,
            'warning_message' => 'AI service returned invalid JSON — manual review required.',
            'model_version' => null,
            'raw' => null,
            'error' => 'invalid_json',
        ];
    }

    if ($status >= 400 || empty($data['ok'])) {
        return [
            'ok' => false,
            'is_anomaly' => false,
            'score' => null,
            'warning_message' => $data['detail'] ?? 'AI service error — manual review required.',
            'model_version' => $data['model_version'] ?? null,
            'raw' => $data,
            'error' => $data['error'] ?? 'http_' . $status,
        ];
    }

    return [
        'ok' => true,
        'is_anomaly' => !empty($data['is_anomaly']),
        'score' => isset($data['score']) ? (float) $data['score'] : null,
        'warning_message' => $data['warning_message'] ?? null,
        'model_version' => $data['model_version'] ?? null,
        'raw' => $data,
    ];
}

function ai_health(): bool
{
    $endpoint = app_config('ai_health_endpoint');
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        return false;
    }
    $data = json_decode($body, true);
    return is_array($data) && !empty($data['ok']);
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
