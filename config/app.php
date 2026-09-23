<?php
/**
 * Application settings for the AI-Assisted Laboratory Information System (LIS).
 *
 * This file builds one big settings array (app name, AI URLs, timeouts, etc.).
 * Other PHP pages load it so they share the same config.
 *
 * Tip: Secrets (API keys) should live in config/env.php, not here.
 *      Copy config/env.example.php to env.php and fill in the blanks.
 */

// ---------------------------------------------------------------------------
// Helper: read one setting from env.php or the server environment
// ---------------------------------------------------------------------------
// "static" means we load env.php only once, then reuse that array.
// Order of lookup: env.php file → getenv() → $_ENV → $_SERVER.
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

// Groq retired these ids on 16 Aug 2026. Map them so an old GROQ_MODEL still chats.
if (!function_exists('ailab_active_groq_model')) {
    function ailab_active_groq_model(?string $model): string
    {
        $model = trim((string) $model);
        $retired = [
            'llama-3.3-70b-versatile' => 'openai/gpt-oss-120b',
            'llama-3.1-8b-instant' => 'openai/gpt-oss-20b',
        ];
        if ($model === '') {
            return 'openai/gpt-oss-120b';
        }
        return $retired[$model] ?? $model;
    }
}

// ---------------------------------------------------------------------------
// Decide the base URL of the AI service (predict / health / chat)
// ---------------------------------------------------------------------------
// Default: local Python AI on port 5001.
$aiBase = ailab_app_env('AI_SERVICE_URL') ?? 'http://127.0.0.1:5001';
$aiBase = rtrim($aiBase, '/');
$embedded = ailab_app_env('START_EMBEDDED_AI');
$onRender = ailab_app_env('RENDER') === 'true';
// On Render the sibling ailab-ai service sleeps; Isolation Forest runs in this container.
// So we force AI calls to localhost when embedded AI is on, or when hosted on Render.
if ($embedded !== '0' && ($embedded === '1' || $onRender)) {
    $aiPort = ailab_app_env('AI_PORT') ?: '5001';
    $aiBase = 'http://127.0.0.1:' . $aiPort;
} elseif ($aiBase !== '' && !preg_match('#^https?://#i', $aiBase)) {
    // Host was given without http/https — turn it into a full URL.
    // Short Render names like "ailab-ai" become "ailab-ai.onrender.com".
    if (!str_contains($aiBase, '.') && preg_match('/^ailab-ai/i', $aiBase)) {
        $aiBase .= '.onrender.com';
    }
    $aiBase = 'https://' . $aiBase;
}

// ---------------------------------------------------------------------------
// Settings returned to the rest of the app (require this file to get them)
// ---------------------------------------------------------------------------
return [
    'app_name' => 'AI-Assisted Laboratory Information System',
    'lab_name' => 'Lagman Qualicare Multispecialty and Diagnostic Center',
    'base_url' => ailab_app_env('APP_BASE_URL') ?? '', // auto-detected if empty
    'timezone' => 'Asia/Manila',
    'session_name' => 'AILAB_LIS_SESS',
    // Specimens older than this many hours show up as "delayed" on the dashboard.
    'specimen_sla_hours' => 24,
    // AI micro-service endpoints (built from $aiBase unless overridden).
    'ai_endpoint' => ailab_app_env('AI_ENDPOINT') ?? ($aiBase . '/predict'),
    'ai_health_endpoint' => ailab_app_env('AI_HEALTH_ENDPOINT') ?? ($aiBase . '/health'),
    'ai_chat_endpoint' => ailab_app_env('AI_CHAT_ENDPOINT') ?? ($aiBase . '/chat'),
    'ai_timeout_seconds' => 5,
    'ai_chat_timeout_seconds' => 45,
    // Primary chat LLM (Groq). OPENROUTER_* names are accepted as aliases.
    'groq_api_key' => ailab_app_env('GROQ_API_KEY') ?? ailab_app_env('OPENROUTER_API_KEY') ?? '',
    'groq_model' => ailab_active_groq_model(ailab_app_env('GROQ_MODEL') ?? ailab_app_env('OPENROUTER_MODEL') ?? ''),
    'groq_base_url' => ailab_app_env('GROQ_BASE_URL') ?? 'https://api.groq.com/openai/v1',
    // Fallback chat provider if Groq is down or not configured.
    'backup_ai_api_key' => ailab_app_env('BACKUP_AI_API_KEY') ?? '',
    'backup_ai_base_url' => ailab_app_env('BACKUP_AI_BASE_URL') ?? 'https://router.bynara.id/v1',
    'backup_ai_model' => ailab_app_env('BACKUP_AI_MODEL') ?? 'auto/bynara',
    'backup_dir' => __DIR__ . '/../backups',
];
