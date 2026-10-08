<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$patient = db()->query("SELECT username, role FROM users WHERE username = 'patient'")->fetch(PDO::FETCH_ASSOC);
$appt = db()->query("SHOW TABLES LIKE 'appointments'")->fetchColumn();
$col = db()->query("SHOW COLUMNS FROM patients LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
$role = db()->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
echo json_encode([
    'patient_user' => $patient,
    'appointments_table' => $appt,
    'user_id_column' => $col['Field'] ?? null,
    'role_type' => $role['Type'] ?? null,
], JSON_PRETTY_PRINT), PHP_EOL;
