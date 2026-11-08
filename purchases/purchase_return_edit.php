<?php
// ═══════════════════════════════════════════════════════════════════════════
//  PURCHASE RETURN — EDIT (FLOW BARU)
//
//  Hanya dokumen berstatus 'pending' yang boleh disunting. Dokumen yang sudah
//  approved / processed / rejected / cancelled dikunci oleh pret_update()
//  di sisi server supaya histori tidak berubah.
// ═══════════════════════════════════════════════════════════════════════════
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
require_once __DIR__ . '/../includes/purchase_returns_function.php';

$page_title = $LANG['pr_edit'];
include '../includes/header.php';

requirePurchaseReturnAccess();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('returns');
if (!canUpdatePurchaseReturn()) {
    redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
}
if (!pret_tablesExist($conn)) {
    bltx_flash_error($LANG['pr_tables_missing']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));

$csrf_token = pret_csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = pret_update($conn, $id, $_POST);
    if ($res['success']) {
        bltx_flash_success($LANG['save_success']);
        redirect(url('purchases/purchase_return_view.php?id=' . $id));
    }
    $key = (string)$res['message_key'];
    bltx_flash_error(isset($LANG[$key]) ? $LANG[$key] : $LANG['error']);
    redirect(url('purchases/purchase_return_edit.php?id=' . $id));
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
if ((string)$pr['status'] !== 'pending') {
    bltx_flash_error($LANG['pr_locked']);
    redirect(url('purchases/purchase_return_view.php?id=' . $id));
}

// Sisa yang boleh dikembalikan — return ini DIKECUALIKAN agar qty miliknya
// sendiri tidak dihitung sebagai "sudah dikembalikan".
$gr_id = (int)($pr['gr_id'] ?? 0);
$avail = [];
if ($gr_id > 0) {
    foreach (pret_availableFromGr($conn, $gr_id, $id) as $a) {
        $avail[$a['gr_item_id']] = $a;
    }
}
?>

<div class="page-title-bar">
    <div>
        <h2 class="page-title-text"><i class="bi bi-pencil-square"></i> <?= htmlspecialchars($pr['return_number']) ?></h2>
        <div class="page-subtitle"><?= htmlspecialchars($page_title) ?></div>
    </div>
    <div class="page-actions">
        <a href="<?= url('purchases/purchase_return_view.php?id=' . $id) ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?>
        </a>
    </div>
</div>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4, menggantikan alert
   inline yang mendorong konten turun. Kunci sesi 'pr_error' TIDAK diubah.
   Saluran sukses sengaja dimatikan (''): halaman ini tidak pernah
   menampilkan pesan sukses — pesan itu milik halaman daftar setelah
   redirect, dan kalau dibaca di sini justru akan hilang dari sana. */
bltx_render_flash('pr', ['success' => '']);
?>

<form method="POST" action="<?= url('purchases/purchase_return_edit.php?id=' . $id) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['pr_header']) ?></div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['pr_return_number']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($pr['return_number']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['return_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="return_date" class="form-control" required value="<?= htmlspecialchars($pr['return_date']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['gr_number']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($pr['gr_number'] ?: '—') ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['supplier']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($pr['supplier_name'] ?: '—') ?>" disabled>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['reason']) ?> <span class="text-danger">*</span></label>
                            <textarea name="reason" rows="2" class="form-control" required><?= htmlspecialchars((string)$pr['reason']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['notes']) ?></label>
                            <textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars((string)$pr['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-calculator"></i> <?= htmlspecialchars($LANG['totals']) ?></div>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary small py-2">
                        <i class="bi bi-shield-check"></i> <?= htmlspecialchars($LANG['gr_no_inventory_notice']) ?>
                    </div>
                    <div class="d-flex justify-content-between fs-5">
                        <span class="fw-bold"><?= htmlspecialchars($LANG['total']) ?>:</span>
                        <strong id="prTotal" class="text-primary">0</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-box-seam"></i> <?= htmlspecialchars($LANG['items']) ?></div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['pr_qty_hint']) ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width:34%"><?= htmlspecialchars($LANG['item_name']) ?></th>
                                    <th style="width:14%" class="text-end"><?= htmlspecialchars($LANG['pr_available_qty']) ?></th>
                                    <th style="width:14%"><?= htmlspecialchars($LANG['pr_return_qty']) ?></th>
                                    <th style="width:16%" class="text-end"><?= htmlspecialchars($LANG['unit_price']) ?></th>
                                    <th style="width:22%"><?= htmlspecialchars($LANG['reason']) ?></th>
                                </tr>
                            </thead>
                            <tbody id="prItemsBody">
                            <?php foreach ($pr['items'] as $it):
                                $gid   = (int)($it['gr_item_id'] ?? 0);
                                // Sisa yang tersedia + qty milik baris ini sendiri
                                $limit = isset($avail[$gid]) ? (float)$avail[$gid]['available'] : (float)$it['quantity'];
                            ?>
                                <tr class="pr-item-row">
                                    <td>
                                        <?= htmlspecialchars($it['item_name']) ?>
                                        <input type="hidden" name="item_name[]"        value="<?= htmlspecialchars($it['item_name'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="gr_item_id[]"       value="<?= $gid ?>">
                                        <input type="hidden" name="po_item_id[]"       value="<?= (int)($it['po_item_id'] ?? 0) ?>">
                                        <input type="hidden" name="purchase_item_id[]" value="<?= (int)($it['purchase_item_id'] ?? 0) ?>">
                                        <input type="hidden" name="inventory_id[]"     value="<?= (int)($it['inventory_id'] ?? 0) ?>">
                                        <input type="hidden" name="unit_id[]"          value="<?= (int)($it['unit_id'] ?? 0) ?>">
                                    </td>
                                    <td class="text-end pr-avail" data-available="<?= (float)$limit ?>">
                                        <?= htmlspecialchars(gr_formatQty($limit)) ?>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="<?= (float)$limit ?>"
                                               name="quantity[]" class="form-control form-control-sm pr-qty"
                                               value="<?= (float)$it['quantity'] ?>">
                                    </td>
                                    <td class="text-end">
                                        <?= htmlspecialchars(formatCurrency((float)$it['unit_price'])) ?>
                                        <input type="hidden" name="unit_price[]" class="pr-price" value="<?= (float)$it['unit_price'] ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="item_reason[]" class="form-control form-control-sm" maxlength="255"
                                               value="<?= htmlspecialchars((string)$it['reason'], ENT_QUOTES) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 text-end">
            <a href="<?= url('purchases/purchase_return_view.php?id=' . $id) ?>" class="btn btn-sm btn-secondary">
                <i class="bi bi-x-lg"></i> <?= htmlspecialchars($LANG['cancel']) ?>
            </a>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-save"></i> <?= htmlspecialchars($LANG['save']) ?>
            </button>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';
    var body = document.getElementById('prItemsBody');
    if (!body) return;

    function fmt(n) {
        if (!isFinite(n)) return '0';
        return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }
    function recalc() {
        var total = 0;
        body.querySelectorAll('.pr-item-row').forEach(function (r) {
            var qtyEl = r.querySelector('.pr-qty');
            var avail = parseFloat(r.querySelector('.pr-avail').dataset.available) || 0;
            var qty   = parseFloat(qtyEl.value) || 0;
            if (qty > avail) { qty = avail; qtyEl.value = avail; }
            if (qty < 0)     { qty = 0;     qtyEl.value = 0; }
            total += qty * (parseFloat(r.querySelector('.pr-price').value) || 0);
        });
        document.getElementById('prTotal').textContent = fmt(total);
    }
    body.addEventListener('input', function (e) {
        if (e.target.classList.contains('pr-qty')) recalc();
    });
    recalc();
})();
</script>

<?php include '../includes/footer.php'; ?>