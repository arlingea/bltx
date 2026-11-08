<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "payroll_settings" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('payroll_settings');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/payroll_settings.php
// Refactored: uses permission-based access (replaces old role-only check)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

if (!isLoggedIn()) redirect(url('index.php'));
requirePayrollSettingsAccess();

$page_title = $LANG['payroll_settings'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$message = '';

/* ───────────────────────────────────────────────────────────
   POST: Save settings
─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canUpdatePayrollSettings()) {
        $message = '<div class="alert alert-danger"><i class="bi bi-lock me-2"></i>' . safeOutput($LANG['no_permission']) . '</div>';
    } elseif (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' . safeOutput($LANG['invalid_csrf']) . '</div>';
    } else {
        $fields = ['multiplier_hour_1','multiplier_hour_2plus','late_deduction_per_minute',
                   'bpjs_kesehatan_rate','bpjs_ketenagakerjaan_rate','pph21_rate','ptkp_monthly'];
        $vals = [];
        foreach ($fields as $f) {
            $vals[$f] = floatval($_POST[$f] ?? 0);
        }
        $uid = (int)$_SESSION['user_id'];
        /* updated_by_name = SALINAN PERMANEN users.full_name saat setelan
           diubah, tanpa foreign key sehingga tetap terbaca setelah akun
           users-nya dihapus. */
        $st  = $conn->prepare(
            "UPDATE overtime_settings SET
                 multiplier_hour_1=?, multiplier_hour_2plus=?, late_deduction_per_minute=?,
                 bpjs_kesehatan_rate=?, bpjs_ketenagakerjaan_rate=?, pph21_rate=?, ptkp_monthly=?,
                 updated_by=?,
                 updated_by_name=(SELECT full_name FROM users WHERE id = ?)
             WHERE id=1"
        );
        $st->bind_param('dddddddii',
            $vals['multiplier_hour_1'], $vals['multiplier_hour_2plus'], $vals['late_deduction_per_minute'],
            $vals['bpjs_kesehatan_rate'], $vals['bpjs_ketenagakerjaan_rate'], $vals['pph21_rate'], $vals['ptkp_monthly'],
            $uid, $uid
        );
        if ($st->execute()) {
            logActivity('payroll_settings', 'Updated payroll settings');
            $message = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>' . safeOutput($LANG['payroll_settings_saved']) . '</div>';
        } else {
            $message = '<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>' . safeOutput($conn->error) . '</div>';
        }
        $st->close();
    }
}

/* ───────────────────────────────────────────────────────────
   DATA
─────────────────────────────────────────────────────────── */
$settings = getOvertimeSettings($conn);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('payroll_settings'); }
?>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-gear-fill text-secondary"></i><?= safeOutput($LANG['payroll_settings']) ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= safeOutput($LANG['back']) ?>
        </a>
    </div>
</div>

<?= $message ?>

<div class="row g-4">
    <!-- Overtime Settings -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-clock-history me-2 text-warning"></i><?= safeOutput($LANG['overtime_settings']) ?></div>
            </div>
            <div class="card-body">
                <?php if (canUpdatePayrollSettings()): ?>
                <form method="POST" action="<?= url('payroll/payroll_settings.php') ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['multiplier_hour_1']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-x-circle"></i></span>
                        <input type="number" name="multiplier_hour_1" class="form-control"
                               value="<?= number_format((float)$settings['multiplier_hour_1'], 1) ?>"
                               step="0.1" min="1" max="5"
                               <?= !canUpdatePayrollSettings() ? 'readonly' : '' ?>>
                    </div>
                    <small class="text-muted"><?= safeOutput($LANG['standard_label']) ?>: 1.5×</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['multiplier_hour_2plus']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-x-circle"></i></span>
                        <input type="number" name="multiplier_hour_2plus" class="form-control"
                               value="<?= number_format((float)$settings['multiplier_hour_2plus'], 1) ?>"
                               step="0.1" min="1" max="5"
                               <?= !canUpdatePayrollSettings() ? 'readonly' : '' ?>>
                    </div>
                    <small class="text-muted"><?= safeOutput($LANG['standard_label']) ?>: 2.0×</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['late_deduction_per_minute']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-currency-exchange"></i></span>
                        <input type="number" name="late_deduction_per_minute" class="form-control"
                               value="<?= number_format((float)$settings['late_deduction_per_minute'], 2) ?>"
                               step="100" min="0"
                               <?= !canUpdatePayrollSettings() ? 'readonly' : '' ?>>
                    </div>
                    <small class="text-muted"><?= safeOutput($LANG['per_minute_late']) ?></small>
                </div>

                <!-- Separator -->
                <hr class="my-3">
                <h6 class="text-muted small text-uppercase mb-3"><?= safeOutput($LANG['deductions']) ?></h6>

                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['bpjs_kesehatan_rate']) ?></label>
                    <div class="input-group">
                        <input type="number" name="bpjs_kesehatan_rate" class="form-control"
                               value="<?= number_format((float)$settings['bpjs_kesehatan_rate'], 2) ?>"
                               step="0.01" min="0" max="10"
                               <?= !canUpdatePayrollSettings() ? 'readonly' : '' ?>>
                        <span class="input-group-text">%</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['bpjs_ketenagakerjaan_rate']) ?></label>
                    <div class="input-group">
                        <input type="number" name="bpjs_ketenagakerjaan_rate" class="form-control"
                               value="<?= number_format((float)$settings['bpjs_ketenagakerjaan_rate'], 2) ?>"
                               step="0.01" min="0" max="10"
                               <?= !canUpdatePayrollSettings() ? 'readonly' : '' ?>>
                        <span class="input-group-text">%</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['pph21_rate']) ?></label>
                    <div class="input-group">
                        <input type="number" name="pph21_rate" class="form-control"
                               value="<?= number_format((float)$settings['pph21_rate'], 2) ?>"
                               step="0.01" min="0" max="50"
                               <?= !canUpdatePayrollSettings() ? 'readonly' : '' ?>>
                        <span class="input-group-text">%</span>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold"><?= safeOutput($LANG['ptkp_monthly']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="number" name="ptkp_monthly" class="form-control"
                               value="<?= (float)$settings['ptkp_monthly'] ?>"
                               step="10000" min="0"
                               <?= !canUpdatePayrollSettings() ? 'readonly' : '' ?>>
                    </div>
                    <small class="text-muted"><?= safeOutput($LANG['ptkp_hint']) ?></small>
                </div>

                <?php if (canUpdatePayrollSettings()): ?>
                <div class="d-grid">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save me-2"></i><?= safeOutput($LANG['save_settings']) ?>
                    </button>
                </div>
                </form>
                <?php else: ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-lock me-2"></i><?= safeOutput($LANG['view_only']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Info Panel -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-info-circle me-2 text-info"></i><?= safeOutput($LANG['payroll_formula_info']) ?></div>
            </div>
            <div class="card-body small">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted fw-semibold"><?= safeOutput($LANG['overtime_formula']) ?></td>
                            <td>Basic Salary ÷ 173 × Multiplier × Hours</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><?= safeOutput($LANG['bpjs_formula']) ?></td>
                            <td>Basic Salary × Rate%</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><?= safeOutput($LANG['pph21_formula']) ?></td>
                            <td>(Gross – PTKP) × Rate%</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><?= safeOutput($LANG['absence_formula']) ?></td>
                            <td>Basic Salary ÷ Working Days × Absent Days</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><?= safeOutput($LANG['late_formula']) ?></td>
                            <td>Late Minutes × Rate / Minute</td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold"><?= safeOutput($LANG['net_formula']) ?></td>
                            <td>Gross – Total Deductions</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-calendar-check me-2 text-success"></i><?= safeOutput($LANG['working_days_per_month']) ?></div>
            </div>
            <div class="card-body small text-muted">
                <p class="mb-1"><?= safeOutput($LANG['working_days_desc']) ?></p>
                <p class="mb-0"><?= safeOutput($LANG['holiday_table_note']) ?>
                    <?php if (checkPermission('holidays', 'can_access')): ?>
                    <a href="<?= payrollGateUrl('holidays') ?>"><i class="bi bi-arrow-right-circle ms-1"></i></a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>
