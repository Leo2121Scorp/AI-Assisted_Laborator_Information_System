<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('view_reports');

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if (!is_iso_date($from)) {
    $from = '';
}
if (!is_iso_date($to)) {
    $to = '';
}
$allowedStatus = ['approved', 'reported', 'released'];

$reportDate = 'COALESCE(r.released_at, r.reported_at, r.approved_at, r.created_at)';
$sql = "SELECT r.*, lr.request_code, CONCAT(p.last_name, ', ', p.first_name) AS patient_name,
               {$reportDate} AS report_date
        FROM lab_results r
        JOIN lab_requests lr ON lr.id = r.lab_request_id
        JOIN patients p ON p.id = lr.patient_id
        WHERE r.status IN ('reported','released','approved')";
$params = [];

if ($status !== '' && in_array($status, $allowedStatus, true)) {
    $sql .= ' AND r.status = ?';
    $params[] = $status;
}
if ($from !== '') {
    $sql .= ' AND ' . sql_as_date($reportDate) . ' >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $sql .= ' AND ' . sql_as_date($reportDate) . ' <= ?';
    $params[] = $to;
}
if ($q !== '') {
    $sql .= ' AND (r.result_code LIKE ? OR lr.request_code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR r.panel_code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= ' ORDER BY ' . $reportDate . ' DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Reports — AI-LIS';
require __DIR__ . '/../includes/header.php';

$filters = [
    '' => 'All ready',
    'approved' => 'Approved',
    'reported' => 'Reported',
    'released' => 'Released',
];
?>
<div class="card no-print">
    <div class="card-head">
        <div>
            <h1>Laboratory Reports</h1>
            <p class="muted">View and print approved, generated, or released reports. Filter by date, then print the list.</p>
        </div>
    </div>
    <form method="get" class="toolbar-form" role="search">
        <input name="q" value="<?= e($q) ?>" placeholder="Search patient, request, or result…">
        <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
        <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <button class="btn" type="submit">Search</button>
        <button class="btn btn-secondary" type="button" onclick="window.print()">Print this list</button>
        <?php if ($q !== '' || $status !== '' || $from !== '' || $to !== ''): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('reports/index.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filter-chips">
        <?php foreach ($filters as $value => $label): ?>
            <?php
            $href = 'reports/index.php';
            $qs = [];
            if ($q !== '') {
                $qs['q'] = $q;
            }
            if ($from !== '') {
                $qs['from'] = $from;
            }
            if ($to !== '') {
                $qs['to'] = $to;
            }
            if ($value !== '') {
                $qs['status'] = $value;
            }
            if ($qs) {
                $href .= '?' . http_build_query($qs);
            }
            ?>
            <a class="chip<?= $status === $value ? ' is-active' : '' ?>" href="<?= e(base_url($href)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<div class="card">
    <div class="print-only">
        <h1>Laboratory reports</h1>
        <p><?= e(app_config('lab_name')) ?></p>
        <p>
            <?php if ($from !== '' || $to !== ''): ?>
                Date: <?= e($from !== '' ? $from : 'any') ?> to <?= e($to !== '' ? $to : 'any') ?>
            <?php else: ?>
                All dates in this list
            <?php endif; ?>
            <?php if ($status !== ''): ?> · Status: <?= e($status) ?><?php endif; ?>
        </p>
    </div>
    <?php if (!$rows): ?>
        <div class="empty-state">
            <p><?= ($q !== '' || $status !== '' || $from !== '' || $to !== '') ? 'No reports match these filters.' : 'No reports ready yet. Reports appear after MedTech approves results.' ?></p>
            <?php if (can('encode_results')): ?>
                <a class="btn" href="<?= e(base_url('results/index.php?status=validated')) ?>">Review results</a>
            <?php else: ?>
                <a class="btn btn-secondary" href="<?= e(base_url('requests/index.php')) ?>">View requests</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>Result</th><th>Request</th><th>Patient</th><th>Panel</th><th>Status</th><th>Date</th><th class="no-print"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['result_code']) ?></td>
                    <td><?= e($r['request_code']) ?></td>
                    <td><?= e($r['patient_name']) ?></td>
                    <td><?= e($r['panel_code']) ?></td>
                    <td><span class="badge<?= $r['status'] === 'released' ? ' badge-ok' : '' ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['report_date']) ?></td>
                    <td class="no-print">
                        <?php if (in_array($r['status'], ['reported', 'released', 'approved'], true)): ?>
                            <a class="btn btn-small btn-secondary" href="<?= e(base_url('reports/view.php?id=' . $r['id'])) ?>">Open</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
