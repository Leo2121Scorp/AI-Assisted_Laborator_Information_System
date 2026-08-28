<?php
declare(strict_types=1);

/**
 * Rule-based validation against reference_ranges.
 *
 * @return array{ok:bool, hard_block:bool, warnings:string[], values:array}
 */
function validate_result_values(array $patient, array $inputs): array
{
    $age = patient_age($patient['birth_date']);
    $sex = $patient['sex'];
    $warnings = [];
    $hardBlock = false;
    $normalized = [];

    foreach ($inputs as $testId => $raw) {
        $testId = (int) $testId;
        if ($raw === '' || $raw === null) {
            continue;
        }
        if (!is_numeric($raw)) {
            $warnings[] = "Test ID {$testId}: value must be numeric.";
            $hardBlock = true;
            continue;
        }
        $value = (float) $raw;
        if ($value < 0) {
            $warnings[] = "Test ID {$testId}: negative values are not allowed.";
            $hardBlock = true;
        }

        $range = find_reference_range($testId, $sex, $age);
        $outOfRange = false;
        $critical = false;

        if ($range) {
            $test = get_lab_test($testId);
            $label = $test['test_code'] ?? ("ID{$testId}");
            if ($range['min_value'] !== null && $value < (float) $range['min_value']) {
                $outOfRange = true;
                $warnings[] = "{$label} ({$value}) is below reference minimum ({$range['min_value']}).";
            }
            if ($range['max_value'] !== null && $value > (float) $range['max_value']) {
                $outOfRange = true;
                $warnings[] = "{$label} ({$value}) is above reference maximum ({$range['max_value']}).";
            }
            if ($range['critical_low'] !== null && $value < (float) $range['critical_low']) {
                $critical = true;
                $warnings[] = "CRITICAL LOW: {$label} ({$value}) < {$range['critical_low']}.";
            }
            if ($range['critical_high'] !== null && $value > (float) $range['critical_high']) {
                $critical = true;
                $warnings[] = "CRITICAL HIGH: {$label} ({$value}) > {$range['critical_high']}.";
            }
            // Hard-block only on extreme critical violations that look like encoding errors
            if ($range['critical_low'] !== null && $value < (float) $range['critical_low'] * 0.1) {
                $hardBlock = true;
                $warnings[] = "{$label}: value appears impossibly low — correct before saving.";
            }
        }

        $normalized[$testId] = [
            'numeric_value' => $value,
            'is_out_of_range' => $outOfRange ? 1 : 0,
            'is_critical' => $critical ? 1 : 0,
        ];
    }

    return [
        'ok' => !$hardBlock && count($normalized) > 0,
        'hard_block' => $hardBlock,
        'warnings' => $warnings,
        'values' => $normalized,
    ];
}

function find_reference_range(int $labTestId, string $sex, int $age): ?array
{
    $sql = 'SELECT * FROM reference_ranges
            WHERE lab_test_id = ?
              AND age_min <= ? AND age_max >= ?
              AND (sex = ? OR sex = \'A\')
            ORDER BY FIELD(sex, ?, \'A\')
            LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$labTestId, $age, $age, $sex, $sex]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_lab_test(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM lab_tests WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
