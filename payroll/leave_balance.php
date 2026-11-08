<?php
// File: payroll/leave_balance.php — NEW: Leave Balance / Quota Management
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

requireLeaveRequestsAccess();

$page_title = $LANG['leave_balance'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Handle AJAX save quota
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    ob_clean(); header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>$LANG['invalid_csrf']]); exit;
    }
    if (!checkPermission('leave_requests','can_update')) {
        echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit;
    }
    $action = $_POST['ajax_action'];

    if ($action === 'save_quota') {
        $empId = (int)$_POST['employee_id'];
        $year  = (int)$_POST['year'];
        $quota = max(0, (int)$_POST['annual_quota']);
        if ($empId <= 0 || $year < 2000) {
            echo json_encode(['success'=>false,'message'=>'Invalid data']); exit;
        }
        $stmt = $conn->prepare("INSERT INTO employee_leave_balance (employee_id, year, annual_quota)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE annual_quota=VALUES(annual_quota), updated_at=NOW()");
        if (!$stmt) {
            // Table may not exist — try to create it first
            $conn->query("CREATE TABLE IF NOT EXISTS `employee_leave_balance` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `employee_id` int(11) NOT NULL,
                `year` int(4) NOT NULL DEFAULT YEAR(NOW()),
                `annual_quota` int(3) NOT NULL DEFAULT 12,
                `carry_over` int(3) NOT NULL DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_emp_year` (`employee_id`,`year`),
                KEY `idx_employee_id` (`employee_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $stmt = $conn->prepare("INSERT INTO employee_leave_balance (employee_id, year, annual_quota)
                VALUES (?,?,?) ON DUPLICATE KEY UPDATE annual_quota=VALUES(annual_quota), updated_at=NOW()");
        }
        $stmt->bind_param('iii', $empId, $year, $quota);
        if ($stmt->execute()) {
            logActivity('leave_balance_save', "Set leave quota emp:$empId year:$year quota:$quota");
            echo json_encode(['success'=>true,'message'=>$LANG['save_success']]); exit;
        }
        echo json_encode(['success'=>false,'message'=>$conn->error]); exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

$filterYear = (int)($_GET['year'] ?? date('Y'));
$filterDept = (int)($_GET['department_id'] ?? 0);
$search     = trim($_GET['search'] ?? '');

// Try to ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS `employee_leave_balance` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `employee_id` int(11) NOT NULL,
    `year` int(4) NOT NULL DEFAULT YEAR(NOW()),
    `annual_quota` int(3) NOT NULL DEFAULT 12,
    `carry_over` int(3) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_emp_year` (`employee_id`,`year`),
    KEY `idx_employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$where = "e.employee_status = 'active'"; $params=[]; $types='';
if ($filterDept) { $where .= ' AND e.department_id=?'; $params[]=$filterDept; $types.='i'; }
if ($search)     { $where .= ' AND (e.full_name LIKE ? OR e.employee_code LIKE ?)'; $s='%'.$search.'%'; $params[]=$s;$params[]=$s; $types.='ss'; }

$sql = "SELECT e.id, e.employee_code, e.full_name, e.department, e.position,
               COALESCE(lb.annual_quota, 12) AS annual_quota,
               COALESCE(lb.carry_over, 0) AS carry_over,
               COALESCE((SELECT SUM(lr.total_days) FROM leave_requests lr
                          WHERE lr.employee_id=e.id AND lr.status='approved'
                          AND YEAR(lr.start_date)=?), 0) AS used_days
        FROM employees e
        LEFT JOIN employee_leave_balance lb ON lb.employee_id=e.id AND lb.year=?
        WHERE $where
        ORDER BY e.department, e.full_name";

$allParams = array_merge([$filterYear, $filterYear], $params);
$allTypes  = 'ii'.$types;
$stmt = $conn->prepare($sql);
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$departments = $conn->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-calendar2-check-fill"></i><?= $LANG['leave_balance'] ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('leave_requests') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?= $LANG['period_year'] ?></label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y=date('Y')+1; $y>=date('Y')-3; $y--): ?>
                    <option value="<?= $y ?>" <?= $filterYear==$y?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold"><?= $LANG['department'] ?></label>
                <select name="department_id" class="form-select form-select-sm">
                    <option value="">-- <?= $LANG['all'] ?> --</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filterDept==$d['id']?'selected':'' ?>><?= safeOutput($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-8 col-md-4">
                <label class="form-label small fw-semibold"><?= $LANG['search'] ?></label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?= safeOutput($search) ?>" placeholder="<?= $LANG['full_name'] ?>, Code...">
            </div>
            <div class="col-4 col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel"></i> <?= htmlspecialchars($LANG['filter']) ?></button>
                <a href="<?= url('payroll/leave_balance.php') ?>" class="btn btn-secondary btn-sm" title="<?= htmlspecialchars($LANG['reset']) ?>"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= $LANG['employee_code'] ?></th>
                        <th><?= $LANG['full_name'] ?></th>
                        <th class="d-none d-md-table-cell"><?= $LANG['department'] ?></th>
                        <th class="text-center"><?= $LANG['leave_quota'] ?></th>
                        <th class="text-center"><?= $LANG['leave_used'] ?></th>
                        <th class="text-center"><?= $LANG['leave_remaining'] ?></th>
                        <?php if (checkPermission('leave_requests','can_update')): ?>
                        <th class="text-center"><?= $LANG['actions'] ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted"><i class="bi bi-inbox me-2"></i><?= $LANG['no_data'] ?></td></tr>
                <?php else: foreach ($employees as $emp):
                    $total = $emp['annual_quota'] + $emp['carry_over'];
                    $remaining = $total - $emp['used_days'];
                    $pct = $total > 0 ? min(100, $emp['used_days']/$total*100) : 0;
                    $barColor = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success');
                ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border"><?= safeOutput($emp['employee_code']) ?></span></td>
                        <td>
                            <div class="fw-semibold"><?= safeOutput($emp['full_name']) ?></div>
                            <small class="text-muted"><?= safeOutput($emp['position']??'') ?></small>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?= safeOutput($emp['department']??'-') ?></small></td>
                        <td class="text-center">
                            <span class="fw-bold"><?= $emp['annual_quota'] ?></span>
                            <?php if ($emp['carry_over']>0): ?><small class="text-muted">+<?= $emp['carry_over'] ?></small><?php endif; ?>
                        </td>
                        <td class="text-center"><?= $emp['used_days'] ?></td>
                        <td class="text-center">
                            <span class="fw-bold <?= $remaining<3?'text-danger':($remaining<6?'text-warning':'text-success') ?>"><?= $remaining ?></span>
                            <div class="progress mt-1" style="height:4px;width:60px;margin:0 auto">
                                <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= round($pct) ?>%"></div>
                            </div>
                        </td>
                        <?php if (checkPermission('leave_requests','can_update')): ?>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary btn-edit-quota"
                                data-id="<?= $emp['id'] ?>"
                                data-name="<?= safeOutput($emp['full_name']) ?>"
                                data-quota="<?= $emp['annual_quota'] ?>"
                                data-year="<?= $filterYear ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted small"><?= count($employees) ?> <?= strtolower($LANG['employees']) ?> — <?= $LANG['period_year'] ?>: <?= $filterYear ?></div>
</div>

<!-- Edit Quota Modal -->
<div class="modal fade" id="modalQuota" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-calendar2-check me-2"></i><?= $LANG['leave_quota'] ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3" id="quotaEmpName"></p>
                <label class="form-label small fw-semibold"><?= $LANG['leave_quota'] ?> (<?= $filterYear ?>)</label>
                <input type="number" id="quotaInput" class="form-control" min="0" max="365" value="12">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> <?= $LANG['cancel'] ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveQuota"><i class="bi bi-check-lg"></i> <?= $LANG['save'] ?></button>
            </div>
        </div>
    </div>
</div>

<script>
var _csrf = '<?= $_SESSION['csrf_token'] ?>';
var _year = <?= $filterYear ?>;
var _quotaEmpId = 0;

document.querySelectorAll('.btn-edit-quota').forEach(function(btn) {
    btn.addEventListener('click', function() {
        _quotaEmpId = this.dataset.id;
        document.getElementById('quotaEmpName').textContent = this.dataset.name;
        document.getElementById('quotaInput').value = this.dataset.quota;
        new bootstrap.Modal(document.getElementById('modalQuota')).show();
    });
});

document.getElementById('btnSaveQuota')?.addEventListener('click', function() {
    var fd = new FormData();
    fd.append('ajax_action','save_quota');
    fd.append('csrf_token',_csrf);
    fd.append('employee_id',_quotaEmpId);
    fd.append('year',_year);
    fd.append('annual_quota',document.getElementById('quotaInput').value);
    fetch('<?= url('payroll/leave_balance.php') ?>',{method:'POST',body:fd})
        .then(r=>r.json()).then(function(res) {
            bootstrap.Modal.getInstance(document.getElementById('modalQuota')).hide();
            if (res.success) { Swal.fire('<?= $LANG['success'] ?>','<?= $LANG['save_success'] ?>','success').then(()=>location.reload()); }
            else { Swal.fire('<?= $LANG['error'] ?>',res.message,'error'); }
        });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
