<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect(has_role(ROLE_PATIENT) ? 'portal/dashboard.php' : 'dashboard.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $sex = $_POST['sex'] ?? '';
    $birth = $_POST['birth_date'] ?? '';
    $contact = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($first === '' || $last === '') {
        $errors[] = 'First and last name are required.';
    }
    if (!in_array($sex, ['M', 'F'], true)) {
        $errors[] = 'Sex is required.';
    }
    if (!is_iso_date($birth) || $birth > date('Y-m-d')) {
        $errors[] = 'A valid birth date is required.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3–50 characters and use only letters, numbers, dots, dashes, or underscores.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $taken = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $taken->execute([$username]);
        if ($taken->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $userId = db_insert(
                'INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)',
                [
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $first . ' ' . $last,
                    ROLE_PATIENT,
                ],
                $pdo
            );
            if ($userId <= 0) {
                throw new RuntimeException('Could not create the login.');
            }
            $code = generate_code('PT');
            $patientId = db_insert(
                'INSERT INTO patients (patient_code, first_name, last_name, middle_name, sex, birth_date, contact_number, address, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $first, $last, $middle ?: null, $sex, $birth, $contact ?: null, $address ?: null, $userId],
                $pdo
            );
            if ($patientId <= 0) {
                throw new RuntimeException('Could not create the patient record.');
            }
            $pdo->commit();
            audit_log('patient_register', 'patient', $patientId, "Portal registration {$code}");
            if (!attempt_login($username, $password)) {
                flash('success', 'Account created. Please log in.');
                redirect('login.php');
            }
            flash('success', 'Welcome. You can book a checkup and view released results here.');
            redirect('portal/dashboard.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Could not create the account. Try a different username.';
        }
    }
}

$pageTitle = 'Create patient account — AI-LIS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= e(base_url('assets/img/clinic-logo.png')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
<div class="login-wrap">
    <div class="card login-card">
        <img class="login-logo" src="<?= e(base_url('assets/img/clinic-logo.png')) ?>" alt="Lagman Qualicare logo" width="96" height="96">
        <h1>Patient account</h1>
        <p class="sub">Register to book a checkup and view your released laboratory results.</p>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" autocomplete="off">
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
            <div class="form-row">
                <label for="username">Username</label>
                <input id="username" name="username" required value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-row inline">
                <div>
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required>
                </div>
                <div>
                    <label for="password_confirm">Confirm password</label>
                    <input id="password_confirm" type="password" name="password_confirm" required>
                </div>
            </div>
            <div class="actions">
                <button class="btn" type="submit">Create account</button>
                <a class="btn btn-secondary" href="<?= e(base_url('login.php')) ?>">Back to login</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
