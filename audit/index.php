<?php
declare(strict_types=1);

/**
 * Audit log viewer — who did what, and when.
 *
 * Rows are written by audit_log() (login, encode, approve, release, and so on).
 * The list is the full trail, newest timestamp first, split into pages so a
 * two-week history stays readable. LEFT JOIN users so a deleted account still
 * shows as "system".
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('view_audit');

$pageSize = 100;
$page = max(1, (int) ($_GET['page'] ?? 1));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$where = [];
$params = [];
if ($from !== '') {
    $where[] = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM audit_logs a {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $pageSize));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $pageSize;

$spanStmt = db()->prepare("SELECT MIN(a.created_at) AS first_at, MAX(a.created_at) AS last_at FROM audit_logs a {$whereSql}");
$spanStmt->execute($params);
$span = $spanStmt->fetch() ?: ['first_at' => null, 'last_at' => null];

$listStmt = db()->prepare(
    "SELECT a.*, u.username, u.full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     {$whereSql}
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT {$pageSize} OFFSET {$offset}"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

$fromRow = $total === 0 ? 0 : $offset + 1;
$toRow = $total === 0 ? 0 : min($offset + $pageSize, $total);

/** Keep the date filter when moving between pages. */
$auditQuery = static function (int $target) use ($from, $to): string {
    $query = ['page' => $target];
    if ($from !== '') {
        $query['from'] = $from;
    }
    if ($to !== '') {
        $query['to'] = $to;
    }
    return 'audit/index.php?' . http_build_query($query);
};

$pageTitle = 'Audit Log — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Audit Log</h1>
    <p>Full activity trail for sign-in, patients, specimens, encoding, approval, release, and backups. Newest time first.</p>
    <p class="muted">
        <?php if ($total === 0): ?>
            No audit events<?= ($from !== '' || $to !== '') ? ' in this date range' : '' ?>.
        <?php else: ?>
            Showing <?= number_format($fromRow) ?>–<?= number_format($toRow) ?> of <?= number_format($total) ?>
            · <?= e((string) $span['first_at']) ?> through <?= e((string) $span['last_at']) ?>
        <?php endif; ?>
    </p>
    <form method="get" class="form-row inline" style="margin-top:0.85rem">
        <div>
            <label>From</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label>To</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div style="align-self:end">
            <button class="btn btn-small" type="submit">Apply</button>
            <?php if ($from !== '' || $to !== ''): ?>
                <a class="btn btn-small btn-secondary" href="<?= e(base_url('audit/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>
<div class="card">
    <?php if ($pages > 1): ?>
        <div class="quick-actions" style="margin-bottom:0.85rem">
            <?php if ($page > 1): ?>
                <a class="btn btn-small btn-secondary" href="<?= e(base_url($auditQuery(1))) ?>">First</a>
                <a class="btn btn-small btn-secondary" href="<?= e(base_url($auditQuery($page - 1))) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted" style="align-self:center">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-small btn-secondary" href="<?= e(base_url($auditQuery($page + 1))) ?>">Next</a>
                <a class="btn btn-small btn-secondary" href="<?= e(base_url($auditQuery($pages))) ?>">Last</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="table-scroll">
    <table>
        <thead>
        <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6"><div class="empty-state"><p>No audit events yet.</p></div></td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="When"><?= e($r['created_at']) ?></td>
                    <td data-label="User"><?= e($r['full_name'] ?: ($r['username'] ?: 'system')) ?></td>
                    <td data-label="Action"><?= e($r['action']) ?></td>
                    <td data-label="Entity"><?= e(($r['entity_type'] ?: '') . ($r['entity_id'] ? '#' . $r['entity_id'] : '')) ?></td>
                    <td data-label="Details"><?= e($r['details']) ?></td>
                    <td data-label="IP"><?= e($r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
