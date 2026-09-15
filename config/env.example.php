<?php
/**
 * Copy to env.php and fill secrets. env.php is gitignored.
 */
return [
    'GROQ_API_KEY' => '',
    'GROQ_MODEL' => 'llama-3.3-70b-versatile',
    'GROQ_BASE_URL' => 'https://api.groq.com/openai/v1',
    // Backup OpenAI-compatible gateway (e.g. NaraRouter)
    'BACKUP_AI_API_KEY' => '',
    'BACKUP_AI_BASE_URL' => 'https://router.bynara.id/v1',
    'BACKUP_AI_MODEL' => 'auto/bynara',
    // 'AI_SERVICE_URL' => 'http://127.0.0.1:5001',
    // 'APP_BASE_URL' => '',
];
