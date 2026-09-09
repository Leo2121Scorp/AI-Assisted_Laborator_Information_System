<?php
/**
 * Application configuration — AI-Assisted LIS
 */

if (!function_exists('ailab_app_env')) {
    /** @return string|null */
    function ailab_app_env(string $key): ?string
    {
        static $fileEnv = null;
        if ($fileEnv === null) {
            $path = __DIR__ . '/env.php';
            $fileEnv = is_file($path) ? (require $path) : [];
            if (!is_array($fileEnv)) {
                $fileEnv = [];
            }
        }
        if (isset($fileEnv[$key]) && $fileEnv[$key] !== '' && $fileEnv[$key] !== null) {
            return (string) $fileEnv[$key];
        }
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        return null;
    }
}

$aiBase = ailab_app_env('AI_SERVICE_URL') ?? 'http://127.0.0.1:5001';
$aiBase = rtrim($aiBase, '/');
if ($aiBase !== '' && !preg_match('#^https?://#i', $aiBase)) {
    // Render often injects short service name; expand to public host
    if (!str_contains($aiBase, '.') && preg_match('/^ailab-ai/i', $aiBase)) {
        $aiBase .= '.onrender.com';
    }
    $aiBase = 'https://' . $aiBase;
}

return [
    'app_name' => 'AI-Assisted Laboratory Information System',
    'lab_name' => 'Lagman Qualicare Multispecialty and Diagnostic Center',
    'base_url' => ailab_app_env('APP_BASE_URL') ?? '', // auto-detected if empty
    'timezone' => 'Asia/Manila',
    'session_name' => 'AILAB_LIS_SESS',
    'specimen_sla_hours' => 24,
    'ai_endpoint' => ailab_app_env('AI_ENDPOINT') ?? ($aiBase . '/predict'),
    'ai_health_endpoint' => ailab_app_env('AI_HEALTH_ENDPOINT') ?? ($aiBase . '/health'),
    'ai_timeout_seconds' => 5,
    'backup_dir' => __DIR__ . '/../backups',
];
