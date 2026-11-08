<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "org_chart" — MODUL 2: STRUKTUR ORGANISASI

   Menetapkan ATASAN LANGSUNG tiap karyawan (employees.manager_id) dan
   menampilkannya sebagai pohon. Kolom manager_id sengaja TANPA foreign
   key, dan nama atasan ikut disimpan permanen di manager_name, sehingga
   penonaktifan atau penghapusan atasan tidak pernah mengubah baris
   bawahannya.

   Modul 3 (approval bertingkat) membaca kolom inilah untuk langkah
   bertipe 'manager'.

   Hanya karyawan berstatus AKTIF yang bisa dipilih sebagai atasan.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('org_chart');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('org_chart');

$__oc_can_update = checkPermission('org_chart', 'can_update');

/* ── Simpan atasan langsung ──────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'oc_set_manager') {

    if (!hrisCsrfValid()) {
        hrisFlash('error', $LANG['invalid_csrf']);
        redirect(payrollGateUrl('org_chart'));
    }
    if (!$__oc_can_update) {
        hrisFlash('error', $LANG['perm_denied_target']);
        redirect(payrollGateUrl('org_chart'));
    }

    $empId = (int)($_POST['employee_id'] ?? 0);
    $mgrId = (int)($_POST['manager_id'] ?? 0);

    if ($empId <= 0) {
        hrisFlash('error', $LANG['save_failed_generic']);
        redirect(payrollGateUrl('org_chart'));
    }
    // Seseorang tidak boleh menjadi atasan dirinya sendiri.
    if ($mgrId === $empId) {
        hrisFlash('error', $LANG['manager_cannot_be_self']);
        redirect(payrollGateUrl('org_chart'));
    }

    /* Cegah lingkaran: telusuri rantai atasan calon; bila bertemu $empId,
       penetapan ini akan membuat siklus dan ditolak. Batas 50 langkah
       menjaga penelusuran tetap berhenti walau data lama sudah melingkar. */
    if ($mgrId > 0) {
        $cursor = $mgrId; $guard = 0; $cycle = false;
        $stC = $conn->prepare("SELECT manager_id FROM employees WHERE id = ? LIMIT 1");
        if ($stC) {
            while ($cursor > 0 && $guard++ < 50) {
                $stC->bind_param('i', $cursor);
                $stC->execute();
                $r = $stC->get_result()->fetch_assoc();
                $cursor = (int)($r['manager_id'] ?? 0);
                if ($cursor === $empId) { $cycle = true; break; }
            }
            $stC->close();
        }
        if ($cycle) {
            hrisFlash('error', $LANG['manager_would_create_loop']);
            redirect(payrollGateUrl('org_chart'));
        }
    }

    // Nama atasan disalin permanen agar tidak hilang bila akunnya dihapus.
    $mgrName = '';
    if ($mgrId > 0) {
        $stN = $conn->prepare("SELECT full_name FROM employees WHERE id = ? LIMIT 1");
        if ($stN) {
            $stN->bind_param('i', $mgrId);
            $stN->execute();
            $rn = $stN->get_result()->fetch_assoc();
            $stN->close();
            $mgrName = (string)($rn['full_name'] ?? '');
        }
    }

    $st = $conn->prepare("UPDATE employees SET manager_id = ?, manager_name = ? WHERE id = ? LIMIT 1");
    if ($st) {
        $mgrParam = ($mgrId > 0) ? $mgrId : null;
        $st->bind_param('isi', $mgrParam, $mgrName, $empId);
        $ok = $st->execute();
        $st->close();
        if ($ok) {
            hrisLog('org_chart', 'set_manager', "Employee #{$empId} manager set to #{$mgrId} ({$mgrName})");
            hrisFlash('success', $LANG['save_success']);
        } else {
            hrisFlash('error', $LANG['save_failed_generic']);
        }
    } else {
        hrisFlash('error', $LANG['save_failed_generic']);
    }
    redirect(payrollGateUrl('org_chart'));
}

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('org_chart'); }

/* ── Data ────────────────────────────────────────────────────────────── */
if (!hrisHasColumn('employees', 'manager_id')) {
    echo hrisMigrationNotice('employees.manager_id');
    return;
}

$__oc_rows = [];
$__oc_res = @$conn->query(
    "SELECT e.id, e.employee_code, e.full_name, e.employee_status,
            e.manager_id, e.manager_name,
            d.name AS dept_name, p.name AS pos_name
       FROM employees e
       LEFT JOIN departments d ON d.id = e.department_id
       LEFT JOIN positions   p ON p.id = e.position_id
      ORDER BY e.full_name ASC"
);
if ($__oc_res) { $__oc_rows = $__oc_res->fetch_all(MYSQLI_ASSOC); $__oc_res->free(); }

$__oc_byId = [];
foreach ($__oc_rows as $r) $__oc_byId[(int)$r['id']] = $r;

$__oc_children = [];
foreach ($__oc_rows as $r) {
    $__oc_children[(int)($r['manager_id'] ?? 0)][] = $r;
}

// Hanya karyawan AKTIF yang boleh dipilih sebagai atasan.
$__oc_managerOptions = [];
foreach ($__oc_rows as $r) {
    if ($r['employee_status'] === 'active') {
        $__oc_managerOptions[(int)$r['id']] = $r['full_name'] . ' (' . $r['employee_code'] . ')';
    }
}

$__oc_csrf = hrisCsrfToken();

/** Cetak satu cabang pohon secara berulang, dengan pembatas kedalaman. */
function ocRenderBranch(int $parentId, array $children, int $depth = 0): void {
    global $LANG;
    if ($depth > 12 || empty($children[$parentId])) return;
    echo '<ul class="oc-branch">';
    foreach ($children[$parentId] as $c) {
        $isActive = ($c['employee_status'] === 'active');
        echo '<li>';
        echo '<div class="oc-node' . ($isActive ? '' : ' oc-inactive') . '">';
        echo '<span class="oc-name">' . hrisOut($c['full_name']) . '</span> ';
        echo '<span class="oc-code text-muted">' . hrisOut($c['employee_code']) . '</span>';
        if (!empty($c['pos_name'])) {
            echo '<div class="oc-pos text-muted">' . hrisOut($c['pos_name']);
            if (!empty($c['dept_name'])) echo ' &middot; ' . hrisOut($c['dept_name']);
            echo '</div>';
        }
        if (!$isActive) {
            echo '<span class="badge bg-secondary-subtle text-secondary ms-1">'
               . hrisOut($LANG['inactive']) . '</span>';
        }
        echo '</div>';
        ocRenderBranch((int)$c['id'], $children, $depth + 1);
        echo '</li>';
    }
    echo '</ul>';
}
?>

<style>
.oc-branch { list-style:none; margin:0; padding-left:1.25rem; }
.oc-branch li { position:relative; padding:.2rem 0 .2rem .9rem; }
.oc-branch li::before { content:''; position:absolute; left:0; top:1.1rem; width:.7rem; height:1px; background:#dfe5ee; }
.oc-branch li::after  { content:''; position:absolute; left:0; top:0; bottom:0; width:1px; background:#dfe5ee; }
.oc-branch li:last-child::after { height:1.1rem; }
.oc-node { display:inline-block; border:1px solid #e9edf3; border-radius:.5rem; background:#fff; padding:.35rem .6rem; }
.oc-node.oc-inactive { opacity:.65; }
.oc-name { font-weight:600; font-size:.875rem; }
.oc-code { font-size:.75rem; }
.oc-pos  { font-size:.75rem; }
[data-bs-theme="dark"] .oc-node { background:#0f172a; border-color:#2b3a4a; }
[data-bs-theme="dark"] .oc-branch li::before,
[data-bs-theme="dark"] .oc-branch li::after { background:#2b3a4a; }
</style>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-diagram-3-fill me-2"></i><?= hrisOut($LANG['org_chart']) ?></div>
            </div>
            <div class="card-body">
                <p class="text-muted small"><?= hrisOut($LANG['intro_org_chart']) ?></p>
                <?php if (empty($__oc_children[0])): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i><?= hrisOut($LANG['org_chart_no_root']) ?>
                    </div>
                <?php else: ocRenderBranch(0, $__oc_children); endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-person-up me-2"></i><?= hrisOut($LANG['set_direct_manager']) ?></div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="oc_table">
                        <thead>
                            <tr>
                                <th><?= hrisOut($LANG['employee']) ?></th>
                                <th><?= hrisOut($LANG['direct_manager']) ?></th>
                                <?php if ($__oc_can_update): ?>
                                <th style="width:70px"><?= hrisOut($LANG['actions']) ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($__oc_rows as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= hrisOut($r['full_name']) ?></div>
                                    <div class="text-muted small"><?= hrisOut($r['employee_code']) ?></div>
                                </td>
                                <td class="small">
                                    <?php if (!empty($r['manager_name'])): ?>
                                        <?= hrisOut($r['manager_name']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($__oc_can_update): ?>
                                <td>
                                    <button type="button" class="btn btn-warning btn-sm"
                                            title="<?= hrisOut($LANG['edit']) ?>"
                                            data-bs-toggle="modal" data-bs-target="#oc_modal"
                                            data-emp-id="<?= (int)$r['id'] ?>"
                                            data-emp-name="<?= hrisOut($r['full_name']) ?>"
                                            data-mgr-id="<?= (int)($r['manager_id'] ?? 0) ?>">
                                        <i class="bi bi-person-up"></i>
                                    </button>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($__oc_can_update): ?>
<div class="modal fade" id="oc_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= hrisOut(payrollGateUrl('org_chart')) ?>" class="modal-content payroll-modal">
            <input type="hidden" name="action" value="oc_set_manager">
            <input type="hidden" name="csrf_token" value="<?= hrisOut($__oc_csrf) ?>">
            <input type="hidden" name="employee_id" id="oc_emp_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-up me-2"></i><?= hrisOut($LANG['set_direct_manager']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="<?= hrisOut($LANG['cancel']) ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3"><span class="text-muted"><?= hrisOut($LANG['employee']) ?>:</span>
                   <strong id="oc_emp_name"></strong></p>
                <label class="form-label" for="oc_mgr_id"><?= hrisOut($LANG['direct_manager']) ?></label>
                <select class="form-select" name="manager_id" id="oc_mgr_id">
                    <option value="0"><?= hrisOut($LANG['no_manager']) ?></option>
                    <?php foreach ($__oc_managerOptions as $mid => $mlabel): ?>
                        <option value="<?= (int)$mid ?>"><?= hrisOut($mlabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text small"><?= hrisOut($LANG['hint_manager_active_only']) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> <?= hrisOut($LANG['cancel']) ?>
                </button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-lg"></i> <?= hrisOut($LANG['save']) ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    function ready(fn){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);}else{fn();} }
    var tries = 0;
    function boot() {
        if (typeof jQuery === 'undefined') { if (++tries > 100) return; setTimeout(boot, 50); return; }
        var $ = jQuery;
        $('#oc_modal').on('show.bs.modal', function (ev) {
            var b = ev.relatedTarget; if (!b) return;
            $('#oc_emp_id').val(b.dataset.empId || '');
            $('#oc_emp_name').text(b.dataset.empName || '');
            $('#oc_mgr_id').val(b.dataset.mgrId && b.dataset.mgrId !== '0' ? b.dataset.mgrId : '0');
        });
        if ($.fn.DataTable) {
            $('#oc_table').DataTable({
                pageLength: 15, order: [],
                columnDefs: [{ orderable: false, targets: -1 }],
                language: { search: '', searchPlaceholder: <?= json_encode((string)$LANG['search'], JSON_UNESCAPED_UNICODE) ?> }
            });
        }
    }
    ready(boot);
})();
</script>
