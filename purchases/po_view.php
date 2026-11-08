<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/purchase_orders_function.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
require_once __DIR__ . '/../includes/purchase_returns_function.php';

$page_title = $LANG['view_purchase_order'];
include '../includes/header.php';

// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('purchase_orders');

if (!canAccessPurchaseOrder()) {
    redirect(url('purchases/pr_dahsboard_gate.php?tab=order'));
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect(url('purchases/pr_dahsboard_gate.php?tab=order'));

$po = po_getOne($conn, $id);
if (!$po) {
    bltx_flash_error($LANG['po_not_found']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=order'));
}
// REPORT DATA SCOPE: user ber-scope "Own Data Only" tidak boleh membuka PO
// milik user lain lewat manipulasi ?id=
if (!po_scopeAllows($po)) {
    bltx_flash_error($LANG['perm_scope_denied']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=order'));
}

$csrf_token = po_csrfToken();

// ── POST: status transitions (unchanged) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!po_csrfVerify((string)($_POST['csrf_token'] ?? ''))) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(url('purchases/po_view.php?id=' . $id));
    }
    $action = $_POST['action'];

    // CATATAN PERUBAHAN:
    // Aksi 'po_mark_received' SENGAJA DIHAPUS dari daftar ini. Status
    // 'received' / 'partial' tidak boleh lagi ditulis langsung dari halaman
    // PO, karena penerimaan barang sekarang harus melewati dokumen Goods
    // Receipt. Status PO dihitung ulang oleh gr_recalcPoStatus() setelah
    // Goods Receipt tersimpan dan business rule terpenuhi.
    // Tombol "Mark as Received" di bawah berubah menjadi link ke
    // purchases/goods_receipt_add.php?po_id=...
    $newStatus = null;
    if ($action === 'po_mark_sent'      && canUpdatePurchaseOrder()) $newStatus = 'sent';
    if ($action === 'po_approve'        && canUpdatePurchaseOrder()) $newStatus = 'approved';
    if ($action === 'po_cancel'         && canUpdatePurchaseOrder()) $newStatus = 'cancelled';

    if ($newStatus) {
        if ($newStatus === 'approved') {
            $uid = (int)$_SESSION['user_id'];
            /* approved_by_name = SALINAN PERMANEN users.full_name saat PO
               disetujui, sehingga nama penyetuju tidak hilang bila akunnya
               dihapus di kemudian hari. */
            $stmt = $conn->prepare(
                "UPDATE purchase_orders
                    SET status = ?,
                        approved_by = ?,
                        approved_by_name = (SELECT full_name FROM users WHERE id = ?),
                        approved_at = NOW()
                  WHERE id = ?"
            );
            $stmt->bind_param('siii', $newStatus, $uid, $uid, $id);
        } else {
            $stmt = $conn->prepare("UPDATE purchase_orders SET status=? WHERE id=?");
            $stmt->bind_param('si', $newStatus, $id);
        }
        if ($stmt && $stmt->execute()) {
            if (function_exists('logActivity')) logActivity('po_status', "PO {$po['po_number']} → {$newStatus}");
            bltx_flash_success(($LANG['po_status_changed']) . ': ' . ucfirst($newStatus));
        } else {
            bltx_flash_error($LANG['error']);
        }
        if ($stmt) $stmt->close();
        redirect(url('purchases/po_view.php?id=' . $id));
    }
}

// Status badge map (same as in listing)
$status_color = [
    'draft' => 'secondary', 'sent' => 'info', 'approved' => 'primary',
    'partial' => 'warning', 'received' => 'success', 'cancelled' => 'danger',
];
$status_label = [
    'draft'     => $LANG['po_status_draft'],
    'sent'      => $LANG['po_status_sent'],
    'approved'  => $LANG['po_status_approved'],
    'partial'   => $LANG['po_status_partial'],
    'received'  => $LANG['po_status_received'],
    'cancelled' => $LANG['po_status_cancelled'],
];
$sKey  = $po['status'] ?? 'draft';
$sColor = $status_color[$sKey] ?? 'secondary';
$sLabel = $status_label[$sKey] ?? ucfirst($sKey);

$can_update = canUpdatePurchaseOrder();
$can_export = canExportPurchaseOrder();

// Bundle + shared document HTML (same renderer used by the PDF/JPEG export files)
$docBundle = po_getDocumentBundle($conn, $id);
$docHtml   = $docBundle ? po_renderDocumentBody($docBundle, 'web') : '';

// ── Riwayat & ringkasan (BARU) ────────────────────────────────────────────
// Semua data di bawah ini hanya DIBACA. Isi Purchase Order tidak pernah
// diubah oleh Goods Receipt / Invoice / Return.
$gr_history      = gr_getByPo($conn, $id);
// Badge Payment Status pada riwayat Goods Receipt hanya ditampilkan bila
// migrasi payment_status sudah dijalankan.
$gr_paystat_ready = function_exists('gr_hasPaymentStatusColumn') && gr_hasPaymentStatusColumn($conn);
$invoice_history = gr_getInvoicesByPo($conn, $id);
$return_history  = pret_getByPo($conn, $id);
$po_comparison   = gr_poComparison($conn, $id);

$can_gr_read   = canReadGoodsReceipt();
$can_gr_create = canCreateGoodsReceipt();
$can_inv_read  = canReadPurchase();
$can_ret_read  = canReadPurchaseReturn();

// PO masih boleh menerima Goods Receipt baru?
$po_receivable = !in_array($sKey, ['cancelled', 'received'], true);
?>
<style><?= po_documentCss() ?></style>

<div class="page-title-bar">
    <div>
        <h2 class="page-title-text">
            <i class="bi bi-file-earmark-text"></i>
            <?= htmlspecialchars($po['po_number']) ?>
            <span class="badge bg-<?= $sColor ?> ms-2 align-middle" style="font-size:.65em;"><?= htmlspecialchars($sLabel) ?></span>
        </h2>
        <div class="page-subtitle"><?= htmlspecialchars($page_title) ?></div>
    </div>
    <div class="page-actions">
        <a href="<?= url('purchases/pr_dahsboard_gate.php?tab=order') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?>
        </a>
        <?php if ($can_update && (isSuperAdmin() || in_array($sKey, ['draft','sent'], true))): ?>
        <a href="<?= url('purchases/po_edit.php?id=' . $id) ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil-square"></i> <?= htmlspecialchars($LANG['edit']) ?>
        </a>
        <?php endif; ?>
        <?php if ($can_export): ?>
        <a href="<?= url('includes/export/po_export_pdf.php?id=' . $id) ?>" class="btn btn-sm btn-danger" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-pdf"></i> <?= htmlspecialchars($LANG['export_pdf']) ?>
        </a>
        <a href="<?= url('includes/export/po_export_image.php?id=' . $id) ?>" class="btn btn-sm btn-success" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-image"></i> <?= htmlspecialchars($LANG['export_image']) ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
   blok HTML+JS yang isinya identik dengan berkas lain. Kunci sesinya
   TIDAK diubah, hanya cara merendernya. */
bltx_render_flash('po');
?>

<div class="row g-3">

    <!-- PO Document (matches the company's printed Purchase Order format) -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-file-earmark-richtext"></i> <?= htmlspecialchars($LANG['po_header']) ?></div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <?= $docHtml ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Status workflow -->
        <div class="card mb-3">
             <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-stoplights"></i> <?= htmlspecialchars($LANG['status_workflow']) ?></div>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3"><?= htmlspecialchars($LANG['current_status']) ?>:</p>
                <p><span class="badge bg-<?= $sColor ?> p-2 fs-6"><?= htmlspecialchars($sLabel) ?></span></p>

                <?php if ($can_update): ?>
                <hr>
                <p class="small fw-semibold"><?= htmlspecialchars($LANG['change_status']) ?>:</p>
                <div class="d-grid gap-2">
                    <?php if ($sKey === 'draft'): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="po_mark_sent">
                            <button class="btn btn-sm btn-info w-100"><i class="bi bi-send"></i> <?= htmlspecialchars($LANG['mark_as_sent']) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($sKey, ['draft','sent'], true)): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="po_approve">
                            <button class="btn btn-sm btn-primary w-100"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($LANG['approve']) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($sKey, ['approved','partial','sent'], true) && $can_gr_create): ?>
                        <!-- "Mark as Received" TIDAK LAGI mengubah status PO secara langsung.
                             Tombol ini membuka dokumen Goods Receipt dengan PO terpilih. -->
                        <a href="<?= url('purchases/goods_receipt_add.php?po_id=' . $id) ?>"
                           class="btn btn-sm btn-success w-100">
                            <i class="bi bi-box-seam"></i> <?= htmlspecialchars($LANG['mark_as_received']) ?>
                        </a>
                        <div class="form-text small"><?= htmlspecialchars($LANG['gr_mark_received_hint']) ?></div>
                    <?php endif; ?>
                    <?php if (!in_array($sKey, ['cancelled','received'], true)): ?>
                        <form method="POST" action="" class="po-confirm-form" data-confirm-msg="<?= htmlspecialchars($LANG['confirm_cancel']) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="po_cancel">
                            <button class="btn btn-sm btn-danger w-100"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($LANG['cancel']) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Order info (fields not part of the printed document itself) -->
        <div class="card">
             <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['po_header']) ?></div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['payment_method']) ?></div>
                        <div class="fw-semibold"><?= htmlspecialchars($po['payment_method'] ?: '—') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['payment_terms']) ?></div>
                        <div><?= htmlspecialchars($po['payment_terms'] ?: '—') ?></div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['created_at']) ?></div>
                        <div><?= htmlspecialchars(date('d M Y H:i', strtotime($po['created_at']))) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════
     PO SUMMARY  —  Ordered / Received / Remaining / Actual Price / Variance
     Semua angka dihitung ulang dari tabel goods_receipt_items.
     Data asli Purchase Order (qty, harga, tanggal) TIDAK PERNAH berubah.
     ══════════════════════════════════════════════════════════════════════ -->
<?php if ($can_gr_read && $po_comparison): ?>
<div class="card mt-3">
     <div class="card-header">
        <div class="card-title">
            <i class="bi bi-clipboard-data"></i> <?= htmlspecialchars($LANG['gr_comparison']) ?></div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($LANG['item_name']) ?></th>
                        <th class="text-end"><?= htmlspecialchars($LANG['gr_ordered_qty']) ?></th>
                        <th class="text-end"><?= htmlspecialchars($LANG['gr_received_qty']) ?></th>
                        <th class="text-end"><?= htmlspecialchars($LANG['gr_remaining']) ?></th>
                        <th class="text-end"><?= htmlspecialchars($LANG['gr_variance_qty']) ?></th>
                        <th class="text-end"><?= htmlspecialchars($LANG['gr_po_price']) ?></th>
                        <th class="text-end"><?= htmlspecialchars($LANG['gr_actual_price']) ?></th>
                        <th class="text-end"><?= htmlspecialchars($LANG['gr_price_variance']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($po_comparison as $cmp): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($cmp['item_name']) ?>
                            <?php if (!empty($cmp['line_closed'])): ?>
                            <span class="badge text-bg-secondary ms-1" title="<?= htmlspecialchars($LANG['gr_close_line_hint']) ?>">
                                <i class="bi bi-slash-circle"></i> <?= htmlspecialchars($LANG['gr_close_line_badge']) ?>
                                <?php if ((float)$cmp['closed_short'] > 0): ?>
                                &minus;<?= htmlspecialchars(gr_formatQty($cmp['closed_short'])) ?>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= htmlspecialchars(gr_formatQty($cmp['ordered']) . ' ' . $cmp['unit']) ?></td>
                        <td class="text-end"><?= htmlspecialchars(gr_formatQty($cmp['received'])) ?></td>
                        <td class="text-end"><?= htmlspecialchars(gr_formatQty($cmp['remaining'])) ?></td>
                        <td class="text-end <?= $cmp['variance_qty'] < 0 ? 'text-danger' : ($cmp['variance_qty'] > 0 ? 'text-success' : '') ?>">
                            <?= $cmp['variance_qty'] > 0 ? '+' : '' ?><?= htmlspecialchars(gr_formatQty($cmp['variance_qty'])) ?>
                        </td>
                        <td class="text-end"><?= htmlspecialchars(formatCurrency($cmp['po_price'])) ?></td>
                        <td class="text-end">
                            <?= $cmp['has_receipt'] ? htmlspecialchars(formatCurrency($cmp['actual_price'])) : '&mdash;' ?>
                        </td>
                        <td class="text-end <?= $cmp['price_variance'] > 0 ? 'text-danger' : ($cmp['price_variance'] < 0 ? 'text-success' : '') ?>">
                            <?php if ($cmp['has_receipt']): ?>
                                <?= $cmp['price_variance'] > 0 ? '+' : '' ?><?= htmlspecialchars(formatCurrency($cmp['price_variance'])) ?>
                            <?php else: ?>&mdash;<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════
     TRACEABILITY :  Goods Receipt / Purchase Invoice / Purchase Return
     ══════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mt-0">

    <?php if ($can_gr_read): ?>
    <div class="col-lg-4">
        <div class="card">
             <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-box-seam"></i> <?= htmlspecialchars($LANG['gr_history']) ?></div>
                <?php if ($po_receivable && $can_gr_create): ?>
                <div class="card-tools">
                    <a href="<?= url('purchases/goods_receipt_add.php?po_id=' . $id) ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['add']) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$gr_history): ?>
                    <p class="text-muted small mb-0"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['no_data']) ?></p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($gr_history as $g): ?>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <span>
                            <a href="<?= url('purchases/goods_receipt_view.php?id=' . (int)$g['id']) ?>">
                                <?= htmlspecialchars($g['gr_number']) ?>
                            </a>
                            <br><small class="text-muted"><?= htmlspecialchars(date('d/m/Y', strtotime($g['receipt_date']))) ?> &middot; <?= htmlspecialchars($g['created_by_name']) ?></small>
                        </span>
                        <span class="text-nowrap">
                            <span class="badge bg-<?= gr_statusColor((string)$g['status']) ?>"><?= htmlspecialchars(gr_statusLabel((string)$g['status'])) ?></span>
                            <?php if ($gr_paystat_ready && $g['status'] !== 'cancelled'): $__ps = gr_paymentStatus($g); ?>
                            <span class="badge bg-<?= gr_paymentStatusColor($__ps) ?>"><?= htmlspecialchars(gr_paymentStatusLabel($__ps)) ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can_inv_read): ?>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-receipt"></i> <?= htmlspecialchars($LANG['gr_invoice_history']) ?></div>
            </div>
            <div class="card-body">
                <?php if (!$invoice_history): ?>
                    <p class="text-muted small mb-0"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['no_data']) ?></p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($invoice_history as $inv): ?>
                    <li class="list-group-item px-0">
                        <a href="<?= url('purchases/purchase_view.php?id=' . (int)$inv['id']) ?>">
                            <?= htmlspecialchars($LANG['invoice']) ?> #<?= (int)$inv['id'] ?>
                        </a>
                        <br><small class="text-muted">
                            <?= htmlspecialchars(date('d/m/Y', strtotime($inv['invoice_date']))) ?>
                            <?php if (!empty($inv['gr_number'])): ?>
                                &middot; <?= htmlspecialchars($inv['gr_number']) ?>
                            <?php endif; ?>
                        </small>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can_ret_read): ?>
    <div class="col-lg-4">
        <div class="card">
             <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-arrow-return-left"></i> <?= htmlspecialchars($LANG['gr_return_history']) ?></div>
            </div>
            <div class="card-body">
                <?php if (!$return_history): ?>
                    <p class="text-muted small mb-0"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['no_data']) ?></p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($return_history as $rt): ?>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <span>
                            <a href="<?= url('purchases/purchase_return_view.php?id=' . (int)$rt['id']) ?>">
                                <?= htmlspecialchars($rt['return_number']) ?>
                            </a>
                            <br><small class="text-muted"><?= htmlspecialchars(date('d/m/Y', strtotime($rt['return_date']))) ?> &middot; <?= htmlspecialchars($rt['created_by_name']) ?></small>
                        </span>
                        <span class="badge bg-<?= pret_statusColor((string)$rt['status']) ?>"><?= htmlspecialchars(pret_statusLabel((string)$rt['status'])) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
// SweetAlert2 confirmation for the two "destructive-ish" workflow actions
// (mark received / cancel) instead of the plain confirm() used before.
document.querySelectorAll('.po-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        Swal.fire({
            text: form.dataset.confirmMsg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<?= htmlspecialchars($LANG['yes'] ?? 'OK') ?>',
            cancelButtonText: '<?= htmlspecialchars($LANG['cancel'] ?? 'Cancel') ?>'
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>