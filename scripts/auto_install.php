<?php
/**
 * Non-interactive installer for hosted boots (Render/Railway).
 * Safe to call repeatedly: only seeds when users table is missing or empty.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../config/database.php';
$driver = $config['driver'] ?? 'mysql';

function exec_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $parts = preg_split('/;\s*\n/', $sql);
    foreach ($parts as $part) {
        $cleanLines = [];
        foreach (explode("\n", $part) as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $stmt = trim(implode("\n", $cleanLines));
        if ($stmt === '') {
            continue;
        }
        if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+|SET\s+NAMES)\b/i', $stmt)) {
            continue;
        }
        $pdo->exec($stmt);
    }
}

try {
    if ($driver === 'pgsql') {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['dbname']);
        $schema = __DIR__ . '/../database/schema.postgres.sql';
        $seed = __DIR__ . '/../database/seed.postgres.sql';
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['dbname'],
            $config['charset']
        );
        $schema = __DIR__ . '/../database/schema.sql';
        $seed = __DIR__ . '/../database/seed.sql';
    }

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $needsInstall = false;
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $needsInstall = $count === 0;
    } catch (Throwable $e) {
        $needsInstall = true;
    }

    if (!$needsInstall) {
        fwrite(STDOUT, "auto_install: already initialized\n");
        exit(0);
    }

    exec_sql_file($pdo, $schema);
    exec_sql_file($pdo, $seed);

    $hash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->exec('DELETE FROM users');
    $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)');
    $ins->execute(['manager', $hash, 'Laboratory Manager', 'manager']);
    $ins->execute(['medtech', $hash, 'Medical Technologist', 'med_tech']);
    $ins->execute(['staff', $hash, 'Administrative Staff', 'staff']);

    $backupDir = __DIR__ . '/../backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    fwrite(STDOUT, "auto_install: schema + demo users ready\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'auto_install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
