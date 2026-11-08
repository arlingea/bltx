<?php
// File: payroll/employee_documents.php
// HRIS — Manage employee documents (KTP, NPWP, CV, certificates, contracts, etc.)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
if (!checkPermission('employees', 'can_access')) redirectNoAccess();

$empId = (int)($_GET['id'] ?? 0);
$emp   = $empId > 0 ? getEmployee($conn, $empId) : null;
if (!$emp) { header('Location: ' . payrollGateUrl('employees')); exit; }

$page_title = ($LANG['employee_documents']) . ' — ' . $emp['full_name'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ─── AJAX DELETE ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    ob_clean(); header('Content-Type: application/json');
    if (!canUpdateEmployee()) { echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>$LANG['invalid_csrf']]); exit;
    }
    $docId = (int)($_POST['id'] ?? 0);
    // Get file path before deleting
    $stF = $conn->prepare("SELECT file_path FROM employee_documents WHERE id=? AND employee_id=?");
    $stF->bind_param('ii', $docId, $empId); $stF->execute();
    $fRow = $stF->get_result()->fetch_assoc(); $stF->close();
    $stD = $conn->prepare("DELETE FROM employee_documents WHERE id=? AND employee_id=?");
    $stD->bind_param('ii', $docId, $empId);
    if ($stD->execute() && $stD->affected_rows > 0) {
        // Delete physical file
        if ($fRow && !empty($fRow['file_path'])) {
            $fp = __DIR__ . '/../uploads/employee/documents/' . $fRow['file_path'];
            if (file_exists($fp)) @unlink($fp);
        }
        $stD->close();
        logActivity('employee_doc_delete', "Deleted doc ID $docId for emp $empId");
        echo json_encode(['success'=>true,'message'=>$LANG['delete_success']]); exit;
    }
    $stD->close();
    echo json_encode(['success'=>false,'message'=>$LANG['error']]); exit;
}

/* ─── POST: ADD ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_delete'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        bltx_flash_error($LANG['invalid_csrf']); redirect(url('payroll/employee_documents.php?id=' . $empId));
    }
    if (!canUpdateEmployee()) { bltx_flash_error($LANG['no_permission']); redirect(url('payroll/employee_documents.php?id='.$empId)); }

    $docType    = trim($_POST['doc_type']    ?? 'other');
    $docName    = trim($_POST['doc_name']    ?? '');
    $expiry     = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $notes      = trim($_POST['notes']       ?? '') ?: null;
    $allowedTypes = ['ktp','npwp','cv','certificate','contract','other'];
    if (!in_array($docType, $allowedTypes, true)) $docType = 'other';

    if (!$docName) { bltx_flash_error('Document name is required'); redirect(url('payroll/employee_documents.php?id='.$empId)); }

    $filePath = null;
    if (!empty($_FILES['doc_file']['name'])) {
        $up = uploadEmployeeAttachment($_FILES['doc_file'], 'documents');
        if (isset($up['error'])) { bltx_flash_error($up['error']); redirect(url('payroll/employee_documents.php?id='.$empId)); }
        $filePath = $up['filename'];
    }

    if (!$filePath) { bltx_flash_error('Please upload a file'); redirect(url('payroll/employee_documents.php?id='.$empId)); }

    /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
       tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
    $st = $conn->prepare("INSERT INTO employee_documents (employee_id, doc_type, doc_name, file_path, expiry_date, notes, created_by, created_by_name) VALUES (?,?,?,?,?,?,?,(SELECT full_name FROM users WHERE id = ?))");
    $st->bind_param('isssssii', $empId, $docType, $docName, $filePath, $expiry, $notes, $_SESSION['user_id'], $_SESSION['user_id']);
    if ($st->execute()) {
        logActivity('employee_doc_add', "Added document '$docName' for emp $empId");
        bltx_flash_success($LANG['save_success']);
    } else {
        bltx_flash_error($conn->error);
    }
    $st->close();
    redirect(url('payroll/employee_documents.php?id=' . $empId));
}

/* ─── DATA ────────────────────────────────────────────────────────────────── */
$stList = $conn->prepare("SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY created_at DESC");
$stList->bind_param('i', $empId); $stList->execute();
$documents = $stList->get_result()->fetch_all(MYSQLI_ASSOC); $stList->close();

$docTypeLabels = [
    'ktp'         => $LANG['ktp'],
    'npwp'        => $LANG['npwp'],
    'cv'          => $LANG['cv_document'],
    'certificate' => $LANG['certificate'],
    'contract'    => $LANG['contract'],
    'other'       => $LANG['other'],
];

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

<!-- Breadcrumb Header -->
<div class="page-title-bar">
    <div>
        <h2 class="page-title-text">
            <i class="bi bi-folder-fill text-info"></i><?= safeOutput($LANG['employee_documents']) ?>
        </h2>
        <div class="page-subtitle">
            <a href="<?= url('payroll/employee_view.php?id=' . $empId) ?>" class="text-decoration-none">
                <?= safeOutput($emp['full_name']) ?>
            </a>
            <span class="badge bg-light text-dark border ms-2"><?= safeOutput($emp['employee_code']) ?></span>
        </div>
    </div>
    <div class="page-actions">
        <a href="<?= url('payroll/employee_view.php?id=' . $empId) ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
        <?php if (canUpdateEmployee()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i><?= safeOutput($LANG['add_document']) ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Documents Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px"><?= safeOutput($LANG['col_no']) ?></th>
                        <th><?= safeOutput($LANG['doc_type']) ?></th>
                        <th><?= safeOutput($LANG['doc_name']) ?></th>
                        <th class="d-none d-md-table-cell"><?= safeOutput($LANG['expiry_date']) ?></th>
                        <th class="d-none d-lg-table-cell"><?= safeOutput($LANG['notes']) ?></th>
                        <th class="d-none d-md-table-cell"><?= safeOutput($LANG['uploaded']) ?></th>
                        <th class="text-center" style="width:110px"><?= safeOutput($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($documents)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-folder2-open fs-3 d-block mb-2"></i><?= safeOutput($LANG['no_data']) ?>
                    </td></tr>
                <?php else: $i=1; foreach ($documents as $doc):
                    $typeLabel = $docTypeLabels[$doc['doc_type']] ?? ucfirst($doc['doc_type']);
                    $typeBadge = ['ktp'=>'primary','npwp'=>'info','cv'=>'success','certificate'=>'warning','contract'=>'dark','other'=>'secondary'][$doc['doc_type']] ?? 'secondary';
                    $isExpired = $doc['expiry_date'] && $doc['expiry_date'] < date('Y-m-d');
                    $expiresSOon = $doc['expiry_date'] && !$isExpired && $doc['expiry_date'] < date('Y-m-d', strtotime('+30 days'));
                    $ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                    $fileIcon = ($ext === 'pdf') ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary';
                ?>
                    <tr>
                        <td class="text-muted small"><?= $i++ ?></td>
                        <td><span class="badge bg-<?= $typeBadge ?>"><?= safeOutput($typeLabel) ?></span></td>
                        <td>
                            <a href="<?= url('uploads/employee/documents/' . rawurlencode($doc['file_path'])) ?>" target="_blank" class="text-decoration-none fw-semibold">
                                <i class="bi <?= $fileIcon ?> me-1"></i><?= safeOutput($doc['doc_name']) ?>
                            </a>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <?php if ($doc['expiry_date']): ?>
                                <span class="<?= $isExpired ? 'text-danger fw-semibold' : ($expiresSOon ? 'text-warning fw-semibold' : '') ?>">
                                    <?= date('d M Y', strtotime($doc['expiry_date'])) ?>
                                    <?= $isExpired ? '<i class="bi bi-exclamation-triangle ms-1"></i>' : '' ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="d-none d-lg-table-cell small text-muted"><?= safeOutput($doc['notes'] ?? '—') ?></td>
                        <td class="d-none d-md-table-cell small text-muted"><?= date('d M Y', strtotime($doc['created_at'])) ?></td>
                        <td class="text-center">
                            <a href="<?= url('uploads/employee/documents/' . rawurlencode($doc['file_path'])) ?>" target="_blank"
                               class="btn btn-sm btn-info" title="<?= safeOutput($LANG['view']) ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (canUpdateEmployee()): ?>
                            <button type="button" class="btn btn-sm btn-danger ms-1"
                                    onclick="deleteDoc(<?= (int)$doc['id'] ?>, <?= bltx_e(bltx_js($doc['doc_name'])) ?>)"
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
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('payroll/employee_documents.php?id=' . $empId) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle text-primary me-2"></i><?= safeOutput($LANG['add_document']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['doc_type']) ?></label>
                        <select name="doc_type" class="form-select">
                            <?php foreach ($docTypeLabels as $key => $label): ?>
                            <option value="<?= $key ?>"><?= safeOutput($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['doc_name']) ?> <span class="text-danger">*</span></label>
                        <input type="text" name="doc_name" class="form-control" required maxlength="200" placeholder="<?= htmlspecialchars($LANG['eg_document_name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['file']) ?> <span class="text-danger">*</span></label>
                        <input type="file" name="doc_file" class="form-control" required accept=".jpg,.jpeg,.png,.pdf">
                        <small class="text-muted">JPG, PNG, PDF — max 10MB</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['expiry_date']) ?></label>
                        <input type="date" name="expiry_date" class="form-control">
                        <small class="text-muted"><?= safeOutput($LANG['optional']) ?></small>
                    </div>
                    <div>
                        <label class="form-label fw-semibold"><?= safeOutput($LANG['notes']) ?></label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i><?= safeOutput($LANG['cancel']) ?></button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i><?= safeOutput($LANG['upload']) ?></button>
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
function deleteDoc(id, name) {
    Swal.fire({
        title: <?php echo bltx_js($LANG['confirm']) ?>,
        text: '<?php echo substr(bltx_js($LANG['delete']), 1, -1) ?> "' + name + '"?',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonText: <?php echo bltx_js($LANG['cancel']) ?>,
        confirmButtonText: <?php echo bltx_js($LANG['delete']) ?>
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= url('payroll/employee_documents.php?id=' . $empId) ?>',
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
