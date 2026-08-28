<?php
declare(strict_types=1);

function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    $userId = current_user()['id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $action, $entityType, $entityId, $details, $ip]);
}
