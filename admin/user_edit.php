<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('manage_users');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) {
    flash('error', 'User not found.');
    redirect('admin/users.php');
}

$isSelf = (int) current_user()['id'] === $id;
$errors = [];
$values = [
    'username' => $user['username'],
    'full_name' => $user['full_name'],
    'role' => $user['role'],
    'is_active' => (int) $user['is_active'] === 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['username'] = trim($_POST['username'] ?? '');
    $values['full_name'] = trim($_POST['full_name'] ?? '');
    $values['role'] = $_POST['role'] ?? '';
    $values['is_active'] = isset($_POST['is_active']);
    $password = (string) ($_POST['password'] ?? '');

    if ($values['username'] === '' || $values['full_name'] === '') {
        $errors[] = 'Username and full name are required.';
    }
    if (!in_array($values['role'], ['manager', 'med_tech', 'staff'], true)) {
        $errors[] = 'A valid role is required.';
    }
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters, or leave it blank to keep the current password.';
    }
    if ($isSelf && !$values['is_active']) {
        $errors[] = 'You cannot deactivate your own account.';
    }
    if ($isSelf && $values['role'] !== ROLE_MANAGER && is_last_active_manager($user)) {
        $errors[] = 'You cannot change your own role while you are the last active manager.';
    }
    if (!$isSelf && is_last_active_manager($user) && ($values['role'] !== ROLE_MANAGER || !$values['is_active'])) {
        $errors[] = 'This is the last active manager. Keep the manager role and leave the account active.';
    }

    if (!$errors) {
        try {
            $sql = 'UPDATE users SET username = ?, full_name = ?, role = ?, is_active = ?, updated_at = ?';
            $params = [
                $values['username'],
                $values['full_name'],
                $values['role'],
                $values['is_active'] ? 1 : 0,
                date('Y-m-d H:i:s'),
            ];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            db()->prepare($sql)->execute($params);

            if ($isSelf) {
                $_SESSION['user']['username'] = $values['username'];
                $_SESSION['user']['full_name'] = $values['full_name'];
                $_SESSION['user']['role'] = $values['role'];
            }

            audit_log('user_update', 'user', $id, 'Updated ' . $values['username']);
            flash('success', 'User ' . $values['username'] . ' updated.');
            redirect('admin/users.php');
        } catch (Throwable $e) {
            $errors[] = 'Could not update user (username may already exist).';
        }
    }
}

$pageTitle = 'Edit User — ' . $user['username'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Edit User</h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-row inline">
            <div><label>Username</label><input name="username" required value="<?= e($values['username']) ?>"></div>
            <div><label>Full name</label><input name="full_name" required value="<?= e($values['full_name']) ?>"></div>
            <div>
                <label>Role</label>
                <select name="role" required>
                    <option value="manager" <?= $values['role'] === 'manager' ? 'selected' : '' ?>>manager</option>
                    <option value="med_tech" <?= $values['role'] === 'med_tech' ? 'selected' : '' ?>>med_tech</option>
                    <option value="staff" <?= $values['role'] === 'staff' ? 'selected' : '' ?>>staff</option>
                </select>
            </div>
        </div>
        <div class="form-row inline">
            <div>
                <label>New password</label>
                <input type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep current">
            </div>
            <div style="align-self:end">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>
                        <?= $isSelf ? 'disabled' : '' ?>>
                    Active (can sign in)
                </label>
                <?php if ($isSelf): ?>
                    <input type="hidden" name="is_active" value="1">
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Save changes</button>
            <a class="btn btn-secondary" href="<?= e(base_url('admin/users.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
