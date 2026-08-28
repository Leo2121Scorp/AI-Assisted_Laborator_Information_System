<?php
declare(strict_types=1);
if (!isset($pageTitle)) {
    $pageTitle = app_config('app_name');
}
$flash = get_flash();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body>
<?php if ($user): ?>
<header class="topbar">
    <div class="brand">
        <strong>AI-LIS</strong>
        <span><?= e(app_config('lab_name')) ?></span>
    </div>
    <nav class="nav">
        <a href="<?= e(base_url('dashboard.php')) ?>">Dashboard</a>
        <?php if (can('patients')): ?><a href="<?= e(base_url('patients/index.php')) ?>">Patients</a><?php endif; ?>
        <?php if (can('requests')): ?><a href="<?= e(base_url('requests/index.php')) ?>">Requests</a><?php endif; ?>
        <?php if (can('specimen_collect')): ?><a href="<?= e(base_url('specimens/index.php')) ?>">Specimens</a><?php endif; ?>
        <?php if (can('encode_results')): ?><a href="<?= e(base_url('results/index.php')) ?>">Results</a><?php endif; ?>
        <?php if (can('view_reports')): ?><a href="<?= e(base_url('reports/index.php')) ?>">Reports</a><?php endif; ?>
        <?php if (can('view_audit')): ?><a href="<?= e(base_url('audit/index.php')) ?>">Audit</a><?php endif; ?>
        <?php if (can('backup')): ?><a href="<?= e(base_url('backup/index.php')) ?>">Backup</a><?php endif; ?>
        <?php if (can('manage_ranges')): ?><a href="<?= e(base_url('admin/ranges.php')) ?>">Ranges</a><?php endif; ?>
        <?php if (can('manage_users')): ?><a href="<?= e(base_url('admin/users.php')) ?>">Users</a><?php endif; ?>
    </nav>
    <div class="userbox">
        <span><?= e($user['full_name']) ?> (<?= e($user['role']) ?>)</span>
        <a class="btn btn-small" href="<?= e(base_url('logout.php')) ?>">Logout</a>
    </div>
</header>
<?php endif; ?>
<main class="container">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
