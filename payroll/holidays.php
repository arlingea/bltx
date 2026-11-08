<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "holidays" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('holidays');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/holidays.php
// Public holiday / day-off management for HRIS
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
if (!checkPermission('holidays', 'can_access')) redirectNoAccess();

$page_title = $LANG['holidays'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ───────────────────────────────────────────────────────────
   POST HANDLERS
─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(payrollGateUrl('holidays'));
    }

    $action = $_POST['action'] ?? '';

    // Add
    if ($action === 'add' && canCreateHoliday()) {
        $date      = trim($_POST['holiday_date'] ?? '');
        $name      = trim($_POST['name']         ?? '');
        $national  = isset($_POST['is_national']) ? 1 : 0;
        if (!$date || !$name) {
            bltx_flash_error('Date and Name are required');
        } else {
            /* created_by_name = SALINAN PERMANEN users.full_name saat hari
               libur dibuat, tanpa foreign key sehingga namanya tidak hilang
               saat akun users dihapus. */
            $st = $conn->prepare("INSERT INTO holidays (holiday_date, name, is_national, created_by, created_by_name) VALUES (?,?,?,?,(SELECT full_name FROM users WHERE id = ?))");
            $st->bind_param('ssiii', $date, $name, $national, $_SESSION['user_id'], $_SESSION['user_id']);
            if ($st->execute()) {
                logActivity('holiday_add', "Added holiday: $name on $date");
                bltx_flash_success($LANG['save_success']);
            } else {
                bltx_flash_error(str_contains($conn->error, 'Duplicate') ? 'Holiday on this date already exists' : $conn->error);
            }
            $st->close();
        }
        redirect(payrollGateUrl('holidays'));
    }

    // Edit
    if ($action === 'edit' && canUpdateHoliday()) {
        $id       = (int)($_POST['edit_id']      ?? 0);
        $date     = trim($_POST['holiday_date']  ?? '');
        $name     = trim($_POST['name']          ?? '');
        $national = isset($_POST['is_national']) ? 1 : 0;
        if (!$id || !$date || !$name) {
            bltx_flash_error('Required fields missing');
        } else {
            $st = $conn->prepare("UPDATE holidays SET holiday_date=?, name=?, is_national=? WHERE id=?");
            $st->bind_param('ssii', $date, $name, $national, $id);
            if ($st->execute()) {
                logActivity('holiday_edit', "Updated holiday ID $id: $name");
                bltx_flash_success($LANG['save_success']);
            } else {
                bltx_flash_error($conn->error);
            }
            $st->close();
        }
        redirect(payrollGateUrl('holidays'));
    }

    // Delete (AJAX)
    if ($action === 'delete') {
        ob_clean(); header('Content-Type: application/json');
        if (!canDeleteHoliday()) {
            echo json_encode(['success' => false, 'message' => $LANG['no_permission']]); exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM holidays WHERE id=?");
        $st->bind_param('i', $id);
        if ($st->execute() && $st->affected_rows > 0) {
            $st->close(); logActivity('holiday_delete', "Deleted holiday ID $id");
            echo json_encode(['success' => true, 'message' => $LANG['delete_success']]); exit;
        }
        $st->close();
        echo json_encode(['success' => false, 'message' => $LANG['error']]); exit;
    }

    redirect(payrollGateUrl('holidays'));
}

/* ───────────────────────────────────────────────────────────
   DATA
─────────────────────────────────────────────────────────── */
$filterYear = (int)($_GET['year'] ?? date('Y'));

$stH = $conn->prepare("SELECT * FROM holidays WHERE YEAR(holiday_date) = ? ORDER BY holiday_date ASC");
$stH->bind_param('i', $filterYear);
$stH->execute();
$holidays = $stH->get_result()->fetch_all(MYSQLI_ASSOC);
$stH->close();

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('holidays'); }
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
    <h2 class="page-title-text"><i class="bi bi-calendar-event-fill text-danger"></i><?= safeOutput($LANG['holidays']) ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
        <?php if (canCreateHoliday()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i><?= safeOutput($LANG['add_holiday']) ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Year filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 small fw-semibold"><?= safeOutput($LANG['year']) ?>:</label>
            <select name="year" class="form-select form-select-sm" style="width:120px" onchange="this.form.submit()">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <span class="text-muted small ms-2"><?= count($holidays) ?> <?= safeOutput($LANG['holidays']) ?></span>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="holidaysTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px"><?= safeOutput($LANG['col_no']) ?></th>
                        <th><?= safeOutput($LANG['date']) ?></th>
                        <th><?= safeOutput($LANG['holiday_name']) ?></th>
                        <th class="text-center" style="width:120px"><?= safeOutput($LANG['national']) ?></th>
                        <th class="text-center" style="width:120px"><?= safeOutput($LANG['day']) ?></th>
                        <?php if (canUpdateHoliday() || canDeleteHoliday()): ?>
                        <th class="text-center" style="width:110px"><?= safeOutput($LANG['actions']) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($holidays)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= safeOutput($LANG['no_data']) ?></td></tr>
                <?php else: ?>
                    <?php foreach ($holidays as $i => $h): ?>
                    <tr>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td>
                            <span class="fw-semibold"><?= date('d', strtotime($h['holiday_date'])) ?></span>
                            <span class="text-muted"><?= getMonthName((int)date('n', strtotime($h['holiday_date']))) ?> <?= date('Y', strtotime($h['holiday_date'])) ?></span>
                        </td>
                        <td><?= safeOutput($h['name']) ?></td>
                        <td class="text-center">
                            <?php if ($h['is_national']): ?>
                            <span class="badge bg-danger"><i class="bi bi-flag me-1"></i><?= safeOutput($LANG['national']) ?></span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><?= safeOutput($LANG['local']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-muted small"><?= date('l', strtotime($h['holiday_date'])) ?></td>
                        <?php if (canUpdateHoliday() || canDeleteHoliday()): ?>
                        <td class="text-center">
                            <?php if (canUpdateHoliday()): ?>
                            <button type="button" class="btn btn-warning btn-sm"
                                    data-rel='<?= htmlspecialchars(json_encode(['id'=>$h['id'],'holiday_date'=>$h['holiday_date'],'name'=>$h['name'],'is_national'=>(int)$h['is_national']]), ENT_QUOTES, 'UTF-8') ?>'
                                    onclick="openEditModal(this)" title="<?= safeOutput($LANG['edit']) ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (canDeleteHoliday()): ?>
                            <button type="button" class="btn btn-danger btn-sm ms-1"
                                    onclick="confirmDelete(<?= (int)$h['id'] ?>, <?= bltx_e(bltx_js($h['name'])) ?>)"
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

<!-- Add Modal -->
<?php if (canCreateHoliday()): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/holidays.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle text-primary me-2"></i><?= safeOutput($LANG['add_holiday']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['date']) ?> <span class="text-danger">*</span></label>
                        <input type="date" name="holiday_date" class="form-control" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['holiday_name']) ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="150" placeholder="<?= htmlspecialchars($LANG['eg_holiday_name']) ?>">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_national" id="addNational" value="1" checked>
                        <label class="form-check-label" for="addNational"><?= safeOutput($LANG['national_holiday']) ?></label>
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

<!-- Edit Modal -->
<?php if (canUpdateHoliday()): ?>
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/holidays.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="edit_id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square text-warning me-2"></i><?= safeOutput($LANG['edit_holiday']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['date']) ?> <span class="text-danger">*</span></label>
                        <input type="date" name="holiday_date" id="editDate" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['holiday_name']) ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required maxlength="150">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_national" id="editNational" value="1">
                        <label class="form-check-label" for="editNational"><?= safeOutput($LANG['national_holiday']) ?></label>
                    </div>
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

<!-- PEMUATAN ULANG ASET DIHAPUS (poin 9).
     Baris <script src> di sini memuat lagi berkas yang SUDAH dimuat
     includes/footer.php. jQuery yang dimuat dua kali mengganti instans
     pertama beserta seluruh plugin yang terdaftar padanya — DataTables
     dan komponen Bootstrap bisa berhenti bekerja di halaman ini tanpa
     satu pun pesan error. Pola yang sama sudah ditutup di settings/users.php,
     settings/news.php, dan inventory/inventory_in.php. -->
<script>
$(function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#holidaysTable')) {
        $('#holidaysTable').DataTable({
            pageLength: <?= (int)(getSetting('pagination', 25)) ?>,
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 3, 4, 5] }],
            language: {
                search:     '<?php echo substr(bltx_js($LANG['search']), 1, -1) ?>:',
                lengthMenu: '<?php echo substr(bltx_js($LANG['show']), 1, -1) ?> _MENU_ <?php echo substr(bltx_js($LANG['entries']), 1, -1) ?>',
                info:       '<?php echo substr(bltx_js($LANG['showing']), 1, -1) ?> _START_ <?php echo substr(bltx_js($LANG['to']), 1, -1) ?> _END_ <?php echo substr(bltx_js($LANG['of']), 1, -1) ?> _TOTAL_ <?php echo substr(bltx_js($LANG['entries']), 1, -1) ?>',
                paginate:   { first:<?php echo bltx_js($LANG['first']) ?>,last:<?php echo bltx_js($LANG['last']) ?>,next:<?php echo bltx_js($LANG['next']) ?>,previous:<?php echo bltx_js($LANG['previous']) ?> },
                emptyTable: <?php echo bltx_js($LANG['no_data']) ?>
            }
        });
    }
});

function openEditModal(btn) {
    const d = JSON.parse($(btn).attr('data-rel'));
    $('#editId').val(d.id);
    $('#editDate').val(d.holiday_date);
    $('#editName').val(d.name);
    $('#editNational').prop('checked', d.is_national == 1);
    $('#editModal').modal('show');
}

function confirmDelete(id, name) {
    Swal.fire({
        title: <?php echo bltx_js($LANG['confirm']) ?>,
        text: '<?php echo substr(bltx_js($LANG['delete']), 1, -1) ?> "' + name + '"?',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonText: <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText: <?php echo bltx_js($LANG['delete']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= url('payroll/holidays.php') ?>', { action:'delete', csrf_token:'<?= $_SESSION['csrf_token'] ?>', id:id }, function(resp) {
            if (resp.success) {
                Swal.fire({icon:'success',title:<?php echo bltx_js($LANG['success']) ?>,text:resp.message,timer:2000,showConfirmButton:false})
                    .then(function(){location.reload();});
            } else {
                Swal.fire({icon:'error',title:<?php echo bltx_js($LANG['error']) ?>,text:resp.message});
            }
        }, 'json');
    });
}
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
