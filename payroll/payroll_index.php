<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "dashboard" — bagian dari payroll/payroll_gate.php

   Berkas ini TIDAK lagi berdiri sendiri. Ia di-include oleh hub
   payroll_gate.php, yang sudah lebih dulu:
     - memeriksa login,
     - memeriksa gerbang induk 'payroll' dan izin modul tab ini,
     - mencetak includes/header.php,
     - dan akan mencetak includes/footer.php setelah fragmen ini selesai.

   Karena itu berkas ini TIDAK BOLEH lagi meng-include header.php maupun
   footer.php — kalau ikut mencetak, sidebar dan navbar akan tampil dua kali.

   URL LAMA TETAP HIDUP: bila berkas ini dibuka langsung (PAYROLL_GATE
   belum terdefinisi), permintaan dialihkan ke tab yang sesuai di hub,
   sehingga bookmark dan tautan lama tidak ada yang mati.

   Seluruh handler POST di bawah tetap berada di posisi semula dan tetap
   berfungsi: includes/config.php mengaktifkan ob_start() di baris 2 dan
   footer.php baru menutupnya dengan ob_end_flush(), sehingga redirect()
   tetap sah walaupun header sudah tercetak lebih dulu.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';

    /* PERMINTAAN POST TETAP DILAYANI DI SINI — JANGAN dialihkan.
       Seluruh form dan pemanggilan AJAX di berkas ini menembak alamatnya
       sendiri. Kalau POST ikut dialihkan ke hub, AJAX akan menerima HTML
       alih-alih JSON dan setiap tombol simpan/hapus akan diam saja.
       Handler POST di bawah berakhir dengan echo JSON + exit atau dengan
       redirect(), jadi tidak pernah sampai ke bagian HTML.

       PERMINTAAN GET dialihkan ke tab yang sesuai di hub, sehingga bookmark
       dan tautan lama tetap hidup dan tidak ada halaman kembar. */
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('dashboard');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/payroll_index.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
if (!checkPermission('payroll', 'can_access') && !checkPermission('payroll_dashboard', 'can_access')) {
    redirectNoAccess();
}

$page_title = $LANG['payroll_dashboard'];

$statsEmp     = (int)($conn->query("SELECT COUNT(*) AS c FROM employees WHERE employee_status = 'active'")->fetch_assoc()['c'] ?? 0);
$statsToday   = (int)($conn->query("SELECT COUNT(*) AS c FROM attendance WHERE attendance_date = CURDATE()")->fetch_assoc()['c'] ?? 0);
$statsPeriod  = (int)($conn->query("SELECT COUNT(*) AS c FROM payroll_periods WHERE period_year = YEAR(NOW()) AND period_month = MONTH(NOW())")->fetch_assoc()['c'] ?? 0);
$statsPaid    = (float)($conn->query("SELECT COALESCE(SUM(p.net_salary),0) AS s FROM payroll p JOIN payroll_periods pp ON p.period_id = pp.id WHERE pp.period_year = YEAR(NOW()) AND pp.period_month = MONTH(NOW()) AND p.status = 'paid'")->fetch_assoc()['s'] ?? 0);
$pendingLeave = (int)($conn->query("SELECT COUNT(*) AS c FROM leave_requests WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0);
$pendingCash  = (int)($conn->query("SELECT COUNT(*) AS c FROM cash_advances WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('dashboard'); }
?>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-people-fill"></i><?= safeOutput($LANG['payroll_dashboard']) ?></h2>
    <div class="page-actions">
        <a href="<?= url('dashboard.php') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-primary bg-opacity-10 text-primary flex-shrink-0"><i class="bi bi-people fs-4"></i></div>
                <div><div class="text-muted small"><?= safeOutput($LANG['active_employees']) ?></div><div class="fw-bold fs-4"><?= $statsEmp ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-success bg-opacity-10 text-success flex-shrink-0"><i class="bi bi-calendar-check fs-4"></i></div>
                <div><div class="text-muted small"><?= safeOutput($LANG['attendance_today']) ?></div><div class="fw-bold fs-4"><?= $statsToday ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-warning bg-opacity-10 text-warning flex-shrink-0"><i class="bi bi-calendar-month fs-4"></i></div>
                <div><div class="text-muted small"><?= safeOutput($LANG['payroll_this_month']) ?></div><div class="fw-bold fs-4"><?= $statsPeriod ? safeOutput($LANG['available']) : safeOutput($LANG['not_yet']) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-info bg-opacity-10 text-info flex-shrink-0"><i class="bi bi-cash-stack fs-4"></i></div>
                <div><div class="text-muted small"><?= safeOutput($LANG['paid_this_month']) ?></div><div class="fw-bold"><?= formatCurrency($statsPaid) ?></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Pending Alerts -->
<?php if ($pendingLeave > 0 && checkPermission('leave_requests', 'can_access')): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span><?= $pendingLeave ?> <strong><?= safeOutput($LANG['leave_requests']) ?></strong> <?= strtolower(safeOutput($LANG['pending'])) ?> —
    <a href="<?= payrollGateUrl('leave_requests') . '&status=pending' ?>" class="alert-link"><?= safeOutput($LANG['view']) ?></a></span>
</div>
<?php endif; ?>
<?php if ($pendingCash > 0 && checkPermission('cash_advances', 'can_access')): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-cash-coin"></i>
    <span><?= $pendingCash ?> <strong><?= safeOutput($LANG['cash_advances']) ?></strong> <?= strtolower(safeOutput($LANG['pending'])) ?> —
    <a href="<?= payrollGateUrl('cash_advances') . '&status=pending' ?>" class="alert-link"><?= safeOutput($LANG['view']) ?></a></span>
</div>
<?php endif; ?>

<?php
// Navigation items — includes new shift_schedules and holidays modules
$navItems = [
    ['mod'=>'employees',        'url'=>payrollGateUrl('employees'),        'icon'=>'person-badge-fill',      'title'=>$LANG['employees'],           'desc'=>$LANG['manage_employee_data'],        'color'=>'primary'],
    ['mod'=>'attendance',       'url'=>payrollGateUrl('attendance'),        'icon'=>'calendar-check-fill',    'title'=>$LANG['attendance'],          'desc'=>$LANG['daily_attendance_records'], 'color'=>'success'],
    ['mod'=>'shifts',           'url'=>payrollGateUrl('shifts'),            'icon'=>'clock-fill',             'title'=>$LANG['shifts'],                  'desc'=>$LANG['manage_work_shifts'],            'color'=>'warning'],
    ['mod'=>'shift_schedules',  'url'=>payrollGateUrl('shift_schedules'),   'icon'=>'calendar3',              'title'=>$LANG['shift_schedules'], 'desc'=>$LANG['shift_schedules_desc'],'color'=>'warning'],
    ['mod'=>'payroll_periods',  'url'=>payrollGateUrl('payroll_periods'),   'icon'=>'calendar-month-fill',    'title'=>$LANG['payroll_periods'], 'desc'=>$LANG['manage_payroll_periods'],    'color'=>'info'],
    ['mod'=>'payroll_list',     'url'=>payrollGateUrl('payroll_list'),      'icon'=>'receipt',                'title'=>$LANG['payroll_list'],      'desc'=>$LANG['view_all_payslips'],              'color'=>'danger'],
    ['mod'=>'leave_requests',   'url'=>payrollGateUrl('leave_requests'),    'icon'=>'file-earmark-check-fill','title'=>$LANG['leave_requests'],  'desc'=>$LANG['manage_leave_requests'],      'color'=>'secondary'],
    ['mod'=>'cash_advances',    'url'=>payrollGateUrl('cash_advances'),     'icon'=>'cash-coin',              'title'=>$LANG['cash_advances'],    'desc'=>$LANG['manage_cash_advances'],        'color'=>'dark'],
    ['mod'=>'holidays',         'url'=>payrollGateUrl('holidays'),          'icon'=>'calendar-event-fill',    'title'=>$LANG['holidays'],              'desc'=>$LANG['manage_holidays'],           'color'=>'danger'],
    ['mod'=>'payroll_settings', 'url'=>payrollGateUrl('payroll_settings'),  'icon'=>'gear-wide-connected',    'title'=>$LANG['payroll_settings'],      'desc'=>$LANG['overtime_tax_bpjs_settings'],  'color'=>'primary'],
];
$masterItems = [
    ['mod'=>'departments','url'=>payrollGateUrl('departments'),'icon'=>'diagram-3-fill',       'title'=>$LANG['departments'],'desc'=>$LANG['manage_departments'],'color'=>'primary'],
    ['mod'=>'positions',  'url'=>payrollGateUrl('positions'),  'icon'=>'briefcase-fill',        'title'=>$LANG['positions'],    'desc'=>$LANG['manage_positions'],    'color'=>'success'],
    ['mod'=>'religions',  'url'=>payrollGateUrl('religions'),  'icon'=>'journal-bookmark-fill', 'title'=>$LANG['religions'],    'desc'=>$LANG['manage_religions'],    'color'=>'info'],
];
$visibleNav    = array_filter($navItems,    fn($n) => checkPermission($n['mod'], 'can_access'));
$visibleMaster = array_filter($masterItems, fn($n) => checkPermission($n['mod'], 'can_access'));
?>

<div class="row g-3">
<?php foreach ($visibleNav as $nav): ?>
    <div class="col-6 col-lg-4 col-xl-3">
        <a href="<?= $nav['url'] ?>" class="card border-0 shadow-sm h-100 text-decoration-none payroll-nav-card">
            <div class="card-body text-center py-4">
                <div class="rounded-circle p-3 bg-<?= $nav['color'] ?> bg-opacity-10 text-<?= $nav['color'] ?> d-inline-block mb-3">
                    <i class="bi bi-<?= $nav['icon'] ?> fs-3"></i>
                </div>
                <h6 class="fw-semibold text-dark mb-1"><?= safeOutput($nav['title']) ?></h6>
                <p class="text-muted small mb-0"><?= safeOutput($nav['desc']) ?></p>
            </div>
        </a>
    </div>
<?php endforeach; ?>
</div>

<?php if (!empty($visibleMaster)): ?>
<h6 class="text-muted fw-semibold mt-4 mb-3 px-1">
    <i class="bi bi-database-fill me-1"></i><?= safeOutput($LANG['master_data']) ?>
</h6>
<div class="row g-3">
<?php foreach ($visibleMaster as $nav): ?>
    <div class="col-6 col-lg-4 col-xl-3">
        <a href="<?= $nav['url'] ?>" class="card border-0 shadow-sm h-100 text-decoration-none payroll-nav-card">
            <div class="card-body text-center py-4">
                <div class="rounded-circle p-3 bg-<?= $nav['color'] ?> bg-opacity-10 text-<?= $nav['color'] ?> d-inline-block mb-3">
                    <i class="bi bi-<?= $nav['icon'] ?> fs-3"></i>
                </div>
                <h6 class="fw-semibold text-dark mb-1"><?= safeOutput($nav['title']) ?></h6>
                <p class="text-muted small mb-0"><?= safeOutput($nav['desc']) ?></p>
            </div>
        </a>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.payroll-nav-card { transition: transform .18s, box-shadow .18s; }
.payroll-nav-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.12) !important; }
</style>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
