<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('backup');

$backupDir = app_config('backup_dir');
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = require __DIR__ . '/../config/database.php';
    $filename = 'ailab_lis_' . date('Ymd_His') . '.sql';
    $filepath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    $driver = $cfg['driver'] ?? db_driver();
    $ok = false;
    $message = 'Backup failed.';

    if ($driver === 'mysql') {
        $mysqldump = 'mysqldump';
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\xampp\\mysql\\bin\\mysqldump',
            'mysqldump',
        ];
        foreach ($candidates as $c) {
            if ($c === 'mysqldump' || file_exists($c)) {
                $mysqldump = $c;
                break;
            }
        }

        $user = escapeshellarg($cfg['username']);
        $pass = $cfg['password'] !== '' ? '-p' . escapeshellarg($cfg['password']) : '';
        $host = escapeshellarg($cfg['host']);
        $db = escapeshellarg($cfg['dbname']);
        $out = escapeshellarg($filepath);
        $cmd = "\"{$mysqldump}\" -h {$host} -u {$user} {$pass} {$db} > {$out} 2>&1";

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $ok = $code === 0 && file_exists($filepath) && filesize($filepath) > 0;
        if ($ok) {
            $message = 'Backup created via mysqldump.';
        }
    }

    if (!$ok) {
        try {
            $pdo = db();
            if (($cfg['driver'] ?? db_driver()) === 'pgsql') {
                $tables = $pdo->query(
                    "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename"
                )->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            }

            $fh = fopen($filepath, 'w');
            fwrite($fh, '-- AI-LIS PHP fallback dump ' . date('c') . "\n");
            foreach ($tables as $table) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
                    continue;
                }
                fwrite($fh, "\n-- TABLE {$table}\n");
                $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $cols = array_map(static function ($c) {
                        return '"' . str_replace('"', '""', (string) $c) . '"';
                    }, array_keys($row));
                    $vals = array_map(static function ($v) use ($pdo) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        if (is_bool($v)) {
                            return $v ? 'TRUE' : 'FALSE';
                        }
                        return $pdo->quote((string) $v);
                    }, array_values($row));
                    fwrite(
                        $fh,
                        'INSERT INTO "' . $table . '" (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n"
                    );
                }
            }
            fclose($fh);
            $ok = file_exists($filepath) && filesize($filepath) > 0;
            $message = $ok ? 'Backup created via PHP data dump.' : 'Backup failed.';
        } catch (Throwable $e) {
            $ok = false;
            $message = 'Backup failed: ' . $e->getMessage();
        }
    }

    $size = $ok ? filesize($filepath) : null;
    db()->prepare(
        'INSERT INTO backups (file_path, file_size, status, message, created_by) VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $filepath,
        $size,
        $ok ? 'success' : 'failed',
        $message,
        current_user()['id'],
    ]);
    audit_log('backup', 'backup', null, $ok ? "Created {$filename}" : 'Backup failed');
    flash($ok ? 'success' : 'error', $message);
    redirect('backup/index.php');
}

$history = db()->query('SELECT b.*, u.full_name FROM backups b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 50')->fetchAll();

$pageTitle = 'Database Backup — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Database Backup</h1>
    <p>Create a database dump and store it under <code>backups/</code>. Recommended weekly/monthly per the sustainability plan.</p>
    <form method="post">
        <button class="btn" type="submit" data-confirm="Run database backup now?">Run backup now</button>
    </form>
</div>
<div class="card">
    <h2>Backup history</h2>
    <table>
        <thead><tr><th>When</th><th>By</th><th>Status</th><th>Size</th><th>Message</th><th>Path</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= e($h['created_at']) ?></td>
                <td><?= e($h['full_name'] ?: '—') ?></td>
                <td><span class="badge <?= $h['status'] === 'success' ? 'badge-ok' : 'badge-danger' ?>"><?= e($h['status']) ?></span></td>
                <td><?= $h['file_size'] !== null ? number_format((int)$h['file_size']) . ' B' : '—' ?></td>
                <td><?= e($h['message']) ?></td>
                <td><small><?= e(basename($h['file_path'])) ?></small></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$history): ?><tr><td colspan="6">No backups yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
