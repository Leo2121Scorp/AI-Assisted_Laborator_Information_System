<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$patient = require_patient();
$stmt = db()->prepare(
    "SELECT r.id, r.result_code, r.panel_code, r.status, r.released_at, r.reported_at, lr.request_code
     FROM lab_results r
     JOIN lab_requests lr ON lr.id = r.lab_request_id
     WHERE lr.patient_id = ? AND r.status = 'released'
     ORDER BY COALESCE(r.released_at, r.reported_at) DESC"
);
$stmt->execute([(int) $patient['id']]);
$rows = $stmt->fetchAll();

$pageTitle = 'My results — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>My results</h1>
    <p class="muted">Only released laboratory reports are shown. Tests still in process are not available here.</p>
    <p class="ai-disclaimer"><?= e(ai_medical_disclaimer()) ?></p>
</div>
<div class="card">
    <?php if (!$rows): ?>
        <div class="empty-state">
            <p>No released results yet.</p>
            <a class="btn btn-secondary" href="<?= e(base_url('portal/book.php')) ?>">Book a checkup</a>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Result</th><th>Request</th><th>Panel</th><th>Status</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['result_code']) ?></td>
                    <td><?= e($row['request_code']) ?></td>
                    <td><?= e($row['panel_code']) ?></td>
                    <td><span class="badge badge-ok"><?= e($row['status']) ?></span></td>
                    <td><?= e($row['released_at'] ?: $row['reported_at']) ?></td>
                    <td><a class="btn btn-small btn-secondary" href="<?= e(base_url('portal/result.php?id=' . $row['id'])) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
