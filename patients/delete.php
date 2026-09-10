<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('delete_patients');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM patients WHERE id = ?');
$stmt->execute([$id]);
$patient = $stmt->fetch();
if (!$patient) {
    flash('error', 'Patient not found.');
    redirect('patients/index.php');
}

$reqCountStmt = db()->prepare('SELECT COUNT(*) FROM lab_requests WHERE patient_id = ?');
$reqCountStmt->execute([$id]);
$requestCount = (int) $reqCountStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $reqStmt = $pdo->prepare('SELECT id FROM lab_requests WHERE patient_id = ?');
        $reqStmt->execute([$id]);
        $requestIds = $reqStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($requestIds) {
            $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
            $pdo->prepare("DELETE FROM lab_results WHERE lab_request_id IN ($placeholders)")->execute($requestIds);
            $pdo->prepare('DELETE FROM lab_requests WHERE patient_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM patients WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Could not delete this patient. Related laboratory records may still be in use.');
        redirect('patients/view.php?id=' . $id);
    }

    $details = 'Deleted ' . $patient['patient_code'];
    if ($requestCount > 0) {
        $details .= ' and ' . $requestCount . ' related lab request(s)';
    }
    audit_log('patient_delete', 'patient', $id, $details);
    flash('success', 'Patient ' . $patient['patient_code'] . ' deleted.');
    redirect('patients/index.php');
}

$pageTitle = 'Delete Patient — ' . $patient['patient_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Delete Patient</h1>
    <p>Permanently delete <strong><?= e($patient['last_name'] . ', ' . $patient['first_name']) ?></strong>
        (<?= e($patient['patient_code']) ?>)?</p>
    <?php if ($requestCount > 0): ?>
        <div class="alert alert-warning">
            This patient has <?= $requestCount ?> laboratory request<?= $requestCount === 1 ? '' : 's' ?>.
            Deleting will also remove those requests, specimens, and results.
        </div>
    <?php else: ?>
        <p class="muted">This patient has no laboratory requests.</p>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="actions">
            <button class="btn btn-danger" type="submit">Delete patient</button>
            <a class="btn btn-secondary" href="<?= e(base_url('patients/view.php?id=' . $id)) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
