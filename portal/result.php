<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$patient = require_patient();
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT r.*, lr.request_code, lr.requesting_physician,
            p.patient_code, p.sex, p.birth_date, p.first_name, p.last_name, p.middle_name
     FROM lab_results r
     JOIN lab_requests lr ON lr.id = r.lab_request_id
     JOIN patients p ON p.id = lr.patient_id
     WHERE r.id = ? AND lr.patient_id = ? AND r.status = 'released'"
);
$stmt->execute([$id, (int) $patient['id']]);
$result = $stmt->fetch();
if (!$result) {
    flash('error', 'That result is not available.');
    redirect('portal/results.php');
}

$values = db()->prepare(
    'SELECT rv.*, lt.test_code, lt.test_name, lt.unit, lt.is_numeric
     FROM result_values rv
     JOIN lab_tests lt ON lt.id = rv.lab_test_id
     WHERE rv.lab_result_id = ?
     ORDER BY lt.sort_order, lt.test_code'
);
$values->execute([$id]);
$valueRows = $values->fetchAll();

$pageTitle = 'Result ' . $result['result_code'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card" id="report-print">
    <h1>Laboratory result</h1>
    <p><strong><?= e(app_config('lab_name')) ?></strong></p>
    <hr>
    <p>
        <strong>Patient:</strong> <?= e($result['last_name'] . ', ' . $result['first_name']) ?><br>
        <strong>Patient ID:</strong> <?= e($result['patient_code']) ?> |
        <strong>Sex:</strong> <?= e($result['sex']) ?> |
        <strong>Age:</strong> <?= patient_age($result['birth_date']) ?><br>
        <strong>Request:</strong> <?= e($result['request_code']) ?> |
        <strong>Result:</strong> <?= e($result['result_code']) ?> |
        <strong>Panel:</strong> <?= e($result['panel_code']) ?>
    </p>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Test</th><th>Result</th><th>Unit</th><th>Flag</th></tr></thead>
        <tbody>
        <?php foreach ($valueRows as $v): ?>
            <?php
            $reportValue = ((int) ($v['is_numeric'] ?? 1) === 1)
                ? (string) ($v['numeric_value'] ?? '—')
                : (string) ($v['text_value'] ?? $v['numeric_value'] ?? '—');
            ?>
            <tr>
                <td><?= e($v['test_name'] . ' (' . $v['test_code'] . ')') ?></td>
                <td><?= e($reportValue) ?></td>
                <td><?= e($v['unit'] ?: '—') ?></td>
                <td><?php if ($v['is_critical']): ?>Critical<?php elseif ($v['is_out_of_range']): ?>Abnormal<?php else: ?>Normal<?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$valueRows): ?>
            <tr><td colspan="4">No values recorded.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p class="ai-disclaimer"><?= e(ai_medical_disclaimer()) ?></p>
    <p class="sub">This report was released by the laboratory. Clinical interpretation remains the responsibility of a licensed physician.</p>
</div>
<div class="actions">
    <button class="btn" type="button" onclick="window.print()">Print</button>
    <a class="btn btn-secondary" href="<?= e(base_url('portal/results.php')) ?>">Back to my results</a>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
