<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('specimen_collect');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT s.*, lr.request_code, lr.id AS request_id, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
     FROM specimens s
     JOIN lab_requests lr ON lr.id = s.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     WHERE s.id = ?"
);
$stmt->execute([$id]);
$specimen = $stmt->fetch();
if (!$specimen) {
    flash('error', 'Specimen not found.');
    redirect('specimens/index.php');
}

$allowed = specimen_transitions()[$specimen['status']] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = $_POST['status'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    if (update_specimen_status($id, $newStatus, $notes ?: null)) {
        if ($newStatus === 'collected') {
            db()->prepare("UPDATE lab_requests SET status = 'in_progress' WHERE id = ? AND status = 'open'")
                ->execute([$specimen['request_id']]);
        }
        flash('success', "Specimen status updated to {$newStatus}.");
        redirect('specimens/view.php?id=' . $id);
    }
    flash('error', 'Invalid status transition or permission denied.');
    redirect('specimens/view.php?id=' . $id);
}

$pageTitle = 'Specimen ' . $specimen['specimen_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Specimen <?= e($specimen['specimen_code']) ?></h1>
    <p>Patient: <?= e($specimen['patient_name']) ?></p>
    <p>Request: <a href="<?= e(base_url('requests/view.php?id=' . $specimen['request_id'])) ?>"><?= e($specimen['request_code']) ?></a></p>
    <p>Type: <?= e($specimen['specimen_type']) ?></p>
    <p>Current status: <span class="badge"><?= e($specimen['status']) ?></span></p>
    <p>Collected at: <?= e($specimen['collected_at'] ?: '—') ?></p>
    <p>Notes: <?= e($specimen['notes'] ?: '—') ?></p>
</div>
<?php if ($allowed): ?>
<div class="card">
    <h2>Update status</h2>
    <form method="post">
        <div class="form-row">
            <label>New status</label>
            <select name="status" required>
                <?php foreach ($allowed as $st): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label>Notes</label>
            <textarea name="notes"></textarea>
        </div>
        <button class="btn" type="submit">Save status</button>
    </form>
</div>
<?php else: ?>
<div class="card"><p>No further status transitions (completed).</p></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
