<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('patients');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM patients WHERE id = ?');
$stmt->execute([$id]);
$patient = $stmt->fetch();
if (!$patient) {
    flash('error', 'Patient not found.');
    redirect('patients/index.php');
}

$req = db()->prepare(
    'SELECT * FROM lab_requests WHERE patient_id = ? ORDER BY created_at DESC'
);
$req->execute([$id]);
$requests = $req->fetchAll();

$pageTitle = 'Patient — ' . $patient['patient_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1><?= e($patient['last_name'] . ', ' . $patient['first_name']) ?></h1>
    <p>
        <strong>Code:</strong> <?= e($patient['patient_code']) ?> |
        <strong>Sex:</strong> <?= e($patient['sex']) ?> |
        <strong>Age:</strong> <?= patient_age($patient['birth_date']) ?> |
        <strong>DOB:</strong> <?= e($patient['birth_date']) ?>
    </p>
    <p><strong>Contact:</strong> <?= e($patient['contact_number'] ?: '—') ?></p>
    <p><strong>Address:</strong> <?= e($patient['address'] ?: '—') ?></p>
    <div class="actions">
        <a class="btn" href="<?= e(base_url('requests/create.php?patient_id=' . $id)) ?>">Create laboratory request</a>
        <a class="btn btn-secondary" href="<?= e(base_url('patients/index.php')) ?>">Back</a>
    </div>
</div>
<div class="card">
    <h2>Laboratory requests</h2>
    <table>
        <thead><tr><th>Code</th><th>Status</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><?= e($r['request_code']) ?></td>
                <td><span class="badge"><?= e($r['status']) ?></span></td>
                <td><?= e($r['created_at']) ?></td>
                <td><a href="<?= e(base_url('requests/view.php?id=' . $r['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="4">No requests yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
