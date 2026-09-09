<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('view_reports');

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$allowedStatus = ['approved', 'reported', 'released'];

$sql = "SELECT r.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
        FROM lab_results r
        JOIN lab_requests lr ON lr.id = r.lab_request_id
        JOIN patients p ON p.id = lr.patient_id
        WHERE r.status IN ('reported','released','approved')";
$params = [];

if ($status !== '' && in_array($status, $allowedStatus, true)) {
    $sql .= ' AND r.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (r.result_code LIKE ? OR lr.request_code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR r.panel_code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= ' ORDER BY COALESCE(r.released_at, r.reported_at, r.approved_at) DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Reports — AI-LIS';
require __DIR__ . '/../includes/header.php';

$filters = [
    '' => 'All ready',
    'approved' => 'Approved',
    'reported' => 'Reported',
    'released' => 'Released',
];
?>
<div class="card">
    <div class="card-head">
        <div>
            <h1>Laboratory Reports</h1>
            <p class="muted">View and print approved, generated, or released reports.</p>
        </div>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search patient, request, or result…">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <button class="btn" type="submit">Search</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('reports/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filter-chips">
        <?php foreach ($filters as $value => $label): ?>
            <?php
            $href = 'reports/index.php';
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
            <p><?= ($q !== '' || $status !== '') ? 'No reports match these filters.' : 'No reports ready yet. Reports appear after MedTech approves results.' ?></p>
            <?php if (can('encode_results')): ?>
                <a class="btn" href="<?= e(base_url('results/index.php?status=validated')) ?>">Review results</a>
            <?php else: ?>
                <a class="btn btn-secondary" href="<?= e(base_url('requests/index.php')) ?>">View requests</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Result</th><th>Request</th><th>Patient</th><th>Panel</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['result_code']) ?></td>
                    <td><?= e($r['request_code']) ?></td>
                    <td><?= e($r['patient_name']) ?></td>
                    <td><?= e($r['panel_code']) ?></td>
                    <td><span class="badge<?= $r['status'] === 'released' ? ' badge-ok' : '' ?>"><?= e($r['status']) ?></span></td>
                    <td>
                        <?php if (in_array($r['status'], ['reported', 'released', 'approved'], true)): ?>
                            <a class="btn btn-small btn-secondary" href="<?= e(base_url('reports/view.php?id=' . $r['id'])) ?>">Open</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
