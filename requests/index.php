<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('requests');

$rows = db()->query(
    "SELECT lr.*, CONCAT(p.last_name, ', ', p.first_name) AS patient_name, p.patient_code
     FROM lab_requests lr
     JOIN patients p ON p.id = lr.patient_id
     ORDER BY lr.created_at DESC
     LIMIT 100"
)->fetchAll();

$pageTitle = 'Laboratory Requests — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Laboratory Requests</h1>
    <a class="btn" href="<?= e(base_url('requests/create.php')) ?>">New request</a>
</div>
<div class="card">
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
                <td><a href="<?= e(base_url('requests/view.php?id=' . $r['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
