<?php
// ═══════════════════════════════════════════════════════════════════════════
//  GOODS RECEIPT — CREATE
//
//  Dibuka dari tombol "Mark as Received" pada po_view.php
//  (purchases/goods_receipt_add.php?po_id=123) atau langsung dari daftar GR.
//
//  Data Purchase Order hanya dipakai sebagai REFERENCE dan di-snapshot ke
//  baris Goods Receipt. Purchase Order TIDAK PERNAH diubah oleh halaman ini.
//  Tidak ada update inventory / stock movement sama sekali.
// ═══════════════════════════════════════════════════════════════════════════
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/purchase_orders_function.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
// Dipakai untuk format/parsing mata uang (kolom Actual Price) dan untuk
// unggah dokumen lewat pf_handleDocumentUpload(), sama dengan purchase_add.php
require_once __DIR__ . '/../includes/purchases_function.php';

$currencyCode   = pf_getCurrencyCode();
$currencySymbol = pf_getCurrencySymbol();
$isIDR          = ($currencyCode === 'IDR');

$page_title = $LANG['gr_add'];
include '../includes/header.php';

requireGoodsReceiptAccess();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('goods_receipt');

if (!canCreateGoodsReceipt()) {
    redirect(url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'));
}
if (!gr_tablesExist($conn)) {
    bltx_flash_error($LANG['gr_tables_missing']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'));
}

$csrf_token = gr_csrfToken();

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = gr_create($conn, $_POST);
    if ($res['success']) {
        bltx_flash_success($LANG['save_success'] . ': ' . $res['gr_number']);
        redirect(url('purchases/goods_receipt_view.php?id=' . (int)$res['gr_id']));
    }
    $key = (string)$res['message_key'];
    bltx_flash_error(isset($LANG[$key]) ? $LANG[$key] : $LANG['error']);
    $back = (int)($_POST['po_id'] ?? 0);
    redirect(url('purchases/goods_receipt_add.php' . ($back > 0 ? '?po_id=' . $back : '')));
}

// ── Mode: dengan PO / tanpa PO / belum memilih ────────────────────────────
$po_id   = (int)($_GET['po_id'] ?? 0);
$no_po   = isset($_GET['no_po']) && $_GET['no_po'] === '1';
$po_ref  = null;

if ($po_id > 0) {
    $po_ref = gr_getPoReference($conn, $po_id, 0);
    if (!$po_ref) {
        bltx_flash_error($LANG['po_not_found']);
        redirect(url('purchases/goods_receipt_add.php'));
    }
    if ($po_ref['status'] === 'cancelled') {
        bltx_flash_error($LANG['gr_po_cancelled']);
        redirect(url('purchases/po_view.php?id=' . $po_id));
    }
}

// PO yang masih bisa diterima (untuk selector). Query selektif + LIMIT.
$eligible_pos = [];
if ($po_id <= 0 && !$no_po) {
    $q = $conn->query(
        "SELECT po.id, po.po_number, po.order_date, po.status, s.name AS supplier_name
           FROM purchase_orders po
           LEFT JOIN suppliers s ON s.id = po.supplier_id
          WHERE po.status IN ('sent','approved','partial')
          ORDER BY po.order_date DESC, po.id DESC
          LIMIT 200"
    );
    if ($q) { while ($r = $q->fetch_assoc()) $eligible_pos[] = $r; }
}

// Dropdown data
$units_res      = $conn->query("SELECT id, name, symbol FROM units WHERE is_active = 1 ORDER BY name ASC");
$categories_res = $conn->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC");
$suppliers_res  = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
$inventoryItems = po_getInventoryForPicker($conn);

$units_arr = [];      if ($units_res)      { while ($u = $units_res->fetch_assoc())      $units_arr[] = $u; }
$cats_arr  = [];      if ($categories_res) { while ($c = $categories_res->fetch_assoc()) $cats_arr[]  = $c; }
$sups_arr  = [];      if ($suppliers_res)  { while ($s = $suppliers_res->fetch_assoc())  $sups_arr[]  = $s; }

$preview_number = gr_generateNumber($conn);

// ── Payment Information (data dari database) ───────────────────────────────
$payment_methods_res = $conn->query("SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY name ASC");
$staff_res           = $conn->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");
$shifts_res          = $conn->query("SELECT id, name FROM employee_shifts WHERE is_active = 1 ORDER BY name ASC");
$pm_arr    = []; if ($payment_methods_res) { while ($r = $payment_methods_res->fetch_assoc()) $pm_arr[]    = $r; }
$staff_arr = []; if ($staff_res)           { while ($r = $staff_res->fetch_assoc())           $staff_arr[] = $r; }
$shift_arr = []; if ($shifts_res)          { while ($r = $shifts_res->fetch_assoc())          $shift_arr[] = $r; }
$gr_pay_ready = gr_hasPaymentColumns($conn);
// Pemilih opsi pembayaran hanya ditampilkan bila migrasinya sudah dijalankan.
// Selama belum, halaman ini berperilaku persis seperti alur lama (bayar
// langsung, keempat field wajib) tanpa error apa pun.
$gr_payopt_ready = gr_hasPaymentStatusColumn($conn);

// Dropdown Purchase Staff HANYA untuk Goods Receipt tanpa PO.
// Untuk GR dengan PO, nama purchase staff diambil dari kontak Shipping
// Address milik PO, jadi tidak ada yang perlu dipilih di sini.
// Sumber data memakai pf_fetchUsers() yang SUDAH dipakai purchase_add.php
// dan purchase_edit.php — tidak ada query baru.
$gr_staff_ready = $no_po && gr_hasPurchaseStaffColumn($conn);
$gr_staff_arr   = [];
if ($gr_staff_ready && function_exists('pf_fetchUsers')) {
    $__res = pf_fetchUsers($conn);
    if ($__res) { while ($__r = $__res->fetch_assoc()) $gr_staff_arr[] = $__r; }
}

// ── Data untuk JS ──────────────────────────────────────────────────────────
$__cats  = []; foreach ($cats_arr  as $c) $__cats[]  = ['id' => (int)$c['id'], 'name' => $c['name']];
$__units = []; foreach ($units_arr as $u) $__units[] = ['id' => (int)$u['id'], 'name' => $u['name'], 'symbol' => $u['symbol']];
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
$itemsJson = json_encode((function () use ($po_ref) {
    $rows = [];
    if ($po_ref) {
        foreach ($po_ref['items'] as $it) {
            // Baris yang sisanya SUDAH ditutup oleh Goods Receipt lain: sisa
            // qty-nya sudah 0 dari gr_getPoReference(), dan ditandai terkunci
            // supaya form menampilkannya sebagai baris mati, bukan sebagai
            // barang yang masih bisa diterima.
            $lineLocked = !empty($it['line_closed']);
            $rows[] = [
                'po_item_id'        => (int)$it['id'],
                'inventory_id'      => (int)($it['inventory_id'] ?? 0),
                'item_name'         => $it['item_name'],
                'category_id'       => (int)($it['category_id'] ?? 0),
                'unit_id'           => (int)($it['unit_id'] ?? 0),
                'ordered_quantity'  => (float)$it['quantity'],
                'remaining'         => (float)$it['remaining'],
                'received_quantity' => (float)$it['remaining'],
                'rejected_quantity' => 0,
                'line_locked'       => $lineLocked ? 1 : 0,
                'po_unit_price'     => (float)$it['unit_price'],
                'unit_price'        => (float)$it['unit_price'],
                'discount'          => 0,
                'price_rounding'    => 0,
                'notes'             => '',
            ];
        }
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
    'ordered'           => $LANG['gr_ordered_qty'],
    'remaining'         => $LANG['gr_remaining'],
    'received'          => $LANG['gr_received_qty'],
    'rejected'          => $LANG['gr_rejected_qty'],
    'accepted'          => $LANG['gr_accepted_qty'],
    'po_price'          => $LANG['gr_po_price'],
    'actual_price'      => $LANG['gr_actual_price'],
    'total_price'       => $LANG['total_price'],
    'notes'             => $LANG['notes'],
    'extra_item'        => $LANG['gr_extra_item'],
    'at_least_one_item' => $LANG['gr_items_required'],
    'payment_required'  => $LANG['gr_payment_info_required'],
    'pay_now_hint'      => $LANG['gr_pay_now_hint'],
    'pay_deferred_hint' => $LANG['gr_pay_deferred_hint'],
    'close_line'                => $LANG['gr_close_line'],
    'close_line_reason'         => $LANG['gr_close_line_reason'],
    'close_line_reason_required'=> $LANG['gr_close_line_reason_required'],
    'close_line_locked'         => $LANG['gr_close_line_locked'],
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
    /* position:fixed dipakai (bukan absolute) karena koordinat panel dihitung
       di JS dari getBoundingClientRect() yang berbasis VIEWPORT. Dengan
       position:absolute, koordinat itu diterapkan relatif terhadap .ss-wrap
       sehingga panel terlempar jauh ke luar layar. fixed juga membuat panel
       tidak terpotong oleh baris item yang memakai overflow-hidden. */
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
   dropdown bawaan browser dan dengan po_add.php / po_edit.php. Warna teks
   dibiarkan mengikuti tema sehingga tetap terbaca pada mode gelap maupun terang. */
.ss-opt:hover        { background: var(--bs-secondary-bg, #e9ecef); color: inherit; }
.ss-opt.active       { background: var(--bs-tertiary-bg, #f8f9fa); color: inherit; }
.ss-opt.active:hover { background: var(--bs-secondary-bg, #e9ecef); }
.ss-empty { padding: .5rem .75rem; font-size: .85rem; color: var(--bs-secondary-color, #6c757d); }
#documentPreview img {
    max-width: 150px; max-height: 150px; object-fit: contain;
    display: block; margin-top: 6px; border-radius: 6px;
    border: 1px solid var(--bs-border-color, #dee2e6);
}

/* ══════════════════════════════════════════════════════════════════════
   RESPONSIVE — TABLET & MOBILE
   Blok ini hanya berisi @media query TAMBAHAN. Aturan desktop di atas
   TIDAK diubah sama sekali, sehingga tampilan pada layar besar persis
   seperti sebelumnya.

   Selector sengaja dibuat 2 tingkat (misal .item-row .col-item-field)
   agar bisa mengalahkan utility class Bootstrap 5 yang sudah memakai
   !important seperti .flex-nowrap dan .overflow-x-auto.
   ══════════════════════════════════════════════════════════════════════ */

/* ── Tablet & mobile (< 768px) ───────────────────────────────────────── */
@media (max-width: 767.98px) {
    /* Baris item dibungkus menjadi grid 2 kolom, bukan digulir horizontal
       panjang. Kolom Goods Receipt ada 11 buah, terlalu jauh untuk digulir
       di layar kecil. */
    .item-row .custom-scrollbar {
        flex-wrap: wrap !important;
        overflow-x: visible !important;
        padding-bottom: 0 !important;
    }
    .item-row .col-item-name   { flex: 1 1 100% !important; min-width: 0 !important; }
    .item-row .col-item-field  { flex: 1 1 calc(50% - .25rem) !important; min-width: 0 !important; }
    .item-row .col-item-action {
        flex: 1 1 100% !important; min-width: 0 !important;
        justify-content: flex-end; padding-bottom: 0 !important;
    }

    /* Padding & badge dirapatkan agar ruang layar tidak habis */
    .item-row { padding: .75rem !important; }
    .item-number-badge { width: 26px; height: 26px; font-size: .78rem; }
    .item-row > .d-flex { gap: .5rem !important; }
    .item-row .form-label-sm { font-size: .78rem; margin-bottom: .15rem; }

    /* Panel total: input mata uang dipersempit agar label tetap muat */
    .totals-panel { padding: .85rem !important; }
    .totals-panel .total-row { gap: .5rem; }
    .totals-panel .total-label { font-size: .85rem; }
    .totals-panel .total-row .input-group { max-width: 180px !important; }
    .totals-panel .total-value.grand { font-size: 1.05rem; }

    /* Dropdown pencarian tidak boleh melebihi lebar layar */
    .ss-panel { max-width: calc(100vw - 1.5rem); }
}

/* ── Mobile kecil (< 576px) ──────────────────────────────────────────── */
@media (max-width: 575.98px) {
    /* Satu field per baris supaya angka & dropdown tidak terpotong */
    .item-row .col-item-field { flex: 1 1 100% !important; }

    .totals-panel .total-row { flex-wrap: wrap; }
    .totals-panel .total-row .input-group { max-width: 150px !important; }
    .totals-panel .total-label { font-size: .8rem; }

    /* Tombol aksi pada baris item dibuat lebih mudah disentuh */
    .item-row .col-item-action .btn { min-width: 44px; min-height: 38px; }

    #documentPreview img { max-width: 100%; }
}

/* ── Layar pendek: daftar dropdown menyesuaikan tinggi viewport ───────── */
@media (max-height: 520px) {
    .ss-list { max-height: 40vh; }
}

/* ── Perangkat sentuh: target sentuh minimal nyaman ───────────────────── */
@media (hover: none) and (pointer: coarse) {
    .ss-opt { padding: .55rem .75rem; }
    .ss-panel .ss-search { padding: .5rem .75rem; }
}

/* ══════════════════════════════════════════════════════════════════════
   TAKE PHOTO — kamera & pratinjau hasil
   Panggung kamera memakai rasio 16:9 (horizontal) dan menyesuaikan diri
   ke lebar layar apa pun, sehingga siap untuk perangkat baru di masa depan.
   ══════════════════════════════════════════════════════════════════════ */
.gr-cam-stage {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;              /* orientasi HORIZONTAL */
    background: #000;
    border-radius: .5rem;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.gr-cam-stage video,
.gr-cam-stage canvas {
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
    display: block;
}
/* Cadangan untuk peramban lama yang belum mendukung aspect-ratio */
@supports not (aspect-ratio: 16 / 9) {
    .gr-cam-stage { height: 0; padding-bottom: 56.25%; }
    .gr-cam-stage video,
    .gr-cam-stage canvas { position: absolute; inset: 0; margin: auto; }
}
#grCamBtnsResult:not(.d-none) { display: flex !important; }

@media (max-width: 575.98px) {
    #grCameraModal .modal-footer .btn { flex: 1 1 auto; min-height: 42px; }
    #grCamBtnsResult .btn { flex: 1 1 calc(50% - .5rem); }
}
</style>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-box-seam"></i> <?= htmlspecialchars($page_title) ?></h2>
    <div class="page-actions">
        <a href="<?= $po_id > 0 ? url('purchases/po_view.php?id=' . $po_id) : url('purchases/pr_dahsboard_gate.php?tab=goods_receipt') ?>"
           class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?>
        </a>
    </div>
</div>

<?php
/* RONDE B — pembaca flash ikut dipindah ke helper terpusat.
   Setter-nya kini menulis ke kunci bersama 'success'/'error', jadi blok
   yang membaca kunci lama TIDAK AKAN PERNAH menyala lagi — pesannya
   hilang diam-diam. Saluran sukses dimatikan ('') — halaman ini
   hanya pernah menampilkan pesan gagal. */
bltx_render_flash('gr', ['success' => '']);
?>

<?php if ($po_id <= 0 && !$no_po): ?>
<!-- ══ STEP 1 : pilih Purchase Order ══════════════════════════════════════ -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-list-check"></i> <?= htmlspecialchars($LANG['gr_select_po']) ?></div>
        <div class="card-tools">
            <a href="<?= url('purchases/goods_receipt_add.php?no_po=1') ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-file-earmark-plus"></i> <?= htmlspecialchars($LANG['gr_without_po']) ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$eligible_pos): ?>
            <p class="text-muted mb-0"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['gr_no_eligible_po']) ?></p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0 datatable">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($LANG['po_number']) ?></th>
                        <th><?= htmlspecialchars($LANG['order_date']) ?></th>
                        <th><?= htmlspecialchars($LANG['supplier']) ?></th>
                        <th><?= htmlspecialchars($LANG['status']) ?></th>
                        <th><?= htmlspecialchars($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($eligible_pos as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['po_number']) ?></strong></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($p['order_date']))) ?></td>
                        <td><?= htmlspecialchars($p['supplier_name'] ?: '—') ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($p['status']) ?></span></td>
                        <td>
                            <a href="<?= url('purchases/goods_receipt_add.php?po_id=' . (int)$p['id']) ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-box-seam"></i> <?= htmlspecialchars($LANG['gr_receive']) ?>
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

<?php else: ?>
<!-- ══ STEP 2 : form Goods Receipt ════════════════════════════════════════ -->
<form method="POST" action="<?= url('purchases/goods_receipt_add.php' . ($po_id > 0 ? '?po_id=' . $po_id : '?no_po=1')) ?>" id="grForm" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="po_id" value="<?= (int)$po_id ?>">

    <div class="row g-3">

        <!-- Header -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['gr_header']) ?></div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['gr_number']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($preview_number) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['gr_receipt_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="receipt_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>

                        <?php if ($po_ref): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['po_number']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($po_ref['po_number']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['supplier']) ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($po_ref['supplier_name'] ?: '—') ?>" disabled>
                        </div>
                        <?php else: ?>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['supplier']) ?></label>
                            <select name="supplier_id" class="form-select">
                                <option value="">— <?= htmlspecialchars($LANG['select']) ?> —</option>
                                <?php foreach ($sups_arr as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['gr_delivery_note']) ?></label>
                            <input type="text" name="delivery_note_number" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['status']) ?></label>
                            <select name="status" class="form-select">
                                <option value="draft"><?= htmlspecialchars($LANG['gr_status_draft']) ?></option>
                                <option value="received" selected><?= htmlspecialchars($LANG['gr_status_received']) ?></option>
                            </select>
                            <div class="form-text small"><?= htmlspecialchars($LANG['gr_status_hint']) ?></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['gr_variance_reason']) ?></label>
                            <textarea name="variance_reason" rows="2" class="form-control"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['notes']) ?></label>
                            <textarea name="notes" rows="3" class="form-control"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- ── Payment Information (menggantikan card Totals) ── -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-credit-card"></i> <?= htmlspecialchars($LANG['gr_payment_info']) ?></div>
                </div>
                <div class="card-body">
                    <?php if (!$gr_pay_ready): ?>
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-database-exclamation"></i> <?= htmlspecialchars($LANG['gr_tables_missing']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($gr_payopt_ready): ?>
                    <!-- ══ OPSI PEMBAYARAN ══════════════════════════════════
                         Menentukan payment_status baris GR ini.
                           Pay Now  → keempat field di bawah wajib, invoice
                                      pembelian bisa langsung dibuat.
                           Deferred → keempat field disembunyikan & tidak
                                      divalidasi. BARANG TETAP DITERIMA dengan
                                      alur yang sama persis; pembayarannya
                                      dicatat kemudian lewat tombol Record
                                      Payment di halaman view.
                         Upload invoice supplier di bawah TETAP WAJIB pada
                         kedua opsi — invoice datang bersama barangnya.
                         ══════════════════════════════════════════════════ -->
                    <div class="mb-3">
                        <label class="form-label d-block"><i class="bi bi-wallet2 me-1"></i><?= htmlspecialchars($LANG['gr_payment_option']) ?> <span class="text-danger">*</span></label>
                        <div class="btn btn-sm-group w-100" role="group" aria-label="<?= htmlspecialchars($LANG['gr_payment_option']) ?>">
                            <input type="radio" class="btn-check" name="payment_option" id="payOptNow" value="now" checked>
                            <label class="btn btn-sm btn-success" for="payOptNow">
                                <i class="bi bi-cash-coin"></i> <?= htmlspecialchars($LANG['gr_pay_now']) ?>
                            </label>
                            <input type="radio" class="btn-check" name="payment_option" id="payOptDeferred" value="deferred">
                            <label class="btn btn-sm btn-warning" for="payOptDeferred">
                                <i class="bi bi-clock-history"></i> <?= htmlspecialchars($LANG['gr_pay_deferred']) ?>
                            </label>
                        </div>
                        <div class="form-text small" id="payOptHint"><?= htmlspecialchars($LANG['gr_pay_now_hint']) ?></div>
                    </div>
                    <?php endif; ?>

                    <div id="grPaymentFields">
                    <?php if ($gr_staff_ready): ?>
                    <!-- ══ PURCHASE STAFF (hanya Goods Receipt TANPA PO) ═════
                         Berada DI DALAM #grPaymentFields, jadi ikut tersembunyi
                         saat opsi Pembayaran Tertunda dipilih — sesuai
                         permintaan: dropdown ini hanya berlaku saat Pay Now.
                         Nama yang dipilih disimpan sebagai TEKS di
                         goods_receipts.purchase_staff_name, sehingga tetap
                         utuh walaupun akun user-nya dihapus kemudian.
                         ══════════════════════════════════════════════════ -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($LANG['purchase_staff']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select ss-select" name="purchase_staff_id" id="grPurchaseStaff">
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($gr_staff_arr as $ps): ?>
                            <option value="<?= (int)$ps['id'] ?>"><?= htmlspecialchars($ps['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-credit-card me-1"></i><?= htmlspecialchars($LANG['payment_method']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select ss-select" name="payment_method">
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($pm_arr as $pm): ?>
                            <option value="<?= (int)$pm['id'] ?>"><?= htmlspecialchars($pm['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-person me-1"></i><?= htmlspecialchars($LANG['payment_by']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select ss-select" name="payment_by">
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($staff_arr as $st): ?>
                            <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($LANG['payer_name']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select ss-select" name="payer_name">
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($staff_arr as $st): ?>
                            <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($LANG['payer_shift']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select ss-select" name="payer_shift">
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($shift_arr as $sh): ?>
                            <option value="<?= (int)$sh['id'] ?>"><?= htmlspecialchars($sh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    </div><!-- /#grPaymentFields -->

                    <div class="mb-0">
                        <label class="form-label"><i class="bi bi-camera me-1"></i><?= htmlspecialchars($LANG['document']) ?> <span class="text-danger">*</span> <span class="text-muted">(Max 5MB)</span></label>

                        <!-- DUA METODE PENGAMBILAN GAMBAR
                             1. Browse  : memilih berkas yang sudah ada di perangkat
                             2. Take Photo : mengambil gambar langsung lewat kamera
                                             (modal #grCameraModal di bawah halaman)
                             Keduanya berakhir di input file yang sama, sehingga
                             pemrosesan di server TIDAK berubah sama sekali. -->
                        <div class="d-flex flex-wrap gap-2 align-items-stretch">
                            <div class="flex-grow-1" style="min-width:170px;">
                                <input type="file" class="form-control" name="document" id="documentInput"
                                       accept="image/*" required>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary flex-shrink-0" id="btnTakePhoto">
                                <i class="bi bi-camera-fill"></i> <?= htmlspecialchars($LANG['take_photo']) ?>
                            </button>
                        </div>

                        <!-- Dicatat ke activity log: gambar berasal dari browse atau kamera -->
                        <input type="hidden" name="document_source" id="documentSource" value="browse">

                        <div id="documentPreview" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>


        <!-- ── Items (tampilan disamakan dengan card "Item List" di po_add.php) ── -->
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
                                           id="overallDiscountInput" value="" inputmode="numeric" placeholder="0" autocomplete="off">
                                </div>
                            </span>
                        </div>
                        <div class="total-row">
                            <span class="total-label"><i class="bi bi-calculator me-1"></i><?= htmlspecialchars($LANG['price_rounding'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span>
                                <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                                    <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                    <input type="text" class="form-control overall-currency-display" name="overall_price_rounding"
                                           id="overallPriceRoundingInput" value="" inputmode="numeric" placeholder="0" autocomplete="off">
                                </div>
                            </span>
                        </div>
                        <div class="total-row">
                            <span class="total-label"><i class="bi bi-receipt me-1"></i><?= htmlspecialchars($LANG['po_doc_ppn_label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span>
                                <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                                    <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                    <input type="text" class="form-control overall-currency-display" name="tax"
                                           id="taxInput" value="" inputmode="numeric" placeholder="0" autocomplete="off">
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
            <a href="<?= $po_id > 0 ? url('purchases/po_view.php?id=' . $po_id) : url('purchases/pr_dahsboard_gate.php?tab=goods_receipt') ?>"
               class="btn btn-sm btn-secondary">
                <i class="bi bi-x-lg"></i> <?= htmlspecialchars($LANG['cancel']) ?>
            </a>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-save"></i> <?= htmlspecialchars($LANG['save']) ?>
            </button>
        </div>
    </div>
</form>

<!-- Template baris item TAMBAHAN (tidak ada di PO) -->

<script>
(function () {
"use strict";

var CURRENCY_SYMBOL = <?= json_encode($currencySymbol) ?>;
var IS_IDR          = <?= $isIDR ? 'true' : 'false' ?>;
var CATEGORIES      = <?= $catsJson ?>;
var UNITS           = <?= $unitsJson ?>;
var INVENTORY       = <?= $invJson ?>;
var EXISTING_ITEMS  = <?= $itemsJson ?>;
var LANG            = <?= $langJson ?>;

/* ── Number helpers (sama dengan po_add.php) ─────────────── */
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
function fmtQty(n) {
    n = parseFloat(n) || 0;
    return (Math.round(n * 100) / 100).toString();
}

/* ── Totals ──────────────────────────────────────────────── */
function calcItemTotal(row) {
    var recv = parseFloat(row.querySelector(".item-recv").value) || 0;
    var rej  = parseFloat(row.querySelector(".item-rej").value)  || 0;
    if (rej > recv) { rej = recv; row.querySelector(".item-rej").value = rej; }
    var acc   = recv - rej;
    var price = parseAmount(row.querySelector('[name="unit_price[]"]').value);
    var disc  = parseAmount(row.querySelector('[name="discount[]"]').value);
    var rnd   = parseAmount(row.querySelector('[name="price_rounding[]"]').value);
    var total = (acc * price) - disc + rnd;

    row.querySelector(".item-accepted").textContent = fmtQty(acc);
    // Peringatan visual bila melebihi sisa PO (validasi tetap di server)
    var remEl = row.querySelector(".item-remaining");
    if (remEl && remEl.dataset.remaining !== undefined) {
        var rem = parseFloat(remEl.dataset.remaining) || 0;
        row.querySelector(".item-recv").classList.toggle("is-invalid", acc > rem + 0.00001);
    }
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

/* ── Option builders ─────────────────────────────────────── */
function buildCategoryOptions(sel) {
    var html = '<option value="">' + LANG.select + '</option>';
    CATEGORIES.forEach(function (c) {
        html += '<option value="' + c.id + '"' + (c.id == sel ? " selected" : "") + '>' + esc(c.name) + '</option>';
    });
    return html;
}
function buildUnitOptions(sel) {
    var html = '<option value="">' + LANG.select + '</option>';
    UNITS.forEach(function (u) {
        html += '<option value="' + u.id + '"' + (u.id == sel ? " selected" : "") + '>' + esc(u.name) + ' (' + esc(u.symbol) + ')</option>';
    });
    return html;
}
/* Picker inventory — HANYA nama barang, tanpa kode barang */
function buildInventoryOptions(sel) {
    var html = '<option value="">' + LANG.select_item + '</option>';
    INVENTORY.forEach(function (i) {
        html += '<option value="' + i.id + '"'
             +  ' data-name="' + esc(i.name) + '" data-price="' + i.price + '"'
             +  ' data-unitid="' + i.unit_id + '" data-category="' + i.category_id + '"'
             +  (i.id == sel ? " selected" : "") + '>' + esc(i.name) + '</option>';
    });
    return html;
}

/* ── Satu baris item ─────────────────────────────────────── */
function createItemRow(data) {
    data = data || {};
    var isPo   = !!(data.po_item_id);
    var div    = document.createElement("div");
    div.className = "item-row rounded p-3 mb-3";
    div.innerHTML =
      '<div class="d-flex align-items-start gap-3">' +
        '<div class="item-number-badge mt-1 flex-shrink-0"><span class="item-number"></span></div>' +
        '<div class="flex-fill overflow-hidden"><div class="d-flex flex-nowrap align-items-end gap-2 w-100 overflow-x-auto pb-2 custom-scrollbar">' +
          '<div class="col-item-name">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-box me-1"></i>' + LANG.item_name +
              (isPo ? '' : ' <span class="badge text-bg-warning ms-1">' + LANG.extra_item + '</span>') +
              ' <span class="text-danger">*</span></label>' +
            (isPo ? '' : '<select class="form-select form-select-sm gr-item-picker mb-1 ss-select">' + buildInventoryOptions(data.inventory_id || 0) + '</select>') +
            '<input type="text" class="form-control form-control-sm gr-item-name" name="item_name[]" value="' + esc(data.item_name || "") + '" placeholder="' + LANG.manual_entry + '" required>' +
            '<input type="hidden" name="po_item_id[]"   value="' + (data.po_item_id || 0) + '">' +
            '<input type="hidden" name="inventory_id[]" class="gr-item-invid" value="' + (data.inventory_id || 0) + '">' +
            '<input type="hidden" name="ordered_quantity[]" value="' + (data.ordered_quantity || 0) + '">' +
            '<input type="hidden" name="po_unit_price[]"    value="' + (data.po_unit_price || 0) + '">' +
            '<input type="hidden" name="price_rounding[]"   value="' + (data.price_rounding || 0) + '">' +
            '<input type="hidden" name="discount[]"         value="' + (data.discount || 0) + '">' +
            '<input type="text" name="item_notes[]" class="form-control form-control-sm mt-1 item-notes" maxlength="255" placeholder="' + LANG.notes + '" value="' + esc(data.notes || "") + '">' +
            /* Penanda "sisa barang tidak tersedia".
               Checkbox yang tidak dicentang TIDAK ikut terkirim oleh browser,
               sehingga indeks baris bisa bergeser dan penanda menempel ke item
               yang salah. Karena itu setiap baris punya hidden pendamping
               bernilai 0 dengan indeks EKSPLISIT, dan checkbox-nya memakai
               indeks yang sama sehingga menimpanya saat dicentang.
               Hanya baris yang berasal dari PO yang punya sisa untuk ditutup. */
            (isPo ?
              (data.line_locked ?
                /* Baris sudah ditutup oleh Goods Receipt LAIN: dikunci penuh.
                   Tidak ada input close_line yang dikirim dari baris ini —
                   penandanya milik GR yang memasangnya, bukan GR ini. Untuk
                   membukanya kembali, batalkan penutupan lewat Edit pada GR
                   tersebut. */
                '<div class="mt-1"><span class="badge text-bg-secondary">' +
                  '<i class="bi bi-lock me-1"></i>' + LANG.close_line_locked +
                '</span></div>'
              :
                '<input type="hidden" class="item-close-zero" value="0">' +
                '<div class="form-check mt-1 item-close-wrap">' +
                  '<input class="form-check-input item-close" type="checkbox" value="1"' + (data.close_line ? ' checked' : '') + '>' +
                  '<label class="form-check-label small text-warning-emphasis item-close-label">' +
                    '<i class="bi bi-slash-circle me-1"></i>' + LANG.close_line +
                    ' <span class="item-close-short fw-semibold"></span>' +
                  '</label>' +
                '</div>'
              )
            : '') +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-clipboard-check me-1"></i>' + LANG.ordered + '</label>' +
            '<div class="fw-semibold pt-1">' + (isPo ? fmtQty(data.ordered_quantity) : "&mdash;") + '</div>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-hourglass-split me-1"></i>' + LANG.remaining + '</label>' +
            '<div class="fw-semibold pt-1 item-remaining"' + (isPo ? ' data-remaining="' + (data.remaining || 0) + '"' : '') + '>' + (isPo ? fmtQty(data.remaining) : "&mdash;") + '</div>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-box-arrow-in-down me-1"></i>' + LANG.received + ' <span class="text-danger">*</span></label>' +
            '<input type="number" step="0.01" min="0" class="form-control form-control-sm item-recv" name="received_quantity[]" value="' + (data.received_quantity !== undefined ? data.received_quantity : (isPo ? (data.remaining || 0) : 1)) + '" required>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-x-octagon me-1"></i>' + LANG.rejected + '</label>' +
            '<input type="number" step="0.01" min="0" class="form-control form-control-sm item-rej" name="rejected_quantity[]" value="' + (data.rejected_quantity || 0) + '">' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-check2-square me-1"></i>' + LANG.accepted + '</label>' +
            '<div class="fw-semibold pt-1 item-accepted">0</div>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-rulers me-1"></i>' + LANG.unit + ' <span class="text-danger">*</span></label>' +
            '<select class="form-select form-select-sm" name="unit_id[]" required>' + buildUnitOptions(data.unit_id || 0) + '</select>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-tag me-1"></i>' + LANG.category + ' <span class="text-danger">*</span></label>' +
            '<select class="form-select form-select-sm" name="category_id[]" required>' + buildCategoryOptions(data.category_id || 0) + '</select>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-receipt me-1"></i>' + LANG.po_price + '</label>' +
            '<div class="fw-semibold pt-1">' + (isPo ? (CURRENCY_SYMBOL + " " + formatDisplay(data.po_unit_price || 0)) : "&mdash;") + '</div>' +
          '</div>' +
          '<div class="col-item-field">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-currency-dollar me-1"></i>' + LANG.actual_price + ' <span class="text-danger">*</span></label>' +
            '<div class="input-group input-group-sm input-currency">' +
              '<span class="input-group-text">' + CURRENCY_SYMBOL + '</span>' +
              '<input type="text" class="form-control item-currency-display" name="unit_price[]" value="' + (data.unit_price ? formatDisplay(data.unit_price) : "") + '" inputmode="numeric" placeholder="0" autocomplete="off">' +
            '</div>' +
          '</div>' +
          '<div class="col-item-field" style="padding-bottom: 2px;">' +
            '<label class="form-label form-label-sm text-nowrap"><i class="bi bi-cash-stack me-1"></i>' + LANG.total_price + '</label>' +
            '<div class="fw-bold text-success item-total fs-6">' + CURRENCY_SYMBOL + ' 0</div>' +
          '</div>' +
          '<div class="col-item-action d-flex gap-1 align-items-end" style="padding-bottom: 2px;">' +
            '<button type="button" class="btn btn-success btn-sm btn-add-item" title="' + LANG.add_item + '"><i class="bi bi-plus-circle"></i></button>' +
            (isPo ? '' : '<button type="button" class="btn btn-danger btn-sm btn-remove-item" title="' + LANG.remove + '"><i class="bi bi-trash"></i></button>') +
          '</div>' +
        '</div></div>' +
      '</div>';
    return div;
}

/* ── Penanda "sisa barang tidak tersedia" ──────────────────────────────
   Saat dicentang:
     - qty Received dipaksa 0 dan dikunci (menutup sisa TIDAK sama dengan
       menerima barang; kalau barangnya datang sebagian, isi qty-nya dulu
       lalu centang untuk menutup sisanya),
     - kolom Notes menjadi WAJIB, karena penutupan baris berarti menyatakan
       sisa barang tidak akan pernah datang dan itu harus bisa
       dipertanggungjawabkan.
   Validasi sesungguhnya tetap ada di server pada gr_normalizePostedItems(). */
function applyCloseLine(row) {
    var cb = row.querySelector(".item-close");
    if (!cb) return;
    var notes = row.querySelector(".item-notes");
    var recv  = row.querySelector(".item-recv");
    var rej   = row.querySelector(".item-rej");

    var shortEl = row.querySelector(".item-close-short");

    if (cb.checked) {
        /* Qty Received & Rejected SENGAJA TIDAK diubah dan TIDAK dikunci.
           Menutup baris berarti "sisa setelah yang ini TIDAK akan datang
           lagi" — bukan "tidak ada barang yang datang". Kasus paling umum
           justru penerimaan sebagian: PO 3 kg, datang 2 kg, sisa 1 kg tidak
           akan dikirim. Karena itu petugas harus tetap bebas mengisi berapa
           yang benar-benar diterima. Menutup baris dengan qty 0 juga tetap
           sah, untuk item yang batal dikirim sama sekali. */
        if (notes) {
            notes.required = true;
            notes.placeholder = LANG.close_line_reason;
            notes.classList.add("border-warning");
        }
        row.classList.add("row-closed");
    } else {
        if (notes) {
            notes.required = false;
            notes.placeholder = LANG.notes;
            notes.classList.remove("border-warning", "is-invalid");
        }
        row.classList.remove("row-closed");
    }

    /* Tampilkan berapa sisa yang ditutup, langsung di sebelah label, supaya
       besarnya terlihat SEBELUM disimpan. Angkanya = ordered - accepted,
       mengikuti perhitungan gr_normalizePostedItems() di server. */
    if (shortEl) {
        if (cb.checked) {
            var ordEl = row.querySelector('[name="ordered_quantity[]"]');
            var ord   = ordEl ? (parseFloat(ordEl.value) || 0) : 0;
            var rcv   = recv ? (parseFloat(recv.value) || 0) : 0;
            var rjc   = rej  ? (parseFloat(rej.value)  || 0) : 0;
            var shortQty = ord - (rcv - rjc);
            shortEl.textContent = shortQty > 0 ? "(" + fmtQty(shortQty) + ")" : "";
        } else {
            shortEl.textContent = "";
        }
    }
    // updateGrandTotal() memanggil calcItemTotal() untuk SEMUA baris,
    // jadi total baris ini ikut terhitung ulang tanpa panggilan terpisah.
    updateGrandTotal();
}

document.addEventListener("change", function (e) {
    if (e.target && e.target.classList && e.target.classList.contains("item-close")) {
        applyCloseLine(e.target.closest(".item-row"));
    }
});

/* Qty berubah -> angka sisa yang ditutup ikut menyesuaikan seketika. */
document.addEventListener("input", function (e) {
    if (!e.target || !e.target.classList) return;
    if (!e.target.classList.contains("item-recv") && !e.target.classList.contains("item-rej")) return;
    var row = e.target.closest(".item-row");
    if (row && row.querySelector(".item-close")) applyCloseLine(row);
});

/* Cegah submit bila ada baris tertutup yang alasannya masih kosong. */
(function () {
    var form = document.getElementById("grForm");
    if (!form) return;
    form.addEventListener("submit", function (e) {
        var bad = null;
        document.querySelectorAll(".item-row").forEach(function (row) {
            if (bad) return;
            var cb = row.querySelector(".item-close");
            var nt = row.querySelector(".item-notes");
            if (cb && cb.checked && nt && nt.value.trim() === "") bad = nt;
        });
        if (!bad) return;
        e.preventDefault();
        bad.classList.add("is-invalid");
        bad.focus();
        if (typeof Swal !== "undefined") {
            Swal.fire({ icon: "warning", title: "", text: LANG.close_line_reason_required, timer: 3500, showConfirmButton: false });
        }
    });
})();

function renumberItems() {
    document.querySelectorAll(".item-row .item-number").forEach(function (el, i) { el.textContent = i + 1; });

    /* Samakan indeks close_line[] dengan POSISI baris.
       gr_normalizePostedItems() membaca $post['close_line'][$i] dengan $i =
       indeks posisi pada item_name[], jadi indeks di sini harus ditulis ulang
       setiap kali ada baris ditambah atau dihapus. Kalau tidak, penanda bisa
       menempel ke item yang salah setelah baris tengah dihapus. */
    document.querySelectorAll(".item-row").forEach(function (row, i) {
        var zero = row.querySelector(".item-close-zero");
        var cb   = row.querySelector(".item-close");
        var lab  = row.querySelector(".item-close-label");
        if (!cb) return;                       // baris non-PO: tidak ada penanda
        if (zero) zero.name = "close_line[" + i + "]";
        cb.name = "close_line[" + i + "]";
        cb.id   = "closeLine" + i;
        if (lab) lab.setAttribute("for", "closeLine" + i);
    });
}

/* ── Dropdown dengan pencarian (di atas <select> asli) ────── */
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
        toggle.textContent = opt ? opt.textContent.trim() : "";
        toggle.classList.toggle("placeholder-text", !select.value);
    }
    function place() {
        var r = toggle.getBoundingClientRect();
        panel.style.width = r.width + "px";
        panel.style.left  = r.left + "px";
        panel.style.top   = (r.bottom + 2) + "px";
        var h = panel.offsetHeight || 0;
        if (h && (r.bottom + h + 8) > window.innerHeight && r.top > (h + 8)) {
            panel.style.top = (r.top - h - 2) + "px";
        }
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
    function open() {
        document.querySelectorAll(".ss-panel.show").forEach(function (p) { p.classList.remove("show"); });
        renderList(""); search.value = "";
        panel.classList.add("show"); place(); search.focus();
    }
    function close() { panel.classList.remove("show"); }

    window.addEventListener("scroll", function () { if (panel.classList.contains("show")) place(); }, true);
    window.addEventListener("resize", function () { if (panel.classList.contains("show")) place(); });
    toggle.addEventListener("click", function (e) { e.preventDefault(); panel.classList.contains("show") ? close() : open(); });
    search.addEventListener("input", function () { renderList(search.value); });
    list.addEventListener("click", function (e) {
        var el = e.target.closest(".ss-opt");
        if (!el) return;
        select.selectedIndex = parseInt(el.dataset.i, 10);
        select.dispatchEvent(new Event("change", { bubbles: true }));
        syncToggle(); close();
    });
    document.addEventListener("click", function (e) { if (!wrap.contains(e.target)) close(); });
    select.addEventListener("change", syncToggle);
    syncToggle();
}
function initSearchables(scope) { (scope || document).querySelectorAll("select.ss-select").forEach(makeSearchable); }

/* ── Add / Remove item ───────────────────────────────────── */
var container = document.getElementById("itemsContainer");
container.addEventListener("click", function (e) {
    var addBtn = e.target.closest(".btn-add-item");
    if (addBtn) {
        var newRow  = createItemRow();
        var current = addBtn.closest(".item-row");
        if (current && current.nextSibling) container.insertBefore(newRow, current.nextSibling);
        else                                container.appendChild(newRow);
        initSearchables(newRow);
        renumberItems(); updateGrandTotal();
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
    renumberItems(); updateGrandTotal();
});

document.addEventListener("input", function (e) {
    if (e.target.matches(".item-currency-display, .overall-currency-display")) {
        syncCurrencyInput(e.target); updateGrandTotal();
    }
    if (e.target.matches(".item-recv, .item-rej")) updateGrandTotal();
});

/* ── Picker inventory mengisi nama / harga / unit / kategori ── */
container.addEventListener("change", function (e) {
    if (!e.target.classList.contains("gr-item-picker")) return;
    var row = e.target.closest(".item-row");
    var opt = e.target.selectedOptions[0];
    var nm  = row.querySelector(".gr-item-name");
    var inv = row.querySelector(".gr-item-invid");
    if (!opt || !opt.value) { inv.value = "0"; return; }
    nm.value  = opt.dataset.name || "";
    inv.value = opt.value;
    var priceIn = row.querySelector('[name="unit_price[]"]');
    if (priceIn) priceIn.value = formatDisplay(opt.dataset.price || 0);
    var unitSel = row.querySelector('[name="unit_id[]"]');
    if (unitSel && opt.dataset.unitid && opt.dataset.unitid !== "0") unitSel.value = opt.dataset.unitid;
    var catSel = row.querySelector('[name="category_id[]"]');
    if (catSel && opt.dataset.category && opt.dataset.category !== "0") catSel.value = opt.dataset.category;
    updateGrandTotal();
});

/* ── Pratinjau dokumen ───────────────────────────────────── */
var docInput = document.getElementById("documentInput");
if (docInput) {
    docInput.addEventListener("change", function () {
        var preview = document.getElementById("documentPreview");
        preview.innerHTML = "";
        if (!this.files || !this.files[0]) return;
        if (this.files[0].size > 5 * 1024 * 1024) {
            preview.innerHTML = '<div class="alert alert-danger py-1 mt-1 mb-0 small">Max 5MB</div>';
            this.value = "";
            return;
        }
        var reader = new FileReader();
        reader.onload = function (ev) {
            preview.innerHTML = '<img src="' + ev.target.result + '" alt="">';
        };
        reader.readAsDataURL(this.files[0]);
    });
}

/* ── Opsi Pembayaran: tampil/sembunyikan field & atur validasinya ──────
   Pay Now  : keempat dropdown wajib (perilaku lama, tidak berubah).
   Deferred : keempat dropdown disembunyikan, nilainya dikosongkan, dan
              tidak divalidasi. Server pun tidak memintanya karena
              gr_collectPaymentInfo() dipanggil dengan $required = false.
   Upload invoice supplier TIDAK ikut disembunyikan dan tetap wajib pada
   kedua opsi. */
(function () {
    var wrap = document.getElementById("grPaymentFields");
    var optNow = document.getElementById("payOptNow");
    var optDef = document.getElementById("payOptDeferred");
    var hint = document.getElementById("payOptHint");
    if (!wrap || !optNow || !optDef) return;

    function apply() {
        var deferred = optDef.checked;
        wrap.style.display = deferred ? "none" : "";
        if (hint) hint.textContent = deferred ? LANG.pay_deferred_hint : LANG.pay_now_hint;
        if (deferred) {
            /* Kosongkan agar tidak ada data pembayaran setengah jadi yang
               ikut terkirim saat user sempat mengisi lalu berpindah opsi. */
            ["purchase_staff_id", "payment_method", "payment_by", "payer_name", "payer_shift"].forEach(function (n) {
                var sel = wrap.querySelector('[name="' + n + '"]');
                if (!sel) return;
                sel.value = "";
                if (typeof sel.dispatchEvent === "function") {
                    sel.dispatchEvent(new Event("change", { bubbles: true }));
                }
                var w = sel.closest(".ss-wrap");
                var b = w ? w.querySelector(".ss-toggle") : null;
                if (b) b.classList.remove("is-invalid");
            });
        }
    }
    optNow.addEventListener("change", apply);
    optDef.addEventListener("change", apply);
    apply();
})();

/* ── Validasi dropdown Payment Information ────────────────────
   <select> aslinya disembunyikan oleh komponen pencarian, dan browser
   TIDAK BISA memfokuskan field tersembunyi yang bertanda `required`
   (form akan menolak submit tanpa pesan apa pun). Karena itu atribut
   `required` dilepas dari select tersebut dan digantikan pengecekan
   manual di bawah ini. Validasi sesungguhnya tetap ada di server pada
   gr_collectPaymentInfo(). */
(function () {
    var form = document.getElementById("grForm");
    if (!form) return;
    form.addEventListener("submit", function (e) {
        /* Pembayaran tertunda: keempat field memang tidak diminta. */
        var optDef = document.getElementById("payOptDeferred");
        if (optDef && optDef.checked) return;

        var missing = null;
        /* purchase_staff_id ikut divalidasi HANYA bila dropdown-nya dirender
           (mode tanpa PO). Pada GR dengan PO, elemennya tidak ada sehingga
           querySelector mengembalikan null dan pengecekan terlewati sendiri. */
        ["purchase_staff_id", "payment_method", "payment_by", "payer_name", "payer_shift"].forEach(function (n) {
            if (missing) return;
            var sel = form.querySelector('[name="' + n + '"]');
            if (sel && !sel.value) missing = sel;
        });
        if (!missing) return;
        e.preventDefault();
        var wrap = missing.closest(".ss-wrap");
        var btn  = wrap ? wrap.querySelector(".ss-toggle") : null;
        if (btn) { btn.classList.add("is-invalid"); btn.focus(); }
        if (typeof Swal !== "undefined") {
            Swal.fire({ icon: "warning", title: "", text: LANG.payment_required, timer: 3000, showConfirmButton: false });
        }
    });
})();

/* ── Seed rows ───────────────────────────────────────────── */
if (EXISTING_ITEMS.length) {
    EXISTING_ITEMS.forEach(function (it) { container.appendChild(createItemRow(it)); });
} else {
    container.appendChild(createItemRow());
}
initSearchables(document);
renumberItems();
/* Terapkan status penanda pada baris yang di-seed (mis. saat form dikembalikan
   dengan error validasi), sehingga qty terkunci dan Notes menjadi wajib
   sejak halaman pertama kali tampil — bukan hanya setelah checkbox diklik. */
document.querySelectorAll(".item-row").forEach(applyCloseLine);
updateGrandTotal();
})();
</script>

<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════════════════
     MODAL TAKE PHOTO
     Mengambil gambar langsung dari kamera perangkat dengan orientasi
     HORIZONTAL (landscape), lengkap dengan rotate kiri / rotate kanan.
     Hasilnya disuntikkan ke input file #documentInput sehingga alur unggah
     di server tetap memakai pf_handleDocumentUpload() yang sudah ada.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="grCameraModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-camera-fill me-1"></i><?= htmlspecialchars($LANG['take_photo']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars($LANG['close']) ?>"></button>
            </div>
            <div class="modal-body p-2">
                <div id="grCamError" class="alert alert-danger d-none mb-2 py-2 small"></div>

                <div id="grCamStageLive" class="gr-cam-stage">
                    <video id="grCamVideo" playsinline autoplay muted></video>
                </div>

                <div id="grCamStageResult" class="gr-cam-stage d-none">
                    <canvas id="grCamCanvas"></canvas>
                </div>
            </div>
            <div class="modal-footer py-2 flex-wrap gap-2">
                <div id="grCamBtnsLive" class="d-flex flex-wrap gap-2 w-100 justify-content-end">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> <?= htmlspecialchars($LANG['cancel']) ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" id="grCamShoot">
                        <i class="bi bi-camera"></i> <?= htmlspecialchars($LANG['capture']) ?>
                    </button>
                </div>
                <div id="grCamBtnsResult" class="d-none flex-wrap gap-2 w-100 justify-content-end">
                    <button type="button" class="btn btn-sm btn-secondary" id="grCamRotL" title="<?= htmlspecialchars($LANG['rotate_left']) ?>">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" id="grCamRotR" title="<?= htmlspecialchars($LANG['rotate_right']) ?>">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-warning" id="grCamRetake">
                        <i class="bi bi-arrow-repeat"></i> <?= htmlspecialchars($LANG['retake']) ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-success" id="grCamUse">
                        <i class="bi bi-check2"></i> <?= htmlspecialchars($LANG['use_photo']) ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════════════════════════
   TAKE PHOTO
   Mengambil gambar dari kamera perangkat (orientasi horizontal), lalu
   memungkinkan rotate kiri / kanan sebelum dipakai.

   Hasil akhir DISUNTIKKAN ke input file #documentInput lewat DataTransfer,
   sehingga sisi server tetap memproses satu jalur yang sama
   (pf_handleDocumentUpload) — tidak ada endpoint atau kolom baru.

   Ditulis sebagai IIFE mandiri agar mudah dipindah/dipakai ulang di
   halaman lain pada pengembangan berikutnya.
   ══════════════════════════════════════════════════════════════════════ */
/* Seluruh modul dibungkus DOMContentLoaded karena bootstrap.bundle.min.js
   baru dimuat oleh footer.php SETELAH blok ini di-parse. Tanpa penundaan ini,
   window.bootstrap masih undefined sehingga instance Modal gagal dibuat dan
   modal kamera tidak bisa ditutup sama sekali. */
document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    var L = <?= json_encode([
        'camera_error' => $LANG['camera_error'],
        'file_too_large' => $LANG['file_too_large'],
    ], JSON_UNESCAPED_UNICODE) ?>;

    var btnOpen   = document.getElementById("btnTakePhoto");
    var modalEl   = document.getElementById("grCameraModal");
    var fileInput = document.getElementById("documentInput");
    var srcInput  = document.getElementById("documentSource");
    if (!btnOpen || !modalEl || !fileInput) return;

    var video      = document.getElementById("grCamVideo");
    var canvas     = document.getElementById("grCamCanvas");
    var errBox     = document.getElementById("grCamError");
    var stageLive  = document.getElementById("grCamStageLive");
    var stageRes   = document.getElementById("grCamStageResult");
    var btnsLive   = document.getElementById("grCamBtnsLive");
    var btnsResult = document.getElementById("grCamBtnsResult");

    var stream = null;
    var shot   = null;   // canvas sumber (hasil jepretan asli)
    var rot    = 0;      // 0 / 90 / 180 / 270

    /* ── Kontrol buka/tutup modal ─────────────────────────────────────
       Instance Bootstrap diambil SECARA MALAS (lazy) lewat
       getOrCreateInstance() agar selalu memakai instance yang sama dan tidak
       bergantung pada urutan pemuatan skrip.
       Bila Bootstrap benar-benar tidak tersedia, dipakai mode manual penuh
       (backdrop + kelas sendiri) sehingga modal TETAP BISA DITUTUP. */
    function getModal() {
        if (window.bootstrap && bootstrap.Modal) {
            return bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        return null;
    }
    var manualBackdrop = null;

    function manualShow() {
        modalEl.classList.add("show", "d-block");
        modalEl.setAttribute("aria-modal", "true");
        modalEl.removeAttribute("aria-hidden");
        manualBackdrop = document.createElement("div");
        manualBackdrop.className = "modal-backdrop fade show";
        document.body.appendChild(manualBackdrop);
        document.body.classList.add("modal-open");
    }
    function manualHide() {
        modalEl.classList.remove("show", "d-block");
        modalEl.setAttribute("aria-hidden", "true");
        modalEl.removeAttribute("aria-modal");
        if (manualBackdrop && manualBackdrop.parentNode) {
            manualBackdrop.parentNode.removeChild(manualBackdrop);
        }
        manualBackdrop = null;
        document.body.classList.remove("modal-open");
    }
    function openModal() {
        var m = getModal();
        if (m) { m.show(); } else { manualShow(); }
    }
    function closeModal() {
        var m = getModal();
        if (m) { m.hide(); } else { manualHide(); }
        stopCamera();   // kamera SELALU dimatikan, lewat jalur mana pun
    }

    function showError(msg) {
        errBox.textContent = msg;
        errBox.classList.remove("d-none");
    }
    function clearError() {
        errBox.textContent = "";
        errBox.classList.add("d-none");
    }
    function toLive() {
        stageLive.classList.remove("d-none");
        stageRes.classList.add("d-none");
        btnsLive.classList.remove("d-none");
        btnsResult.classList.add("d-none");
    }
    function toResult() {
        stageLive.classList.add("d-none");
        stageRes.classList.remove("d-none");
        btnsLive.classList.add("d-none");
        btnsResult.classList.remove("d-none");
    }

    function startCamera() {
        clearError();
        toLive();
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showError(L.camera_error);
            return;
        }
        navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: "environment" },   // kamera belakang bila ada
                width:  { ideal: 1920 },                // minta resolusi landscape
                height: { ideal: 1080 }
            },
            audio: false
        }).then(function (s) {
            stream = s;
            video.srcObject = s;
        }).catch(function () {
            showError(L.camera_error);
        });
    }

    function stopCamera() {
        if (!stream) return;
        stream.getTracks().forEach(function (t) { t.stop(); });
        stream = null;
        video.srcObject = null;
    }

    /* Gambar ulang canvas tampilan sesuai sudut rotasi saat ini */
    function renderRotated() {
        if (!shot) return;
        var w = shot.width, h = shot.height;
        var swap = (rot === 90 || rot === 270);
        canvas.width  = swap ? h : w;
        canvas.height = swap ? w : h;

        var ctx = canvas.getContext("2d");
        ctx.save();
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate(rot * Math.PI / 180);
        ctx.drawImage(shot, -w / 2, -h / 2);
        ctx.restore();
    }

    btnOpen.addEventListener("click", function () {
        shot = null;
        rot  = 0;
        openModal();
        startCamera();
    });

    // Bootstrap tetap memicu event ini bila tersedia; kamera dimatikan ganda
    // (di sini dan di closeModal) agar tidak pernah tertinggal menyala.
    modalEl.addEventListener("shown.bs.modal",  function () { if (!stream) startCamera(); });
    modalEl.addEventListener("hidden.bs.modal", stopCamera);

    document.getElementById("grCamShoot").addEventListener("click", function () {
        if (!video.videoWidth) { showError(L.camera_error); return; }
        shot = document.createElement("canvas");
        shot.width  = video.videoWidth;
        shot.height = video.videoHeight;
        shot.getContext("2d").drawImage(video, 0, 0, shot.width, shot.height);
        rot = 0;
        renderRotated();
        stopCamera();
        toResult();
    });

    document.getElementById("grCamRotL").addEventListener("click", function () {
        rot = (rot + 270) % 360;
        renderRotated();
    });
    document.getElementById("grCamRotR").addEventListener("click", function () {
        rot = (rot + 90) % 360;
        renderRotated();
    });
    document.getElementById("grCamRetake").addEventListener("click", function () {
        shot = null; rot = 0;
        startCamera();
    });

    document.getElementById("grCamUse").addEventListener("click", function () {
        if (!shot) return;
        if (typeof DataTransfer === "undefined") { showError(L.camera_error); return; }

        canvas.toBlob(function (blob) {
            if (!blob) { showError(L.camera_error); return; }
            if (blob.size > 5 * 1024 * 1024) { showError(L.file_too_large); return; }

            var stamp = new Date();
            var name  = "gr_photo_" + stamp.getFullYear()
                      + ("0" + (stamp.getMonth() + 1)).slice(-2)
                      + ("0" + stamp.getDate()).slice(-2) + "_"
                      + ("0" + stamp.getHours()).slice(-2)
                      + ("0" + stamp.getMinutes()).slice(-2)
                      + ("0" + stamp.getSeconds()).slice(-2) + ".jpg";

            var file = new File([blob], name, { type: "image/jpeg" });
            var dt   = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;

            if (srcInput) srcInput.value = "camera";
            fileInput.dispatchEvent(new Event("change", { bubbles: true }));

            closeModal();
        }, "image/jpeg", 0.9);
    });

    /* Memilih berkas lewat Browse mengembalikan sumber ke "browse" */
    fileInput.addEventListener("click", function () {
        if (srcInput) srcInput.value = "browse";
    });

    /* ── Tiga jalur penutupan modal ───────────────────────────────────
       Ketiganya dipasang manual dan TIDAK bergantung pada Bootstrap,
       sehingga modal selalu bisa ditutup dalam kondisi apa pun. */

    // 1. Tombol X di header dan tombol Cancel di footer
    modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (el) {
        el.addEventListener("click", function (e) {
            e.preventDefault();
            closeModal();
        });
    });

    // 2. Klik area gelap di luar kotak modal
    modalEl.addEventListener("click", function (e) {
        if (e.target === modalEl) closeModal();
    });

    // 3. Tombol Escape pada papan ketik
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape" && e.keyCode !== 27) return;
        if (modalEl.classList.contains("show")) closeModal();
    });
});
</script>

<?php include '../includes/footer.php'; ?>