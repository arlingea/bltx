<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "employees" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('employees');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/employees.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

requireEmployeesAccess();

$page_title = $LANG['employees'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Handle POST actions (delete / export)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        if (!canDeleteEmployee()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE employees SET employee_status = 'inactive', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            logActivity('employee_delete', "Deactivated employee ID: $id");
            echo json_encode(['success'=>true]); exit;
        }
        echo json_encode(['success'=>false,'message'=>$conn->error]); exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// Build filter query
$where = '1=1'; $params = []; $types = '';
if (!empty($_GET['search'])) {
    $s = '%'.$_GET['search'].'%';
    $where .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ? OR e.nick_name LIKE ? OR e.position LIKE ? OR e.department LIKE ?)";
    $params = array_merge($params, [$s,$s,$s,$s,$s]); $types .= 'sssss';
}
if (!empty($_GET['status'])) {
    $where .= " AND e.employee_status = ?"; $params[] = $_GET['status']; $types .= 's';
}
if (!empty($_GET['department_id'])) {
    $where .= " AND e.department_id = ?"; $params[] = (int)$_GET['department_id']; $types .= 'i';
}
if (!empty($_GET['employment_type'])) {
    $where .= " AND e.employment_type = ?"; $params[] = $_GET['employment_type']; $types .= 's';
}

$sql = "SELECT e.*, es.name AS shift_name FROM employees e LEFT JOIN employee_shifts es ON e.shift_id = es.id WHERE $where ORDER BY e.full_name ASC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$departments = $conn->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('employees'); }
?>
<!-- Filter -->
<div class="card mb-4">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-person-badge-fill"></i><?= $LANG['employees'] ?></div>
        <div class="card-tools">
            <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
            <?php if (canCreateEmployee()): ?>
            <a href="<?= url('payroll/employee_add.php') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> <?= $LANG['add_employee'] ?></a>
            <?php endif; ?>
            <?php if (canExportEmployee()): ?>
            <a href="<?= url('payroll/employee_add.php?export=1&'.http_build_query($_GET)) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> <?= $LANG['export'] ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-sm-4">
                <label class="form-label small fw-semibold"><?= $LANG['search'] ?></label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="<?= $LANG['full_name'] ?>, Code..." value="<?= safeOutput($_GET['search'] ?? '') ?>">
            </div>
            <div class="col-6 col-sm-2">
                <label class="form-label small fw-semibold"><?= $LANG['status'] ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?= $LANG['all'] ?></option>
                    <?php foreach (['active','inactive','resigned','terminated'] as $s): ?>
                    <option value="<?= $s ?>" <?= (($_GET['status']??'')===$s)?'selected':'' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-2">
                <label class="form-label small fw-semibold"><?= $LANG['department'] ?></label>
                <select name="department_id" class="form-select form-select-sm">
                    <option value=""><?= $LANG['all'] ?></option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= (($_GET['department_id']??'')==$d['id'])?'selected':'' ?>><?= safeOutput($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-2">
                <label class="form-label small fw-semibold"><?= $LANG['employment_type'] ?></label>
                <select name="employment_type" class="form-select form-select-sm">
                    <option value=""><?= $LANG['all'] ?></option>
                    <?php foreach (['permanent','contract','part_time','probation'] as $t): ?>
                    <option value="<?= $t ?>" <?= (($_GET['employment_type']??'')===$t)?'selected':'' ?>><?= ucwords(str_replace('_',' ',$t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel"></i> <?= htmlspecialchars($LANG['filter']) ?></button>
                <a href="<?= payrollGateUrl('employees') ?>" class="btn btn-secondary btn-sm" title="<?= htmlspecialchars($LANG['reset']) ?>"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="empTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:45px"><?= $LANG['col_no'] ?></th>
                        <th style="width:50px"><?= $LANG['photo'] ?></th>
                        <th><?= $LANG['employee_code'] ?></th>
                        <th><?= $LANG['full_name'] ?></th>
                        <th class="d-none d-md-table-cell"><?= $LANG['position'] ?></th>
                        <th class="d-none d-lg-table-cell"><?= $LANG['department'] ?></th>
                        <th class="d-none d-xl-table-cell"><?= $LANG['shift'] ?></th>
                        <th class="d-none d-xl-table-cell"><?= $LANG['basic_salary'] ?></th>
                        <th><?= $LANG['status'] ?></th>
                        <th style="width:110px"><?= $LANG['actions'] ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="10" class="text-center py-4 text-muted"><i class="bi bi-inbox me-2"></i><?= $LANG['no_data'] ?></td></tr>
                <?php else: $i=1; foreach ($employees as $emp): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td>
                            <?php if ($emp['photo'] && file_exists(__DIR__.'/../uploads/employee/employee_photos/'.$emp['photo'])): ?>
                                <img src="<?= url('uploads/employee/employee_photos/'.rawurlencode($emp['photo'])) ?>" class="rounded-circle" width="38" height="38" style="object-fit:cover" loading="lazy" alt="">
                            <?php else: ?>
                                <div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center text-white fw-bold" style="width:38px;height:38px;font-size:15px"><?= strtoupper(substr($emp['full_name'],0,1)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= safeOutput($emp['employee_code']) ?></span></td>
                        <td>
                            <div class="fw-semibold"><?= safeOutput($emp['full_name']) ?></div>
                            <?php if ($emp['nick_name']): ?><small class="text-muted"><?= safeOutput($emp['nick_name']) ?></small><?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?= safeOutput($emp['position'] ?? '-') ?></small></td>
                        <td class="d-none d-lg-table-cell"><small><?= safeOutput($emp['department'] ?? '-') ?></small></td>
                        <td class="d-none d-xl-table-cell"><small><?= safeOutput($emp['shift_name'] ?? '-') ?></small></td>
                        <td class="d-none d-xl-table-cell"><?= formatCurrency($emp['basic_salary']) ?></td>
                        <td>
                            <?php $badge=['active'=>'success','inactive'=>'secondary','resigned'=>'warning','terminated'=>'danger'][$emp['employee_status']]??'secondary'; ?>
                            <span class="badge bg-<?= $badge ?>"><?= ucfirst($emp['employee_status']) ?></span>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= url('payroll/employee_view.php?id='.$emp['id']) ?>" class="btn btn-sm btn-info" title="<?= $LANG['view'] ?>"><i class="bi bi-eye"></i></a>
                                <?php if (canUpdateEmployee()): ?>
                                <a href="<?= url('payroll/employee_edit.php?id='.$emp['id']) ?>" class="btn btn-sm btn-primary" title="<?= $LANG['edit'] ?>"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <a href="<?= url('payroll/employee_documents.php?id='.$emp['id']) ?>" class="btn btn-sm btn-info" title="<?= $LANG['documents'] ?>"><i class="bi bi-folder"></i></a>
                                <?php if (canDeleteEmployee() && $emp['employee_status'] === 'active'): ?>
                                <button class="btn btn-sm btn-danger btn-delete" data-id="<?= $emp['id'] ?>" data-name="<?= safeOutput($emp['full_name']) ?>" title="<?= $LANG['delete'] ?>"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted small"><?= count($employees) ?> <?= strtolower($LANG['employees']) ?></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('empTable') && typeof $.fn.DataTable !== 'undefined') {
        $('#empTable').DataTable({paging:true,pageLength:25,searching:false,info:true,order:[[3,'asc']],columnDefs:[{orderable:false,targets:[0,1,9]}],language:{emptyTable:'<?= $LANG['no_data'] ?>'}});
    }
    document.querySelectorAll('.btn-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id, name = this.dataset.name;
            Swal.fire({title:'<?= $LANG['are_you_sure'] ?>',text:'<?= $LANG['deactivate_employee'] ?> "'+name+'"?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',confirmButtonText:'<?= $LANG['yes'] ?>',cancelButtonText:'<?= $LANG['cancel'] ?>'}).then(function(result) {
                if (result.isConfirmed) {
                    var fd = new FormData();
                    fd.append('action','delete'); fd.append('id',id); fd.append('csrf_token','<?= $_SESSION['csrf_token'] ?>');
                    fetch('<?= url('payroll/employees.php') ?>',{method:'POST',body:fd}).then(r=>r.json()).then(function(res) {
                        if (res.success) { Swal.fire('<?= $LANG['success'] ?>','','success').then(()=>location.reload()); }
                        else { Swal.fire('<?= $LANG['error'] ?>',res.message,'error'); }
                    });
                }
            });
        });
    });
});
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
