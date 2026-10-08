<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$patient = require_patient();
expire_overdue_appointments();

$apptStmt = db()->prepare(
    'SELECT * FROM appointments WHERE patient_id = ? ORDER BY created_at DESC LIMIT 8'
);
$apptStmt->execute([(int) $patient['id']]);
$appointments = $apptStmt->fetchAll();
$latest = $appointments[0] ?? null;

$progress = ['in_lab' => false, 'result_ready' => false];
if ($latest && !empty($latest['lab_request_id'])) {
    $progress = appointment_request_progress((int) $latest['lab_request_id']);
}
$steps = $latest
    ? appointment_process_steps($latest, $progress['in_lab'], $progress['result_ready'])
    : [];

$results = db()->prepare(
    "SELECT r.id, r.result_code, r.panel_code, r.status, r.released_at, r.reported_at, lr.request_code
     FROM lab_results r
     JOIN lab_requests lr ON lr.id = r.lab_request_id
     WHERE lr.patient_id = ? AND r.status = 'released'
     ORDER BY COALESCE(r.released_at, r.reported_at) DESC
     LIMIT 5"
);
$results->execute([(int) $patient['id']]);
$ready = $results->fetchAll();

$pageTitle = 'My dashboard — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card dashboard-hero">
    <div class="dashboard-hero-text">
        <h1>My laboratory record</h1>
        <p class="muted"><?= e(app_config('lab_name')) ?> · <?= e($patient['last_name'] . ', ' . $patient['first_name']) ?> (<?= e($patient['patient_code']) ?>)</p>
        <p class="workflow-hint">Your flow: Book a checkup → wait for the approved time → arrive at the clinic → view released results.</p>
    </div>
    <div class="dashboard-hero-actions">
        <a class="btn" href="<?= e(base_url('portal/book.php')) ?>">Book checkup</a>
        <a class="btn btn-secondary" href="<?= e(base_url('portal/results.php')) ?>">My results</a>
    </div>
</div>

<div class="card">
    <h2>Where you are</h2>
    <?php if (!$latest): ?>
        <div class="empty-state">
            <p>You have no checkup yet. Book one and the clinic will set your approved time.</p>
            <a class="btn" href="<?= e(base_url('portal/book.php')) ?>">Book a checkup</a>
        </div>
    <?php else: ?>
        <p>
            Latest booking <strong><?= e($latest['appointment_code']) ?></strong>
            · <?= e(appointment_status_label((string) $latest['status'])) ?>
            <?php if ($latest['status'] === 'approved' && $latest['scheduled_at']): ?>
                · arrive by <strong><?= e($latest['scheduled_at']) ?></strong>
                (the booking expires <?= (int) APPOINTMENT_GRACE_MINUTES ?> minutes after that time if you do not arrive)
            <?php elseif ($latest['status'] === 'pending'): ?>
                · requested for <?= e($latest['preferred_at']) ?>, waiting for the clinic to approve a time
            <?php elseif ($latest['status'] === 'expired'): ?>
                · this approved time passed with no arrival. Book again.
            <?php endif; ?>
        </p>
        <div class="result-pipeline" aria-label="Your checkup progress">
            <?php foreach ($steps as $step): ?>
                <span class="result-pipe<?= $step['done'] ? ' is-active' : '' ?>"><?= e($step['label']) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>Checkups</h2>
        <a class="btn btn-small" href="<?= e(base_url('portal/book.php')) ?>">New booking</a>
    </div>
    <?php if (!$appointments): ?>
        <p class="muted">No bookings yet.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Code</th><th>Reason</th><th>Requested</th><th>Approved time</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($appointments as $row): ?>
                <tr>
                    <td><?= e($row['appointment_code']) ?></td>
                    <td><?= e($row['checkup_reason']) ?></td>
                    <td><?= e($row['preferred_at']) ?></td>
                    <td><?= e($row['scheduled_at'] ?: '—') ?></td>
                    <td><span class="badge<?= $row['status'] === 'expired' ? ' badge-danger' : ($row['status'] === 'approved' || $row['status'] === 'arrived' ? ' badge-ok' : '') ?>"><?= e(appointment_status_label((string) $row['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>Released results</h2>
        <a class="btn btn-small btn-secondary" href="<?= e(base_url('portal/results.php')) ?>">View all</a>
    </div>
    <?php if (!$ready): ?>
        <p class="muted">Released results appear here after the laboratory finishes your tests. Results still being processed stay hidden.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Result</th><th>Request</th><th>Panel</th><th>Released</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ready as $row): ?>
                <tr>
                    <td><?= e($row['result_code']) ?></td>
                    <td><?= e($row['request_code']) ?></td>
                    <td><?= e($row['panel_code']) ?></td>
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
