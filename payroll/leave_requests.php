<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "leave_requests" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('leave_requests');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/leave_requests.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
requireLeaveRequestsAccess();

$page_title = $LANG['leave_requests'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ───────────────────────────────────────────────────────────
   AJAX DELETE
─────────────────────────────────────────────────────────── */
if (isset($_POST['ajax_delete']) && $_POST['ajax_delete'] === 'true') {
    ob_clean(); header('Content-Type: application/json');
    if (!canDeleteLeaveRequest()) { echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>$LANG['invalid_csrf']]); exit;
    }
    $delId = (int)($_POST['id'] ?? 0);
    echo json_encode($delId > 0 ? leaveRequestDelete($conn, $delId, $LANG) : ['success'=>false,'message'=>'Invalid request']);
    exit;
}

/* ───────────────────────────────────────────────────────────
   AJAX APPROVE
─────────────────────────────────────────────────────────── */
if (isset($_POST['ajax_approve']) && $_POST['ajax_approve'] === 'true') {
    ob_clean(); header('Content-Type: application/json');
    if (!canApproveLeaveRequest()) { echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>$LANG['invalid_csrf']]); exit;
    }
    $apvId = (int)($_POST['id'] ?? 0);
    echo json_encode($apvId > 0 ? leaveRequestApprove($conn, $apvId, (int)$_SESSION['user_id'], $LANG) : ['success'=>false,'message'=>'Invalid request']);
    exit;
}

/* ───────────────────────────────────────────────────────────
   AJAX REJECT
─────────────────────────────────────────────────────────── */
if (isset($_POST['ajax_reject']) && $_POST['ajax_reject'] === 'true') {
    ob_clean(); header('Content-Type: application/json');
    if (!canApproveLeaveRequest()) { echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>$LANG['invalid_csrf']]); exit;
    }
    $rjtId     = (int)($_POST['id'] ?? 0);
    $rjtReason = trim($_POST['rejection_reason'] ?? '');
    echo json_encode($rjtId > 0 ? leaveRequestReject($conn, $rjtId, (int)$_SESSION['user_id'], $rjtReason, $LANG) : ['success'=>false,'message'=>'Invalid request']);
    exit;
}

/* ───────────────────────────────────────────────────────────
   POST — ADD / EDIT
─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['ajax_delete'])
    && !isset($_POST['ajax_approve'])
    && !isset($_POST['ajax_reject'])
) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(payrollGateUrl('leave_requests'));
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add' && canCreateLeaveRequest()) {
        $result = leaveRequestSave($conn, $_POST, $LANG);
        if ($result['success']) { bltx_flash_success($result['message']); } else { bltx_flash_error($result['message']); }
    }
    if ($action === 'edit' && canApproveLeaveRequest()) {
        $result = leaveRequestSave($conn, $_POST, $LANG);
        if ($result['success']) { bltx_flash_success($result['message']); } else { bltx_flash_error($result['message']); }
    }
    /* Filter pada query string dipertahankan, tetapi ?tab= milik hub tidak boleh
       ikut terbawa dua kali — karena itu tab dipasang lebih dulu lalu sisa filter
       ditempel di belakangnya. */
    $__qs = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($__qs, $__q); unset($__q['tab']);
    $__extra = http_build_query($__q);
    redirect(payrollGateUrl('leave_requests') . ($__extra !== '' ? '&' . $__extra : ''));
}

/* ───────────────────────────────────────────────────────────
   DATA
─────────────────────────────────────────────────────────── */
$filterEmp    = (int)($_GET['employee_id'] ?? 0);
$filterStatus = trim($_GET['status']     ?? '');
$filterType   = trim($_GET['leave_type'] ?? '');
$filterMonth  = trim($_GET['month']      ?? '');
$search       = trim($_GET['search']     ?? '');

$result = leaveRequestGetList($conn, $filterEmp, $filterStatus, $filterType, $filterMonth, $search, 99999, 0);
$leaves    = $result['data'];
$employees = getActiveEmployees($conn);

$leaveTypeLabels = [
    'annual'    => $LANG['leave_type_annual'],
    'sick'      => $LANG['leave_type_sick'],
    'personal'  => $LANG['leave_type_personal'],
    'maternity' => $LANG['leave_type_maternity'],
    'paternity' => $LANG['leave_type_paternity'],
    'other'     => $LANG['leave_type_other'],
];
$leaveTypeBadge = [
    'annual'=>'primary','sick'=>'danger','personal'=>'info',
    'maternity'=>'success','paternity'=>'warning','other'=>'secondary',
];

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('leave_requests'); }
?>

<link rel="stylesheet" href="<?= url('assets/vendor/datatables/dataTables.bootstrap5.min.css') ?>">

<?php
/* RONDE 2 — notifikasi hasil kini TOAST MELAYANG, sama seperti purchases/.

   Sebelumnya: modal SweetAlert2 yang menutupi layar dan (untuk pesan gagal)
   harus ditutup manual. Itu juga melanggar aturan A4.1/B2 poin 5 — SweetAlert2
   hanya untuk dialog KONFIRMASI, bukan notifikasi hasil.

   Kunci sesi ikut pindah dari swal_success/swal_error ke success/error, yaitu
   kunci bersama yang dipakai seluruh sistem sejak Ronde B. Setter di berkas ini
   sudah diubah bersamaan; tidak ada pembaca lain yang tertinggal.

   SweetAlert2 tetap dipakai di berkas ini untuk konfirmasi hapus — itu
   pemakaian yang benar dan sengaja dipertahankan. */
bltx_render_flash('hris');
?>

<!-- Header -->
<div class="page-title-bar">
    <h2 class="page-title-text">
        <i class="bi bi-file-earmark-check-fill text-primary"></i>
        <?= safeOutput($LANG['leave_requests']) ?>
    </h2>
    <div class="page-actions">
        <a href="<?= url('payroll/leave_balance.php') ?>" class="btn btn-sm btn-info">
            <i class="bi bi-calendar2-week"></i><?= safeOutput($LANG['leave_balance']) ?>
        </a>
        <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
        <?php if (canCreateLeaveRequest()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i><?= safeOutput($LANG['add_leave_request']) ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['employee']) ?></label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value=""><?= safeOutput($LANG['all_employees']) ?></option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>" <?= $filterEmp == $emp['id'] ? 'selected' : '' ?>>
                        <?= safeOutput($emp['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['leave_type']) ?></label>
                <select name="leave_type" class="form-select form-select-sm">
                    <option value=""><?= safeOutput($LANG['all']) ?></option>
                    <?php foreach ($leaveTypeLabels as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= safeOutput($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['status']) ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?= safeOutput($LANG['all']) ?></option>
                    <?php foreach (['pending','approved','rejected'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['month']) ?></label>
                <input type="month" name="month" class="form-control form-control-sm" value="<?= safeOutput($filterMonth) ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="bi bi-search me-1"></i><?= safeOutput($LANG['filter']) ?>
                </button>
                <a href="<?= payrollGateUrl('leave_requests') ?>" class="btn btn-secondary btn-sm">
                    <i class="bi bi-x"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="leaveTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px"><?= safeOutput($LANG['col_no']) ?></th>
                        <th><?= safeOutput($LANG['employee']) ?></th>
                        <th><?= safeOutput($LANG['leave_type']) ?></th>
                        <th><?= safeOutput($LANG['start_date']) ?></th>
                        <th><?= safeOutput($LANG['end_date']) ?></th>
                        <th class="text-center" style="width:70px"><?= safeOutput($LANG['days']) ?></th>
                        <th class="text-center" style="width:100px"><?= safeOutput($LANG['status']) ?></th>
                        <th class="text-center" style="width:130px"><?= safeOutput($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($leaves)): $i = 1; foreach ($leaves as $lr):
                    $statusBg  = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$lr['status']] ?? 'secondary';
                    $typeBadge = $leaveTypeBadge[$lr['leave_type']] ?? 'secondary';
                    $typeLabel = $leaveTypeLabels[$lr['leave_type']] ?? ucfirst($lr['leave_type']);
                ?>
                    <tr>
                        <td class="text-muted small"><?= $i++ ?></td>
                        <td>
                            <div class="fw-semibold small"><?= safeOutput($lr['full_name']) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= safeOutput($lr['employee_code']) ?></div>
                        </td>
                        <td><span class="badge bg-<?= $typeBadge ?>"><?= safeOutput($typeLabel) ?></span></td>
                        <td><?= date('d M Y', strtotime($lr['start_date'])) ?></td>
                        <td><?= date('d M Y', strtotime($lr['end_date'])) ?></td>
                        <td class="text-center">
                            <span class="badge bg-primary rounded-pill"><?= (int)$lr['total_days'] ?> <?= safeOutput($LANG['days_abbr']) ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $statusBg ?>"><?= safeOutput(ucfirst($lr['status'])) ?></span>
                            <?php if ($lr['status'] === 'rejected' && !empty($lr['rejection_reason'])): ?>
                            <button class="btn btn-link btn-sm p-0 ms-1" title="<?= safeOutput($lr['rejection_reason']) ?>" data-bs-toggle="tooltip">
                                <i class="bi bi-info-circle text-danger" style="font-size:.75rem"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <?php if ($lr['status'] === 'pending' && canApproveLeaveRequest()): ?>
                                <button class="btn btn-success btn-sm" onclick="approveLeave(<?= (int)$lr['id'] ?>)" title="<?= safeOutput($LANG['approve']) ?>">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                                <button class="btn btn-warning btn-sm" onclick="rejectLeave(<?= (int)$lr['id'] ?>)" title="<?= safeOutput($LANG['reject']) ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($lr['status'] === 'pending' && canApproveLeaveRequest()): ?>
                                <button class="btn btn-primary btn-sm" onclick="editLeave(<?= htmlspecialchars(json_encode($lr), ENT_QUOTES, 'UTF-8') ?>)" title="<?= safeOutput($LANG['edit']) ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($lr['status'] === 'pending' && canDeleteLeaveRequest()): ?>
                                <button class="btn btn-danger btn-sm" onclick="deleteLeave(<?= (int)$lr['id'] ?>, <?= bltx_e(bltx_js($lr['full_name'])) ?>)" title="<?= safeOutput($LANG['delete']) ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD MODAL -->
<?php if (canCreateLeaveRequest()): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/leave_requests.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle text-primary me-2"></i><?= safeOutput($LANG['add_leave_request']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['employee']) ?> <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select" required>
                            <option value=""><?= safeOutput($LANG['select_employee']) ?></option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int)$emp['id'] ?>"><?= safeOutput($emp['full_name']) ?> (<?= safeOutput($emp['employee_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['leave_type']) ?> <span class="text-danger">*</span></label>
                        <select name="leave_type" class="form-select" required>
                            <?php foreach ($leaveTypeLabels as $key => $label): ?>
                            <option value="<?= $key ?>"><?= safeOutput($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['start_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['end_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['status']) ?></label>
                        <select name="status" class="form-select">
                            <option value="pending"><?= safeOutput($LANG['pending']) ?></option>
                            <option value="approved"><?= safeOutput($LANG['approved']) ?></option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['reason']) ?></label>
                        <textarea name="reason" class="form-control" rows="3" maxlength="1000" placeholder="<?= safeOutput($LANG['optional']) ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?></button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-circle me-1"></i><?= safeOutput($LANG['save']) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- EDIT MODAL -->
<?php if (canApproveLeaveRequest()): ?>
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/leave_requests.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square text-warning me-2"></i><?= safeOutput($LANG['edit']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['leave_type']) ?></label>
                        <select name="leave_type" id="editType" class="form-select">
                            <?php foreach ($leaveTypeLabels as $key => $label): ?>
                            <option value="<?= $key ?>"><?= safeOutput($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['start_date']) ?></label>
                            <input type="date" name="start_date" id="editStart" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['end_date']) ?></label>
                            <input type="date" name="end_date" id="editEnd" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['status']) ?></label>
                        <select name="status" id="editStatus" class="form-select">
                            <option value="pending"><?= htmlspecialchars($LANG['pending']) ?></option>
                            <option value="approved"><?= htmlspecialchars($LANG['approved']) ?></option>
                            <option value="rejected"><?= htmlspecialchars($LANG['rejected']) ?></option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['reason']) ?></label>
                        <textarea name="reason" id="editReason" class="form-control" rows="3" maxlength="1000"></textarea>
                    </div>
                    <input type="hidden" name="employee_id" id="editEmpId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?></button>
                    <button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-check-circle me-1"></i><?= safeOutput($LANG['update']) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reject Modal -->
<?php if (canApproveLeaveRequest()): ?>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i><?= safeOutput($LANG['reject']) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold"><?= safeOutput($LANG['rejection_reason']) ?></label>
                <textarea id="rejectReason" class="form-control" rows="3" maxlength="500" placeholder="<?= safeOutput($LANG['optional']) ?>"></textarea>
                <input type="hidden" id="rejectId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?></button>
                <button type="button" class="btn btn-danger btn-sm" onclick="submitReject()"><i class="bi bi-check me-1"></i><?= safeOutput($LANG['reject']) ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#leaveTable')) {
        $('#leaveTable').DataTable({
            pageLength: <?= (int)(getSetting('pagination', 10)) ?>,
            order: [[3, 'desc']],
            columnDefs: [{ orderable: false, targets: [0, 7] }],
            language: { search:'<?php echo substr(bltx_js($LANG['search']), 1, -1) ?>:', emptyTable:<?php echo bltx_js($LANG['no_data']) ?> }
        });
    }
    // Bootstrap tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
});

function approveLeave(id) {
    Swal.fire({
        title: <?php echo bltx_js($LANG['confirm']) ?>,
        text: <?php echo bltx_js($LANG['confirm_approve']) ?>,
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonText: <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText: <?php echo bltx_js($LANG['approve']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= url('payroll/leave_requests.php') ?>', { ajax_approve:'true', csrf_token:'<?= $_SESSION['csrf_token'] ?>', id:id }, function(resp) {
            if (resp.success) { Swal.fire({icon:'success',title:<?php echo bltx_js($LANG['success']) ?>,text:resp.message,timer:2000,showConfirmButton:false}).then(()=>location.reload()); }
            else { Swal.fire({icon:'error',title:<?php echo bltx_js($LANG['error']) ?>,text:resp.message}); }
        }, 'json');
    });
}

function rejectLeave(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function submitReject() {
    const id     = document.getElementById('rejectId').value;
    const reason = document.getElementById('rejectReason').value;
    $.post('<?= url('payroll/leave_requests.php') ?>', { ajax_reject:'true', csrf_token:'<?= $_SESSION['csrf_token'] ?>', id:id, rejection_reason:reason }, function(resp) {
        bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide();
        if (resp.success) { Swal.fire({icon:'success',title:<?php echo bltx_js($LANG['success']) ?>,text:resp.message,timer:2000,showConfirmButton:false}).then(()=>location.reload()); }
        else { Swal.fire({icon:'error',title:<?php echo bltx_js($LANG['error']) ?>,text:resp.message}); }
    }, 'json');
}

function deleteLeave(id, name) {
    Swal.fire({
        title: <?php echo bltx_js($LANG['confirm']) ?>,
        text: '<?php echo substr(bltx_js($LANG['delete']), 1, -1) ?> "' + name + '"?',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonText: <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText: <?php echo bltx_js($LANG['delete']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= url('payroll/leave_requests.php') ?>', { ajax_delete:'true', csrf_token:'<?= $_SESSION['csrf_token'] ?>', id:id }, function(resp) {
            if (resp.success) { Swal.fire({icon:'success',title:<?php echo bltx_js($LANG['success']) ?>,text:resp.message,timer:2000,showConfirmButton:false}).then(()=>location.reload()); }
            else { Swal.fire({icon:'error',title:<?php echo bltx_js($LANG['error']) ?>,text:resp.message}); }
        }, 'json');
    });
}

function editLeave(d) {
    document.getElementById('editId').value     = d.id;
    document.getElementById('editEmpId').value  = d.employee_id;
    document.getElementById('editType').value   = d.leave_type;
    document.getElementById('editStart').value  = d.start_date;
    document.getElementById('editEnd').value    = d.end_date;
    document.getElementById('editStatus').value = d.status;
    document.getElementById('editReason').value = d.reason || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
