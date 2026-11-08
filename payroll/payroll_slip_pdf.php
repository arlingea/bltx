<?php
// File: payroll/payroll_slip_pdf.php
// Generate PDF payslip using dompdf (correct vendor path)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
requirePayrollListAccess();

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid payroll ID');

$data = getPayrollDetail($conn, $id);
if (!$data) die('Payroll record not found');

$p   = $data['payroll'];
$e   = $data['employee'];
$ea  = $data['allowances'] ?? [];
$att = $data['attendance'];

$siteName    = defined('APP_NAME') ? APP_NAME : 'Company';
$periodLabel = getMonthName((int)$p['period_month']) . ' ' . $p['period_year'];
$printDate   = date('d F Y');

// ── Earnings HTML ────────────────────────────────────────────────────────────
$earnings = [
    ($LANG['basic_salary'])        => (float)$p['basic_salary'],
    ($LANG['meal_allowance'])       => (float)$p['meal_allowance'],
    ($LANG['transport_allowance'])  => (float)$p['transport_allowance'],
    ($LANG['overtime'])             => (float)$p['overtime_amount'],
    ($LANG['bonus'])                => (float)$p['bonus'],
    ($LANG['other_allowance'])      => (float)$p['other_allowance'],
];
$earningsHtml = '';
$totalEarnings = 0;
foreach ($earnings as $label => $amt) {
    if ($amt == 0) continue;
    $totalEarnings += $amt;
    $earningsHtml .= '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                   . '<td style="text-align:right">' . formatCurrency($amt) . '</td></tr>';
}

// ── Deductions HTML ──────────────────────────────────────────────────────────
$deductions = [
    ($LANG['absence_deduction'])     => (float)$p['absence_deduction'],
    ($LANG['late_deduction'])        => (float)$p['late_deduction'],
    ($LANG['bpjs_kesehatan'])        => (float)$p['bpjs_kesehatan_deduction'],
    ($LANG['bpjs_ketenagakerjaan'])  => (float)$p['bpjs_ketenagakerjaan_deduction'],
    ($LANG['income_tax'])    => (float)$p['income_tax'],
    ($LANG['cash_advance']) => (float)$p['kasbon_deduction'],
    ($LANG['other_deduction'])       => (float)$p['other_deduction'],
];
$deductionsHtml = '';
$totalDeductions = 0;
foreach ($deductions as $label => $amt) {
    if ($amt == 0) continue;
    $totalDeductions += $amt;
    $deductionsHtml .= '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                     . '<td style="text-align:right;color:#dc2626">' . formatCurrency($amt) . '</td></tr>';
}

$netSalary = $totalEarnings - $totalDeductions;

// ── Build full HTML ──────────────────────────────────────────────────────────
$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip - ' . htmlspecialchars($e['full_name'], ENT_QUOTES, 'UTF-8') . '</title>
<style>
  /* DejaVu Sans supports Chinese, Japanese, Korean characters */
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1a2a3a; background: #fff; }
  .slip { padding: 24px; max-width: 740px; margin: 0 auto; }
  .header { text-align: center; padding: 16px; background: #1a56db; color: #fff; border-radius: 6px 6px 0 0; margin-bottom: 12px; }
  .header h1 { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
  .header p { font-size: 11px; opacity: .85; margin-bottom: 6px; }
  .badge-period { display: inline-block; background: rgba(255,255,255,.2); border-radius: 20px; padding: 3px 12px; font-size: 11px; }
  .emp-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 12px; }
  .emp-box .row { display: flex; gap: 8px; flex-wrap: wrap; }
  .emp-box .col { flex: 1; min-width: 120px; }
  .emp-box label { font-size: 9px; color: #888; display: block; margin-bottom: 2px; }
  .emp-box span { font-weight: 600; font-size: 11px; }
  .section-title { font-weight: 700; font-size: 11px; text-transform: uppercase; color: #4a5568; border-bottom: 2px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 8px; margin-top: 10px; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data td { padding: 4px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10.5px; }
  table.data tr:nth-child(even) td { background: #f8f9fa; }
  .totals-box { display: flex; gap: 12px; margin-top: 12px; }
  .tot-card { flex: 1; border-radius: 6px; padding: 9px 12px; }
  .tot-card.earn { background: #e8f4fd; border: 1px solid #bee3f8; }
  .tot-card.ded  { background: #fef3f2; border: 1px solid #fecaca; }
  .tot-card.net  { background: #f0fdf4; border: 1px solid #bbf7d0; }
  .tot-card label { font-size: 9px; color: #666; display: block; }
  .tot-card .val { font-size: 14px; font-weight: bold; margin-top: 2px; }
  .tot-card.earn .val { color: #1a3a5c; }
  .tot-card.ded  .val { color: #dc2626; }
  .tot-card.net  .val { color: #16a34a; }
  .att-row { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
  .att-item { flex: 1; min-width: 80px; background: #f8f9fa; border-radius: 6px; padding: 7px 10px; text-align: center; border: 1px solid #e9ecef; }
  .att-item .num { font-size: 16px; font-weight: bold; color: #1a3a5c; }
  .att-item label { font-size: 9px; color: #888; display: block; }
  .sig-row { display: flex; margin-top: 28px; gap: 16px; }
  .sig-box { flex: 1; text-align: center; }
  .sig-box .sig-line { border-top: 1px solid #333; margin-top: 48px; padding-top: 4px; font-size: 10px; }
  .footer { text-align: center; margin-top: 18px; font-size: 9px; color: #999; padding-top: 8px; border-top: 1px solid #eee; }
</style>
</head>
<body>
<div class="slip">
  <div class="header">
    <h1>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</h1>
    <p>' . htmlspecialchars($LANG['official_payslip'], ENT_QUOTES, 'UTF-8') . '</p>
    <div class="badge-period">Period: ' . htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') . '</div>
  </div>

  <div class="emp-box">
    <div class="row">
      <div class="col"><label>' . htmlspecialchars($LANG['employee_name'], ENT_QUOTES, 'UTF-8') . '</label><span>' . htmlspecialchars($e['full_name'], ENT_QUOTES, 'UTF-8') . '</span></div>
      <div class="col"><label>' . htmlspecialchars($LANG['employee_code'], ENT_QUOTES, 'UTF-8') . '</label><span>' . htmlspecialchars($e['employee_code'], ENT_QUOTES, 'UTF-8') . '</span></div>
      <div class="col"><label>' . htmlspecialchars($LANG['department'], ENT_QUOTES, 'UTF-8') . '</label><span>' . htmlspecialchars($e['department'] ?? '-', ENT_QUOTES, 'UTF-8') . '</span></div>
      <div class="col"><label>' . htmlspecialchars($LANG['position'], ENT_QUOTES, 'UTF-8') . '</label><span>' . htmlspecialchars($e['position'] ?? '-', ENT_QUOTES, 'UTF-8') . '</span></div>
    </div>
    <div class="row" style="margin-top:6px">
      <div class="col"><label>' . htmlspecialchars($LANG['bank'], ENT_QUOTES, 'UTF-8') . '</label><span>' . htmlspecialchars($e['bank_name'] ?? '-', ENT_QUOTES, 'UTF-8') . '</span></div>
      <div class="col"><label>' . htmlspecialchars($LANG['account_no'], ENT_QUOTES, 'UTF-8') . '</label><span>' . htmlspecialchars($e['bank_account_number'] ?? '-', ENT_QUOTES, 'UTF-8') . '</span></div>
      <div class="col"><label>' . htmlspecialchars($LANG['printed'], ENT_QUOTES, 'UTF-8') . '</label><span>' . htmlspecialchars($printDate, ENT_QUOTES, 'UTF-8') . '</span></div>
      <div class="col"><label>' . htmlspecialchars($LANG['status'], ENT_QUOTES, 'UTF-8') . '</label><span>' . ucfirst(htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8')) . '</span></div>
    </div>
  </div>

  <div style="display:flex; gap:14px">
    <div style="flex:1">
      <div class="section-title">' . htmlspecialchars($LANG['earnings'], ENT_QUOTES, 'UTF-8') . '</div>
      <table class="data">' . ($earningsHtml ?: '<tr><td colspan="2" style="text-align:center;color:#888">—</td></tr>') . '</table>
    </div>
    <div style="flex:1">
      <div class="section-title">' . htmlspecialchars($LANG['deductions'], ENT_QUOTES, 'UTF-8') . '</div>
      <table class="data">' . ($deductionsHtml ?: '<tr><td colspan="2" style="text-align:center;color:#888">' . htmlspecialchars($LANG['no_deductions'], ENT_QUOTES, 'UTF-8') . '</td></tr>') . '</table>
    </div>
  </div>

  <div class="totals-box">
    <div class="tot-card earn"><label>' . htmlspecialchars($LANG['total_earnings']) . '</label><div class="val">' . formatCurrency($totalEarnings) . '</div></div>
    <div class="tot-card ded"><label>' . htmlspecialchars($LANG['total_deductions']) . '</label><div class="val">' . formatCurrency($totalDeductions) . '</div></div>
    <div class="tot-card net"><label>' . htmlspecialchars($LANG['net_salary']) . '</label><div class="val">' . formatCurrency($netSalary) . '</div></div>
  </div>

  <div class="section-title" style="margin-top:14px">' . htmlspecialchars($LANG['attendance_summary'], ENT_QUOTES, 'UTF-8') . '</div>
  <div class="att-row">
    <div class="att-item"><div class="num">' . (int)($p['working_days'] ?? 0) . '</div><label>' . htmlspecialchars($LANG['working_days'], ENT_QUOTES, 'UTF-8') . '</label></div>
    <div class="att-item"><div class="num">' . (int)($p['present_days'] ?? 0) . '</div><label>' . htmlspecialchars($LANG['present'], ENT_QUOTES, 'UTF-8') . '</label></div>
    <div class="att-item"><div class="num">' . (int)($p['absent_days'] ?? 0) . '</div><label>' . htmlspecialchars($LANG['absent'], ENT_QUOTES, 'UTF-8') . '</label></div>
    <div class="att-item"><div class="num">' . (int)($p['late_count'] ?? 0) . '</div><label>' . htmlspecialchars($LANG['late'], ENT_QUOTES, 'UTF-8') . '</label></div>
    <div class="att-item"><div class="num">' . round((float)($p['overtime_hours'] ?? 0), 1) . '</div><label>' . htmlspecialchars($LANG['ot_hours'], ENT_QUOTES, 'UTF-8') . '</label></div>
  </div>

  <div class="sig-row">
    <div class="sig-box"><div class="sig-line">' . htmlspecialchars($LANG['employee'], ENT_QUOTES, 'UTF-8') . '<br><strong>' . htmlspecialchars($e['full_name'], ENT_QUOTES, 'UTF-8') . '</strong></div></div>
    <div class="sig-box"><div class="sig-line">' . htmlspecialchars($LANG['hr_manager'], ENT_QUOTES, 'UTF-8') . '<br><strong>__________________</strong></div></div>
    <div class="sig-box"><div class="sig-line">' . htmlspecialchars($LANG['director'], ENT_QUOTES, 'UTF-8') . '<br><strong>__________________</strong></div></div>
  </div>

  <div class="footer">This is a computer-generated payslip &bull; ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . ' &bull; ' . htmlspecialchars($printDate, ENT_QUOTES, 'UTF-8') . '</div>
</div>
</body>
</html>';

// ── Try dompdf — CORRECTED path ──────────────────────────────────────────────
$dompdfAutoload = __DIR__ . '/../assets/vendor/dompdf/vendor/autoload.php';
$useDompdf      = file_exists($dompdfAutoload);

if ($useDompdf) {
    require_once $dompdfAutoload;
    $dompdf = new \Dompdf\Dompdf([
        'enable_php'              => false,
        'enable_css_float'        => true,
        'enable_html5_parser'     => true,
        'default_font'            => 'DejaVu Sans', // supports CJK characters
        'default_media_type'      => 'print',
        'dpi'                     => 96,
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $empCode = preg_replace('/[^a-z0-9]/i', '_', $e['employee_code'] ?? 'emp');
    $filename = 'payslip_' . $empCode . '_' . $p['period_year'] . '_' . str_pad((int)$p['period_month'], 2, '0', STR_PAD_LEFT) . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
} else {
    // Fallback: print-ready HTML
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    echo '<script>window.onload = function(){ window.print(); }</script>';
}
exit;
