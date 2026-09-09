<?php
/**
 * Application configuration — AI-Assisted LIS
 */
$aiBase = getenv('AI_SERVICE_URL') ?: 'http://127.0.0.1:5001';
$aiBase = rtrim((string) $aiBase, '/');
if ($aiBase !== '' && !preg_match('#^https?://#i', $aiBase)) {
    $aiBase = 'https://' . $aiBase;
}

return [
    'app_name' => 'AI-Assisted Laboratory Information System',
    'lab_name' => 'Lagman Qualicare Multispecialty and Diagnostic Center',
    'base_url' => getenv('APP_BASE_URL') ?: '', // auto-detected if empty
    'timezone' => 'Asia/Manila',
    'session_name' => 'AILAB_LIS_SESS',
    'specimen_sla_hours' => 24,
    'ai_endpoint' => getenv('AI_ENDPOINT') ?: ($aiBase . '/predict'),
    'ai_health_endpoint' => getenv('AI_HEALTH_ENDPOINT') ?: ($aiBase . '/health'),
    'ai_timeout_seconds' => 5,
    'backup_dir' => __DIR__ . '/../backups',
];
