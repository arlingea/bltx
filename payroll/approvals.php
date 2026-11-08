<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "approvals" — MODUL 3: APPROVAL BERTINGKAT

   Dua bagian:
     1. KOTAK MASUK  — dokumen yang menunggu persetujuan user ini
     2. PENGATURAN   — alur dan langkah penyetuju

   Dokumen LAMA tidak pernah macet: bila alurnya belum diatur,
   leave_requests dan cash_advances tetap memakai persetujuan satu
   langkah yang sudah berjalan selama ini. Kolom approval_request_id
   yang NULL berarti dokumen itu di jalur lama.

   Seluruh keputusan tercatat permanen di approval_logs beserta NAMA
   pelakunya, sehingga jejaknya tidak hilang walau akunnya dihapus.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('approvals');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('approvals');

$__ap_can_update = checkPermission('approvals', 'can_update');
$__ap_can_create = checkPermission('approvals', 'can_create');
$__ap_can_delete = checkPermission('approvals', 'can_delete');

/* ── Aksi setujui / tolak ────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && in_array(($_POST['action'] ?? ''), ['ap_approve', 'ap_reject'], true)) {

    if (!hrisCsrfValid()) {
        hrisFlash('error', $LANG['invalid_csrf']);
        redirect(payrollGateUrl('approvals'));
    }
    if (!$__ap_can_update) {
        hrisFlash('error', $LANG['perm_denied_target']);
        redirect(payrollGateUrl('approvals'));
    }

    $reqId  = (int)($_POST['request_id'] ?? 0);
    $notes  = hrisIn('notes');
    $verdict = ($_POST['action'] === 'ap_approve') ? 'approved' : 'rejected';

    if ($reqId > 0 && hrisApprovalAct($reqId, $verdict, $notes)) {
        hrisFlash('success', $verdict === 'approved' ? $LANG['approved'] : $LANG['rejected']);
    } else {
        hrisFlash('error', $LANG['approval_action_failed']);
    }
    redirect(payrollGateUrl('approvals'));
}

/* ── Konfigurasi alur & langkah memakai mesin CRUD generik ───────────── */
$__ap_flowOptions = [];
foreach (hrisApprovalFlows() as $f) $__ap_flowOptions[$f['id']] = $f['name'];
$__ap_posOptions  = hrisRefOptions('positions');

$__ap_userOptions = [];
$__ap_ures = @$conn->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");
if ($__ap_ures) {
    while ($u = $__ap_ures->fetch_assoc()) $__ap_userOptions[$u['id']] = $u['full_name'];
    $__ap_ures->free();
}

$cfgSteps = [
    'module'   => 'approvals',
    'table'    => 'approval_steps',
    'tab'      => 'approvals',
    'title'    => $LANG['approval_steps'],
    'icon'     => 'bi-diagram-2',
    'order'    => 'flow_id ASC, step_order ASC',
    'intro'    => $LANG['intro_approval_steps'],
    'snapshot' => [],
    'fields'   => [
        'flow_id'       => ['label' => $LANG['approval_flow'], 'type' => 'select', 'required' => true,
                            'list' => true, 'col' => 6, 'options' => $__ap_flowOptions],
        'step_order'    => ['label' => $LANG['step_order'], 'type' => 'number', 'required' => true,
                            'list' => true, 'col' => 3],
        'step_name'     => ['label' => $LANG['name'], 'type' => 'text', 'list' => true, 'col' => 3],
        'approver_type' => ['label' => $LANG['approver_type'], 'type' => 'select', 'required' => true,
                            'list' => true, 'col' => 4,
                            'options' => ['manager'  => $LANG['approver_manager'],
                                          'position' => $LANG['approver_position'],
                                          'user'     => $LANG['approver_user']]],
        'position_id'   => ['label' => $LANG['position'], 'type' => 'select', 'col' => 4,
                            'options' => $__ap_posOptions, 'hint' => $LANG['hint_only_for_position']],
        'user_id'       => ['label' => $LANG['user'], 'type' => 'select', 'col' => 4,
                            'options' => $__ap_userOptions, 'hint' => $LANG['hint_only_for_user']],
        'min_amount'    => ['label' => $LANG['min_amount'], 'type' => 'money', 'list' => true, 'col' => 6,
                            'hint' => $LANG['hint_min_amount']],
        'is_active'     => ['label' => $LANG['status'], 'type' => 'checkbox', 'list' => true, 'col' => 6],
    ],
];

hrisCrudHandle($cfgSteps);

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('approvals'); }

if (!hrisHasTable('approval_requests')) {
    echo hrisMigrationNotice('approval_requests');
    return;
}

/* ── Kotak masuk ─────────────────────────────────────────────────────── */
$__ap_pending = hrisApprovalPending();
$__ap_csrf    = hrisCsrfToken();

$__ap_refLabel = [
    'leave_requests' => $LANG['leave_requests'],
    'cash_advances'  => $LANG['cash_advances'],
];
?>

<div class="card mb-3">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-inbox-fill me-2"></i><?= hrisOut($LANG['approval_inbox']) ?>
        <span class="badge bg-primary ms-2"><?= count($__ap_pending) ?></span></div>
    </div>
    <div class="card-body">
        <p class="text-muted small"><?= hrisOut($LANG['intro_approval_inbox']) ?></p>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="ap_table">
                <thead>
                    <tr>
                        <th><?= hrisOut($LANG['document']) ?></th>
                        <th><?= hrisOut($LANG['employee']) ?></th>
                        <th><?= hrisOut($LANG['amount']) ?></th>
                        <th><?= hrisOut($LANG['step']) ?></th>
                        <th><?= hrisOut($LANG['date']) ?></th>
                        <?php if ($__ap_can_update): ?>
                        <th style="width:120px"><?= hrisOut($LANG['actions']) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($__ap_pending)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?= hrisOut($LANG['no_data']) ?></td></tr>
                <?php else: foreach ($__ap_pending as $r): ?>
                    <?php $mine = hrisApprovalCanAct($r); ?>
                    <tr>
                        <td class="small">
                            <span class="fw-semibold"><?= hrisOut($__ap_refLabel[$r['ref_table']] ?? $r['ref_table']) ?></span>
                            <span class="text-muted">#<?= (int)$r['ref_id'] ?></span>
                        </td>
                        <td class="small"><?= hrisOut($r['employee_name']) ?></td>
                        <td class="small"><?= formatCurrency((float)$r['amount']) ?></td>
                        <td class="small"><?= (int)$r['current_step'] ?> / <?= (int)$r['total_steps'] ?></td>
                        <td class="small"><?= hrisOut(date('d-m-Y', strtotime((string)$r['created_at']))) ?></td>
                        <?php if ($__ap_can_update): ?>
                        <td>
                            <?php if ($mine): ?>
                            <button type="button" class="btn btn-success btn-sm"
                                    title="<?= hrisOut($LANG['approve']) ?>"
                                    onclick="apAct('ap_approve', <?= (int)$r['id'] ?>)">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <button type="button" class="btn btn-danger btn-sm"
                                    title="<?= hrisOut($LANG['reject']) ?>"
                                    onclick="apAct('ap_reject', <?= (int)$r['id'] ?>)">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary">
                                <?= hrisOut($LANG['not_your_turn']) ?>
                            </span>
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

<?php if ($__ap_can_update): ?>
<form method="POST" action="<?= hrisOut(payrollGateUrl('approvals')) ?>" id="ap_form" class="d-none">
    <input type="hidden" name="action" id="ap_action">
    <input type="hidden" name="csrf_token" value="<?= hrisOut($__ap_csrf) ?>">
    <input type="hidden" name="request_id" id="ap_request_id">
    <input type="hidden" name="notes" id="ap_notes">
</form>
<script>
function apAct(action, id) {
    var isReject = (action === 'ap_reject');
    Swal.fire({
        title: isReject ? <?= json_encode((string)$LANG['reject'], JSON_UNESCAPED_UNICODE) ?>
                        : <?= json_encode((string)$LANG['approve'], JSON_UNESCAPED_UNICODE) ?>,
        input: 'textarea',
        inputLabel: <?= json_encode((string)$LANG['notes'], JSON_UNESCAPED_UNICODE) ?>,
        inputAttributes: { 'aria-label': <?= json_encode((string)$LANG['notes'], JSON_UNESCAPED_UNICODE) ?> },
        icon: isReject ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: isReject ? '#dc3545' : '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: <?= json_encode((string)$LANG['save'], JSON_UNESCAPED_UNICODE) ?>,
        cancelButtonText:  <?= json_encode((string)$LANG['cancel'], JSON_UNESCAPED_UNICODE) ?>,
        reverseButtons: true
    }).then(function (r) {
        if (!r.isConfirmed) return;
        document.getElementById('ap_action').value = action;
        document.getElementById('ap_request_id').value = id;
        document.getElementById('ap_notes').value = r.value || '';
        document.getElementById('ap_form').submit();
    });
}
</script>
<?php endif; ?>

<?php
/* ── Pengaturan alur (CRUD generik) ──────────────────────────────────── */
if (checkPermission('approvals', 'can_read') || isSuperAdmin()) {
    $cfgFlows = [
        'module'   => 'approvals',
        'table'    => 'approval_flows',
        'tab'      => 'approvals',
        'title'    => $LANG['approval_flows'],
        'icon'     => 'bi-signpost-split',
        'order'    => 'name ASC',
        'intro'    => $LANG['intro_approval_flows'],
        'snapshot' => ['created_by' => 'created_by_name'],
        'fields'   => [
            'flow_key'    => ['label' => $LANG['code'], 'type' => 'text', 'required' => true,
                              'list' => true, 'col' => 4, 'hint' => $LANG['hint_flow_key']],
            'name'        => ['label' => $LANG['name'], 'type' => 'text', 'required' => true,
                              'list' => true, 'col' => 8],
            'description' => ['label' => $LANG['description'], 'type' => 'textarea', 'col' => 12],
            'is_active'   => ['label' => $LANG['status'], 'type' => 'checkbox', 'list' => true, 'col' => 6],
        ],
    ];
    echo '<div class="mb-3"></div>';
    hrisCrudRender($cfgFlows);
    echo '<div class="mb-3"></div>';
    hrisCrudRender($cfgSteps);
}
?>

<script>
(function () {
    function ready(fn){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);}else{fn();} }
    var tries = 0;
    function boot() {
        if (typeof jQuery === 'undefined') { if (++tries > 100) return; setTimeout(boot, 50); return; }
        if (jQuery.fn.DataTable) {
            jQuery('#ap_table').DataTable({
                pageLength: 15, order: [],
                columnDefs: [{ orderable: false, targets: -1 }],
                language: { search: '', searchPlaceholder: <?= json_encode((string)$LANG['search'], JSON_UNESCAPED_UNICODE) ?> }
            });
        }
    }
    ready(boot);
})();
</script>
