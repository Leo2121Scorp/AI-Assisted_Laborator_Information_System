<?php
declare(strict_types=1);

const ROLE_MANAGER = 'manager';
const ROLE_MED_TECH = 'med_tech';
const ROLE_STAFF = 'staff';

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
}

function user_role(): ?string
{
    $user = current_user();
    return $user['role'] ?? null;
}

function role_label(?string $role = null): string
{
    $role = $role ?? user_role();
    return match ($role) {
        ROLE_MANAGER => 'Laboratory Manager',
        ROLE_MED_TECH => 'Medical Technologist',
        ROLE_STAFF => 'Administrative Staff',
        default => $role ? ucwords(str_replace('_', ' ', $role)) : 'Guest',
    };
}

function role_short_label(?string $role = null): string
{
    $role = $role ?? user_role();
    return match ($role) {
        ROLE_MANAGER => 'Manager',
        ROLE_MED_TECH => 'MedTech',
        ROLE_STAFF => 'Staff',
        default => 'User',
    };
}

/** Current top-nav key for active link highlighting. */
function current_nav_key(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/patients/')) {
        return 'patients';
    }
    if (str_contains($script, '/requests/')) {
        return 'requests';
    }
    if (str_contains($script, '/specimens/')) {
        return 'specimens';
    }
    if (str_contains($script, '/results/')) {
        return 'results';
    }
    if (str_contains($script, '/reports/')) {
        return 'reports';
    }
    if (str_contains($script, '/audit/')) {
        return 'audit';
    }
    if (str_contains($script, '/backup/')) {
        return 'backup';
    }
    if (str_contains($script, '/admin/ranges')) {
        return 'ranges';
    }
    if (str_contains($script, '/admin/users') || str_contains($script, '/admin/user_')) {
        return 'users';
    }
    if (str_ends_with($script, '/dashboard.php') || str_ends_with($script, 'dashboard.php')) {
        return 'dashboard';
    }
    return '';
}

function nav_link_class(string $key, string $current): string
{
    return $key === $current ? ' class="is-active"' : '';
}

function has_role(string ...$roles): bool
{
    $role = user_role();
    return $role !== null && in_array($role, $roles, true);
}

function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        flash('error', 'You do not have permission to access that page.');
        redirect('dashboard.php');
    }
}

/**
 * Permission helpers aligned with docs/RBAC_AND_STATE_MACHINE.md
 */
function can(string $permission): bool
{
    $map = [
        'manage_users' => [ROLE_MANAGER],
        'manage_ranges' => [ROLE_MANAGER],
        'patients' => [ROLE_MANAGER, ROLE_MED_TECH, ROLE_STAFF],
        'edit_patients' => [ROLE_MANAGER],
        'delete_patients' => [ROLE_MANAGER],
        'requests' => [ROLE_MANAGER, ROLE_MED_TECH, ROLE_STAFF],
        'specimen_collect' => [ROLE_MANAGER, ROLE_MED_TECH, ROLE_STAFF],
        'specimen_process' => [ROLE_MANAGER, ROLE_MED_TECH],
        'encode_results' => [ROLE_MANAGER, ROLE_MED_TECH],
        'approve_results' => [ROLE_MANAGER, ROLE_MED_TECH],
        'release_reports' => [ROLE_MANAGER, ROLE_MED_TECH],
        'view_reports' => [ROLE_MANAGER, ROLE_MED_TECH, ROLE_STAFF],
        'view_audit' => [ROLE_MANAGER, ROLE_MED_TECH],
        'backup' => [ROLE_MANAGER],
        'view_ai' => [ROLE_MANAGER, ROLE_MED_TECH],
        'use_ai_chat' => [ROLE_MANAGER, ROLE_MED_TECH],
    ];
    if (!isset($map[$permission])) {
        return false;
    }
    return has_role(...$map[$permission]);
}

function require_permission(string $permission): void
{
    require_login();
    if (!can($permission)) {
        flash('error', 'You do not have permission for that action.');
        redirect('dashboard.php');
    }
}

function active_manager_count(): int
{
    return (int) db()->query(
        "SELECT COUNT(*) FROM users WHERE role = 'manager' AND is_active = 1"
    )->fetchColumn();
}

function is_last_active_manager(array $user): bool
{
    return ($user['role'] ?? '') === ROLE_MANAGER
        && (int) ($user['is_active'] ?? 0) === 1
        && active_manager_count() <= 1;
}

function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
    ];
    audit_log('login', 'user', (int) $user['id'], 'User logged in');
    return true;
}

function logout_user(): void
{
    if (is_logged_in()) {
        audit_log('logout', 'user', (int) current_user()['id'], 'User logged out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
