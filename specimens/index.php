<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('specimen_collect');

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$allowedStatus = ['pending', 'collected', 'processing', 'completed', 'delayed', 'missing', 'active'];

$sql = "SELECT s.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
        FROM specimens s
        JOIN lab_requests lr ON lr.id = s.lab_request_id
        JOIN patients p ON p.id = lr.patient_id
        WHERE 1=1";
$params = [];

if ($status === 'active') {
    $sql .= " AND s.status NOT IN ('completed')";
} elseif ($status !== '' && in_array($status, $allowedStatus, true)) {
    $sql .= ' AND s.status = ?';
    $params[] = $status;
}

if ($q !== '') {
    $sql .= ' AND (s.specimen_code LIKE ? OR lr.request_code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR s.specimen_type LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= ' ORDER BY ' . sql_order_by_list('s.status', ['missing', 'delayed', 'pending', 'collected', 'processing', 'completed'])
    . ', s.status_updated_at ASC LIMIT 150';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Specimens — AI-LIS';
require __DIR__ . '/../includes/header.php';

$filters = [
    '' => 'All',
    'pending' => 'Pending collect',
    'collected' => 'Collected',
    'processing' => 'Processing',
    'delayed' => 'Delayed',
    'missing' => 'Missing',
    'active' => 'Active',
    'completed' => 'Completed',
];
?>
<div class="card">
    <div class="card-head">
        <div>
            <h1>Specimen Tracking</h1>
            <p class="muted">Monitor sample status. Delayed and missing samples are highlighted first.</p>
        </div>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search specimen, request, patient…">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <button class="btn" type="submit">Search</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('specimens/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filter-chips">
        <?php foreach ($filters as $value => $label): ?>
            <?php
            $href = 'specimens/index.php';
            $qs = [];
            if ($q !== '') {
                $qs['q'] = $q;
            }
            if ($value !== '') {
                $qs['status'] = $value;
            }
            if ($qs) {
                $href .= '?' . http_build_query($qs);
            }
            ?>
            <a class="chip<?= $status === $value ? ' is-active' : '' ?>" href="<?= e(base_url($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<div class="card">
    <?php if (!$rows): ?>
        <div class="empty-state">
            <p><?= ($q !== '' || $status !== '') ? 'No specimens match these filters.' : 'No specimens yet. Creating a lab request automatically adds a specimen to track.' ?></p>
            <?php if (can('requests')): ?>
                <a class="btn" href="<?= e(base_url('requests/create.php')) ?>">New request</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Specimen</th><th>Request</th><th>Patient</th><th>Type</th><th>Status</th><th>Updated</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                $badge = 'badge';
                if (in_array($r['status'], ['delayed', 'missing'], true)) {
                    $badge = 'badge badge-danger';
                } elseif ($r['status'] === 'completed') {
                    $badge = 'badge badge-ok';
                } elseif ($r['status'] === 'processing') {
                    $badge = 'badge badge-info';
                } elseif ($r['status'] === 'pending') {
                    $badge = 'badge badge-warning';
                }
                ?>
                <tr>
                    <td><?= e($r['specimen_code']) ?></td>
                    <td><?= e($r['request_code']) ?></td>
                    <td><?= e($r['patient_name']) ?></td>
                    <td><?= e($r['specimen_type']) ?></td>
                    <td><span class="<?= $badge ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['status_updated_at']) ?></td>
                    <td><a class="btn btn-small btn-secondary" href="<?= e(base_url('specimens/view.php?id=' . $r['id'])) ?>">Update</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
