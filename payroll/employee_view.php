<?php
// File: payroll/employee_view.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';
requireLogin();
if (!checkPermission('employees', 'can_read') && !checkPermission('employees', 'can_access')) {
    redirectNoAccess();
}

$page_title = $LANG['employee_detail'];

$id = (int)($_GET['id'] ?? 0);
$emp = getEmployee($conn, $id);
if (!$emp) { header('Location: ' . payrollGateUrl('employees')); exit; }

// Get allowances
$stmt = $conn->prepare("SELECT ea.*, sc.component_name FROM employee_allowances ea JOIN employee_salary_components sc ON ea.component_key = sc.component_key WHERE ea.employee_id = ? ORDER BY sc.sort_order");
$stmt->bind_param('i', $id);
$stmt->execute();
$allowances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent attendance
$stmt = $conn->prepare("SELECT a.*, es.name AS shift_name FROM attendance a LEFT JOIN employee_shifts es ON a.shift_id = es.id WHERE a.employee_id = ? ORDER BY a.attendance_date DESC LIMIT 10");
$stmt->bind_param('i', $id);
$stmt->execute();
$recentAtt = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!empty($_GET['added'])) {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){Swal.fire("' . $LANG['success'] . '","Employee added successfully","success");});</script>';
}
if (!empty($_GET['updated'])) {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){Swal.fire("' . $LANG['success'] . '","' . $LANG['update_success'] . '","success");});</script>';
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-person-circle"></i><?= safeOutput($emp['full_name']) ?></h2>
    <div class="page-actions">
        <a href="<?= url('payroll/employee_edit.php?id=' . $id) ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> <?= $LANG['edit'] ?></a>
        <a href="<?= url('payroll/employee_allowances.php?id=' . $id) ?>" class="btn btn-sm btn-warning"><i class="bi bi-cash-stack"></i> <?= $LANG['allowances'] ?></a>
        <a href="<?= payrollGateUrl('attendance') . '&employee_id=' . $id ?>" class="btn btn-sm btn-success"><i class="bi bi-calendar-check"></i> <?= $LANG['attendance'] ?></a>
        <a href="<?= url('payroll/employee_documents.php?id=' . $id) ?>" class="btn btn-sm btn-info"><i class="bi bi-folder-fill"></i> <?= $LANG['documents'] ?></a>
        <a href="<?= url('payroll/employee_trainings.php?id=' . $id) ?>" class="btn btn-sm btn-success"><i class="bi bi-mortarboard-fill"></i> <?= $LANG['trainings'] ?></a>
        <a href="<?= url('payroll/employee_warnings.php?id=' . $id) ?>" class="btn btn-sm btn-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= $LANG['warnings'] ?></a>
        <a href="<?= payrollGateUrl('employees') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
    </div>
</div>

<div class="row g-4">
    <!-- Left: Photo & ID -->
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm text-center p-3 mb-3">
            <?php if ($emp['photo'] && file_exists(__DIR__ . '/../uploads/employee/employee_photos/' . $emp['photo'])): ?>
                <img src="<?= url('uploads/employee/employee_photos/' . $emp['photo']) ?>" class="rounded-circle mx-auto mb-3 d-block" width="120" height="120" style="object-fit:cover">
            <?php else: ?>
                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white mx-auto mb-3" style="width:120px;height:120px;font-size:42px"><?= strtoupper(substr($emp['full_name'],0,1)) ?></div>
            <?php endif; ?>
            <h5 class="fw-bold mb-1"><?= safeOutput($emp['full_name']) ?></h5>
            <?php if ($emp['nick_name']): ?><div class="text-muted small mb-2">"<?= safeOutput($emp['nick_name']) ?>"</div><?php endif; ?>
            <div class="badge bg-secondary mb-1"><?= safeOutput($emp['employee_code']) ?></div><br>
            <?php $badges=['active'=>'success','inactive'=>'secondary','resigned'=>'warning','terminated'=>'danger']; ?>
            <span class="badge bg-<?= $badges[$emp['employee_status']] ?? 'secondary' ?> mt-1"><?= ucfirst($emp['employee_status']) ?></span>
            <hr>
            <div class="text-start small">
                <div><i class="bi bi-briefcase me-1 text-muted"></i><?= safeOutput($emp['position'] ?? '-') ?></div>
                <div><i class="bi bi-building me-1 text-muted"></i><?= safeOutput($emp['department'] ?? '-') ?></div>
                <div><i class="bi bi-clock me-1 text-muted"></i><?= safeOutput($emp['shift_name'] ?? '-') ?></div>
            </div>
        </div>
        <?php if ($emp['cv_file'] && file_exists(__DIR__ . '/../uploads/employee/employee_document/' . $emp['cv_file'])): ?>
        <div class="card border-0 shadow-sm p-3 text-center">
            <a href="<?= url('uploads/employee/employee_document/' . $emp['cv_file']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                <i class="bi bi-file-earmark-person"></i> <?= $LANG['cv_document'] ?>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Details -->
    <div class="col-lg-9">
        <!-- Personal -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-person me-2 text-primary"></i><?= $LANG['personal_information'] ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <?php $rows = [
                        [$LANG['nik'], $emp['nik']],
                        [$LANG['npwp'], $emp['npwp']],
                        [$LANG['bpjs_kesehatan'], $emp['bpjs_kesehatan']],
                        [$LANG['bpjs_ketenagakerjaan'], $emp['bpjs_ketenagakerjaan']],
                        [$LANG['birth_date'], $emp['birth_date'] ? date('d M Y', strtotime($emp['birth_date'])) : '-'],
                        [$LANG['birth_place'], $emp['birth_place']],
                        [$LANG['gender'], ucfirst($emp['gender'])],
                        [$LANG['marital_status'], ucfirst($emp['marital_status'])],
                        [$LANG['blood_type'], $emp['blood_type']],
                        [$LANG['religion'], $emp['religion']],
                        [$LANG['phone'], $emp['phone']],
                        [$LANG['email'], $emp['email']],
                        [$LANG['emergency_phone'], $emp['emergency_phone']],
                        [$LANG['emergency_contact_name'], $emp['emergency_contact_name']],
                    ]; foreach ($rows as [$label, $val]): ?>
                    <div class="col-md-6"><div class="d-flex"><span class="text-muted small me-2" style="min-width:140px"><?= $label ?>:</span><span class="small fw-semibold"><?= safeOutput($val ?? '-') ?></span></div></div>
                    <?php endforeach; ?>
                    <div class="col-12"><div class="d-flex"><span class="text-muted small me-2" style="min-width:140px"><?= $LANG['address'] ?>:</span><span class="small fw-semibold"><?= safeOutput($emp['address'] ?? '-') ?></span></div></div>
                </div>
            </div>
        </div>

        <!-- Employment -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-briefcase me-2 text-success"></i><?= $LANG['employment_information'] ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <?php $rows2 = [
                        [$LANG['join_date'], $emp['join_date'] ? date('d M Y', strtotime($emp['join_date'])) : '-'],
                        [$LANG['employment_type'], ucfirst(str_replace('_',' ',$emp['employment_type']))],
                        [$LANG['basic_salary'], formatCurrency($emp['basic_salary'])],
                        [$LANG['bank_name'], $emp['bank_name']],
                        [$LANG['bank_account'], $emp['bank_account_number']],
                        [$LANG['account_name'], $emp['bank_account_name']],
                    ]; foreach ($rows2 as [$label, $val]): ?>
                    <div class="col-md-6"><div class="d-flex"><span class="text-muted small me-2" style="min-width:140px"><?= $label ?>:</span><span class="small fw-semibold"><?= safeOutput($val ?? '-') ?></span></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Allowances -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-cash-stack me-2 text-warning"></i><?= $LANG['allowances'] ?></div>
                <div class="card-tools"><a href="<?= url('payroll/employee_allowances.php?id=' . $id) ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil-square"></i> <?= $LANG['manage'] ?></a></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th><?= htmlspecialchars($LANG['component']) ?></th><th class="text-end"><?= htmlspecialchars($LANG['amount']) ?></th></tr></thead>
                        <tbody>
                        <?php if (empty($allowances)): ?>
                            <tr><td colspan="2" class="text-center text-muted py-3"><?= $LANG['no_data'] ?></td></tr>
                        <?php else: foreach ($allowances as $al): ?>
                            <tr><td><?= safeOutput($al['component_name']) ?></td><td class="text-end"><?= formatCurrency($al['amount']) ?></td></tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-calendar-check me-2 text-success"></i><?= $LANG['recent_attendance'] ?></div>
                <div class="card-tools"><a href="<?= payrollGateUrl('attendance') . '&employee_id=' . $id ?>" class="btn btn-success btn-sm btn-sm"><?= $LANG['view_all'] ?></a></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th><?= htmlspecialchars($LANG['date']) ?></th><th><?= htmlspecialchars($LANG['status']) ?></th><th><?= htmlspecialchars($LANG['check_in']) ?></th><th><?= htmlspecialchars($LANG['check_out']) ?></th><th><?= htmlspecialchars($LANG['late']) ?></th></tr></thead>
                        <tbody>
                        <?php if (empty($recentAtt)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3"><?= $LANG['no_data'] ?></td></tr>
                        <?php else: foreach ($recentAtt as $a): 
                            $statusColors = ['present'=>'success','absent'=>'danger','permission'=>'warning','sick'=>'info','holiday'=>'secondary','leave'=>'primary'];
                            $sc = $statusColors[$a['status']] ?? 'secondary';
                        ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($a['attendance_date'])) ?></td>
                                <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($a['status']) ?></span></td>
                                <td><?= $a['check_in'] ? date('H:i', strtotime($a['check_in'])) : '-' ?></td>
                                <td><?= $a['check_out'] ? date('H:i', strtotime($a['check_out'])) : '-' ?></td>
                                <td><?= $a['late_minutes'] > 0 ? $a['late_minutes'] . ' min' : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>