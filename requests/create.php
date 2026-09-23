<?php
/**
 * requests/create.php — Create a new laboratory request
 *
 * What this page does:
 * Lets you pick a patient, physician, specimen type, notes, and one or more tests.
 * On save it creates: a lab_request, linked tests, one specimen, and pending result
 * rows (one per test panel). Then redirects to the request view page.
 *
 * POST vs GET:
 * - GET  = show the form. Optional ?patient_id=... pre-selects that patient.
 * - POST = validate and insert everything in one database transaction.
 *
 * Transaction tip: beginTransaction + commit means all inserts succeed together,
 * or rollBack undoes them if something fails mid-way.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('requests');

// ---------------------------------------------------------------------------
// Load dropdown data from the database (patients + active lab tests)
// ---------------------------------------------------------------------------
$patients = db()->query('SELECT id, patient_code, first_name, last_name FROM patients ORDER BY last_name')->fetchAll();
$tests = db()->query('SELECT * FROM lab_tests WHERE is_active = 1 ORDER BY panel_code, sort_order, test_code')->fetchAll();

// Group tests by panel code so the HTML can show CBC / Chemistry / Urine / Stool sections
$testsByPanel = [];
foreach ($tests as $t) {
    $testsByPanel[$t['panel_code']][] = $t;
}
$panelLabels = [
    'CBC' => 'Hematology / CBC',
    'CHEMISTRY' => 'Chemistry (blood)',
    'URINE' => 'Urinalysis',
    'STOOL' => 'Fecalysis',
];
// Group checkboxes the same way the printed result sheets are laid out
$cbcSections = [
    'Hematology' => ['HGB', 'HCT', 'RBC', 'WBC', 'PLT'],
    'Differential count' => ['SEG', 'LYM', 'MON', 'EOS'],
    'Red cell indices' => ['MCV', 'MCH', 'MCHC'],
];
$urineSections = [
    'Physical Examination' => ['COLOR', 'APPEARANCE', 'PH', 'SG'],
    'Chemical Examination' => ['GLU', 'PRO', 'KET', 'BLD', 'BIL', 'UBG', 'NIT', 'LEU'],
    'Microscopic Examination' => ['WBC', 'RBC', 'MTHR', 'AUR', 'EC', 'BAC', 'CAST', 'CRYS', 'YST'],
];
$stoolSections = [
    'Physical Examination' => ['COLOR', 'CONS'],
    'Microscopic Examination' => ['WBC', 'RBC', 'BAC'],
    'Parasite result' => ['PARA'],
    'Other stool exams' => ['MUC', 'BLOOD', 'OVA', 'CYST', 'TROPH', 'YEAST', 'FAT', 'FOB'],
];
$panelSections = [
    'CBC' => $cbcSections,
    'URINE' => $urineSections,
    'STOOL' => $stoolSections,
];

// Optional pre-select from patient view page: create.php?patient_id=12
$preselect = (int) ($_GET['patient_id'] ?? 0);
$errors = [];

// ---------------------------------------------------------------------------
// Form handling — POST creates request + tests + specimen + result shells
// ---------------------------------------------------------------------------
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
            // 1) Main request row
            $reqCode = generate_code('RQ');
            $requestId = db_insert(
                'INSERT INTO lab_requests (request_code, patient_id, requesting_physician, clinical_notes, status, created_by)
                 VALUES (?, ?, ?, ?, \'open\', ?)',
                [$reqCode, $patientId, $physician ?: null, $notes ?: null, current_user()['id']],
                $pdo
            );
            if ($requestId <= 0) {
                throw new RuntimeException('Could not read new request id (Postgres RETURNING).');
            }

            // 2) Link each selected test; remember which panels were chosen
            $insTest = $pdo->prepare('INSERT INTO request_tests (lab_request_id, lab_test_id) VALUES (?, ?)');
            $panelCodes = [];
            foreach ($selected as $testId) {
                $insTest->execute([$requestId, $testId]);
                $t = get_lab_test($testId);
                if ($t) {
                    $panelCodes[$t['panel_code']] = true;
                }
            }

            // 3) One specimen to track the sample for this request
            $specCode = generate_code('SP');
            $specimenId = db_insert(
                'INSERT INTO specimens (specimen_code, lab_request_id, specimen_type, status, updated_by)
                 VALUES (?, ?, ?, \'pending\', ?)',
                [$specCode, $requestId, $specimenType ?: 'Blood', current_user()['id']],
                $pdo
            );
            if ($specimenId <= 0) {
                throw new RuntimeException('Could not read new specimen id.');
            }

            // 4) One pending result record per panel (e.g. CBC, CHEMISTRY)
            foreach (array_keys($panelCodes) as $panel) {
                $resCode = generate_code('RS');
                db_insert(
                    'INSERT INTO lab_results (result_code, lab_request_id, specimen_id, panel_code, status)
                     VALUES (?, ?, ?, ?, \'pending\')',
                    [$resCode, $requestId, $specimenId, $panel],
                    $pdo
                );
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

// ---------------------------------------------------------------------------
// HTML display — create form
// ---------------------------------------------------------------------------
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
            <?php
            // Remember checked boxes after a failed POST so the user does not re-tick everything
            $posted = array_map('intval', $_POST['tests'] ?? []);
            foreach ($testsByPanel as $panel => $panelTests):
                $title = $panelLabels[$panel] ?? $panel;
            ?>
                <div style="margin-top:0.85rem">
                    <strong style="display:block;margin-bottom:0.45rem"><?= e($title) ?></strong>
                    <?php if (isset($panelSections[$panel])): ?>
                        <?php
                        $byCode = [];
                        foreach ($panelTests as $t) {
                            $byCode[$t['test_code']] = $t;
                        }
                        $sections = $panelSections[$panel];
                        $listed = [];
                        foreach ($sections as $codes) {
                            foreach ($codes as $code) {
                                $listed[$code] = true;
                            }
                        }
                        $leftover = [];
                        foreach ($byCode as $code => $unused) {
                            if (!isset($listed[$code])) {
                                $leftover[] = $code;
                            }
                        }
                        if ($leftover) {
                            $sections['Other'] = $leftover;
                        }
                        foreach ($sections as $section => $codes):
                        ?>
                            <p class="muted" style="margin:0.55rem 0 0.3rem"><?= e($section) ?></p>
                            <div class="grid grid-3">
                                <?php foreach ($codes as $code):
                                    if (!isset($byCode[$code])) {
                                        continue;
                                    }
                                    $t = $byCode[$code];
                                ?>
                                    <label style="color:var(--ink)">
                                        <input type="checkbox" name="tests[]" value="<?= (int) $t['id'] ?>"
                                            <?= in_array((int)$t['id'], $posted, true) ? 'checked' : '' ?>>
                                        <?= e($t['panel_code'] . ' / ' . $t['test_code'] . ' — ' . $t['test_name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="grid grid-3">
                            <?php foreach ($panelTests as $t): ?>
                                <label style="color:var(--ink)">
                                    <input type="checkbox" name="tests[]" value="<?= (int) $t['id'] ?>"
                                        <?= in_array((int)$t['id'], $posted, true) ? 'checked' : '' ?>>
                                    <?= e($t['panel_code'] . ' / ' . $t['test_code'] . ' — ' . $t['test_name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Create request</button>
            <a class="btn btn-secondary" href="<?= e(base_url('requests/index.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
