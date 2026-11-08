<?php
// ═══════════════════════════════════════════════════════════════════════════
//  PURCHASE RETURN — VIEW (FLOW BARU)
//
//  Workflow : pending -> approved -> processed
//             pending -> rejected
//             pending / approved -> cancelled
//
//  Semua transisi memakai POST + CSRF + permission + whitelist di server.
//  Dokumen yang sudah approved/processed TIDAK BISA dihapus (histori utuh).
//  Tidak ada update inventory pada transisi mana pun.
// ═══════════════════════════════════════════════════════════════════════════
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
require_once __DIR__ . '/../includes/purchase_returns_function.php';

$page_title = $LANG['pr_new_flow_list'];
include '../includes/header.php';

requirePurchaseReturnAccess();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('returns');
if (!pret_tablesExist($conn)) {
    bltx_flash_error($LANG['pr_tables_missing']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));

$csrf_token = pret_csrfToken();

// ── POST: workflow / delete ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $csrf   = (string)($_POST['csrf_token'] ?? '');

    if ($action === 'pr_delete') {
        $r = pret_delete($conn, $id, $csrf);
        if ($r['success']) {
            bltx_flash_success($LANG['delete_success']);
            redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
        }
    } else {
        $r = pret_setStatus($conn, $id, $action, $csrf);
    }

    $key = (string)$r['message_key'];
    if ($r['success']) {
        bltx_flash_success(isset($LANG[$key]) ? $LANG[$key] : $LANG['save_success']);
    } else {
        bltx_flash_error(isset($LANG[$key]) ? $LANG[$key] : $LANG['error']);
    }
    redirect(url('purchases/purchase_return_view.php?id=' . $id));
}

$pr = pret_getOne($conn, $id);
if (!$pr) {
    bltx_flash_error($LANG['pr_not_found']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
}
// REPORT DATA SCOPE: cegah pembukaan dokumen milik user lain lewat ?id=
if (!pret_scopeAllows($pr)) {
    bltx_flash_error($LANG['perm_scope_denied']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
}

$status  = (string)$pr['status'];
$sColor  = pret_statusColor($status);
$sLabel  = pret_statusLabel($status);

$can_edit    = ($status === 'pending') && canUpdatePurchaseReturn();
$can_delete  = ($status === 'pending') && canDeletePurchaseReturn();
$can_approve = ($status === 'pending') && canApprovePurchaseReturn();
$can_process = ($status === 'approved') && canApprovePurchaseReturn();
$can_cancel  = in_array($status, ['pending', 'approved'], true) && canCancelPurchaseReturn();
?>

<div class="page-title-bar">
    <div>
        <h2 class="page-title-text">
            <i class="bi bi-arrow-return-left"></i>
            <?= htmlspecialchars($pr['return_number']) ?>
            <span class="badge bg-<?= $sColor ?> ms-2 align-middle" style="font-size:.65em;"><?= htmlspecialchars($sLabel) ?></span>
        </h2>
        <div class="page-subtitle"><?= htmlspecialchars($page_title) ?></div>
    </div>
    <div class="page-actions">
        <a href="<?= url('purchases/pr_dahsboard_gate.php?tab=returns') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?>
        </a>
        <?php if ($can_edit): ?>
        <a href="<?= url('purchases/purchase_return_edit.php?id=' . $id) ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil-square"></i> <?= htmlspecialchars($LANG['edit']) ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
   blok HTML+JS yang isinya identik dengan berkas lain. Kunci sesinya
   TIDAK diubah, hanya cara merendernya. */
bltx_render_flash('pr');
?>

<div class="row g-3">

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-box-seam"></i> <?= htmlspecialchars($LANG['items']) ?></div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars($LANG['item_name']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['quantity']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['unit_price']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['subtotal']) ?></th>
                                <th><?= htmlspecialchars($LANG['reason']) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pr['items'] as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['item_name']) ?></td>
                                <td class="text-end">
                                    <?= htmlspecialchars(gr_formatQty($it['quantity']) . ' ' . ($it['unit_symbol'] ?: ($it['unit_name'] ?: ''))) ?>
                                </td>
                                <td class="text-end"><?= htmlspecialchars(formatCurrency((float)$it['unit_price'])) ?></td>
                                <td class="text-end"><?= htmlspecialchars(formatCurrency((float)$it['total_price'])) ?></td>
                                <td><?= nl2br(htmlspecialchars((string)$it['reason'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <th colspan="3" class="text-end"><?= htmlspecialchars($LANG['total']) ?></th>
                                <th class="text-end"><?= htmlspecialchars(formatCurrency((float)$pr['total_amount'])) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="alert alert-light border mt-3 mb-0">
                    <strong><?= htmlspecialchars($LANG['reason']) ?>:</strong><br>
                    <?= nl2br(htmlspecialchars((string)$pr['reason'])) ?>
                </div>
                <?php if (!empty($pr['notes'])): ?>
                <div class="alert alert-light border mt-2 mb-0">
                    <strong><?= htmlspecialchars($LANG['notes']) ?>:</strong><br>
                    <?= nl2br(htmlspecialchars((string)$pr['notes'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">

        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-diagram-3"></i> <?= htmlspecialchars($LANG['gr_traceability']) ?></div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['po_number']) ?></div>
                        <div>
                            <?php if (!empty($pr['po_id'])): ?>
                                <a href="<?= url('purchases/po_view.php?id=' . (int)$pr['po_id']) ?>"><?= htmlspecialchars($pr['po_number']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['gr_number']) ?></div>
                        <div>
                            <?php if (!empty($pr['gr_id'])): ?>
                                <a href="<?= url('purchases/goods_receipt_view.php?id=' . (int)$pr['gr_id']) ?>"><?= htmlspecialchars($pr['gr_number']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['invoice']) ?></div>
                        <div>
                            <?php if (!empty($pr['purchase_id'])): ?>
                                <a href="<?= url('purchases/purchase_view.php?id=' . (int)$pr['purchase_id']) ?>">#<?= (int)$pr['purchase_id'] ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['supplier']) ?></div>
                        <div><?= htmlspecialchars($pr['supplier_name'] ?: '—') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['return_date']) ?></div>
                        <div><?= htmlspecialchars(date('d M Y', strtotime($pr['return_date']))) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['created_by']) ?></div>
                        <div><?= htmlspecialchars($pr['created_by_name']) ?></div>
                    </div>
                    <?php if (!empty($pr['approved_by_name'])): ?>
                    <div class="col-12">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['approved_by']) ?></div>
                        <div><?= htmlspecialchars($pr['approved_by_name']) ?> &middot; <?= htmlspecialchars(date('d M Y H:i', strtotime((string)$pr['approved_at']))) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($pr['cancelled_by_name'])): ?>
                    <div class="col-12">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['cancelled']) ?></div>
                        <div class="text-danger"><?= htmlspecialchars($pr['cancelled_by_name']) ?> &middot; <?= htmlspecialchars(date('d M Y H:i', strtotime((string)$pr['cancelled_at']))) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-stoplights"></i> <?= htmlspecialchars($LANG['status_workflow']) ?></div>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2"><?= htmlspecialchars($LANG['current_status']) ?>:</p>
                <p><span class="badge bg-<?= $sColor ?> p-2 fs-6"><?= htmlspecialchars($sLabel) ?></span></p>

                <div class="alert alert-secondary small py-2">
                    <i class="bi bi-shield-check"></i> <?= htmlspecialchars($LANG['gr_no_inventory_notice']) ?>
                </div>

                <div class="d-grid gap-2">
                    <?php if ($can_approve): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="pr_approve">
                        <button class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($LANG['approve']) ?>
                        </button>
                    </form>
                    <form method="POST" action="" class="pr-confirm-form" data-confirm-msg="<?= htmlspecialchars($LANG['confirm']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="pr_reject">
                        <button class="btn btn-sm btn-danger w-100">
                            <i class="bi bi-x-octagon"></i> <?= htmlspecialchars($LANG['reject']) ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($can_process): ?>
                    <form method="POST" action="" class="pr-confirm-form" data-confirm-msg="<?= htmlspecialchars($LANG['confirm']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="pr_process">
                        <button class="btn btn-sm btn-success w-100">
                            <i class="bi bi-box-arrow-up"></i> <?= htmlspecialchars($LANG['pr_process']) ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($can_cancel): ?>
                    <form method="POST" action="" class="pr-confirm-form" data-confirm-msg="<?= htmlspecialchars($LANG['confirm_cancel']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="pr_cancel">
                        <button class="btn btn-sm btn-secondary w-100">
                            <i class="bi bi-slash-circle"></i> <?= htmlspecialchars($LANG['cancel']) ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($can_delete): ?>
                    <form method="POST" action="" class="pr-confirm-form" data-confirm-msg="<?= htmlspecialchars($LANG['confirm_delete']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="pr_delete">
                        <button class="btn btn-sm btn-danger w-100">
                            <i class="bi bi-trash"></i> <?= htmlspecialchars($LANG['delete']) ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="form-text small"><i class="bi bi-lock"></i> <?= htmlspecialchars($LANG['pr_delete_forbidden']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.querySelectorAll('.pr-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        Swal.fire({
            text: form.dataset.confirmMsg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<?= htmlspecialchars($LANG['yes']) ?>',
            cancelButtonText: '<?= htmlspecialchars($LANG['cancel']) ?>'
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>