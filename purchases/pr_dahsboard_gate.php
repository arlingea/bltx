<?php
// ═══════════════════════════════════════════════════════════════════════════
//  PURCHASE — HUB / GATE
//  Pola mengikuti settings/index.php: satu halaman yang berperan sekaligus
//  sebagai GERBANG (parent gate) dan sebagai WADAH TAB.
//
//  GERBANG
//    Modul 'purchase' (TUNGGAL) adalah parent gate — sengaja dibedakan dari
//    modul 'purchases' (JAMAK) yang sudah dipakai halaman Purchase Invoice,
//    supaya tidak terjadi double permission. Gate OFF => seluruh menu dan
//    halaman Purchase tertutup, apa pun isi permission sub-modulnya.
//    Superadmin selalu lolos (ditangani di dalam _dbCan()).
//
//  TAB (urutan sesuai submenu):
//    Purchase Order → Goods Receipt → Purchase Invoice → Returns →
//    Suppliers → Categories → Units
//
//  File ini adalah SATU-SATUNYA hub Purchase. purchases.php yang dulu
//  bertugas mengalihkan ke sini SUDAH DIHAPUS, dan seluruh tautan yang
//  dulu menunjuk ke sana kini menunjuk langsung ke berkas ini.
//
//  Berkas ini SENGAJA dijaga tetap tipis: gerbang izin, pemilihan tab,
//  nav tab, include tiap tab, dan gaya bersama antar tab. Seluruh urusan
//  tab Purchase Invoice berada di purchases_invoice.php.
// ═══════════════════════════════════════════════════════════════════════════
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/purchases_function.php';
require_once __DIR__ . '/../includes/purchase_orders_function.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';

// Tidak boleh diakses langsung tanpa login.
if (!isLoggedIn()) redirect(url('index.php'));

// GERBANG dievaluasi SEBELUM header dicetak, supaya user yang tidak berhak
// tidak sempat melihat kerangka halaman sama sekali.
if (!canAccessPurchaseGate()) {
    redirectNoAccess();
}

// ── Active tab detection ──────────────────────────────────────────────────
// Whitelist ketat: nilai di luar daftar ini diabaikan.
//
// PENTING — tab yang TIDAK DIIZINKAN tidak boleh sekadar disembunyikan dari
// daftar tab; ia harus ditolak sebagai NILAI $active_tab juga. Tanpa ini,
// mengetik ?tab=invoice pada akun yang izin 'purchases'-nya dicabut akan
// tetap menyorot tab tersebut dan tetap meng-include isinya.
//
// $PURCHASE_TAB_MODULES dipetakan terpusat di global_permission.php
// (purchaseTabModules()), sehingga daftar tab dan aturan izinnya hanya
// ditulis di satu tempat.
$PURCHASE_TAB_MODULES = purchaseTabModules();

// Tab yang benar-benar boleh dibuka oleh user ini, dalam urutan tampil.
$PURCHASE_TABS = [];
foreach ($PURCHASE_TAB_MODULES as $__tab => $__mod) {
    if (purchaseModuleAllowed($__mod)) $PURCHASE_TABS[] = $__tab;
}

// Gerbang sudah lolos di atas, tetapi bisa saja SELURUH sub-modul dicabut.
if (empty($PURCHASE_TABS)) redirectNoAccess();

$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : '';
// Tab yang tidak dikenal ATAU tidak diizinkan → jatuh ke tab pertama yang boleh.
if (!in_array($active_tab, $PURCHASE_TABS, true)) $active_tab = $PURCHASE_TABS[0];

// Titipan untuk breadcrumb di header.php.
// Perlu dikirim lewat variabel tersendiri karena untuk URL tanpa ?tab=,
// $_GET['tab'] kosong sementara halaman sebenarnya menampilkan tab pertama
// yang DIIZINKAN — dan tab itu berbeda-beda per user, bergantung permission.
// Tanpa titipan ini breadcrumb akan menyebut tab yang salah.
$__bc_tab = $active_tab;

$page_title = $LANG['purchases'];
include '../includes/header.php';

// ── POST: Delete purchase ORDER (new) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'po_delete') {
    $po_del_id   = (int)($_POST['po_id']      ?? 0);
    $po_del_csrf = (string)($_POST['csrf_token'] ?? '');
    $po_del_res  = po_delete($conn, $po_del_id, $po_del_csrf);
    if ($po_del_res['success']) {
        bltx_flash_success($LANG['delete_success']);
    } else {
        $key = $po_del_res['message_key'];
        if ($key === 'invalid_csrf') {
            bltx_flash_error($LANG['invalid_csrf']);
        } elseif ($key === 'no_permission') {
            bltx_flash_error($LANG['no_permission']);
        } elseif ($key === 'po_only_draft_can_delete') {
            bltx_flash_error($LANG['po_only_draft_can_delete']);
        } else {
            bltx_flash_error($LANG['error']);
        }
    }
    redirect(url('purchases/pr_dahsboard_gate.php?tab=order'));
}

// ── CSRF token tab Purchase Order ────────────────────────────────────────
// Token tab Purchase Invoice (pf_csrfToken) SUDAH DIPINDAH ke
// purchases_invoice.php bersama seluruh urusan tab tersebut.
$po_csrf_token = po_csrfToken();   // for PO delete form

// ── Fetch Purchase Orders for the 'order' tab ─────────────────────────────
$po_list = po_getList($conn, [
    'status'      => isset($_GET['po_status'])      ? trim($_GET['po_status']) : '',
    'supplier_id' => isset($_GET['po_supplier_id']) ? (int)$_GET['po_supplier_id'] : 0,
]);

$extra_js = '
<style>
/* ══════════════════════════════════════════════════════════════════════════
   GAYA BERSAMA SELURUH TAB PURCHASE

   Hanya aturan yang berlaku untuk SEMUA tab yang tinggal di sini: tabel,
   kartu, judul, alert, tombol, gambar, dan aturan responsifnya.

   Seluruh gaya khusus tab Purchase Invoice (.filter-bar, .cur-cell,
   .invoice-thumbnail, skeleton tabel) SUDAH DIPINDAH ke purchases_invoice.php.
   Aturan di bawah ini SENGAJA TIDAK ikut pindah: ketujuh tab memakainya, jadi
   memindahkannya akan mengubah tampilan tab Order, Goods Receipt, Returns,
   Suppliers, Categories, dan Units.

   purchases_invoice.php MENAMBAHKAN gayanya ke $extra_js ini memakai .=
   (bukan menimpanya), sehingga urutan cascade tetap: bersama dulu, lalu invoice.
   ══════════════════════════════════════════════════════════════════════ */
/* ── Table / card ────────────────────────────────────────────── */
.table th          { white-space:nowrap; font-weight:600; background-color:#f8f9fa; border-bottom:2px solid #dee2e6; }
.table th,.table td{ vertical-align:middle; padding:.75rem; }
.table td          { word-break:break-word; }
.card              { border-radius:.5rem; box-shadow:0 .125rem .25rem rgba(0,0,0,.075); border:1px solid rgba(0,0,0,.125); }
.card-body         { padding:1.5rem; }
h4                 { font-size:1.5rem; font-weight:600; margin:0; }
.alert             { border-radius:.5rem; border:none; }
.btn-group-sm>.btn { padding:.25rem .5rem; font-size:.875rem; }
img                { image-rendering:-webkit-optimize-contrast; }
/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width:992px){
    .card-body{padding:1rem;} .table th,.table td{padding:.5rem;font-size:.875rem;} h4{font-size:1.25rem;}
}
@media (max-width:768px){
    h4{font-size:1.1rem;margin-bottom:1rem;} .card-body{padding:.75rem;}
}
@media (max-width:576px){
    .table th,.table td{padding:.4rem;font-size:.8rem;} h4{font-size:1rem;} .btn-sm{font-size:.8rem;padding:.25rem .5rem;}
}
*{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}
</style>
';
?>

<!-- ══ NAV TABS ════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs mb-4" id="purchasesTabs" role="tablist">
    <?php if (purchaseModuleAllowed('purchase_orders')): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === 'order' ? 'active' : '' ?>"
           href="<?= url('purchases/pr_dahsboard_gate.php?tab=order') ?>"
           role="tab" aria-selected="<?= $active_tab === 'order' ? 'true' : 'false' ?>">
            <i class="bi bi-file-earmark-text"></i>
            <?= htmlspecialchars($LANG['purchase_order']) ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (purchaseModuleAllowed('goods_receipt')): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === 'goods_receipt' ? 'active' : '' ?>"
           href="<?= url('purchases/pr_dahsboard_gate.php?tab=goods_receipt') ?>"
           role="tab" aria-selected="<?= $active_tab === 'goods_receipt' ? 'true' : 'false' ?>">
            <i class="bi bi-box-seam"></i>
            <?= htmlspecialchars($LANG['goods_receipt']) ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (purchaseModuleAllowed('purchases')): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === 'invoice' ? 'active' : '' ?>"
           href="<?= url('purchases/pr_dahsboard_gate.php?tab=invoice') ?>"
           role="tab" aria-selected="<?= $active_tab === 'invoice' ? 'true' : 'false' ?>">
            <i class="bi bi-receipt"></i>
            <?= htmlspecialchars($LANG['purchase_invoice']) ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (purchaseModuleAllowed('returns')): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === 'returns' ? 'active' : '' ?>"
           href="<?= url('purchases/pr_dahsboard_gate.php?tab=returns') ?>"
           role="tab" aria-selected="<?= $active_tab === 'returns' ? 'true' : 'false' ?>">
            <i class="bi bi-arrow-return-left"></i>
            <?= htmlspecialchars($LANG['returns']) ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (purchaseModuleAllowed('suppliers')): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === 'suppliers' ? 'active' : '' ?>"
           href="<?= url('purchases/pr_dahsboard_gate.php?tab=suppliers') ?>"
           role="tab" aria-selected="<?= $active_tab === 'suppliers' ? 'true' : 'false' ?>">
            <i class="bi bi-people-fill"></i>
            <?= htmlspecialchars($LANG['suppliers']) ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (purchaseModuleAllowed('categories')): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === 'categories' ? 'active' : '' ?>"
           href="<?= url('purchases/pr_dahsboard_gate.php?tab=categories') ?>"
           role="tab" aria-selected="<?= $active_tab === 'categories' ? 'true' : 'false' ?>">
            <i class="bi bi-tags-fill"></i>
            <?= htmlspecialchars($LANG['categories']) ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (purchaseModuleAllowed('units')): ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === 'units' ? 'active' : '' ?>"
           href="<?= url('purchases/pr_dahsboard_gate.php?tab=units') ?>"
           role="tab" aria-selected="<?= $active_tab === 'units' ? 'true' : 'false' ?>">
            <i class="bi bi-rulers"></i>
            <?= htmlspecialchars($LANG['units']) ?>
        </a>
    </li>
    <?php endif; ?>
</ul>

<!-- ══ TAB CONTENT ═════════════════════════════════════════════════════ -->
<div class="tab-content" id="purchasesTabContent">

    <!-- Purchase Order tab -->
    <?php if (purchaseModuleAllowed('purchase_orders')): ?>
    <div class="tab-pane <?= $active_tab === 'order' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'order') { include __DIR__ . '/purchases_order.php'; } ?>
    </div>
    <?php endif; ?>

    <!-- Goods Receipt tab -->
    <?php if (purchaseModuleAllowed('goods_receipt')): ?>
    <div class="tab-pane <?= $active_tab === 'goods_receipt' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'goods_receipt') { include __DIR__ . '/goods_receipt.php'; } ?>
    </div>
    <?php endif; ?>

    <!-- Purchase Invoice tab -->
    <?php if (purchaseModuleAllowed('purchases')): ?>
    <div class="tab-pane <?= $active_tab === 'invoice' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'invoice') { include __DIR__ . '/purchases_invoice.php'; } ?>
    </div>
    <?php endif; ?>

    <!-- Returns tab -->
    <?php if (purchaseModuleAllowed('returns')): ?>
    <div class="tab-pane <?= $active_tab === 'returns' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'returns') { include __DIR__ . '/returns.php'; } ?>
    </div>
    <?php endif; ?>

    <!-- Suppliers tab -->
    <?php if (purchaseModuleAllowed('suppliers')): ?>
    <div class="tab-pane <?= $active_tab === 'suppliers' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'suppliers') { include __DIR__ . '/suppliers.php'; } ?>
    </div>
    <?php endif; ?>

    <!-- Categories tab -->
    <?php if (purchaseModuleAllowed('categories')): ?>
    <div class="tab-pane <?= $active_tab === 'categories' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'categories') { include __DIR__ . '/categories.php'; } ?>
    </div>
    <?php endif; ?>

    <!-- Units tab -->
    <?php if (purchaseModuleAllowed('units')): ?>
    <div class="tab-pane <?= $active_tab === 'units' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'units') { include __DIR__ . '/units.php'; } ?>
    </div>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>