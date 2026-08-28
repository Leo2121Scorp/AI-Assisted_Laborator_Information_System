<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('requests');

$patients = db()->query('SELECT id, patient_code, first_name, last_name FROM patients ORDER BY last_name')->fetchAll();
$tests = db()->query('SELECT * FROM lab_tests WHERE is_active = 1 ORDER BY panel_code, test_code')->fetchAll();
$preselect = (int) ($_GET['patient_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $physician = trim($_POST['requesting_physician'] ?? '');
    $notes = trim($_POST['clinical_notes'] ?? '');
    $selected = array_map('intval', $_POST['tests'] ?? []);
    $specimenType = trim($_POST['specimen_type'] ?? 'Blood');

    if ($patientId <= 0) {
        $errors[] = 'Select a patient.';
    }
    if (!$selected) {
        $errors[] = 'Select at least one test.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $reqCode = generate_code('RQ');
            $pdo->prepare(
                'INSERT INTO lab_requests (request_code, patient_id, requesting_physician, clinical_notes, status, created_by)
                 VALUES (?, ?, ?, ?, \'open\', ?)'
            )->execute([$reqCode, $patientId, $physician ?: null, $notes ?: null, current_user()['id']]);
            $requestId = (int) $pdo->lastInsertId();

            $insTest = $pdo->prepare('INSERT INTO request_tests (lab_request_id, lab_test_id) VALUES (?, ?)');
            $panelCodes = [];
            foreach ($selected as $testId) {
                $insTest->execute([$requestId, $testId]);
                $t = get_lab_test($testId);
                if ($t) {
                    $panelCodes[$t['panel_code']] = true;
                }
            }

            $specCode = generate_code('SP');
            $pdo->prepare(
                'INSERT INTO specimens (specimen_code, lab_request_id, specimen_type, status, updated_by)
                 VALUES (?, ?, ?, \'pending\', ?)'
            )->execute([$specCode, $requestId, $specimenType ?: 'Blood', current_user()['id']]);
            $specimenId = (int) $pdo->lastInsertId();

            // One result record per panel
            foreach (array_keys($panelCodes) as $panel) {
                $resCode = generate_code('RS');
                $pdo->prepare(
                    'INSERT INTO lab_results (result_code, lab_request_id, specimen_id, panel_code, status)
                     VALUES (?, ?, ?, ?, \'pending\')'
                )->execute([$resCode, $requestId, $specimenId, $panel]);
            }

            $pdo->commit();
            audit_log('request_create', 'lab_request', $requestId, "Created {$reqCode}");
            flash('success', "Request {$reqCode} created with specimen {$specCode}.");
            redirect('requests/view.php?id=' . $requestId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Could not create request: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'New Laboratory Request — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Create Laboratory Request</h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <div class="form-row">
            <label>Patient</label>
            <select name="patient_id" required>
                <option value="">Select patient</option>
                <?php foreach ($patients as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= ($preselect === (int)$p['id'] || (int)($_POST['patient_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                        <?= e($p['patient_code'] . ' — ' . $p['last_name'] . ', ' . $p['first_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row inline">
            <div><label>Requesting physician</label><input name="requesting_physician" value="<?= e($_POST['requesting_physician'] ?? '') ?>"></div>
            <div><label>Specimen type</label><input name="specimen_type" value="<?= e($_POST['specimen_type'] ?? 'Blood') ?>"></div>
        </div>
        <div class="form-row">
            <label>Clinical notes</label>
            <textarea name="clinical_notes"><?= e($_POST['clinical_notes'] ?? '') ?></textarea>
        </div>
        <div class="form-row">
            <label>Tests / analytes</label>
            <div class="grid grid-3">
                <?php
                $posted = array_map('intval', $_POST['tests'] ?? []);
                foreach ($tests as $t):
                ?>
                    <label style="color:var(--ink)">
                        <input type="checkbox" name="tests[]" value="<?= (int) $t['id'] ?>"
                            <?= in_array((int)$t['id'], $posted, true) ? 'checked' : '' ?>>
                        <?= e($t['panel_code'] . ' / ' . $t['test_code'] . ' — ' . $t['test_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Create request</button>
            <a class="btn btn-secondary" href="<?= e(base_url('requests/index.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
