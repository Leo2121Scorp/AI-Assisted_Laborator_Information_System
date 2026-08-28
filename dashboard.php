<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Dashboard — AI-LIS';
$pdo = db();

$stats = [
    'patients' => (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
    'open_requests' => (int) $pdo->query("SELECT COUNT(*) FROM lab_requests WHERE status IN ('open','in_progress')")->fetchColumn(),
    'active_specimens' => (int) $pdo->query("SELECT COUNT(*) FROM specimens WHERE status NOT IN ('completed')")->fetchColumn(),
    'pending_review' => (int) $pdo->query("SELECT COUNT(*) FROM lab_results WHERE status = 'validated'")->fetchColumn(),
    'ai_flags' => (int) $pdo->query('SELECT COUNT(*) FROM lab_results WHERE ai_flagged = 1 AND status IN (\'validated\',\'approved\')')->fetchColumn(),
];

$slaHours = (int) app_config('specimen_sla_hours', 24);
$delayed = $pdo->prepare(
    "SELECT s.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
     FROM specimens s
     JOIN lab_requests lr ON lr.id = s.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     WHERE s.status IN ('pending','collected','processing','delayed')
       AND TIMESTAMPDIFF(HOUR, s.status_updated_at, NOW()) >= ?
     ORDER BY s.status_updated_at ASC
     LIMIT 10"
);
$delayed->execute([$slaHours]);
$delayedRows = $delayed->fetchAll();

$aiHealth = ai_health();

require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h1>Laboratory Dashboard</h1>
    <p>Operational overview for <?= e(app_config('lab_name')) ?>.</p>
    <p>
        AI service:
        <?php if ($aiHealth): ?>
            <span class="badge badge-ok">online</span>
        <?php else: ?>
            <span class="badge badge-warning">offline (manual review still available)</span>
        <?php endif; ?>
    </p>
</div>

<div class="grid grid-3">
    <div class="stat"><div class="num"><?= $stats['patients'] ?></div><div class="label">Patients</div></div>
    <div class="stat"><div class="num"><?= $stats['open_requests'] ?></div><div class="label">Open requests</div></div>
    <div class="stat"><div class="num"><?= $stats['active_specimens'] ?></div><div class="label">Active specimens</div></div>
    <div class="stat"><div class="num"><?= $stats['pending_review'] ?></div><div class="label">Awaiting MT review</div></div>
    <div class="stat"><div class="num"><?= $stats['ai_flags'] ?></div><div class="label">AI warnings open</div></div>
</div>

<div class="card" style="margin-top:1.25rem">
    <h2>Specimen delay alerts (≥ <?= (int) $slaHours ?>h)</h2>
    <?php if (!$delayedRows): ?>
        <p>No delayed specimens.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Specimen</th><th>Request</th><th>Patient</th><th>Status</th><th>Last update</th></tr>
            </thead>
            <tbody>
            <?php foreach ($delayedRows as $row): ?>
                <tr>
                    <td><?= e($row['specimen_code']) ?></td>
                    <td><?= e($row['request_code']) ?></td>
                    <td><?= e($row['patient_name']) ?></td>
                    <td><span class="badge badge-warning"><?= e($row['status']) ?></span></td>
                    <td><?= e($row['status_updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
