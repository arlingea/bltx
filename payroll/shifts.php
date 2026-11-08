<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "shifts" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('shifts');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/shifts.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
if (!checkPermission('shifts', 'can_access')) redirectNoAccess();

$page_title = $LANG['shifts'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ───────────────────────────────────────────────────────────
   AJAX: Toggle Status
─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_toggle_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!checkPermission('shifts', 'can_update')) {
        echo json_encode(['success' => false, 'message' => $LANG['no_permission']]); exit;
    }
    $togId     = (int)($_POST['id']        ?? 0);
    $newStatus = (int)($_POST['is_active'] ?? 0);
    if ($togId > 0) {
        echo json_encode(shiftToggleStatus($conn, $togId, $newStatus));
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
    }
    exit;
}

/* ───────────────────────────────────────────────────────────
   AJAX: Delete
─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!checkPermission('shifts', 'can_delete')) {
        echo json_encode(['success' => false, 'message' => $LANG['no_permission']]); exit;
    }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_csrf']]); exit;
    }
    $delId = (int)($_POST['id'] ?? 0);
    echo json_encode($delId > 0 ? shiftDelete($conn, $delId, $LANG) : ['success' => false, 'message' => 'Invalid request']);
    exit;
}

/* ───────────────────────────────────────────────────────────
   POST: Add / Edit
─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_toggle_status']) && !isset($_POST['ajax_delete'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(payrollGateUrl('shifts'));
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add' && checkPermission('shifts', 'can_create')) {
        $result = shiftSave($conn, 'add', $_POST, $LANG);
        if ($result['success']) { bltx_flash_success($result['message']); } else { bltx_flash_error($result['message']); }
        redirect(payrollGateUrl('shifts'));
    }

    if ($action === 'edit' && checkPermission('shifts', 'can_update')) {
        $result = shiftSave($conn, 'edit', $_POST, $LANG);
        if ($result['success']) { bltx_flash_success($result['message']); } else { bltx_flash_error($result['message']); }
        redirect(payrollGateUrl('shifts'));
    }
    redirect(payrollGateUrl('shifts'));
}

/* ───────────────────────────────────────────────────────────
   DATA
─────────────────────────────────────────────────────────── */
$stList = $conn->prepare(
    "SELECT s.id, s.name, s.code, s.start_time, s.end_time,
            s.break_minutes, s.cross_midnight, s.max_late_minutes, s.color,
            s.description, s.is_active,
            COUNT(e.id) AS emp_count
     FROM employee_shifts s
     LEFT JOIN employees e ON e.shift_id = s.id
     GROUP BY s.id, s.name, s.code, s.start_time, s.end_time,
              s.break_minutes, s.cross_midnight, s.max_late_minutes, s.color,
              s.description, s.is_active
     ORDER BY s.start_time ASC, s.name ASC"
);
$stList->execute();
$shifts = $stList->get_result()->fetch_all(MYSQLI_ASSOC);
$stList->close();

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('shifts'); }
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

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-clock-fill text-warning"></i><?= safeOutput($LANG['shifts']) ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
        <?php if (checkPermission('shift_schedules', 'can_access')): ?>
        <a href="<?= payrollGateUrl('shift_schedules') ?>" class="btn btn-sm btn-info">
            <i class="bi bi-calendar3"></i><?= safeOutput($LANG['shift_schedules']) ?>
        </a>
        <?php endif; ?>
        <?php if (checkPermission('shifts', 'can_create')): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i><?= safeOutput($LANG['add_shift']) ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="shiftsTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px"><?= safeOutput($LANG['col_no']) ?></th>
                        <th><?= safeOutput($LANG['shift_name']) ?></th>
                        <th><?= safeOutput($LANG['shift_code']) ?></th>
                        <th class="d-none d-md-table-cell"><?= safeOutput($LANG['start_time']) ?></th>
                        <th class="d-none d-md-table-cell"><?= safeOutput($LANG['end_time']) ?></th>
                        <th class="d-none d-lg-table-cell text-center"><?= safeOutput($LANG['break']) ?></th>
                        <th class="d-none d-xl-table-cell text-center"><?= safeOutput($LANG['cross_midnight']) ?></th>
                        <th style="width:90px" class="text-center"><?= safeOutput($LANG['employees_count']) ?></th>
                        <th style="width:110px" class="text-center"><?= safeOutput($LANG['status']) ?></th>
                        <?php if (checkPermission('shifts', 'can_update') || checkPermission('shifts', 'can_delete')): ?>
                        <th style="width:110px" class="text-center"><?= safeOutput($LANG['actions']) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($shifts)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i><?= safeOutput($LANG['no_data']) ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($shifts as $i => $s): ?>
                    <tr>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td>
                            <span class="d-inline-flex align-items-center gap-2">
                                <span class="rounded-circle border" style="width:12px;height:12px;background:<?= safeOutput($s['color'] ?? '#6c757d') ?>;flex-shrink:0"></span>
                                <span class="fw-semibold"><?= safeOutput($s['name']) ?></span>
                            </span>
                            <?php if (!empty($s['description'])): ?>
                            <div class="text-muted small text-truncate" style="max-width:200px"><?= safeOutput($s['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary"><?= safeOutput($s['code']) ?></span></td>
                        <td class="d-none d-md-table-cell"><?= $s['start_time'] ? date('H:i', strtotime($s['start_time'])) : '—' ?></td>
                        <td class="d-none d-md-table-cell"><?= $s['end_time'] ? date('H:i', strtotime($s['end_time'])) : '—' ?></td>
                        <td class="d-none d-lg-table-cell text-center small"><?= (int)$s['break_minutes'] > 0 ? (int)$s['break_minutes'] . ' min' : '—' ?></td>
                        <td class="d-none d-xl-table-cell text-center">
                            <?php if ($s['cross_midnight']): ?>
                            <span class="badge bg-dark"><i class="bi bi-moon-stars"></i></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge bg-primary rounded-pill"><?= (int)$s['emp_count'] ?></span></td>
                        <td class="text-center">
                            <?php if (checkPermission('shifts', 'can_update')): ?>
                            <div class="form-check form-switch d-flex justify-content-center mb-0">
                                <input type="checkbox" class="form-check-input status-toggle" role="switch"
                                       data-id="<?= (int)$s['id'] ?>"
                                       <?= $s['is_active'] ? 'checked' : '' ?>
                                       title="<?= safeOutput($LANG['toggle_status']) ?>">
                            </div>
                            <?php else: ?>
                                <span class="badge bg-<?= $s['is_active'] ? 'success' : 'secondary' ?>">
                                    <?= safeOutput($s['is_active'] ? ($LANG['active']) : ($LANG['inactive'])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php if (checkPermission('shifts', 'can_update') || checkPermission('shifts', 'can_delete')): ?>
                        <td class="text-center">
                            <?php if (checkPermission('shifts', 'can_update')): ?>
                            <button type="button" class="btn btn-warning btn-sm"
                                    data-rel='<?= htmlspecialchars(json_encode([
                                        'id'               => $s['id'],
                                        'name'             => $s['name'],
                                        'code'             => $s['code'],
                                        'start_time'       => $s['start_time'] ? date('H:i', strtotime($s['start_time'])) : '',
                                        'end_time'         => $s['end_time']   ? date('H:i', strtotime($s['end_time']))   : '',
                                        'break_minutes'    => (int)$s['break_minutes'],
                                        'cross_midnight'   => (int)$s['cross_midnight'],
                                        'max_late_minutes' => (int)$s['max_late_minutes'],
                                        'color'            => $s['color']       ?? '#6c757d',
                                        'description'      => $s['description'] ?? '',
                                        'is_active'        => (int)$s['is_active'],
                                    ]), ENT_QUOTES, 'UTF-8') ?>'
                                    onclick="openEditModal(this)"
                                    title="<?= safeOutput($LANG['edit']) ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (checkPermission('shifts', 'can_delete')): ?>
                            <button type="button" class="btn btn-danger btn-sm ms-1"
                                    onclick="confirmDeleteShift(<?= (int)$s['id'] ?>, <?= bltx_e(bltx_js($s['name'])) ?>)"
                                    title="<?= safeOutput($LANG['delete']) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php /* ── ADD MODAL ── */ if (checkPermission('shifts', 'can_create')): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/shifts.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle text-primary me-2"></i><?= safeOutput($LANG['add_shift']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['shift_name']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="50" autofocus placeholder="<?= htmlspecialchars($LANG['eg_shift_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['shift_code']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control" required maxlength="20" placeholder="<?= htmlspecialchars($LANG['eg_shift_code']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['start_time']) ?></label>
                            <input type="time" name="start_time" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['end_time']) ?></label>
                            <input type="time" name="end_time" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['break_minutes']) ?></label>
                            <input type="number" name="break_minutes" class="form-control" min="0" max="240" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['max_late_minutes']) ?></label>
                            <input type="number" name="max_late_minutes" class="form-control" min="0" max="60" value="15">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['shift_color']) ?></label>
                            <input type="color" name="color" class="form-control form-control-color w-100" value="#6c757d">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['description']) ?></label>
                            <textarea name="description" class="form-control" rows="2" maxlength="255" placeholder="<?= safeOutput($LANG['optional']) ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="cross_midnight" id="addCrossMid" value="1">
                                <label class="form-check-label" for="addCrossMid">
                                    <i class="bi bi-moon-stars me-1"></i><?= safeOutput($LANG['cross_midnight']) ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="addActive" value="1" checked>
                                <label class="form-check-label" for="addActive"><?= safeOutput($LANG['active']) ?></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-circle me-1"></i><?= safeOutput($LANG['save']) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php /* ── EDIT MODAL ── */ if (checkPermission('shifts', 'can_update')): ?>
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/shifts.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="edit_id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square text-warning me-2"></i><?= safeOutput($LANG['edit_shift']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['shift_name']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editName" class="form-control" required maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['shift_code']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="code" id="editCode" class="form-control" required maxlength="20">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['start_time']) ?></label>
                            <input type="time" name="start_time" id="editStartTime" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['end_time']) ?></label>
                            <input type="time" name="end_time" id="editEndTime" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['break_minutes']) ?></label>
                            <input type="number" name="break_minutes" id="editBreakMin" class="form-control" min="0" max="240">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['max_late_minutes']) ?></label>
                            <input type="number" name="max_late_minutes" id="editMaxLate" class="form-control" min="0" max="60">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['shift_color']) ?></label>
                            <input type="color" name="color" id="editColor" class="form-control form-control-color w-100">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['description']) ?></label>
                            <textarea name="description" id="editDescription" class="form-control" rows="2" maxlength="255"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="cross_midnight" id="editCrossMid" value="1">
                                <label class="form-check-label" for="editCrossMid">
                                    <i class="bi bi-moon-stars me-1"></i><?= safeOutput($LANG['cross_midnight']) ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="editActive" value="1">
                                <label class="form-check-label" for="editActive"><?= safeOutput($LANG['active']) ?></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-check-circle me-1"></i><?= safeOutput($LANG['update']) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- PEMUATAN ULANG ASET DIHAPUS (poin 9).
     Baris <script src> di sini memuat lagi berkas yang SUDAH dimuat
     includes/footer.php. jQuery yang dimuat dua kali mengganti instans
     pertama beserta seluruh plugin yang terdaftar padanya — DataTables
     dan komponen Bootstrap bisa berhenti bekerja di halaman ini tanpa
     satu pun pesan error. Pola yang sama sudah ditutup di settings/users.php,
     settings/news.php, dan inventory/inventory_in.php. -->
<script>
$(function() {

    // DataTable
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#shiftsTable')) {
        $('#shiftsTable').DataTable({
            pageLength: <?= (int)(getSetting('pagination', 10)) ?>,
            responsive: true,
            order: [[3, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 7, 8, 9] }],
            language: {
                search:     '<?php echo substr(bltx_js($LANG['search']), 1, -1) ?>:',
                lengthMenu: '<?php echo substr(bltx_js($LANG['show']), 1, -1) ?> _MENU_ <?php echo substr(bltx_js($LANG['entries']), 1, -1) ?>',
                info:       '<?php echo substr(bltx_js($LANG['showing']), 1, -1) ?> _START_ <?php echo substr(bltx_js($LANG['to']), 1, -1) ?> _END_ <?php echo substr(bltx_js($LANG['of']), 1, -1) ?> _TOTAL_ <?php echo substr(bltx_js($LANG['entries']), 1, -1) ?>',
                paginate: {
                    first:    <?php echo bltx_js($LANG['first']) ?>,
                    last:     <?php echo bltx_js($LANG['last']) ?>,
                    next:     <?php echo bltx_js($LANG['next']) ?>,
                    previous: <?php echo bltx_js($LANG['previous']) ?>
                },
                emptyTable:  <?php echo bltx_js($LANG['no_data']) ?>,
                zeroRecords: <?php echo bltx_js($LANG['no_data']) ?>
            }
        });
    }

    // Toggle Status
    $(document).on('change', '.status-toggle', function() {
        const $t = $(this);
        const id = $t.data('id');
        const val = $t.is(':checked') ? 1 : 0;
        $t.prop('disabled', true);
        $.ajax({
            url: '<?= url('payroll/shifts.php') ?>',
            type: 'POST',
            data: { ajax_toggle_status: 'true', id: id, is_active: val },
            dataType: 'json',
            success: function(r) {
                if (!r.success) {
                    Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: r.message });
                    $t.prop('checked', !$t.is(':checked'));
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: <?php echo bltx_js($LANG['network_error']) ?> });
                $t.prop('checked', !$t.is(':checked'));
            },
            complete: function() { $t.prop('disabled', false); }
        });
    });
});

function openEditModal(btn) {
    const d = JSON.parse($(btn).attr('data-rel'));
    const currentStatus = $(btn).closest('tr').find('.status-toggle').is(':checked');
    $('#editId').val(d.id);
    $('#editName').val(d.name);
    $('#editCode').val(d.code);
    $('#editStartTime').val(d.start_time);
    $('#editEndTime').val(d.end_time);
    $('#editBreakMin').val(d.break_minutes);
    $('#editMaxLate').val(d.max_late_minutes);
    $('#editColor').val(d.color || '#6c757d');
    $('#editDescription').val(d.description || '');
    $('#editCrossMid').prop('checked', d.cross_midnight == 1);
    $('#editActive').prop('checked', currentStatus);
    $('#editModal').modal('show');
}

function confirmDeleteShift(id, name) {
    Swal.fire({
        title:              <?php echo bltx_js($LANG['confirm']) ?>,
        text:               '<?php echo substr(bltx_js($LANG['confirm_delete_shift']), 1, -1) ?> "' + name + '"?',
        icon:               'warning',
        showCancelButton:   true,
        confirmButtonColor: '#dc3545',
        cancelButtonText:   <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText:  <?php echo bltx_js($LANG['delete']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= url('payroll/shifts.php') ?>',
            type: 'POST',
            data: { ajax_delete: 'true', csrf_token: '<?= $_SESSION['csrf_token'] ?>', id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    if (resp.action === 'inactive') {
                        $('.status-toggle[data-id="' + id + '"]').prop('checked', false);
                        Swal.fire({ icon: 'warning', title: <?php echo bltx_js($LANG['success']) ?>, text: resp.message, timer: 2500, showConfirmButton: false })
                            .then(function() { location.reload(); });
                    } else {
                        Swal.fire({ icon: 'success', title: <?php echo bltx_js($LANG['success']) ?>, text: resp.message, timer: 2000, showConfirmButton: false })
                            .then(function() { location.reload(); });
                    }
                } else {
                    Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: resp.message });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: <?php echo bltx_js($LANG['error']) ?>, text: <?php echo bltx_js($LANG['network_error']) ?> });
            }
        });
    });
}
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
