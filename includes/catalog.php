<?php
declare(strict_types=1);

/**
 * Lab test catalog (CBC, Chemistry, Urine, Stool).
 *
 * This file keeps the list of tests in the database up to date.
 * It can safely run on every boot — if a test already exists, it updates the name/unit;
 * if a test is missing, it inserts it.
 * Also fixes the unique key so the same test_code can exist on different panels
 * (e.g. WBC on CBC and WBC on URINE).
 */

/**
 * Make sure the lab_tests table schema and rows are ready.
 * Safe to call on every boot (Render auto_install / local install refresh).
 */
function ensure_lab_test_catalog(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // --- Schema first, then data ---
    ensure_lab_tests_schema($pdo, $driver);
    upsert_lab_tests($pdo, $driver);
    upsert_catalog_reference_ranges($pdo);
}

/**
 * Add sort_order if missing, and ensure uniqueness is (panel_code, test_code).
 * Older installs had a global unique on test_code alone — that blocks urine/stool.
 */
function ensure_lab_tests_schema(PDO $pdo, string $driver): void
{
    // --- PostgreSQL (Render) ---
    if ($driver === 'pgsql') {
        $pdo->exec('ALTER TABLE lab_tests ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0');
        $uq = $pdo->query(
            "SELECT 1 FROM pg_constraint WHERE conname = 'lab_tests_panel_code_test_code_key' LIMIT 1"
        )->fetchColumn();
        if (!$uq) {
            // Drop legacy global unique on test_code if present
            $legacy = $pdo->query(
                "SELECT conname FROM pg_constraint
                 WHERE conrelid = 'lab_tests'::regclass AND contype = 'u'
                   AND pg_get_constraintdef(oid) LIKE '%(test_code)%'
                   AND pg_get_constraintdef(oid) NOT LIKE '%panel_code%'"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($legacy as $name) {
                $pdo->exec('ALTER TABLE lab_tests DROP CONSTRAINT IF EXISTS ' . $name);
            }
            $pdo->exec('ALTER TABLE lab_tests ADD CONSTRAINT lab_tests_panel_code_test_code_key UNIQUE (panel_code, test_code)');
        }
        return;
    }

    // --- MySQL (local XAMPP) ---
    try {
        $pdo->query('SELECT sort_order FROM lab_tests LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec('ALTER TABLE lab_tests ADD COLUMN sort_order INT NOT NULL DEFAULT 0');
    }
    $idx = $pdo->query("SHOW INDEX FROM lab_tests WHERE Key_name = 'uq_panel_test'")->fetch();
    if (!$idx) {
        try {
            $pdo->exec('ALTER TABLE lab_tests DROP INDEX test_code');
        } catch (Throwable $e) {
            // already dropped
        }
        $pdo->exec('ALTER TABLE lab_tests ADD UNIQUE KEY uq_panel_test (panel_code, test_code)');
    }
}

/**
 * Built-in list of all catalog tests.
 * Each row: [test_code, test_name, panel_code, unit, is_numeric, sort_order]
 * is_numeric = 1 means the value must be a number; 0 means free text.
 *
 * @return list<array{0:string,1:string,2:string,3:?string,4:int,5:int}>
 */
function lab_test_catalog_rows(): array
{
    return [
        // HEMATOLOGY / CBC — order matches the printed hematology form.
        // Core counts stay in the units the Isolation Forest model expects
        // (g/dL and %). Differential and indices follow the printed sheet.
        ['HGB', 'Hemoglobin', 'CBC', 'g/dL', 1, 10],
        ['HCT', 'Hematocrit', 'CBC', '%', 1, 20],
        ['RBC', 'R.B.C Count', 'CBC', 'x10^12/L', 1, 30],
        ['WBC', 'W.B.C Count', 'CBC', 'x10^9/L', 1, 40],
        ['SEG', 'Segmenters', 'CBC', 'fraction', 1, 50],
        ['LYM', 'Lymphocytes', 'CBC', 'fraction', 1, 60],
        ['MON', 'Monocytes', 'CBC', 'fraction', 1, 70],
        ['EOS', 'Eosinophils', 'CBC', 'fraction', 1, 80],
        ['MCV', 'MCV', 'CBC', 'fL', 1, 90],
        ['MCH', 'MCH', 'CBC', 'pg', 1, 100],
        ['MCHC', 'MCHC', 'CBC', 'g/L', 1, 110],
        ['PLT', 'Platelet Count', 'CBC', 'x10^9/L', 1, 120],
        // CHEMISTRY (blood)
        ['GLU', 'Fasting Blood Sugar', 'CHEMISTRY', 'mg/dL', 1, 10],
        ['CREA', 'Creatinine', 'CHEMISTRY', 'mg/dL', 1, 20],
        ['BUN', 'Blood Urea Nitrogen', 'CHEMISTRY', 'mg/dL', 1, 30],
        ['UA', 'Uric Acid', 'CHEMISTRY', 'mg/dL', 1, 40],
        ['CHOL', 'Total Cholesterol', 'CHEMISTRY', 'mg/dL', 1, 50],
        // URINALYSIS — physical, chemical, microscopic (printed form)
        ['COLOR', 'Color', 'URINE', null, 0, 10],
        ['APPEARANCE', 'Transparency', 'URINE', null, 0, 20],
        ['PH', 'pH', 'URINE', null, 1, 30],
        ['SG', 'Specific Gravity', 'URINE', null, 1, 40],
        ['GLU', 'Sugar', 'URINE', null, 0, 50],
        ['PRO', 'Albumin', 'URINE', null, 0, 60],
        ['KET', 'Ketones', 'URINE', null, 0, 70],
        ['BLD', 'Blood', 'URINE', null, 0, 80],
        ['BIL', 'Bilirubin', 'URINE', null, 0, 90],
        ['UBG', 'Urobilinogen', 'URINE', 'EU/dL', 0, 100],
        ['NIT', 'Nitrite', 'URINE', null, 0, 110],
        ['LEU', 'Leukocyte Esterase', 'URINE', null, 0, 120],
        // Microscopic lines are text (e.g. "4-6 / hpf", "RARE", "FEW")
        ['WBC', 'Pus Cells', 'URINE', '/hpf', 0, 130],
        ['RBC', 'Red Cells', 'URINE', '/hpf', 0, 140],
        ['MTHR', 'Mucus Threads', 'URINE', null, 0, 150],
        ['AUR', 'Amorphous Urates', 'URINE', null, 0, 160],
        ['EC', 'Epithelial Cells', 'URINE', '/hpf', 0, 170],
        ['BAC', 'Bacteria', 'URINE', null, 0, 180],
        ['CAST', 'Casts', 'URINE', '/LPF', 0, 190],
        ['CRYS', 'Crystals', 'URINE', null, 0, 200],
        ['YST', 'Yeast', 'URINE', null, 0, 210],
        // FECALYSIS — printed form, plus extra stool exams already in the catalog
        ['COLOR', 'Color', 'STOOL', null, 0, 10],
        ['CONS', 'Consistency', 'STOOL', null, 0, 20],
        ['WBC', 'Pus Cells', 'STOOL', '/hpf', 0, 30],
        ['RBC', 'Red Cells', 'STOOL', '/hpf', 0, 40],
        ['BAC', 'Bacteria', 'STOOL', null, 0, 50],
        ['PARA', 'Intestinal Parasites', 'STOOL', null, 0, 60],
        ['MUC', 'Mucus', 'STOOL', null, 0, 70],
        ['BLOOD', 'Visible Blood', 'STOOL', null, 0, 80],
        ['OVA', 'Parasite Ova', 'STOOL', null, 0, 90],
        ['CYST', 'Protozoan Cyst', 'STOOL', null, 0, 100],
        ['TROPH', 'Protozoan Trophozoite', 'STOOL', null, 0, 110],
        ['YEAST', 'Yeast', 'STOOL', null, 0, 120],
        ['FAT', 'Fat Globules', 'STOOL', null, 0, 130],
        ['FOB', 'Fecal Occult Blood', 'STOOL', null, 0, 140],
    ];
}

/**
 * Insert or update every catalog row.
 * "Upsert" means: insert if new, update if the (panel, test_code) already exists.
 */
function upsert_lab_tests(PDO $pdo, string $driver): void
{
    // Postgres and MySQL use slightly different upsert syntax
    if ($driver === 'pgsql') {
        $sql = 'INSERT INTO lab_tests (test_code, test_name, panel_code, unit, is_numeric, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, 1, ?)
                ON CONFLICT (panel_code, test_code) DO UPDATE SET
                  test_name = EXCLUDED.test_name,
                  unit = EXCLUDED.unit,
                  is_numeric = EXCLUDED.is_numeric,
                  is_active = 1,
                  sort_order = EXCLUDED.sort_order';
    } else {
        $sql = 'INSERT INTO lab_tests (test_code, test_name, panel_code, unit, is_numeric, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE
                  test_name = VALUES(test_name),
                  unit = VALUES(unit),
                  is_numeric = VALUES(is_numeric),
                  is_active = 1,
                  sort_order = VALUES(sort_order)';
    }
    $stmt = $pdo->prepare($sql);
    foreach (lab_test_catalog_rows() as $row) {
        $stmt->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5]]);
    }
}

/**
 * Seed reference ranges that are still missing.
 * Does not overwrite ranges a manager may have edited later.
 *
 * Hematology mins/maxes are the values printed on the clinic hematology form.
 * Critical limits are wider safety bounds used only for critical flags.
 */
function upsert_catalog_reference_ranges(PDO $pdo): void
{
    // Each row: [panel, test_code, min, max, critical_low, critical_high]
    $ranges = [
        // Printed hematology reference values (both sexes, all ages on that form)
        ['CBC', 'SEG', 0.40, 0.60, 0.10, 0.90],
        ['CBC', 'LYM', 0.20, 0.40, 0.05, 0.80],
        ['CBC', 'MON', 0.02, 0.10, null, 0.40],
        ['CBC', 'EOS', 0.02, 0.08, null, 0.30],
        ['CBC', 'MCV', 80.0, 97.0, 50.0, 130.0],
        ['CBC', 'MCH', 26.50, 33.50, 15.0, 45.0],
        ['CBC', 'MCHC', 320.0, 360.0, 250.0, 400.0],
        // Urine numeric lines (pus/red cells are text ranges like "4-6 / hpf")
        ['URINE', 'SG', 1.005, 1.030, 1.000, 1.040],
        ['URINE', 'PH', 4.5, 8.0, 4.0, 9.0],
    ];
    $find = $pdo->prepare(
        'SELECT id FROM lab_tests WHERE panel_code = ? AND test_code = ? LIMIT 1'
    );
    $exists = $pdo->prepare(
        'SELECT 1 FROM reference_ranges WHERE lab_test_id = ? AND sex = \'A\' AND age_min = 0 AND age_max = 150 LIMIT 1'
    );
    $ins = $pdo->prepare(
        'INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
         VALUES (?, \'A\', 0, 150, ?, ?, ?, ?)'
    );
    foreach ($ranges as [$panel, $code, $min, $max, $critLow, $critHigh]) {
        $find->execute([$panel, $code]);
        $id = (int) $find->fetchColumn();
        if ($id <= 0) {
            continue;
        }
        // Skip if a range for this test already exists
        $exists->execute([$id]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $ins->execute([$id, $min, $max, $critLow, $critHigh]);
    }
}
