<?php
/**
 * Database configuration — local XAMPP (MySQL) defaults; env overrides for Railway/Render.
 */

if (!function_exists('ailab_env')) {
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
}

$driver = 'mysql';
$host = ailab_env('MYSQLHOST') ?? ailab_env('DB_HOST') ?? '127.0.0.1';
$port = (int) (ailab_env('MYSQLPORT') ?? ailab_env('DB_PORT') ?? 3306);
$dbname = ailab_env('MYSQLDATABASE') ?? ailab_env('DB_NAME') ?? 'ailab_lis';
$username = ailab_env('MYSQLUSER') ?? ailab_env('DB_USER') ?? 'root';
$password = ailab_env('MYSQLPASSWORD') ?? ailab_env('DB_PASSWORD') ?? '';
$sslmode = null;
$hostCandidates = [];

// Render Postgres / Railway / generic DATABASE_URL
$url = ailab_env('DATABASE_URL') ?? ailab_env('MYSQL_URL');
if (is_string($url) && $url !== '') {
    if (str_contains($url, '…') || str_contains($url, '...')) {
        throw new RuntimeException(
            'DATABASE_URL looks truncated (contains …). Re-copy the full External Database URL from Render Postgres → Connections.'
        );
    }

    // Fix common copy mistake: missing "@" before Render host (dpg-...)
    if (preg_match('#^(postgres(?:ql)?://[^:/]+):([^@/]+)(dpg-[^/@]+)(/.*)?$#i', $url, $m)) {
        $url = $m[1] . ':' . $m[2] . '@' . $m[3] . ($m[4] ?? '');
    }

    $parts = parse_url($url);
    if ($parts !== false && isset($parts['scheme'])) {
        $scheme = strtolower($parts['scheme']);
        if (in_array($scheme, ['postgres', 'postgresql'], true)) {
            $driver = 'pgsql';
            $port = isset($parts['port']) ? (int) $parts['port'] : 5432;
            $sslmode = 'require';
        } elseif (str_starts_with($scheme, 'mysql')) {
            $driver = 'mysql';
            $port = isset($parts['port']) ? (int) $parts['port'] : 3306;
        }
        $host = $parts['host'] ?? $host;
        $username = isset($parts['user']) ? urldecode($parts['user']) : $username;
        $password = isset($parts['pass']) ? urldecode($parts['pass']) : $password;
        $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : $dbname;
        if (str_contains($dbname, '?')) {
            $dbname = strstr($dbname, '?', true);
        }
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['sslmode'])) {
                $sslmode = (string) $q['sslmode'];
            }
        }
    }
}

// Render also exposes discrete Postgres vars
if (($driver === 'mysql' && ailab_env('PGHOST')) || ailab_env('PGHOST')) {
    if (ailab_env('PGHOST')) {
        $driver = 'pgsql';
        $host = ailab_env('PGHOST') ?? $host;
        $port = (int) (ailab_env('PGPORT') ?? 5432);
        $dbname = ailab_env('PGDATABASE') ?? $dbname;
        $username = ailab_env('PGUSER') ?? $username;
        $password = ailab_env('PGPASSWORD') ?? $password;
        $sslmode = $sslmode ?: 'require';
    }
}

// Build host candidates: internal short name often fails DNS; try external FQDNs
$hostCandidates[] = $host;
if ($driver === 'pgsql' && is_string($host) && preg_match('/^dpg-[a-z0-9]+-a$/i', $host)) {
    $regions = ['oregon', 'singapore', 'frankfurt', 'ohio', 'virginia'];
    $preferred = ailab_env('RENDER_POSTGRES_REGION');
    if ($preferred) {
        array_unshift($regions, strtolower($preferred));
        $regions = array_values(array_unique($regions));
    }
    foreach ($regions as $region) {
        $hostCandidates[] = $host . '.' . $region . '-postgres.render.com';
    }
}
$hostCandidates = array_values(array_unique(array_filter($hostCandidates)));

// On Render/Railway, never silently fall back to localhost MySQL
$hosted = ailab_env('RENDER') === 'true'
    || ailab_env('RAILWAY_ENVIRONMENT') !== null
    || ailab_env('DATABASE_URL') !== null
    || ailab_env('PGHOST') !== null;

return [
    'driver' => $driver,
    'host' => $host,
    'host_candidates' => $hostCandidates,
    'port' => $port,
    'dbname' => $dbname,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'sslmode' => $sslmode,
    'hosted' => $hosted,
];
