<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('manage_appointments');
expire_overdue_appointments();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT a.*, CONCAT(p.last_name, ', ', p.first_name) AS patient_name, p.patient_code
     FROM appointments a
     JOIN patients p ON p.id = a.patient_id
     WHERE a.id = ?"
);
$stmt->execute([$id]);
$appt = $stmt->fetch();
if (!$appt) {
    flash('error', 'Appointment not found.');
    redirect('appointments/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'approve' && in_array($appt['status'], ['pending', 'approved'], true)) {
            $when = parse_local_datetime((string) ($_POST['scheduled_at'] ?? ''));
            if ($when === null) {
                throw new RuntimeException('Enter the approved date and time.');
            }
            if (strtotime($when) <= time()) {
                throw new RuntimeException('Choose a future approved date and time.');
            }
            db()->prepare(
                "UPDATE appointments
                 SET status = 'approved', scheduled_at = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status IN ('pending','approved')"
            )->execute([$when, (int) current_user()['id'], $id]);
            audit_log('appointment_approve', 'appointment', $id, 'Approved time ' . $when);
            flash('success', 'Approved time saved. The patient must arrive within ' . APPOINTMENT_GRACE_MINUTES . ' minutes of that time.');
        } elseif ($action === 'arrive' && $appt['status'] === 'approved') {
            $deadline = db_driver() === 'pgsql'
                ? "scheduled_at + INTERVAL '" . APPOINTMENT_GRACE_MINUTES . " minutes'"
                : 'DATE_ADD(scheduled_at, INTERVAL ' . APPOINTMENT_GRACE_MINUTES . ' MINUTE)';
            $updated = db()->prepare(
                "UPDATE appointments
                 SET status = 'arrived', arrived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status = 'approved' AND {$deadline} > CURRENT_TIMESTAMP"
            );
            $updated->execute([$id]);
            if ($updated->rowCount() < 1) {
                throw new RuntimeException('This booking is no longer waiting for arrival. It may have expired.');
            }
            audit_log('appointment_arrive', 'appointment', $id, 'Patient marked arrived');
            flash('success', 'Arrival recorded. You can start the laboratory request.');
        } elseif ($action === 'cancel' && in_array($appt['status'], ['pending', 'approved'], true)) {
            db()->prepare(
                "UPDATE appointments SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status IN ('pending','approved')"
            )->execute([$id]);
            audit_log('appointment_cancel', 'appointment', $id, 'Appointment cancelled');
            flash('success', 'Appointment cancelled.');
        } else {
            flash('error', 'That action is not available for this appointment.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('appointments/view.php?id=' . $id);
}

$panels = $appt['panel_codes'] ? str_replace(',', ', ', (string) $appt['panel_codes']) : '—';
$scheduleValue = datetime_local_value($appt['scheduled_at'] ?: $appt['preferred_at']);

$pageTitle = 'Appointment ' . $appt['appointment_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1><?= e($appt['appointment_code']) ?></h1>
    <p>
        <strong>Patient:</strong>
        <a href="<?= e(base_url('patients/view.php?id=' . $appt['patient_id'])) ?>"><?= e($appt['patient_name']) ?></a>
        (<?= e($appt['patient_code']) ?>)
    </p>
    <p><strong>Reason:</strong> <?= e($appt['checkup_reason']) ?></p>
    <p><strong>Requested time:</strong> <?= e($appt['preferred_at']) ?></p>
    <p><strong>Approved time:</strong> <?= e($appt['scheduled_at'] ?: 'Not set') ?></p>
    <p><strong>Tests requested:</strong> <?= e($panels) ?></p>
    <p><strong>Notes:</strong> <?= e($appt['notes'] ?: '—') ?></p>
    <p><strong>Status:</strong> <span class="badge<?= $appt['status'] === 'expired' ? ' badge-danger' : '' ?>"><?= e(appointment_status_label((string) $appt['status'])) ?></span></p>
    <?php if ($appt['status'] === 'approved'): ?>
        <p class="muted">If the patient is not marked arrived within <?= (int) APPOINTMENT_GRACE_MINUTES ?> minutes after <?= e($appt['scheduled_at']) ?>, this booking expires.</p>
    <?php elseif ($appt['status'] === 'expired'): ?>
        <p class="muted">The patient did not arrive within <?= (int) APPOINTMENT_GRACE_MINUTES ?> minutes of the approved time. They need to book again.</p>
    <?php endif; ?>

    <?php if (in_array($appt['status'], ['pending', 'approved'], true)): ?>
        <form method="post" class="form-row">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="hidden" name="action" value="approve">
            <label for="scheduled_at">Approved schedule</label>
            <input id="scheduled_at" type="datetime-local" name="scheduled_at" required value="<?= e($scheduleValue) ?>">
            <div class="actions">
                <button class="btn" type="submit"><?= $appt['status'] === 'approved' ? 'Update approved time' : 'Approve this time' ?></button>
            </div>
        </form>
    <?php endif; ?>

    <div class="actions">
        <?php if ($appt['status'] === 'approved'): ?>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="action" value="arrive">
                <button class="btn" type="submit">Mark arrived</button>
            </form>
        <?php endif; ?>
        <?php if (in_array($appt['status'], ['pending', 'approved'], true)): ?>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-danger" type="submit">Cancel booking</button>
            </form>
        <?php endif; ?>
        <?php if ($appt['status'] === 'arrived' && empty($appt['lab_request_id'])): ?>
            <a class="btn" href="<?= e(base_url('requests/create.php?patient_id=' . $appt['patient_id'] . '&appointment_id=' . $id)) ?>">Start laboratory request</a>
        <?php endif; ?>
        <?php if (!empty($appt['lab_request_id'])): ?>
            <a class="btn" href="<?= e(base_url('requests/view.php?id=' . $appt['lab_request_id'])) ?>">Open laboratory request</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= e(base_url('appointments/index.php')) ?>">Back</a>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
