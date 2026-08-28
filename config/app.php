<?php
/**
 * Application configuration — AI-Assisted LIS
 */
return [
    'app_name' => 'AI-Assisted Laboratory Information System',
    'lab_name' => 'Lagman Qualicare Multispecialty and Diagnostic Center',
    'base_url' => '', // auto-detected if empty
    'timezone' => 'Asia/Manila',
    'session_name' => 'AILAB_LIS_SESS',
    'specimen_sla_hours' => 24,
    'ai_endpoint' => 'http://127.0.0.1:5001/predict',
    'ai_health_endpoint' => 'http://127.0.0.1:5001/health',
    'ai_timeout_seconds' => 5,
    'backup_dir' => __DIR__ . '/../backups',
];
