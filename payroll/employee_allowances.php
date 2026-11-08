<?php
// File: payroll/employee_allowances.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';
requireLogin();
if (!canUpdateEmployee()) {
    redirectNoAccess();
}

$page_title = $LANG['allowances'];

$id = (int)($_GET['id'] ?? 0);
$emp = getEmployee($conn, $id);
if (!$emp) { header('Location: ' . payrollGateUrl('employees')); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = '<div class="alert alert-danger">' . htmlspecialchars($LANG['invalid_csrf']) . '</div>';
    } else {
        // Save all components
        $components = $_POST['components'] ?? [];
        foreach ($components as $key => $amount) {
            $amount = floatval($amount);
            $notes = trim($_POST['notes'][$key] ?? '');
            // Upsert
            $stmt = $conn->prepare("INSERT INTO employee_allowances (employee_id, component_key, amount, notes) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount), notes=VALUES(notes)");
            $stmt->bind_param('isds', $id, $key, $amount, $notes);
            $stmt->execute();
        }
        logActivity('employee_allowances', "Updated allowances for employee ID: $id");
        $message = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>' . ($LANG['save_success']) . '</div>';
    }
}

// Get all components
$components = $conn->query("SELECT * FROM employee_salary_components WHERE is_active = 1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

// Get employee's current allowances
$stmt = $conn->prepare("SELECT component_key, amount, notes FROM employee_allowances WHERE employee_id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$empAllowances = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
    $empAllowances[$a['component_key']] = $a;
}

include __DIR__ . '/../includes/header.php';
?>
<?= $message ?>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-cash-stack"></i><?= $LANG['allowances'] ?> — <?= safeOutput($emp['full_name']) ?></h2>
    <div class="page-actions">
        <a href="<?= url('payroll/employee_view.php?id=' . $id) ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <!-- Earnings -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header"><i class="bi bi-plus-circle me-2"></i><?= $LANG['earnings'] ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th><?= htmlspecialchars($LANG['component']) ?></th><th><?= htmlspecialchars($LANG['amount']) ?></th><th><?= htmlspecialchars($LANG['notes']) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($components as $c): if ($c['component_type'] !== 'earning' || $c['component_key'] === 'basic_salary') continue; ?>
                        <tr>
                            <td class="fw-semibold"><?= safeOutput($c['component_name']) ?><?= $c['is_taxable'] ? ' <span class="badge bg-warning text-dark"><?= htmlspecialchars($LANG['taxable']) ?></span>' : '' ?></td>
                            <td><input type="number" name="components[<?= $c['component_key'] ?>]" class="form-control form-control-sm" value="<?= $empAllowances[$c['component_key']]['amount'] ?? 0 ?>" min="0" step="1000" style="max-width:160px"></td>
                            <td><textarea name="notes[<?= $c['component_key'] ?>]" class="form-control form-control-sm" rows="1" placeholder="<?= htmlspecialchars($LANG['notes'], ENT_QUOTES, 'UTF-8') ?>" style="resize:vertical;min-height:calc(1.5em + .5rem + 2px)"><?= safeOutput($empAllowances[$c['component_key']]['notes'] ?? '') ?></textarea></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Deductions (Override) -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header"><i class="bi bi-dash-circle me-2"></i><?= $LANG['deductions'] ?></div>
        <div class="card-body">
            <p class="text-muted small mb-3">Note: BPJS and tax are auto-calculated from settings. Use below to set fixed overrides (0 = auto-calculate).</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th><?= htmlspecialchars($LANG['component']) ?></th><th><?= htmlspecialchars($LANG['fixed_amount_idr']) ?> <small class="text-muted">(0=auto)</small></th><th><?= htmlspecialchars($LANG['notes']) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($components as $c): if ($c['component_type'] !== 'deduction') continue; ?>
                        <tr>
                            <td class="fw-semibold"><?= safeOutput($c['component_name']) ?></td>
                            <td><input type="number" name="components[<?= $c['component_key'] ?>]" class="form-control form-control-sm" value="<?= $empAllowances[$c['component_key']]['amount'] ?? 0 ?>" min="0" step="1000" style="max-width:160px"></td>
                            <td><textarea name="notes[<?= $c['component_key'] ?>]" class="form-control form-control-sm" rows="1" placeholder="<?= htmlspecialchars($LANG['notes'], ENT_QUOTES, 'UTF-8') ?>" style="resize:vertical;min-height:calc(1.5em + .5rem + 2px)"><?= safeOutput($empAllowances[$c['component_key']]['notes'] ?? '') ?></textarea></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end pb-4">
        <a href="<?= url('payroll/employee_view.php?id=' . $id) ?>" class="btn btn-sm btn-secondary"><i class="bi bi-x-lg"></i> <?= $LANG['cancel'] ?></a>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> <?= $LANG['save'] ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>