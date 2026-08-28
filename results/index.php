<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('encode_results');

$rows = db()->query(
    "SELECT r.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
     FROM lab_results r
     JOIN lab_requests lr ON lr.id = r.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     ORDER BY FIELD(r.status,'validated','encoded','pending','approved','reported','released'), r.updated_at DESC
     LIMIT 150"
)->fetchAll();

$pageTitle = 'Results — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Laboratory Results</h1>
    <p>Encode values, run rule-based + AI validation, then approve for release.</p>
</div>
<div class="card">
    <table>
        <thead>
        <tr><th>Result</th><th>Request</th><th>Patient</th><th>Panel</th><th>Status</th><th>AI</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['result_code']) ?></td>
                <td><?= e($r['request_code']) ?></td>
                <td><?= e($r['patient_name']) ?></td>
                <td><?= e($r['panel_code']) ?></td>
                <td><span class="badge"><?= e($r['status']) ?></span></td>
                <td><?= $r['ai_flagged'] ? '<span class="badge badge-warning">warning</span>' : '—' ?></td>
                <td><a href="<?= e(base_url('results/view.php?id=' . $r['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
