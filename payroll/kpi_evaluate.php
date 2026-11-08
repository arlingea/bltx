<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/kpi_functions.php';

if (!isLoggedIn()) { redirect(url('index.php')); exit; }
if (!_canAccess('kpi', 'can_access')) { redirectNoAccess(); exit; }

$_can_create = _canAccess('kpi', 'can_create');
$_can_update = _canAccess('kpi', 'can_update');

if (!kpi_tablesExist($conn)) {
    redirect(payrollGateUrl('kpi'));
    exit;
}

$flash = null;
$evalId = (int)($_GET['id'] ?? 0);
$periodId = (int)($_GET['period_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);

// Editing existing evaluation
$existingEval = null;
$existingScores = [];
if ($evalId > 0) {
    $st = $conn->prepare("SELECT * FROM kpi_evaluations WHERE id = ? LIMIT 1");
    $st->bind_param('i', $evalId);
    $st->execute();
    $existingEval = $st->get_result()->fetch_assoc();
    $st->close();
    if ($existingEval) {
        $employeeId = (int)$existingEval['employee_id'];
        $periodId   = (int)$existingEval['period_id'];
        foreach (kpi_getScores($conn, $evalId) as $s) {
            $existingScores[(int)$s['indicator_id']] = $s;
        }
    }
}

// Save submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kpi_csrfVerify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'msg' => $LANG['invalid_csrf']];
    } elseif (!$_can_create && !$_can_update) {
        $flash = ['type' => 'danger', 'msg' => $LANG['no_permission']];
    } else {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $periodId   = (int)($_POST['period_id'] ?? 0);
        $comments   = trim($_POST['comments'] ?? '');
        $status     = ($_POST['submit_action'] ?? 'draft') === 'submit' ? 'submitted' : 'draft';
        $scores     = $_POST['scores'] ?? [];

        if ($employeeId <= 0 || $periodId <= 0) {
            $flash = ['type' => 'danger', 'msg' => 'Employee and period are required.'];
        } else {
            $scoreInputs = [];
            foreach ($scores as $iid => $val) {
                $scoreInputs[(int)$iid] = ['score' => (float)$val];
            }
            $r = kpi_saveEvaluation($conn, $employeeId, $periodId, (int)$_SESSION['user_id'], $scoreInputs, $comments, $status);
            if ($r['success']) {
                logActivity('kpi_evaluation_save', "Saved KPI eval for employee $employeeId, period $periodId, score {$r['total_score']}");
                redirect(url('payroll/kpi_view.php?id=' . (int)$r['evaluation_id']));
                exit;
            } else {
                $flash = ['type' => 'danger', 'msg' => $r['message']];
            }
        }
    }
}

$page_title = $LANG['kpi_evaluate'];
include __DIR__ . '/../includes/header.php';

$periods    = kpi_getPeriods($conn);
$indicators = kpi_getIndicators($conn, true);
// Employees list
$employees  = [];
/* Tabel `employees` TIDAK punya kolom `nip` maupun `is_active` — dua-duanya
   membuat query ini melempar fatal error dan halaman KPI mati total.
   Kolom yang benar: `employee_code`, dan status aktif dibaca dari
   `employee_status` yang bertipe enum('active','inactive','resigned','terminated').
   Alias `nip` dipertahankan supaya baris tampilan di bawah tidak perlu diubah. */
$res = $conn->query(
    "SELECT id, full_name, employee_code AS nip, position_id, department_id
       FROM employees
      WHERE employee_status = 'active'
      ORDER BY full_name ASC"
);
if ($res) $employees = $res->fetch_all(MYSQLI_ASSOC);
$csrf = kpi_csrfToken();

// Group indicators by category
$indicatorsByCategory = [];
foreach ($indicators as $ind) {
    $indicatorsByCategory[$ind['category_name']][] = $ind;
}
?>
<div class="container-fluid">
    <div class="page-title-bar">
        <div>
            <h2 class="page-title-text"><i class="bi bi-clipboard-check"></i><?= htmlspecialchars($evalId ? ($LANG['kpi_edit_evaluation']) : ($LANG['kpi_new_evaluation'])) ?></h2>
            <div class="page-subtitle"><?= htmlspecialchars($LANG['kpi_evaluate_subtitle']) ?></div>
        </div>
        <div class="page-actions">
            <a href="<?= payrollGateUrl('kpi') . ($periodId ? '&period_id='.$periodId : '') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?></a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show"><?= htmlspecialchars($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (empty($indicators)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        <?= htmlspecialchars($LANG['kpi_no_indicators']) ?>
        <a href="<?= url('payroll/kpi_indicators.php') ?>" class="alert-link"><?= htmlspecialchars($LANG['kpi_manage_indicators']) ?></a>
    </div>
    <?php elseif (empty($periods)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        <?= htmlspecialchars($LANG['kpi_create_period_first']) ?>
        <a href="<?= url('payroll/kpi_periods.php') ?>" class="alert-link"><?= htmlspecialchars($LANG['kpi_manage_periods']) ?></a>
    </div>
    <?php else: ?>

    <form method="POST" id="kpiForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header"><div class="card-title"><i class="bi bi-person"></i> <?= htmlspecialchars($LANG['employee']) ?></div></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"><?= htmlspecialchars($LANG['employee']) ?> <span class="text-danger">*</span></label>
                            <select name="employee_id" class="form-select" required <?= $evalId ? 'disabled' : '' ?>>
                                <option value=""><?= htmlspecialchars($LANG['select']) ?></option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= (int)$emp['id'] ?>" <?= $emp['id'] == $employeeId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['full_name']) ?> · <?= htmlspecialchars($emp['nip'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($evalId): ?>
                                <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label"><?= htmlspecialchars($LANG['kpi_period']) ?> <span class="text-danger">*</span></label>
                            <select name="period_id" class="form-select" required <?= $evalId ? 'disabled' : '' ?>>
                                <option value=""><?= htmlspecialchars($LANG['select']) ?></option>
                                <?php foreach ($periods as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= $p['id'] == $periodId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($evalId): ?>
                                <input type="hidden" name="period_id" value="<?= (int)$periodId ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header"><div class="card-title"><i class="bi bi-graph-up"></i> <?= htmlspecialchars($LANG['kpi_total_score']) ?></div></div>
                    <div class="card-body d-flex flex-column align-items-center justify-content-center">
                        <div class="display-4 fw-bold" id="totalScoreDisplay">0.00</div>
                        <div class="text-muted small"><?= htmlspecialchars($LANG['kpi_weighted_score']) ?></div>
                        <div class="mt-2"><span class="badge fs-5 px-3 py-2" id="gradeBadge">—</span></div>
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($indicatorsByCategory as $catName => $catIndicators): ?>
        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-folder"></i> <?= htmlspecialchars($catName) ?></div>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40%"><?= htmlspecialchars($LANG['kpi_indicator']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($LANG['weight']) ?></th>
                            <th><?= htmlspecialchars($LANG['kpi_score']) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catIndicators as $ind):
                            $currentScore = $existingScores[$ind['id']]['score'] ?? '';
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($ind['name']) ?></div>
                                <?php if ($ind['description']): ?><small class="text-muted"><?= htmlspecialchars($ind['description']) ?></small><?php endif; ?>
                            </td>
                            <td class="text-center"><span class="badge bg-secondary"><?= number_format((float)$ind['weight'], 2) ?>%</span></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="range"
                                           name="scores[<?= (int)$ind['id'] ?>]"
                                           class="form-range kpi-score-input"
                                           min="0" max="100" step="1"
                                           value="<?= htmlspecialchars((string)$currentScore) ?>"
                                           data-weight="<?= (float)$ind['weight'] ?>"
                                           oninput="this.nextElementSibling.value=this.value; updateTotal();">
                                    <output class="text-end fw-bold" style="width:50px;"><?= htmlspecialchars((string)$currentScore) ?: '0' ?></output>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="card mb-3">
            <div class="card-header"><div class="card-title"><i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($LANG['kpi_comments']) ?></div></div>
            <div class="card-body">
                <textarea name="comments" class="form-control" rows="3" placeholder="<?= htmlspecialchars($LANG['kpi_comments_placeholder']) ?>"><?= htmlspecialchars($existingEval['comments'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="card">
            <div class="card-body d-flex justify-content-end gap-2">
                <button type="submit" name="submit_action" value="draft" class="btn btn-sm btn-secondary"><i class="bi bi-save"></i> <?= htmlspecialchars($LANG['save_as_draft']) ?></button>
                <button type="submit" name="submit_action" value="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle"></i> <?= htmlspecialchars($LANG['submit_evaluation']) ?></button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
(function() {
    function updateTotal() {
        var inputs = document.querySelectorAll('.kpi-score-input');
        var totalWeight = 0, weighted = 0;
        inputs.forEach(function(inp) {
            var w = parseFloat(inp.dataset.weight) || 0;
            var s = parseFloat(inp.value) || 0;
            totalWeight += w;
            weighted += s * w;
        });
        var total = totalWeight > 0 ? (weighted / totalWeight) : 0;
        var disp = document.getElementById('totalScoreDisplay');
        if (disp) disp.textContent = total.toFixed(2);

        var badge = document.getElementById('gradeBadge');
        if (badge) {
            var grade = 'E', cls = 'dark';
            if (total >= 90) { grade = 'A'; cls = 'success'; }
            else if (total >= 80) { grade = 'B'; cls = 'info'; }
            else if (total >= 70) { grade = 'C'; cls = 'warning'; }
            else if (total >= 60) { grade = 'D'; cls = 'danger'; }
            badge.textContent = grade;
            badge.className = 'badge fs-5 px-3 py-2 bg-' + cls;
        }
    }
    window.updateTotal = updateTotal;
    document.addEventListener('DOMContentLoaded', updateTotal);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
