<?php
declare(strict_types=1);
if (!isset($pageTitle)) {
    $pageTitle = app_config('app_name');
}
$flash = get_flash();
$user = current_user();
$navKey = current_nav_key();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= e(base_url('assets/img/clinic-logo.png')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
<?php if ($user): ?>
<header class="topbar" id="topbar">
    <div class="topbar-bar">
        <a class="brand" href="<?= e(base_url('dashboard.php')) ?>">
            <img class="brand-logo" src="<?= e(base_url('assets/img/clinic-logo.png')) ?>" alt="Lagman Qualicare logo" width="42" height="42">
            <span class="brand-text">
                <strong>AI-LIS</strong>
                <span><?= e(app_config('lab_name')) ?></span>
            </span>
        </a>
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="topbar-menu" aria-label="Open menu">
            <span class="nav-toggle-bars" aria-hidden="true"></span>
        </button>
    </div>
    <div class="topbar-menu" id="topbar-menu">
        <nav class="nav" id="main-nav" aria-label="Main">
            <a href="<?= e(base_url('dashboard.php')) ?>"<?= nav_link_class('dashboard', $navKey) ?>>Dashboard</a>
            <?php if (can('patients')): ?><a href="<?= e(base_url('patients/index.php')) ?>"<?= nav_link_class('patients', $navKey) ?>>Patients</a><?php endif; ?>
            <?php if (can('requests')): ?><a href="<?= e(base_url('requests/index.php')) ?>"<?= nav_link_class('requests', $navKey) ?>>Requests</a><?php endif; ?>
            <?php if (can('specimen_collect')): ?><a href="<?= e(base_url('specimens/index.php')) ?>"<?= nav_link_class('specimens', $navKey) ?>>Specimens</a><?php endif; ?>
            <?php if (can('encode_results')): ?><a href="<?= e(base_url('results/index.php')) ?>"<?= nav_link_class('results', $navKey) ?>>Results</a><?php endif; ?>
            <?php if (can('view_reports')): ?><a href="<?= e(base_url('reports/index.php')) ?>"<?= nav_link_class('reports', $navKey) ?>>Reports</a><?php endif; ?>
            <?php if (can('view_audit')): ?><a href="<?= e(base_url('audit/index.php')) ?>"<?= nav_link_class('audit', $navKey) ?>>Audit</a><?php endif; ?>
            <?php if (can('backup')): ?><a href="<?= e(base_url('backup/index.php')) ?>"<?= nav_link_class('backup', $navKey) ?>>Backup</a><?php endif; ?>
            <?php if (can('manage_ranges')): ?><a href="<?= e(base_url('admin/ranges.php')) ?>"<?= nav_link_class('ranges', $navKey) ?>>Ranges</a><?php endif; ?>
            <?php if (can('manage_users')): ?><a href="<?= e(base_url('admin/users.php')) ?>"<?= nav_link_class('users', $navKey) ?>>Users</a><?php endif; ?>
        </nav>
        <div class="userbox">
            <button type="button" class="btn btn-small btn-ghost" id="open-guide-btn" data-guide-open title="Open role guide">Guide</button>
            <span class="user-role" title="<?= e(role_label()) ?>"><?= e($user['full_name']) ?> · <?= e(role_short_label()) ?></span>
            <a class="btn btn-small" href="<?= e(base_url('logout.php')) ?>">Logout</a>
        </div>
    </div>
</header>
<?php endif; ?>
<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
