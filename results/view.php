<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('encode_results');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT r.*, lr.request_code, lr.id AS request_id, p.sex, p.birth_date,
            CONCAT(p.last_name, ', ', p.first_name) AS patient_name, p.patient_code
     FROM lab_results r
     JOIN lab_requests lr ON lr.id = r.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     WHERE r.id = ?"
);
$stmt->execute([$id]);
$result = $stmt->fetch();
if (!$result) {
    flash('error', 'Result not found.');
    redirect('results/index.php');
}

// Tests for this panel on this request
$testsStmt = db()->prepare(
    'SELECT lt.* FROM request_tests rt
     JOIN lab_tests lt ON lt.id = rt.lab_test_id
     WHERE rt.lab_request_id = ? AND lt.panel_code = ?
     ORDER BY lt.test_code'
);
$testsStmt->execute([$result['lab_request_id'], $result['panel_code']]);
$tests = $testsStmt->fetchAll();

$valuesStmt = db()->prepare('SELECT * FROM result_values WHERE lab_result_id = ?');
$valuesStmt->execute([$id]);
$existing = [];
foreach ($valuesStmt->fetchAll() as $v) {
    $existing[(int) $v['lab_test_id']] = $v;
}

$aiStmt = db()->prepare('SELECT * FROM ai_flags WHERE lab_result_id = ? ORDER BY id DESC LIMIT 1');
$aiStmt->execute([$id]);
$aiFlag = $aiStmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'encode') {
        $inputs = $_POST['values'] ?? [];
        $out = encode_and_validate_result($id, $inputs);
        if ($out['ok']) {
            $msg = $out['message'];
            if (!empty($out['ai_flagged'])) {
                $msg .= ' AI warning present — review carefully before approval.';
            }
            flash(!empty($out['ai_flagged']) ? 'warning' : 'success', $msg);
        } else {
            flash('error', $out['message'] . (!empty($out['warnings']) ? ' ' . implode(' ', $out['warnings']) : ''));
        }
        redirect('results/view.php?id=' . $id);
    }
    if ($action === 'approve') {
        $out = approve_result($id);
        flash($out['ok'] ? 'success' : 'error', $out['message']);
        redirect('results/view.php?id=' . $id);
    }
    if ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            flash('error', 'Rejection reason is required.');
        } else {
            $out = reject_result($id, $reason);
            flash($out['ok'] ? 'success' : 'error', $out['message']);
        }
        redirect('results/view.php?id=' . $id);
    }
    if ($action === 'report') {
        $out = mark_reported($id);
        flash($out['ok'] ? 'success' : 'error', $out['message']);
        if ($out['ok']) {
            redirect('reports/view.php?id=' . $id);
        }
        redirect('results/view.php?id=' . $id);
    }
    if ($action === 'release') {
        $out = release_result($id);
        flash($out['ok'] ? 'success' : 'error', $out['message']);
        redirect('results/view.php?id=' . $id);
    }
}

$editable = in_array($result['status'], ['pending', 'encoded', 'validated'], true);
$pageTitle = 'Result ' . $result['result_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Result <?= e($result['result_code']) ?></h1>
    <p>
        Patient: <?= e($result['patient_name']) ?> (<?= e($result['patient_code']) ?>) —
        <?= e($result['sex']) ?> / <?= patient_age($result['birth_date']) ?>y
    </p>
    <p>Request: <a href="<?= e(base_url('requests/view.php?id=' . $result['request_id'])) ?>"><?= e($result['request_code']) ?></a></p>
    <p>Panel: <?= e($result['panel_code']) ?> | Status: <span class="badge"><?= e($result['status']) ?></span></p>
</div>

<?php if ($result['rule_warnings']): ?>
<div class="warning-box">
    <strong>Rule-based validation notes</strong>
    <pre style="white-space:pre-wrap;margin:0.4rem 0 0"><?= e($result['rule_warnings']) ?></pre>
</div>
<?php endif; ?>

<?php if ($aiFlag && ($aiFlag['is_anomaly'] || $result['ai_flagged'])): ?>
<div class="warning-box">
    <strong>AI Warning (Isolation Forest)</strong>
    <p><?= e($aiFlag['warning_message'] ?: 'Anomaly flagged.') ?></p>
    <p>Score: <?= e((string) $aiFlag['score']) ?> | Model: <?= e($aiFlag['model_version'] ?: '—') ?></p>
</div>
<?php endif; ?>

<?php if ($result['rejection_reason']): ?>
<div class="alert alert-error">Previous rejection: <?= e($result['rejection_reason']) ?></div>
<?php endif; ?>

<div class="card">
    <h2><?= $editable ? 'Encode results' : 'Encoded values' ?></h2>
    <form method="post">
        <input type="hidden" name="action" value="encode">
        <table>
            <thead><tr><th>Test</th><th>Unit</th><th>Value</th><th>Flags</th></tr></thead>
            <tbody>
            <?php foreach ($tests as $t): ?>
                <?php $ex = $existing[(int)$t['id']] ?? null; ?>
                <tr>
                    <td><?= e($t['test_code'] . ' — ' . $t['test_name']) ?></td>
                    <td><?= e($t['unit']) ?></td>
                    <td>
                        <?php if ($editable): ?>
                            <input name="values[<?= (int)$t['id'] ?>]"
                                   value="<?= e($ex['numeric_value'] ?? '') ?>"
                                   inputmode="decimal">
                        <?php else: ?>
                            <?= e((string)($ex['numeric_value'] ?? '—')) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ex && $ex['is_critical']): ?><span class="badge badge-danger">critical</span><?php endif; ?>
                        <?php if ($ex && $ex['is_out_of_range']): ?><span class="badge badge-warning">OOR</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($editable): ?>
            <div class="actions">
                <button class="btn" type="submit">Save, validate &amp; run AI</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2>Medical technologist review</h2>
    <div class="actions">
        <?php if ($result['status'] === 'validated' && can('approve_results')): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="approve">
                <button class="btn" type="submit" data-confirm="Approve this result?">Approve result</button>
            </form>
            <form method="post" style="display:inline;display:flex;gap:0.5rem;align-items:end">
                <input type="hidden" name="action" value="reject">
                <input name="rejection_reason" placeholder="Rejection reason" required>
                <button class="btn btn-danger" type="submit">Reject / re-encode</button>
            </form>
        <?php endif; ?>
        <?php if ($result['status'] === 'approved' && can('release_reports')): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="report">
                <button class="btn btn-amber" type="submit">Generate report</button>
            </form>
        <?php endif; ?>
        <?php if ($result['status'] === 'reported' && can('release_reports')): ?>
            <a class="btn btn-secondary" href="<?= e(base_url('reports/view.php?id=' . $id)) ?>">View report</a>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="release">
                <button class="btn" type="submit" data-confirm="Release this result to the patient/clinic?">Release result</button>
            </form>
        <?php endif; ?>
        <?php if ($result['status'] === 'released'): ?>
            <a class="btn" href="<?= e(base_url('reports/view.php?id=' . $id)) ?>">View released report</a>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
