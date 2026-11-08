<?php

// /includes/po_edit.php

require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/purchase_orders_function.php';
// Dipakai untuk format & parsing nilai mata uang agar kolom Unit Price /
// Discount berperilaku sama persis dengan purchase_add.php & purchase_edit.php
// (pf_getCurrencySymbol / pf_getCurrencyCode / pf_parsePostedAmount).
require_once __DIR__ . '/../includes/purchases_function.php';

$currencyCode   = pf_getCurrencyCode();
$currencySymbol = pf_getCurrencySymbol();
$isIDR          = ($currencyCode === 'IDR');

$page_title = $LANG['edit_purchase_order'];
include '../includes/header.php';

requirePurchaseOrderUpdate();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('purchase_orders');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) redirect(url('purchases/pr_dahsboard_gate.php?tab=order'));

$po = po_getOne($conn, $id);
if (!$po) {
    bltx_flash_error($LANG['po_not_found']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=order'));
}

// Only draft/sent can be edited (superadmin can override and edit any status)
if (!isSuperAdmin() && !in_array($po['status'], ['draft','sent'], true)) {
    bltx_flash_error($LANG['po_cannot_edit']);
    redirect(url('purchases/po_view.php?id=' . $id));
}

$csrf_token = po_csrfToken();

// ── POST: update ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!po_csrfVerify((string)($_POST['csrf_token'] ?? ''))) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(url('purchases/po_edit.php?id=' . $id));
    }

    $order_date      = trim($_POST['order_date'] ?? '');
    $expected_date   = trim($_POST['expected_date'] ?? '') ?: null;
    $supplier_id     = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $payment_method  = trim($_POST['payment_method'] ?? 'cash');
    $payment_terms   = trim($_POST['payment_terms'] ?? '');
    // Shipping/"delivery" address = company info from Settings → General
    // (see po_getShippingInfo()), refreshed on every save — same as po_add.php.
    $delivery_addr   = (string) getSetting('po_company_address', '');
    $notes           = trim($_POST['notes'] ?? '');
    $overall_disc    = pf_parsePostedAmount($_POST['overall_discount'] ?? 0);
    $overall_round   = pf_parsePostedAmount($_POST['overall_price_rounding'] ?? 0);
    $tax             = pf_parsePostedAmount($_POST['tax'] ?? 0);

    $item_names    = $_POST['item_name']      ?? [];
    $item_invids   = $_POST['inventory_id']   ?? [];
    $item_qtys     = $_POST['quantity']       ?? [];
    $item_units    = $_POST['unit_id']        ?? [];
    $item_prices   = $_POST['unit_price']     ?? [];
    $item_discs    = $_POST['discount']       ?? [];
    $item_rounds   = $_POST['price_rounding'] ?? [];
    $item_cats     = $_POST['category_id']    ?? [];

    if (!is_array($item_names) || count(array_filter($item_names, 'strlen')) === 0) {
        bltx_flash_error($LANG['po_items_required']);
        redirect(url('purchases/po_edit.php?id=' . $id));
    }

    // Category dan Unit WAJIB diisi. Atribut `required` pada dropdown hanya
    // berlaku di browser dan bisa dilewati, jadi divalidasi ulang di server
    // untuk setiap baris item yang benar-benar terisi namanya.
    for ($i = 0; $i < count($item_names); $i++) {
        if (trim((string)$item_names[$i]) === '') continue;
        if ((int)($item_cats[$i] ?? 0) <= 0 || (int)($item_units[$i] ?? 0) <= 0) {
            bltx_flash_error($LANG['purchase_incomplete_form']);
            redirect(url('purchases/po_edit.php?id=' . $id));
        }
    }

    $conn->begin_transaction();
    try {
        // Compute total
        $items_to_save = [];
        $subtotal      = 0.0;
        for ($i = 0; $i < count($item_names); $i++) {
            $iname = trim((string)$item_names[$i]);
            if ($iname === '') continue;
            $qty   = (float)$item_qtys[$i];
            $price = pf_parsePostedAmount($item_prices[$i] ?? 0);
            $disc  = pf_parsePostedAmount($item_discs[$i] ?? 0);
            $rnd   = pf_parsePostedAmount($item_rounds[$i] ?? 0);
            $tot   = ($qty * $price) - $disc + $rnd;
            $items_to_save[] = [
                'inventory_id'   => (int)($item_invids[$i] ?? 0) ?: null,
                'name'           => $iname,
                'quantity'       => $qty,
                'unit_id'        => (int)($item_units[$i] ?? 0) ?: null,
                'unit_price'     => $price,
                'discount'       => $disc,
                'price_rounding' => $rnd,
                'category_id'    => (int)($item_cats[$i] ?? 0) ?: null,
                'total_price'    => $tot,
            ];
            $subtotal += $tot;
        }
        $total_amount = $subtotal - $overall_disc + $overall_round + $tax;

        // Update header
        $stmt = $conn->prepare(
            "UPDATE purchase_orders SET
                order_date=?, expected_date=?, supplier_id=?, payment_method=?,
                payment_terms=?, delivery_address=?, notes=?,
                overall_discount=?, overall_price_rounding=?, tax=?, total_amount=?
             WHERE id=?"
        );
        $stmt->bind_param(
            'ssissssddddi',
            $order_date, $expected_date, $supplier_id, $payment_method,
            $payment_terms, $delivery_addr, $notes,
            $overall_disc, $overall_round, $tax, $total_amount, $id
        );
        $stmt->execute();
        $stmt->close();

        // Snapshot Contact (users.full_name + users.phone) untuk PO LAMA yang
        // kolom snapshot-nya masih kosong — diambil dari user yang SEDANG
        // LOGIN dan disimpan sebagai TEKS sehingga permanen walaupun akun
        // user terkait nanti dihapus.
        // Snapshot yang SUDAH terisi sengaja TIDAK ditimpa: dokumen PO yang
        // sudah terbit harus tetap menampilkan contact yang sama seperti saat
        // diterbitkan. Kondisi `company_contact IS NULL OR = ''` pada WHERE
        // menjamin hal itu bahkan bila ada dua penyimpanan bersamaan.
        if (po_hasCompanyContactColumn($conn) && trim((string)($po['company_contact'] ?? '')) === '') {
            $contact_snapshot = po_currentUserContact($conn);
            if ($contact_snapshot !== '') {
                $stmtC = $conn->prepare(
                    "UPDATE purchase_orders SET company_contact = ?
                      WHERE id = ? AND (company_contact IS NULL OR company_contact = '')"
                );
                if ($stmtC) {
                    $stmtC->bind_param('si', $contact_snapshot, $id);
                    $stmtC->execute();
                    $stmtC->close();
                }
            }
        }

        // Wipe + re-insert items (simple approach — unchanged from before)
        $delS = $conn->prepare("DELETE FROM purchase_order_items WHERE po_id=?");
        $delS->bind_param('i', $id);
        $delS->execute();
        $delS->close();

        $stmtI = $conn->prepare(
            "INSERT INTO purchase_order_items
                (po_id, inventory_id, item_name, quantity, unit_id, unit_price, discount, price_rounding, category_id, total_price)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($items_to_save as $it) {
            $stmtI->bind_param(
                'iisdidddid',
                $id, $it['inventory_id'], $it['name'], $it['quantity'], $it['unit_id'], $it['unit_price'],
                $it['discount'], $it['price_rounding'], $it['category_id'], $it['total_price']
            );
            $stmtI->execute();
        }
        $stmtI->close();

        $conn->commit();
        if (function_exists('logActivity')) logActivity('po_update', "Updated PO {$po['po_number']} (#{$id})");
        bltx_flash_success($LANG['po_updated']);
        redirect(url('purchases/po_view.php?id=' . $id));
    } catch (Throwable $e) {
        $conn->rollback();
        bltx_flash_error(($LANG['error']) . ': ' . $e->getMessage());
        redirect(url('purchases/po_edit.php?id=' . $id));
    }
}

// ── Dropdown / picker data ──────────────────────────────────────────────────
$suppliers      = $conn->query("SELECT id, name AS supplier_name FROM suppliers ORDER BY name");
$units          = $conn->query("SELECT id, name, symbol FROM units ORDER BY name");
$categories     = $conn->query("SELECT id, name FROM categories ORDER BY name");
$inventoryItems = po_getInventoryForPicker($conn);

// Payment Method kini diambil dari tabel payment_methods (bukan input teks bebas)
$paymentMethods = $conn->query("SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY name");

// ── Data untuk JS (kategori / unit / inventory / item existing / label) ─────
$__cats = [];  if ($categories) { while ($c = $categories->fetch_assoc()) $__cats[]  = ['id' => (int)$c['id'], 'name' => $c['name']]; }
$__units = []; if ($units)      { while ($u = $units->fetch_assoc())      $__units[] = ['id' => (int)$u['id'], 'name' => $u['name'], 'symbol' => $u['symbol']]; }
// Picker inventory: HANYA nama barang, kode barang TIDAK disertakan
$__inv = [];
foreach ($inventoryItems as $inv) {
    $__inv[] = [
        'id'          => (int)$inv['id'],
        'name'        => $inv['item_name'],
        'price'       => (float)$inv['unit_price'],
        'unit_id'     => (int)($inv['unit_id'] ?? 0),
        'category_id' => (int)($inv['category_id'] ?? 0),
    ];
}
$catsJson  = json_encode($__cats,  JSON_UNESCAPED_UNICODE);
$unitsJson = json_encode($__units, JSON_UNESCAPED_UNICODE);
$invJson   = json_encode($__inv,   JSON_UNESCAPED_UNICODE);
// Closure TIDAK mewarisi variabel dari scope luar, jadi $po harus dikirim
// lewat "use". Tanpa itu $po bernilai null di dalam closure.
$itemsJson = json_encode((function () use ($po) {
    $rows = [];
    foreach (($po['items'] ?? []) as $it) {
        $rows[] = [
            'inventory_id'   => (int)($it['inventory_id'] ?? 0),
            'item_name'      => $it['item_name'],
            'category_id'    => (int)($it['category_id'] ?? 0),
            'unit_id'        => (int)($it['unit_id'] ?? 0),
            'quantity'       => (float)$it['quantity'],
            'unit_price'     => (float)$it['unit_price'],
            'discount'       => (float)$it['discount'],
            'price_rounding' => (float)($it['price_rounding'] ?? 0),
        ];
    }
    return $rows;
})(), JSON_UNESCAPED_UNICODE);
$langJson  = json_encode([
    'select'            => $LANG['select'],
    'select_item'       => $LANG['po_select_item'],
    'manual_entry'      => $LANG['po_manual_entry'],
    'search'            => $LANG['search'],
    'no_data'           => $LANG['no_data'],
    'add_item'          => $LANG['add_item'],
    'remove'            => $LANG['remove'],
    'item_name'         => $LANG['item_name'],
    'category'          => $LANG['category'],
    'unit'              => $LANG['unit'],
    'quantity'          => $LANG['quantity'],
    'unit_price'        => $LANG['unit_price'],
    'discount'          => $LANG['discount'],
    'total_price'       => $LANG['total_price'],
    'at_least_one_item' => $LANG['po_items_required'],
], JSON_UNESCAPED_UNICODE);


?>
<style>
/* ── Lebar kolom item (disamakan dengan purchase_add.php / purchase_edit.php) ── */
@media (min-width: 768px) {
    .col-item-name   { flex: 2 1 200px !important; min-width: 200px !important; }
    .col-item-field  { flex: 1 1 0px !important;   min-width: 120px !important; }
    .col-item-action { flex: 0 0 70px !important;  min-width: 70px !important; }
}
@media (max-width: 767px) {
    .col-item-name   { flex: 1 1 100% !important;  min-width: 200px !important; }
    .col-item-field  { flex: 0 0 auto !important;  min-width: 120px !important; }
    .col-item-action { flex: 0 0 auto !important;  min-width: 70px !important; }
}
/* ── Currency input group ── */
.input-currency .input-group-text { font-weight: 600; min-width: 38px; justify-content: center; }
.input-currency input { text-align: right; }
/* ── Item rows ── */
.item-row { background: var(--bs-body-bg, #fff); border: 1px solid var(--bs-border-color, #dee2e6) !important; transition: box-shadow .15s; }
.item-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.item-number-badge {
    width: 30px; height: 30px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .85rem;
    background: var(--bs-primary); color: #fff; flex-shrink: 0;
}
/* ── Totals panel ── */
.totals-panel { background: var(--bs-tertiary-bg, #f8f9fa); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .5rem; }
.totals-panel .total-row { display: flex; justify-content: space-between; align-items: center; padding: .5rem 0; border-bottom: 1px dashed var(--bs-border-color, #dee2e6); }
.totals-panel .total-row:last-child { border-bottom: 0; padding-top: .75rem; }
.totals-panel .total-label { font-weight: 500; color: var(--bs-secondary-color, #6c757d); }
.totals-panel .total-value { font-weight: 600; font-size: 1rem; }
.totals-panel .total-value.grand { font-size: 1.25rem; color: var(--bs-primary); }
/* ── Scrollbar horizontal baris item ── */
.custom-scrollbar::-webkit-scrollbar { height: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: var(--bs-light, #f8f9fa); border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #adb5bd; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #6c757d; }
/* ── Dropdown dengan pencarian (dibangun di atas <select> asli, tanpa library baru) ── */
.ss-wrap { position: relative; }
.ss-toggle { text-align: left; cursor: pointer; }
.ss-toggle.placeholder-text { color: var(--bs-secondary-color, #6c757d); }
.ss-panel {
    /* position:fixed dipakai (bukan absolute) supaya panel TIDAK terpotong oleh
       kontainer baris item yang memakai overflow-hidden / overflow-x-auto.
       Koordinatnya dihitung di JS saat panel dibuka. */
    position: fixed; z-index: 1090;
    background: var(--bs-body-bg, #fff);
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: .375rem; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15);
    display: none;
}
.ss-panel.show { display: block; }
.ss-panel .ss-search { border: 0; border-bottom: 1px solid var(--bs-border-color, #dee2e6); border-radius: .375rem .375rem 0 0; }
.ss-panel .ss-search:focus { box-shadow: none; }
.ss-list { max-height: 220px; overflow-y: auto; }
.ss-opt { padding: .35rem .75rem; cursor: pointer; font-size: .875rem; }
/* Warna sorot dibuat ABU-ABU netral (bukan biru) agar sama dengan tampilan
   dropdown bawaan browser pada Category / Unit. Warna teks dibiarkan
   mengikuti tema sehingga tetap terbaca pada mode gelap maupun terang. */
.ss-opt:hover      { background: var(--bs-secondary-bg, #e9ecef); color: inherit; }
.ss-opt.active     { background: var(--bs-tertiary-bg, #f8f9fa); color: inherit; }
.ss-opt.active:hover { background: var(--bs-secondary-bg, #e9ecef); }
.ss-empty { padding: .5rem .75rem; font-size: .85rem; color: var(--bs-secondary-color, #6c757d); }
</style>

<div class="page-title-bar">
    <h2 class="page-title-text">
        <i class="bi bi-pencil-square"></i>
        <?= htmlspecialchars($page_title) ?> — <?= htmlspecialchars($po['po_number']) ?>
    </h2>
    <div class="page-actions">
        <a href="<?= url('purchases/po_view.php?id=' . $id) ?>" class="btn btn-sm btn-info">
            <i class="bi bi-eye"></i> <?= htmlspecialchars($LANG['view']) ?>
        </a>
        <a href="<?= url('purchases/pr_dahsboard_gate.php?tab=order') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?>
        </a>
    </div>
</div>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4, menggantikan alert
   inline yang mendorong konten turun. Kunci sesi 'po_error' TIDAK diubah.
   Saluran sukses sengaja dimatikan (''): halaman ini tidak pernah
   menampilkan pesan sukses — pesan itu milik halaman daftar setelah
   redirect, dan kalau dibaca di sini justru akan hilang dari sana. */
bltx_render_flash('po', ['success' => '']);
?>

<form method="POST" action="<?= url('purchases/po_edit.php?id=' . $id) ?>" id="poEditForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="row g-3">

        <div class="col-12">
            <div class="card">
                <div class="card-header"><div class="card-title"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['po_header']) ?></div></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['po_doc_reference']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($po['po_number']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['order_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="order_date" class="form-control" required value="<?= htmlspecialchars($po['order_date']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['expected_date']) ?></label>
                            <input type="date" name="expected_date" class="form-control" value="<?= htmlspecialchars($po['expected_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['supplier']) ?></label>
                            <select name="supplier_id" class="form-select ss-select">
                                <option value="">— <?= htmlspecialchars($LANG['select']) ?> —</option>
                                <?php while ($s = $suppliers->fetch_assoc()): ?>
                                    <option value="<?= $s['id'] ?>" <?= ((int)$po['supplier_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['supplier_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['payment_method']) ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="">— <?= htmlspecialchars($LANG['select']) ?> —</option>
                                <?php
                                $__pm_cur   = (string)($po['payment_method'] ?? '');
                                $__pm_found = false;
                                while ($pm = $paymentMethods->fetch_assoc()):
                                    $__sel = ($__pm_cur !== '' && $__pm_cur === $pm['name']);
                                    if ($__sel) $__pm_found = true;
                                ?>
                                    <option value="<?= htmlspecialchars($pm['name'], ENT_QUOTES) ?>" <?= $__sel ? 'selected' : '' ?>><?= htmlspecialchars($pm['name']) ?></option>
                                <?php endwhile; ?>
                                <?php if ($__pm_cur !== '' && !$__pm_found): ?>
                                    <!-- Nilai lama yang tidak ada di tabel payment_methods tetap dipertahankan -->
                                    <option value="<?= htmlspecialchars($__pm_cur, ENT_QUOTES) ?>" selected><?= htmlspecialchars($__pm_cur) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['payment_terms']) ?></label>
                            <input type="text" name="payment_terms" class="form-control" value="<?= htmlspecialchars($po['payment_terms'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['po_doc_notes_label']) ?></label>
                            <textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars($po['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>


    <!-- ── Items (tampilan disamakan dengan card "Item List" di purchase_add.php) ── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-list-ul"></i> <?= htmlspecialchars($LANG['item'] . ' ' . $LANG['list'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="card-body">
                <div id="itemsContainer"></div>

                <!-- ── Totals panel (menggantikan card Totals) ── -->
                <div class="totals-panel p-3 mt-3">
                    <div class="total-row">
                        <span class="total-label"><i class="bi bi-list-check me-1"></i><?= htmlspecialchars($LANG['total'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="total-value" id="subtotalDisplay"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?> 0</span>
                    </div>
                    <div class="total-row">
                        <span class="total-label"><i class="bi bi-percent me-1"></i><?= htmlspecialchars($LANG['overall_discount'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span>
                            <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                                <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" class="form-control overall-currency-display" name="overall_discount"
                                       id="overallDiscountInput" value="<?= htmlspecialchars(pf_formatInputDisplay((float)$po['overall_discount']), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"
                                       placeholder="0" autocomplete="off">
                            </div>
                        </span>
                    </div>
                    <div class="total-row">
                        <span class="total-label"><i class="bi bi-calculator me-1"></i><?= htmlspecialchars($LANG['price_rounding'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span>
                            <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                                <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" class="form-control overall-currency-display" name="overall_price_rounding"
                                       id="overallPriceRoundingInput" value="<?= htmlspecialchars(pf_formatInputDisplay((float)$po['overall_price_rounding']), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"
                                       placeholder="0" autocomplete="off">
                            </div>
                        </span>
                    </div>
                    <div class="total-row">
                        <span class="total-label"><i class="bi bi-receipt me-1"></i><?= htmlspecialchars($LANG['po_doc_ppn_label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span>
                            <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                                <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" class="form-control overall-currency-display" name="tax"
                                       id="taxInput" value="<?= htmlspecialchars(pf_formatInputDisplay((float)$po['tax']), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"
                                       placeholder="0" autocomplete="off">
                            </div>
                        </span>
                    </div>
                    <div class="total-row">
                        <span class="total-label fw-bold fs-5"><i class="bi bi-cash-coin me-1"></i><?= htmlspecialchars($LANG['grand_total'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="total-value grand" id="grandTotalDisplay"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?> 0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

        <div class="col-12 text-end">
            <a href="<?= url('purchases/po_view.php?id=' . $id) ?>" class="btn btn-sm btn-secondary"><i class="bi bi-x-lg"></i> <?= htmlspecialchars($LANG['cancel']) ?></a>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> <?= htmlspecialchars($LANG['save']) ?></button>
        </div>
    </div>
</form>

<!-- Template row for adding new items -->

<script>
(function () {
"use strict";

/* ── Config ───────────────────────────────────────────────── */
var CURRENCY_SYMBOL = <?= json_encode($currencySymbol) ?>;
var IS_IDR          = <?= $isIDR ? 'true' : 'false' ?>;
var CATEGORIES      = <?= $catsJson ?>;
var UNITS           = <?= $unitsJson ?>;
var INVENTORY       = <?= $invJson ?>;
var EXISTING_ITEMS  = <?= $itemsJson ?>;
var LANG            = <?= $langJson ?>;

/* ── Number helpers (sama dengan purchase_add.php) ─────────── */
function parseAmount(str) {
    str = String(str || "0");
    if (IS_IDR) { str = str.replace(/\./g, "").replace(",", "."); }
    else        { str = str.replace(/,/g, ""); }
    return parseFloat(str) || 0;
}
function formatDisplay(num) {
    num = Math.abs(parseFloat(num) || 0);
    if (IS_IDR) return Math.round(num).toLocaleString("id-ID");
    return num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}
function syncCurrencyInput(input) {
    var raw = input.value.replace(/[^0-9,\.]/g, "");
    if (raw === "") { input.value = ""; return; }
    if (IS_IDR) {
        raw = raw.replace(/\./g, "").replace(/,/g, "");
        if (raw === "") { input.value = "0"; return; }
        input.value = parseInt(raw, 10).toLocaleString("id-ID");
    } else {
        raw = raw.replace(/,/g, "");
        var parts = raw.split(".");
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        if (parts.length > 2) parts = [parts[0], parts[1]];
        input.value = parts.join(".");
    }
}
function esc(str) {
    return String(str).replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
}

/* ── Totals ───────────────────────────────────────────────── */
function calcItemTotal(row) {
    var qty   = parseFloat(row.querySelector(".item-qty").value) || 0;
    var price = parseAmount(row.querySelector('[name="unit_price[]"]').value);
    var disc  = parseAmount(row.querySelector('[name="discount[]"]').value);
    var rnd   = parseAmount(row.querySelector('[name="price_rounding[]"]').value);
    var total = (qty * price) - disc + rnd;
    row.querySelector(".item-total").textContent = CURRENCY_SYMBOL + " " + formatDisplay(total);
    return total;
}
function updateGrandTotal() {
    var subtotal = 0;
    document.querySelectorAll(".item-row").forEach(function (row) { subtotal += calcItemTotal(row); });
    var d = parseAmount(document.getElementById("overallDiscountInput").value);
    var r = parseAmount(document.getElementById("overallPriceRoundingInput").value);
    var t = parseAmount(document.getElementById("taxInput").value);
    document.getElementById("subtotalDisplay").textContent   = CURRENCY_SYMBOL + " " + formatDisplay(subtotal);
    document.getElementById("grandTotalDisplay").textContent = CURRENCY_SYMBOL + " " + formatDisplay(subtotal - d + r + t);
}

/* ── Option builders ──────────────────────────────────────── */
function buildCategoryOptions(selectedId) {
    var html = '<option value="">' + LANG.select + '</option>';
    CATEGORIES.forEach(function (c) {
        html += '<option value="' + c.id + '"' + (c.id == selectedId ? " selected" : "") + '>' + esc(c.name) + '</option>';
    });
    return html;
}
function buildUnitOptions(selectedId) {
    var html = '<option value="">' + LANG.select + '</option>';
    UNITS.forEach(function (u) {
        html += '<option value="' + u.id + '"' + (u.id == selectedId ? " selected" : "") + '>' + esc(u.name) + ' (' + esc(u.symbol) + ')</option>';
    });
    return html;
}
/* Picker inventory — HANYA nama barang, tanpa kode barang */
function buildInventoryOptions(selectedId) {
    var html = '<option value="">' + LANG.select_item + '</option>';
    INVENTORY.forEach(function (i) {
        html += '<option value="' + i.id + '"'
             +  ' data-name="'     + esc(i.name)  + '"'
             +  ' data-price="'    + i.price      + '"'
             +  ' data-unitid="'   + i.unit_id    + '"'
             +  ' data-category="' + i.category_id + '"'
             +  (i.id == selectedId ? " selected" : "")
             +  '>' + esc(i.name) + '</option>';
    });
    return html;
}

/* ── Build one item row (urutan: Item Name, Category, Unit,
      Quantity, Unit Price, Discount, Total Price, aksi) ───── */
function createItemRow(data) {
    data = data || {};
    var div = document.createElement("div");
    div.className = "item-row rounded p-3 mb-3";
    div.innerHTML =
      '<div class="d-flex align-items-start gap-3">' +
        '<div class="item-number-badge mt-1 flex-shrink-0"><span class="item-number"></span></div>' +
        '<div class="flex-fill overflow-hidden"><div class="d-flex flex-nowrap align-items-end gap-2 w-100 overflow-x-auto pb-2 custom-scrollbar">' +
          '<div class="col-item-name">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-box me-1"></i>' + LANG.item_name + ' <span class="text-danger">*</span></label>' +
            '<select class="form-select form-select-sm po-item-picker mb-1 ss-select">' + buildInventoryOptions(data.inventory_id || 0) + '</select>' +
            '<input type="text" class="form-control form-control-sm po-item-name" name="item_name[]" value="' + esc(data.item_name || "") + '" placeholder="' + LANG.manual_entry + '" required>' +
            '<input type="hidden" name="inventory_id[]" class="po-item-invid" value="' + (data.inventory_id || "") + '">' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-tag me-1"></i>' + LANG.category + ' <span class="text-danger">*</span></label>' +
            '<select class="form-select form-select-sm" name="category_id[]" required>' + buildCategoryOptions(data.category_id || 0) + '</select>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-rulers me-1"></i>' + LANG.unit + ' <span class="text-danger">*</span></label>' +
            '<select class="form-select form-select-sm" name="unit_id[]" required>' + buildUnitOptions(data.unit_id || 0) + '</select>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-hash me-1"></i>' + LANG.quantity + ' <span class="text-danger">*</span></label>' +
            '<input type="number" step="0.01" min="0.01" class="form-control form-control-sm item-qty" name="quantity[]" value="' + (data.quantity || "1") + '" required>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-currency-dollar me-1"></i>' + LANG.unit_price + ' <span class="text-danger">*</span></label>' +
            '<div class="input-group input-group-sm input-currency">' +
              '<span class="input-group-text">' + CURRENCY_SYMBOL + '</span>' +
              '<input type="text" class="form-control item-currency-display" name="unit_price[]" value="' + (data.unit_price ? formatDisplay(data.unit_price) : "") + '" inputmode="numeric" placeholder="0" autocomplete="off">' +
            '</div>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-percent me-1"></i>' + LANG.discount + '</label>' +
            '<div class="input-group input-group-sm input-currency">' +
              '<span class="input-group-text">' + CURRENCY_SYMBOL + '</span>' +
              '<input type="text" class="form-control item-currency-display" name="discount[]" value="' + formatDisplay(data.discount || 0) + '" inputmode="numeric" placeholder="0" autocomplete="off">' +
            '</div>' +
          '</div>' +
          '<div class="col-item-field" style="padding-bottom: 2px;">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-cash-stack me-1"></i>' + LANG.total_price + '</label>' +
            '<div class="fw-bold text-success item-total fs-6">' + CURRENCY_SYMBOL + ' 0</div>' +
          '</div>' +
          '<div class="col-item-action d-flex gap-1 align-items-end" style="padding-bottom: 2px;">' +
            '<button type="button" class="btn btn-success btn-sm btn-add-item" title="' + LANG.add_item + '"><i class="bi bi-plus-circle"></i></button>' +
            '<button type="button" class="btn btn-danger btn-sm btn-remove-item" title="' + LANG.remove + '"><i class="bi bi-trash"></i></button>' +
          '</div>' +
          '<input type="hidden" name="price_rounding[]" value="' + (data.price_rounding || 0) + '">' +
        '</div></div>' +
      '</div>';
    return div;
}

function renumberItems() {
    document.querySelectorAll(".item-row .item-number").forEach(function (el, i) { el.textContent = i + 1; });
}

/* ── Searchable dropdown ──────────────────────────────────────
   Dibangun DI ATAS <select> asli: select-nya tetap ada dan tetap
   ikut ter-submit, hanya disembunyikan. Tidak ada library baru. */
function makeSearchable(select) {
    if (!select || select.dataset.ssInit === "1") return;
    select.dataset.ssInit = "1";

    var wrap = document.createElement("div");
    wrap.className = "ss-wrap";
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add("d-none");

    var isSm   = select.classList.contains("form-select-sm");
    var toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "form-select ss-toggle" + (isSm ? " form-select-sm" : "");

    var panel = document.createElement("div");
    panel.className = "ss-panel";
    panel.innerHTML = '<input type="text" class="form-control form-control-sm ss-search" placeholder="' + LANG.search + '" autocomplete="off"><div class="ss-list"></div>';

    wrap.appendChild(toggle);
    wrap.appendChild(panel);

    var search = panel.querySelector(".ss-search");
    var list   = panel.querySelector(".ss-list");

    function syncToggle() {
        var opt = select.options[select.selectedIndex];
        var txt = opt ? opt.textContent.trim() : "";
        toggle.textContent = txt;
        toggle.classList.toggle("placeholder-text", !select.value);
    }
    function renderList(filter) {
        filter = (filter || "").toLowerCase();
        var html = "", shown = 0;
        Array.prototype.forEach.call(select.options, function (o, i) {
            var txt = o.textContent.trim();
            if (filter && txt.toLowerCase().indexOf(filter) === -1) return;
            shown++;
            html += '<div class="ss-opt' + (i === select.selectedIndex ? " active" : "") + '" data-i="' + i + '">' + esc(txt) + '</div>';
        });
        list.innerHTML = shown ? html : '<div class="ss-empty">' + LANG.no_data + '</div>';
        if (panel.classList.contains("show")) place();
    }
    /* Panel memakai position:fixed, jadi koordinatnya dihitung dari posisi
       tombol di viewport. Ini membuat daftar tetap terlihat penuh walaupun
       baris item berada di dalam kontainer ber-overflow. */
    function place() {
        var r = toggle.getBoundingClientRect();
        panel.style.width = r.width + "px";
        panel.style.left  = r.left + "px";
        panel.style.top   = (r.bottom + 2) + "px";
        var h = panel.offsetHeight || 0;
        // Balik ke atas bila ruang di bawah tidak cukup
        if (h && (r.bottom + h + 8) > window.innerHeight && r.top > (h + 8)) {
            panel.style.top = (r.top - h - 2) + "px";
        }
    }
    function open() {
        document.querySelectorAll(".ss-panel.show").forEach(function (p) { p.classList.remove("show"); });
        renderList("");
        search.value = "";
        panel.classList.add("show");
        place();
        search.focus();
    }
    function close() { panel.classList.remove("show"); }

    // Ikut bergerak saat halaman atau baris item digulir / jendela diubah
    window.addEventListener("scroll", function () { if (panel.classList.contains("show")) place(); }, true);
    window.addEventListener("resize", function () { if (panel.classList.contains("show")) place(); });

    toggle.addEventListener("click", function (e) {
        e.preventDefault();
        panel.classList.contains("show") ? close() : open();
    });
    search.addEventListener("input", function () { renderList(search.value); });
    list.addEventListener("click", function (e) {
        var el = e.target.closest(".ss-opt");
        if (!el) return;
        select.selectedIndex = parseInt(el.dataset.i, 10);
        select.dispatchEvent(new Event("change", { bubbles: true }));
        syncToggle();
        close();
    });
    document.addEventListener("click", function (e) { if (!wrap.contains(e.target)) close(); });
    select.addEventListener("change", syncToggle);
    syncToggle();
}
function initSearchables(scope) {
    (scope || document).querySelectorAll("select.ss-select").forEach(makeSearchable);
}

/* ── Add / Remove item (event delegation) ─────────────────── */
var container = document.getElementById("itemsContainer");
container.addEventListener("click", function (e) {
    var addBtn = e.target.closest(".btn-add-item");
    if (addBtn) {
        var newRow  = createItemRow();
        var current = addBtn.closest(".item-row");
        if (current && current.nextSibling) container.insertBefore(newRow, current.nextSibling);
        else                                container.appendChild(newRow);
        initSearchables(newRow);
        renumberItems();
        updateGrandTotal();
        return;
    }
    var delBtn = e.target.closest(".btn-remove-item");
    if (!delBtn) return;
    if (document.querySelectorAll(".item-row").length <= 1) {
        if (typeof Swal !== "undefined") {
            Swal.fire({ icon: "warning", title: "", text: LANG.at_least_one_item, timer: 2500, showConfirmButton: false });
        }
        return;
    }
    delBtn.closest(".item-row").remove();
    renumberItems();
    updateGrandTotal();
});

/* ── Input formatting & recalc ────────────────────────────── */
document.addEventListener("input", function (e) {
    if (e.target.matches(".item-currency-display, .overall-currency-display")) {
        syncCurrencyInput(e.target);
        updateGrandTotal();
    }
    if (e.target.matches(".item-qty")) updateGrandTotal();
});

/* ── Inventory picker mengisi nama / harga / unit / kategori ── */
container.addEventListener("change", function (e) {
    if (!e.target.classList.contains("po-item-picker")) return;
    var row  = e.target.closest(".item-row");
    var opt  = e.target.selectedOptions[0];
    var name = row.querySelector(".po-item-name");
    var inv  = row.querySelector(".po-item-invid");
    if (!opt || !opt.value) { inv.value = ""; return; }
    name.value = opt.dataset.name || "";
    inv.value  = opt.value;
    var priceIn = row.querySelector('[name="unit_price[]"]');
    if (priceIn) { priceIn.value = formatDisplay(opt.dataset.price || 0); }
    var unitSel = row.querySelector('[name="unit_id[]"]');
    if (unitSel && opt.dataset.unitid && opt.dataset.unitid !== "0") unitSel.value = opt.dataset.unitid;
    var catSel = row.querySelector('[name="category_id[]"]');
    if (catSel && opt.dataset.category && opt.dataset.category !== "0") catSel.value = opt.dataset.category;
    updateGrandTotal();
});

/* ── Seed rows ────────────────────────────────────────────── */
if (EXISTING_ITEMS.length) {
    EXISTING_ITEMS.forEach(function (it) { container.appendChild(createItemRow(it)); });
} else {
    container.appendChild(createItemRow());
}
initSearchables(document);
renumberItems();
updateGrandTotal();
})();
</script>

<?php include '../includes/footer.php'; ?>