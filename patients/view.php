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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'portal_account') {
    $password = (string) ($_POST['password'] ?? '');
    $username = trim($_POST['username'] ?? '');
    if (strlen($password) < 6) {
        flash('error', 'Portal password must be at least 6 characters.');
        redirect('patients/view.php?id=' . $id);
    }
    try {
        if (!empty($patient['user_id'])) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $patient['user_id'], ROLE_PATIENT]);
            audit_log('patient_portal_password', 'patient', $id, 'Reset patient portal password');
            flash('success', 'Patient portal password updated.');
        } else {
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                throw new RuntimeException('Username must be 3–50 characters and use only letters, numbers, dots, dashes, or underscores.');
            }
            $taken = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $taken->execute([$username]);
            if ($taken->fetch()) {
                throw new RuntimeException('That username is already taken.');
            }
            $userId = db_insert(
                'INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)',
                [
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    trim($patient['first_name'] . ' ' . $patient['last_name']),
                    ROLE_PATIENT,
                ]
            );
            db()->prepare('UPDATE patients SET user_id = ? WHERE id = ? AND user_id IS NULL')
                ->execute([$userId, $id]);
            audit_log('patient_portal_create', 'patient', $id, "Portal login {$username}");
            flash('success', 'Patient can now sign in to view results and book a checkup.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('patients/view.php?id=' . $id);
}

$portalUser = null;
if (!empty($patient['user_id'])) {
    $portalStmt = db()->prepare('SELECT id, username FROM users WHERE id = ? AND role = ?');
    $portalStmt->execute([(int) $patient['user_id'], ROLE_PATIENT]);
    $portalUser = $portalStmt->fetch() ?: null;
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
    <h2>Patient portal</h2>
    <?php if ($portalUser): ?>
        <p>Login username: <strong><?= e($portalUser['username']) ?></strong>. The patient uses this to view released results and book a checkup.</p>
        <form method="post" class="form-row inline">
            <input type="hidden" name="action" value="portal_account">
            <div>
                <label>New password</label>
                <input type="password" name="password" required minlength="6">
            </div>
            <div style="align-self:end"><button class="btn" type="submit">Reset portal password</button></div>
        </form>
    <?php else: ?>
        <p class="muted">Create a login so this patient can view released results and book a checkup.</p>
        <form method="post" class="form-row inline">
            <input type="hidden" name="action" value="portal_account">
            <div>
                <label>Username</label>
                <input name="username" required value="<?= e($patient['patient_code']) ?>">
            </div>
            <div>
                <label>Password</label>
                <input type="password" name="password" required minlength="6">
            </div>
            <div style="align-self:end"><button class="btn" type="submit">Create portal login</button></div>
        </form>
    <?php endif; ?>
    <div class="actions">
        <a class="btn" href="<?= e(base_url('requests/create.php?patient_id=' . $id)) ?>">Create laboratory request</a>
        <?php if (can('edit_patients')): ?>
            <a class="btn" href="<?= e(base_url('patients/edit.php?id=' . $id)) ?>">Edit</a>
        <?php endif; ?>
        <?php if (can('delete_patients')): ?>
            <a class="btn btn-danger" href="<?= e(base_url('patients/delete.php?id=' . $id)) ?>">Delete</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= e(base_url('patients/index.php')) ?>">Back</a>
    </div>
</div>
<div class="card">
    <h2>Laboratory requests</h2>
    <div class="table-scroll">
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
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
