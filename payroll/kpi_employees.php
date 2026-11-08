<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "kpi" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('kpi');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/kpi_functions.php';

if (!isLoggedIn()) { redirect(url('index.php')); exit; }
if (!_canAccess('kpi', 'can_access')) { redirectNoAccess(); exit; }

$page_title = $LANG['kpi_employees'];
/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('kpi'); }

$_can_create = _canAccess('kpi', 'can_create');
$_can_update = _canAccess('kpi', 'can_update');
$_can_delete = _canAccess('kpi', 'can_delete');

$tablesExist = kpi_tablesExist($conn);
?>
<div class="container-fluid">
    <?php if (!$tablesExist): ?>
    <div class="alert alert-warning d-flex align-items-start gap-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-3 flex-shrink-0"></i>
        <div class="flex-grow-1">
            <h5 class="alert-heading mb-1"><?= htmlspecialchars($LANG['kpi_setup_required']) ?></h5>
            <p class="mb-2"><?= htmlspecialchars($LANG['kpi_tables_missing_msg']) ?></p>
            <p class="mb-2"><code>migrations/2026_kpi_employee.sql</code></p>
            <ol class="mb-0 small">
                <li>Buka phpMyAdmin → pilih database</li>
                <li>Tab "Import" → upload <code>migrations/2026_kpi_employee.sql</code></li>
                <li>Klik "Go" → refresh halaman ini</li>
            </ol>
        </div>
    </div>
    <?php else: ?>

    <?php
    // Filters
    $periods   = kpi_getPeriods($conn);
    $periodId  = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
    $period    = $periodId > 0 ? kpi_getPeriod($conn, $periodId) : null;
    $evals     = $periodId > 0 ? kpi_getEvaluations($conn, $periodId) : [];
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <div class="card-title"><i class="bi bi-funnel"></i> <?= htmlspecialchars($LANG['filter']) ?></div>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label"><?= htmlspecialchars($LANG['kpi_period']) ?></label>
                    <select name="period_id" class="form-select">
                        <?php if (empty($periods)): ?>
                            <option><?= htmlspecialchars($LANG['kpi_no_periods']) ?></option>
                        <?php else: foreach ($periods as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $p['id'] == $periodId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?> · <?= htmlspecialchars($p['status']) ?>
                            </option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-arrow-clockwise"></i> <?= htmlspecialchars($LANG['apply']) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($periods)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        <?= htmlspecialchars($LANG['kpi_create_period_first']) ?>
        <a href="<?= url('payroll/kpi_periods.php') ?>" class="alert-link"><?= htmlspecialchars($LANG['kpi_manage_periods']) ?></a>
    </div>
    <?php else: ?>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="bi bi-trophy"></i>
                <?= htmlspecialchars($LANG['kpi_results']) ?>
                <?php if ($period): ?>
                    <small class="text-muted ms-2"><?= htmlspecialchars($period['name']) ?></small>
                <?php endif; ?>
            </div>
            <div class="card-tools">
                <a href="<?= url('payroll/kpi_indicators.php') ?>" class="btn btn-secondary btn-sm">
                    <i class="bi bi-list-check"></i> <?= htmlspecialchars($LANG['kpi_manage_indicators']) ?>
                </a>
                <a href="<?= url('payroll/kpi_periods.php') ?>" class="btn btn-secondary btn-sm">
                    <i class="bi bi-calendar-range"></i> <?= htmlspecialchars($LANG['kpi_manage_periods']) ?>
                </a>
                <?php if ($_can_create): ?>
                <a href="<?= url('payroll/kpi_evaluate.php') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['kpi_new_evaluation']) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($evals)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                <p class="mb-0"><?= htmlspecialchars($LANG['kpi_no_evaluations']) ?></p>
                <?php if ($_can_create): ?>
                <a href="<?= url('payroll/kpi_evaluate.php?period_id=' . $periodId) ?>" class="btn btn-sm btn-primary mt-3">
                    <i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['kpi_start_evaluation']) ?>
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="kpiTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px;">#</th>
                            <th><?= htmlspecialchars($LANG['employee']) ?></th>
                            <th><?= htmlspecialchars($LANG['department']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($LANG['kpi_score']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($LANG['kpi_grade']) ?></th>
                            <th><?= htmlspecialchars($LANG['status']) ?></th>
                            <th><?= htmlspecialchars($LANG['kpi_evaluator']) ?></th>
                            <th class="text-center" style="width:140px;"><?= htmlspecialchars($LANG['actions']) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($evals as $ev):
                            $gradeColor = ['A'=>'success','B'=>'info','C'=>'warning','D'=>'danger','E'=>'dark'][$ev['grade'] ?? 'C'] ?? 'secondary';
                            $statusColor = ['draft'=>'secondary','submitted'=>'info','approved'=>'success','rejected'=>'danger'][$ev['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($ev['employee_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($ev['nip'] ?? '') ?> · <?= htmlspecialchars($ev['position_name'] ?? '—') ?></small>
                            </td>
                            <td><?= htmlspecialchars($ev['department_name'] ?? '—') ?></td>
                            <td class="text-center">
                                <span class="fw-bold fs-5"><?= number_format((float)$ev['total_score'], 2) ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $gradeColor ?> fs-6 px-3"><?= htmlspecialchars($ev['grade'] ?? '—') ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $statusColor ?>"><?= htmlspecialchars(ucfirst($ev['status'])) ?></span>
                            </td>
                            <td><?= htmlspecialchars($ev['evaluator_name'] ?? '—') ?></td>
                            <td class="text-center">
                                <a href="<?= url('payroll/kpi_view.php?id=' . (int)$ev['id']) ?>" class="btn btn-sm btn-info" title="<?= htmlspecialchars($LANG['view']) ?>"><i class="bi bi-eye"></i></a>
                                <?php if ($_can_update && $ev['status'] !== 'approved'): ?>
                                <a href="<?= url('payroll/kpi_evaluate.php?id=' . (int)$ev['id']) ?>" class="btn btn-sm btn-primary" title="<?= htmlspecialchars($LANG['edit']) ?>"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
