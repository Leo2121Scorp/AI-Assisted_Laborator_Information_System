<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('patients');

$q = trim($_GET['q'] ?? '');
$sex = $_GET['sex'] ?? '';
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if (!in_array($sex, ['M', 'F'], true)) {
    $sex = '';
}
if (!is_iso_date($from)) {
    $from = '';
}
if (!is_iso_date($to)) {
    $to = '';
}

$sql = 'SELECT * FROM patients';
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(patient_code LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR contact_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($sex !== '') {
    $where[] = 'sex = ?';
    $params[] = $sex;
}
if ($from !== '') {
    $where[] = sql_as_date('created_at') . ' >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = sql_as_date('created_at') . ' <= ?';
    $params[] = $to;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$limit = ($from !== '' || $to !== '') ? 500 : 100;
$sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();
$filtered = $q !== '' || $sex !== '' || $from !== '' || $to !== '';

$pageTitle = 'Patients — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <div>
            <h1>Patients</h1>
            <p class="muted">Search by code, name, or contact. Use the date range to open older records.</p>
        </div>
        <a class="btn" href="<?= e(base_url('patients/create.php')) ?>">Register patient</a>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search code, name, or contact…"<?= $q === '' ? ' autofocus' : '' ?>>
        <select name="sex" aria-label="Sex">
            <option value="">Any sex</option>
            <option value="M" <?= $sex === 'M' ? 'selected' : '' ?>>Male</option>
            <option value="F" <?= $sex === 'F' ? 'selected' : '' ?>>Female</option>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>" aria-label="Registered from">
        <input type="date" name="to" value="<?= e($to) ?>" aria-label="Registered to">
        <button class="btn" type="submit">Search</button>
        <?php if ($filtered): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('patients/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>
<div class="card">
    <?php if (!$patients): ?>
        <div class="empty-state">
            <p><?= $filtered ? 'No patients match these filters.' : 'No patients yet. Register the first patient to start a lab request.' ?></p>
            <a class="btn" href="<?= e(base_url('patients/create.php')) ?>">Register patient</a>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Code</th><th>Name</th><th>Sex</th><th>Age</th><th>Contact</th><th>Registered</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($patients as $p): ?>
                <tr>
                    <td><?= e($p['patient_code']) ?></td>
                    <td><?= e($p['last_name'] . ', ' . $p['first_name']) ?></td>
                    <td><?= e($p['sex']) ?></td>
                    <td><?= patient_age($p['birth_date']) ?></td>
                    <td><?= e($p['contact_number']) ?></td>
                    <td><?= e($p['created_at']) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-small btn-secondary" href="<?= e(base_url('patients/view.php?id=' . $p['id'])) ?>">View</a>
                            <?php if (can('edit_patients')): ?>
                                <a class="btn btn-small" href="<?= e(base_url('patients/edit.php?id=' . $p['id'])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if (can('delete_patients')): ?>
                                <a class="btn btn-small btn-danger" href="<?= e(base_url('patients/delete.php?id=' . $p['id'])) ?>">Delete</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
