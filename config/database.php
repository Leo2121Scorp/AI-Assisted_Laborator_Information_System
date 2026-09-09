<?php
/**
 * Database configuration — local XAMPP (MySQL) defaults; env overrides for Railway/Render.
 */
$driver = 'mysql';
$host = getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
$port = (int) (getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: 3306));
$dbname = getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'ailab_lis');
$username = getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root');
$password = getenv('MYSQLPASSWORD');
if ($password === false) {
    $password = getenv('DB_PASSWORD');
}
if ($password === false) {
    $password = '';
}

// Render Postgres / Railway / generic DATABASE_URL
$url = getenv('DATABASE_URL') ?: getenv('MYSQL_URL');
if (is_string($url) && $url !== '') {
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
    }
}

// Render also exposes discrete Postgres vars
if ($driver === 'mysql' && getenv('PGHOST')) {
    $driver = 'pgsql';
    $host = getenv('PGHOST') ?: $host;
    $port = (int) (getenv('PGPORT') ?: 5432);
    $dbname = getenv('PGDATABASE') ?: $dbname;
    $username = getenv('PGUSER') ?: $username;
    $password = getenv('PGPASSWORD') !== false ? (string) getenv('PGPASSWORD') : $password;
}

return [
    'driver' => $driver,
    'host' => $host,
    'port' => $port,
    'dbname' => $dbname,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
];
