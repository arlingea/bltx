<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "departments" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('departments');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/departments.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

// Access: permission-based (superadmin always full control)
if (!checkPermission('departments', 'can_access')) {
    redirectNoAccess();
}

$page_title = $LANG['departments'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ===============================
   AJAX TOGGLE STATUS — no page refresh
=============================== */
if (isset($_POST['ajax_toggle_status']) && $_POST['ajax_toggle_status'] === 'true') {
    ob_clean();
    header('Content-Type: application/json');

    if (!checkPermission('departments', 'can_update')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $togId     = (int)($_POST['id']        ?? 0);
    $newStatus = (int)($_POST['is_active'] ?? 0);

    if ($togId > 0) {
        echo json_encode(departmentToggleStatus($conn, $togId, $newStatus));
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
    }
    exit;
}

/* ===============================
   AJAX DELETE
=============================== */
if (isset($_POST['ajax_delete']) && $_POST['ajax_delete'] === 'true') {
    if (!canDeleteDepartment()) { ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
    ob_clean();
    header('Content-Type: application/json');

    if (!checkPermission('departments', 'can_delete')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_csrf']]);
        exit;
    }

    $delId = (int)($_POST['id'] ?? 0);
    if ($delId > 0) {
        echo json_encode(departmentDelete($conn, $delId, $LANG));
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
    }
    exit;
}

/* ===============================
   HANDLE POST ACTIONS
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_toggle_status']) && !isset($_POST['ajax_delete'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(payrollGateUrl('departments'));
    }

    $action = $_POST['action'] ?? '';

    // ADD
    if ($action === 'add' && checkPermission('departments', 'can_create')) {
        $result = departmentAddOrEdit($conn, 'add', $_POST, $LANG);
        if ($result['success']) {
            bltx_flash_success($result['message']);
        } else {
            bltx_flash_error($result['message']);
        }
        redirect(payrollGateUrl('departments'));
    }

    // EDIT
    if ($action === 'edit' && checkPermission('departments', 'can_update')) {
        $data = [
            'name'        => $_POST['name']        ?? '',
            'description' => $_POST['description'] ?? '',
            'edit_id'     => $_POST['edit_id']     ?? 0,
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ];
        $result = departmentAddOrEdit($conn, 'edit', $data, $LANG);
        if ($result['success']) {
            bltx_flash_success($result['message']);
        } else {
            bltx_flash_error($result['message']);
        }
        redirect(payrollGateUrl('departments'));
    }

    // DELETE is handled via AJAX (ajax_delete) — see block above
}

/* ===============================
   QUERY DATA
=============================== */
$stList = $conn->prepare(
    "SELECT d.id, d.name, d.description, d.is_active,
            (SELECT COUNT(*) FROM positions p WHERE p.department_id = d.id) AS pos_count,
            COUNT(e.id) AS emp_count
     FROM departments d
     LEFT JOIN employees e ON e.department_id = d.id
     GROUP BY d.id, d.name, d.description, d.is_active
     ORDER BY d.name"
);
$stList->execute();
$departments = $stList->get_result()->fetch_all(MYSQLI_ASSOC);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('departments'); }
?>

<link rel="stylesheet" href="<?= url('assets/vendor/datatables/dataTables.bootstrap5.min.css') ?>">
<!-- PEMUATAN ULANG ASET DIHAPUS (poin 9).
     Baris <script src> di sini memuat lagi berkas yang SUDAH dimuat
     includes/footer.php. jQuery yang dimuat dua kali mengganti instans
     pertama beserta seluruh plugin yang terdaftar padanya — DataTables
     dan komponen Bootstrap bisa berhenti bekerja di halaman ini tanpa
     satu pun pesan error. Pola yang sama sudah ditutup di settings/users.php,
     settings/news.php, dan inventory/inventory_in.php. -->

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

<!-- Table Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="bi bi-diagram-3-fill"></i><?= $LANG['department_list'] ?>
        </div>
        <div class="card-tools">
            <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?>
            </a>
            <?php if (checkPermission('departments', 'can_create')): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> <?= $LANG['add_department'] ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm" id="departmentsTable">
                <thead>
                    <tr>
                        <th width="60"><?= $LANG['col_no'] ?></th>
                        <th><?= $LANG['department_name'] ?></th>
                        <th><?= $LANG['description'] ?></th>
                        <th width="100" class="text-center"><?= $LANG['positions_count'] ?></th>
                        <th width="100" class="text-center"><?= $LANG['employees_count'] ?></th>
                        <th width="120" class="text-center"><?= $LANG['status'] ?></th>
                        <?php if (checkPermission('departments', 'can_update') || checkPermission('departments', 'can_delete')): ?>
                        <th width="120" class="text-center"><?= $LANG['actions'] ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($departments)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            <i class="bi bi-inbox"></i> <?= $LANG['no_data'] ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($departments as $i => $d): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-muted"><?= htmlspecialchars($d['description'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center"><span class="badge bg-info"><?= (int)$d['pos_count'] ?></span></td>
                        <td class="text-center"><span class="badge bg-primary"><?= (int)$d['emp_count'] ?></span></td>
                        <td class="text-center">
                            <?php if (checkPermission('departments', 'can_update')): ?>
                            <div class="form-check form-switch d-flex justify-content-center mb-0">
                                <input type="checkbox"
                                       class="form-check-input status-toggle"
                                       data-id="<?= $d['id'] ?>"
                                       <?= $d['is_active'] ? 'checked' : '' ?>>
                            </div>
                            <?php else: ?>
                                <?php if ($d['is_active']): ?>
                                    <span class="badge bg-success"><?= $LANG['active'] ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= $LANG['inactive'] ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php if (checkPermission('departments', 'can_update') || checkPermission('departments', 'can_delete')): ?>
                        <td class="text-center">
                            <?php if (checkPermission('departments', 'can_update')): ?>
                            <button type="button" class="btn btn-warning btn-sm"
                                    data-rel='<?= htmlspecialchars(json_encode([
                                        'id'          => $d['id'],
                                        'name'        => $d['name'],
                                        'description' => $d['description'],
                                        'is_active'   => (int)$d['is_active'],
                                    ]), ENT_QUOTES, 'UTF-8') ?>'
                                    onclick="openEditModal(this)"
                                    title="<?= $LANG['edit'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (checkPermission('departments', 'can_delete')): ?>
                            <button type="button" class="btn btn-danger btn-sm ms-1"
                                    onclick="return confirmDeleteDepartment(<?= $d['id'] ?>, <?= bltx_e(bltx_js($d['name'])) ?>)"
                                    title="<?= $LANG['delete'] ?>">
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
<?php if (checkPermission('departments', 'can_create')): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/departments.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">
                        <i class="bi bi-plus-circle text-primary me-2"></i><?= $LANG['add_department'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?= $LANG['department_name'] ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name" class="form-control" required maxlength="100" autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= $LANG['description'] ?></label>
                        <textarea name="description" class="form-control" rows="3" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-circle me-1"></i><?= $LANG['save'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<?php if (checkPermission('departments', 'can_update')): ?>
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/departments.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="edit_id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">
                        <i class="bi bi-pencil-square text-warning me-2"></i><?= $LANG['edit_department'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?= $LANG['department_name'] ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name" id="editName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= $LANG['description'] ?></label>
                        <textarea name="description" id="editDesc" class="form-control" rows="3" maxlength="500"></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editActive" value="1">
                        <label class="form-check-label" for="editActive"><?= $LANG['active'] ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-circle me-1"></i><?= $LANG['update'] ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<script>
$(document).ready(function() {

    // ── DataTable ────────────────────────────────────────────────────────────
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#departmentsTable')) {
        $('#departmentsTable').DataTable({
            pageLength: <?= (int)(getSetting('pagination', 10)) ?>,
            responsive: true,
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: [0, 3, 4, 5, 6] }
            ],
            language: {
                search:     "<?= $LANG['search'] ?>:",
                lengthMenu: "<?= $LANG['show'] ?> _MENU_ <?= $LANG['entries'] ?>",
                info:       "<?= $LANG['showing'] ?> _START_ <?= $LANG['to'] ?> _END_ <?= $LANG['of'] ?> _TOTAL_ <?= $LANG['entries'] ?>",
                paginate: {
                    first:    "<?= $LANG['first'] ?>",
                    last:     "<?= $LANG['last'] ?>",
                    next:     "<?= $LANG['next'] ?>",
                    previous: "<?= $LANG['previous'] ?>"
                },
                emptyTable:  "<?= $LANG['no_data'] ?>",
                zeroRecords: "<?= $LANG['no_data'] ?>"
            }
        });
    }

    // ── AJAX Toggle Status — no page refresh ─────────────────────────────────
    $(document).on('change', '.status-toggle', function(e) {
        e.preventDefault();

        const $toggle  = $(this);
        const togId    = $toggle.data('id');
        const isActive = $toggle.is(':checked') ? 1 : 0;

        $.ajax({
            url:      '<?= url('payroll/departments.php') ?>',
            type:     'POST',
            data:     {
                ajax_toggle_status: 'true',
                id:        togId,
                is_active: isActive
            },
            dataType: 'json',
            beforeSend: function() {
                $toggle.prop('disabled', true);
            },
            success: function(resp) {
                if (!resp.success) {
                    Swal.fire({ icon:'error', title:'<?= $LANG['error'] ?>', text: resp.message || '<?= $LANG['error'] ?>' });
                    $toggle.prop('checked', !$toggle.is(':checked'));
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon:'error', title:'<?= $LANG['error'] ?>', text: '<?= $LANG['network_error'] ?>: ' + error });
                $toggle.prop('checked', !$toggle.is(':checked'));
            },
            complete: function() {
                $toggle.prop('disabled', false);
            }
        });
    });

});

// ── Open Edit Modal ──────────────────────────────────────────────────────────
function openEditModal(btn) {
    const d = JSON.parse($(btn).attr('data-rel'));
    // Sync toggle state live from table row (may differ if toggled via AJAX)
    const currentStatus = $(btn).closest('tr').find('.status-toggle').is(':checked');

    $('#editId').val(d.id);
    $('#editName').val(d.name);
    $('#editDesc').val(d.description || '');
    $('#editActive').prop('checked', currentStatus);

    $('#editModal').modal('show');
}

// ── Confirm Delete Department ────────────────────────────────────────────────
function confirmDeleteDepartment(id, name) {
    Swal.fire({
        title:              '<?= $LANG['confirm'] ?>',
        text:               '<?php echo substr(bltx_js($LANG['confirm_delete_department']), 1, -1) ?> "' + name + '"?',
        icon:               'warning',
        showCancelButton:   true,
        confirmButtonColor: '#dc3545',
        cancelButtonText:   '<?= $LANG['cancel'] ?>',
        confirmButtonText:  '<?= $LANG['delete'] ?>'
    }).then(function(r) {
        if (!r.isConfirmed) return;

        $.ajax({
            url:      '<?= url('payroll/departments.php') ?>',
            type:     'POST',
            data:     {
                ajax_delete: 'true',
                csrf_token:  '<?= $_SESSION['csrf_token'] ?>',
                id:          id
            },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    if (resp.action === 'inactive') {
                        // Has linked employees — set inactive: update toggle in DOM immediately
                        var $toggle = $('.status-toggle[data-id="' + id + '"]');
                        if ($toggle.length) {
                            $toggle.prop('checked', false);
                        }
                        Swal.fire({ icon:'warning', title:'<?= $LANG['success'] ?>', text: resp.message, timer:2500, showConfirmButton:false })
                            .then(function() { location.reload(); });
                    } else {
                        // No linked employees — permanently deleted
                        Swal.fire({ icon:'success', title:'<?= $LANG['success'] ?>', text: resp.message, timer:2000, showConfirmButton:false })
                            .then(function() { location.reload(); });
                    }
                } else {
                    Swal.fire({ icon:'error', title:'<?= $LANG['error'] ?>', text: resp.message });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon:'error', title:'<?= $LANG['error'] ?>', text: '<?= $LANG['network_error'] ?>: ' + error });
            }
        });
    });
    return false;
}
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>