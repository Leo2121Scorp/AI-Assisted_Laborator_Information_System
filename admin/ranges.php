<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('manage_ranges');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testId = (int) ($_POST['lab_test_id'] ?? 0);
    $sex = $_POST['sex'] ?? 'A';
    $ageMin = (int) ($_POST['age_min'] ?? 0);
    $ageMax = (int) ($_POST['age_max'] ?? 150);
    $min = $_POST['min_value'] !== '' ? (float) $_POST['min_value'] : null;
    $max = $_POST['max_value'] !== '' ? (float) $_POST['max_value'] : null;
    $cl = $_POST['critical_low'] !== '' ? (float) $_POST['critical_low'] : null;
    $ch = $_POST['critical_high'] !== '' ? (float) $_POST['critical_high'] : null;
    if ($testId > 0 && in_array($sex, ['M', 'F', 'A'], true)) {
        db()->prepare(
            'INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$testId, $sex, $ageMin, $ageMax, $min, $max, $cl, $ch]);
        audit_log('range_create', 'reference_range', (int) db()->lastInsertId(), 'Added reference range');
        flash('success', 'Reference range added.');
    } else {
        flash('error', 'Invalid range data.');
    }
    redirect('admin/ranges.php');
}

$ranges = db()->query(
    'SELECT rr.*, lt.test_code, lt.test_name
     FROM reference_ranges rr
     JOIN lab_tests lt ON lt.id = rr.lab_test_id
     ORDER BY lt.panel_code, lt.test_code, rr.sex, rr.age_min'
)->fetchAll();
$tests = db()->query('SELECT id, test_code, test_name FROM lab_tests WHERE is_active = 1 ORDER BY test_code')->fetchAll();

$pageTitle = 'Reference Ranges — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Reference Ranges</h1>
    <p>Rule-based validation uses these ranges (sex and age aware).</p>
</div>
<div class="card">
    <h2>Add range</h2>
    <form method="post" class="form-row inline">
        <div>
            <label>Test</label>
            <select name="lab_test_id" required>
                <?php foreach ($tests as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= e($t['test_code'] . ' — ' . $t['test_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Sex</label>
            <select name="sex"><option value="A">Any</option><option value="M">M</option><option value="F">F</option></select>
        </div>
        <div><label>Age min</label><input type="number" name="age_min" value="18"></div>
        <div><label>Age max</label><input type="number" name="age_max" value="150"></div>
        <div><label>Min</label><input name="min_value"></div>
        <div><label>Max</label><input name="max_value"></div>
        <div><label>Critical low</label><input name="critical_low"></div>
        <div><label>Critical high</label><input name="critical_high"></div>
        <div style="align-self:end"><button class="btn" type="submit">Add</button></div>
    </form>
</div>
<div class="card">
    <table>
        <thead>
        <tr><th>Test</th><th>Sex</th><th>Age</th><th>Min</th><th>Max</th><th>Crit low</th><th>Crit high</th></tr>
        </thead>
        <tbody>
        <?php foreach ($ranges as $r): ?>
            <tr>
                <td><?= e($r['test_code']) ?></td>
                <td><?= e($r['sex']) ?></td>
                <td><?= (int)$r['age_min'] ?>–<?= (int)$r['age_max'] ?></td>
                <td><?= e((string)$r['min_value']) ?></td>
                <td><?= e((string)$r['max_value']) ?></td>
                <td><?= e((string)$r['critical_low']) ?></td>
                <td><?= e((string)$r['critical_high']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
