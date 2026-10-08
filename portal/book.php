<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$patient = require_patient();
expire_overdue_appointments();
$panels = appointment_panel_catalog();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['checkup_reason'] ?? '');
    $when = parse_local_datetime((string) ($_POST['preferred_at'] ?? ''));
    $notes = trim($_POST['notes'] ?? '');
    $selected = array_values(array_intersect(array_keys($panels), (array) ($_POST['panels'] ?? [])));

    if ($reason === '') {
        $errors[] = 'Say what the checkup is for.';
    }
    if ($when === null) {
        $errors[] = 'Choose a date and time.';
    } elseif (strtotime($when) < time() - 60) {
        $errors[] = 'Choose a future date and time.';
    }

    if (!$errors) {
        try {
            $code = generate_code('AP');
            $id = db_insert(
                'INSERT INTO appointments (appointment_code, patient_id, preferred_at, checkup_reason, panel_codes, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'pending\')',
                [
                    $code,
                    (int) $patient['id'],
                    $when,
                    $reason,
                    $selected ? implode(',', $selected) : null,
                    $notes ?: null,
                ]
            );
            audit_log('appointment_book', 'appointment', $id, "Patient booked {$code}");
            flash('success', "Checkup {$code} requested. The clinic will set your approved time.");
            redirect('portal/dashboard.php');
        } catch (Throwable $e) {
            $errors[] = 'Could not save the booking. Please try again.';
        }
    }
}

$pageTitle = 'Book checkup — AI-LIS';
require __DIR__ . '/../includes/header.php';
$postedPanels = (array) ($_POST['panels'] ?? []);
?>
<div class="card">
    <h1>Book a checkup</h1>
    <p class="muted">Request a visit for <?= e($patient['patient_code']) ?>. Staff approve the time. If you do not arrive within <?= (int) APPOINTMENT_GRACE_MINUTES ?> minutes of that approved time, the booking expires.</p>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <div class="form-row">
            <label for="checkup_reason">Reason for checkup</label>
            <input id="checkup_reason" name="checkup_reason" required maxlength="255" value="<?= e($_POST['checkup_reason'] ?? '') ?>" placeholder="Example: annual laboratory checkup">
        </div>
        <div class="form-row">
            <label for="preferred_at">Preferred date and time</label>
            <input id="preferred_at" type="datetime-local" name="preferred_at" required value="<?= e($_POST['preferred_at'] ?? '') ?>">
        </div>
        <?php if ($panels): ?>
            <div class="form-row">
                <label>Laboratory tests to request</label>
                <div class="grid grid-3">
                    <?php foreach ($panels as $code => $label): ?>
                        <label style="color:var(--ink)">
                            <input type="checkbox" name="panels[]" value="<?= e($code) ?>" <?= in_array($code, $postedPanels, true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="form-row">
            <label for="notes">Notes for the clinic</label>
            <textarea id="notes" name="notes"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Request checkup</button>
            <a class="btn btn-secondary" href="<?= e(base_url('portal/dashboard.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
