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
$blocked = null;
if ($isSelf) {
    $blocked = 'You cannot delete your own account.';
} elseif (is_last_active_manager($user)) {
    $blocked = 'You cannot delete the last active manager.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($blocked) {
        flash('error', $blocked);
        redirect('admin/users.php');
    }
    try {
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        audit_log('user_delete', 'user', $id, 'Deleted ' . $user['username']);
        flash('success', 'User ' . $user['username'] . ' deleted.');
    } catch (Throwable $e) {
        flash('error', 'Could not delete this user. Related records may still reference the account.');
        redirect('admin/users.php');
    }
    redirect('admin/users.php');
}

$pageTitle = 'Delete User — ' . $user['username'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Delete User</h1>
    <?php if ($blocked): ?>
        <div class="alert alert-error"><?= e($blocked) ?></div>
        <div class="actions">
            <a class="btn btn-secondary" href="<?= e(base_url('admin/users.php')) ?>">Back</a>
        </div>
    <?php else: ?>
        <p>Permanently delete <strong><?= e($user['full_name']) ?></strong>
            (<?= e($user['username']) ?>, <?= e($user['role']) ?>)?</p>
        <p class="muted">Lab records they created stay in the system; their name is cleared from those links.</p>
        <form method="post">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="actions">
                <button class="btn btn-danger" type="submit">Delete user</button>
                <a class="btn btn-secondary" href="<?= e(base_url('admin/users.php')) ?>">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
