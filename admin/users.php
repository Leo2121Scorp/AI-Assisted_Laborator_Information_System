<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('manage_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';
    if ($username && $fullName && in_array($role, ['manager', 'med_tech', 'staff'], true) && strlen($password) >= 6) {
        try {
            db()->prepare(
                'INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)'
            )->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);
            audit_log('user_create', 'user', (int) db()->lastInsertId(), "Created {$username}");
            flash('success', 'User created.');
        } catch (Throwable $e) {
            flash('error', 'Could not create user (username may already exist).');
        }
    } else {
        flash('error', 'Invalid user data. Password must be at least 6 characters.');
    }
    redirect('admin/users.php');
}

$users = db()->query('SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY id')->fetchAll();
$pageTitle = 'Users — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>User Management</h1>
</div>
<div class="card">
    <h2>Add user</h2>
    <form method="post" class="form-row inline">
        <div><label>Username</label><input name="username" required></div>
        <div><label>Full name</label><input name="full_name" required></div>
        <div>
            <label>Role</label>
            <select name="role">
                <option value="manager">manager</option>
                <option value="med_tech">med_tech</option>
                <option value="staff">staff</option>
            </select>
        </div>
        <div><label>Password</label><input type="password" name="password" required></div>
        <div style="align-self:end"><button class="btn" type="submit">Create</button></div>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>ID</th><th>Username</th><th>Name</th><th>Role</th><th>Active</th><th>Created</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= e($u['username']) ?></td>
                <td><?= e($u['full_name']) ?></td>
                <td><?= e($u['role']) ?></td>
                <td><?= $u['is_active'] ? 'yes' : 'no' ?></td>
                <td><?= e($u['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
