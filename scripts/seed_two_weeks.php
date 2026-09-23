<?php
/**
 * Two weeks of clinic visits for the live database.
 *
 * Weekdays 1–14 September 2026, closed Saturday and Sunday.
 * Every timestamp is a real clock time between 06:00 and 20:30 (Asia/Manila).
 * Safe to run more than once: a settings row stops a second load.
 */
declare(strict_types=1);

/**
 * @return array{ok:bool,created:int,message:string}
 */
function seed_two_week_clinic_data(PDO $pdo): array
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $locked = false;
    if ($driver === 'pgsql') {
        $pdo->query('SELECT pg_advisory_lock(260901)');
        $locked = true;
    }

    try {
        $existing = $pdo->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'clinic_seed_sep2026'"
        )->fetchColumn();
        if ($existing === 'done') {
            return ['ok' => true, 'created' => 0, 'message' => 'September clinic seed already loaded.'];
        }

        $users = $pdo->query('SELECT id, username, role FROM users WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $byRole = ['staff' => null, 'med_tech' => null, 'manager' => null];
        foreach ($users as $user) {
            $role = (string) $user['role'];
            if ($byRole[$role] === null) {
                $byRole[$role] = (int) $user['id'];
            }
        }
        $fallback = $users[0]['id'] ?? null;
        if ($fallback === null) {
            return ['ok' => false, 'created' => 0, 'message' => 'No users found. Install first.'];
        }
        $staffId = $byRole['staff'] ?? (int) $fallback;
        $techId = $byRole['med_tech'] ?? (int) $fallback;
        $managerId = $byRole['manager'] ?? $techId;

        $tests = $pdo->query(
            'SELECT id, test_code, test_name, panel_code, is_numeric FROM lab_tests WHERE is_active = 1 ORDER BY panel_code, sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $byPanel = [];
        foreach ($tests as $test) {
            $byPanel[(string) $test['panel_code']][] = $test;
        }
        if (empty($byPanel['CBC'])) {
            return ['ok' => false, 'created' => 0, 'message' => 'CBC tests are missing. Install the catalog first.'];
        }

        $rangeRows = $pdo->query(
            'SELECT lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high FROM reference_ranges'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ranges = [];
        foreach ($rangeRows as $row) {
            $ranges[(int) $row['lab_test_id']][] = $row;
        }

        $days = clinic_seed_working_days();
        $audits = [];
        $created = 0;

        $insert = static function (string $sql, array $params) use ($pdo, $driver): int {
            if ($driver === 'pgsql') {
                $sql = rtrim($sql, "; \t\n\r") . ' RETURNING id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return (int) $stmt->fetchColumn();
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $pdo->lastInsertId();
        };

        $pdo->beginTransaction();

        $insTest = $pdo->prepare('INSERT INTO request_tests (lab_request_id, lab_test_id) VALUES (?, ?)');
        $insValue = $pdo->prepare(
            'INSERT INTO result_values (lab_result_id, lab_test_id, numeric_value, text_value, is_out_of_range, is_critical)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insFlag = $pdo->prepare(
            $driver === 'pgsql'
                ? 'INSERT INTO ai_flags (lab_result_id, is_anomaly, score, warning_message, model_version, raw_response, created_at)
                   VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb), ?)'
                : 'INSERT INTO ai_flags (lab_result_id, is_anomaly, score, warning_message, model_version, raw_response, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($days as $day) {
            $audits[] = clinic_seed_audit($staffId, 'login', 'user', $staffId, 'User logged in', '192.168.1.21', clinic_seed_stamp($day, random_int(361, 368), random_int(5, 40)));
            $audits[] = clinic_seed_audit($techId, 'login', 'user', $techId, 'User logged in', '192.168.1.34', clinic_seed_stamp($day, random_int(366, 374), random_int(5, 40)));
            $audits[] = clinic_seed_audit($managerId, 'login', 'user', $managerId, 'User logged in', '192.168.1.10', clinic_seed_stamp($day, random_int(370, 378), random_int(5, 40)));

            $visits = random_int(6, 9);
            $arrivals = clinic_seed_arrivals($visits);
            foreach ($arrivals as $arrival) {
                $patient = clinic_seed_patient($day);
                $registeredAt = clinic_seed_stamp($day, $arrival, random_int(5, 40));
                $patientId = $insert(
                    'INSERT INTO patients (patient_code, first_name, last_name, middle_name, sex, birth_date, contact_number, address, created_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $patient['code'],
                        $patient['first'],
                        $patient['last'],
                        $patient['middle'],
                        $patient['sex'],
                        $patient['birth'],
                        $patient['contact'],
                        $patient['address'],
                        $staffId,
                        $registeredAt,
                    ]
                );
                $audits[] = clinic_seed_audit(
                    $staffId,
                    'patient_create',
                    'patient',
                    $patientId,
                    'Registered ' . $patient['code'],
                    '192.168.1.21',
                    $registeredAt
                );

                $order = clinic_seed_order();
                $panelTests = [];
                foreach ($order['panels'] as $panel) {
                    if (!empty($byPanel[$panel])) {
                        $panelTests[$panel] = $byPanel[$panel];
                    }
                }
                if (!$panelTests) {
                    continue;
                }

                $reqAt = $arrival + random_int(2, 7);
                $collectAt = $reqAt + random_int(5, 14);
                $processAt = $collectAt + random_int(10, 24);
                $encodeAt = $processAt + random_int(12, 36);
                $clock = clinic_seed_fit($arrival, [
                    'req' => $reqAt,
                    'collect' => $collectAt,
                    'process' => $processAt,
                    'encode' => $encodeAt,
                ]);
                $reqAt = $clock['req'];
                $collectAt = $clock['collect'];
                $processAt = $clock['process'];
                $encodeAt = $clock['encode'];
                $reqStamp = clinic_seed_stamp($day, $reqAt, 12);
                $collectStamp = clinic_seed_stamp($day, $collectAt, 18);
                $processStamp = clinic_seed_stamp($day, $processAt, 24);
                $reqCode = clinic_seed_code('RQ', $day);
                $requestId = $insert(
                    'INSERT INTO lab_requests (request_code, patient_id, requesting_physician, clinical_notes, status, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $reqCode,
                        $patientId,
                        $order['physician'],
                        $order['notes'],
                        'open',
                        $staffId,
                        $reqStamp,
                        $reqStamp,
                    ]
                );
                $audits[] = clinic_seed_audit($staffId, 'request_create', 'lab_request', $requestId, 'Created ' . $reqCode, '192.168.1.21', $reqStamp);

                foreach ($panelTests as $rows) {
                    foreach ($rows as $test) {
                        $insTest->execute([$requestId, (int) $test['id']]);
                    }
                }

                $specCode = clinic_seed_code('SP', $day);
                $specimenId = $insert(
                    'INSERT INTO specimens (specimen_code, lab_request_id, specimen_type, status, collected_at, status_updated_at, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $specCode,
                        $requestId,
                        $order['specimen'],
                        'pending',
                        $collectStamp,
                        $collectStamp,
                        $staffId,
                    ]
                );
                $audits[] = clinic_seed_audit($staffId, 'specimen_status', 'specimen', $specimenId, 'pending → collected', '192.168.1.21', $collectStamp);
                $audits[] = clinic_seed_audit($techId, 'specimen_status', 'specimen', $specimenId, 'collected → processing', '192.168.1.34', $processStamp);

                $lastReleaseStamp = $processStamp;
                $firstEncodeStamp = clinic_seed_stamp($day, $encodeAt, 50);
                $panelIndex = 0;
                foreach ($panelTests as $panel => $rows) {
                    $panelClock = clinic_seed_fit($encodeAt + ($panelIndex * 8), [
                        'encode' => $encodeAt + ($panelIndex * 8),
                        'approve' => $encodeAt + ($panelIndex * 8) + random_int(6, 12),
                        'report' => $encodeAt + ($panelIndex * 8) + random_int(14, 22),
                        'release' => $encodeAt + ($panelIndex * 8) + random_int(24, 34),
                    ]);
                    $panelIndex++;
                    $encodeStamp = clinic_seed_stamp($day, $panelClock['encode'], 10);
                    if ($panelIndex === 1) {
                        $firstEncodeStamp = $encodeStamp;
                    }
                    $approveStamp = clinic_seed_stamp($day, $panelClock['approve'], 20);
                    $reportStamp = clinic_seed_stamp($day, $panelClock['report'], 30);
                    $releaseStamp = clinic_seed_stamp($day, $panelClock['release'], 40);
                    $lastReleaseStamp = $releaseStamp;

                    $built = clinic_seed_values($panel, $rows, $ranges, $patient['sex'], $patient['age']);
                    $resCode = clinic_seed_code('RS', $day);
                    $resultId = $insert(
                        'INSERT INTO lab_results (
                            result_code, lab_request_id, specimen_id, panel_code, status, ai_flagged, rule_warnings,
                            encoded_by, encoded_at, approved_by, approved_at, reported_at, released_by, released_at, created_at, updated_at
                         ) VALUES (?, ?, ?, ?, \'released\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $resCode,
                            $requestId,
                            $specimenId,
                            $panel,
                            $built['ai'] ? 1 : 0,
                            $built['warnings'] !== '' ? $built['warnings'] : null,
                            $techId,
                            $encodeStamp,
                            $techId,
                            $approveStamp,
                            $reportStamp,
                            $managerId,
                            $releaseStamp,
                            $reqStamp,
                            $releaseStamp,
                        ]
                    );

                    foreach ($built['rows'] as $valueRow) {
                        $insValue->execute([
                            $resultId,
                            $valueRow['test_id'],
                            $valueRow['numeric'],
                            $valueRow['text'],
                            $valueRow['oor'],
                            $valueRow['critical'],
                        ]);
                    }

                    $insFlag->execute([
                        $resultId,
                        $built['ai'] ? 1 : 0,
                        $built['score'],
                        $built['ai_message'],
                        $panel === 'CBC' ? 'iforest-cbc-v1' : null,
                        json_encode($built['raw'], JSON_UNESCAPED_UNICODE),
                        $encodeStamp,
                    ]);

                    $audits[] = clinic_seed_audit(
                        $techId,
                        'encode_validate',
                        'lab_result',
                        $resultId,
                        'Encoded and validated; AI flagged=' . ($built['ai'] ? '1' : '0'),
                        '192.168.1.34',
                        $encodeStamp
                    );
                    $audits[] = clinic_seed_audit($techId, 'approve', 'lab_result', $resultId, 'Result approved', '192.168.1.34', $approveStamp);
                    $audits[] = clinic_seed_audit($managerId, 'report', 'lab_result', $resultId, 'Report generated', '192.168.1.10', $reportStamp);
                    $audits[] = clinic_seed_audit($managerId, 'release', 'lab_result', $resultId, 'Result released', '192.168.1.10', $releaseStamp);
                }

                $completedAt = (new DateTimeImmutable($firstEncodeStamp))->modify('+2 seconds')->format('Y-m-d H:i:s');
                $pdo->prepare(
                    'UPDATE specimens SET status = ?, collected_at = ?, status_updated_at = ?, updated_by = ? WHERE id = ?'
                )->execute([
                    'completed',
                    $collectStamp,
                    $completedAt,
                    $techId,
                    $specimenId,
                ]);
                $audits[] = clinic_seed_audit($techId, 'specimen_status', 'specimen', $specimenId, 'processing → completed', '192.168.1.34', $completedAt);

                $pdo->prepare('UPDATE lab_requests SET status = ?, updated_at = ? WHERE id = ?')->execute([
                    'completed',
                    $lastReleaseStamp,
                    $requestId,
                ]);
                $created++;
            }

            $audits[] = clinic_seed_audit($staffId, 'logout', 'user', $staffId, 'User logged out', '192.168.1.21', clinic_seed_stamp($day, 20 * 60 + 22, random_int(5, 40)));
            $audits[] = clinic_seed_audit($techId, 'logout', 'user', $techId, 'User logged out', '192.168.1.34', clinic_seed_stamp($day, 20 * 60 + 25, random_int(5, 40)));
            $audits[] = clinic_seed_audit($managerId, 'logout', 'user', $managerId, 'User logged out', '192.168.1.10', clinic_seed_stamp($day, 20 * 60 + 29, random_int(5, 40)));
        }

        usort($audits, static fn(array $a, array $b): int => [$a['at'], $a['action']] <=> [$b['at'], $b['action']]);
        $insAudit = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($audits as $audit) {
            $insAudit->execute([
                $audit['user_id'],
                $audit['action'],
                $audit['entity_type'],
                $audit['entity_id'],
                $audit['details'],
                $audit['ip'],
                $audit['at'],
            ]);
        }

        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = EXCLUDED.updated_at'
        )->execute(['clinic_seed_sep2026', 'done', '2026-09-14 20:30:00']);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'created' => 0, 'message' => 'September clinic seed failed: ' . $e->getMessage()];
    } finally {
        if ($locked) {
            $pdo->query('SELECT pg_advisory_unlock(260901)');
        }
    }

    return [
        'ok' => true,
        'created' => $created,
        'message' => "Loaded {$created} weekday visits from 1–14 September 2026 (closed Sat/Sun, 06:00–20:30).",
    ];
}

/** @return list<string> */
function clinic_seed_working_days(): array
{
    $days = [];
    $cursor = new DateTimeImmutable('2026-09-01');
    $end = new DateTimeImmutable('2026-09-14');
    while ($cursor <= $end) {
        $weekday = (int) $cursor->format('N');
        if ($weekday < 6) {
            $days[] = $cursor->format('Y-m-d');
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $days;
}

/** @return list<int> minutes from midnight */
function clinic_seed_arrivals(int $count): array
{
    $start = 6 * 60 + 20;
    $end = 17 * 60 + 30;
    $span = $end - $start;
    $times = [];
    for ($i = 0; $i < $count; $i++) {
        $slot = $start + (int) round((($i + 0.5) * $span) / $count);
        $times[] = max($start, min($end, $slot + random_int(-10, 10)));
    }
    sort($times);
    return $times;
}

/** @param array<string,int> $minutes @return array<string,int> */
function clinic_seed_fit(int $floor, array $minutes): array
{
    $limit = 20 * 60 + 18;
    $max = max($minutes);
    if ($max > $limit) {
        $shift = $max - $limit;
        foreach ($minutes as $key => $value) {
            $minutes[$key] = $value - $shift;
        }
    }
    $previous = $floor;
    foreach ($minutes as $key => $value) {
        if ($value < $previous) {
            $value = $previous;
        }
        if ($value > $limit) {
            $value = $limit;
        }
        $minutes[$key] = $value;
        $previous = $value + 1;
    }
    return $minutes;
}

function clinic_seed_stamp(string $day, int $minute, int $second = 0): string
{
    $minute = max(6 * 60, min(20 * 60 + 30, $minute));
    $second = max(0, min(59, $second));
    $hour = intdiv($minute, 60);
    $min = $minute % 60;
    return sprintf('%s %02d:%02d:%02d', $day, $hour, $min, $second);
}

function clinic_seed_code(string $prefix, string $day): string
{
    $ymd = substr(str_replace('-', '', $day), 2);
    return $prefix . $ymd . strtoupper(bin2hex(random_bytes(3)));
}

/** @return array{code:string,first:string,last:string,middle:string,sex:string,birth:string,contact:string,address:string,age:int} */
function clinic_seed_patient(string $day): array
{
    $male = ['Juan', 'Jose', 'Mark', 'Angelo', 'Carlo', 'Miguel', 'Rafael', 'Gabriel', 'Antonio', 'Pedro', 'Ramon', 'Luis', 'Christian', 'Daniel', 'Joshua', 'Emmanuel', 'Francis', 'Jerome', 'Eduardo', 'Fernando', 'Ricardo', 'Mario', 'Ryan', 'Kevin', 'Patrick', 'Adrian', 'Jayson', 'Roberto', 'Marco', 'Paolo', 'Lorenzo', 'Mateo', 'Diego', 'Joaquin', 'Andres', 'Noel', 'Allan', 'Dennis'];
    $female = ['Maria', 'Ana', 'Rosa', 'Grace', 'Joy', 'Michelle', 'Angela', 'Patricia', 'Catherine', 'Jennifer', 'Christine', 'Jessica', 'Nicole', 'Andrea', 'Bianca', 'Camille', 'Denise', 'Elaine', 'Gloria', 'Hannah', 'Irene', 'Jasmine', 'Karen', 'Liza', 'Maricel', 'Nina', 'Olivia', 'Rochelle', 'Sarah', 'Teresa', 'Angelica', 'Clarissa', 'Diana', 'Elena', 'Fatima', 'Hazel', 'Isabel', 'Katrina', 'Lourdes', 'Sofia', 'Carmela', 'Rowena', 'Princess', 'Thea', 'Josie', 'Marites'];
    $surnames = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Gonzales', 'Torres', 'Flores', 'Rivera', 'Ramos', 'Castillo', 'Aquino', 'Villanueva', 'Fernandez', 'Dela Cruz', 'Navarro', 'Domingo', 'Pascual', 'Gutierrez', 'Hernandez', 'Lopez', 'Morales', 'Castro', 'Romero', 'Aguilar', 'Salazar', 'Mercado', 'Ocampo', 'De Leon', 'Santiago', 'Velasco', 'Corpuz', 'Manalo', 'Padilla', 'Soriano', 'Valdez', 'Tolentino', 'Evangelista', 'De Guzman', 'Lagman', 'Bernardo', 'Chua', 'Tan', 'Lim', 'Ong', 'Yap'];
    $places = [
        '12 Mabini St, Barangay San Roque, Malolos, Bulacan',
        '45 Rizal Ave, Barangay Poblacion, Meycauayan, Bulacan',
        '88 J.P. Rizal, Barangay Muzon, San Jose del Monte, Bulacan',
        '7 Burgos St, Barangay Caniogan, Calumpit, Bulacan',
        '23 Luna St, Barangay Sta. Cruz, Guiguinto, Bulacan',
        '56 Bonifacio St, Barangay Tikay, Malolos, Bulacan',
        '101 Quirino Highway, Barangay Graceville, San Jose del Monte',
        '14 Del Pilar, Barangay Bagbaguin, Meycauayan, Bulacan',
        '9 Aguinaldo St, Barangay Longos, Malolos, Bulacan',
        '33 National Road, Barangay Tabang, Guiguinto, Bulacan',
        '18 Sampaguita St, Barangay Fatima, San Jose del Monte',
        '77 MacArthur Highway, Barangay Ibayo, Marilao, Bulacan',
        '5 Jacinto St, Barangay Balasing, Santa Maria, Bulacan',
        '61 Mabolo St, Barangay Caypombo, Santa Maria, Bulacan',
        '28 Real St, Barangay Poblacion, Pulilan, Bulacan',
    ];

    $sex = random_int(0, 1) === 0 ? 'M' : 'F';
    $first = $sex === 'M' ? $male[random_int(0, count($male) - 1)] : $female[random_int(0, count($female) - 1)];
    $last = $surnames[random_int(0, count($surnames) - 1)];
    $middle = $surnames[random_int(0, count($surnames) - 1)];
    if ($middle === $last) {
        $middle = 'Santos';
    }

    $band = random_int(1, 100);
    if ($band <= 8) {
        $age = random_int(2, 12);
    } elseif ($band <= 18) {
        $age = random_int(13, 19);
    } elseif ($band <= 78) {
        $age = random_int(20, 59);
    } else {
        $age = random_int(60, 82);
    }

    $visit = new DateTimeImmutable($day);
    $birth = $visit->setDate((int) $visit->format('Y') - $age, random_int(1, 12), random_int(1, 28));
    if ($birth > $visit) {
        $birth = $birth->modify('-1 year');
    }

    return [
        'code' => clinic_seed_code('PT', $day),
        'first' => $first,
        'last' => $last,
        'middle' => $middle,
        'sex' => $sex,
        'birth' => $birth->format('Y-m-d'),
        'contact' => '09' . (string) random_int(100000000, 999999999),
        'address' => $places[random_int(0, count($places) - 1)],
        'age' => $age,
    ];
}

/** @return array{panels:list<string>,specimen:string,physician:string,notes:string} */
function clinic_seed_order(): array
{
    $physicians = ['Dr. Antonio Reyes', 'Dr. Maria Lim', 'Dr. Jose Cruz', 'Dr. Ana Garcia', 'Dr. Ramon Santos', 'Dr. Liza Mendoza', 'Dr. Paolo Bautista', 'Dr. Carmen Villanueva'];
    $notes = ['Annual checkup', 'Fasting blood chemistry', 'Follow-up visit', 'Pre-employment', 'Dizziness', 'Fever for 3 days', 'Routine CBC', 'Hypertension follow-up', 'Body malaise', 'Executive checkup'];
    $roll = random_int(1, 100);
    if ($roll <= 42) {
        $panels = ['CBC'];
        $specimen = 'Blood';
    } elseif ($roll <= 64) {
        $panels = ['CBC', 'CHEMISTRY'];
        $specimen = 'Blood';
    } elseif ($roll <= 78) {
        $panels = ['CHEMISTRY'];
        $specimen = 'Blood';
    } elseif ($roll <= 90) {
        $panels = ['URINE'];
        $specimen = 'Urine';
    } else {
        $panels = ['STOOL'];
        $specimen = 'Stool';
    }
    return [
        'panels' => $panels,
        'specimen' => $specimen,
        'physician' => $physicians[random_int(0, count($physicians) - 1)],
        'notes' => $notes[random_int(0, count($notes) - 1)],
    ];
}

/**
 * @param list<array<string,mixed>> $tests
 * @param array<int,list<array<string,mixed>>> $ranges
 * @return array{rows:list<array<string,mixed>>,warnings:string,ai:bool,score:?float,ai_message:?string,raw:array<string,mixed>}
 */
function clinic_seed_values(string $panel, array $tests, array $ranges, string $sex, int $age): array
{
    $roll = random_int(1, 100);
    $mode = $roll <= 78 ? 'normal' : ($roll <= 93 ? 'abnormal' : 'critical');
    $story = clinic_seed_story($panel, $mode);

    $rows = [];
    $warnings = [];
    $outCount = 0;
    $criticalCount = 0;

    if ($panel === 'CBC') {
        $numbers = clinic_seed_cbc_numbers($sex, $age, $story);
    } elseif ($panel === 'CHEMISTRY') {
        $numbers = clinic_seed_chem_numbers($sex, $story);
    } else {
        $numbers = [];
    }

    foreach ($tests as $test) {
        $code = (string) $test['test_code'];
        $numeric = (int) $test['is_numeric'] === 1;
        $range = clinic_seed_match_range($ranges[(int) $test['id']] ?? [], $sex, $age);
        $oor = 0;
        $critical = 0;
        $num = null;
        $text = null;

        if ($panel === 'URINE' || $panel === 'STOOL') {
            $textValue = clinic_seed_text_result($panel, $code, $story);
            if ($numeric) {
                $num = (float) $textValue;
                $text = null;
            } else {
                $text = $textValue;
            }
            if (clinic_seed_text_abnormal($panel, $code, $textValue)) {
                $oor = 1;
                $outCount++;
            }
        } else {
            $num = $numbers[$code] ?? clinic_seed_ranged_number($range, 'normal', clinic_seed_decimals($code));
            if ($num === null) {
                continue;
            }
            if ($range && $range['min_value'] !== null && $num < (float) $range['min_value']) {
                $oor = 1;
                $warnings[] = $test['test_name'] . " ({$num}) is below reference minimum ({$range['min_value']}).";
            }
            if ($range && $range['max_value'] !== null && $num > (float) $range['max_value']) {
                $oor = 1;
                $warnings[] = $test['test_name'] . " ({$num}) is above reference maximum ({$range['max_value']}).";
            }
            if ($range && $range['critical_low'] !== null && $num < (float) $range['critical_low']) {
                $critical = 1;
                $warnings[] = "CRITICAL LOW: {$test['test_name']} ({$num}) < {$range['critical_low']}.";
            }
            if ($range && $range['critical_high'] !== null && $num > (float) $range['critical_high']) {
                $critical = 1;
                $warnings[] = "CRITICAL HIGH: {$test['test_name']} ({$num}) > {$range['critical_high']}.";
            }
            if ($oor) {
                $outCount++;
            }
            if ($critical) {
                $criticalCount++;
            }
        }

        $rows[] = [
            'test_id' => (int) $test['id'],
            'numeric' => $num,
            'text' => $text,
            'oor' => $oor,
            'critical' => $critical,
        ];
    }

    $ai = $panel === 'CBC' && ($criticalCount > 0 || $outCount >= 2);
    if ($panel === 'CBC') {
        $score = $ai ? round(-1 * (random_int(18, 62) / 100), 4) : round(random_int(8, 42) / 100, 4);
        $message = $ai ? 'Isolation Forest warning — CBC pattern looks unusual. Review before approval.' : null;
        $raw = ['model_version' => 'iforest-cbc-v1', 'is_anomaly' => $ai, 'seed' => 'clinic-sep-2026'];
    } else {
        $score = null;
        $message = null;
        $raw = ['note' => 'Panel outside CBC Isolation Forest scope; rule-based validation applies.'];
    }

    return [
        'rows' => $rows,
        'warnings' => implode("\n", $warnings),
        'ai' => $ai,
        'score' => $score,
        'ai_message' => $message,
        'raw' => $raw,
    ];
}

function clinic_seed_story(string $panel, string $mode): string
{
    if ($mode === 'normal') {
        return 'normal';
    }
    if ($panel === 'CBC') {
        $options = $mode === 'critical'
            ? ['critical_wbc', 'critical_hgb', 'critical_plt']
            : ['infection', 'anemia', 'thrombocytopenia'];
    } elseif ($panel === 'CHEMISTRY') {
        $options = $mode === 'critical'
            ? ['critical_glu', 'critical_renal']
            : ['hyperglycemia', 'renal', 'cholesterol'];
    } else {
        $options = $mode === 'critical' ? ['marked'] : ['mild'];
    }
    return $options[random_int(0, count($options) - 1)];
}

/** @return array<string,float> */
function clinic_seed_cbc_numbers(string $sex, int $age, string $story): array
{
    $child = $age < 18;
    $rbc = $sex === 'M' ? clinic_seed_between(4.6, 5.4, 2) : clinic_seed_between(4.1, 5.0, 2);
    $mcv = clinic_seed_between(82, 96, 1);
    $mch = clinic_seed_between(27.2, 32.8, 1);
    $mchc = clinic_seed_between(326, 354, 0);
    $wbc = $child ? clinic_seed_between(6.2, 12.5, 1) : clinic_seed_between(4.8, 10.2, 1);
    $plt = clinic_seed_between(175, 390, 0);
    $seg = clinic_seed_between(0.46, 0.58, 2);
    $lym = clinic_seed_between(0.24, 0.35, 2);
    $mon = clinic_seed_between(0.03, 0.08, 2);

    if ($story === 'infection') {
        $wbc = $child ? clinic_seed_between(15.5, 20.0, 1) : clinic_seed_between(12.4, 18.6, 1);
        $seg = clinic_seed_between(0.68, 0.80, 2);
        $lym = clinic_seed_between(0.10, 0.18, 2);
    } elseif ($story === 'critical_wbc') {
        $wbc = clinic_seed_between(32.0, 46.0, 1);
        $seg = clinic_seed_between(0.78, 0.88, 2);
        $lym = clinic_seed_between(0.06, 0.12, 2);
    } elseif ($story === 'anemia') {
        $rbc = $sex === 'M' ? clinic_seed_between(3.2, 4.1, 2) : clinic_seed_between(3.0, 3.8, 2);
        $mchc = clinic_seed_between(300, 318, 0);
    } elseif ($story === 'critical_hgb') {
        $rbc = clinic_seed_between(2.4, 3.0, 2);
        $mchc = clinic_seed_between(280, 305, 0);
    } elseif ($story === 'thrombocytopenia') {
        $plt = clinic_seed_between(70, 120, 0);
    } elseif ($story === 'critical_plt') {
        $plt = clinic_seed_between(18, 42, 0);
    }

    $eos = round(1 - $seg - $lym - $mon, 2);
    if ($eos < 0.01) {
        $lym = round($lym - (0.02 - $eos), 2);
        $eos = 0.02;
    }
    if ($eos > 0.12) {
        $eos = 0.08;
    }

    $hct = round($rbc * $mcv / 10, 1);
    $hgb = round($hct * $mchc / 100, 0);
    if ($story === 'normal') {
        $hctMin = $sex === 'M' && !$child ? 40.5 : ($child ? 34.0 : 36.5);
        $hctMax = $sex === 'M' && !$child ? 49.0 : ($child ? 44.0 : 45.5);
        $hct = max($hctMin, min($hctMax, $hct));
        $hgb = $child ? max(112, min(155, $hgb)) : max(122, min(158, $hgb));
        $wbc = $child ? max(5.6, min(13.4, $wbc)) : max(4.3, min(10.4, $wbc));
        $plt = max(160, min(430, $plt));
    }
    if ($story === 'critical_hgb') {
        $hgb = clinic_seed_between(62, 74, 0);
        $hct = round($hgb / 3.3, 1);
    } elseif ($story === 'anemia') {
        $hgb = clinic_seed_between(88, 112, 0);
        $hct = round($hgb / 3.2, 1);
    }

    return [
        'WBC' => $wbc,
        'RBC' => $rbc,
        'HGB' => $hgb,
        'HCT' => $hct,
        'PLT' => $plt,
        'SEG' => $seg,
        'LYM' => $lym,
        'MON' => $mon,
        'EOS' => $eos,
        'MCV' => $mcv,
        'MCH' => $mch,
        'MCHC' => $mchc,
    ];
}

/** @return array<string,float> */
function clinic_seed_chem_numbers(string $sex, string $story): array
{
    $glu = clinic_seed_between(74, 98, 1);
    $crea = $sex === 'M' ? clinic_seed_between(0.75, 1.2, 2) : clinic_seed_between(0.62, 1.02, 2);
    $bun = clinic_seed_between(8, 18, 1);
    $ua = $sex === 'M' ? clinic_seed_between(3.8, 6.8, 1) : clinic_seed_between(2.9, 5.6, 1);
    $chol = clinic_seed_between(140, 198, 0);

    if ($story === 'hyperglycemia') {
        $glu = clinic_seed_between(128, 186, 1);
    } elseif ($story === 'critical_glu') {
        $glu = clinic_seed_between(410, 520, 0);
    } elseif ($story === 'renal' || $story === 'critical_renal') {
        $crea = $story === 'critical_renal' ? clinic_seed_between(5.4, 7.8, 2) : clinic_seed_between(1.6, 2.8, 2);
        $bun = $story === 'critical_renal' ? clinic_seed_between(62, 90, 1) : clinic_seed_between(24, 38, 1);
    } elseif ($story === 'cholesterol') {
        $chol = clinic_seed_between(220, 275, 0);
    }

    return ['GLU' => $glu, 'CREA' => $crea, 'BUN' => $bun, 'UA' => $ua, 'CHOL' => $chol];
}

function clinic_seed_text_result(string $panel, string $code, string $story): string
{
    $urine = [
        'COLOR' => 'Yellow', 'APPEARANCE' => 'Clear', 'PH' => '6.0', 'SG' => '1.015',
        'GLU' => 'Negative', 'PRO' => 'Negative', 'KET' => 'Negative', 'BLD' => 'Negative',
        'BIL' => 'Negative', 'UBG' => 'Normal', 'NIT' => 'Negative', 'LEU' => 'Negative',
        'WBC' => '0-2 / hpf', 'RBC' => '0-1 / hpf', 'MTHR' => 'Few', 'AUR' => 'None',
        'EC' => 'Few', 'BAC' => 'None', 'CAST' => 'None', 'CRYS' => 'None', 'YST' => 'None',
    ];
    $stool = [
        'COLOR' => 'Brown', 'CONS' => 'Soft', 'WBC' => '0-1 / hpf', 'RBC' => '0-1 / hpf',
        'BAC' => 'Few', 'PARA' => 'None seen', 'MUC' => 'None', 'BLOOD' => 'None',
        'OVA' => 'None seen', 'CYST' => 'None seen', 'TROPH' => 'None seen', 'YEAST' => 'None',
        'FAT' => 'None', 'FOB' => 'Negative',
    ];
    $base = $panel === 'URINE' ? $urine : $stool;
    $value = $base[$code] ?? 'None';
    if ($story === 'normal') {
        if ($code === 'PH') {
            return (string) clinic_seed_between(5.0, 7.5, 1);
        }
        if ($code === 'SG') {
            return number_format(clinic_seed_between(1.008, 1.025, 3), 3, '.', '');
        }
        return $value;
    }
    $abnormal = $panel === 'URINE'
        ? ['PRO' => '1+', 'GLU' => 'Trace', 'BLD' => 'Moderate', 'LEU' => '1+', 'WBC' => '10-15 / hpf', 'BAC' => 'Moderate', 'NIT' => 'Positive']
        : ['WBC' => '8-12 / hpf', 'MUC' => 'Moderate', 'PARA' => 'Ascaris lumbricoides ova', 'OVA' => 'Ascaris lumbricoides', 'FOB' => 'Positive', 'CONS' => 'Watery'];
    if ($story === 'marked' && isset($abnormal[$code]) && random_int(1, 100) <= 70) {
        return $abnormal[$code];
    }
    if ($story === 'mild' && isset($abnormal[$code]) && random_int(1, 100) <= 35) {
        return $abnormal[$code];
    }
    if ($code === 'PH') {
        return (string) clinic_seed_between(5.0, 7.5, 1);
    }
    if ($code === 'SG') {
        return number_format(clinic_seed_between(1.008, 1.028, 3), 3, '.', '');
    }
    return $value;
}

function clinic_seed_text_abnormal(string $panel, string $code, string $value): bool
{
    $normal = ['Yellow', 'Clear', 'Negative', 'Normal', 'None', 'None seen', 'Few', 'Brown', 'Soft', '0-2 / hpf', '0-1 / hpf'];
    if (in_array($code, ['PH', 'SG'], true)) {
        return false;
    }
    return !in_array($value, $normal, true);
}

/** @param list<array<string,mixed>> $rows */
function clinic_seed_match_range(array $rows, string $sex, int $age): ?array
{
    $any = null;
    foreach ($rows as $row) {
        if ($age < (int) $row['age_min'] || $age > (int) $row['age_max']) {
            continue;
        }
        if ((string) $row['sex'] === $sex) {
            return $row;
        }
        if ((string) $row['sex'] === 'A') {
            $any = $row;
        }
    }
    return $any;
}

function clinic_seed_ranged_number(?array $range, string $mode, int $decimals): ?float
{
    if (!$range || $range['min_value'] === null || $range['max_value'] === null) {
        return null;
    }
    return clinic_seed_between((float) $range['min_value'], (float) $range['max_value'], $decimals);
}

function clinic_seed_decimals(string $code): int
{
    return match ($code) {
        'RBC', 'CREA' => 2,
        'SG' => 3,
        'SEG', 'LYM', 'MON', 'EOS' => 2,
        'HGB', 'PLT', 'MCHC', 'CHOL' => 0,
        default => 1,
    };
}

function clinic_seed_between(float $min, float $max, int $decimals): float
{
    $span = $max - $min;
    $value = $min + ($span * random_int(0, 1000) / 1000);
    return round($value, $decimals);
}

/** @return array{user_id:int,action:string,entity_type:string,entity_id:int,details:string,ip:string,at:string} */
function clinic_seed_audit(int $userId, string $action, string $entityType, int $entityId, string $details, string $ip, string $at): array
{
    return [
        'user_id' => $userId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => $details,
        'ip' => $ip,
        'at' => $at,
    ];
}
