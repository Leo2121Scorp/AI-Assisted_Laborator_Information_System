<?php
/**
 * One-time installer: creates schema, seed data, and demo users.
 * Open in browser once, then delete or protect this file.
 */
declare(strict_types=1);

$config = require __DIR__ . '/config/database.php';
$messages = [];
$ok = true;

function exec_sql_file(PDO $pdo, string $path, array &$messages, bool $skipCreateDb = false): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $parts = preg_split('/;\s*\n/', $sql);
    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            $lines = array_filter(array_map('trim', explode("\n", $stmt)), fn($l) => $l !== '' && !str_starts_with($l, '--'));
            if (!$lines) {
                continue;
            }
            $stmt = implode("\n", $lines);
        }
        $cleanLines = [];
        foreach (explode("\n", $stmt) as $line) {
            if (str_starts_with(trim($line), '--')) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $stmt = trim(implode("\n", $cleanLines));
        if ($stmt === '') {
            continue;
        }
        if ($skipCreateDb) {
            if (preg_match('/^\s*CREATE\s+DATABASE\b/i', $stmt)) {
                continue;
            }
            if (preg_match('/^\s*USE\s+/i', $stmt)) {
                continue;
            }
            if (preg_match('/^\s*SET\s+NAMES\b/i', $stmt)) {
                continue;
            }
        }
        $pdo->exec($stmt);
    }
    $messages[] = basename($path) . ' executed.';
}

try {
    $driver = $config['driver'] ?? 'mysql';
    $managedMysql = (getenv('MYSQLHOST') !== false && getenv('MYSQLHOST') !== '')
        || (getenv('MYSQL_URL') !== false && getenv('MYSQL_URL') !== '');

    if ($driver === 'pgsql') {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $config['host'],
            $config['port'],
            $config['dbname']
        );
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        exec_sql_file($pdo, __DIR__ . '/database/schema.postgres.sql', $messages, true);
        exec_sql_file($pdo, __DIR__ . '/database/seed.postgres.sql', $messages, true);
    } elseif ($managedMysql) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['dbname'],
            $config['charset']
        );
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        exec_sql_file($pdo, __DIR__ . '/database/schema.sql', $messages, true);
        exec_sql_file($pdo, __DIR__ . '/database/seed.sql', $messages, true);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']);
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        exec_sql_file($pdo, __DIR__ . '/database/schema.sql', $messages, false);
        exec_sql_file($pdo, __DIR__ . '/database/seed.sql', $messages, false);
        $pdo->exec('USE `' . str_replace('`', '``', $config['dbname']) . '`');
    }

    $hash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->exec('DELETE FROM users');
    $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)');
    $ins->execute(['manager', $hash, 'Laboratory Manager', 'manager']);
    $ins->execute(['medtech', $hash, 'Medical Technologist', 'med_tech']);
    $ins->execute(['staff', $hash, 'Administrative Staff', 'staff']);
    $messages[] = 'Demo users created (password: password123).';

    if (!is_dir(__DIR__ . '/backups')) {
        mkdir(__DIR__ . '/backups', 0775, true);
    }
    $messages[] = 'Backup directory ready.';
} catch (Throwable $e) {
    $ok = false;
    $messages[] = 'ERROR: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI-LIS Installer</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="card login-card">
        <h1>Installer</h1>
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-<?= str_starts_with($m, 'ERROR') ? 'error' : ($ok ? 'success' : 'error') ?>"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
        <?php if ($ok): ?>
            <p>Next steps:</p>
            <ol>
                <li><a href="login.php">Go to login</a></li>
                <li>Delete or restrict <code>install.php</code> after setup.</li>
            </ol>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
