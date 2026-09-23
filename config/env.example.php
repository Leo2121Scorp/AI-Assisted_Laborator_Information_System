<?php
/**
 * Example environment file — copy this to env.php and fill in your secrets.
 *
 * How to use:
 *   1. Copy this file and rename the copy to env.php (same folder).
 *   2. Put real API keys in the empty strings below.
 *   3. Never commit env.php — it is gitignored so secrets stay off GitHub.
 *
 * The app reads these values through helpers in config/app.php and
 * config/database.php (ailab_app_env / ailab_env).
 */
return [
    // Primary AI chat (Groq). Leave blank until you have a key.
    'GROQ_API_KEY' => '',
    'GROQ_MODEL' => 'openai/gpt-oss-120b',
    'GROQ_BASE_URL' => 'https://api.groq.com/openai/v1',
    // Backup OpenAI-compatible gateway (e.g. NaraRouter) if Groq is unavailable.
    'BACKUP_AI_API_KEY' => '',
    'BACKUP_AI_BASE_URL' => 'https://router.bynara.id/v1',
    'BACKUP_AI_MODEL' => 'auto/bynara',
    // Optional overrides — uncomment and edit if you need them:
    // 'AI_SERVICE_URL' => 'http://127.0.0.1:5001',
    // 'APP_BASE_URL' => '',
];
