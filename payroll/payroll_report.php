<?php
// File: payroll/payroll_report.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

requirePayrollListAccess();
if (!canExportPayroll()) { redirectNoAccess(); }

$page_title = $LANG['payroll_report'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$filterPeriod = (int)($_GET['period_id'] ?? 0);
$filterDept   = (int)($_GET['department_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$viewMode     = $_GET['view'] ?? 'department'; // 'department' or 'employee'

// Export CSV
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $where = '1=1'; $params = []; $types = '';
    if ($filterPeriod) { $where .= ' AND pr.period_id=?'; $params[]=$filterPeriod; $types.='i'; }
    if ($filterDept)   { $where .= ' AND e.department_id=?'; $params[]=$filterDept; $types.='i'; }
    if ($filterStatus) { $where .= ' AND pr.status=?'; $params[]=$filterStatus; $types.='s'; }

    $sql = "SELECT e.employee_code, e.full_name, e.department, e.position,
                   pp.period_name,
                   pr.basic_salary, pr.meal_allowance, pr.transport_allowance,
                   pr.overtime_amount, pr.bonus, pr.other_allowance, pr.gross_salary,
                   pr.absence_deduction, pr.late_deduction, pr.bpjs_kesehatan_deduction,
                   pr.bpjs_ketenagakerjaan_deduction, pr.income_tax, pr.kasbon_deduction,
                   pr.other_deduction, pr.total_deduction, pr.net_salary,
                   pr.working_days, pr.present_days, pr.absent_days, pr.status
            FROM payroll pr
            JOIN employees e ON pr.employee_id = e.id
            JOIN payroll_periods pp ON pr.period_id = pp.id
            WHERE $where ORDER BY e.department, e.full_name";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payroll_report_'.date('Ymd').'.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Code','Name','Department','Position','Period','Basic Salary','Meal','Transport','Overtime','Bonus','Other Allow','Gross','Absence Ded','Late Ded','BPJS Health','BPJS Emp','Tax','Kasbon','Other Ded','Total Ded','Net Salary','Work Days','Present','Absent','Status']);
    foreach ($rows as $r) {
        fputcsv($out, array_values($r));
    }
    fclose($out);
    exit;
}

// Export PDF
if (!empty($_GET['export']) && $_GET['export'] === 'pdf') {
    $dompdfAutoload = __DIR__ . '/../assets/vendor/dompdf/vendor/autoload.php';
    if (!file_exists($dompdfAutoload)) {
        header('Location: ' . url('payroll/payroll_report.php?' . http_build_query(array_merge($_GET, ['export'=>'csv']))));
        exit;
    }
    require_once $dompdfAutoload;

    $where = '1=1'; $params = []; $types = '';
    if ($filterPeriod) { $where .= ' AND pr.period_id=?'; $params[]=$filterPeriod; $types.='i'; }
    if ($filterDept)   { $where .= ' AND e.department_id=?'; $params[]=$filterDept; $types.='i'; }
    if ($filterStatus) { $where .= ' AND pr.status=?'; $params[]=$filterStatus; $types.='s'; }

    $pdfSql = "SELECT e.employee_code, e.full_name, e.department, e.position,
                      pr.gross_salary, pr.total_deduction, pr.net_salary,
                      pr.present_days, pr.absent_days, pr.working_days, pr.status
               FROM payroll pr
               JOIN employees e ON pr.employee_id = e.id
               WHERE $where ORDER BY e.department, e.full_name";
    $stmt = $conn->prepare($pdfSql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $pdfRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $siteName    = defined('APP_NAME') ? APP_NAME : 'Company';
    $reportTitle = $LANG['payroll_report'];
    $printDate   = date('d F Y');

    $tableRows = '';
    $totalGross = $totalDed = $totalNet = 0;
    foreach ($pdfRows as $row) {
        $totalGross += $row['gross_salary'];
        $totalDed   += $row['total_deduction'];
        $totalNet   += $row['net_salary'];
        $tableRows .= '<tr>
            <td>' . htmlspecialchars($row['employee_code'], ENT_QUOTES, 'UTF-8') . '</td>
            <td>' . htmlspecialchars($row['full_name'],     ENT_QUOTES, 'UTF-8') . '</td>
            <td>' . htmlspecialchars($row['department']??'-', ENT_QUOTES, 'UTF-8') . '</td>
            <td style="text-align:right">' . formatCurrency($row['gross_salary']) . '</td>
            <td style="text-align:right;color:#dc2626">' . formatCurrency($row['total_deduction']) . '</td>
            <td style="text-align:right;font-weight:bold;color:#16a34a">' . formatCurrency($row['net_salary']) . '</td>
            <td style="text-align:center">' . $row['present_days'] . '/' . $row['working_days'] . '</td>
            <td style="text-align:center">' . ucfirst(htmlspecialchars($row['status'],ENT_QUOTES,'UTF-8')) . '</td>
        </tr>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        * { box-sizing: border-box; margin:0; padding:0; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a2a3a; }
        .hdr { background: #1a56db; color:#fff; padding: 14px 18px; border-radius: 5px; margin-bottom: 12px; }
        .hdr h1 { font-size: 15px; margin-bottom: 3px; }
        .hdr p  { font-size: 9px; opacity: .85; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #f0f4ff; color: #1e3a5f; font-weight: bold; padding: 5px 6px; border: 1px solid #d1d9e6; font-size: 8.5px; }
        td { padding: 4px 6px; border: 1px solid #e5e7eb; font-size: 8.5px; }
        tr:nth-child(even) td { background: #f9fafb; }
        tfoot td, tfoot th { background: #1a56db !important; color: #fff !important; font-weight: bold; }
        .footer { text-align: center; margin-top: 14px; font-size: 8px; color: #9ca3af; }
    </style></head><body>
    <div class="hdr">
        <h1>' . htmlspecialchars($siteName,ENT_QUOTES,'UTF-8') . ' — ' . htmlspecialchars($reportTitle,ENT_QUOTES,'UTF-8') . '</h1>
        <p>' . htmlspecialchars($printDate,ENT_QUOTES,'UTF-8') . ' &bull; ' . count($pdfRows) . ' employees</p>
    </div>
    <table>
        <thead><tr>
            <th>' . htmlspecialchars($LANG['code'],ENT_QUOTES,'UTF-8') . '</th><th>' . htmlspecialchars($LANG['full_name'],ENT_QUOTES,'UTF-8') . '</th>
            <th>' . htmlspecialchars($LANG['department'],ENT_QUOTES,'UTF-8') . '</th>
            <th style="text-align:right">' . htmlspecialchars($LANG['gross_salary'],ENT_QUOTES,'UTF-8') . '</th>
            <th style="text-align:right">' . htmlspecialchars($LANG['total_deduction'],ENT_QUOTES,'UTF-8') . '</th>
            <th style="text-align:right">' . htmlspecialchars($LANG['net_salary'],ENT_QUOTES,'UTF-8') . '</th>
            <th style="text-align:center">' . htmlspecialchars($LANG['present_days'],ENT_QUOTES,'UTF-8') . '</th>
            <th style="text-align:center">' . htmlspecialchars($LANG['status'],ENT_QUOTES,'UTF-8') . '</th>
        </tr></thead>
        <tbody>' . $tableRows . '</tbody>
        <tfoot><tr>
            <td colspan="3">' . htmlspecialchars($LANG['total'],ENT_QUOTES,'UTF-8') . ' (' . count($pdfRows) . ')</td>
            <td style="text-align:right">' . formatCurrency($totalGross) . '</td>
            <td style="text-align:right">' . formatCurrency($totalDed) . '</td>
            <td style="text-align:right">' . formatCurrency($totalNet) . '</td>
            <td colspan="2"></td>
        </tr></tfoot>
    </table>
    <div class="footer">' . htmlspecialchars($siteName,ENT_QUOTES,'UTF-8') . ' &bull; ' . htmlspecialchars($printDate,ENT_QUOTES,'UTF-8') . '</div>
    </body></html>';

    $dompdf = new \Dompdf\Dompdf([
        'enable_php'          => false,
        'enable_html5_parser' => true,
        'default_font'        => 'DejaVu Sans',
        'default_paper_size'  => 'a4',
        'default_paper_orientation' => 'landscape',
        'dpi'                 => 96,
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $filename = 'payroll_report_' . date('Ymd') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

// Load filter options
$periods = $conn->query("SELECT id, period_name FROM payroll_periods ORDER BY period_year DESC, period_month DESC")->fetch_all(MYSQLI_ASSOC);
$departments = $conn->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Summary by department
$deptWhere = '1=1'; $dParams = []; $dTypes = '';
if ($filterPeriod) { $deptWhere .= ' AND pr.period_id=?'; $dParams[]=$filterPeriod; $dTypes.='i'; }
if ($filterDept)   { $deptWhere .= ' AND e.department_id=?'; $dParams[]=$filterDept; $dTypes.='i'; }
if ($filterStatus) { $deptWhere .= ' AND pr.status=?'; $dParams[]=$filterStatus; $dTypes.='s'; }

$deptSql = "SELECT COALESCE(e.department, '(No Department)') AS dept_name,
                   COUNT(*) AS emp_count,
                   SUM(pr.gross_salary) AS total_gross,
                   SUM(pr.total_deduction) AS total_ded,
                   SUM(pr.net_salary) AS total_net
            FROM payroll pr
            JOIN employees e ON pr.employee_id = e.id
            WHERE $deptWhere
            GROUP BY e.department, e.department_id
            ORDER BY e.department";
$stmt = $conn->prepare($deptSql);
if ($dParams) $stmt->bind_param($dTypes, ...$dParams);
$stmt->execute();
$deptRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary totals
$totGross = array_sum(array_column($deptRows, 'total_gross'));
$totDed   = array_sum(array_column($deptRows, 'total_ded'));
$totNet   = array_sum(array_column($deptRows, 'total_net'));
$totEmp   = array_sum(array_column($deptRows, 'emp_count'));

// Employee detail rows
$empRows = [];
if ($viewMode === 'employee') {
    $empSql = "SELECT e.employee_code, e.full_name, e.department, e.position,
                      pr.gross_salary, pr.total_deduction, pr.net_salary,
                      pr.present_days, pr.absent_days, pr.working_days, pr.status
               FROM payroll pr
               JOIN employees e ON pr.employee_id = e.id
               WHERE $deptWhere
               ORDER BY e.department, e.full_name";
    $stmt = $conn->prepare($empSql);
    if ($dParams) $stmt->bind_param($dTypes, ...$dParams);
    $stmt->execute();
    $empRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-bar-chart-fill"></i><?= $LANG['payroll_report'] ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('payroll_list') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
        <?php if (!empty($deptRows)): ?>
        <a href="<?= url('payroll/payroll_report.php?'.http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-excel"></i> <?= $LANG['export_excel'] ?></a>
        <a href="<?= url('payroll/payroll_report.php?'.http_build_query(array_merge($_GET, ['export'=>'pdf']))) ?>" class="btn btn-sm btn-danger" target="_blank"><i class="bi bi-file-earmark-pdf"></i> <?= $LANG['export_pdf'] ?></a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold"><?= $LANG['payroll_periods'] ?></label>
                <select name="period_id" class="form-select form-select-sm">
                    <option value="">-- <?= $LANG['all'] ?> --</option>
                    <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= ($filterPeriod==$p['id'])?'selected':'' ?>><?= safeOutput($p['period_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold"><?= $LANG['department'] ?></label>
                <select name="department_id" class="form-select form-select-sm">
                    <option value="">-- <?= $LANG['all'] ?> --</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($filterDept==$d['id'])?'selected':'' ?>><?= safeOutput($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?= $LANG['status'] ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">-- <?= $LANG['all'] ?> --</option>
                    <?php foreach (['draft','approved','paid'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($filterStatus===$s)?'selected':'' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold"><?= $LANG['view'] ?></label>
                <select name="view" class="form-select form-select-sm">
                    <option value="department" <?= $viewMode==='department'?'selected':'' ?>><?= $LANG['summary_by_department'] ?></option>
                    <option value="employee"   <?= $viewMode==='employee'?'selected':'' ?>><?= $LANG['summary_by_employee'] ?></option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search"></i></button>
                <a href="<?= url('payroll/payroll_report.php') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($deptRows)): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i><?= $LANG['no_data'] ?></div>
<?php else: ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small"><?= $LANG['total_employees'] ?></div>
                <div class="fw-bold fs-4"><?= number_format($totEmp) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small"><?= $LANG['gross_salary'] ?></div>
                <div class="fw-bold text-primary"><?= formatCurrency($totGross) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small"><?= $LANG['total_deduction'] ?></div>
                <div class="fw-bold text-danger"><?= formatCurrency($totDed) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small"><?= $LANG['net_salary'] ?></div>
                <div class="fw-bold text-success"><?= formatCurrency($totNet) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($viewMode === 'department'): ?>
<!-- Department Summary Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i><?= $LANG['summary_by_department'] ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="rptTable">
                <thead class="table-light">
                    <tr>
                        <th><?= $LANG['department'] ?></th>
                        <th class="text-end"><?= $LANG['employees'] ?></th>
                        <th class="text-end"><?= $LANG['gross_salary'] ?></th>
                        <th class="text-end"><?= $LANG['total_deduction'] ?></th>
                        <th class="text-end"><?= $LANG['net_salary'] ?></th>
                        <th class="text-end">%</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deptRows as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?= safeOutput($row['dept_name']) ?></td>
                        <td class="text-end"><?= number_format($row['emp_count']) ?></td>
                        <td class="text-end"><?= formatCurrency($row['total_gross']) ?></td>
                        <td class="text-end text-danger"><?= formatCurrency($row['total_ded']) ?></td>
                        <td class="text-end fw-bold text-success"><?= formatCurrency($row['total_net']) ?></td>
                        <td class="text-end text-muted small"><?= $totNet > 0 ? number_format($row['total_net']/$totNet*100,1).'%' : '0%' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <th><?= $LANG['total'] ?></th>
                        <th class="text-end"><?= number_format($totEmp) ?></th>
                        <th class="text-end"><?= formatCurrency($totGross) ?></th>
                        <th class="text-end"><?= formatCurrency($totDed) ?></th>
                        <th class="text-end"><?= formatCurrency($totNet) ?></th>
                        <th class="text-end">100%</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Employee Detail Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i><?= $LANG['summary_by_employee'] ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-sm" id="rptTable">
                <thead class="table-light">
                    <tr>
                        <th><?= $LANG['employee_code'] ?></th>
                        <th><?= $LANG['full_name'] ?></th>
                        <th class="d-none d-md-table-cell"><?= $LANG['department'] ?></th>
                        <th class="text-end"><?= $LANG['gross_salary'] ?></th>
                        <th class="text-end"><?= $LANG['total_deduction'] ?></th>
                        <th class="text-end"><?= $LANG['net_salary'] ?></th>
                        <th class="text-center"><?= $LANG['present_days'] ?></th>
                        <th><?= $LANG['status'] ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($empRows as $row): ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border"><?= safeOutput($row['employee_code']) ?></span></td>
                        <td>
                            <div><?= safeOutput($row['full_name']) ?></div>
                            <small class="text-muted"><?= safeOutput($row['position'] ?? '') ?></small>
                        </td>
                        <td class="d-none d-md-table-cell"><small><?= safeOutput($row['department'] ?? '-') ?></small></td>
                        <td class="text-end"><?= formatCurrency($row['gross_salary']) ?></td>
                        <td class="text-end text-danger"><?= formatCurrency($row['total_deduction']) ?></td>
                        <td class="text-end fw-bold text-success"><?= formatCurrency($row['net_salary']) ?></td>
                        <td class="text-center"><?= $row['present_days'] ?>/<?= $row['working_days'] ?></td>
                        <td><?php $sb=['draft'=>'secondary','approved'=>'primary','paid'=>'success'][$row['status']]??'secondary'; ?><span class="badge bg-<?= $sb ?>"><?= ucfirst($row['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <th colspan="3"><?= $LANG['total'] ?> (<?= count($empRows) ?>)</th>
                        <th class="text-end"><?= formatCurrency($totGross) ?></th>
                        <th class="text-end"><?= formatCurrency($totDed) ?></th>
                        <th class="text-end"><?= formatCurrency($totNet) ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('rptTable') && typeof $.fn.DataTable !== 'undefined') {
        $('#rptTable').DataTable({paging:true,pageLength:25,searching:true,info:true,order:[[0,'asc']],language:{emptyTable:'<?= $LANG['no_data'] ?>',search:'<?= $LANG['search'] ?>:'}});
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
