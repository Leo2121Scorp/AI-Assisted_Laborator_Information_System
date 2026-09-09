<?php
/**
 * Database configuration — local XAMPP (MySQL) defaults; env overrides for Railway/Render.
 */

/** @return string|null */
function ailab_env(string $key): ?string
{
    static $fileEnv = null;
    if ($fileEnv === null) {
        $path = __DIR__ . '/env.php';
        $fileEnv = is_file($path) ? (require $path) : [];
        if (!is_array($fileEnv)) {
            $fileEnv = [];
        }
    }
    if (isset($fileEnv[$key]) && $fileEnv[$key] !== '' && $fileEnv[$key] !== null) {
        return (string) $fileEnv[$key];
    }
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    return null;
}

$driver = 'mysql';
$host = ailab_env('MYSQLHOST') ?? ailab_env('DB_HOST') ?? '127.0.0.1';
$port = (int) (ailab_env('MYSQLPORT') ?? ailab_env('DB_PORT') ?? 3306);
$dbname = ailab_env('MYSQLDATABASE') ?? ailab_env('DB_NAME') ?? 'ailab_lis';
$username = ailab_env('MYSQLUSER') ?? ailab_env('DB_USER') ?? 'root';
$password = ailab_env('MYSQLPASSWORD') ?? ailab_env('DB_PASSWORD') ?? '';

// Render Postgres / Railway / generic DATABASE_URL
$url = ailab_env('DATABASE_URL') ?? ailab_env('MYSQL_URL');
if (is_string($url) && $url !== '') {
    // Render sometimes uses postgres:// — normalize for parse_url
    $parts = parse_url($url);
    if ($parts !== false && isset($parts['scheme'])) {
        $scheme = strtolower($parts['scheme']);
        if (in_array($scheme, ['postgres', 'postgresql'], true)) {
            $driver = 'pgsql';
            $port = isset($parts['port']) ? (int) $parts['port'] : 5432;
        } elseif (str_starts_with($scheme, 'mysql')) {
            $driver = 'mysql';
            $port = isset($parts['port']) ? (int) $parts['port'] : 3306;
        }
        $host = $parts['host'] ?? $host;
        $username = isset($parts['user']) ? urldecode($parts['user']) : $username;
        $password = isset($parts['pass']) ? urldecode($parts['pass']) : $password;
        $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : $dbname;
        // Strip query params from db name if present
        if (str_contains($dbname, '?')) {
            $dbname = strstr($dbname, '?', true);
        }
    }
}

// Render also exposes discrete Postgres vars
if ($driver === 'mysql' && ailab_env('PGHOST')) {
    $driver = 'pgsql';
    $host = ailab_env('PGHOST') ?? $host;
    $port = (int) (ailab_env('PGPORT') ?? 5432);
    $dbname = ailab_env('PGDATABASE') ?? $dbname;
    $username = ailab_env('PGUSER') ?? $username;
    $password = ailab_env('PGPASSWORD') ?? $password;
}

// On Render/Railway, never silently fall back to localhost MySQL
$hosted = ailab_env('RENDER') === 'true'
    || ailab_env('RAILWAY_ENVIRONMENT') !== null
    || ailab_env('DATABASE_URL') !== null
    || ailab_env('PGHOST') !== null;

return [
    'driver' => $driver,
    'host' => $host,
    'port' => $port,
    'dbname' => $dbname,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'hosted' => $hosted,
];
