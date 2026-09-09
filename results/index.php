<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('encode_results');

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

$pageTitle = 'Results — AI-LIS';
require __DIR__ . '/../includes/header.php';

$filters = [
    '' => 'All',
    'pending' => 'Pending encode',
    'validated' => 'Awaiting review',
    'approved' => 'Approved',
    'released' => 'Released',
];
?>
<div class="card">
    <div class="card-head">
        <div>
            <h1>Laboratory Results</h1>
            <p class="muted">Encode values, review rule-based + AI warnings, then approve for release.</p>
        </div>
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
            <p><?= ($q !== '' || $status !== '' || $aiOnly) ? 'No results match these filters.' : 'No results in the queue yet. Results appear when a lab request is created.' ?></p>
            <a class="btn btn-secondary" href="<?= e(base_url('requests/index.php')) ?>">View requests</a>
        </div>
    <?php else: ?>
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
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
