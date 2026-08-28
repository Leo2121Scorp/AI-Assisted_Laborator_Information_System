<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('patients');

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM patients';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE patient_code LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR contact_number LIKE ?';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
}
$sql .= ' ORDER BY created_at DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();

$pageTitle = 'Patients — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Patients</h1>
    <form method="get" class="form-row inline">
        <div>
            <label>Search</label>
            <input name="q" value="<?= e($q) ?>" placeholder="Code, name, contact">
        </div>
        <div style="align-self:end">
            <button class="btn" type="submit">Search</button>
            <a class="btn btn-secondary" href="<?= e(base_url('patients/create.php')) ?>">Register patient</a>
        </div>
    </form>
</div>
<div class="card">
    <table>
        <thead>
        <tr><th>Code</th><th>Name</th><th>Sex</th><th>Age</th><th>Contact</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($patients as $p): ?>
            <tr>
                <td><?= e($p['patient_code']) ?></td>
                <td><?= e($p['last_name'] . ', ' . $p['first_name']) ?></td>
                <td><?= e($p['sex']) ?></td>
                <td><?= patient_age($p['birth_date']) ?></td>
                <td><?= e($p['contact_number']) ?></td>
                <td><a href="<?= e(base_url('patients/view.php?id=' . $p['id'])) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$patients): ?><tr><td colspan="6">No patients found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
