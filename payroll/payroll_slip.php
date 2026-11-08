<?php
// File: payroll/payroll_slip.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';
requirePayrollListAccess();

$page_title = $LANG['payslip'];

$id = (int)($_GET['id'] ?? 0);
$detail = getPayrollDetail($conn, $id);
if (!$detail) { header('Location: ' . payrollGateUrl('payroll_list')); exit; }
// Flatten nested getPayrollDetail result into a single $data array for the view
$data = array_merge(
    $detail['payroll']   ?? [],
    $detail['employee']  ?? [],
    ['approved_by_name' => null]
);
// Pull approved_by_name from users table if available
if (!empty($data['approved_by'])) {
    $stAp = $conn->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
    $stAp->bind_param('i', $data['approved_by']); $stAp->execute();
    $apRow = $stAp->get_result()->fetch_assoc(); $stAp->close();
    $data['approved_by_name'] = $apRow['full_name'] ?? null;
}
// slip_sent flag (check if email was sent previously — stored in payroll.notes or a flag)
$data['slip_sent'] = false;

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Handle send email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_payroll') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { die('Invalid CSRF'); }
    if (!canApprovePayroll()) { die($LANG['no_permission']); }
    $uid = (int)$_SESSION['user_id'];
    /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
       tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
    $stA = $conn->prepare(
        "UPDATE payroll
            SET status='approved',
                approved_by=?,
                approved_by_name=(SELECT full_name FROM users WHERE id = ?)
          WHERE id=? AND status='draft'"
    );
    $stA->bind_param('iii', $uid, $uid, $id); $stA->execute();
    logActivity('payroll_approve', "Approved payroll ID: $id");
    header('Location: '.url('payroll/payroll_slip.php?id='.$id.'&approved=1')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    $result = sendPayslipEmail($conn, $id);
    if ($result['success']) {
        $emailMsg = '<div class="alert alert-success"><i class="bi bi-envelope-check me-2"></i>' . safeOutput($result['message']) . '</div>';
    } else {
        $emailMsg = '<div class="alert alert-danger">' . safeOutput($result['message']) . '</div>';
    }
}

$fmt = fn($n) => formatCurrency($n);
$siteName = getSetting('site_name', APP_NAME);

include __DIR__ . '/../includes/header.php';
?>
<?= $emailMsg ?? '' ?>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-receipt"></i><?= $LANG['payslip'] ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('payroll_list') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?></a>
        <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="bi bi-printer"></i> <?= $LANG['print_payslip'] ?></button>
        <a href="<?= url('payroll/payroll_slip_pdf.php?id=' . $id) ?>" class="btn btn-sm btn-danger" target="_blank"><i class="bi bi-file-pdf"></i> <?= $LANG['download_pdf'] ?></a>
        <?php if (!empty($data['email'])): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="send_email">
            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Send payslip to <?= safeOutput($data['email']) ?>?')">
                <i class="bi bi-envelope-arrow-up"></i> <?= $LANG['send_email'] ?>
            </button>
        </form>
        <?php else: ?>
        <button class="btn btn-sm btn-secondary" disabled title="<?= htmlspecialchars($LANG['no_email_on_file'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-envelope-x"></i> <?= htmlspecialchars($LANG['no_email']) ?></button>
        <?php endif; ?>
    </div>
</div>

<!-- Payslip Card -->
<div class="card border-0 shadow-sm" id="slipCard" style="max-width:760px;margin:auto">
    <div class="card-body p-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h3 class="fw-bold text-primary mb-1"><?= safeOutput($siteName) ?></h3>
                <div class="text-muted">Payslip | <?= safeOutput($data['period_name']) ?></div>
            </div>
            <div class="text-end">
                <div class="badge bg-<?= ['draft'=>'secondary','approved'=>'info','paid'=>'success'][$data['status']] ?? 'secondary' ?> fs-6"><?= ucfirst($data['status']) ?></div>
                <?php if ($data['slip_sent']): ?><div class="text-success small mt-1"><i class="bi bi-envelope-check"></i> <?= htmlspecialchars($LANG['email_sent']) ?></div><?php endif; ?>
            </div>
        </div>

        <hr>

        <!-- Employee Info -->
        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:140px"><?= htmlspecialchars($LANG['employee_name']) ?></td><td class="fw-semibold"><?= safeOutput($data['full_name']) ?></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars($LANG['employee_code']) ?></td><td><?= safeOutput($data['employee_code']) ?></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars($LANG['position']) ?></td><td><?= safeOutput($data['position'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars($LANG['department']) ?></td><td><?= safeOutput($data['department'] ?? '-') ?></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:140px"><?= htmlspecialchars($LANG['period']) ?></td><td class="fw-semibold"><?= safeOutput($data['period_name']) ?></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars($LANG['payment_date']) ?></td><td><?= $data['payment_date'] ? date('d M Y', strtotime($data['payment_date'])) : '-' ?></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars($LANG['bank']) ?></td><td><?= safeOutput(($data['bank_name']??'') . ' - ' . ($data['bank_account_number']??'')) ?></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars($LANG['account_name']) ?></td><td><?= safeOutput($data['bank_account_name'] ?? '-') ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Attendance Summary -->
        <div class="row g-2 mb-4">
            <?php $attItems = [
                ['Working Days','working_days','primary'],
                ['Present','present_days','success'],
                ['Absent','absent_days','danger'],
                ['Late Count','late_count','warning'],
                [$LANG['late'],'late_minutes_total','warning'],
                [$LANG['overtime'],'overtime_hours','info'],
            ]; foreach ($attItems as [$l,$k,$c]): ?>
            <div class="col-4 col-md-2 text-center">
                <div class="border rounded p-2">
                    <div class="fw-bold text-<?= $c ?>"><?= $data[$k] ?></div>
                    <div class="text-muted" style="font-size:11px"><?= $l ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Salary Breakdown -->
        <div class="row g-3">
            <!-- Earnings -->
            <div class="col-md-6">
                <div class="fw-semibold text-success mb-2"><i class="bi bi-plus-circle me-1"></i><?= htmlspecialchars($LANG['earnings']) ?></div>
                <table class="table table-sm table-bordered mb-0">
                    <tr class="table-light"><td><?= htmlspecialchars($LANG['basic_salary']) ?></td><td class="text-end"><?= $fmt($data['basic_salary']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['meal_allowance']) ?></td><td class="text-end"><?= $fmt($data['meal_allowance']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['transport_allowance']) ?></td><td class="text-end"><?= $fmt($data['transport_allowance']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['overtime']) ?> (<?= $data['overtime_hours'] ?>)</td><td class="text-end"><?= $fmt($data['overtime_amount']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['bonus']) ?></td><td class="text-end"><?= $fmt($data['bonus']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['other_allowance']) ?></td><td class="text-end"><?= $fmt($data['other_allowance']) ?></td></tr>
                    <tr class="table-success fw-bold"><td><?= htmlspecialchars($LANG['gross_salary']) ?></td><td class="text-end"><?= $fmt($data['gross_salary']) ?></td></tr>
                </table>
            </div>
            <!-- Deductions -->
            <div class="col-md-6">
                <div class="fw-semibold text-danger mb-2"><i class="bi bi-dash-circle me-1"></i><?= htmlspecialchars($LANG['deductions']) ?></div>
                <table class="table table-sm table-bordered mb-0">
                    <tr><td><?= htmlspecialchars($LANG['absent']) ?> (<?= $data['absent_days'] ?>)</td><td class="text-end text-danger">-<?= $fmt($data['absence_deduction']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['late']) ?> (<?= $data['late_minutes_total'] ?>)</td><td class="text-end text-danger">-<?= $fmt($data['late_deduction']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['bpjs_kesehatan']) ?></td><td class="text-end text-danger">-<?= $fmt($data['bpjs_kesehatan_deduction']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['bpjs_ketenagakerjaan']) ?></td><td class="text-end text-danger">-<?= $fmt($data['bpjs_ketenagakerjaan_deduction']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['income_tax']) ?></td><td class="text-end text-danger">-<?= $fmt($data['income_tax']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['cash_advance']) ?></td><td class="text-end text-danger">-<?= $fmt($data['kasbon_deduction']) ?></td></tr>
                    <tr><td><?= htmlspecialchars($LANG['other_deduction']) ?></td><td class="text-end text-danger">-<?= $fmt($data['other_deduction']) ?></td></tr>
                    <tr class="table-danger fw-bold"><td><?= htmlspecialchars($LANG['total_deduction']) ?></td><td class="text-end">-<?= $fmt($data['total_deduction']) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Net Salary -->
        <div class="mt-4 p-3 rounded text-white text-center" style="background:linear-gradient(135deg,#667eea,#764ba2)">
            <div class="small opacity-75 mb-1"><?= htmlspecialchars($LANG['net_salary']) ?></div>
            <div class="fs-3 fw-bold"><?= $fmt($data['net_salary']) ?></div>
        </div>

        <?php if ($data['notes']): ?>
        <div class="mt-3 p-3 bg-light rounded small text-muted"><b>Notes:</b> <?= safeOutput($data['notes']) ?></div>
        <?php endif; ?>

        <div class="text-center text-muted mt-4" style="font-size:11px">
            This is a computer-generated payslip. No signature required. | <?= safeOutput($siteName) ?>
            <?php if ($data['approved_by_name']): ?> | Approved by: <?= safeOutput($data['approved_by_name']) ?><?php endif; ?>
        </div>
    </div>
</div>

<style>
@media print {
    .navbar, .sidebar, .content > .d-flex, .btn, footer { display: none !important; }
    .content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    #slipCard { box-shadow: none !important; max-width: 100% !important; }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>