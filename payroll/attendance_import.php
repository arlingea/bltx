<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "attendance_import" — MODUL 8: IMPORT MESIN ABSENSI

   Alur tiga langkah yang sengaja dipisah:
     1. UNGGAH  — berkas CSV disimpan sebagai satu batch
     2. BACA    — tiap baris disimpan MENTAH ke attendance_import_rows
                  dan dicocokkan ke employee_code. TIDAK ada satu pun
                  baris `attendance` yang disentuh di tahap ini.
     3. TERAPKAN— barulah baris yang cocok ditulis ke `attendance`.

   Pemisahan ini disengaja: Anda bisa meninjau hasil pencocokan lebih
   dulu, dan baris yang gagal tidak pernah menghentikan batch. Terapkan
   memakai INSERT ... ON DUPLICATE KEY UPDATE sehingga menjalankan ulang
   batch yang sama tidak menggandakan data.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('attendance_import');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('attendance_import');

$__ai_can_create = checkPermission('attendance_import', 'can_create');
$__ai_can_update = checkPermission('attendance_import', 'can_update');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && in_array(($_POST['action'] ?? ''), ['ai_upload', 'ai_apply'], true)) {

    if (!hrisCsrfValid()) {
        hrisFlash('error', $LANG['invalid_csrf']);
        redirect(payrollGateUrl('attendance_import'));
    }

    /* ── Unggah & baca ──────────────────────────────────────────────── */
    if ($_POST['action'] === 'ai_upload') {
        if (!$__ai_can_create) {
            hrisFlash('error', $LANG['perm_denied_target']);
            redirect(payrollGateUrl('attendance_import'));
        }

        $machine = hrisIn('machine_type', 'generic_csv');
        if (!array_key_exists($machine, hrisAttendanceFormats())) $machine = 'generic_csv';

        $f = $_FILES['csv_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            hrisFlash('error', $LANG['upload_failed']);
            redirect(payrollGateUrl('attendance_import'));
        }
        // Batasi jenis dan ukuran berkas.
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true) || (int)$f['size'] > 5 * 1024 * 1024) {
            hrisFlash('error', $LANG['invalid_file_type']);
            redirect(payrollGateUrl('attendance_import'));
        }

        $rows = hrisParseAttendanceCsv((string)$f['tmp_name'], $machine);
        if (empty($rows)) {
            hrisFlash('error', $LANG['import_no_rows']);
            redirect(payrollGateUrl('attendance_import'));
        }

        $conn->begin_transaction();
        try {
            $uid   = (int)($_SESSION['user_id'] ?? 0);
            $uname = hrisActorName();
            $fname = basename((string)$f['name']);
            $total = count($rows);

            $st = $conn->prepare(
                "INSERT INTO attendance_imports
                     (file_name, machine_type, total_rows, status, imported_by, imported_by_name)
                 VALUES (?, ?, ?, 'parsed', ?, ?)"
            );
            if (!$st) throw new RuntimeException('prepare_failed');
            $st->bind_param('ssiis', $fname, $machine, $total, $uid, $uname);
            if (!$st->execute()) { $st->close(); throw new RuntimeException('insert_failed'); }
            $importId = (int)$st->insert_id;
            $st->close();

            $stR = $conn->prepare(
                "INSERT INTO attendance_import_rows
                     (import_id, row_number, raw_employee_code, raw_date, raw_check_in,
                      raw_check_out, matched_employee_id, result, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stR) throw new RuntimeException('prepare_rows_failed');

            $stM = $conn->prepare("SELECT id FROM employees WHERE employee_code = ? LIMIT 1");
            if (!$stM) throw new RuntimeException('prepare_match_failed');

            $failed = 0;
            foreach ($rows as $r) {
                $code = (string)$r['raw_employee_code'];
                $matched = null; $result = 'pending'; $msg = '';

                $stM->bind_param('s', $code);
                $stM->execute();
                $m = $stM->get_result()->fetch_assoc();
                if ($m) {
                    $matched = (int)$m['id'];
                } else {
                    $result = 'error'; $msg = $LANG['import_employee_not_found']; $failed++;
                }
                if ($matched !== null && hrisNormaliseDate((string)$r['raw_date']) === null) {
                    $result = 'error'; $msg = $LANG['import_bad_date']; $failed++;
                }

                $rn = (int)$r['row_number'];
                $stR->bind_param('iisssssis',
                    $importId, $rn, $code, $r['raw_date'], $r['raw_check_in'],
                    $r['raw_check_out'], $matched, $result, $msg);
                $stR->execute();
            }
            $stR->close();
            $stM->close();

            $stU = $conn->prepare("UPDATE attendance_imports SET failed_rows = ? WHERE id = ?");
            if ($stU) { $stU->bind_param('ii', $failed, $importId); $stU->execute(); $stU->close(); }

            $conn->commit();
            hrisLog('attendance_import', 'parse', "Batch #{$importId} '{$fname}': {$total} rows, {$failed} failed");
            hrisFlash('success', $LANG['import_parsed_ok']);
        } catch (Throwable $e) {
            $conn->rollback();
            hrisFlash('error', $LANG['import_failed']);
        }
        redirect(payrollGateUrl('attendance_import'));
    }

    /* ── Terapkan ke tabel attendance ───────────────────────────────── */
    if (!$__ai_can_update) {
        hrisFlash('error', $LANG['perm_denied_target']);
        redirect(payrollGateUrl('attendance_import'));
    }
    $importId = (int)($_POST['import_id'] ?? 0);
    if ($importId <= 0) {
        hrisFlash('error', $LANG['import_failed']);
        redirect(payrollGateUrl('attendance_import'));
    }

    $conn->begin_transaction();
    try {
        $stS = $conn->prepare(
            "SELECT * FROM attendance_import_rows
              WHERE import_id = ? AND result = 'pending' AND matched_employee_id IS NOT NULL"
        );
        if (!$stS) throw new RuntimeException('prepare_failed');
        $stS->bind_param('i', $importId);
        $stS->execute();
        $pending = $stS->get_result()->fetch_all(MYSQLI_ASSOC);
        $stS->close();

        /* created_by_name = SALINAN PERMANEN nama pengimpor, konsisten dengan
           seluruh tabel lain di sistem ini. */
        $stA = $conn->prepare(
            "INSERT INTO attendance
                 (employee_id, attendance_date, check_in, check_out, status, created_by, created_by_name)
             VALUES (?, ?, ?, ?, 'present', ?, ?)
             ON DUPLICATE KEY UPDATE
                 check_in = VALUES(check_in), check_out = VALUES(check_out), status = 'present'"
        );
        if (!$stA) throw new RuntimeException('prepare_att_failed');

        $stM = $conn->prepare("UPDATE attendance_import_rows SET result = 'applied', message = '' WHERE id = ?");
        if (!$stM) throw new RuntimeException('prepare_mark_failed');

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $un  = hrisActorName();
        $applied = 0;

        foreach ($pending as $r) {
            $date = hrisNormaliseDate((string)$r['raw_date']);
            if ($date === null) continue;
            $eid = (int)$r['matched_employee_id'];
            $in  = trim((string)$r['raw_check_in'])  ?: null;
            $out = trim((string)$r['raw_check_out']) ?: null;

            $stA->bind_param('isssis', $eid, $date, $in, $out, $uid, $un);
            if ($stA->execute()) {
                $rid = (int)$r['id'];
                $stM->bind_param('i', $rid);
                $stM->execute();
                $applied++;
            }
        }
        $stA->close();
        $stM->close();

        $stU = $conn->prepare("UPDATE attendance_imports SET imported_rows = ?, status = 'applied' WHERE id = ?");
        if ($stU) { $stU->bind_param('ii', $applied, $importId); $stU->execute(); $stU->close(); }

        $conn->commit();
        hrisLog('attendance_import', 'apply', "Batch #{$importId}: {$applied} attendance rows applied");
        hrisFlash('success', $LANG['import_applied_ok']);
    } catch (Throwable $e) {
        $conn->rollback();
        hrisFlash('error', $LANG['import_failed']);
    }
    redirect(payrollGateUrl('attendance_import'));
}

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('attendance_import'); }

if (!hrisHasTable('attendance_imports')) {
    echo hrisMigrationNotice('attendance_imports');
    return;
}

$__ai_batches = [];
$__ai_res = @$conn->query("SELECT * FROM attendance_imports ORDER BY id DESC LIMIT 50");
if ($__ai_res) { $__ai_batches = $__ai_res->fetch_all(MYSQLI_ASSOC); $__ai_res->free(); }

$__ai_csrf   = hrisCsrfToken();
$__ai_status = [
    'uploaded' => $LANG['status_uploaded'], 'parsed' => $LANG['status_parsed'],
    'applied'  => $LANG['status_applied'],  'failed' => $LANG['failed'],
];
?>

<?php if ($__ai_can_create): ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-upload me-2"></i><?= hrisOut($LANG['attendance_import']) ?></div>
    <div class="card-body">
        <p class="text-muted small"><?= hrisOut($LANG['intro_attendance_import']) ?></p>
        <form method="POST" action="<?= hrisOut(payrollGateUrl('attendance_import')) ?>" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="ai_upload">
            <input type="hidden" name="csrf_token" value="<?= hrisOut($__ai_csrf) ?>">
            <div class="col-md-5">
                <label class="form-label" for="ai_file"><?= hrisOut($LANG['csv_file']) ?> <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="ai_file" name="csv_file" accept=".csv,.txt" required>
                <div class="form-text small"><?= hrisOut($LANG['hint_csv_columns']) ?></div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="ai_machine"><?= hrisOut($LANG['machine_type']) ?></label>
                <select class="form-select" id="ai_machine" name="machine_type">
                    <?php foreach (array_keys(hrisAttendanceFormats()) as $mt): ?>
                        <option value="<?= hrisOut($mt) ?>"><?= hrisOut($mt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-upload"></i> <?= hrisOut($LANG['upload']) ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i><?= hrisOut($LANG['import_history']) ?></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="ai_table">
                <thead>
                    <tr>
                        <th><?= hrisOut($LANG['file']) ?></th>
                        <th><?= hrisOut($LANG['machine_type']) ?></th>
                        <th><?= hrisOut($LANG['total']) ?></th>
                        <th><?= hrisOut($LANG['status_applied']) ?></th>
                        <th><?= hrisOut($LANG['failed']) ?></th>
                        <th><?= hrisOut($LANG['status']) ?></th>
                        <th><?= hrisOut($LANG['imported_by']) ?></th>
                        <?php if ($__ai_can_update): ?>
                        <th style="width:100px"><?= hrisOut($LANG['actions']) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($__ai_batches)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                <?php else: foreach ($__ai_batches as $b): ?>
                    <tr>
                        <td class="small"><?= hrisOut($b['file_name']) ?></td>
                        <td class="small"><?= hrisOut($b['machine_type']) ?></td>
                        <td class="small"><?= (int)$b['total_rows'] ?></td>
                        <td class="small"><?= (int)$b['imported_rows'] ?></td>
                        <td class="small <?= (int)$b['failed_rows'] > 0 ? 'text-danger' : '' ?>"><?= (int)$b['failed_rows'] ?></td>
                        <td class="small"><span class="badge bg-secondary-subtle text-secondary">
                            <?= hrisOut($__ai_status[$b['status']] ?? $b['status']) ?></span></td>
                        <td class="small"><?= hrisOut($b['imported_by_name']) ?></td>
                        <?php if ($__ai_can_update): ?>
                        <td>
                            <?php if ($b['status'] === 'parsed'): ?>
                            <button type="button" class="btn btn-success btn-sm"
                                    title="<?= hrisOut($LANG['apply']) ?>"
                                    onclick="aiApply(<?= (int)$b['id'] ?>)">
                                <i class="bi bi-check2-square"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted small">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($__ai_can_update): ?>
<form method="POST" action="<?= hrisOut(payrollGateUrl('attendance_import')) ?>" id="ai_applyform" class="d-none">
    <input type="hidden" name="action" value="ai_apply">
    <input type="hidden" name="csrf_token" value="<?= hrisOut($__ai_csrf) ?>">
    <input type="hidden" name="import_id" id="ai_import_id">
</form>
<script>
function aiApply(id) {
    Swal.fire({
        title: <?= json_encode((string)$LANG['apply'], JSON_UNESCAPED_UNICODE) ?>,
        text:  <?= json_encode((string)$LANG['confirm_apply_import'], JSON_UNESCAPED_UNICODE) ?>,
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#198754', cancelButtonColor: '#6c757d',
        confirmButtonText: <?= json_encode((string)$LANG['apply'], JSON_UNESCAPED_UNICODE) ?>,
        cancelButtonText:  <?= json_encode((string)$LANG['cancel'], JSON_UNESCAPED_UNICODE) ?>,
        reverseButtons: true
    }).then(function (r) {
        if (!r.isConfirmed) return;
        document.getElementById('ai_import_id').value = id;
        document.getElementById('ai_applyform').submit();
    });
}
</script>
<?php endif; ?>

<script>
(function () {
    function ready(fn){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);}else{fn();} }
    var tries = 0;
    function boot() {
        if (typeof jQuery === 'undefined') { if (++tries > 100) return; setTimeout(boot, 50); return; }
        if (jQuery.fn.DataTable) {
            jQuery('#ai_table').DataTable({
                pageLength: 15, order: [],
                columnDefs: [{ orderable: false, targets: -1 }],
                language: { search: '', searchPlaceholder: <?= json_encode((string)$LANG['search'], JSON_UNESCAPED_UNICODE) ?> }
            });
        }
    }
    ready(boot);
})();
</script>
