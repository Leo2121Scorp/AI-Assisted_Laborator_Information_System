<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$sibling = 'https://ailab-ai.onrender.com/health';
$origin = ai_http_get($sibling, 2);
agent_dbg_ai_log('H1', 'scripts/_probe_ai_health.php:origin-style', 'Render sibling /health as deployed PHP (2s)', [
    'host' => (string) (parse_url($sibling, PHP_URL_HOST) ?: ''),
    'status' => $origin['status'],
    'error' => $origin['error'],
    'body_len' => is_string($origin['body']) ? strlen($origin['body']) : 0,
    'would_be_online' => $origin['status'] === 200 && is_array(ai_decode_response_body($origin['body'] ?? '')),
]);

$ok = ai_health();
agent_dbg_ai_log('H5', 'scripts/_probe_ai_health.php:head-ai_health', 'Local HEAD ai_health()', [
    'ok' => $ok,
    'configured_host' => (string) (parse_url((string) app_config('ai_health_endpoint'), PHP_URL_HOST) ?: ''),
]);

echo json_encode(['origin_status' => $origin['status'], 'origin_error' => $origin['error'], 'head_ok' => $ok], JSON_UNESCAPED_UNICODE) . PHP_EOL;
