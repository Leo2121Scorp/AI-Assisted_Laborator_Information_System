<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Dashboard — AI-LIS';
$pdo = db();
$role = user_role();

$stats = [
    'patients' => (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
    'open_requests' => (int) $pdo->query("SELECT COUNT(*) FROM lab_requests WHERE status IN ('open','in_progress')")->fetchColumn(),
    'active_specimens' => (int) $pdo->query("SELECT COUNT(*) FROM specimens WHERE status NOT IN ('completed')")->fetchColumn(),
    'pending_review' => (int) $pdo->query("SELECT COUNT(*) FROM lab_results WHERE status = 'validated'")->fetchColumn(),
    'ai_flags' => (int) $pdo->query('SELECT COUNT(*) FROM lab_results WHERE ai_flagged = 1 AND status IN (\'validated\',\'approved\')')->fetchColumn(),
    'pending_collect' => (int) $pdo->query("SELECT COUNT(*) FROM specimens WHERE status = 'pending'")->fetchColumn(),
    'released_reports' => (int) $pdo->query("SELECT COUNT(*) FROM lab_results WHERE status IN ('reported','released')")->fetchColumn(),
];

$slaHours = (int) app_config('specimen_sla_hours', 24);
$ageExpr = db_driver() === 'pgsql'
    ? '(EXTRACT(EPOCH FROM (NOW() - s.status_updated_at)) / 3600)'
    : 'TIMESTAMPDIFF(HOUR, s.status_updated_at, NOW())';
$delayed = $pdo->prepare(
    "SELECT s.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
     FROM specimens s
     JOIN lab_requests lr ON lr.id = s.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     WHERE s.status IN ('pending','collected','processing','delayed')
       AND {$ageExpr} >= ?
     ORDER BY s.status_updated_at ASC
     LIMIT 10"
);
$delayed->execute([$slaHours]);
$delayedRows = $delayed->fetchAll();

$aiHealth = ai_health();

$quickActions = match ($role) {
    ROLE_STAFF => [
        ['label' => 'Register patient', 'href' => 'patients/create.php', 'primary' => true],
        ['label' => 'New request', 'href' => 'requests/create.php', 'primary' => false],
        ['label' => 'Collect specimens', 'href' => 'specimens/index.php?status=pending', 'primary' => false],
        ['label' => 'Find reports', 'href' => 'reports/index.php', 'primary' => false],
    ],
    ROLE_MED_TECH => [
        ['label' => 'Review results', 'href' => 'results/index.php?status=validated', 'primary' => true],
        ['label' => 'Encode pending', 'href' => 'results/index.php?status=pending', 'primary' => false],
        ['label' => 'Process specimens', 'href' => 'specimens/index.php?status=collected', 'primary' => false],
        ['label' => 'AI warnings', 'href' => 'results/index.php?ai=1', 'primary' => false],
    ],
    ROLE_MANAGER => [
        ['label' => 'Awaiting review', 'href' => 'results/index.php?status=validated', 'primary' => true],
        ['label' => 'Manage users', 'href' => 'admin/users.php', 'primary' => false],
        ['label' => 'Reference ranges', 'href' => 'admin/ranges.php', 'primary' => false],
        ['label' => 'Run backup', 'href' => 'backup/index.php', 'primary' => false],
    ],
    default => [
        ['label' => 'Patients', 'href' => 'patients/index.php', 'primary' => true],
    ],
};

$workflowHint = match ($role) {
    ROLE_STAFF => 'Your flow: Patient → Request → Collect specimen → Look up report.',
    ROLE_MED_TECH => 'Your flow: Process specimen → Encode → Review AI → Approve → Release.',
    ROLE_MANAGER => 'Your focus: delays, pending review, AI flags, users, ranges, and backups.',
    default => 'Use the Guide button anytime for a step-by-step walkthrough.',
};

require __DIR__ . '/includes/header.php';
?>
<div class="card dashboard-hero">
    <div class="dashboard-hero-text">
        <h1>Laboratory Dashboard</h1>
        <p class="muted"><?= e(app_config('lab_name')) ?> · signed in as <strong><?= e(role_label()) ?></strong></p>
        <p class="workflow-hint"><?= e($workflowHint) ?></p>
        <p class="ai-status">
            AI service:
            <?php if ($aiHealth): ?>
                <span class="badge badge-ok">online</span>
            <?php else: ?>
                <span class="badge badge-warning">offline (manual review still available)</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="dashboard-hero-actions">
        <button type="button" class="btn" data-guide-open>Open <?= e(role_short_label()) ?> guide</button>
        <button type="button" class="btn btn-secondary" data-guide-open data-guide-restart>Replay demo</button>
    </div>
</div>

<div class="card quick-actions-card">
    <h2>Quick actions</h2>
    <div class="quick-actions">
        <?php foreach ($quickActions as $action): ?>
            <a class="btn<?= empty($action['primary']) ? ' btn-secondary' : '' ?>" href="<?= e(base_url($action['href'])) ?>"><?= e($action['label']) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-3 stats-grid">
    <a class="stat stat-link" href="<?= e(base_url('patients/index.php')) ?>">
        <div class="num"><?= $stats['patients'] ?></div>
        <div class="label">Patients</div>
        <span class="stat-go">Open →</span>
    </a>
    <a class="stat stat-link" href="<?= e(base_url('requests/index.php?status=open')) ?>">
        <div class="num"><?= $stats['open_requests'] ?></div>
        <div class="label">Open requests</div>
        <span class="stat-go">Open →</span>
    </a>
    <a class="stat stat-link" href="<?= e(base_url('specimens/index.php?status=active')) ?>">
        <div class="num"><?= $stats['active_specimens'] ?></div>
        <div class="label">Active specimens</div>
        <span class="stat-go">Open →</span>
    </a>
    <?php if (can('encode_results')): ?>
        <a class="stat stat-link" href="<?= e(base_url('results/index.php?status=validated')) ?>">
            <div class="num"><?= $stats['pending_review'] ?></div>
            <div class="label">Awaiting MT review</div>
            <span class="stat-go">Review →</span>
        </a>
        <a class="stat stat-link" href="<?= e(base_url('results/index.php?ai=1')) ?>">
            <div class="num"><?= $stats['ai_flags'] ?></div>
            <div class="label">AI warnings open</div>
            <span class="stat-go">Review →</span>
        </a>
    <?php else: ?>
        <a class="stat stat-link" href="<?= e(base_url('specimens/index.php?status=pending')) ?>">
            <div class="num"><?= $stats['pending_collect'] ?></div>
            <div class="label">Awaiting collection</div>
            <span class="stat-go">Collect →</span>
        </a>
        <a class="stat stat-link" href="<?= e(base_url('reports/index.php')) ?>">
            <div class="num"><?= $stats['released_reports'] ?></div>
            <div class="label">Ready reports</div>
            <span class="stat-go">Open →</span>
        </a>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:1.25rem">
    <div class="card-head">
        <h2>Specimen delay alerts (≥ <?= (int) $slaHours ?>h)</h2>
        <a class="btn btn-small btn-secondary" href="<?= e(base_url('specimens/index.php?status=delayed')) ?>">All specimens</a>
    </div>
    <?php if (!$delayedRows): ?>
        <div class="empty-state">
            <p>No delayed specimens. Samples are within the SLA window.</p>
            <a class="btn btn-secondary" href="<?= e(base_url('specimens/index.php')) ?>">View specimen board</a>
        </div>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Specimen</th><th>Request</th><th>Patient</th><th>Status</th><th>Last update</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($delayedRows as $row): ?>
                <tr>
                    <td><?= e($row['specimen_code']) ?></td>
                    <td><?= e($row['request_code']) ?></td>
                    <td><?= e($row['patient_name']) ?></td>
                    <td><span class="badge badge-warning"><?= e($row['status']) ?></span></td>
                    <td><?= e($row['status_updated_at']) ?></td>
                    <td><a href="<?= e(base_url('specimens/view.php?id=' . $row['id'])) ?>">Update</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
