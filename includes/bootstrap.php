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
    if (preg_match('#/(patients|requests|specimens|results|reports|admin|audit|backup)$#', $dir)) {
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
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/workflow.php';
