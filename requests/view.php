<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('requests');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT lr.*, CONCAT(p.last_name, ', ', p.first_name) AS patient_name, p.patient_code, p.id AS patient_id
     FROM lab_requests lr
     JOIN patients p ON p.id = lr.patient_id
     WHERE lr.id = ?"
);
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) {
    flash('error', 'Request not found.');
    redirect('requests/index.php');
}

$tests = db()->prepare(
    'SELECT lt.* FROM request_tests rt JOIN lab_tests lt ON lt.id = rt.lab_test_id WHERE rt.lab_request_id = ?'
);
$tests->execute([$id]);
$testRows = $tests->fetchAll();

$specs = db()->prepare('SELECT * FROM specimens WHERE lab_request_id = ?');
$specs->execute([$id]);
$specimens = $specs->fetchAll();

$results = db()->prepare('SELECT * FROM lab_results WHERE lab_request_id = ?');
$results->execute([$id]);
$resultRows = $results->fetchAll();

$pageTitle = 'Request ' . $request['request_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Request <?= e($request['request_code']) ?></h1>
    <p>
        Patient: <a href="<?= e(base_url('patients/view.php?id=' . $request['patient_id'])) ?>"><?= e($request['patient_name']) ?></a>
        (<?= e($request['patient_code']) ?>)
    </p>
    <p>Status: <span class="badge"><?= e($request['status']) ?></span></p>
    <p>Physician: <?= e($request['requesting_physician'] ?: '—') ?></p>
    <p>Notes: <?= e($request['clinical_notes'] ?: '—') ?></p>
</div>
<div class="card">
    <h2>Ordered tests</h2>
    <ul>
        <?php foreach ($testRows as $t): ?>
            <li><?= e($t['panel_code'] . ' / ' . $t['test_code'] . ' — ' . $t['test_name']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<div class="card">
    <h2>Specimens</h2>
    <table>
        <thead><tr><th>Code</th><th>Type</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($specimens as $s): ?>
            <tr>
                <td><?= e($s['specimen_code']) ?></td>
                <td><?= e($s['specimen_type']) ?></td>
                <td><span class="badge"><?= e($s['status']) ?></span></td>
                <td><a href="<?= e(base_url('specimens/view.php?id=' . $s['id'])) ?>">Update</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="card">
    <h2>Results</h2>
    <table>
        <thead><tr><th>Code</th><th>Panel</th><th>Status</th><th>AI</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($resultRows as $r): ?>
            <tr>
                <td><?= e($r['result_code']) ?></td>
                <td><?= e($r['panel_code']) ?></td>
                <td><span class="badge"><?= e($r['status']) ?></span></td>
                <td><?= $r['ai_flagged'] ? '<span class="badge badge-warning">flagged</span>' : '—' ?></td>
                <td><a href="<?= e(base_url('results/view.php?id=' . $r['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
