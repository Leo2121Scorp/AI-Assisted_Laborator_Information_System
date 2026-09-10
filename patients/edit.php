<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('edit_patients');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM patients WHERE id = ?');
$stmt->execute([$id]);
$patient = $stmt->fetch();
if (!$patient) {
    flash('error', 'Patient not found.');
    redirect('patients/index.php');
}

$errors = [];
$values = [
    'first_name' => $patient['first_name'],
    'middle_name' => (string) ($patient['middle_name'] ?? ''),
    'last_name' => $patient['last_name'],
    'sex' => $patient['sex'],
    'birth_date' => $patient['birth_date'],
    'contact_number' => (string) ($patient['contact_number'] ?? ''),
    'address' => (string) ($patient['address'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['first_name'] = trim($_POST['first_name'] ?? '');
    $values['last_name'] = trim($_POST['last_name'] ?? '');
    $values['middle_name'] = trim($_POST['middle_name'] ?? '');
    $values['sex'] = $_POST['sex'] ?? '';
    $values['birth_date'] = $_POST['birth_date'] ?? '';
    $values['contact_number'] = trim($_POST['contact_number'] ?? '');
    $values['address'] = trim($_POST['address'] ?? '');

    if ($values['first_name'] === '' || $values['last_name'] === '') {
        $errors[] = 'First and last name are required.';
    }
    if (!in_array($values['sex'], ['M', 'F'], true)) {
        $errors[] = 'Sex is required.';
    }
    if ($values['birth_date'] === '') {
        $errors[] = 'Birth date is required.';
    }

    if (!$errors) {
        db()->prepare(
            'UPDATE patients
             SET first_name = ?, last_name = ?, middle_name = ?, sex = ?, birth_date = ?,
                 contact_number = ?, address = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            $values['first_name'],
            $values['last_name'],
            $values['middle_name'] !== '' ? $values['middle_name'] : null,
            $values['sex'],
            $values['birth_date'],
            $values['contact_number'] !== '' ? $values['contact_number'] : null,
            $values['address'] !== '' ? $values['address'] : null,
            date('Y-m-d H:i:s'),
            $id,
        ]);
        audit_log('patient_update', 'patient', $id, 'Updated ' . $patient['patient_code']);
        flash('success', 'Patient ' . $patient['patient_code'] . ' updated.');
        redirect('patients/view.php?id=' . $id);
    }
}

$pageTitle = 'Edit Patient — ' . $patient['patient_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Edit Patient</h1>
    <p class="muted">Code <?= e($patient['patient_code']) ?> cannot be changed.</p>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-row inline">
            <div><label>First name</label><input name="first_name" required value="<?= e($values['first_name']) ?>"></div>
            <div><label>Middle name</label><input name="middle_name" value="<?= e($values['middle_name']) ?>"></div>
            <div><label>Last name</label><input name="last_name" required value="<?= e($values['last_name']) ?>"></div>
        </div>
        <div class="form-row inline">
            <div>
                <label>Sex</label>
                <select name="sex" required>
                    <option value="">Select</option>
                    <option value="M" <?= $values['sex'] === 'M' ? 'selected' : '' ?>>Male</option>
                    <option value="F" <?= $values['sex'] === 'F' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>
            <div><label>Birth date</label><input type="date" name="birth_date" required value="<?= e($values['birth_date']) ?>"></div>
            <div><label>Contact</label><input name="contact_number" value="<?= e($values['contact_number']) ?>"></div>
        </div>
        <div class="form-row">
            <label>Address</label>
            <textarea name="address"><?= e($values['address']) ?></textarea>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Save changes</button>
            <a class="btn btn-secondary" href="<?= e(base_url('patients/view.php?id=' . $id)) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
