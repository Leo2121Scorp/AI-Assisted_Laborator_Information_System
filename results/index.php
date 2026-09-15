<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('encode_results');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seed_demo') {
    $out = seed_demo_lab_cases(db());
    flash($out['ok'] ? 'success' : 'error', $out['message']);
    redirect('results/index.php');
}

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$aiOnly = isset($_GET['ai']) && $_GET['ai'] === '1';
$allowedStatus = ['pending', 'encoded', 'validated', 'approved', 'reported', 'released'];

$sql = "SELECT r.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name
        FROM lab_results r
        JOIN lab_requests lr ON lr.id = r.lab_request_id
        JOIN patients p ON p.id = lr.patient_id
        WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, $allowedStatus, true)) {
    $sql .= ' AND r.status = ?';
    $params[] = $status;
}
if ($aiOnly) {
    $sql .= ' AND r.ai_flagged = 1 AND r.status IN (\'validated\',\'approved\')';
}
if ($q !== '') {
    $sql .= ' AND (r.result_code LIKE ? OR lr.request_code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR r.panel_code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= ' ORDER BY ' . sql_order_by_list('r.status', ['validated', 'encoded', 'pending', 'approved', 'reported', 'released'])
    . ', r.updated_at DESC LIMIT 150';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = result_status_counts();
$pageTitle = 'Results — AI-LIS';
require __DIR__ . '/../includes/header.php';

$filters = [
    '' => 'All',
    'pending' => 'Pending encode',
    'encoded' => 'Encoded',
    'validated' => 'Awaiting review',
    'approved' => 'Approved',
    'reported' => 'Reported',
    'released' => 'Released',
];
$pipeline = [
    'pending' => 'Pending',
    'encoded' => 'Encoded',
    'validated' => 'Validated',
    'approved' => 'Approved',
    'reported' => 'Reported',
    'released' => 'Released',
];
?>
<div class="card">
    <div class="card-head">
        <div>
            <h1>Laboratory Results</h1>
            <p class="muted">Results are stored in Render Postgres (or local MySQL). Encode values, review rule-based + AI warnings, then approve for release.</p>
        </div>
        <?php if (can('view_database')): ?>
            <a class="btn btn-small btn-secondary" href="<?= e(base_url('admin/database.php')) ?>">Database control</a>
        <?php endif; ?>
    </div>
    <p class="workflow-hint">Pipeline: pending → encode → validate (rules + AI) → approve → generate report → release. New result rows appear automatically when a lab request is created.</p>
    <div class="result-pipeline" aria-label="Result counts by status">
        <a class="result-pipe<?= $status === '' && !$aiOnly ? ' is-active' : '' ?>" href="<?= e(base_url('results/index.php')) ?>">
            All <strong><?= (int) ($counts['all'] ?? 0) ?></strong>
        </a>
        <?php foreach ($pipeline as $value => $label): ?>
            <?php
            $href = 'results/index.php?status=' . rawurlencode($value);
            if ($q !== '') {
                $href .= '&q=' . rawurlencode($q);
            }
            ?>
            <a class="result-pipe<?= !$aiOnly && $status === $value ? ' is-active' : '' ?>" href="<?= e(base_url($href)) ?>">
                <?= e($label) ?> <strong><?= (int) ($counts[$value] ?? 0) ?></strong>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search result, request, patient, panel…">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php if ($aiOnly): ?><input type="hidden" name="ai" value="1"><?php endif; ?>
        <button class="btn" type="submit">Search</button>
        <?php if ($q !== '' || $status !== '' || $aiOnly): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('results/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filter-chips">
        <?php foreach ($filters as $value => $label): ?>
            <?php
            $href = 'results/index.php';
            $qs = [];
            if ($q !== '') {
                $qs['q'] = $q;
            }
            if ($value !== '') {
                $qs['status'] = $value;
            }
            if ($qs) {
                $href .= '?' . http_build_query($qs);
            }
            ?>
            <a class="chip<?= !$aiOnly && $status === $value ? ' is-active' : '' ?>" href="<?= e(base_url($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php
        $aiHref = 'results/index.php?ai=1';
        if ($q !== '') {
            $aiHref .= '&q=' . rawurlencode($q);
        }
        ?>
        <a class="chip<?= $aiOnly ? ' is-active' : '' ?>" href="<?= e(base_url($aiHref)) ?>">AI warnings</a>
    </div>
</div>
<div class="card">
    <?php if (!$rows): ?>
        <div class="empty-state">
            <?php if ($aiOnly && ($counts['all'] ?? 0) > 0): ?>
                <p>No AI warnings in the queue. Other results are already in the database.</p>
                <a class="btn" href="<?= e(base_url('results/index.php')) ?>">View all results</a>
            <?php elseif ($status !== '' || $q !== ''): ?>
                <p>No results match these filters.</p>
                <a class="btn" href="<?= e(base_url('results/index.php')) ?>">View all results</a>
            <?php else: ?>
                <p>No result rows yet. Load the demo cases (pending, AI warning, approved, released) or create a lab request.</p>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="seed_demo">
                    <button class="btn" type="submit">Load demo results</button>
                </form>
                <a class="btn btn-secondary" href="<?= e(base_url('requests/create.php')) ?>">New request</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Result</th><th>Request</th><th>Patient</th><th>Panel</th><th>Status</th><th>AI</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['result_code']) ?></td>
                    <td><?= e($r['request_code']) ?></td>
                    <td><?= e($r['patient_name']) ?></td>
                    <td><?= e($r['panel_code']) ?></td>
                    <td><span class="badge<?= $r['status'] === 'validated' ? ' badge-warning' : ($r['status'] === 'released' ? ' badge-ok' : '') ?>"><?= e($r['status']) ?></span></td>
                    <td><?= $r['ai_flagged'] ? '<span class="badge badge-warning">warning</span>' : '—' ?></td>
                    <td><a class="btn btn-small btn-secondary" href="<?= e(base_url('results/view.php?id=' . $r['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
