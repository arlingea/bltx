<?php
// File: payroll/employee_warnings.php
// HRIS — Employee disciplinary warnings (SP1, SP2, SP3, verbal, termination)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
if (!checkPermission('employees', 'can_access')) redirectNoAccess();

$empId = (int)($_GET['id'] ?? 0);
$emp   = $empId > 0 ? getEmployee($conn, $empId) : null;
if (!$emp) { header('Location: ' . payrollGateUrl('employees')); exit; }

$page_title = ($LANG['employee_warnings']) . ' — ' . $emp['full_name'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ─── AJAX DELETE ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    ob_clean(); header('Content-Type: application/json');
    if (!canUpdateEmployee()) { echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>$LANG['invalid_csrf']]); exit;
    }
    $wid = (int)($_POST['id'] ?? 0);
    $stF = $conn->prepare("SELECT attachment FROM employee_warnings WHERE id=? AND employee_id=?");
    $stF->bind_param('ii', $wid, $empId); $stF->execute();
    $fRow = $stF->get_result()->fetch_assoc(); $stF->close();
    $stD = $conn->prepare("DELETE FROM employee_warnings WHERE id=? AND employee_id=?");
    $stD->bind_param('ii', $wid, $empId);
    if ($stD->execute() && $stD->affected_rows > 0) {
        if ($fRow && !empty($fRow['attachment'])) {
            $fp = __DIR__ . '/../uploads/employee/warnings/' . $fRow['attachment'];
            if (file_exists($fp)) @unlink($fp);
        }
        $stD->close();
        logActivity('warning_delete', "Deleted warning ID $wid for emp $empId");
        echo json_encode(['success'=>true,'message'=>$LANG['delete_success']]); exit;
    }
    $stD->close();
    echo json_encode(['success'=>false,'message'=>$LANG['error']]); exit;
}

/* ─── POST: ADD / EDIT ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_delete'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        bltx_flash_error($LANG['invalid_csrf']); redirect(url('payroll/employee_warnings.php?id='.$empId));
    }
    if (!canUpdateEmployee()) { bltx_flash_error($LANG['no_permission']); redirect(url('payroll/employee_warnings.php?id='.$empId)); }

    $action      = $_POST['action']       ?? 'add';
    $editId      = (int)($_POST['edit_id'] ?? 0);
    $warnType    = trim($_POST['warning_type'] ?? 'verbal');
    $warnDate    = trim($_POST['warning_date'] ?? date('Y-m-d'));
    $reason      = trim($_POST['reason']       ?? '');
    $allowedTypes = ['verbal','sp1','sp2','sp3','termination'];
    if (!in_array($warnType, $allowedTypes, true)) $warnType = 'verbal';

    if (!$reason) { bltx_flash_error('Reason is required'); redirect(url('payroll/employee_warnings.php?id='.$empId)); }

    $attachment = null;
    if (!empty($_FILES['attachment']['name'])) {
        $up = uploadEmployeeAttachment($_FILES['attachment'], 'warnings');
        if (isset($up['error'])) { bltx_flash_error($up['error']); redirect(url('payroll/employee_warnings.php?id='.$empId)); }
        $attachment = $up['filename'];
    }

    $issuedBy = (int)$_SESSION['user_id'];

    if ($action === 'edit' && $editId > 0) {
        /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
           tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
        if ($attachment) {
            $st = $conn->prepare("UPDATE employee_warnings SET warning_type=?,warning_date=?,reason=?,attachment=?,issued_by=?,issued_by_name=(SELECT full_name FROM users WHERE id = ?) WHERE id=? AND employee_id=?");
            $st->bind_param('ssssiiii', $warnType, $warnDate, $reason, $attachment, $issuedBy, $issuedBy, $editId, $empId);
        } else {
            $st = $conn->prepare("UPDATE employee_warnings SET warning_type=?,warning_date=?,reason=?,issued_by=?,issued_by_name=(SELECT full_name FROM users WHERE id = ?) WHERE id=? AND employee_id=?");
            $st->bind_param('sssiiii', $warnType, $warnDate, $reason, $issuedBy, $issuedBy, $editId, $empId);
        }
        if ($st->execute()) {
            logActivity('warning_edit', "Updated warning ID $editId for emp $empId");
            bltx_flash_success($LANG['save_success']);
        } else { bltx_flash_error($conn->error); }
        $st->close();
    } else {
        $st = $conn->prepare("INSERT INTO employee_warnings (employee_id,warning_type,warning_date,reason,attachment,issued_by,issued_by_name) VALUES (?,?,?,?,?,?,(SELECT full_name FROM users WHERE id = ?))");
        $st->bind_param('issssii', $empId, $warnType, $warnDate, $reason, $attachment, $issuedBy, $issuedBy);
        if ($st->execute()) {
            logActivity('warning_add', "Added $warnType warning for emp $empId");
            bltx_flash_success($LANG['save_success']);
        } else { bltx_flash_error($conn->error); }
        $st->close();
    }
    redirect(url('payroll/employee_warnings.php?id='.$empId));
}

/* ─── DATA ────────────────────────────────────────────────────────────────── */
/* Nama penerbit SP dibaca LANGSUNG dari kolom permanen employee_warnings
   .issued_by_name — tidak lagi lewat JOIN ke tabel users, sehingga namanya
   tetap tampil utuh setelah akun users-nya dihapus. */
$stList = $conn->prepare(
    "SELECT w.*
     FROM employee_warnings w
     WHERE w.employee_id=? ORDER BY w.warning_date DESC"
);
$stList->bind_param('i', $empId); $stList->execute();
$warnings = $stList->get_result()->fetch_all(MYSQLI_ASSOC); $stList->close();

$warnTypeLabels = [
    'verbal'      => $LANG['warning_verbal'],
    'sp1'         => 'SP-1',
    'sp2'         => 'SP-2',
    'sp3'         => 'SP-3',
    'termination' => $LANG['termination'],
];
$warnTypeBadge = ['verbal'=>'secondary','sp1'=>'warning','sp2'=>'orange','sp3'=>'danger','termination'=>'dark'];

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
        <h2 class="page-title-text"><i class="bi bi-exclamation-triangle-fill text-danger"></i><?= safeOutput($LANG['employee_warnings']) ?></h2>
        <div class="page-subtitle">
            <a href="<?= url('payroll/employee_view.php?id='.$empId) ?>" class="text-decoration-none"><?= safeOutput($emp['full_name']) ?></a>
            <span class="badge bg-light text-dark border ms-2"><?= safeOutput($emp['employee_code']) ?></span>
            <?php if (count($warnings) > 0): ?>
            <span class="badge bg-danger ms-2"><?= count($warnings) ?> <?= safeOutput(strtolower($LANG['warnings'])) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-actions">
        <a href="<?= url('payroll/employee_view.php?id='.$empId) ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
        <?php if (canUpdateEmployee()): ?>
        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i><?= safeOutput($LANG['add_warning']) ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($warnings)): ?>
<!-- Warning Summary -->
<div class="row g-3 mb-4">
    <?php
    $counts = ['verbal'=>0,'sp1'=>0,'sp2'=>0,'sp3'=>0,'termination'=>0];
    foreach ($warnings as $w) { if (isset($counts[$w['warning_type']])) $counts[$w['warning_type']]++; }
    foreach ($warnTypeLabels as $type => $label):
        if ($counts[$type] == 0) continue;
        $bg = $warnTypeBadge[$type] ?? 'secondary';
    ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fw-bold fs-3 <?= $type !== 'verbal' ? 'text-danger' : 'text-muted' ?>"><?= $counts[$type] ?></div>
            <div class="small text-muted"><?= safeOutput($label) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px"><?= safeOutput($LANG['col_no']) ?></th>
                        <th><?= safeOutput($LANG['warning_type']) ?></th>
                        <th><?= safeOutput($LANG['date']) ?></th>
                        <th><?= safeOutput($LANG['reason']) ?></th>
                        <th class="d-none d-lg-table-cell"><?= safeOutput($LANG['issued_by']) ?></th>
                        <th class="text-center" style="width:110px"><?= safeOutput($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($warnings)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-shield-check fs-3 d-block mb-2 text-success"></i><?= safeOutput($LANG['no_warnings']) ?>
                    </td></tr>
                <?php else: $i=1; foreach ($warnings as $w):
                    $typeLabel = $warnTypeLabels[$w['warning_type']] ?? ucfirst($w['warning_type']);
                    $typeBg    = ['verbal'=>'secondary','sp1'=>'warning','sp2'=>'warning','sp3'=>'danger','termination'=>'dark'][$w['warning_type']] ?? 'secondary';
                ?>
                    <tr>
                        <td class="text-muted small"><?= $i++ ?></td>
                        <td>
                            <span class="badge bg-<?= $typeBg ?>"><?= safeOutput($typeLabel) ?></span>
                        </td>
                        <td><?= date('d M Y', strtotime($w['warning_date'])) ?></td>
                        <td>
                            <div class="small" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= safeOutput($w['reason']) ?>">
                                <?= safeOutput($w['reason']) ?>
                            </div>
                        </td>
                        <td class="d-none d-lg-table-cell small text-muted"><?= safeOutput($w['issued_by_name'] ?? '—') ?></td>
                        <td class="text-center">
                            <?php if ($w['attachment']): ?>
                            <a href="<?= url('uploads/employee/warnings/'.rawurlencode($w['attachment'])) ?>" target="_blank"
                               class="btn btn-sm btn-info" title="<?= safeOutput($LANG['view']) ?>">
                                <i class="bi bi-file-earmark-text"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (canUpdateEmployee()): ?>
                            <button type="button" class="btn btn-sm btn-warning ms-1"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($w), ENT_QUOTES, 'UTF-8') ?>)"
                                    title="<?= safeOutput($LANG['edit']) ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger ms-1"
                                    onclick="deleteWarning(<?= (int)$w['id'] ?>, <?= bltx_e(bltx_js($typeLabel)) ?>)"
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
            <form method="POST" action="<?= url('payroll/employee_warnings.php?id='.$empId) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i><?= safeOutput($LANG['add_warning']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['warning_type']) ?> <span class="text-danger">*</span></label>
                            <select name="warning_type" class="form-select">
                                <?php foreach ($warnTypeLabels as $key => $label): ?>
                                <option value="<?= $key ?>"><?= safeOutput($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="warning_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['attachment']) ?></label>
                            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                            <small class="text-muted"><?= htmlspecialchars($LANG['file_hint_10mb']) ?></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['reason']) ?> <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="4" required maxlength="2000" placeholder="<?= safeOutput($LANG['describe_incident']) ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?></button>
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-check-circle me-1"></i><?= safeOutput($LANG['save']) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/employee_warnings.php?id='.$empId) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="edit_id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square text-warning me-2"></i><?= safeOutput($LANG['edit']) ?> <?= safeOutput($LANG['warning']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['warning_type']) ?></label>
                            <select name="warning_type" id="editType" class="form-select">
                                <?php foreach ($warnTypeLabels as $key => $label): ?>
                                <option value="<?= $key ?>"><?= safeOutput($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['date']) ?></label>
                            <input type="date" name="warning_date" id="editDate" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['attachment']) ?></label>
                            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                            <small class="text-muted"><?= safeOutput($LANG['leave_blank']) ?></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= safeOutput($LANG['reason']) ?> <span class="text-danger">*</span></label>
                            <textarea name="reason" id="editReason" class="form-control" rows="4" required maxlength="2000"></textarea>
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
    document.getElementById('editId').value     = d.id;
    document.getElementById('editType').value   = d.warning_type;
    document.getElementById('editDate').value   = d.warning_date;
    document.getElementById('editReason').value = d.reason;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function deleteWarning(id, type) {
    Swal.fire({
        title: <?php echo bltx_js($LANG['confirm']) ?>,
        text: '<?php echo substr(bltx_js($LANG['delete']), 1, -1) ?> ' + type + '?',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonText: <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText: <?php echo bltx_js($LANG['delete']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= url('payroll/employee_warnings.php?id='.$empId) ?>',
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
