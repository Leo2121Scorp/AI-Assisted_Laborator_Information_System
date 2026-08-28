<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('view_reports');

$rows = db()->query(
    "SELECT r.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
     FROM lab_results r
     JOIN lab_requests lr ON lr.id = r.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     WHERE r.status IN ('reported','released','approved')
     ORDER BY COALESCE(r.released_at, r.reported_at, r.approved_at) DESC
     LIMIT 100"
)->fetchAll();

$pageTitle = 'Reports — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Laboratory Reports</h1>
</div>
<div class="card">
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
                <td><span class="badge"><?= e($r['status']) ?></span></td>
                <td>
                    <?php if (in_array($r['status'], ['reported', 'released', 'approved'], true)): ?>
                        <a href="<?= e(base_url('reports/view.php?id=' . $r['id'])) ?>">Open</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
