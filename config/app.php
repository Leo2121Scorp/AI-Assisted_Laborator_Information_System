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
$embedded = ailab_app_env('START_EMBEDDED_AI');
$onRender = ailab_app_env('RENDER') === 'true';
// On Render the sibling ailab-ai service sleeps; Isolation Forest runs in this container.
if ($embedded !== '0' && ($embedded === '1' || $onRender)) {
    $aiPort = ailab_app_env('AI_PORT') ?: '5001';
    $aiBase = 'http://127.0.0.1:' . $aiPort;
} elseif ($aiBase !== '' && !preg_match('#^https?://#i', $aiBase)) {
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
    'ai_chat_endpoint' => ailab_app_env('AI_CHAT_ENDPOINT') ?? ($aiBase . '/chat'),
    'ai_timeout_seconds' => 5,
    'ai_chat_timeout_seconds' => 45,
    'groq_api_key' => ailab_app_env('GROQ_API_KEY') ?? ailab_app_env('OPENROUTER_API_KEY') ?? '',
    'groq_model' => ailab_app_env('GROQ_MODEL') ?? ailab_app_env('OPENROUTER_MODEL') ?? 'llama-3.3-70b-versatile',
    'groq_base_url' => ailab_app_env('GROQ_BASE_URL') ?? 'https://api.groq.com/openai/v1',
    'backup_ai_api_key' => ailab_app_env('BACKUP_AI_API_KEY') ?? '',
    'backup_ai_base_url' => ailab_app_env('BACKUP_AI_BASE_URL') ?? 'https://router.bynara.id/v1',
    'backup_ai_model' => ailab_app_env('BACKUP_AI_MODEL') ?? 'auto/bynara',
    'backup_dir' => __DIR__ . '/../backups',
];
