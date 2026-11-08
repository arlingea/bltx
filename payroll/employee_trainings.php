<?php
// File: payroll/employee_trainings.php
// HRIS — Employee training & certification records
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
if (!checkPermission('employees', 'can_access')) redirectNoAccess();

$empId = (int)($_GET['id'] ?? 0);
$emp   = $empId > 0 ? getEmployee($conn, $empId) : null;
if (!$emp) { header('Location: ' . payrollGateUrl('employees')); exit; }

$page_title = ($LANG['employee_trainings']) . ' — ' . $emp['full_name'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ─── AJAX DELETE ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    ob_clean(); header('Content-Type: application/json');
    if (!canUpdateEmployee()) { echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>$LANG['invalid_csrf']]); exit;
    }
    $tid = (int)($_POST['id'] ?? 0);
    $stF = $conn->prepare("SELECT certificate FROM employee_trainings WHERE id=? AND employee_id=?");
    $stF->bind_param('ii', $tid, $empId); $stF->execute();
    $fRow = $stF->get_result()->fetch_assoc(); $stF->close();
    $stD = $conn->prepare("DELETE FROM employee_trainings WHERE id=? AND employee_id=?");
    $stD->bind_param('ii', $tid, $empId);
    if ($stD->execute() && $stD->affected_rows > 0) {
        if ($fRow && !empty($fRow['certificate'])) {
            $fp = __DIR__ . '/../uploads/employee/trainings/' . $fRow['certificate'];
            if (file_exists($fp)) @unlink($fp);
        }
        $stD->close();
        logActivity('training_delete', "Deleted training ID $tid for emp $empId");
        echo json_encode(['success'=>true,'message'=>$LANG['delete_success']]); exit;
    }
    $stD->close();
    echo json_encode(['success'=>false,'message'=>$LANG['error']]); exit;
}

/* ─── POST: ADD / EDIT ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_delete'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        bltx_flash_error($LANG['invalid_csrf']); redirect(url('payroll/employee_trainings.php?id='.$empId));
    }
    if (!canUpdateEmployee()) { bltx_flash_error($LANG['no_permission']); redirect(url('payroll/employee_trainings.php?id='.$empId)); }

    $action       = $_POST['action'] ?? 'add';
    $editId       = (int)($_POST['edit_id'] ?? 0);
    $trainingName = trim($_POST['training_name'] ?? '');
    $organizer    = trim($_POST['organizer']     ?? '') ?: null;
    $startDate    = trim($_POST['start_date']    ?? '');
    $endDate      = !empty($_POST['end_date'])   ? $_POST['end_date'] : null;
    $notes        = trim($_POST['notes']         ?? '') ?: null;

    if (!$trainingName || !$startDate) {
        bltx_flash_error('Training name and start date are required');
        redirect(url('payroll/employee_trainings.php?id='.$empId));
    }

    $certPath = null;
    if (!empty($_FILES['certificate']['name'])) {
        $up = uploadEmployeeAttachment($_FILES['certificate'], 'trainings');
        if (isset($up['error'])) { bltx_flash_error($up['error']); redirect(url('payroll/employee_trainings.php?id='.$empId)); }
        $certPath = $up['filename'];
    }

    if ($action === 'edit' && $editId > 0) {
        if ($certPath) {
            $st = $conn->prepare("UPDATE employee_trainings SET training_name=?,organizer=?,start_date=?,end_date=?,certificate=?,notes=? WHERE id=? AND employee_id=?");
            $st->bind_param('ssssssii', $trainingName, $organizer, $startDate, $endDate, $certPath, $notes, $editId, $empId);
        } else {
            $st = $conn->prepare("UPDATE employee_trainings SET training_name=?,organizer=?,start_date=?,end_date=?,notes=? WHERE id=? AND employee_id=?");
            $st->bind_param('sssssii', $trainingName, $organizer, $startDate, $endDate, $notes, $editId, $empId);
        }
        if ($st->execute()) {
            logActivity('training_edit', "Updated training ID $editId for emp $empId");
            bltx_flash_success($LANG['save_success']);
        } else { bltx_flash_error($conn->error); }
        $st->close();
    } else {
        $uid = (int)$_SESSION['user_id'];
        /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
           tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
        $st = $conn->prepare("INSERT INTO employee_trainings (employee_id,training_name,organizer,start_date,end_date,certificate,notes,created_by,created_by_name) VALUES (?,?,?,?,?,?,?,?,(SELECT full_name FROM users WHERE id = ?))");
        $st->bind_param('issssssii', $empId, $trainingName, $organizer, $startDate, $endDate, $certPath, $notes, $uid, $uid);
        if ($st->execute()) {
            logActivity('training_add', "Added training '$trainingName' for emp $empId");
            bltx_flash_success($LANG['save_success']);
        } else { bltx_flash_error($conn->error); }
        $st->close();
    }
    redirect(url('payroll/employee_trainings.php?id='.$empId));
}

/* ─── DATA ────────────────────────────────────────────────────────────────── */
$stList = $conn->prepare("SELECT * FROM employee_trainings WHERE employee_id=? ORDER BY start_date DESC");
$stList->bind_param('i', $empId); $stList->execute();
$trainings = $stList->get_result()->fetch_all(MYSQLI_ASSOC); $stList->close();

include __DIR__ . '/../includes/header.php';
?>

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
    <div>
        <h2 class="page-title-text"><i class="bi bi-mortarboard-fill text-success"></i><?= safeOutput($LANG['employee_trainings']) ?></h2>
        <div class="page-subtitle">
            <a href="<?= url('payroll/employee_view.php?id='.$empId) ?>" class="text-decoration-none"><?= safeOutput($emp['full_name']) ?></a>
            <span class="badge bg-light text-dark border ms-2"><?= safeOutput($emp['employee_code']) ?></span>
        </div>
    </div>
    <div class="page-actions">
        <a href="<?= url('payroll/employee_view.php?id='.$empId) ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
        <?php if (canUpdateEmployee()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i><?= safeOutput($LANG['add_training']) ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px"><?= safeOutput($LANG['col_no']) ?></th>
                        <th><?= safeOutput($LANG['training_name']) ?></th>
                        <th class="d-none d-md-table-cell"><?= safeOutput($LANG['organizer']) ?></th>
                        <th><?= safeOutput($LANG['start_date']) ?></th>
                        <th class="d-none d-md-table-cell"><?= safeOutput($LANG['end_date']) ?></th>
                        <th class="text-center" style="width:110px"><?= safeOutput($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($trainings)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-mortarboard fs-3 d-block mb-2"></i><?= safeOutput($LANG['no_data']) ?>
                    </td></tr>
                <?php else: $i=1; foreach ($trainings as $t): ?>
                    <tr>
                        <td class="text-muted small"><?= $i++ ?></td>
                        <td>
                            <div class="fw-semibold"><?= safeOutput($t['training_name']) ?></div>
                            <?php if ($t['notes']): ?><small class="text-muted"><?= safeOutput($t['notes']) ?></small><?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><?= safeOutput($t['organizer'] ?? '—') ?></td>
                        <td><?= $t['start_date'] ? date('d M Y', strtotime($t['start_date'])) : '—' ?></td>
                        <td class="d-none d-md-table-cell"><?= $t['end_date'] ? date('d M Y', strtotime($t['end_date'])) : '—' ?></td>
                        <td class="text-center">
                            <?php if ($t['certificate']): ?>
                            <a href="<?= url('uploads/employee/trainings/' . rawurlencode($t['certificate'])) ?>" target="_blank"
                               class="btn btn-sm btn-success" title="<?= safeOutput($LANG['certificate']) ?>">
                                <i class="bi bi-file-earmark-check"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (canUpdateEmployee()): ?>
                            <button type="button" class="btn btn-sm btn-warning ms-1"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)"
                                    title="<?= safeOutput($LANG['edit']) ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger ms-1"
                                    onclick="deleteTraining(<?= (int)$t['id'] ?>, <?= bltx_e(bltx_js($t['training_name'])) ?>)"
                                    title="<?= safeOutput($LANG['delete']) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<?php if (canUpdateEmployee()): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/employee_trainings.php?id='.$empId) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle text-primary me-2"></i><?= safeOutput($LANG['add_training']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['training_name']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="training_name" class="form-control" required maxlength="200" autofocus>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['organizer']) ?></label>
                            <input type="text" name="organizer" class="form-control" maxlength="150">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['start_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['end_date']) ?></label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['certificate']) ?></label>
                            <input type="file" name="certificate" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                            <small class="text-muted"><?= htmlspecialchars($LANG['file_hint_10mb']) ?></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['notes']) ?></label>
                            <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>
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

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/employee_trainings.php?id='.$empId) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="edit_id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square text-warning me-2"></i><?= safeOutput($LANG['edit']) ?> <?= safeOutput($LANG['training']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['training_name']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="training_name" id="editName" class="form-control" required maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['organizer']) ?></label>
                            <input type="text" name="organizer" id="editOrg" class="form-control" maxlength="150">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['start_date']) ?></label>
                            <input type="date" name="start_date" id="editStart" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['end_date']) ?></label>
                            <input type="date" name="end_date" id="editEnd" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['certificate']) ?></label>
                            <input type="file" name="certificate" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                            <small class="text-muted"><?= safeOutput($LANG['leave_blank']) ?></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['notes']) ?></label>
                            <textarea name="notes" id="editNotes" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>
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
function openEditModal(d) {
    document.getElementById('editId').value    = d.id;
    document.getElementById('editName').value  = d.training_name;
    document.getElementById('editOrg').value   = d.organizer || '';
    document.getElementById('editStart').value = d.start_date || '';
    document.getElementById('editEnd').value   = d.end_date || '';
    document.getElementById('editNotes').value = d.notes || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function deleteTraining(id, name) {
    Swal.fire({
        title: <?php echo bltx_js($LANG['confirm']) ?>,
        text: '<?php echo substr(bltx_js($LANG['delete']), 1, -1) ?> "' + name + '"?',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonText: <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText: <?php echo bltx_js($LANG['delete']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= url('payroll/employee_trainings.php?id='.$empId) ?>',
            { ajax_delete: '1', csrf_token: '<?= $_SESSION['csrf_token'] ?>', id: id },
            function(resp) {
                if (resp.success) {
                    Swal.fire({icon:'success',title:<?php echo bltx_js($LANG['success']) ?>,text:resp.message,timer:1800,showConfirmButton:false})
                        .then(function(){location.reload();});
                } else {
                    Swal.fire({icon:'error',title:<?php echo bltx_js($LANG['error']) ?>,text:resp.message});
                }
            }, 'json');
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
