<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "employee_portal" — MODUL 10: SELF-SERVICE KARYAWAN

   Halaman ini menampilkan data karyawan yang login SAJA.

   ATURAN KEAMANAN YANG TIDAK BOLEH DILANGGAR:
   Seluruh query di bawah difilter dengan employee_id yang berasal dari
   hrisCurrentEmployee() — yaitu baris employees yang user_id-nya sama
   dengan akun yang sedang login. TIDAK ADA satu pun query yang membaca
   $_GET['id'] atau $_POST['employee_id']. Dengan begitu, mengubah URL
   tidak akan pernah menampilkan data karyawan lain.

   Kaitan akun dibuat lewat kolom employees.user_id. Selama kolom itu
   kosong, karyawan belum bisa memakai portal — dan halaman ini
   memberitahukannya dengan jelas, bukan menampilkan halaman kosong.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('employee_portal');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('employee_portal');

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('employee_portal'); }

if (!hrisHasColumn('employees', 'user_id')) {
    echo hrisMigrationNotice('employees.user_id');
    return;
}

$__ep_me = hrisCurrentEmployee();

if (!$__ep_me) {
    echo '<div class="alert alert-warning d-flex align-items-start gap-2 mb-0">'
       . '<i class="bi bi-person-exclamation mt-1"></i><div>'
       . '<strong>' . hrisOut($LANG['portal_not_linked_title']) . '</strong><br>'
       . hrisOut($LANG['portal_not_linked_text'])
       . '</div></div>';
    return;
}

$__ep_id = (int)$__ep_me['id'];

/* ── Semua query DIKUNCI ke $__ep_id ─────────────────────────────────── */
$__ep_att = [];
$st = $conn->prepare(
    "SELECT attendance_date, check_in, check_out, status, late_minutes
       FROM attendance WHERE employee_id = ?
      ORDER BY attendance_date DESC LIMIT 30"
);
if ($st) { $st->bind_param('i', $__ep_id); $st->execute();
           $__ep_att = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); }

$__ep_leave = [];
$st = $conn->prepare(
    "SELECT leave_type, start_date, end_date, total_days, status
       FROM leave_requests WHERE employee_id = ?
      ORDER BY start_date DESC LIMIT 20"
);
if ($st) { $st->bind_param('i', $__ep_id); $st->execute();
           $__ep_leave = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); }

$__ep_adv = [];
$st = $conn->prepare(
    "SELECT request_date, amount, monthly_deduction, remaining_balance, status
       FROM cash_advances WHERE employee_id = ?
      ORDER BY request_date DESC LIMIT 20"
);
if ($st) { $st->bind_param('i', $__ep_id); $st->execute();
           $__ep_adv = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); }

$__ep_slips = [];
$st = $conn->prepare(
    "SELECT p.id, p.net_salary, p.status, pp.period_name
       FROM payroll p
       LEFT JOIN payroll_periods pp ON pp.id = p.period_id
      WHERE p.employee_id = ?
      ORDER BY p.id DESC LIMIT 12"
);
if ($st) { $st->bind_param('i', $__ep_id); $st->execute();
           $__ep_slips = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); }

$__ep_assets = [];
if (hrisHasTable('employee_assets')) {
    $st = $conn->prepare(
        "SELECT asset_code, asset_name, assigned_date, asset_condition, status
           FROM employee_assets WHERE employee_id = ? ORDER BY id DESC"
    );
    if ($st) { $st->bind_param('i', $__ep_id); $st->execute();
               $__ep_assets = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); }
}

$__ep_leaveStatus = ['pending' => $LANG['pending'], 'approved' => $LANG['approved'], 'rejected' => $LANG['rejected']];
?>

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person-vcard-fill me-2"></i><?= hrisOut($LANG['my_profile']) ?></div>
            <div class="card-body">
                <div class="row g-2 small">
                    <div class="col-sm-6"><span class="text-muted"><?= hrisOut($LANG['full_name']) ?>:</span>
                        <strong><?= hrisOut($__ep_me['full_name']) ?></strong></div>
                    <div class="col-sm-6"><span class="text-muted"><?= hrisOut($LANG['employee_code']) ?>:</span>
                        <strong><?= hrisOut($__ep_me['employee_code']) ?></strong></div>
                    <div class="col-sm-6"><span class="text-muted"><?= hrisOut($LANG['email']) ?>:</span>
                        <?= hrisOut($__ep_me['email'] ?? '') ?></div>
                    <div class="col-sm-6"><span class="text-muted"><?= hrisOut($LANG['direct_manager']) ?>:</span>
                        <?= hrisOut($__ep_me['manager_name'] ?? '') ?: '<span class="text-muted">&mdash;</span>' ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 stat-card">
            <div class="card-body text-center">
                <div class="stat-label"><?= hrisOut($LANG['basic_salary']) ?></div>
                <div class="stat-value"><?= formatCurrency((float)($__ep_me['basic_salary'] ?? 0)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-receipt me-2"></i><?= hrisOut($LANG['my_payslips']) ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr>
                            <th><?= hrisOut($LANG['period']) ?></th>
                            <th><?= hrisOut($LANG['net_salary']) ?></th>
                            <th><?= hrisOut($LANG['status']) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($__ep_slips)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                        <?php else: foreach ($__ep_slips as $s): ?>
                            <tr>
                                <td class="small"><?= hrisOut($s['period_name'] ?? '') ?></td>
                                <td class="small"><?= formatCurrency((float)$s['net_salary']) ?></td>
                                <td class="small"><?= hrisOut($s['status']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-file-earmark-check-fill me-2"></i><?= hrisOut($LANG['my_leave']) ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr>
                            <th><?= hrisOut($LANG['type']) ?></th>
                            <th><?= hrisOut($LANG['date']) ?></th>
                            <th><?= hrisOut($LANG['total']) ?></th>
                            <th><?= hrisOut($LANG['status']) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($__ep_leave)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                        <?php else: foreach ($__ep_leave as $l): ?>
                            <tr>
                                <td class="small"><?= hrisOut($l['leave_type']) ?></td>
                                <td class="small"><?= hrisOut(date('d-m-Y', strtotime((string)$l['start_date']))) ?></td>
                                <td class="small"><?= (int)$l['total_days'] ?></td>
                                <td class="small"><?= hrisOut($__ep_leaveStatus[$l['status']] ?? $l['status']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cash-coin me-2"></i><?= hrisOut($LANG['my_cash_advances']) ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr>
                            <th><?= hrisOut($LANG['date']) ?></th>
                            <th><?= hrisOut($LANG['amount']) ?></th>
                            <th><?= hrisOut($LANG['remaining']) ?></th>
                            <th><?= hrisOut($LANG['status']) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($__ep_adv)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                        <?php else: foreach ($__ep_adv as $a): ?>
                            <tr>
                                <td class="small"><?= hrisOut(date('d-m-Y', strtotime((string)$a['request_date']))) ?></td>
                                <td class="small"><?= formatCurrency((float)$a['amount']) ?></td>
                                <td class="small"><?= formatCurrency((float)$a['remaining_balance']) ?></td>
                                <td class="small"><?= hrisOut($a['status']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-box-seam me-2"></i><?= hrisOut($LANG['my_assets']) ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr>
                            <th><?= hrisOut($LANG['code']) ?></th>
                            <th><?= hrisOut($LANG['name']) ?></th>
                            <th><?= hrisOut($LANG['assigned_date']) ?></th>
                            <th><?= hrisOut($LANG['status']) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($__ep_assets)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                        <?php else: foreach ($__ep_assets as $a): ?>
                            <tr>
                                <td class="small"><?= hrisOut($a['asset_code']) ?></td>
                                <td class="small"><?= hrisOut($a['asset_name']) ?></td>
                                <td class="small"><?= $a['assigned_date'] ? hrisOut(date('d-m-Y', strtotime((string)$a['assigned_date']))) : '&mdash;' ?></td>
                                <td class="small"><?= hrisOut($a['status']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-calendar-check-fill me-2"></i><?= hrisOut($LANG['my_attendance']) ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr>
                            <th><?= hrisOut($LANG['date']) ?></th>
                            <th><?= hrisOut($LANG['check_in']) ?></th>
                            <th><?= hrisOut($LANG['check_out']) ?></th>
                            <th><?= hrisOut($LANG['status']) ?></th>
                            <th><?= hrisOut($LANG['late']) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($__ep_att)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                        <?php else: foreach ($__ep_att as $a): ?>
                            <tr>
                                <td class="small"><?= hrisOut(date('d-m-Y', strtotime((string)$a['attendance_date']))) ?></td>
                                <td class="small"><?= hrisOut($a['check_in'] ?? '') ?></td>
                                <td class="small"><?= hrisOut($a['check_out'] ?? '') ?></td>
                                <td class="small"><?= hrisOut($a['status']) ?></td>
                                <td class="small"><?= (int)($a['late_minutes'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
