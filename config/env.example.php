<?php
/**
 * Copy to env.php and fill secrets. env.php is gitignored.
 */
return [
    'OPENROUTER_API_KEY' => '',
    'OPENROUTER_MODEL' => 'openai/gpt-4o-mini',
    // Backup OpenAI-compatible gateway (e.g. NaraRouter)
    'BACKUP_AI_API_KEY' => '',
    'BACKUP_AI_BASE_URL' => 'https://router.bynara.id/v1',
    'BACKUP_AI_MODEL' => 'auto/bynara',
    // 'AI_SERVICE_URL' => 'http://127.0.0.1:5001',
    // 'APP_BASE_URL' => '',
];
