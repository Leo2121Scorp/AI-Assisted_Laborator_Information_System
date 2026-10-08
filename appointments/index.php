<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('manage_appointments');
expire_overdue_appointments();

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if (!is_iso_date($from)) {
    $from = '';
}
if (!is_iso_date($to)) {
    $to = '';
}
$allowed = ['pending', 'approved', 'arrived', 'expired', 'cancelled'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

[$sql, $params] = appointment_list_sql([
    'q' => $q,
    'status' => $status,
    'from' => $from,
    'to' => $to,
]);
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Appointments — AI-LIS';
require __DIR__ . '/../includes/header.php';

$filters = [
    '' => 'All',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'arrived' => 'Arrived',
    'expired' => 'Expired',
    'cancelled' => 'Cancelled',
];
$filtered = $q !== '' || $status !== '' || $from !== '' || $to !== '';
?>
<div class="card no-print">
    <div class="card-head">
        <div>
            <h1>Checkup appointments</h1>
            <p class="muted">Approve a time, then mark the patient arrived. Approved bookings expire <?= (int) APPOINTMENT_GRACE_MINUTES ?> minutes after the scheduled time if nobody arrives.</p>
        </div>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search code, patient, or reason…">
        <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
        <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <button class="btn" type="submit">Filter</button>
        <?php if ($filtered): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('appointments/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filter-chips">
        <?php foreach ($filters as $value => $label): ?>
            <?php
            $qs = [];
            if ($q !== '') {
                $qs['q'] = $q;
            }
            if ($from !== '') {
                $qs['from'] = $from;
            }
            if ($to !== '') {
                $qs['to'] = $to;
            }
            if ($value !== '') {
                $qs['status'] = $value;
            }
            $href = 'appointments/index.php' . ($qs ? '?' . http_build_query($qs) : '');
            ?>
            <a class="chip<?= $status === $value ? ' is-active' : '' ?>" href="<?= e(base_url($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<div class="card">
    <?php if (!$rows): ?>
        <div class="empty-state">
            <p><?= $filtered ? 'No appointments match these filters.' : 'No checkup bookings yet. Patients request them from their portal.' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Code</th><th>Patient</th><th>Reason</th><th>Requested</th><th>Approved time</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['appointment_code']) ?></td>
                    <td><?= e($row['patient_name']) ?> (<?= e($row['patient_code']) ?>)</td>
                    <td><?= e($row['checkup_reason']) ?></td>
                    <td><?= e($row['preferred_at']) ?></td>
                    <td><?= e($row['scheduled_at'] ?: '—') ?></td>
                    <td><span class="badge<?= $row['status'] === 'expired' ? ' badge-danger' : ($row['status'] === 'approved' || $row['status'] === 'arrived' ? ' badge-ok' : ($row['status'] === 'pending' ? ' badge-warning' : '')) ?>"><?= e(appointment_status_label((string) $row['status'])) ?></span></td>
                    <td><a class="btn btn-small btn-secondary" href="<?= e(base_url('appointments/view.php?id=' . $row['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
