<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !can('use_ai_chat')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'detail' => 'AI chat is for Manager and MedTech only.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'detail' => 'POST required']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json', 'detail' => 'Expected JSON body']);
    exit;
}

$message = trim((string) ($input['message'] ?? ''));
$history = $input['history'] ?? [];
if (!is_array($history)) {
    $history = [];
}

$cleanHistory = [];
foreach ($history as $item) {
    if (!is_array($item)) {
        continue;
    }
    $role = (string) ($item['role'] ?? '');
    $content = trim((string) ($item['content'] ?? ''));
    if (($role === 'user' || $role === 'assistant') && $content !== '') {
        $cleanHistory[] = ['role' => $role, 'content' => substr($content, 0, 4000)];
    }
}

$result = ai_chat($message, $cleanHistory, user_role());

if (!empty($result['ok'])) {
    audit_log('ai_chat', 'user', (int) current_user()['id'], 'OpenRouter assistant query');
    echo json_encode([
        'ok' => true,
        'reply' => $result['reply'] ?? '',
        'model' => $result['model'] ?? null,
    ]);
    exit;
}

http_response_code(502);
echo json_encode([
    'ok' => false,
    'error' => $result['error'] ?? 'chat_failed',
    'detail' => $result['detail'] ?? 'Chat failed',
]);
