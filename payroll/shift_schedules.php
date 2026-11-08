<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "shift_schedules" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('shift_schedules');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/shift_schedules.php
// Multi-Shift: Assign shift schedule per employee per date
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
if (!checkPermission('shift_schedules', 'can_access')) redirectNoAccess();

$page_title = $LANG['shift_schedules'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ───────────────────────────────────────────────────────────
   AJAX: Save / Delete Schedule
─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_csrf']]); exit;
    }

    $ajax = $_POST['ajax'];
    $uid  = (int)$_SESSION['user_id'];

    // Save single schedule entry
    if ($ajax === 'save') {
        if (!canManageShiftSchedules()) {
            echo json_encode(['success' => false, 'message' => $LANG['no_permission']]); exit;
        }
        $empId   = (int)($_POST['employee_id'] ?? 0);
        $date    = trim($_POST['schedule_date'] ?? '');
        $shiftId = (int)($_POST['shift_id']     ?? 0);
        $isOff   = isset($_POST['is_off']) ? 1 : 0;
        $notes   = trim($_POST['notes'] ?? '');

        if ($empId <= 0 || !$date) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing']); exit;
        }
        if (!$isOff && !$shiftId) {
            echo json_encode(['success' => false, 'message' => 'Please select a shift']); exit;
        }
        $notes = $notes ?: null;
        $result = saveEmployeeShiftSchedule($conn, $empId, $date, (int)$shiftId, $isOff, $notes, $uid);
        echo json_encode($result); exit;
    }

    // Save bulk schedule for a date range
    if ($ajax === 'bulk_save') {
        if (!canManageShiftSchedules()) {
            echo json_encode(['success' => false, 'message' => $LANG['no_permission']]); exit;
        }
        $empId    = (int)($_POST['employee_id']  ?? 0);
        $shiftId  = (int)($_POST['shift_id']     ?? 0);
        $startDate = trim($_POST['start_date']   ?? '');
        $endDate   = trim($_POST['end_date']     ?? '');
        $skipDow   = $_POST['skip_dow'] ?? []; // days of week to skip (0=Sun,6=Sat)
        $isOff    = isset($_POST['is_off']) ? 1 : 0;
        $notes    = trim($_POST['notes'] ?? '') ?: null;

        if ($empId <= 0 || !$startDate || !$endDate) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing']); exit;
        }
        if (!$isOff && !$shiftId) {
            echo json_encode(['success' => false, 'message' => 'Please select a shift']); exit;
        }
        if ($endDate < $startDate) {
            echo json_encode(['success' => false, 'message' => 'End date must be after start date']); exit;
        }
        $cur   = new DateTime($startDate);
        $last  = new DateTime($endDate);
        $count = 0;
        while ($cur <= $last) {
            $dow = (int)$cur->format('w'); // 0=Sun,6=Sat
            if (!in_array($dow, $skipDow, false)) {
                saveEmployeeShiftSchedule($conn, $empId, $cur->format('Y-m-d'), $shiftId ?: 0, $isOff, $notes, $uid);
                $count++;
            }
            $cur->modify('+1 day');
        }
        logActivity('shift_schedule_bulk', "Bulk schedule for emp $empId: $startDate to $endDate ($count days)");
        echo json_encode(['success' => true, 'count' => $count, 'message' => "$count days scheduled"]); exit;
    }

    // Delete a single schedule entry
    if ($ajax === 'delete') {
        if (!canDeleteShiftSchedule()) {
            echo json_encode(['success' => false, 'message' => $LANG['no_permission']]); exit;
        }
        $empId = (int)($_POST['employee_id']  ?? 0);
        $date  = trim($_POST['schedule_date'] ?? '');
        if ($empId <= 0 || !$date) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']); exit;
        }
        $ok = deleteEmployeeShiftSchedule($conn, $empId, $date);
        echo json_encode(['success' => $ok]); exit;
    }

    // Get schedule for an employee for a month (for calendar view)
    if ($ajax === 'get_schedule') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $year  = (int)($_POST['year']        ?? date('Y'));
        $month = (int)($_POST['month']       ?? date('n'));
        if ($empId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid employee']); exit; }
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));
        $schedule  = getEmployeeScheduleForRange($conn, $empId, $startDate, $endDate);
        // Also get employee's default shift
        $emp = getEmployee($conn, $empId);
        echo json_encode(['success' => true, 'schedule' => $schedule, 'default_shift_id' => $emp['shift_id'] ?? null]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']); exit;
}

/* ───────────────────────────────────────────────────────────
   DATA
─────────────────────────────────────────────────────────── */
$employees  = getActiveEmployees($conn);
$allShifts  = getActiveShifts($conn);

$filterEmp  = (int)($_GET['employee_id'] ?? (count($employees) === 1 ? $employees[0]['id'] : 0));
$filterYear  = (int)($_GET['year']       ?? date('Y'));
$filterMonth = (int)($_GET['month']      ?? date('n'));

// Current month schedule
$scheduleData = [];
$defaultShiftId = null;
if ($filterEmp > 0) {
    $startDate      = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
    $endDate        = date('Y-m-t', strtotime($startDate));
    $scheduleData   = getEmployeeScheduleForRange($conn, $filterEmp, $startDate, $endDate);
    $selectedEmp    = getEmployee($conn, $filterEmp);
    $defaultShiftId = $selectedEmp['shift_id'] ?? null;
}

// Build calendar days
$daysInMonth  = (int)date('t', mktime(0,0,0,$filterMonth,1,$filterYear));
$firstDow     = (int)date('w', mktime(0,0,0,$filterMonth,1,$filterYear)); // 0=Sun

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('shift_schedules'); }
?>

<link rel="stylesheet" href="<?= url('assets/vendor/datatables/dataTables.bootstrap5.min.css') ?>">
<style>
.shift-calendar { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.shift-calendar .cal-header { text-align:center; font-size:.75rem; font-weight:600; color:#6c757d; padding:4px 0; }
.shift-calendar .cal-day { border:1px solid #e2e8f0; border-radius:8px; min-height:70px; padding:6px; cursor:pointer; transition:all .15s; position:relative; background:#fff; }
.shift-calendar .cal-day:hover { border-color:#4f8ef7; box-shadow:0 0 0 2px rgba(79,142,247,.15); }
.shift-calendar .cal-day.today { border-color:#4f8ef7; background:#f0f4ff; }
.shift-calendar .cal-day.day-off { background:#fff5f5; }
.shift-calendar .cal-day.has-schedule { border-color:#ccc; }
.shift-calendar .cal-day.filler { border:none; background:transparent; cursor:default; }
.cal-day-num { font-size:.8rem; font-weight:600; color:#1e2a38; }
.cal-shift-badge { font-size:.7rem; padding:2px 6px; border-radius:12px; display:inline-block; margin-top:3px; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
</style>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-calendar3 text-info"></i><?= safeOutput($LANG['shift_schedules']) ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('shifts') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
        <?php if (canManageShiftSchedules()): ?>
        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#bulkModal">
            <i class="bi bi-calendar-plus"></i><?= safeOutput($LANG['bulk_schedule']) ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['employee']) ?></label>
                <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value=""><?= safeOutput($LANG['select_employee']) ?></option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>" <?= $filterEmp === (int)$emp['id'] ? 'selected' : '' ?>>
                        <?= safeOutput($emp['full_name']) ?> (<?= safeOutput($emp['employee_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['month']) ?></label>
                <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>><?= getMonthName($m) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['year']) ?></label>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($filterEmp > 0 && !empty($allShifts)): ?>
<!-- Shift Legend -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($allShifts as $sh): ?>
    <span class="badge rounded-pill border" style="background:<?= safeOutput($sh['color'] ?? '#6c757d') ?>;color:#fff">
        <?= safeOutput($sh['code']) ?> <?= $sh['start_time'] ? date('H:i', strtotime($sh['start_time'])) : '' ?>
    </span>
    <?php endforeach; ?>
    <span class="badge rounded-pill border bg-danger-subtle text-danger"><?= safeOutput($LANG['day_off']) ?></span>
    <?php if ($defaultShiftId): ?>
    <?php $ds = array_values(array_filter($allShifts, fn($s) => $s['id'] == $defaultShiftId))[0] ?? null; ?>
    <?php if ($ds): ?>
    <span class="text-muted small ms-2"><i class="bi bi-info-circle me-1"></i><?= safeOutput($LANG['default_shift']) ?>: <strong><?= safeOutput($ds['name']) ?></strong></span>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Calendar -->
<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title">
            <?= safeOutput(getMonthName($filterMonth)) ?> <?= $filterYear ?>
            <?php if ($selectedEmp ?? false): ?> — <?= safeOutput($selectedEmp['full_name']) ?><?php endif; ?>
        </div>
        <div class="d-flex gap-1">
            <?php
            $prevM = $filterMonth - 1; $prevY = $filterYear;
            if ($prevM < 1) { $prevM = 12; $prevY--; }
            $nextM = $filterMonth + 1; $nextY = $filterYear;
            if ($nextM > 12) { $nextM = 1; $nextY++; }
            ?>
            <a href="?employee_id=<?= $filterEmp ?>&month=<?= $prevM ?>&year=<?= $prevY ?>" class="btn btn-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
            <a href="?employee_id=<?= $filterEmp ?>&month=<?= $nextM ?>&year=<?= $nextY ?>" class="btn btn-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
        </div>
    </div>
    <div class="card-body">
        <div class="shift-calendar mb-2">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dh): ?>
            <div class="cal-header"><?= $dh ?></div>
            <?php endforeach; ?>
            <!-- Filler cells for first week -->
            <?php for ($f = 0; $f < $firstDow; $f++): ?>
            <div class="cal-day filler"></div>
            <?php endfor; ?>
            <?php
            $today = date('Y-m-d');
            for ($d = 1; $d <= $daysInMonth; $d++):
                $dateStr = sprintf('%04d-%02d-%02d', $filterYear, $filterMonth, $d);
                $sched   = $scheduleData[$dateStr] ?? null;
                $isToday = ($dateStr === $today);
                $classes = 'cal-day';
                if ($isToday) $classes .= ' today';
                if ($sched && $sched['is_off']) $classes .= ' day-off';
                if ($sched && !$sched['is_off']) $classes .= ' has-schedule';

                // Find shift info
                $shiftInfo = null;
                if ($sched && !$sched['is_off'] && $sched['shift_id']) {
                    foreach ($allShifts as $sh) {
                        if ($sh['id'] == $sched['shift_id']) { $shiftInfo = $sh; break; }
                    }
                } elseif (!$sched && $defaultShiftId) {
                    foreach ($allShifts as $sh) {
                        if ($sh['id'] == $defaultShiftId) { $shiftInfo = $sh; break; }
                    }
                }
            ?>
            <div class="<?= $classes ?>"
                 data-date="<?= $dateStr ?>"
                 data-shift-id="<?= $sched ? (int)$sched['shift_id'] : (int)($defaultShiftId ?? 0) ?>"
                 data-is-off="<?= $sched ? (int)$sched['is_off'] : 0 ?>"
                 data-is-custom="<?= $sched ? 1 : 0 ?>"
                 onclick="openDayModal('<?= $dateStr ?>',this)"
                 title="<?= $dateStr ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <span class="cal-day-num"><?= $d ?></span>
                    <?php if ($sched): ?>
                    <button type="button" class="btn btn-link btn-sm p-0 text-danger" title="<?= safeOutput($LANG['clear']) ?>"
                            onclick="event.stopPropagation();clearSchedule('<?= $dateStr ?>')">
                        <i class="bi bi-x-circle" style="font-size:.7rem"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($sched && $sched['is_off']): ?>
                    <span class="cal-shift-badge bg-danger text-white"><?= safeOutput($LANG['day_off']) ?></span>
                <?php elseif ($shiftInfo): ?>
                    <span class="cal-shift-badge text-white" style="background:<?= safeOutput($shiftInfo['color'] ?? '#6c757d') ?>">
                        <?= safeOutput($shiftInfo['code']) ?>
                        <?= $shiftInfo['start_time'] ? date('H:i', strtotime($shiftInfo['start_time'])) : '' ?>
                    </span>
                    <?php if (!$sched): ?><span class="text-muted" style="font-size:.6rem">(<?= safeOutput($LANG['default']) ?>)</span><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Day Edit Modal -->
<?php if (canManageShiftSchedules()): ?>
<div class="modal fade" id="dayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="dayModalTitle"><i class="bi bi-calendar-day me-2"></i><?= htmlspecialchars($LANG['schedule']) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="dayIsOff" onchange="toggleDayOff(this)">
                        <label class="form-check-label text-danger fw-semibold" for="dayIsOff">
                            <i class="bi bi-calendar-x me-1"></i><?= safeOutput($LANG['day_off']) ?>
                        </label>
                    </div>
                </div>
                <div id="shiftSelectWrap">
                    <label class="form-label small fw-semibold"><?= safeOutput($LANG['shift']) ?></label>
                    <select id="dayShiftId" class="form-select form-select-sm">
                        <option value=""><?= safeOutput($LANG['select_shift']) ?></option>
                        <?php foreach ($allShifts as $sh): ?>
                        <option value="<?= (int)$sh['id'] ?>"
                                data-color="<?= safeOutput($sh['color'] ?? '#6c757d') ?>"
                                data-start="<?= $sh['start_time'] ? date('H:i', strtotime($sh['start_time'])) : '' ?>"
                                data-end="<?= $sh['end_time'] ? date('H:i', strtotime($sh['end_time'])) : '' ?>">
                            <?= safeOutput($sh['name']) ?>
                            <?= $sh['start_time'] ? '(' . date('H:i', strtotime($sh['start_time'])) . '–' . date('H:i', strtotime($sh['end_time'])) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mt-2">
                    <label class="form-label small"><?= safeOutput($LANG['notes']) ?></label>
                    <input type="text" id="dayNotes" class="form-control form-control-sm" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveDaySchedule()">
                    <i class="bi bi-check-circle me-1"></i><?= safeOutput($LANG['save']) ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Schedule Modal -->
<?php if (canManageShiftSchedules()): ?>
<div class="modal fade" id="bulkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar-plus text-success me-2"></i><?= safeOutput($LANG['bulk_schedule']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['employee']) ?> <span class="text-danger">*</span></label>
                    <select id="bulkEmpId" class="form-select">
                        <option value=""><?= safeOutput($LANG['select_employee']) ?></option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int)$emp['id'] ?>" <?= $filterEmp === (int)$emp['id'] ? 'selected' : '' ?>>
                            <?= safeOutput($emp['full_name']) ?> (<?= safeOutput($emp['employee_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['start_date']) ?> <span class="text-danger">*</span></label>
                        <input type="date" id="bulkStart" class="form-control" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['end_date']) ?> <span class="text-danger">*</span></label>
                        <input type="date" id="bulkEnd" class="form-control" value="<?= date('Y-m-t') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="bulkIsOff" onchange="toggleBulkOff(this)">
                        <label class="form-check-label text-danger" for="bulkIsOff"><i class="bi bi-calendar-x me-1"></i><?= safeOutput($LANG['mark_as_day_off']) ?></label>
                    </div>
                </div>
                <div id="bulkShiftWrap" class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['shift']) ?> <span class="text-danger">*</span></label>
                    <select id="bulkShiftId" class="form-select">
                        <option value=""><?= safeOutput($LANG['select_shift']) ?></option>
                        <?php foreach ($allShifts as $sh): ?>
                        <option value="<?= (int)$sh['id'] ?>">
                            <?= safeOutput($sh['name']) ?>
                            <?= $sh['start_time'] ? '(' . date('H:i', strtotime($sh['start_time'])) . '–' . date('H:i', strtotime($sh['end_time'])) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['skip_days']) ?></label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        $dowNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                        foreach ($dowNames as $di => $dn):
                        ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="skip_dow[]" id="skip<?= $di ?>" value="<?= $di ?>"
                                   <?= $di === 0 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="skip<?= $di ?>"><?= $dn ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="form-label small"><?= safeOutput($LANG['notes']) ?></label>
                    <input type="text" id="bulkNotes" class="form-control form-control-sm" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?>
                </button>
                <button type="button" class="btn btn-sm btn-success" onclick="saveBulkSchedule()">
                    <i class="bi bi-calendar-check me-1"></i><?= safeOutput($LANG['apply']) ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    <?= safeOutput($LANG['select_employee_to_view_schedule']) ?>
</div>
<?php endif; ?>

<script>
const CSRF  = '<?= $_SESSION['csrf_token'] ?>';
const EMPID = <?= $filterEmp > 0 ? $filterEmp : 0 ?>;
let _activeDayEl = null;
let _activeDate  = null;

function openDayModal(date, el) {
    <?php if (!canManageShiftSchedules()): ?>
    return;
    <?php endif; ?>
    _activeDayEl = el;
    _activeDate  = date;
    const isOff    = el.dataset.isOff == '1';
    const shiftId  = el.dataset.shiftId;
    document.getElementById('dayModalTitle').textContent = '<?php echo substr(bltx_js($LANG['schedule_for']), 1, -1) ?> ' + date;
    document.getElementById('dayIsOff').checked = isOff;
    document.getElementById('dayShiftId').value = shiftId || '';
    document.getElementById('dayNotes').value = '';
    document.getElementById('shiftSelectWrap').style.display = isOff ? 'none' : '';
    new bootstrap.Modal(document.getElementById('dayModal')).show();
}

function toggleDayOff(cb) {
    document.getElementById('shiftSelectWrap').style.display = cb.checked ? 'none' : '';
}

function toggleBulkOff(cb) {
    document.getElementById('bulkShiftWrap').style.display = cb.checked ? 'none' : '';
}

function saveDaySchedule() {
    if (!_activeDate) return;
    const isOff   = document.getElementById('dayIsOff').checked ? 1 : 0;
    const shiftId = document.getElementById('dayShiftId').value;
    const notes   = document.getElementById('dayNotes').value;
    if (!isOff && !shiftId) {
        Swal.fire({ icon: 'warning', title: <?php echo bltx_js($LANG['warning']) ?>, text: <?php echo bltx_js($LANG['select_shift']) ?> });
        return;
    }
    $.ajax({
        url: '<?= url('payroll/shift_schedules.php') ?>',
        type: 'POST',
        data: { ajax: 'save', csrf_token: CSRF, employee_id: EMPID, schedule_date: _activeDate, shift_id: shiftId, is_off: isOff ? '1' : '', notes: notes },
        dataType: 'json',
        success: function(r) {
            if (r.success) {
                bootstrap.Modal.getInstance(document.getElementById('dayModal')).hide();
                location.reload();
            } else {
                Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: r.message });
            }
        },
        error: function() { Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: <?php echo bltx_js($LANG['network_error']) ?> }); }
    });
}

function clearSchedule(date) {
    Swal.fire({
        title: <?php echo bltx_js($LANG['confirm']) ?>,
        text: '<?php echo substr(bltx_js($LANG['clear_schedule_confirm']), 1, -1) ?> ' + date + '?',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonText: <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText: <?php echo bltx_js($LANG['clear']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= url('payroll/shift_schedules.php') ?>',
            type: 'POST',
            data: { ajax: 'delete', csrf_token: CSRF, employee_id: EMPID, schedule_date: date },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) location.reload();
                else Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: resp.message || <?php echo bltx_js($LANG['error']) ?> });
            }
        });
    });
}

function saveBulkSchedule() {
    const empId   = document.getElementById('bulkEmpId').value;
    const start   = document.getElementById('bulkStart').value;
    const end     = document.getElementById('bulkEnd').value;
    const isOff   = document.getElementById('bulkIsOff').checked ? 1 : 0;
    const shiftId = document.getElementById('bulkShiftId').value;
    const notes   = document.getElementById('bulkNotes').value;
    const skipDow = [...document.querySelectorAll('input[name="skip_dow[]"]:checked')].map(c => c.value);

    if (!empId || !start || !end || (!isOff && !shiftId)) {
        Swal.fire({ icon: 'warning', title: <?php echo bltx_js($LANG['warning']) ?>, text: <?php echo bltx_js($LANG['fill_required_fields']) ?> });
        return;
    }
    $.ajax({
        url: '<?= url('payroll/shift_schedules.php') ?>',
        type: 'POST',
        data: { ajax: 'bulk_save', csrf_token: CSRF, employee_id: empId, shift_id: shiftId, start_date: start, end_date: end, is_off: isOff ? '1' : '', notes: notes, 'skip_dow[]': skipDow },
        dataType: 'json',
        success: function(r) {
            if (r.success) {
                bootstrap.Modal.getInstance(document.getElementById('bulkModal')).hide();
                Swal.fire({ icon: 'success', title: <?php echo bltx_js($LANG['success']) ?>, text: r.message, timer: 2000, showConfirmButton: false })
                    .then(function() { location.href = '<?= payrollGateUrl('shift_schedules') ?>&employee_id=' + empId + '&month=<?= $filterMonth ?>&year=<?= $filterYear ?>'; });
            } else {
                Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: r.message });
            }
        },
        error: function() { Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: <?php echo bltx_js($LANG['network_error']) ?> }); }
    });
}
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
