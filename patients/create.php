<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('patients');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $sex = $_POST['sex'] ?? '';
    $birth = $_POST['birth_date'] ?? '';
    $contact = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($first === '' || $last === '') {
        $errors[] = 'First and last name are required.';
    }
    if (!in_array($sex, ['M', 'F'], true)) {
        $errors[] = 'Sex is required.';
    }
    if ($birth === '') {
        $errors[] = 'Birth date is required.';
    }

    if (!$errors) {
        $code = generate_code('PT');
        $stmt = db()->prepare(
            'INSERT INTO patients (patient_code, first_name, last_name, middle_name, sex, birth_date, contact_number, address, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code, $first, $last, $middle ?: null, $sex, $birth, $contact ?: null, $address ?: null, current_user()['id']
        ]);
        $id = (int) db()->lastInsertId();
        audit_log('patient_create', 'patient', $id, "Registered {$code}");
        flash('success', "Patient {$code} registered.");
        redirect('patients/view.php?id=' . $id);
    }
}

$pageTitle = 'Register Patient — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Register Patient</h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <div class="form-row inline">
            <div><label>First name</label><input name="first_name" required value="<?= e($_POST['first_name'] ?? '') ?>"></div>
            <div><label>Middle name</label><input name="middle_name" value="<?= e($_POST['middle_name'] ?? '') ?>"></div>
            <div><label>Last name</label><input name="last_name" required value="<?= e($_POST['last_name'] ?? '') ?>"></div>
        </div>
        <div class="form-row inline">
            <div>
                <label>Sex</label>
                <select name="sex" required>
                    <option value="">Select</option>
                    <option value="M" <?= ($_POST['sex'] ?? '') === 'M' ? 'selected' : '' ?>>Male</option>
                    <option value="F" <?= ($_POST['sex'] ?? '') === 'F' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>
            <div><label>Birth date</label><input type="date" name="birth_date" required value="<?= e($_POST['birth_date'] ?? '') ?>"></div>
            <div><label>Contact</label><input name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <label>Address</label>
            <textarea name="address"><?= e($_POST['address'] ?? '') ?></textarea>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Save patient</button>
            <a class="btn btn-secondary" href="<?= e(base_url('patients/index.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
