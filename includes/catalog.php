<?php
declare(strict_types=1);

/**
 * Ensure lab_tests uniqueness is (panel_code, test_code) and seed missing catalog rows.
 * Safe to call on every boot (Render auto_install / local install refresh).
 */
function ensure_lab_test_catalog(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    ensure_lab_tests_schema($pdo, $driver);
    upsert_lab_tests($pdo, $driver);
    upsert_urine_reference_ranges($pdo);
}

function ensure_lab_tests_schema(PDO $pdo, string $driver): void
{
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

    // MySQL
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
 * @return list<array{0:string,1:string,2:string,3:?string,4:int,5:int}>
 */
function lab_test_catalog_rows(): array
{
    return [
        // CBC
        ['WBC', 'White Blood Cell Count', 'CBC', 'x10^9/L', 1, 10],
        ['RBC', 'Red Blood Cell Count', 'CBC', 'x10^12/L', 1, 20],
        ['HGB', 'Hemoglobin', 'CBC', 'g/dL', 1, 30],
        ['HCT', 'Hematocrit', 'CBC', '%', 1, 40],
        ['PLT', 'Platelet Count', 'CBC', 'x10^9/L', 1, 50],
        // CHEMISTRY (blood)
        ['GLU', 'Fasting Blood Sugar', 'CHEMISTRY', 'mg/dL', 1, 10],
        ['CREA', 'Creatinine', 'CHEMISTRY', 'mg/dL', 1, 20],
        ['BUN', 'Blood Urea Nitrogen', 'CHEMISTRY', 'mg/dL', 1, 30],
        ['UA', 'Uric Acid', 'CHEMISTRY', 'mg/dL', 1, 40],
        ['CHOL', 'Total Cholesterol', 'CHEMISTRY', 'mg/dL', 1, 50],
        // URINE — Physical
        ['COLOR', 'Urine Color', 'URINE', null, 0, 10],
        ['APPEARANCE', 'Urine Appearance', 'URINE', null, 0, 20],
        ['SG', 'Specific Gravity', 'URINE', null, 1, 30],
        ['PH', 'Urine pH', 'URINE', null, 1, 40],
        // URINE — Chemical
        ['PRO', 'Protein', 'URINE', null, 0, 50],
        ['GLU', 'Urine Glucose', 'URINE', null, 0, 60],
        ['KET', 'Ketones', 'URINE', null, 0, 70],
        ['BLD', 'Blood', 'URINE', null, 0, 80],
        ['BIL', 'Bilirubin', 'URINE', null, 0, 90],
        ['UBG', 'Urobilinogen', 'URINE', 'EU/dL', 0, 100],
        ['NIT', 'Nitrite', 'URINE', null, 0, 110],
        ['LEU', 'Leukocyte Esterase', 'URINE', null, 0, 120],
        // URINE — Microscopic
        ['RBC', 'Red Blood Cells', 'URINE', '/HPF', 1, 130],
        ['WBC', 'White Blood Cells', 'URINE', '/HPF', 1, 140],
        ['EC', 'Epithelial Cells', 'URINE', '/HPF', 0, 150],
        ['BAC', 'Bacteria', 'URINE', null, 0, 160],
        ['CAST', 'Casts', 'URINE', '/LPF', 0, 170],
        ['CRYS', 'Crystals', 'URINE', null, 0, 180],
        ['YST', 'Yeast', 'URINE', null, 0, 190],
    ];
}

function upsert_lab_tests(PDO $pdo, string $driver): void
{
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

function upsert_urine_reference_ranges(PDO $pdo): void
{
    $ranges = [
        ['SG', 1.005, 1.030, 1.000, 1.040],
        ['PH', 4.5, 8.0, 4.0, 9.0],
        ['RBC', 0.0, 5.0, null, 50.0],
        ['WBC', 0.0, 5.0, null, 50.0],
    ];
    $find = $pdo->prepare(
        "SELECT id FROM lab_tests WHERE panel_code = 'URINE' AND test_code = ? LIMIT 1"
    );
    $exists = $pdo->prepare(
        'SELECT 1 FROM reference_ranges WHERE lab_test_id = ? AND sex = \'A\' AND age_min = 0 AND age_max = 150 LIMIT 1'
    );
    $ins = $pdo->prepare(
        'INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
         VALUES (?, \'A\', 0, 150, ?, ?, ?, ?)'
    );
    foreach ($ranges as [$code, $min, $max, $critLow, $critHigh]) {
        $find->execute([$code]);
        $id = (int) $find->fetchColumn();
        if ($id <= 0) {
            continue;
        }
        $exists->execute([$id]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $ins->execute([$id, $min, $max, $critLow, $critHigh]);
    }
}
