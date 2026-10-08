<?php
declare(strict_types=1);

const APPOINTMENT_GRACE_MINUTES = 10;

function appointment_panel_catalog(): array
{
    $rows = db()->query(
        'SELECT DISTINCT panel_code FROM lab_tests WHERE is_active = 1 ORDER BY panel_code'
    )->fetchAll(PDO::FETCH_COLUMN);
    $labels = [
        'CBC' => 'Hematology / CBC',
        'CHEMISTRY' => 'Chemistry',
        'URINE' => 'Urinalysis',
        'STOOL' => 'Fecalysis',
    ];
    $out = [];
    foreach ($rows as $code) {
        $code = (string) $code;
        $out[$code] = $labels[$code] ?? $code;
    }
    return $out;
}

function appointment_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pending approval',
        'approved' => 'Approved',
        'arrived' => 'Arrived',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        default => ucfirst($status),
    };
}

/** Turn a stored datetime into a value for input type="datetime-local". */
function datetime_local_value(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

/** Accept datetime-local (2026-10-02T14:30) or "Y-m-d H:i:s". */
function parse_local_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
        return null;
    }
    return $parsed->format('Y-m-d H:i:s');
}

function is_iso_date(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
        return false;
    }
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

/**
 * Approved bookings with no arrival become expired 10 minutes after the approved time.
 */
function expire_overdue_appointments(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $pdo->exec(
            "UPDATE appointments
             SET status = 'expired', updated_at = CURRENT_TIMESTAMP
             WHERE status = 'approved'
               AND scheduled_at IS NOT NULL
               AND scheduled_at + INTERVAL '" . APPOINTMENT_GRACE_MINUTES . " minutes' <= CURRENT_TIMESTAMP"
        );
        return;
    }
    $pdo->exec(
        "UPDATE appointments
         SET status = 'expired'
         WHERE status = 'approved'
           AND scheduled_at IS NOT NULL
           AND DATE_ADD(scheduled_at, INTERVAL " . APPOINTMENT_GRACE_MINUTES . " MINUTE) <= NOW()"
    );
}

/**
 * @return array{0:string,1:list<mixed>}
 */
function appointment_list_sql(array $filters): array
{
    $sql = "SELECT a.*, CONCAT(p.last_name, ', ', p.first_name) AS patient_name, p.patient_code
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE 1 = 1";
    $params = [];
    $status = (string) ($filters['status'] ?? '');
    $allowed = ['pending', 'approved', 'arrived', 'expired', 'cancelled'];
    if (in_array($status, $allowed, true)) {
        $sql .= ' AND a.status = ?';
        $params[] = $status;
    }
    $from = (string) ($filters['from'] ?? '');
    $to = (string) ($filters['to'] ?? '');
    $dateExpr = sql_as_date('COALESCE(a.scheduled_at, a.preferred_at)');
    if (is_iso_date($from)) {
        $sql .= " AND {$dateExpr} >= ?";
        $params[] = $from;
    }
    if (is_iso_date($to)) {
        $sql .= " AND {$dateExpr} <= ?";
        $params[] = $to;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $sql .= ' AND (a.appointment_code LIKE ? OR p.patient_code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR a.checkup_reason LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY COALESCE(a.scheduled_at, a.preferred_at) DESC LIMIT 200';
    return [$sql, $params];
}

/**
 * Steps the patient sees for one booking.
 *
 * @return list<array{label:string,done:bool}>
 */
function appointment_process_steps(array $appt, bool $inLab, bool $resultReady): array
{
    $status = (string) ($appt['status'] ?? '');
    $approved = in_array($status, ['approved', 'arrived', 'expired'], true) || !empty($appt['scheduled_at']);
    $arrived = $status === 'arrived' || $inLab || $resultReady;
    return [
        ['label' => 'Booked', 'done' => true],
        ['label' => 'Approved', 'done' => $approved],
        ['label' => 'Arrived', 'done' => $arrived],
        ['label' => 'In lab', 'done' => $inLab || $resultReady],
        ['label' => 'Result ready', 'done' => $resultReady],
    ];
}

function appointment_request_progress(int $requestId): array
{
    if ($requestId <= 0) {
        return ['in_lab' => false, 'result_ready' => false];
    }
    $stmt = db()->prepare(
        "SELECT
            SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) AS ready_count,
            COUNT(*) AS total_count
         FROM lab_results
         WHERE lab_request_id = ?"
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch() ?: ['ready_count' => 0, 'total_count' => 0];
    $ready = (int) $row['ready_count'];
    $total = (int) $row['total_count'];
    return [
        'in_lab' => $total > 0 && $ready < $total,
        'result_ready' => $ready > 0,
    ];
}
