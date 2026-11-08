<?php
// ═══════════════════════════════════════════════════════════════════════════
//  INVENTORY — HUB / GATE
//  File: public_html/bltx/inventory/inventory_gate.php
//
//  File ini adalah index.php yang DIGANTI NAMANYA. Pola dan tampilannya
//  mengikuti purchases/pr_dahsboard_gate.php: satu halaman yang berperan
//  sekaligus sebagai GERBANG (parent gate) dan sebagai WADAH TAB.
//
//  GERBANG
//    Modul 'inventory' adalah parent gate — modul yang SUDAH ADA di tabel
//    `permissions` dan memang sudah berperan sebagai gerbang menu Inventory
//    di sidebar, jadi TIDAK ada modul permission baru dan tidak ada double
//    permission. Gate OFF => seluruh menu dan halaman Inventory tertutup,
//    apa pun isi permission sub-modulnya. Superadmin selalu lolos
//    (ditangani di dalam _dbCan()).
//
//    Seluruh keputusan izin diambil dari includes/global_permission.php
//    lewat inventoryModuleAllowed() / inventoryTabModules(), sehingga
//    daftar tab dan aturan izinnya hanya ditulis di SATU tempat.
//
//  TAB (urutan sesuai submenu sidebar):
//    Inventory Data → Items In → Items Out → Stock Opname → Location
//
//    Kelima-limanya adalah PANE SUNGGUHAN di dalam halaman ini — termasuk
//    Stock Opname, yang sebelumnya berupa tautan keluar. stock_opname.php
//    kini dirender di dalam tab lewat include, dan akses langsung ke berkas
//    itu dialihkan balik ke sini, sehingga hub tidak pernah ditinggalkan.
//
//  index.php di folder ini SUDAH DIHAPUS dan TIDAK DIPAKAI LAGI sama sekali.
//  Seluruh rujukan ke nama lama itu sudah diarahkan ke berkas ini, sehingga
//  tidak ada satu pun tautan di dalam aplikasi yang masih menunjuk ke sana.
// ═══════════════════════════════════════════════════════════════════════════

/* ===============================
   CORE FILES
=============================== */
require_once __DIR__ . '/../includes/config.php';

/* ===============================
   INCLUDE INVENTORY FUNCTION HELPER
   Semua fungsi helper, query builder, dan POST action handler
   ada di includes/inventory_function.php.
=============================== */
require_once __DIR__ . '/../includes/inventory_function.php';

/* ===============================
   HANDLE POST ACTIONS (JSON response)
   Dipanggil sebelum output HTML apapun.
   invHandleProcess() mengirim header JSON lalu exit().
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    invHandleProcess();
}

/* ===============================
   LOGIN CHECK
   Halaman tidak boleh diakses langsung tanpa login.
=============================== */
if (!isLoggedIn()) {
    header('Location: ' . url('index.php'));
    exit;
}

/* ===============================
   PERMISSION CHECK
   Seluruh logika izin dipindahkan ke includes/global_permission.php.
   Halaman ini TIDAK lagi memuat sendiri baris permission dari database.
=============================== */
require_once __DIR__ . '/../includes/global_permission.php';

// GERBANG dievaluasi SEBELUM header dicetak, supaya user yang tidak berhak
// tidak sempat melihat kerangka halaman sama sekali.
if (!canAccessInventoryGate()) {
    redirectNoAccess();
}

// ── Active tab detection ──────────────────────────────────────────────────
// Whitelist ketat: nilai di luar daftar ini diabaikan.
//
// PENTING — tab yang TIDAK DIIZINKAN tidak boleh sekadar disembunyikan dari
// daftar tab; ia harus ditolak sebagai NILAI $active_tab juga. Tanpa ini,
// mengetik ?tab=inventory_in pada akun yang izinnya dicabut akan tetap
// menyorot tab tersebut dan tetap meng-include isinya.
$INV_TAB_MODULES   = inventoryTabModules();
$INV_TAB_ICONS     = inventoryTabIcons();
$INV_TAB_LANGKEYS  = inventoryTabLangKeys();

// Tab yang benar-benar boleh dibuka oleh user ini, dalam urutan tampil.
$INV_TABS = [];
foreach ($INV_TAB_MODULES as $__tab => $__mod) {
    if (inventoryModuleAllowed($__mod)) $INV_TABS[] = $__tab;
}

// Gerbang sudah lolos di atas, tetapi bisa saja SELURUH sub-modul dicabut.
if (empty($INV_TABS)) redirectNoAccess();

$active_tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : '';
// Tab yang tidak dikenal ATAU tidak diizinkan → jatuh ke tab pertama yang boleh.
if (!in_array($active_tab, $INV_TABS, true)) $active_tab = $INV_TABS[0];

// Titipan untuk breadcrumb dan sorotan submenu di header.php.
// Perlu dikirim lewat variabel tersendiri karena untuk URL tanpa ?tab=,
// $_GET['tab'] kosong sementara halaman sebenarnya menampilkan tab pertama
// yang DIIZINKAN — dan tab itu berbeda-beda per user, bergantung permission.
// Tanpa titipan ini breadcrumb dan submenu akan menyebut tab yang salah.
$__bc_tab = $active_tab;

// ── Izin per aksi untuk file yang di-include ──────────────────────────────
// $permissions tetap disediakan demi kompatibilitas mundur dengan
// inventory_data.php / inventory_add.php yang membacanya. Nilainya kini
// berasal dari helper terpusat, bukan dari query yang ditulis ulang di sini.
$permissions = [
    'can_create' => inventoryModuleCan('inventory_data', 'can_create') ? 1 : 0,
    'can_read'   => inventoryModuleCan('inventory_data', 'can_read')   ? 1 : 0,
    'can_update' => inventoryModuleCan('inventory_data', 'can_update') ? 1 : 0,
    'can_delete' => inventoryModuleCan('inventory_data', 'can_delete') ? 1 : 0,
    'can_access' => 1,
    'can_export' => inventoryModuleCan('inventory_data', 'can_export') ? 1 : 0,
];

/* ===============================
   LANGUAGE HANDLING
   Bahasa default sistem adalah English (en) dan ditetapkan di header.php.
   TANPA fallback language: berkas bahasa yang aktif dipakai apa adanya.
=============================== */
$current_lang = $_SESSION['lang'] ?? 'en';
$LANG = loadLanguage($current_lang);

// $lang dipertahankan sebagai alias karena file yang di-include
// (inventory_data.php, inventory_in.php, dst.) membacanya dengan nama itu.
$lang = $LANG;

/* ===============================
   PAGE SETUP
=============================== */
$page_title = $LANG['inventory'];
$action     = isset($_GET['action']) ? sanitize($_GET['action']) : 'list';

/* ===============================
   HEADER
=============================== */
include __DIR__ . '/../includes/header.php';

// header.php memuat ulang $LANG dari $_SESSION['lang'] (termasuk saat bahasa
// baru saja diganti lewat ?lang=). Alias $lang disegarkan di sini supaya file
// tab yang di-include di bawah membaca berkas bahasa yang sama persis.
$lang = $LANG;
?>

<!-- ══ NAV TABS ════════════════════════════════════════════════════════
     Tampilan tab MENGIKUTI purchases/pr_dahsboard_gate.php: <ul> polos
     dengan kelas Bootstrap "nav nav-tabs mb-4", tiap tab adalah <a> biasa
     yang menavigasi (bukan tab JavaScript), ikon Bootstrap di depan judul.
     ══════════════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs mb-4" id="inventoryTabs" role="tablist">
    <?php foreach ($INV_TAB_MODULES as $__tab => $__mod): ?>
    <?php if (!inventoryModuleAllowed($__mod)) continue; ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?= $active_tab === $__tab ? 'active' : '' ?>"
           href="<?= url('inventory/inventory_gate.php?tab=' . $__tab) ?>"
           role="tab" aria-selected="<?= $active_tab === $__tab ? 'true' : 'false' ?>">
            <i class="bi <?= htmlspecialchars($INV_TAB_ICONS[$__tab]) ?>"></i>
            <?= htmlspecialchars($LANG[$INV_TAB_LANGKEYS[$__tab]]) ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- ══ TAB CONTENT ═════════════════════════════════════════════════════ -->
<div class="tab-content" id="inventoryTabContent">

    <!-- Inventory Data tab -->
    <?php if (inventoryModuleAllowed('inventory_data')): ?>
    <div class="tab-pane <?= $active_tab === 'inventory_data' ? 'show active' : '' ?>" role="tabpanel">
        <?php
        if ($active_tab === 'inventory_data') {
            include __DIR__ . ($action === 'add' ? '/inventory_add.php' : '/inventory_data.php');
        }
        ?>
    </div>
    <?php endif; ?>

    <!-- Items In tab -->
    <?php if (inventoryModuleAllowed('inventory_in')): ?>
    <div class="tab-pane <?= $active_tab === 'inventory_in' ? 'show active' : '' ?>" role="tabpanel">
        <?php
        if ($active_tab === 'inventory_in') {
            include __DIR__ . ($action === 'add' ? '/inventory_in_add.php' : '/inventory_in.php');
        }
        ?>
    </div>
    <?php endif; ?>

    <!-- Items Out tab -->
    <?php if (inventoryModuleAllowed('inventory_out')): ?>
    <div class="tab-pane <?= $active_tab === 'inventory_out' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'inventory_out') { include __DIR__ . '/inventory_out.php'; } ?>
    </div>
    <?php endif; ?>

    <!-- Stock Opname tab -->
    <?php if (inventoryModuleAllowed('stock_opname')): ?>
    <div class="tab-pane <?= $active_tab === 'stock_opname' ? 'show active' : '' ?>" role="tabpanel">
        <?php
        if ($active_tab === 'stock_opname') {
            // INV_GATE_EMBED memberi tahu stock_opname.php bahwa ia sedang
            // dirender DI DALAM hub, sehingga berkas itu melewati pengalihan
            // kanoniknya dan tidak memuat ulang header.php / footer.php yang
            // sudah menjadi milik halaman ini.
            if (!defined('INV_GATE_EMBED')) define('INV_GATE_EMBED', true);
            include __DIR__ . '/stock_opname.php';
        }
        ?>
    </div>
    <?php endif; ?>

    <!-- Location tab -->
    <?php if (inventoryModuleAllowed('warehouse_location')): ?>
    <div class="tab-pane <?= $active_tab === 'warehouse_location' ? 'show active' : '' ?>" role="tabpanel">
        <?php if ($active_tab === 'warehouse_location') { include __DIR__ . '/warehouse_location.php'; } ?>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
