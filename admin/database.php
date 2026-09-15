<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('view_database');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seed_demo') {
    $out = seed_demo_lab_cases(db());
    flash($out['ok'] ? 'success' : 'error', $out['message']);
    redirect('admin/database.php');
}

$info = db_public_info();
$counts = result_status_counts();
$pdo = db();
$driver = db_driver();

$tableCounts = [];
try {
    if ($driver === 'pgsql') {
        $names = $pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }
    foreach ($names as $table) {
        $table = (string) $table;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }
        $tableCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
} catch (Throwable $e) {
    $tableCounts = [];
}

$recentResults = [];
try {
    $recentResults = $pdo->query(
        "SELECT r.result_code, r.status, r.panel_code, r.ai_flagged, r.updated_at,
                lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name, r.id
         FROM lab_results r
         JOIN lab_requests lr ON lr.id = r.lab_request_id
         JOIN patients p ON p.id = lr.patient_id
         ORDER BY r.id DESC
         LIMIT 25"
    )->fetchAll();
} catch (Throwable $e) {
    $recentResults = [];
}

$requiredTables = [
    'users', 'patients', 'lab_tests', 'reference_ranges', 'lab_requests',
    'request_tests', 'specimens', 'lab_results', 'result_values', 'ai_flags',
    'audit_logs', 'backups', 'system_settings',
];
$missingTables = array_values(array_filter($requiredTables, static fn($t) => !isset($tableCounts[$t])));

$pageTitle = 'Database — AI-LIS';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <div>
            <h1>Database control</h1>
            <p class="muted">Inspect the live LIS database that stores laboratory results. Passwords are never shown here.</p>
        </div>
        <div class="actions" style="margin:0">
            <?php if (($counts['all'] ?? 0) === 0): ?>
                <form method="post">
                    <input type="hidden" name="action" value="seed_demo">
                    <button class="btn btn-small" type="submit">Load demo results</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-small btn-secondary" href="<?= e(base_url('backup/index.php')) ?>">Run backup</a>
        </div>
    </div>
    <div class="db-meta">
        <div>
            <span class="label">Engine</span>
            <strong><?= e($info['engine_label']) ?></strong>
        </div>
        <div>
            <span class="label">Host</span>
            <strong><?= e($info['host'] !== '' ? $info['host'] : '—') ?></strong>
        </div>
        <div>
            <span class="label">Database</span>
            <strong><?= e($info['dbname'] !== '' ? $info['dbname'] : '—') ?></strong>
        </div>
        <div>
            <span class="label">User</span>
            <strong><?= e($info['username'] !== '' ? $info['username'] : '—') ?></strong>
        </div>
        <div>
            <span class="label">Port</span>
            <strong><?= (int) $info['port'] ?></strong>
        </div>
        <div>
            <span class="label">Where</span>
            <strong>
                <?php if ($info['is_render']): ?>
                    <span class="badge badge-ok">Render Postgres</span>
                <?php elseif ($info['hosted']): ?>
                    <span class="badge badge-ok">Hosted</span>
                <?php else: ?>
                    <span class="badge">Local XAMPP</span>
                <?php endif; ?>
            </strong>
        </div>
        <div>
            <span class="label">DATABASE_URL</span>
            <strong><?= $info['url_set'] ? 'set' : 'not set' ?></strong>
        </div>
        <div>
            <span class="label">SSL</span>
            <strong><?= e($info['sslmode'] ?: 'off') ?></strong>
        </div>
    </div>
    <?php if ($missingTables): ?>
        <div class="alert alert-error" style="margin-top:1rem">
            Missing tables: <?= e(implode(', ', $missingTables)) ?>.
            Open <a href="<?= e(base_url('install.php')) ?>">install.php</a> once, or check the Render DATABASE_URL.
        </div>
    <?php else: ?>
        <p class="workflow-hint">Schema is complete. Result rows live in <code>lab_results</code> + <code>result_values</code>.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Results in this database</h2>
    <div class="result-pipeline">
        <a class="result-pipe is-active" href="<?= e(base_url('results/index.php')) ?>">All <strong><?= (int) ($counts['all'] ?? 0) ?></strong></a>
        <?php foreach (['pending' => 'Pending', 'encoded' => 'Encoded', 'validated' => 'Validated', 'approved' => 'Approved', 'reported' => 'Reported', 'released' => 'Released'] as $st => $label): ?>
            <a class="result-pipe" href="<?= e(base_url('results/index.php?status=' . $st)) ?>"><?= e($label) ?> <strong><?= (int) ($counts[$st] ?? 0) ?></strong></a>
        <?php endforeach; ?>
    </div>
    <div class="table-scroll" style="margin-top:1rem">
        <table>
            <thead>
            <tr><th>Result</th><th>Request</th><th>Patient</th><th>Panel</th><th>Status</th><th>AI</th><th>Updated</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentResults as $r): ?>
                <tr>
                    <td><?= e($r['result_code']) ?></td>
                    <td><?= e($r['request_code']) ?></td>
                    <td><?= e($r['patient_name']) ?></td>
                    <td><?= e($r['panel_code']) ?></td>
                    <td><span class="badge"><?= e($r['status']) ?></span></td>
                    <td><?= !empty($r['ai_flagged']) ? '<span class="badge badge-warning">warning</span>' : '—' ?></td>
                    <td><?= e((string) ($r['updated_at'] ?? '—')) ?></td>
                    <td><a class="btn btn-small btn-secondary" href="<?= e(base_url('results/view.php?id=' . $r['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentResults): ?>
                <tr><td colspan="8">No result rows yet. Create a lab request to insert pending results into this database.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Table inventory</h2>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Table</th><th>Rows</th></tr></thead>
            <tbody>
            <?php foreach ($tableCounts as $name => $n): ?>
                <tr>
                    <td><code><?= e($name) ?></code></td>
                    <td><?= number_format($n) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tableCounts): ?>
                <tr><td colspan="2">Could not list tables. Confirm DATABASE_URL on Render.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card guide-doc">
    <h2>Guide: Results + Render database</h2>
    <ol class="guide-list">
        <li><strong>Create or open Postgres</strong> — Render Dashboard → <em>PostgreSQL</em> (or apply the Blueprint, which creates <code>ailab-db</code>).</li>
        <li><strong>Wire the web app</strong> — On <code>ailab-web</code> set <code>DATABASE_URL</code> to the <em>Internal Database URL</em> (same region). The Blueprint now copies this automatically via <code>fromDatabase</code>.</li>
        <li><strong>Install schema</strong> — Redeploy so <code>auto_install.php</code> creates <code>lab_results</code> and related tables. You can also open <code>/install.php</code> once.</li>
        <li><strong>Add a result</strong> — Patients → New request → pick tests. The app inserts a pending row into <code>lab_results</code>. Then open <strong>Results</strong> on the dashboard.</li>
        <li><strong>Encode and release</strong> — Results → pending → enter values → Save, validate &amp; run AI → Approve → Generate report → Release.</li>
        <li><strong>Inspect on Render</strong> — Postgres → <em>Connect</em> (psql / External URL for local tools) or this page for live counts. Use Backup before any destructive change.</li>
    </ol>
    <p class="muted">Full write-up: <code>docs/RENDER_DATABASE.md</code> in the repo. Internal URL is for Render services; External URL (TLS) is for your PC.</p>
    <div class="actions">
        <a class="btn" href="<?= e(base_url('results/index.php')) ?>">Open Results</a>
        <a class="btn btn-secondary" href="<?= e(base_url('requests/create.php')) ?>">Create request (adds results)</a>
        <a class="btn btn-secondary" href="https://dashboard.render.com/" target="_blank" rel="noopener">Render Dashboard</a>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
