<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/kpi_functions.php';

if (!isLoggedIn()) { redirect(url('index.php')); exit; }
if (!_canAccess('kpi', 'can_access')) { redirectNoAccess(); exit; }

$_can_update = _canAccess('kpi', 'can_update');

if (!kpi_tablesExist($conn)) {
    redirect(payrollGateUrl('kpi'));
    exit;
}

$evalId = (int)($_GET['id'] ?? 0);
if ($evalId <= 0) { redirect(payrollGateUrl('kpi')); exit; }

// Fetch evaluation with related info
$st = $conn->prepare("
    SELECT ev.*, e.full_name AS employee_name, e.employee_code AS nip,
           p.name AS position_name, d.name AS department_name,
           per.name AS period_name, per.start_date AS period_start, per.end_date AS period_end,
           ev.evaluator_name
    FROM kpi_evaluations ev
    JOIN employees e ON e.id = ev.employee_id
    LEFT JOIN positions p ON p.id = e.position_id
    LEFT JOIN departments d ON d.id = e.department_id
    JOIN kpi_periods per ON per.id = ev.period_id
    WHERE ev.id = ?
");
$st->bind_param('i', $evalId);
$st->execute();
$eval = $st->get_result()->fetch_assoc();
$st->close();

if (!$eval) { redirect(payrollGateUrl('kpi')); exit; }

$scores = kpi_getScores($conn, $evalId);

// Group scores by category
$scoresByCategory = [];
foreach ($scores as $s) {
    $scoresByCategory[$s['category_name']][] = $s;
}

$gradeColor  = ['A'=>'success','B'=>'info','C'=>'warning','D'=>'danger','E'=>'dark'][$eval['grade'] ?? 'C'] ?? 'secondary';
$statusColor = ['draft'=>'secondary','submitted'=>'info','approved'=>'success','rejected'=>'danger'][$eval['status']] ?? 'secondary';

$page_title = ($LANG['kpi_view']) . ' — ' . $eval['employee_name'];
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-title-bar">
        <div>
            <h2 class="page-title-text"><i class="bi bi-graph-up"></i><?= htmlspecialchars($LANG['kpi_view']) ?></h2>
            <div class="page-subtitle"><?= htmlspecialchars($eval['employee_name']) ?> · <?= htmlspecialchars($eval['period_name']) ?></div>
        </div>
        <div class="page-actions">
            <a href="<?= payrollGateUrl('kpi') . '&period_id=' . (int$eval['period_id']) ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?></a>
            <?php if ($_can_update && $eval['status'] !== 'approved'): ?>
            <a href="<?= url('payroll/kpi_evaluate.php?id=' . $evalId) ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> <?= htmlspecialchars($LANG['edit']) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-header"><div class="card-title"><i class="bi bi-person-vcard"></i> <?= htmlspecialchars($LANG['employee_info']) ?></div></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4"><?= htmlspecialchars($LANG['employee']) ?></dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($eval['employee_name']) ?> <small class="text-muted">(<?= htmlspecialchars($eval['nip'] ?? '—') ?>)</small></dd>

                        <dt class="col-sm-4"><?= htmlspecialchars($LANG['position']) ?></dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($eval['position_name'] ?? '—') ?></dd>

                        <dt class="col-sm-4"><?= htmlspecialchars($LANG['department']) ?></dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($eval['department_name'] ?? '—') ?></dd>

                        <dt class="col-sm-4"><?= htmlspecialchars($LANG['kpi_period']) ?></dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($eval['period_name']) ?> · <?= date('d M Y', strtotime($eval['period_start'])) ?> — <?= date('d M Y', strtotime($eval['period_end'])) ?></dd>

                        <dt class="col-sm-4"><?= htmlspecialchars($LANG['kpi_evaluator']) ?></dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($eval['evaluator_name'] ?? '—') ?></dd>

                        <dt class="col-sm-4"><?= htmlspecialchars($LANG['status']) ?></dt>
                        <dd class="col-sm-8"><span class="badge bg-<?= $statusColor ?>"><?= htmlspecialchars(ucfirst($eval['status'])) ?></span></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 text-center">
                <div class="card-header"><div class="card-title justify-content-center"><i class="bi bi-trophy"></i> <?= htmlspecialchars($LANG['kpi_final_score']) ?></div></div>
                <div class="card-body d-flex flex-column align-items-center justify-content-center">
                    <div class="display-2 fw-bold"><?= number_format((float)$eval['total_score'], 2) ?></div>
                    <div class="text-muted small mb-2"><?= htmlspecialchars($LANG['kpi_out_of_100']) ?></div>
                    <span class="badge bg-<?= $gradeColor ?> fs-3 px-4 py-2"><?= htmlspecialchars($eval['grade'] ?? '—') ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($scoresByCategory as $catName => $catScores):
        $catTotal = array_sum(array_column($catScores, 'score'));
        $catAvg   = count($catScores) > 0 ? $catTotal / count($catScores) : 0;
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <div class="card-title"><i class="bi bi-folder"></i> <?= htmlspecialchars($catName) ?></div>
            <span class="badge bg-secondary"><?= htmlspecialchars($LANG['kpi_avg']) ?>: <?= number_format($catAvg, 2) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:40%"><?= htmlspecialchars($LANG['kpi_indicator']) ?></th>
                        <th class="text-center"><?= htmlspecialchars($LANG['weight']) ?></th>
                        <th><?= htmlspecialchars($LANG['kpi_score']) ?></th>
                        <th><?= htmlspecialchars($LANG['notes']) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catScores as $s):
                        $score = (float)$s['score'];
                        $barColor = $score >= 80 ? 'success' : ($score >= 60 ? 'info' : ($score >= 40 ? 'warning' : 'danger'));
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($s['indicator_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($s['scoring_type']) ?></small>
                        </td>
                        <td class="text-center"><span class="badge bg-secondary"><?= number_format((float)$s['weight'], 2) ?>%</span></td>
                        <td style="min-width:200px;">
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:8px;">
                                    <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= $score ?>%;"></div>
                                </div>
                                <span class="fw-bold" style="width:48px;text-align:right;"><?= number_format($score, 0) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($s['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($eval['comments'])): ?>
    <div class="card mb-3">
        <div class="card-header"><div class="card-title"><i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($LANG['kpi_comments']) ?></div></div>
        <div class="card-body"><p class="mb-0"><?= nl2br(htmlspecialchars($eval['comments'])) ?></p></div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
