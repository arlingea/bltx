<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "attendance" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('attendance');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/attendance.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

requireAttendanceAccess();

$page_title = $LANG['attendance'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Handle AJAX CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        if (!canCreateAttendance() && !canUpdateAttendance()) {
            echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit;
        }
        $empId     = (int)$_POST['employee_id'];
        $date      = $_POST['attendance_date'] ?? date('Y-m-d');
        $status    = $_POST['status'] ?? 'present';
        $checkIn   = !empty($_POST['check_in']) ? $_POST['check_in'] : null;
        $checkOut  = !empty($_POST['check_out']) ? $_POST['check_out'] : null;
        $shiftId   = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
        $overtime  = floatval($_POST['overtime_hours'] ?? 0);
        $notes     = $_POST['notes'] ?? '';

        // Calculate late — multi-shift aware: use scheduled shift for date, fallback to default
        $lateMin = 0;
        // Auto-detect shift if not provided: use per-date schedule or employee default
        if (!$shiftId) {
            $autoShift = getEmployeeShiftForDate($conn, $empId, $date);
            if ($autoShift) $shiftId = $autoShift['shift_id'] ?? null;
        }
        if ($shiftId && $checkIn) {
            $stShift = $conn->prepare("SELECT start_time, max_late_minutes FROM employee_shifts WHERE id = ?");
            $stShift->bind_param('i', $shiftId);
            $stShift->execute();
            $shiftRow = $stShift->get_result()->fetch_assoc();
            $stShift->close();
            if ($shiftRow) {
                $lateMin = calculateLateMinutes(
                    $shiftRow['start_time'],
                    $checkIn,
                    (int)($shiftRow['max_late_minutes'] ?? 0)
                );
            }
        }

        /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
           tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
        $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, shift_id, check_in, check_out, status, late_minutes, overtime_hours, notes, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,(SELECT full_name FROM users WHERE id = ?))
            ON DUPLICATE KEY UPDATE shift_id=VALUES(shift_id), check_in=VALUES(check_in), check_out=VALUES(check_out), status=VALUES(status), late_minutes=VALUES(late_minutes), overtime_hours=VALUES(overtime_hours), notes=VALUES(notes)");
        $stmt->bind_param('isiissidsii', $empId, $date, $shiftId, $checkIn, $checkOut, $status, $lateMin, $overtime, $notes, $_SESSION['user_id'], $_SESSION['user_id']);
        if ($stmt->execute()) {
            logActivity('attendance_save', "Attendance for emp $empId on $date: $status");
            echo json_encode(['success'=>true]); exit;
        }
        echo json_encode(['success'=>false,'message'=>$conn->error]); exit;
    }

    if ($action === 'delete') {
        if (!canDeleteAttendance()) {
            echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit;
        }
        $attId = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->bind_param('i', $attId);
        echo json_encode(['success'=>$stmt->execute()]); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// Filter vars
$filterEmp  = (int)($_GET['employee_id'] ?? 0);
$filterDate = $_GET['date'] ?? date('Y-m');
$filterStatus = $_GET['status'] ?? '';

// Build where
$where = '1=1'; $params = []; $types = '';
if ($filterEmp) { $where .= ' AND a.employee_id = ?'; $params[] = $filterEmp; $types .= 'i'; }
if ($filterDate) {
    if (strlen($filterDate) === 7) {
        $where .= ' AND DATE_FORMAT(a.attendance_date, "%Y-%m") = ?'; $params[] = $filterDate; $types .= 's';
    } else {
        $where .= ' AND a.attendance_date = ?'; $params[] = $filterDate; $types .= 's';
    }
}
if ($filterStatus) { $where .= ' AND a.status = ?'; $params[] = $filterStatus; $types .= 's'; }

$sql = "SELECT a.*, e.full_name, e.employee_code, es.name AS shift_name 
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.id 
        LEFT JOIN employee_shifts es ON a.shift_id = es.id 
        WHERE $where ORDER BY a.attendance_date DESC, e.full_name ASC LIMIT 500";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$attendances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$employees = getActiveEmployees($conn);
$shifts = $conn->query("SELECT id, name, start_time, end_time, color FROM employee_shifts WHERE is_active = 1 ORDER BY start_time ASC, name ASC")->fetch_all(MYSQLI_ASSOC);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('attendance'); }
?>
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-calendar-check-fill"></i><?= $LANG['attendance'] ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
        <button class="btn btn-sm btn-primary" id="btnAddAtt"><i class="bi bi-plus-circle"></i> <?= $LANG['add_attendance'] ?></button>
        <a href="<?= url('payroll/attendance_photo.php') ?>" class="btn btn-sm btn-success" target="_blank"><i class="bi bi-camera"></i> <?= $LANG['photo_attendance'] ?></a>
    </div>
</div>

<!-- Filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-sm-4">
                <label class="form-label small fw-semibold"><?= $LANG['employee'] ?></label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">-- <?= $LANG['all'] ?> --</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= ($filterEmp == $e['id']) ? 'selected' : '' ?>><?= safeOutput($e['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-3">
                <label class="form-label small fw-semibold"><?= $LANG['month_year'] ?></label>
                <input type="month" name="date" class="form-control form-control-sm" value="<?= safeOutput($filterDate) ?>">
            </div>
            <div class="col-12 col-sm-3">
                <label class="form-label small fw-semibold"><?= $LANG['status'] ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">-- <?= $LANG['all'] ?> --</option>
                    <?php foreach (['present','absent','permission','sick','holiday','leave'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($filterStatus===$s)?'selected':'' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search"></i></button>
                <a href="<?= payrollGateUrl('attendance') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Attendance Summary Cards -->
<?php if ($filterEmp && count($attendances) > 0): 
    $summary = ['present'=>0,'absent'=>0,'permission'=>0,'sick'=>0,'holiday'=>0,'leave'=>0,'late_total'=>0];
    foreach ($attendances as $a) {
        $summary[$a['status']] = ($summary[$a['status']] ?? 0) + 1;
        $summary['late_total'] += $a['late_minutes'];
    }
?>
<div class="row g-2 mb-4">
    <?php $sumItems = [['present','success'],['absent','danger'],['sick','info'],['permission','warning'],['leave','primary'],['holiday','secondary']]; foreach ($sumItems as [$s,$c]): ?>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm text-center p-2">
            <div class="text-<?= $c ?> fw-bold fs-4"><?= $summary[$s] ?></div>
            <div class="text-muted small"><?= ucfirst($s) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="attTable">
                <thead class="table-light">
                    <tr>
                        <th><?= $LANG['no'] ?></th>
                        <th><?= $LANG['date'] ?></th>
                        <th><?= $LANG['employee'] ?></th>
                        <th><?= $LANG['shift'] ?></th>
                        <th><?= $LANG['check_in'] ?></th>
                        <th><?= $LANG['check_out'] ?></th>
                        <th><?= $LANG['status'] ?></th>
                        <th><?= $LANG['late'] ?></th>
                        <th><?= $LANG['overtime'] ?></th>
                        <th><?= $LANG['actions'] ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($attendances)): ?>
                    <tr><td colspan="10" class="text-center py-4 text-muted"><?= $LANG['no_data'] ?></td></tr>
                <?php else: $i=1; foreach ($attendances as $a): 
                    $sc = ['present'=>'success','absent'=>'danger','permission'=>'warning','sick'=>'info','holiday'=>'secondary','leave'=>'primary'][$a['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= date('d M Y', strtotime($a['attendance_date'])) ?></td>
                        <td>
                            <div class="fw-semibold small"><?= safeOutput($a['full_name']) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= safeOutput($a['employee_code']) ?></div>
                        </td>
                        <td><?= safeOutput($a['shift_name'] ?? '-') ?></td>
                        <td><?= $a['check_in'] ? date('H:i', strtotime($a['check_in'])) : '-' ?></td>
                        <td><?= $a['check_out'] ? date('H:i', strtotime($a['check_out'])) : '-' ?></td>
                        <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($a['status']) ?></span></td>
                        <td><?= $a['late_minutes'] > 0 ? '<span class="text-danger">' . $a['late_minutes'] . ' min</span>' : '-' ?></td>
                        <td><?= $a['overtime_hours'] > 0 ? $a['overtime_hours'] . ' hrs' : '-' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-primary btn-edit-att" 
                                    data-id="<?= $a['id'] ?>"
                                    data-emp="<?= $a['employee_id'] ?>"
                                    data-date="<?= $a['attendance_date'] ?>"
                                    data-shift="<?= $a['shift_id'] ?>"
                                    data-cin="<?= $a['check_in'] ?>"
                                    data-cout="<?= $a['check_out'] ?>"
                                    data-status="<?= $a['status'] ?>"
                                    data-ot="<?= $a['overtime_hours'] ?>"
                                    data-notes="<?= safeOutput($a['notes'] ?? '') ?>"
                                    title="<?= $LANG['edit'] ?>"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-danger btn-del-att" data-id="<?= $a['id'] ?>" title="<?= $LANG['delete'] ?>"><i class="bi bi-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="attModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="attModalTitle"><i class="bi bi-calendar-check me-2"></i><?= $LANG['attendance'] ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="attId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['employee'] ?> *</label>
                        <select id="attEmp" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= safeOutput($e['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['date'] ?> *</label>
                        <input type="date" id="attDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['shift'] ?></label>
                        <select id="attShift" class="form-select">
                            <option value="">-- No Shift --</option>
                            <?php foreach ($shifts as $s): ?>
                            <option value="<?= $s['id'] ?>"
                                    data-start="<?= $s['start_time'] ? date('H:i', strtotime($s['start_time'])) : '' ?>"
                                    data-color="<?= safeOutput($s['color'] ?? '#6c757d') ?>">
                                <?= safeOutput($s['name']) ?>
                                <?= $s['start_time'] ? '(' . date('H:i', strtotime($s['start_time'])) . '–' . date('H:i', strtotime($s['end_time'])) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['status'] ?> *</label>
                        <select id="attStatus" class="form-select">
                            <?php foreach (['present'=>'Present (Hadir)','absent'=>'Absent (Alpha)','permission'=>'Permission (Izin)','sick'=>'Sick (Sakit)','holiday'=>'Holiday (Libur)','leave'=>'Leave (Cuti)'] as $v=>$l): ?>
                            <option value="<?= $v ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['check_in'] ?></label>
                        <input type="time" id="attCin" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['check_out'] ?></label>
                        <input type="time" id="attCout" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['overtime_hours'] ?></label>
                        <input type="number" id="attOT" class="form-control" value="0" min="0" step="0.5">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['notes'] ?></label>
                        <textarea id="attNotes" class="form-control" rows="2" placeholder="<?= $LANG['notes'] ?>..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> <?= $LANG['cancel'] ?></button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSaveAtt"><i class="bi bi-check-lg"></i> <?= $LANG['save'] ?></button>
            </div>
        </div>
    </div>
</div>

<script>
var csrf = '<?= $_SESSION['csrf_token'] ?>';
var attModal = new bootstrap.Modal(document.getElementById('attModal'));

var btnAddAtt = document.getElementById('btnAddAtt');
if (btnAddAtt) {
    btnAddAtt.addEventListener('click', function() {
        document.getElementById('attModalTitle').innerHTML = '<i class="bi bi-calendar-plus me-2"></i><?= $LANG['add_attendance'] ?>';
        document.getElementById('attId').value = '';
        document.getElementById('attEmp').value = '<?= $filterEmp ?: '' ?>';
        document.getElementById('attDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('attShift').value = '';
        document.getElementById('attStatus').value = 'present';
        document.getElementById('attCin').value = '';
        document.getElementById('attCout').value = '';
        document.getElementById('attOT').value = '0';
        document.getElementById('attNotes').value = '';
        attModal.show();
    });
}

document.querySelectorAll('.btn-edit-att').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('attModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i><?= $LANG['edit'] ?> <?= $LANG['attendance'] ?>';
        document.getElementById('attId').value = this.dataset.id;
        document.getElementById('attEmp').value = this.dataset.emp;
        document.getElementById('attDate').value = this.dataset.date;
        document.getElementById('attShift').value = this.dataset.shift;
        document.getElementById('attStatus').value = this.dataset.status;
        document.getElementById('attCin').value = this.dataset.cin ? this.dataset.cin.substring(0,5) : '';
        document.getElementById('attCout').value = this.dataset.cout ? this.dataset.cout.substring(0,5) : '';
        document.getElementById('attOT').value = this.dataset.ot;
        document.getElementById('attNotes').value = this.dataset.notes;
        attModal.show();
    });
});

document.getElementById('btnSaveAtt').addEventListener('click', function() {
    var empId = document.getElementById('attEmp').value;
    var date  = document.getElementById('attDate').value;
    if (!empId || !date) { Swal.fire('<?= $LANG['error'] ?>', 'Employee and Date are required', 'error'); return; }
    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('action', 'save');
    fd.append('csrf_token', csrf);
    fd.append('employee_id', empId);
    fd.append('attendance_date', date);
    fd.append('shift_id', document.getElementById('attShift').value);
    fd.append('status', document.getElementById('attStatus').value);
    fd.append('check_in', document.getElementById('attCin').value);
    fd.append('check_out', document.getElementById('attCout').value);
    fd.append('overtime_hours', document.getElementById('attOT').value);
    fd.append('notes', document.getElementById('attNotes').value);
    fetch('<?= url('payroll/attendance.php') ?>', { method: 'POST', body: fd })
        .then(r => r.json()).then(function(res) {
            if (res.success) {
                attModal.hide();
                Swal.fire('<?= $LANG['success'] ?>', '<?= $LANG['save_success'] ?>', 'success').then(() => location.reload());
            } else {
                Swal.fire('<?= $LANG['error'] ?>', res.message || 'Error saving', 'error');
            }
        });
});

document.querySelectorAll('.btn-del-att').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.dataset.id;
        Swal.fire({ title: '<?= $LANG['are_you_sure'] ?>', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
        .then(function(r) {
            if (r.isConfirmed) {
                var fd = new FormData();
                fd.append('ajax','1'); fd.append('action','delete'); fd.append('csrf_token',csrf); fd.append('id',id);
                fetch('<?= url('payroll/attendance.php') ?>', { method:'POST', body: fd })
                    .then(r => r.json()).then(function(res) {
                        if (res.success) Swal.fire('<?= $LANG['success'] ?>','<?= $LANG['delete_success'] ?>','success').then(()=>location.reload());
                        else Swal.fire('<?= $LANG['error'] ?>','Delete failed','error');
                    });
            }
        });
    });
});
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>