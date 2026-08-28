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
