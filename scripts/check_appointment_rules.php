<?php
declare(strict_types=1);
require __DIR__ . '/../includes/appointments.php';

$checks = [
    'Valid leap day' => is_iso_date('2024-02-29'),
    'Invalid leap day rejected' => !is_iso_date('2025-02-29'),
    'Invalid month rejected' => !is_iso_date('2026-13-01'),
    'Datetime-local accepted' => parse_local_datetime('2026-10-08T14:30') === '2026-10-08 14:30:00',
    'Invalid calendar datetime rejected' => parse_local_datetime('2026-02-30T14:30') === null,
    'Invalid hour rejected' => parse_local_datetime('2026-10-08T25:30') === null,
    'Invalid minute rejected' => parse_local_datetime('2026-10-08T14:60') === null,
    'Trailing input rejected' => parse_local_datetime('2026-10-08T14:30 extra') === null,
    'Ten minute grace period' => APPOINTMENT_GRACE_MINUTES === 10,
];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
