<?php
declare(strict_types=1);

/** Specimen transitions: from => allowed to */
function specimen_transitions(): array
{
    return [
        'pending' => ['collected'],
        'collected' => ['processing', 'delayed', 'missing'],
        'processing' => ['completed', 'delayed', 'missing'],
        'delayed' => ['collected', 'processing', 'missing'],
        'missing' => ['collected', 'processing'],
        'completed' => [],
    ];
}

function can_transition_specimen(string $from, string $to): bool
{
    $map = specimen_transitions();
    return isset($map[$from]) && in_array($to, $map[$from], true);
}

function result_transitions(): array
{
    return [
        'pending' => ['encoded'],
        'encoded' => ['validated'],
        'validated' => ['approved', 'encoded'],
        'approved' => ['reported'],
        'reported' => ['released'],
        'released' => [],
    ];
}

function can_transition_result(string $from, string $to): bool
{
    $map = result_transitions();
    return isset($map[$from]) && in_array($to, $map[$from], true);
}

function update_specimen_status(int $specimenId, string $newStatus, ?string $notes = null): bool
{
    $stmt = db()->prepare('SELECT * FROM specimens WHERE id = ?');
    $stmt->execute([$specimenId]);
    $specimen = $stmt->fetch();
    if (!$specimen || !can_transition_specimen($specimen['status'], $newStatus)) {
        return false;
    }

    // Permission: staff only for pending→collected; process roles for later
    if ($specimen['status'] === 'pending' && $newStatus === 'collected') {
        if (!can('specimen_collect')) {
            return false;
        }
    } else {
        if (!can('specimen_process')) {
            return false;
        }
    }

    $collectedAt = $specimen['collected_at'];
    if ($newStatus === 'collected' && !$collectedAt) {
        $collectedAt = date('Y-m-d H:i:s');
    }

    $upd = db()->prepare(
        'UPDATE specimens SET status = ?, notes = COALESCE(?, notes), collected_at = ?,
         status_updated_at = NOW(), updated_by = ? WHERE id = ?'
    );
    $upd->execute([$newStatus, $notes, $collectedAt, current_user()['id'], $specimenId]);
    audit_log('specimen_status', 'specimen', $specimenId, "{$specimen['status']} → {$newStatus}");
    return true;
}

/**
 * Encode values, run rule validation + AI, set status to validated.
 */
function encode_and_validate_result(int $resultId, array $inputs): array
{
    if (!can('encode_results')) {
        return ['ok' => false, 'message' => 'Permission denied.'];
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT r.*, p.sex, p.birth_date, p.first_name, p.last_name
         FROM lab_results r
         JOIN lab_requests lr ON lr.id = r.lab_request_id
         JOIN patients p ON p.id = lr.patient_id
         WHERE r.id = ?'
    );
    $stmt->execute([$resultId]);
    $result = $stmt->fetch();
    if (!$result) {
        return ['ok' => false, 'message' => 'Result not found.'];
    }
    if (!in_array($result['status'], ['pending', 'encoded', 'validated'], true)) {
        return ['ok' => false, 'message' => 'Result can no longer be edited.'];
    }

    $patient = [
        'sex' => $result['sex'],
        'birth_date' => $result['birth_date'],
    ];
    $validation = validate_result_values($patient, $inputs);
    if ($validation['hard_block'] || count($validation['values']) === 0) {
        return [
            'ok' => false,
            'message' => 'Rule-based validation blocked save.',
            'warnings' => $validation['warnings'],
        ];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM result_values WHERE lab_result_id = ?')->execute([$resultId]);
        $ins = $pdo->prepare(
            'INSERT INTO result_values (lab_result_id, lab_test_id, numeric_value, is_out_of_range, is_critical)
             VALUES (?, ?, ?, ?, ?)'
        );
        $features = [];
        foreach ($validation['values'] as $testId => $row) {
            $ins->execute([
                $resultId,
                $testId,
                $row['numeric_value'],
                $row['is_out_of_range'],
                $row['is_critical'],
            ]);
            $test = get_lab_test((int) $testId);
            if ($test) {
                $features[$test['test_code']] = $row['numeric_value'];
            }
        }

        $pdo->prepare(
            'UPDATE lab_results SET status = ?, rule_warnings = ?, encoded_by = ?, encoded_at = NOW(),
             ai_flagged = 0, rejection_reason = NULL WHERE id = ?'
        )->execute([
            'encoded',
            $validation['warnings'] ? implode("\n", $validation['warnings']) : null,
            current_user()['id'],
            $resultId,
        ]);

        // AI prediction
        $aiPayload = [
            'result_id' => $resultId,
            'patient_sex' => $result['sex'],
            'patient_age' => patient_age($result['birth_date']),
            'test_code' => $result['panel_code'],
            'features' => $features,
        ];
        $ai = ai_predict($aiPayload);

        $pdo->prepare('DELETE FROM ai_flags WHERE lab_result_id = ?')->execute([$resultId]);
        $pdo->prepare(
            'INSERT INTO ai_flags (lab_result_id, is_anomaly, score, warning_message, model_version, raw_response)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $resultId,
            $ai['is_anomaly'] ? 1 : 0,
            $ai['score'],
            $ai['warning_message'],
            $ai['model_version'],
            $ai['raw'] ? json_encode($ai['raw']) : json_encode($ai),
        ]);

        $aiFlagged = !empty($ai['is_anomaly']) || !$ai['ok'] ? 1 : 0;
        $pdo->prepare(
            'UPDATE lab_results SET status = ?, ai_flagged = ? WHERE id = ?'
        )->execute(['validated', $aiFlagged, $resultId]);

        // Advance specimen to completed if still processing
        $pdo->prepare(
            "UPDATE specimens SET status = 'completed', status_updated_at = NOW(), updated_by = ?
             WHERE id = ? AND status IN ('processing','collected')"
        )->execute([current_user()['id'], $result['specimen_id']]);

        $pdo->commit();
        audit_log('encode_validate', 'lab_result', $resultId, 'Encoded and validated; AI flagged=' . $aiFlagged);

        $messages = $validation['warnings'];
        if (!empty($ai['warning_message'])) {
            $messages[] = $ai['warning_message'];
        }
        return [
            'ok' => true,
            'message' => 'Result encoded and validated.',
            'warnings' => $messages,
            'ai_flagged' => (bool) $aiFlagged,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Save failed: ' . $e->getMessage()];
    }
}

function approve_result(int $resultId): array
{
    if (!can('approve_results')) {
        return ['ok' => false, 'message' => 'Permission denied.'];
    }
    $stmt = db()->prepare('SELECT * FROM lab_results WHERE id = ?');
    $stmt->execute([$resultId]);
    $result = $stmt->fetch();
    if (!$result || !can_transition_result($result['status'], 'approved')) {
        return ['ok' => false, 'message' => 'Result is not ready for approval.'];
    }
    db()->prepare(
        'UPDATE lab_results SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?'
    )->execute(['approved', current_user()['id'], $resultId]);
    audit_log('approve', 'lab_result', $resultId, 'Result approved');
    return ['ok' => true, 'message' => 'Result approved.'];
}

function reject_result(int $resultId, string $reason): array
{
    if (!can('approve_results')) {
        return ['ok' => false, 'message' => 'Permission denied.'];
    }
    $stmt = db()->prepare('SELECT * FROM lab_results WHERE id = ?');
    $stmt->execute([$resultId]);
    $result = $stmt->fetch();
    if (!$result || !can_transition_result($result['status'], 'encoded')) {
        return ['ok' => false, 'message' => 'Result cannot be rejected in its current state.'];
    }
    db()->prepare(
        'UPDATE lab_results SET status = ?, rejection_reason = ?, ai_flagged = 0,
         approved_by = NULL, approved_at = NULL WHERE id = ?'
    )->execute(['encoded', $reason, $resultId]);
    audit_log('reject', 'lab_result', $resultId, 'Rejected: ' . $reason);
    return ['ok' => true, 'message' => 'Result sent back for re-encoding.'];
}

function mark_reported(int $resultId): array
{
    if (!can('release_reports')) {
        return ['ok' => false, 'message' => 'Permission denied.'];
    }
    $stmt = db()->prepare('SELECT * FROM lab_results WHERE id = ?');
    $stmt->execute([$resultId]);
    $result = $stmt->fetch();
    if (!$result || !can_transition_result($result['status'], 'reported')) {
        return ['ok' => false, 'message' => 'Only approved results can generate a report.'];
    }
    db()->prepare(
        'UPDATE lab_results SET status = ?, reported_at = NOW() WHERE id = ?'
    )->execute(['reported', $resultId]);
    audit_log('report', 'lab_result', $resultId, 'Report generated');
    return ['ok' => true, 'message' => 'Report generated.'];
}

function release_result(int $resultId): array
{
    if (!can('release_reports')) {
        return ['ok' => false, 'message' => 'Permission denied.'];
    }
    $stmt = db()->prepare('SELECT * FROM lab_results WHERE id = ?');
    $stmt->execute([$resultId]);
    $result = $stmt->fetch();
    if (!$result || !can_transition_result($result['status'], 'released')) {
        return ['ok' => false, 'message' => 'Only reported results can be released.'];
    }
    db()->prepare(
        'UPDATE lab_results SET status = ?, released_by = ?, released_at = NOW() WHERE id = ?'
    )->execute(['released', current_user()['id'], $resultId]);
    // Close parent request if all results released
    db()->prepare(
        "UPDATE lab_requests SET status = 'completed'
         WHERE id = ? AND NOT EXISTS (
           SELECT 1 FROM lab_results WHERE lab_request_id = ? AND status <> 'released'
         )"
    )->execute([$result['lab_request_id'], $result['lab_request_id']]);
    audit_log('release', 'lab_result', $resultId, 'Result released');
    return ['ok' => true, 'message' => 'Result released.'];
}
