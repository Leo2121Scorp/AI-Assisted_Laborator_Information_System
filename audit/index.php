<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('view_audit');

$rows = db()->query(
    'SELECT a.*, u.username, u.full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC
     LIMIT 200'
)->fetchAll();

$pageTitle = 'Audit Log — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Audit Log</h1>
    <p>Immutable activity trail for authentication, specimen updates, encoding, approval, release, and backups.</p>
</div>
<div class="card">
    <table>
        <thead>
        <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['created_at']) ?></td>
                <td><?= e($r['full_name'] ?: ($r['username'] ?: 'system')) ?></td>
                <td><?= e($r['action']) ?></td>
                <td><?= e(($r['entity_type'] ?: '') . ($r['entity_id'] ? '#' . $r['entity_id'] : '')) ?></td>
                <td><?= e($r['details']) ?></td>
                <td><?= e($r['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
