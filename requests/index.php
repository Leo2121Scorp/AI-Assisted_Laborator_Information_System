<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('requests');

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$allowedStatus = ['open', 'in_progress', 'completed', 'cancelled'];

$sql = "SELECT lr.*, CONCAT(p.last_name, ', ', p.first_name) AS patient_name, p.patient_code
        FROM lab_requests lr
        JOIN patients p ON p.id = lr.patient_id
        WHERE 1=1";
$params = [];

if ($status === 'open') {
    $sql .= " AND lr.status IN ('open','in_progress')";
} elseif ($status !== '' && in_array($status, $allowedStatus, true)) {
    $sql .= ' AND lr.status = ?';
    $params[] = $status;
}

if ($q !== '') {
    $sql .= ' AND (lr.request_code LIKE ? OR p.patient_code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR lr.requesting_physician LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= ' ORDER BY lr.created_at DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Laboratory Requests — AI-LIS';
require __DIR__ . '/../includes/header.php';

$filters = [
    '' => 'All',
    'open' => 'Open / in progress',
    'completed' => 'Completed',
];
?>
<div class="card">
    <div class="card-head">
        <div>
            <h1>Laboratory Requests</h1>
            <p class="muted">Create orders and track request status. Search by request, patient, or physician.</p>
        </div>
        <a class="btn" href="<?= e(base_url('requests/create.php')) ?>">New request</a>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search request, patient, physician…">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <button class="btn" type="submit">Search</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('requests/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filter-chips" role="tablist" aria-label="Request status filter">
        <?php foreach ($filters as $value => $label): ?>
            <?php
            $href = 'requests/index.php';
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
            $active = $status === $value;
            ?>
            <a class="chip<?= $active ? ' is-active' : '' ?>" href="<?= e(base_url($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<div class="card">
    <?php if (!$rows): ?>
        <div class="empty-state">
            <p><?= ($q !== '' || $status !== '') ? 'No requests match these filters.' : 'No laboratory requests yet. Create one from a patient record or use New request.' ?></p>
            <a class="btn" href="<?= e(base_url('requests/create.php')) ?>">New request</a>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Request</th><th>Patient</th><th>Physician</th><th>Status</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['request_code']) ?></td>
                    <td><?= e($r['patient_name']) ?> <small>(<?= e($r['patient_code']) ?>)</small></td>
                    <td><?= e($r['requesting_physician'] ?: '—') ?></td>
                    <td><span class="badge"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['created_at']) ?></td>
                    <td><a class="btn btn-small btn-secondary" href="<?= e(base_url('requests/view.php?id=' . $r['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
