<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "payroll_list" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('payroll_list');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/payroll_list.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';
requirePayrollListAccess();

$page_title = $LANG['payroll_list'];

$filterPeriod = (int)($_GET['period_id'] ?? 0);
$filterEmp    = (int)($_GET['employee_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';

$where = '1=1'; $params = []; $types = '';
if ($filterPeriod) { $where .= ' AND pr.period_id = ?'; $params[] = $filterPeriod; $types .= 'i'; }
if ($filterEmp)    { $where .= ' AND pr.employee_id = ?'; $params[] = $filterEmp; $types .= 'i'; }
if ($filterStatus) { $where .= ' AND pr.status = ?'; $params[] = $filterStatus; $types .= 's'; }

$sql = "SELECT pr.*, e.full_name, e.employee_code, e.position, pp.period_name, pp.period_year, pp.period_month
    FROM payroll pr
    JOIN employees e ON pr.employee_id = e.id
    JOIN payroll_periods pp ON pr.period_id = pp.id
    WHERE $where ORDER BY pp.period_year DESC, pp.period_month DESC, e.full_name ASC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$payrolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$periods   = $conn->query("SELECT id, period_name FROM payroll_periods ORDER BY period_year DESC, period_month DESC")->fetch_all(MYSQLI_ASSOC);
$employees = getActiveEmployees($conn);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('payroll_list'); }
?>
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-receipt"></i><?= $LANG['payroll_list'] ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= htmlspecialchars($LANG['period']) ?></label>
                <select name="period_id" class="form-select form-select-sm">
                    <option value="">-- All Periods --</option>
                    <?php foreach ($periods as $p): ?><option value="<?= $p['id'] ?>" <?= ($filterPeriod == $p['id']) ? 'selected' : '' ?>><?= safeOutput($p['period_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= htmlspecialchars($LANG['employee']) ?></label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">-- All --</option>
                    <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= ($filterEmp == $e['id']) ? 'selected' : '' ?>><?= safeOutput($e['full_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= htmlspecialchars($LANG['status']) ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">-- All --</option>
                    <?php foreach (['draft','approved','paid'] as $s): ?><option value="<?= $s ?>" <?= ($filterStatus===$s)?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel"></i> <?= htmlspecialchars($LANG['filter']) ?></button>
                <a href="<?= payrollGateUrl('payroll_list') ?>" class="btn btn-secondary btn-sm" title="<?= htmlspecialchars($LANG['reset']) ?>"><i class="bi bi-x-circle"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="payrollTable">
                <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th><?= htmlspecialchars($LANG['period']) ?></th>
                        <th><?= htmlspecialchars($LANG['employee']) ?></th>
                        <th><?= htmlspecialchars($LANG['gross']) ?></th>
                        <th><?= htmlspecialchars($LANG['deduction']) ?></th>
                        <th><?= htmlspecialchars($LANG['net']) ?></th>
                        <th><?= htmlspecialchars($LANG['status']) ?></th>
                        <th><?= htmlspecialchars($LANG['email']) ?></th>
                        <th><?= htmlspecialchars($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($payrolls)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted"><?= $LANG['no_data'] ?></td></tr>
                <?php else: $i=1; foreach ($payrolls as $pr):
                    $psc = ['draft'=>'secondary','approved'=>'info','paid'=>'success'][$pr['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><span class="badge bg-light text-dark border"><?= safeOutput($pr['period_name']) ?></span></td>
                        <td>
                            <div class="fw-semibold small"><?= safeOutput($pr['full_name']) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= safeOutput($pr['employee_code']) ?></div>
                        </td>
                        <td><?= formatCurrency($pr['gross_salary']) ?></td>
                        <td class="text-danger">-<?= formatCurrency($pr['total_deduction']) ?></td>
                        <td class="fw-bold text-success"><?= formatCurrency($pr['net_salary']) ?></td>
                        <td><span class="badge bg-<?= $psc ?>"><?= ucfirst($pr['status']) ?></span></td>
                        <td><?= $pr['slip_sent'] ? '<span class="text-success"><i class="bi bi-envelope-check"></i></span>' : '<span class="text-muted">-</span>' ?></td>
                        <td>
                            <a href="<?= url('payroll/payroll_slip.php?id=' . $pr['id']) ?>" class="btn btn-sm btn-primary" title="<?= htmlspecialchars($LANG['view_slip'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-receipt"></i></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    if ($('#payrollTable tbody tr').length > 10) {
        $('#payrollTable').DataTable({ order: [] });
    }
});
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>