<?php
declare(strict_types=1);

/**
 * Demo patients + result pipeline for empty databases (local and Render).
 * Safe to call repeatedly: no-ops when lab_results already has rows.
 *
 * @return array{ok:bool,created:int,message:string}
 */
function seed_demo_lab_cases(PDO $pdo): array
{
    try {
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM lab_results')->fetchColumn();
    } catch (Throwable $e) {
        return ['ok' => false, 'created' => 0, 'message' => 'lab_results table is missing. Run install first.'];
    }
    if ($existing > 0) {
        return ['ok' => true, 'created' => 0, 'message' => 'Results already exist — demo seed skipped.'];
    }

    $userId = (int) $pdo->query(
        "SELECT id FROM users WHERE is_active = 1 ORDER BY CASE WHEN role = 'med_tech' THEN 0 WHEN role = 'manager' THEN 1 ELSE 2 END, id LIMIT 1"
    )->fetchColumn();
    if ($userId <= 0) {
        return ['ok' => false, 'created' => 0, 'message' => 'No users found. Run install.php first.'];
    }

    $tests = $pdo->query('SELECT id, test_code, panel_code FROM lab_tests WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
    $byCode = [];
    $byPanel = [];
    foreach ($tests as $t) {
        $code = (string) $t['test_code'];
        $panel = (string) $t['panel_code'];
        $byCode[$code] = (int) $t['id'];
        $byPanel[$panel][] = (int) $t['id'];
    }
    if (empty($byPanel['CBC'])) {
        return ['ok' => false, 'created' => 0, 'message' => 'Lab tests not seeded. Run install.php first.'];
    }

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $insert = static function (string $sql, array $params) use ($pdo, $driver): int {
        if ($driver === 'pgsql' && stripos($sql, 'returning') === false) {
            $sql = rtrim($sql, "; \t\n\r") . ' RETURNING id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    };

    $cbcValuesNormal = ['WBC' => 6.2, 'RBC' => 4.8, 'HGB' => 14.1, 'HCT' => 42.0, 'PLT' => 245];
    $cbcValuesAnomaly = ['WBC' => 28.4, 'RBC' => 3.1, 'HGB' => 8.2, 'HCT' => 24.0, 'PLT' => 42];
    $chemValues = ['GLU' => 96.0, 'CREA' => 0.9, 'BUN' => 14.0, 'UA' => 5.1, 'CHOL' => 188.0];

    $cases = [
        [
            'patient' => ['DEM-PT-001', 'Juan', 'Dela Cruz', 'M', '1990-05-12', '09171234567', 'Quezon City'],
            'physician' => 'Dr. Reyes',
            'panel' => 'CBC',
            'status' => 'pending',
            'specimen' => 'collected',
            'values' => [],
            'ai' => false,
        ],
        [
            'patient' => ['DEM-PT-002', 'Maria', 'Santos', 'F', '1988-11-03', '09189876543', 'Makati'],
            'physician' => 'Dr. Lim',
            'panel' => 'CBC',
            'status' => 'validated',
            'specimen' => 'completed',
            'values' => $cbcValuesAnomaly,
            'ai' => true,
        ],
        [
            'patient' => ['DEM-PT-003', 'Pedro', 'Reyes', 'M', '1975-02-20', '09175550123', 'Pasig'],
            'physician' => 'Dr. Cruz',
            'panel' => 'CHEMISTRY',
            'status' => 'approved',
            'specimen' => 'completed',
            'values' => $chemValues,
            'ai' => false,
        ],
        [
            'patient' => ['DEM-PT-004', 'Ana', 'Cruz', 'F', '1995-08-30', '09170001122', 'Manila'],
            'physician' => 'Dr. Reyes',
            'panel' => 'CBC',
            'status' => 'released',
            'specimen' => 'completed',
            'values' => $cbcValuesNormal,
            'ai' => false,
        ],
    ];

    $created = 0;
    $pdo->beginTransaction();
    try {
        $insRt = $pdo->prepare('INSERT INTO request_tests (lab_request_id, lab_test_id) VALUES (?, ?)');
        $insVal = $pdo->prepare(
            'INSERT INTO result_values (lab_result_id, lab_test_id, numeric_value, is_out_of_range, is_critical)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($cases as $i => $case) {
            $panel = $case['panel'];
            $testIds = $byPanel[$panel] ?? [];
            if (!$testIds) {
                continue;
            }

            $p = $case['patient'];
            $patientId = $insert(
                'INSERT INTO patients (patient_code, first_name, last_name, sex, birth_date, contact_number, address, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $userId]
            );

            $reqCode = sprintf('DEM-RQ-%03d', $i + 1);
            $requestStatus = $case['status'] === 'released' ? 'completed' : 'in_progress';
            $requestId = $insert(
                'INSERT INTO lab_requests (request_code, patient_id, requesting_physician, clinical_notes, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$reqCode, $patientId, $case['physician'], 'Demo seed case', $requestStatus, $userId]
            );

            foreach ($testIds as $tid) {
                $insRt->execute([$requestId, $tid]);
            }

            $specCode = sprintf('DEM-SP-%03d', $i + 1);
            $collected = $case['specimen'] !== 'pending' ? date('Y-m-d H:i:s') : null;
            $specimenId = $insert(
                'INSERT INTO specimens (specimen_code, lab_request_id, specimen_type, status, collected_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$specCode, $requestId, 'Blood', $case['specimen'], $collected, $userId]
            );

            $resCode = sprintf('DEM-RS-%03d', $i + 1);
            $encodedAt = $case['values'] ? date('Y-m-d H:i:s') : null;
            $approvedAt = in_array($case['status'], ['approved', 'reported', 'released'], true) ? date('Y-m-d H:i:s') : null;
            $releasedAt = $case['status'] === 'released' ? date('Y-m-d H:i:s') : null;
            $reportedAt = in_array($case['status'], ['reported', 'released'], true) ? date('Y-m-d H:i:s') : null;

            $resultId = $insert(
                'INSERT INTO lab_results (
                    result_code, lab_request_id, specimen_id, panel_code, status, ai_flagged,
                    encoded_by, encoded_at, approved_by, approved_at, reported_at, released_by, released_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $resCode,
                    $requestId,
                    $specimenId,
                    $panel,
                    $case['status'],
                    $case['ai'] ? 1 : 0,
                    $encodedAt ? $userId : null,
                    $encodedAt,
                    $approvedAt ? $userId : null,
                    $approvedAt,
                    $reportedAt,
                    $releasedAt ? $userId : null,
                    $releasedAt,
                ]
            );

            foreach ($case['values'] as $code => $num) {
                if (!isset($byCode[$code])) {
                    continue;
                }
                $oor = $case['ai'] ? 1 : 0;
                $crit = ($code === 'PLT' && $num < 50) || ($code === 'WBC' && $num > 25) ? 1 : 0;
                $insVal->execute([$resultId, $byCode[$code], $num, $oor, $crit]);
            }

            if ($case['ai']) {
                $pdo->prepare(
                    'INSERT INTO ai_flags (lab_result_id, is_anomaly, score, warning_message, model_version)
                     VALUES (?, 1, ?, ?, ?)'
                )->execute([
                    $resultId,
                    -0.42,
                    'Demo Isolation Forest warning — CBC pattern looks anomalous. Review before approval.',
                    'demo-seed',
                ]);
            }

            $created++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'created' => 0, 'message' => 'Demo seed failed: ' . $e->getMessage()];
    }

    return [
        'ok' => true,
        'created' => $created,
        'message' => $created > 0
            ? "Loaded {$created} demo results (pending, AI warning, approved, released)."
            : 'No demo panels could be created.',
    ];
}
