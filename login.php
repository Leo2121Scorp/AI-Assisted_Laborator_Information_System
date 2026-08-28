<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif (attempt_login($username, $password)) {
        flash('success', 'Welcome back.');
        redirect('dashboard.php');
    } else {
        $error = 'Invalid credentials.';
    }
}
$pageTitle = 'Login — AI-LIS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="login-wrap">
    <div class="card login-card">
        <h1>AI-Assisted LIS</h1>
        <p class="sub"><?= e(app_config('lab_name')) ?></p>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="form-row">
                <label for="username">Username</label>
                <input id="username" name="username" required value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-row">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>
            </div>
            <button class="btn" type="submit">Log In</button>
        </form>
        <p class="sub" style="margin-top:1rem;font-size:0.85rem">
            Demo: manager / medtech / staff — password <code>password123</code>
        </p>
    </div>
</div>
</body>
</html>
