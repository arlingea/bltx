<?php
// File: payroll/payroll_calculate.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

requirePayrollPeriodsAccess();
if (!canUpdatePayrollPeriod()) { redirectNoAccess(); }

$page_title = $LANG['calculate_payroll'];

$period_id = (int)($_GET['period_id'] ?? 0);
if (!$period_id) { header('Location: ' . payrollGateUrl('payroll_periods')); exit; }

$stmt = $conn->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$stmt->bind_param('i', $period_id);
$stmt->execute();
$period = $stmt->get_result()->fetch_assoc();
if (!$period) { header('Location: ' . payrollGateUrl('payroll_periods')); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$message = '';

// Handle calculate all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    if ($_POST['action'] === 'calculate_all') {
        $employees = getActiveEmployees($conn);
        $count = 0;
        foreach ($employees as $emp) {
            $data = calculatePayroll($conn, $emp['id'], $period_id);
            if ($data) {
                savePayroll($conn, $data, $_SESSION['user_id']);
                $count++;
            }
        }
        logActivity('payroll_calculate', "Calculated payroll for period ID: $period_id, $count employees");
        $message = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Payroll calculated for ' . $count . ' employees.</div>';
    }

    if ($_POST['action'] === 'calculate_one') {
        $empId = (int)$_POST['employee_id'];
        $data = calculatePayroll($conn, $empId, $period_id);
        if ($data) {
            $prId = savePayroll($conn, $data, $_SESSION['user_id']);
            $message = '<div class="alert alert-success">Payroll calculated for employee ID ' . $empId . '</div>';
        } else {
            $message = '<div class="alert alert-danger">' . htmlspecialchars($LANG['failed_to_calculate']) . '</div>';
        }
    }

    if ($_POST['action'] === 'mark_paid') {
        if (!canUpdatePayrollPeriod()) {
            $message = '<div class="alert alert-danger">' . ($LANG['no_permission']) . '</div>';
        } else {
            $st1 = $conn->prepare("UPDATE payroll SET status='paid', payment_date=NOW() WHERE period_id = ?");
            $st1->bind_param('i', $period_id); $st1->execute();
            $st2 = $conn->prepare("UPDATE payroll_periods SET status='paid' WHERE id = ?");
            $st2->bind_param('i', $period_id); $st2->execute();
            logActivity('payroll_paid', "Marked payroll period $period_id as paid");
            $message = '<div class="alert alert-success">' . ($LANG['payroll_marked_paid']) . '</div>';
        }
    }

    if ($_POST['action'] === 'mark_approved') {
        if (!canUpdatePayrollPeriod()) {
            $message = '<div class="alert alert-danger">' . ($LANG['no_permission']) . '</div>';
        } else {
            $userId = (int)$_SESSION['user_id'];
            /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
               tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
            $stA = $conn->prepare(
                "UPDATE payroll
                    SET status='approved',
                        approved_by=?,
                        approved_by_name=(SELECT full_name FROM users WHERE id = ?),
                        approved_at=NOW()
                  WHERE period_id = ? AND status='draft'"
            );
            $stA->bind_param('iii', $userId, $userId, $period_id); $stA->execute();
            $stB = $conn->prepare("UPDATE payroll_periods SET status='calculated' WHERE id = ?");
            $stB->bind_param('i', $period_id); $stB->execute();
            logActivity('payroll_approve_all', "Approved all payroll for period ID: $period_id");
            $message = '<div class="alert alert-success">' . ($LANG['payroll_approved']) . '</div>';
        }
    }
}

// Get payroll list for this period
$stPay = $conn->prepare("SELECT pr.*, e.full_name, e.employee_code, e.position, e.department
    FROM payroll pr JOIN employees e ON pr.employee_id = e.id
    WHERE pr.period_id = ? ORDER BY e.full_name ASC");
$stPay->bind_param('i', $period_id); $stPay->execute();
$payrolls = $stPay->get_result()->fetch_all(MYSQLI_ASSOC); $stPay->close();

$totalNet = array_sum(array_column($payrolls, 'net_salary'));
$totalGross = array_sum(array_column($payrolls, 'gross_salary'));

$employees = getActiveEmployees($conn);
include __DIR__ . '/../includes/header.php';
?>
<?= $message ?>

<div class="page-title-bar">
    <div>
        <h2 class="page-title-text"><i class="bi bi-calculator"></i><?= htmlspecialchars($LANG['calculate_payroll']) ?></h2>
        <div class="page-subtitle"><?= safeOutput($period['period_name']) ?> | <?= date('d M Y', strtotime($period['start_date'])) ?> - <?= date('d M Y', strtotime($period['end_date'])) ?></div>
    </div>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('payroll_list') . '&period_id=' . $period_id ?>" class="btn btn-sm btn-info"><i class="bi bi-list-ul"></i> <?= htmlspecialchars($LANG['view_slips']) ?></a>
        <a href="<?= payrollGateUrl('payroll_periods') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?></a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small"><?= htmlspecialchars($LANG['total_employees']) ?></div><div class="fw-bold fs-4"><?= count($payrolls) ?></div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small"><?= htmlspecialchars($LANG['total_gross_salary']) ?></div><div class="fw-bold"><?= formatCurrency($totalGross) ?></div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small"><?= htmlspecialchars($LANG['total_net_salary']) ?></div><div class="fw-bold text-success"><?= formatCurrency($totalNet) ?></div></div></div>
    </div>
</div>

<!-- Action Buttons -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap gap-2">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="calculate_all">
            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Calculate payroll for ALL active employees?')">
                <i class="bi bi-calculator me-1"></i> <?= htmlspecialchars($LANG['calculate_all_employees']) ?>
            </button>
        </form>
        <?php if (!empty($payrolls) && $period['status'] === 'draft'): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="mark_approved">
            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Approve all payroll?')">
                <i class="bi bi-check-all me-1"></i> <?= htmlspecialchars($LANG['approve_all']) ?>
            </button>
        </form>
        <?php endif; ?>
        <?php if ($period['status'] === 'calculated'): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="mark_paid">
            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Mark all as PAID?')">
                <i class="bi bi-cash me-1"></i> <?= htmlspecialchars($LANG['mark_all_as_paid']) ?>
            </button>
        </form>
        <?php endif; ?>
        <span class="badge bg-<?= ['draft'=>'secondary','calculated'=>'info','paid'=>'success','cancelled'=>'danger'][$period['status']] ?> align-self-center fs-6">
            <?= ucfirst($period['status']) ?>
        </span>
    </div>
</div>

<!-- Payroll Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th><?= htmlspecialchars($LANG['employee']) ?></th>
                        <th><?= htmlspecialchars($LANG['gross_salary']) ?></th>
                        <th><?= htmlspecialchars($LANG['total_deduction']) ?></th>
                        <th><?= htmlspecialchars($LANG['net_salary']) ?></th>
                        <th><?= htmlspecialchars($LANG['attendance']) ?></th>
                        <th><?= htmlspecialchars($LANG['status']) ?></th>
                        <th><?= htmlspecialchars($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($payrolls)): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No payroll data. Click "Calculate All" to generate.</td></tr>
                <?php else: $i=1; foreach ($payrolls as $pr):
                    $psc = ['draft'=>'secondary','approved'=>'info','paid'=>'success'][$pr['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td>
                            <div class="fw-semibold"><?= safeOutput($pr['full_name']) ?></div>
                            <small class="text-muted"><?= safeOutput($pr['employee_code']) ?> | <?= safeOutput($pr['position']??'') ?></small>
                        </td>
                        <td><?= formatCurrency($pr['gross_salary']) ?></td>
                        <td class="text-danger">-<?= formatCurrency($pr['total_deduction']) ?></td>
                        <td class="fw-bold text-success"><?= formatCurrency($pr['net_salary']) ?></td>
                        <td>
                            <small>
                                <span class="text-success"><?= $pr['present_days'] ?>P</span> /
                                <span class="text-danger"><?= $pr['absent_days'] ?>A</span> /
                                <span class="text-muted"><?= $pr['working_days'] ?>W</span>
                            </small>
                        </td>
                        <td><span class="badge bg-<?= $psc ?>"><?= ucfirst($pr['status']) ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= url('payroll/payroll_slip.php?id=' . $pr['id']) ?>" class="btn btn-sm btn-primary" title="<?= htmlspecialchars($LANG['view_slip'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-receipt"></i></a>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="calculate_one">
                                    <input type="hidden" name="employee_id" value="<?= $pr['employee_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning" title="<?= htmlspecialchars($LANG['recalculate'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-arrow-clockwise"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($payrolls)): ?>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="2" class="fw-bold"><?= htmlspecialchars($LANG['total']) ?> (<?= count($payrolls) ?> <?= htmlspecialchars($LANG['employees']) ?>)</td>
                        <td class="fw-bold"><?= formatCurrency($totalGross) ?></td>
                        <td class="fw-bold text-danger">-<?= formatCurrency(array_sum(array_column($payrolls,'total_deduction'))) ?></td>
                        <td class="fw-bold text-success"><?= formatCurrency($totalNet) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>