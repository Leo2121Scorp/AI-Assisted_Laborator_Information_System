<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('specimen_collect');

$rows = db()->query(
    "SELECT s.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
     FROM specimens s
     JOIN lab_requests lr ON lr.id = s.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     ORDER BY FIELD(s.status,'missing','delayed','pending','collected','processing','completed'), s.status_updated_at ASC
     LIMIT 150"
)->fetchAll();

$pageTitle = 'Specimens — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Specimen Tracking</h1>
    <p>Monitor sample status across workflow stages. Delayed/missing samples are highlighted.</p>
</div>
<div class="card">
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
            }
            ?>
            <tr>
                <td><?= e($r['specimen_code']) ?></td>
                <td><?= e($r['request_code']) ?></td>
                <td><?= e($r['patient_name']) ?></td>
                <td><?= e($r['specimen_type']) ?></td>
                <td><span class="<?= $badge ?>"><?= e($r['status']) ?></span></td>
                <td><?= e($r['status_updated_at']) ?></td>
                <td><a href="<?= e(base_url('specimens/view.php?id=' . $r['id'])) ?>">Update</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
