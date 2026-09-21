<?php
declare(strict_types=1);

$dbConfig = require __DIR__ . '/../config/database.php';
$appConfig = require __DIR__ . '/../config/app.php';

date_default_timezone_set($appConfig['timezone']);

if (session_status() === PHP_SESSION_NONE) {
    session_name($appConfig['session_name']);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = require __DIR__ . '/../config/database.php';
    $driver = $cfg['driver'] ?? 'mysql';

    // Hosted deploy without DATABASE_URL must not try XAMPP localhost
    if (!empty($cfg['hosted']) && ($cfg['host'] === '127.0.0.1' || $cfg['host'] === 'localhost')) {
        throw new RuntimeException(
            'DATABASE_URL is not reaching this service. On Render: open ailab-web → Environment → '
            . 'set DATABASE_URL to the Postgres External Database URL (full host ending in -postgres.render.com), then Save, rebuild, and deploy.'
        );
    }

    if ($driver === 'pgsql') {
        $hosts = $cfg['host_candidates'] ?? [$cfg['host']];
        $ssl = $cfg['sslmode'] ?? 'require';
        $last = null;
        foreach ($hosts as $host) {
            if (!is_string($host) || $host === '' || str_contains($host, '…')) {
                continue;
            }
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $host,
                $cfg['port'],
                $cfg['dbname'],
                $ssl
            );
            try {
                $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                return $pdo;
            } catch (Throwable $e) {
                $last = $e;
            }
        }
        throw new RuntimeException(
            'Could not connect to Postgres. Use the External Database URL from Render (host must look like '
            . 'dpg-xxxxx-a.REGION-postgres.render.com). Last error: '
            . ($last ? $last->getMessage() : 'unknown'),
            0,
            $last
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['dbname'],
        $cfg['charset']
    );
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function db_driver(): string
{
    return (string) ((require __DIR__ . '/../config/database.php')['driver'] ?? 'mysql');
}

/**
 * INSERT and return the new id. Postgres PDO lastInsertId() is often 0 unless
 * the statement uses RETURNING id — which is why Render requests created no results.
 */
function db_insert(string $sql, array $params = [], ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();
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

/**
 * Safe connection facts for the Database control page (never includes the password).
 *
 * @return array{driver:string,host:string,port:int,dbname:string,username:string,hosted:bool,sslmode:?string,is_render:bool,url_set:bool,engine_label:string}
 */
function db_public_info(): array
{
    $cfg = require __DIR__ . '/../config/database.php';
    $host = (string) ($cfg['host'] ?? '');
    $isRender = str_contains($host, 'render.com')
        || str_starts_with($host, 'dpg-')
        || ailab_env('RENDER') === 'true';
    $driver = (string) ($cfg['driver'] ?? 'mysql');
    return [
        'driver' => $driver,
        'host' => $host,
        'port' => (int) ($cfg['port'] ?? 0),
        'dbname' => (string) ($cfg['dbname'] ?? ''),
        'username' => (string) ($cfg['username'] ?? ''),
        'hosted' => !empty($cfg['hosted']),
        'sslmode' => isset($cfg['sslmode']) ? (string) $cfg['sslmode'] : null,
        'is_render' => $isRender,
        'url_set' => ailab_env('DATABASE_URL') !== null || ailab_env('PGHOST') !== null,
        'engine_label' => $driver === 'pgsql' ? 'PostgreSQL' : 'MySQL',
    ];
}

/** @return array<string,int> */
function result_status_counts(): array
{
    $keys = ['pending', 'encoded', 'validated', 'approved', 'reported', 'released'];
    $out = array_fill_keys($keys, 0);
    try {
        $rows = db()->query('SELECT status, COUNT(*) AS n FROM lab_results GROUP BY status')->fetchAll();
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($out[$status])) {
                $out[$status] = (int) $row['n'];
            }
        }
    } catch (Throwable $e) {
        return $out + ['all' => 0, 'warnings' => 0];
    }
    $out['all'] = array_sum($out);
    try {
        $out['warnings'] = (int) db()->query(
            'SELECT COUNT(*) FROM lab_results r WHERE ' . sql_result_has_warning()
        )->fetchColumn();
    } catch (Throwable $e) {
        $out['warnings'] = 0;
    }
    return $out;
}

/** Rule-based OOR/critical, rule_warnings text, or Isolation Forest flag — any status. */
function sql_result_has_warning(string $alias = 'r'): string
{
    return "({$alias}.ai_flagged = 1
        OR ({$alias}.rule_warnings IS NOT NULL AND {$alias}.rule_warnings <> '')
        OR EXISTS (
            SELECT 1 FROM result_values rv
            WHERE rv.lab_result_id = {$alias}.id
              AND (rv.is_out_of_range = 1 OR rv.is_critical = 1)
        ))";
}

/**
 * Portable ORDER BY priority list (replaces MySQL FIELD()).
 * Example: sql_order_by_list('s.status', ['missing','delayed','pending'])
 */
function sql_order_by_list(string $expression, array $values): string
{
    $parts = [];
    foreach (array_values($values) as $i => $value) {
        $parts[] = 'WHEN ' . db()->quote((string) $value) . ' THEN ' . ($i + 1);
    }
    return 'CASE ' . $expression . ' ' . implode(' ', $parts) . ' ELSE ' . (count($values) + 1) . ' END';
}

function app_config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/app.php';
    }
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function base_path(string $path = ''): string
{
    $root = dirname(__DIR__);
    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

function base_url(string $path = ''): string
{
    $configured = app_config('base_url', '');
    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . ltrim($path, '/');
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Project lives under /AI-Assisted_Laborator_Information_System/public or project root
    $dir = str_replace('\\', '/', dirname($script));
    // If in a subdirectory (patients, results, etc.), go up to app root URL
    if (preg_match('#/(patients|requests|specimens|results|reports|admin|audit|backup|api)$#', $dir)) {
        $dir = dirname($dir);
    }
    $base = rtrim($dir, '/');
    if ($base === '' || $base === '\\') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . base_url($path));
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function generate_code(string $prefix): string
{
    return $prefix . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
}

function patient_age(string $birthDate): int
{
    $dob = new DateTime($birthDate);
    $now = new DateTime('today');
    return (int) $dob->diff($now)->y;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/guides.php';
require_once __DIR__ . '/demo_seed.php';

// One-time (or rare) catalog migration for existing deployments that predate URINE/STOOL.
try {
    $urineCount = (int) db()->query("SELECT COUNT(*) FROM lab_tests WHERE panel_code = 'URINE'")->fetchColumn();
    $stoolCount = (int) db()->query("SELECT COUNT(*) FROM lab_tests WHERE panel_code = 'STOOL'")->fetchColumn();
    if ($urineCount < 19 || $stoolCount < 12) {
        ensure_lab_test_catalog();
    }
} catch (Throwable $e) {
    // Schema may not exist yet during first install — ignore.
}
