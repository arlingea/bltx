<?php
// ═══════════════════════════════════════════════════════════════════════════
//  PURCHASE RETURN — CREATE (FLOW BARU)
//
//  Sumber return: Goods Receipt (purchase_return_add.php?gr_id=123).
//  Qty maksimal per baris = accepted_quantity - yang sudah pernah dikembalikan.
//  Validasi final tetap dilakukan server-side oleh pret_create().
//
//  TIDAK ADA update inventory / stock movement.
//  Tabel `returns` LAMA tidak disentuh sama sekali oleh halaman ini.
// ═══════════════════════════════════════════════════════════════════════════
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/purchase_orders_function.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
require_once __DIR__ . '/../includes/purchase_returns_function.php';

$page_title = $LANG['pr_add'];
include '../includes/header.php';

requirePurchaseReturnAccess();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('returns');
if (!canCreatePurchaseReturn()) {
    redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
}
if (!pret_tablesExist($conn) || !gr_tablesExist($conn)) {
    bltx_flash_error($LANG['pr_tables_missing']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=returns'));
}

$csrf_token = pret_csrfToken();

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = pret_create($conn, $_POST);
    if ($res['success']) {
        bltx_flash_success($LANG['save_success'] . ': ' . $res['return_number']);
        redirect(url('purchases/purchase_return_view.php?id=' . (int)$res['return_id']));
    }
    $key = (string)$res['message_key'];
    bltx_flash_error(isset($LANG[$key]) ? $LANG[$key] : $LANG['error']);
    $back = (int)($_POST['gr_id'] ?? 0);
    redirect(url('purchases/purchase_return_add.php' . ($back > 0 ? '?gr_id=' . $back : '')));
}

$gr_id  = (int)($_GET['gr_id'] ?? 0);
$gr     = null;
$avail  = [];

if ($gr_id > 0) {
    $gr = gr_getOne($conn, $gr_id);
    if (!$gr) {
        bltx_flash_error($LANG['gr_not_found']);
        redirect(url('purchases/purchase_return_add.php'));
    }
    if ($gr['status'] !== 'received') {
        bltx_flash_error($LANG['pr_gr_not_received']);
        redirect(url('purchases/goods_receipt_view.php?id=' . $gr_id));
    }
    $avail = pret_availableFromGr($conn, $gr_id, 0);
}

// Daftar Goods Receipt yang bisa dijadikan sumber return
$eligible_grs = [];
if ($gr_id <= 0) {
    $q = $conn->query(
        "SELECT id, gr_number, receipt_date, po_number, supplier_name
           FROM goods_receipts
          WHERE status = 'received'
          ORDER BY receipt_date DESC, id DESC
          LIMIT 200"
    );
    if ($q) { while ($r = $q->fetch_assoc()) $eligible_grs[] = $r; }
}
?>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-arrow-return-left"></i> <?= htmlspecialchars($page_title) ?></h2>
    <div class="page-actions">
        <a href="<?= $gr_id > 0 ? url('purchases/goods_receipt_view.php?id=' . $gr_id) : url('purchases/pr_dahsboard_gate.php?tab=returns') ?>"
           class="btn btn-sm btn-secondary">
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

<?php if ($gr_id <= 0): ?>
<!-- ══ STEP 1 : pilih Goods Receipt ══ -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-list-check"></i> <?= htmlspecialchars($LANG['pr_select_gr']) ?></div>
    </div>
    <div class="card-body">
        <?php if (!$eligible_grs): ?>
            <p class="text-muted mb-0"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['pr_no_eligible_gr']) ?></p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0 datatable">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($LANG['gr_number']) ?></th>
                        <th><?= htmlspecialchars($LANG['po_number']) ?></th>
                        <th><?= htmlspecialchars($LANG['supplier']) ?></th>
                        <th><?= htmlspecialchars($LANG['gr_receipt_date']) ?></th>
                        <th><?= htmlspecialchars($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($eligible_grs as $g): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($g['gr_number']) ?></strong></td>
                        <td><?= htmlspecialchars($g['po_number'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($g['supplier_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($g['receipt_date']))) ?></td>
                        <td>
                            <a href="<?= url('purchases/purchase_return_add.php?gr_id=' . (int)$g['id']) ?>" class="btn btn-sm btn-warning">
                                <i class="bi bi-arrow-return-left"></i> <?= htmlspecialchars($LANG['pr_create_return']) ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif (!$avail): ?>
<div class="alert alert-warning">
    <i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['pr_nothing_returnable']) ?>
</div>

<?php else: ?>
<!-- ══ STEP 2 : form return ══ -->
<form method="POST" action="<?= url('purchases/purchase_return_add.php?gr_id=' . $gr_id) ?>" id="prForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="gr_id" value="<?= (int)$gr_id ?>">

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['pr_header']) ?></div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['gr_number']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($gr['gr_number']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['po_number']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($gr['po_number'] ?: '—') ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['supplier']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($gr['supplier_name'] ?: '—') ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['return_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="return_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['reason']) ?> <span class="text-danger">*</span></label>
                            <textarea name="reason" rows="2" class="form-control" required></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['notes']) ?></label>
                            <textarea name="notes" rows="3" class="form-control"></textarea>
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
                                    <th style="width:28%"><?= htmlspecialchars($LANG['item_name']) ?></th>
                                    <th style="width:10%" class="text-end"><?= htmlspecialchars($LANG['gr_accepted_qty']) ?></th>
                                    <th style="width:10%" class="text-end"><?= htmlspecialchars($LANG['pr_returned_qty']) ?></th>
                                    <th style="width:10%" class="text-end"><?= htmlspecialchars($LANG['pr_available_qty']) ?></th>
                                    <th style="width:12%"><?= htmlspecialchars($LANG['pr_return_qty']) ?></th>
                                    <th style="width:12%" class="text-end"><?= htmlspecialchars($LANG['unit_price']) ?></th>
                                    <th style="width:18%"><?= htmlspecialchars($LANG['reason']) ?></th>
                                </tr>
                            </thead>
                            <tbody id="prItemsBody">
                            <?php foreach ($avail as $a): ?>
                                <tr class="pr-item-row">
                                    <td>
                                        <?= htmlspecialchars($a['item_name']) ?>
                                        <input type="hidden" name="item_name[]"    value="<?= htmlspecialchars($a['item_name'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="gr_item_id[]"   value="<?= (int)$a['gr_item_id'] ?>">
                                        <input type="hidden" name="po_item_id[]"   value="<?= (int)($a['po_item_id'] ?? 0) ?>">
                                        <input type="hidden" name="purchase_item_id[]" value="0">
                                        <input type="hidden" name="inventory_id[]" value="<?= (int)($a['inventory_id'] ?? 0) ?>">
                                        <input type="hidden" name="unit_id[]"      value="<?= (int)($a['unit_id'] ?? 0) ?>">
                                    </td>
                                    <td class="text-end"><?= htmlspecialchars(gr_formatQty($a['accepted'])) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(gr_formatQty($a['returned'])) ?></td>
                                    <td class="text-end fw-semibold pr-avail" data-available="<?= (float)$a['available'] ?>">
                                        <?= htmlspecialchars(gr_formatQty($a['available']) . ' ' . $a['unit_symbol']) ?>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="<?= (float)$a['available'] ?>"
                                               name="quantity[]" class="form-control form-control-sm pr-qty" value="0"
                                               <?= $a['available'] <= 0 ? 'readonly' : '' ?>>
                                    </td>
                                    <td class="text-end">
                                        <?= htmlspecialchars(formatCurrency((float)$a['unit_price'])) ?>
                                        <input type="hidden" name="unit_price[]" class="pr-price" value="<?= (float)$a['unit_price'] ?>">
                                    </td>
                                    <td><input type="text" name="item_reason[]" class="form-control form-control-sm" maxlength="255"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 text-end">
            <a href="<?= url('purchases/goods_receipt_view.php?id=' . $gr_id) ?>" class="btn btn-sm btn-secondary">
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
<?php endif; ?>

<?php include '../includes/footer.php'; ?>