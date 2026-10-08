<?php
declare(strict_types=1);

/**
 * Add the patient role, portal link, and appointments table on databases
 * that were installed before the patient portal. Safe to call more than once.
 */
function ensure_portal_schema(?PDO $pdo = null): void
{
    $cache = $pdo === null;
    if ($cache && (int) ($_SESSION['portal_schema_ver'] ?? 0) >= 1) {
        return;
    }
    $pdo = $pdo ?? db();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'pgsql') {
        ensure_portal_schema_pgsql($pdo);
    } else {
        ensure_portal_schema_mysql($pdo);
    }

    if ($cache) {
        $_SESSION['portal_schema_ver'] = 1;
    }
}

function ensure_portal_schema_pgsql(PDO $pdo): void
{
    $names = $pdo->query(
        "SELECT conname FROM pg_constraint
         WHERE conrelid = 'users'::regclass AND contype = 'c'
           AND pg_get_constraintdef(oid) ILIKE '%role%'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $name) {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            continue;
        }
        $pdo->exec('ALTER TABLE users DROP CONSTRAINT ' . $name);
    }
    $pdo->exec(
        "ALTER TABLE users ADD CONSTRAINT users_role_check
         CHECK (role IN ('manager', 'med_tech', 'staff', 'patient'))"
    );

    $pdo->exec('ALTER TABLE patients ADD COLUMN IF NOT EXISTS user_id INTEGER NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_patients_user ON patients (user_id)');
    $fk = $pdo->query("SELECT 1 FROM pg_constraint WHERE conname = 'fk_patients_user'")->fetchColumn();
    if (!$fk) {
        $pdo->exec(
            'ALTER TABLE patients ADD CONSTRAINT fk_patients_user
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS appointments (
          id SERIAL PRIMARY KEY,
          appointment_code VARCHAR(30) NOT NULL UNIQUE,
          patient_id INT NOT NULL REFERENCES patients(id),
          preferred_at TIMESTAMP NOT NULL,
          scheduled_at TIMESTAMP NULL,
          checkup_reason VARCHAR(255) NOT NULL,
          panel_codes VARCHAR(255) NULL,
          notes TEXT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'pending'
            CHECK (status IN ('pending','approved','arrived','expired','cancelled')),
          approved_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
          approved_at TIMESTAMP NULL,
          arrived_at TIMESTAMP NULL,
          lab_request_id INT NULL REFERENCES lab_requests(id) ON DELETE SET NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_patient ON appointments (patient_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appt_status ON appointments (status, scheduled_at)');
}

function ensure_portal_schema_mysql(PDO $pdo): void
{
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    $type = (string) ($col['Type'] ?? '');
    if (!str_contains($type, 'patient')) {
        $pdo->exec(
            "ALTER TABLE users MODIFY role ENUM('manager','med_tech','staff','patient') NOT NULL"
        );
    }

    $userCol = $pdo->query("SHOW COLUMNS FROM patients LIKE 'user_id'")->fetch();
    if (!$userCol) {
        $pdo->exec('ALTER TABLE patients ADD COLUMN user_id INT UNSIGNED NULL');
        $pdo->exec('ALTER TABLE patients ADD UNIQUE KEY uq_patients_user (user_id)');
        $pdo->exec(
            'ALTER TABLE patients ADD CONSTRAINT fk_patients_user
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    $table = $pdo->query("SHOW TABLES LIKE 'appointments'")->fetch();
    if (!$table) {
        $pdo->exec(
            "CREATE TABLE appointments (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              appointment_code VARCHAR(30) NOT NULL UNIQUE,
              patient_id INT UNSIGNED NOT NULL,
              preferred_at DATETIME NOT NULL,
              scheduled_at DATETIME NULL,
              checkup_reason VARCHAR(255) NOT NULL,
              panel_codes VARCHAR(255) NULL,
              notes TEXT NULL,
              status ENUM('pending','approved','arrived','expired','cancelled') NOT NULL DEFAULT 'pending',
              approved_by INT UNSIGNED NULL,
              approved_at DATETIME NULL,
              arrived_at DATETIME NULL,
              lab_request_id INT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
              CONSTRAINT fk_appt_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
              CONSTRAINT fk_appt_request FOREIGN KEY (lab_request_id) REFERENCES lab_requests(id) ON DELETE SET NULL,
              INDEX idx_appt_patient (patient_id),
              INDEX idx_appt_status (status, scheduled_at)
            ) ENGINE=InnoDB"
        );
    }
}

/**
 * Demo portal login (username patient / password123) linked to patient PT-DEMO.
 * Does not reset the password if the account already exists.
 */
function ensure_demo_patient(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $findUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $findUser->execute(['patient']);
    $userId = (int) $findUser->fetchColumn();
    if ($userId <= 0) {
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $userId = portal_insert(
            $pdo,
            'INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)',
            ['patient', $hash, 'Demo Patient', 'patient']
        );
    }
    if ($userId <= 0) {
        return;
    }

    $linked = $pdo->prepare('SELECT id FROM patients WHERE user_id = ? LIMIT 1');
    $linked->execute([$userId]);
    if ((int) $linked->fetchColumn() > 0) {
        return;
    }

    $byCode = $pdo->prepare('SELECT id, user_id FROM patients WHERE patient_code = ? LIMIT 1');
    $byCode->execute(['PT-DEMO']);
    $existing = $byCode->fetch();
    if ($existing && ($existing['user_id'] === null || (int) $existing['user_id'] === $userId)) {
        $pdo->prepare('UPDATE patients SET user_id = ? WHERE id = ?')->execute([$userId, (int) $existing['id']]);
        return;
    }
    if ($existing) {
        return;
    }

    portal_insert(
        $pdo,
        'INSERT INTO patients (patient_code, first_name, last_name, sex, birth_date, contact_number, address, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        ['PT-DEMO', 'Demo', 'Patient', 'M', '1995-01-15', '09000000000', 'Patient portal demo account', $userId]
    );
}

function portal_insert(PDO $pdo, string $sql, array $params): int
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql' && stripos($sql, 'returning') === false) {
        $sql = rtrim($sql, "; \t\n\r") . ' RETURNING id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}
