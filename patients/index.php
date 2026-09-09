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
    <div class="card-head">
        <div>
            <h1>Patients</h1>
            <p class="muted">Register and find patients quickly by code, name, or contact.</p>
        </div>
        <a class="btn" href="<?= e(base_url('patients/create.php')) ?>">Register patient</a>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search code, name, or contact…"<?= $q === '' ? ' autofocus' : '' ?>>
        <button class="btn" type="submit">Search</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('patients/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>
<div class="card">
    <?php if (!$patients): ?>
        <div class="empty-state">
            <p><?= $q !== '' ? 'No patients match your search.' : 'No patients yet. Register the first patient to start a lab request.' ?></p>
            <a class="btn" href="<?= e(base_url('patients/create.php')) ?>">Register patient</a>
        </div>
    <?php else: ?>
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
                    <td><a class="btn btn-small btn-secondary" href="<?= e(base_url('patients/view.php?id=' . $p['id'])) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
