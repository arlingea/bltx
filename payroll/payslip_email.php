<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "payslip_email" — MODUL 9: PENGIRIMAN SLIP GAJI

   Slip gaji dimasukkan ke ANTREAN, bukan langsung dikirim. Alasannya
   jujur dan disengaja: pengiriman sesungguhnya butuh kredensial SMTP
   yang belum tersedia di sistem ini. Sampai itu diisi, setiap slip
   tercatat berstatus 'queued' lengkap dengan tujuan dan waktunya,
   sehingga riwayatnya utuh dan bisa dikirim ulang tanpa menebak.

   Yang perlu Anda isi agar pengiriman benar-benar jalan: host, port,
   user, dan sandi SMTP. Begitu tersedia, hanya satu fungsi yang perlu
   diubah — hrisQueuePayslipEmail() di includes/hris_functions.php.

   Halaman ini TIDAK menyentuh tabel `payroll` sama sekali; ia hanya
   membaca dan menulis payslip_email_log.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('payslip_email');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('payslip_email');

$__pe_can_create = checkPermission('payslip_email', 'can_create');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'pe_queue') {
    if (!hrisCsrfValid()) {
        hrisFlash('error', $LANG['invalid_csrf']);
        redirect(payrollGateUrl('payslip_email'));
    }
    if (!$__pe_can_create) {
        hrisFlash('error', $LANG['perm_denied_target']);
        redirect(payrollGateUrl('payslip_email'));
    }

    $periodId = (int)($_POST['period_id'] ?? 0);
    if ($periodId <= 0) {
        hrisFlash('error', $LANG['please_complete_required_fields']);
        redirect(payrollGateUrl('payslip_email'));
    }

    /* Hanya karyawan yang PUNYA email yang diantrekan. Yang tidak punya
       dilewati dan dihitung, bukan dibuatkan baris gagal — agar daftar
       antrean tetap bersih. */
    $st = $conn->prepare(
        "SELECT p.id AS payroll_id, e.id AS employee_id, e.full_name, e.email
           FROM payroll p
           JOIN employees e ON e.id = p.employee_id
          WHERE p.period_id = ?"
    );
    $queued = 0; $skipped = 0;
    if ($st) {
        $st->bind_param('i', $periodId);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        foreach ($rows as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
            $subject = $LANG['official_payslip'] . ' - ' . $r['full_name'];
            if (hrisQueuePayslipEmail((int)$r['payroll_id'], (int)$r['employee_id'],
                                      (string)$r['full_name'], $email, $subject)) {
                $queued++;
            }
        }
    }
    hrisFlash('success', $LANG['payslip_queued'] . ': ' . $queued . ' / ' . $LANG['skipped'] . ': ' . $skipped);
    redirect(payrollGateUrl('payslip_email'));
}

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('payslip_email'); }

if (!hrisHasTable('payslip_email_log')) {
    echo hrisMigrationNotice('payslip_email_log');
    return;
}

$__pe_periods = [];
$__pe_pres = @$conn->query("SELECT id, period_name FROM payroll_periods ORDER BY id DESC");
if ($__pe_pres) { $__pe_periods = $__pe_pres->fetch_all(MYSQLI_ASSOC); $__pe_pres->free(); }

$__pe_logs = [];
$__pe_lres = @$conn->query("SELECT * FROM payslip_email_log ORDER BY id DESC LIMIT 200");
if ($__pe_lres) { $__pe_logs = $__pe_lres->fetch_all(MYSQLI_ASSOC); $__pe_lres->free(); }

$__pe_csrf = hrisCsrfToken();
$__pe_stat = ['queued' => $LANG['status_queued'], 'sent' => $LANG['status_sent'], 'failed' => $LANG['failed']];
?>

<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div><?= hrisOut($LANG['smtp_not_configured_notice']) ?></div>
</div>

<?php if ($__pe_can_create): ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-envelope-paper-fill me-2"></i><?= hrisOut($LANG['queue_payslips']) ?></div>
    <div class="card-body">
        <p class="text-muted small"><?= hrisOut($LANG['intro_payslip_email']) ?></p>
        <form method="POST" action="<?= hrisOut(payrollGateUrl('payslip_email')) ?>" class="row g-3">
            <input type="hidden" name="action" value="pe_queue">
            <input type="hidden" name="csrf_token" value="<?= hrisOut($__pe_csrf) ?>">
            <div class="col-md-8">
                <label class="form-label" for="pe_period"><?= hrisOut($LANG['payroll_period']) ?> <span class="text-danger">*</span></label>
                <select class="form-select" id="pe_period" name="period_id" required>
                    <option value=""><?= hrisOut($LANG['choose']) ?></option>
                    <?php foreach ($__pe_periods as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= hrisOut($p['period_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-send"></i> <?= hrisOut($LANG['add_to_queue']) ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-list-ul me-2"></i><?= hrisOut($LANG['payslip_email_log']) ?></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="pe_table">
                <thead>
                    <tr>
                        <th><?= hrisOut($LANG['employee']) ?></th>
                        <th><?= hrisOut($LANG['email']) ?></th>
                        <th><?= hrisOut($LANG['subject']) ?></th>
                        <th><?= hrisOut($LANG['status']) ?></th>
                        <th><?= hrisOut($LANG['sent_by']) ?></th>
                        <th><?= hrisOut($LANG['date']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($__pe_logs)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                <?php else: foreach ($__pe_logs as $l): ?>
                    <tr>
                        <td class="small"><?= hrisOut($l['employee_name']) ?></td>
                        <td class="small"><?= hrisOut($l['email_to']) ?></td>
                        <td class="small"><?= hrisOut($l['subject']) ?></td>
                        <td class="small"><span class="badge bg-secondary-subtle text-secondary">
                            <?= hrisOut($__pe_stat[$l['status']] ?? $l['status']) ?></span></td>
                        <td class="small"><?= hrisOut($l['sent_by_name']) ?></td>
                        <td class="small"><?= hrisOut(date('d-m-Y H:i', strtotime((string)$l['created_at']))) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    function ready(fn){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);}else{fn();} }
    var tries = 0;
    function boot() {
        if (typeof jQuery === 'undefined') { if (++tries > 100) return; setTimeout(boot, 50); return; }
        if (jQuery.fn.DataTable) {
            jQuery('#pe_table').DataTable({
                pageLength: 25, order: [],
                language: { search: '', searchPlaceholder: <?= json_encode((string)$LANG['search'], JSON_UNESCAPED_UNICODE) ?> }
            });
        }
    }
    ready(boot);
})();
</script>
