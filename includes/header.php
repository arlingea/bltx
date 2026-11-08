<?php
/* ═════════════════════════════════════════════════════════════════════════
   HEADER — AdminLTE v4 Refactor
   Semua PHP logic (auth, permission, sidebar conditionals) DIPERTAHANKAN
   sama persis seperti versi lama (lihat header.php.bak).
   Hanya markup HTML/CSS/JS shell yang berubah agar konsisten dgn AdminLTE 4.
   ═════════════════════════════════════════════════════════════════════════ */

// Reusable table UI helpers (ui_table_open, ui_table_close, ui_table_empty, etc.)
require_once __DIR__ . '/ui_table.php';

// Centralized permission helpers (includes/global_permission.php).
// Diperlukan oleh sidebar untuk menampilkan submenu Goods Receipt lewat
// canAccessGoodsReceipt(). File tersebut sudah aman dipanggil berulang:
// seluruh fungsinya dibungkus if (!function_exists(...)) dan ia langsung
// return bila config.php belum dimuat.
require_once __DIR__ . '/global_permission.php';

if (!isLoggedIn()) {
    redirect(url('index.php'));
    exit;
}

// Inisialisasi Bahasa dari Cookie/Session spesifik user
if (!isset($_SESSION['lang'])) {
    $_current_user_cookie = isset($_SESSION['user_id']) ? '__bltx_lang_' . $_SESSION['user_id'] : '__bltx_lang_guest';
    if (isset($_COOKIE[$_current_user_cookie]) && in_array($_COOKIE[$_current_user_cookie], ['en', 'id', 'ch'], true)) {
        $_SESSION['lang'] = $_COOKIE[$_current_user_cookie];
    } else {
        $_SESSION['lang'] = getSetting('language', 'en');
    }
}

// Inisialisasi Mata Uang dari Cookie/Session spesifik user
if (!isset($_SESSION['currency'])) {
    $_curr_cookie = isset($_SESSION['user_id']) ? '__bltx_curr_' . $_SESSION['user_id'] : '__bltx_curr_guest';
    if (isset($_COOKIE[$_curr_cookie]) && in_array($_COOKIE[$_curr_cookie], ['IDR', 'USD', 'CNY'], true)) {
        $_SESSION['currency'] = $_COOKIE[$_curr_cookie];
    } else {
        $_SESSION['currency'] = getSetting('currency', 'IDR');
    }
}

$LANG = loadLanguage($_SESSION['lang']);

$current_page = basename($_SERVER['PHP_SELF'], '.php');
// Special: settings/settings_gate.php and inventory/inventory_gate.php should
// be treated as hub names.
//
// CATATAN — inventory/index.php SUDAH DIHAPUS dan tidak dipakai lagi sama
// sekali. Karena itu 'index' TIDAK lagi dipetakan ke hub Inventory; pemetaan
// 'index' di bawah hanya tersisa untuk settings, dan folder inventory kini
// dikenali lewat nama berkasnya sendiri: inventory_gate.
if ($current_page === 'index' || $current_page === 'settings_gate' || $current_page === 'inventory_gate') {
    $dir = basename(dirname($_SERVER['PHP_SELF']));
    if ($dir === 'settings')  $current_page = 'settings';
    if ($dir === 'inventory' && $current_page === 'inventory_gate') $current_page = 'inventory';
}

/* ─────────────────────────────────────────────────────────────────────
   URL DASAR UNTUK TOMBOL GANTI BAHASA & MATA UANG.

   MASALAH YANG DIPERBAIKI
   Link switcher dulu ditulis relatif (href="?lang=en"). Browser
   mengartikannya sebagai "ganti SELURUH query string", sehingga
   ?tab=users, ?from_date=..., dan filter lain HILANG begitu tombolnya
   diklik — pengguna terlempar ke tab pertama dan filternya hangus.
   Redirect sesudahnya memakai $_SERVER['PHP_SELF'] yang memang tidak
   pernah memuat query string, jadi masalahnya berlapis dua.

   $_bltx_switch_base di bawah membangun ulang URL halaman ini LENGKAP
   dengan seluruh parameter GET yang sedang aktif, minus lang/currency
   yang akan diganti. http_build_query() otomatis meng-URL-encode
   semuanya sehingga tidak bisa dijadikan jalur injeksi, dan
   basename() memastikan tujuannya selalu berkas di folder yang sama —
   tidak mungkin diarahkan ke domain luar.

   Berlaku untuk SELURUH halaman bertab dan berfilter:
   settings_gate.php, inventory_gate.php, pr_dahsboard_gate.php,
   purchases_invoice.php, inventory_data/in/out.php, dan file export.
   ───────────────────────────────────────────────────────────────── */
$_bltx_switch_params = $_GET;
unset($_bltx_switch_params['lang'], $_bltx_switch_params['currency']);
$_bltx_switch_query  = http_build_query($_bltx_switch_params);
$_bltx_switch_base   = basename($_SERVER['PHP_SELF'])
                     . '?' . ($_bltx_switch_query !== '' ? $_bltx_switch_query . '&' : '');
/** URL kembali sesudah switcher diproses: halaman & filter yang sama persis. */
$_bltx_switch_return = basename($_SERVER['PHP_SELF'])
                     . ($_bltx_switch_query !== '' ? '?' . $_bltx_switch_query : '');

// Handle language change (Independen & Secure Cookie)
if (isset($_GET['lang'])) {
    $lang = sanitize($_GET['lang']);
    if (in_array($lang, ['en', 'id', 'ch'], true)) {
        $_SESSION['lang'] = $lang;
        $_current_user_cookie = isset($_SESSION['user_id']) ? '__bltx_lang_' . $_SESSION['user_id'] : '__bltx_lang_guest';
        
        setcookie($_current_user_cookie, $lang, [
            'expires'  => time() + (365 * 24 * 60 * 60),
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        $LANG = loadLanguage($lang);
        // Kembali ke halaman & filter yang SAMA (dulu: PHP_SELF, yang
        // membuang seluruh query string sehingga tab/filter hilang).
        redirect($_bltx_switch_return);
        exit;
    }
}

// Handle currency change (Independen & Secure Cookie)
if (isset($_GET['currency'])) {
    $currency = sanitize($_GET['currency']);
    if (in_array($currency, ['IDR', 'USD', 'CNY'], true)) {
        $_SESSION['currency'] = $currency;
        $_curr_cookie = isset($_SESSION['user_id']) ? '__bltx_curr_' . $_SESSION['user_id'] : '__bltx_curr_guest';
        
        // Simpan currency ke cookie yang aman agar bertahan tanpa mengganggu global DB
        setcookie($_curr_cookie, $currency, [
            'expires'  => time() + (365 * 24 * 60 * 60),
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        // Kembali ke halaman & filter yang SAMA (dulu: PHP_SELF, yang
        // membuang seluruh query string sehingga tab/filter hilang).
        redirect($_bltx_switch_return);
        exit;
    }
}

/* -------------------------------------------------------
   PERMISSION VARIABLES FOR SIDEBAR RENDER
   (sama persis seperti versi lama — TIDAK DIUBAH)
------------------------------------------------------- */
$_current_role  = $_SESSION['role'] ?? '';
$_current_uid   = (int)($_SESSION['user_id'] ?? 0);
$_is_superadmin = ($_current_role === 'superadmin');
$_no_access     = false;

// Active-detection vars — digunakan di PHP conditionals dan inline JS
$_in_inventory  = (strpos($_SERVER['REQUEST_URI'], '/inventory/') !== false);
// $__bc_tab didahulukan: hub Inventory (inventory_gate.php) menitipkan tab
// yang BENAR-BENAR ditampilkan, karena pada URL tanpa ?tab= halaman jatuh ke
// tab pertama yang DIIZINKAN — dan tab itu berbeda-beda per user sesuai
// permission. Tanpa titipan ini, sorotan submenu bisa menunjuk tab yang salah.
// $_GET['tab'] tetap dipakai sebagai cadangan untuk halaman lain.
$_inv_tab       = (isset($__bc_tab) && $__bc_tab !== '')
                ? (string)$__bc_tab
                : (isset($_GET['tab']) ? $_GET['tab'] : '');
$_in_payroll    = strpos($_SERVER['REQUEST_URI'], '/payroll/') !== false;
/* Tab hub HR & Payroll yang sedang aktif. $__bc_tab dititipkan payroll_gate.php
   karena untuk URL tanpa ?tab= nilai $_GET['tab'] kosong sementara halaman
   sebenarnya menampilkan tab pertama yang DIIZINKAN — dan tab itu berbeda-beda
   per user. Dipakai untuk menyorot submenu sidebar dan mengisi breadcrumb. */
$_pay_tab = (isset($__bc_tab) && $__bc_tab !== '')
          ? (string)$__bc_tab
          : (isset($_GET['tab']) ? (string)$_GET['tab'] : '');
$_is_pay_hub = strpos($_SERVER['REQUEST_URI'], '/payroll/payroll_gate.php') !== false;
$_in_dreport    = strpos($_SERVER['REQUEST_URI'], 'manual_dreport') !== false;
$_in_take_items = strpos($_SERVER['REQUEST_URI'], 'take_items') !== false;
$_in_in_items   = strpos($_SERVER['REQUEST_URI'], 'in_items') !== false;
$_in_stock_opname = strpos($_SERVER['REQUEST_URI'], 'stock_opname') !== false;
$_in_settings   = ($current_page === 'settings');
$_settings_tab  = isset($_GET['tab']) ? $_GET['tab'] : 'general';

// ── Theme (dark / light) ─────────────────────────────────────────────────
// Persisted via cookie `__bltx_theme`. Default = light.
$_theme = (isset($_COOKIE['__bltx_theme']) && $_COOKIE['__bltx_theme'] === 'dark') ? 'dark' : 'light';

// ─── Sidebar: keadaan tertutup/terbuka ────────────────────────────────────
// SATU sumber kebenaran = cookie `__bltx_sb_min`, dibaca di sini lalu dipakai
// oleh kelas <html> MAUPUN kelas <body> di bawah. Sebelumnya kelas <html>
// dibaca dari localStorage sementara kelas <body> dari cookie; ketika keduanya
// tidak sinkron, peredam transisi tidak cocok dan animasi tetap terlihat.
$_sb_collapsed = (isset($_COOKIE['__bltx_sb_min']) && $_COOKIE['__bltx_sb_min'] === '1');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang']); ?>" translate="no" class="notranslate<?php echo $_sb_collapsed ? ' sidebar-collapse-init' : ''; ?>" data-bs-theme="<?php echo $_theme; ?>" style="background-color: <?php echo $_theme === 'dark' ? '#0f172a' : '#f5f7fb'; ?>;">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="color-scheme" content="<?php echo $_theme === 'dark' ? 'dark' : 'light'; ?>">
<meta name="theme-color" content="<?php echo $_theme === 'dark' ? '#1e2a38' : '#0d6efd'; ?>">
<meta name="description" content="<?php echo APP_NAME; ?> - ARW Management System">
<meta name="google" content="notranslate">
<meta http-equiv="Content-Language" content="<?php echo htmlspecialchars($_SESSION['lang']); ?>">

<title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo APP_NAME; ?></title>

<link rel="icon" href="<?= url('assets/arw-favicon.svg') ?>" type="image/svg+xml">

<!-- Preload critical resources -->
<link rel="preload" href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>" as="style">
<link rel="preload" href="<?= url('assets/vendor/adminlte/css/adminlte.min.css') ?>" as="style">

<!-- CSS — Vendor (LOCAL ONLY, NO CDN) -->
<link href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/datatables/dataTables.bootstrap5.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">

<!-- CSS — AdminLTE v4 -->
<!-- overlayscrollbars — scrollbar sidebar AdminLTE 4.
     WAJIB dimuat SEBELUM adminlte.min.css, mengikuti urutan di starter.html
     bawaan AdminLTE 4.9.1. Diunduh lokal, bukan CDN. -->
<link rel="stylesheet" href="<?= url('assets/vendor/overlayscrollbars/overlayscrollbars.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/adminlte/css/adminlte.min.css') ?>">

<style>
/* ═══════════════════════════════════════════════════════════════════════
   ARW MANAGEMENT — AdminLTE v4 Customisation Layer
   Keeps the project's signature deep-navy sidebar + light navbar look
   while inheriting all AdminLTE 4 layout, responsiveness, and primitives.
   ═══════════════════════════════════════════════════════════════════════ */
:root {
    --arw-sidebar-bg:         #1e2a38;
    --arw-sidebar-brand-bg:   #17222e;
    --arw-sidebar-hover:      rgba(255,255,255,.07);
    --arw-sidebar-active:     rgba(255,255,255,.12);
    --arw-sidebar-text:       rgba(255,255,255,.78);
    --arw-sidebar-text-on:    #ffffff;
    --arw-sidebar-accent:     #4f8ef7;
    --arw-sidebar-border:     rgba(255,255,255,.08);
    --arw-navbar-height:      56px;
    --arw-primary:            #0d6efd;
    --arw-primary-soft:       #e0eeff;
}

html, body { height: 100%; }

body {
    font-family: 'Source Sans 3', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    font-size: .95rem;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    background-color: #f4f6f9;
}
/* AdminLTE 4 uniform typography baseline */
h1, h2, h3, h4, h5, h6 { font-weight: 600; line-height: 1.3; }
h1 { font-size: 1.75rem; }
h2 { font-size: 1.5rem; }
h3 { font-size: 1.25rem; }
h4 { font-size: 1.1rem; }
h5 { font-size: 1rem; }
h6 { font-size: .9rem; }
.small, small { font-size: .82rem; }

/* ─── App Header (top navbar) ─────────────────────────────────────────── */
.app-header.navbar {
    background: #ffffff !important;
    box-shadow: 0 1px 0 rgba(0,0,0,.06), 0 1px 3px rgba(0,0,0,.04);
    min-height: var(--arw-navbar-height);
}

.app-header .navbar-nav .nav-link {
    color: #4a5568 !important;
    padding: .5rem .85rem;
    border-radius: .375rem;
    transition: background-color .15s ease, color .15s ease;
}
.app-header .navbar-nav .nav-link:hover,
.app-header .navbar-nav .nav-link:focus {
    color: #1e2a38 !important;
    background: rgba(0,0,0,.04);
}
.app-header .navbar-nav .nav-link i { font-size: 1.1rem; }

/* Header breadcrumb (next to sidebar toggle) — shows page hierarchy */
.bltx-header-bc {
    padding: 0;
    background: transparent !important;
    font-size: .88rem;
}
.bltx-header-bc .breadcrumb-item { display: inline-flex; align-items: center; }
.bltx-header-bc .breadcrumb-item a {
    color: #475569;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
}
.bltx-header-bc .breadcrumb-item a:hover { color: #2563eb; }
.bltx-header-bc .breadcrumb-item.active { color: #1e2a38; font-weight: 600; }
.bltx-header-bc .breadcrumb-item + .breadcrumb-item::before {
    content: "/";
    color: #94a3b8;
    padding: 0 .35rem 0 0;
}
.app-header .dropdown-menu {
    border: none;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
    border-radius: .5rem;
    margin-top: .5rem;
    min-width: 200px;
}
.app-header .dropdown-item {
    padding: .55rem 1rem;
    font-size: .92rem;
    display: flex;
    align-items: center;
    gap: .6rem;
}
.app-header .dropdown-item:hover {
    background: var(--arw-primary-soft);
    color: var(--arw-primary);
}
.app-header .dropdown-item i { width: 18px; text-align: center; }

.app-header .navbar-toggle {
    color: #1e2a38;
    background: transparent;
    border: 0;
    padding: .35rem .55rem;
    font-size: 1.35rem;
    border-radius: .375rem;
}
.app-header .navbar-toggle:hover { background: rgba(0,0,0,.05); }

/* ─── App Sidebar (deep navy) ─────────────────────────────────────────── */
.app-sidebar {
    background: var(--arw-sidebar-bg) !important;
    box-shadow: 0 0 12px rgba(0,0,0,.18);
}

.app-sidebar .sidebar-brand {
    background: var(--arw-sidebar-brand-bg);
    border-bottom: 1px solid var(--arw-sidebar-border);
    padding: .65rem 1rem;
    min-height: var(--arw-navbar-height);
    display: flex;
    align-items: center;
}
.app-sidebar .sidebar-brand .brand-link {
    color: #fff;
    display: flex;
    align-items: center;
    gap: .6rem;
    text-decoration: none;
    width: 100%;
}
.app-sidebar .sidebar-brand .brand-image {
    height: 32px;
    width: 32px;
    object-fit: contain;
    flex-shrink: 0;
}
.app-sidebar .sidebar-brand .brand-text {
    font-weight: 600;
    font-size: 1rem;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.app-sidebar .sidebar-wrapper { padding: .5rem 0; }

/* sidebar-menu items */
.app-sidebar .sidebar-menu .nav-item { margin: 0; }
.app-sidebar .sidebar-menu > .nav-item > .nav-link,
.app-sidebar .sidebar-menu .nav-link {
    color: var(--arw-sidebar-text);
    padding: .58rem 1.1rem;
    border-left: 3px solid transparent;
    border-radius: 0;
    display: flex;
    align-items: center;
    gap: .75rem;
    font-size: .94rem;
    transition: background-color .2s, color .2s, border-color .2s, transform .2s;
}
.app-sidebar .sidebar-menu .nav-link:hover,
.app-sidebar .sidebar-menu .nav-link:focus {
    background: var(--arw-sidebar-hover);
    color: var(--arw-sidebar-text-on);
    border-left-color: var(--arw-sidebar-accent);
    transform: translateX(2px);
}
.app-sidebar .sidebar-menu .nav-link.active,
.app-sidebar .sidebar-menu .nav-link[aria-current="page"] {
    background: var(--arw-sidebar-active);
    color: var(--arw-sidebar-text-on);
    border-left-color: var(--arw-sidebar-accent);
    font-weight: 600;
}
.app-sidebar .sidebar-menu .nav-link .nav-icon,
.app-sidebar .sidebar-menu .nav-link > i:first-child {
    font-size: 1.05rem;
    width: 20px;
    text-align: center;
    flex-shrink: 0;
}
.app-sidebar .sidebar-menu .nav-link p {
    margin: 0;
    flex: 1;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.app-sidebar .sidebar-menu .nav-arrow {
    font-size: .8rem;
    margin-left: auto;
    transition: transform .25s ease;
}
.app-sidebar .sidebar-menu .menu-open > .nav-link .nav-arrow,
.app-sidebar .sidebar-menu .nav-link[aria-expanded="true"] .nav-arrow {
    transform: rotate(90deg);
}

/* sub menu (treeview) */
.app-sidebar .sidebar-menu .nav-treeview {
    list-style: none;
    padding-left: 0;
    margin: 0;
    background: rgba(0,0,0,.18);
    display: none;
}

/* ─── MODE IKON (sidebar-mini + sidebar-collapse) — gaya AdminLTE 4 ──────
   Tiga aturan kustom di atas menghalangi ikon terpusat saat sidebar menyusut:

     .nav-link      { padding: .58rem 1.1rem; gap: .75rem;
                      border-left: 3px solid transparent; }
     .nav-link p    { flex: 1; }          <- INI penyebab utamanya
     .nav-icon      { width: 20px; }

   `flex: 1` pada elemen p membuatnya memakan seluruh sisa lebar walau teksnya
   tidak terlihat, sehingga ikon terdorong ke kiri dan ruang kosong menumpuk
   di kanan. `gap` dan padding menambah pergeseran, dan `border-left` 3px
   membuat kiri-kanan tidak simetris.

   Blok ini menetralkan keempatnya, HANYA saat menyusut. Memakai !important
   karena selektor kustom di atas sama spesifiknya. `:not(:hover)` menjaga
   sidebar tetap melebar sementara saat disentuh kursor, seperti contoh
   sidebar-mini bawaan AdminLTE 4. */

body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu .nav-link {
    padding-left: 0 !important;
    padding-right: 0 !important;
    gap: 0 !important;
    justify-content: center !important;
    /* Border kanan transparan mengimbangi border kiri 3px, supaya kotak
       isinya benar-benar di tengah strip — bukan tergeser 3px. Penanda
       menu aktif di border kiri tetap berfungsi. */
    border-right: 3px solid transparent;
}
/* Teks menu dan panah submenu: disembunyikan, dan flex-nya dimatikan supaya
   tidak menyisakan ruang. */
body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu .nav-link p {
    display: none !important;
    flex: 0 !important;
}
/* Ikon: lebar tetap 20px dilepas agar benar-benar terpusat. */
body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu .nav-link .nav-icon,
body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu .nav-link > i:first-child {
    margin: 0 !important;
    width: auto !important;
}
/* Submenu tidak boleh mengembang saat menyusut. */
body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu .nav-treeview {
    display: none !important;
}
/* Brand ikut terpusat. */
body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-brand {
    padding-left: 0 !important;
    padding-right: 0 !important;
}
body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-brand .brand-link {
    justify-content: center !important;
}
body.sidebar-collapse .app-sidebar:not(:hover) .sidebar-brand .brand-text {
    display: none !important;
}

.app-sidebar .sidebar-menu .menu-open > .nav-treeview { display: block; }
.app-sidebar .sidebar-menu .nav-treeview .nav-link {
    padding: .42rem 1.1rem .42rem 2.6rem;
    font-size: .87rem;
    border-left: 3px solid transparent;
}
.app-sidebar .sidebar-menu .nav-treeview .nav-link i {
    font-size: .9rem;
    width: 16px;
}
.app-sidebar .sidebar-menu .nav-treeview .nav-link.active {
    background: var(--arw-sidebar-active);
    color: var(--arw-sidebar-text-on);
    border-left-color: var(--arw-sidebar-accent);
}
.sidebar-submenu-divider {
    border: 0;
    border-top: 1px solid rgba(255,255,255,.18);
    margin: .35rem 1.4rem;
}

/* ─── App Main / Content — uniform page padding (0.5rem all sides) ─── */
.app-main { background: #f4f6f9; padding-top: 0; }
.app-content { padding: .5rem 0; }                  /* vertical padding */
.app-content > .container-fluid { padding: 0 .5rem; } /* horizontal padding */
.app-content-header { padding: 0 0 .5rem !important; }
.content-header { padding-top: 0 !important; }

/* The legacy templates expect a `.content` wrapper around their body —
   inherit identical horizontal padding so old + new pages align. */
.content {
    padding: .5rem;
    min-height: calc(100vh - var(--arw-navbar-height) - 60px);
}

/* ─── Cards everywhere should look clean ──────────────────────────────── */
.card {
    border: 1px solid #e3e6f0;
    border-radius: .55rem;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
}
.card .card-header {
    background: #fff;
    border-bottom: 1px solid #edf0f5;
    padding: .85rem 1.1rem;
    font-weight: 600;
}
.card .card-body { padding: 1.1rem; }

/* ─── Tables — uniform AdminLTE 4 style ───────────────────────────────── */
.table {
    vertical-align: middle;
    font-size: .9rem;
    color: #344054;
    margin-bottom: 0;
}
.table > thead {
    background: #f8fafc;
}
.table > thead th {
    font-weight: 600;
    color: #475569;
    border-bottom: 2px solid #e3e6f0;
    font-size: .85rem;
    text-transform: none;
    padding: .65rem .75rem;
    white-space: nowrap;
}
.table > tbody > tr > td {
    padding: .6rem .75rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.table-hover > tbody > tr:hover > * {
    --bs-table-hover-bg: rgba(13,110,253,.04);
    background-color: rgba(13,110,253,.04);
}
.table-striped > tbody > tr:nth-of-type(odd) > * {
    background-color: rgba(13,110,253,.02);
}
.table-sm > thead th { padding: .45rem .55rem; }
.table-sm > tbody > tr > td { padding: .4rem .55rem; }
/* Responsive tables — always allow horizontal scroll */
.table-responsive { -webkit-overflow-scrolling: touch; }

/* ─── Forms ───────────────────────────────────────────────────────────── */
.form-label { font-weight: 500; color: #344054; font-size: .88rem; margin-bottom: .35rem; }
.form-control, .form-select {
    border-color: #d0d5dd;
    font-size: .92rem;
    padding: .45rem .75rem;
    border-radius: .4rem;
}
.form-control:focus, .form-select:focus {
    border-color: var(--arw-primary);
    box-shadow: 0 0 0 .2rem rgba(13,110,253,.12);
}

/* ═══════════════════════════════════════════════════════════════════════
   UNIFORM HEIGHT — all form-controls, selects, date inputs, buttons match
   the unit_price field on purchase_add.php (input-group-sm = ~38px).
   This applies project-wide so filter rows, date pickers, and table-action
   buttons align on a single baseline. The .notes / textarea is excluded.
   ═══════════════════════════════════════════════════════════════════════ */
:root { --arw-control-height: 32px; }
.form-control:not(textarea):not(.form-control-lg):not(.notes),
.form-select:not(.form-select-lg),
.input-group .form-control:not(textarea),
.input-group .form-select,
.input-group .input-group-text,
.daterangepicker-input,
.dataTables_length select,
.dataTables_filter input,
.btn:not(.btn-lg):not(.btn-link) {
    min-height: var(--arw-control-height);
    line-height: 1.4;
}
/* Bootstrap 'sm' size keep slightly smaller text but same outer height */
.form-control-sm, .form-select-sm, .input-group-sm > .form-control,
.input-group-sm > .form-select, .input-group-sm > .input-group-text,
.btn-sm {
    min-height: var(--arw-control-height);
    font-size: .85rem;
    padding: .35rem .65rem;
}
/* Textareas / notes excluded — keep their natural multi-line height */
textarea.form-control, .form-control.notes { min-height: 0; }

/* ─── Buttons ─────────────────────────────────────────────────────────── */
.btn {
    border-radius: .4rem;
    font-weight: 500;
    padding: .42rem .9rem;
    font-size: .92rem;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    line-height: 1.4;
}
.btn-sm { padding: .25rem .55rem; font-size: .82rem; }
.btn i { font-size: .95em; }

/* AUTO-SIZE: buttons inside table cells should be small (kompak) */
.table td .btn,
.table th .btn,
.table td .btn-group .btn,
.table th .btn-group .btn {
    padding: .25rem .5rem;
    font-size: .78rem;
}
/* btn-group-sm Bootstrap pattern — children auto small */
.btn-group-sm > .btn { padding: .25rem .55rem; font-size: .82rem; }

/* AUTO-SIZE: card-tools buttons stay small */
.card > .card-header .card-tools .btn:not(.btn-tool) {
    padding: .25rem .55rem;
    font-size: .82rem;
}

/* Icon-only buttons (no visible text label) — square shape */
.table td .btn > i:only-child,
.table th .btn > i:only-child { font-size: .9rem; }

/* ─── Modal ───────────────────────────────────────────────────────────── */
.modal-header { background: #fff; border-bottom: 1px solid #edf0f5; }
.modal-title { font-weight: 600; }
.modal-content { border: 0; border-radius: .65rem; box-shadow: 0 10px 40px rgba(0,0,0,.18); }
.modal-footer { background: #fafbfc; border-top: 1px solid #edf0f5; }

/* ─── App Footer ──────────────────────────────────────────────────────── */
.app-footer {
    background: #fff;
    border-top: 1px solid #edf0f5;
    color: #4a5568;
    padding: .8rem 1.5rem;
    font-size: .88rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

/* ─── Mobile / touch optimisation ─────────────────────────────────────── */
@media (max-width: 767px) {
    .app-content > .container-fluid { padding: 0 .5rem; }
    .card .card-body { padding: .9rem; }
}
@media (hover: none) and (pointer: coarse) {
    .app-header .navbar-nav .nav-link,
    .app-sidebar .sidebar-menu .nav-link,
    .app-header .dropdown-item {
        min-height: 44px;
    }
}

/* ═════════════════════════════════════════════════════════════════════════
   FIXED HEADER + FOOTER LAYOUT (AdminLTE v4 grid-native)
   AdminLTE v4 uses CSS Grid: the sidebar is a full-height LEFT column,
   while header / main / footer stack in the RIGHT column. We must NOT
   override .app-wrapper or .app-sidebar positioning — that breaks the grid
   and is exactly what caused the sidebar to cover the header/footer.

   Instead we keep AdminLTE's grid and only make the header + footer sticky
   within the scrolling main column.
   ═════════════════════════════════════════════════════════════════════════ */

/* Header sticky to top (AdminLTE already does this; we keep z-index sane) */
.app-header {
    position: sticky;
    top: 0;
    z-index: 1035;   /* above sidebar's 1038? no — but in grid they don't overlap.
                        Kept high so dropdowns/overlays from header win. */
}

/* Footer sticky to bottom of the main column */
.app-footer {
    position: sticky;
    bottom: 0;
    z-index: 1020;
    padding: .55rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bs-body-bg, #fff);
    border-top: 1px solid var(--bs-border-color, #e9ecef);
    font-size: .82rem;
    color: var(--bs-secondary-color, #6c757d);
}
.app-footer .footer-text    { line-height: 1; }
.app-footer .footer-version { line-height: 1; opacity: .8; font-variant-numeric: tabular-nums; }

/* The main content column scrolls; the grid keeps sidebar fixed in place.
   .app-main already has overflow:auto from AdminLTE in .layout-fixed. */

/* Modal backdrop — semi-transparent, NOT dark/black */
.modal-backdrop {
    --bs-backdrop-bg: rgba(15, 23, 42, .35);
    background-color: rgba(15, 23, 42, .35);
}
.modal-backdrop.show {
    opacity: 1 !important;   /* override Bootstrap's .5 + dark bg */
}
[data-bs-theme="dark"] .modal-backdrop,
[data-bs-theme="dark"] .modal-backdrop.show {
    background-color: rgba(0, 0, 0, .55) !important;
}

/* ─── Page-title bar (AdminLTE-style, drop-in helper) ─────────────────── */
.page-title-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: .75rem;
    margin-bottom: 1.1rem;
    padding-bottom: .65rem;
    border-bottom: 1px solid #e3e6f0;
}
.page-title-bar .page-title-text {
    display: flex;
    align-items: center;
    gap: .55rem;
    margin: 0;
    font-weight: 600;
    font-size: 1.35rem;
    color: #1e2a38;
}
.page-title-bar .page-title-text i { color: #2563eb; font-size: 1.55rem; }
.page-title-bar h1,
.page-title-bar h2,
.page-title-bar h3,
.page-title-bar h4 { margin: 0; font-weight: 600; color: #1e2a38; }
.page-title-bar .breadcrumb {
    margin-bottom: 0;
    background: transparent;
    padding: 0;
    font-size: .8rem;
}
.page-title-bar .breadcrumb-item + .breadcrumb-item::before { color: #94a3b8; }
.page-title-bar .breadcrumb-item.active { color: #475569; }
.page-title-bar .page-actions {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
    margin-left: auto;       /* always push to the right */
    justify-content: flex-end;
    align-items: center;
}
.page-title-bar .page-subtitle {
    font-size: .8rem;
    color: #64748b;
    margin-top: .15rem;
}
@media (max-width: 767px) {
    .page-title-bar { gap: .55rem; }
    .page-title-bar .page-title-text { font-size: 1.1rem; }
    .page-title-bar .page-title-text i { font-size: 1.25rem; }
    .page-title-bar .page-actions {
        width: 100%;
        margin-left: 0;
        justify-content: flex-start;   /* on mobile, align left under the title */
    }
}

/* ─── AdminLTE-style card refinements ─────────────────────────────────── */
.card { border: 1px solid #e3e6f0; box-shadow: 0 1px 3px rgba(0,0,0,.04); }

/* Override AdminLTE 4's `.card-header > .card-tools { float:right }`.
   We want flex with space-between so title stays left and tools stay right
   without floats (floats interact badly with our flex container). */
.card > .card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e3e6f0;
    padding: .75rem 1.05rem;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center;
    flex-wrap: wrap;
    gap: .5rem;
}
/* Kill the legacy float that AdminLTE applies to .card-tools */
.card > .card-header > .card-tools {
    float: none !important;
    margin-right: 0 !important;
    margin-left: auto !important;     /* pushes the tools group to the right edge */
}
/* Remove the AdminLTE clearfix that adds an empty block sibling — it
   forces card-tools onto its own visual row inside our flex container */
.card > .card-header::after { content: none !important; display: none !important; }

.card > .card-header .card-title {
    margin: 0;
    font-size: .95rem;
    font-weight: 600;
    color: #1e2a38;
    display: flex;
    align-items: center;
    gap: .45rem;
}
.card > .card-header .card-title i { color: #2563eb; }
.card > .card-header .card-tools {
    display: flex;
    gap: .35rem;
    align-items: center;
    flex-wrap: nowrap;     /* keep tool buttons on one row on desktop */
}
.card > .card-header .card-tools .btn { padding: .25rem .55rem; font-size: .8rem; }
.card > .card-header .card-tools .btn-tool {
    background: transparent;
    border: none;
    color: #64748b;
    padding: .25rem .4rem;
    border-radius: .25rem;
}
.card > .card-header .card-tools .btn-tool:hover { background: rgba(0,0,0,.05); color: #1e2a38; }
@media (max-width: 575px) {
    /* Mobile: stack title above tools, but tools still right-aligned within row */
    .card > .card-header { flex-direction: column; align-items: stretch; gap: .4rem; }
    .card > .card-header > .card-tools {
        margin-left: 0 !important;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    .card > .card-header .card-tools .btn { flex: 0 1 auto; }
}
.card > .card-footer {
    background: #f8fafc;
    border-top: 1px solid #e3e6f0;
    padding: .65rem 1.05rem;
    font-size: .85rem;
}

/* ─── Form polish ─────────────────────────────────────────────────────── */
.form-label { font-weight: 500; color: #334155; margin-bottom: .35rem; font-size: .85rem; }
.form-label .required-mark { color: #dc2626; }
.form-text { color: #64748b; font-size: .78rem; }
.form-control, .form-select { font-size: .9rem; }
.input-group-text { background: #f1f5f9; color: #475569; font-size: .85rem; }

/* ─── Table polish ────────────────────────────────────────────────────── */
.table thead th {
    background: #f8fafc;
    color: #334155;
    font-weight: 600;
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .02em;
    border-bottom: 2px solid #e2e8f0;
}
.table tbody td { font-size: .88rem; vertical-align: middle; }
.table-hover tbody tr:hover { background-color: #f8fafc; }

/* ─── Action buttons in tables ────────────────────────────────────────── */
.table .btn-action {
    padding: .25rem .5rem;
    font-size: .78rem;
    line-height: 1.2;
}
.table .action-group { display: inline-flex; gap: .25rem; }

/* ─── Empty state ─────────────────────────────────────────────────────── */
.empty-state {
    padding: 3rem 1rem;
    text-align: center;
    color: #94a3b8;
}
.empty-state i { font-size: 3rem; margin-bottom: .5rem; display: block; opacity: .5; }
.empty-state .empty-title { font-weight: 600; color: #475569; margin: .5rem 0 .25rem; }
.empty-state .empty-text { font-size: .85rem; color: #64748b; max-width: 28rem; margin: 0 auto; }

/* ─── Stat boxes (dashboard / hub pages) ──────────────────────────────── */
.stat-box {
    background: #fff;
    border: 1px solid #e3e6f0;
    border-radius: .5rem;
    padding: 1.1rem 1.2rem;
    transition: transform .15s ease, box-shadow .15s ease;
    height: 100%;
}
.stat-box:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.06); }
.stat-box .stat-label { font-size: .8rem; color: #64748b; text-transform: uppercase; letter-spacing: .03em; font-weight: 600; }
.stat-box .stat-value { font-size: 1.55rem; font-weight: 700; color: #1e2a38; margin-top: .3rem; }
.stat-box .stat-icon {
    width: 42px; height: 42px;
    display: flex; align-items: center; justify-content: center;
    border-radius: .4rem;
    font-size: 1.2rem;
    background: rgba(37,99,235,.1);
    color: #2563eb;
}

/* ═════════════════════════════════════════════════════════════════════════
   DARK MODE — overrides for custom components (applies when
   <html data-bs-theme="dark">)
   Bootstrap 5.3 natively styles .card, .table, .form-control, .btn etc.,
   so we only need to fix our CUSTOM classes here.
   ═════════════════════════════════════════════════════════════════════════ */

/* ── Bootstrap 5.3 CSS variables — comprehensive dark overrides ────────── */
html[data-bs-theme="dark"],
[data-bs-theme="dark"] {
    --bs-body-bg:           #0f172a;
    --bs-body-color:        #e6edf3;
    --bs-body-color-rgb:    230, 237, 243;
    --bs-body-bg-rgb:       15, 23, 42;
    --bs-secondary-bg:      #1b2735;
    --bs-tertiary-bg:       #243042;
    --bs-emphasis-color:    #f1f5f9;
    --bs-secondary-color:   #cbd5e1;
    --bs-tertiary-color:    #94a3b8;
    --bs-border-color:      #2b3a4a;
    --bs-border-color-translucent: rgba(255,255,255,.12);
    --bs-link-color:        #4f9eff;
    --bs-link-hover-color:  #6cb0ff;
    --bs-heading-color:     #f1f5f9;
    --bs-form-control-bg:   #0f172a;
    --bs-form-control-color:#e6edf3;
    --bs-table-bg:          transparent;
    --bs-table-color:       #cbd5e1;
    --bs-table-border-color:#2b3a4a;
    --bs-card-bg:           #1b2735;
    --bs-card-color:        #e6edf3;
    --bs-card-border-color: #2b3a4a;
    --bs-card-cap-bg:       #1b2735;
    --bs-modal-bg:          #1b2735;
    --bs-modal-color:       #e6edf3;
    --bs-modal-border-color:#2b3a4a;
    --bs-modal-header-border-color: #2b3a4a;
    --bs-modal-footer-border-color: #2b3a4a;
    --bs-dropdown-bg:       #1b2735;
    --bs-dropdown-color:    #e6edf3;
    --bs-dropdown-border-color: #2b3a4a;
    --bs-dropdown-link-color: #cbd5e1;
    --bs-dropdown-link-hover-bg: rgba(79,158,255,.1);
    --bs-dropdown-link-hover-color: #f1f5f9;
    --bs-dropdown-divider-bg: #2b3a4a;
    --bs-list-group-bg:     #1b2735;
    --bs-list-group-color:  #cbd5e1;
    --bs-list-group-border-color: #2b3a4a;
    --bs-tooltip-bg:        #243042;
    --bs-popover-bg:        #1b2735;
    --bs-popover-border-color: #2b3a4a;
    --bs-popover-body-color: #cbd5e1;
    --bs-popover-header-bg: #243042;
    --bs-nav-link-color:    #cbd5e1;
    --bs-nav-tabs-link-active-bg: #1b2735;
    --bs-nav-tabs-link-active-border-color: #2b3a4a #2b3a4a #1b2735;
    --bs-nav-pills-link-active-bg: #4f9eff;
    --bs-pagination-bg:     #1b2735;
    --bs-pagination-color:  #cbd5e1;
    --bs-pagination-border-color: #2b3a4a;
    --bs-pagination-hover-bg: #243042;
    --bs-pagination-disabled-bg: #1b2735;
    --bs-pagination-active-bg: #4f9eff;
    color-scheme: dark;
}

/* ── Root-level dark mode background — no white bleeds through anywhere ─── */
html[data-bs-theme="dark"],
html[data-bs-theme="dark"] body,
[data-bs-theme="dark"] .app-wrapper,
[data-bs-theme="dark"] .app-main,
[data-bs-theme="dark"] .app-content,
[data-bs-theme="dark"] .content-wrapper,
[data-bs-theme="dark"] .content {
    background-color: #0f172a !important;
    color: #e6edf3;
}

/* Body text default in dark mode */
[data-bs-theme="dark"] body { color: #e6edf3; }

/* App header in dark mode */
[data-bs-theme="dark"] .app-header.navbar,
[data-bs-theme="dark"] .app-header {
    background-color: #1b2735 !important;
    border-bottom: 1px solid #2b3a4a;
}
[data-bs-theme="dark"] .app-header .nav-link { color: #cbd5e1; }
[data-bs-theme="dark"] .app-header .nav-link:hover { color: #e6edf3; }

/* App footer in dark mode */
[data-bs-theme="dark"] .app-footer {
    background-color: #1b2735 !important;
    border-top: 1px solid #2b3a4a;
    color: #94a3b8;
}

/* Dark mode scrollbar (WebKit only — Firefox uses native dark from color-scheme meta) */
[data-bs-theme="dark"] ::-webkit-scrollbar { width: 10px; height: 10px; }
[data-bs-theme="dark"] ::-webkit-scrollbar-track { background: #0f172a; }
[data-bs-theme="dark"] ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
[data-bs-theme="dark"] ::-webkit-scrollbar-thumb:hover { background: #475569; }

/* Page-title-bar */
[data-bs-theme="dark"] .page-title-bar { border-bottom-color: #2b3a4a; }
[data-bs-theme="dark"] .page-title-bar .page-title-text { color: #e6edf3; }
[data-bs-theme="dark"] .page-title-bar .page-title-text i { color: #4f9eff; }
[data-bs-theme="dark"] .page-title-bar h1, [data-bs-theme="dark"] .page-title-bar h2, [data-bs-theme="dark"] .page-title-bar h3, [data-bs-theme="dark"] .page-title-bar h4 { color: #e6edf3; }
[data-bs-theme="dark"] .page-title-bar .page-subtitle { color: #94a3b8; }
[data-bs-theme="dark"] .page-title-bar .breadcrumb-item.active { color: #cbd5e1; }
[data-bs-theme="dark"] .page-title-bar .breadcrumb-item + .breadcrumb-item::before { color: #475569; }
/* Form controls + labels (custom layer overrides the light defaults above) */
[data-bs-theme="dark"] .form-label { color: #cbd5e1; }
[data-bs-theme="dark"] .form-control, [data-bs-theme="dark"] .form-select {
        background-color: #0f172a;
        border-color: #2b3a4a;
        color: #e6edf3;
    }
[data-bs-theme="dark"] .form-control::placeholder { color: #64748b; }
[data-bs-theme="dark"] .form-control:focus, [data-bs-theme="dark"] .form-select:focus {
        background-color: #0f172a;
        border-color: #4f9eff;
        color: #e6edf3;
        box-shadow: 0 0 0 .2rem rgba(79,158,255,.18);
    }
[data-bs-theme="dark"] .form-select {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2394a3b8' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    }
[data-bs-theme="dark"] .input-group-text {
        background-color: #243042;
        border-color: #2b3a4a;
        color: #cbd5e1;
    }
[data-bs-theme="dark"] .form-check-input { background-color: #0f172a; border-color: #2b3a4a; }
[data-bs-theme="dark"] .form-check-input:checked { background-color: #4f9eff; border-color: #4f9eff; }
[data-bs-theme="dark"] textarea.form-control { background-color: #0f172a; color: #e6edf3; }
[data-bs-theme="dark"] .form-range::-webkit-slider-runnable-track { background: #2b3a4a; }
[data-bs-theme="dark"] .form-range::-moz-range-track { background: #2b3a4a; }
/* Cards (custom layer on top of Bootstrap dark) */
[data-bs-theme="dark"] .card { border-color: #2b3a4a; box-shadow: 0 1px 3px rgba(0,0,0,.25); background-color: #1b2735; }
[data-bs-theme="dark"] .card-body { background-color: #1b2735; color: #cbd5e1; }
[data-bs-theme="dark"] .card > .card-header { background: #1b2735; border-bottom-color: #2b3a4a; }
[data-bs-theme="dark"] .card > .card-header .card-title { color: #e6edf3; }
[data-bs-theme="dark"] .card > .card-header .card-title i { color: #4f9eff; }
[data-bs-theme="dark"] .card > .card-header .card-tools .btn-tool { color: #94a3b8; }
[data-bs-theme="dark"] .card > .card-header .card-tools .btn-tool:hover { background: rgba(255,255,255,.06); color: #e6edf3; }
[data-bs-theme="dark"] .card > .card-footer { background: #1b2735; border-top-color: #2b3a4a; }
/* Form polish */
[data-bs-theme="dark"] .form-label { color: #cbd5e1; }
[data-bs-theme="dark"] .form-text { color: #94a3b8; }
[data-bs-theme="dark"] .input-group-text { background: #1b2735; color: #cbd5e1; border-color: #2b3a4a; }
/* Table polish */
[data-bs-theme="dark"] .table { color: #cbd5e1; }
[data-bs-theme="dark"] .table > thead,
[data-bs-theme="dark"] .table thead { background: #1b2735 !important; }
[data-bs-theme="dark"] .table thead th { background: #1b2735; color: #cbd5e1; border-bottom-color: #2b3a4a; }
[data-bs-theme="dark"] .table > tbody > tr > td { border-bottom-color: #2b3a4a; }
[data-bs-theme="dark"] .table-hover tbody tr:hover { background-color: rgba(255,255,255,.04); }
[data-bs-theme="dark"] .table-hover > tbody > tr:hover > * { background-color: rgba(79,158,255,.08); --bs-table-hover-bg: rgba(79,158,255,.08); }
[data-bs-theme="dark"] .table-striped > tbody > tr:nth-of-type(odd) > * { background-color: rgba(255,255,255,.025); }
[data-bs-theme="dark"] .table-light { --bs-table-bg: #1b2735; --bs-table-color: #cbd5e1; }
[data-bs-theme="dark"] thead.table-light { background: #1b2735 !important; color: #cbd5e1 !important; }
/* Stat box (dashboard) */
[data-bs-theme="dark"] .stat-box { background: #1b2735; border-color: #2b3a4a; }
[data-bs-theme="dark"] .stat-box:hover { box-shadow: 0 6px 16px rgba(0,0,0,.35); }
[data-bs-theme="dark"] .stat-box .stat-label { color: #94a3b8; }
[data-bs-theme="dark"] .stat-box .stat-value { color: #e6edf3; }
/* Empty state */
[data-bs-theme="dark"] .empty-state { color: #475569; }
[data-bs-theme="dark"] .empty-state .empty-title { color: #cbd5e1; }
[data-bs-theme="dark"] .empty-state .empty-text { color: #94a3b8; }
/* DataTables wrapper text */
[data-bs-theme="dark"] .dataTables_wrapper .dataTables_length, [data-bs-theme="dark"] .dataTables_wrapper .dataTables_filter, [data-bs-theme="dark"] .dataTables_wrapper .dataTables_info, [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate { color: #cbd5e1; }
[data-bs-theme="dark"] .dataTables_wrapper .dataTables_length select, [data-bs-theme="dark"] .dataTables_wrapper .dataTables_filter input {
        background-color: #0f172a; color: #e6edf3; border-color: #2b3a4a;
    }
[data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button {
        color: #cbd5e1 !important;
    }
[data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button.current, [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
        background: #2b3a4a !important; color: #e6edf3 !important; border-color: #2b3a4a !important;
    }
[data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: rgba(255,255,255,.06) !important; border-color: #2b3a4a !important;
    }
[data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button.disabled, [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
        color: #475569 !important;
    }
/* Navbar theme-toggle icon glow */
[data-bs-theme="dark"] .app-header #bltxThemeToggle .bi-sun-fill { color: #fbbf24; }

/* ─── Page-specific dark mode patches ───────────────────────────────────
   Some pages have hard-coded background:#fff in their inline <style>.
   These overrides target those specific selectors in dark mode only,
   so the visual remains coherent without touching the page-level CSS. */
/* Dashboard period filter inputs */
[data-bs-theme="dark"] .pfbar select:focus, [data-bs-theme="dark"] .pfbar input:focus { background: #0f172a; color: #e6edf3; }
/* arw_pos manual_dreport — view modal cards */
[data-bs-theme="dark"] .ymdr-card, [data-bs-theme="dark"] .dreport-card-detail, [data-bs-theme="dark"] .ymdr-section, [data-bs-theme="dark"] .dreport-card, [data-bs-theme="dark"] .ycsv-card, [data-bs-theme="dark"] .yc-detail-card { background: #1b2735; color: #cbd5e1; border-color: #2b3a4a; }
/* Shift schedules calendar */
[data-bs-theme="dark"] .shift-calendar .cal-day { background: #1b2735; border-color: #2b3a4a; color: #cbd5e1; }
[data-bs-theme="dark"] .shift-calendar .cal-day.day-off { background: #2b1f1f; }
/* Inventory take_items + in_items — card panels */
[data-bs-theme="dark"] .ti-card, [data-bs-theme="dark"] .in-card, [data-bs-theme="dark"] .ti-form-card, [data-bs-theme="dark"] .ti-summary-card, [data-bs-theme="dark"] .in-form-card, [data-bs-theme="dark"] .in-summary-card { background: #1b2735; color: #cbd5e1; border-color: #2b3a4a; }
/* Content header / breadcrumb area (white in dark mode → dark) */
[data-bs-theme="dark"] .app-content-header,
[data-bs-theme="dark"] .content-header,
[data-bs-theme="dark"] .content-header-wrapper {
    background-color: #0f172a !important;
    color: #cbd5e1 !important;
}
[data-bs-theme="dark"] .breadcrumb {
    background-color: transparent !important;
    color: #cbd5e1;
    --bs-breadcrumb-divider-color: #475569;
}
[data-bs-theme="dark"] .breadcrumb-item { color: #94a3b8; }
[data-bs-theme="dark"] .breadcrumb-item a { color: #4f9eff; text-decoration: none; }
[data-bs-theme="dark"] .breadcrumb-item a:hover { color: #6cb0ff; }
[data-bs-theme="dark"] .breadcrumb-item.active { color: #cbd5e1; }
[data-bs-theme="dark"] .breadcrumb-item + .breadcrumb-item::before { color: #475569; }
[data-bs-theme="dark"] .bltx-header-bc .breadcrumb-item a { color: #94a3b8; }
[data-bs-theme="dark"] .bltx-header-bc .breadcrumb-item a:hover { color: #4f9eff; }
[data-bs-theme="dark"] .bltx-header-bc .breadcrumb-item.active { color: #e6edf3; }
[data-bs-theme="dark"] .bltx-header-bc .breadcrumb-item + .breadcrumb-item::before { color: #475569; }
/* Dropdown menus and offcanvas (Bootstrap-native but sometimes overridden) */
[data-bs-theme="dark"] .dropdown-menu { background: #1b2735; border-color: #2b3a4a; }
[data-bs-theme="dark"] .dropdown-item { color: #cbd5e1; }
[data-bs-theme="dark"] .dropdown-item:hover, [data-bs-theme="dark"] .dropdown-item:focus { background: rgba(255,255,255,.06); color: #e6edf3; }
[data-bs-theme="dark"] .dropdown-divider { border-top-color: #2b3a4a; }
/* Modal */
[data-bs-theme="dark"] .modal-content { background: #1b2735; color: #cbd5e1; border-color: #2b3a4a; }
[data-bs-theme="dark"] .modal-header, [data-bs-theme="dark"] .modal-footer { border-color: #2b3a4a; background: #1b2735; }
[data-bs-theme="dark"] .modal-body { background: #1b2735; color: #cbd5e1; }
[data-bs-theme="dark"] .modal-header.bg-light { background: #1b2735 !important; }
/* Universal: any .bg-white in dark mode should use card background */
[data-bs-theme="dark"] .bg-white, [data-bs-theme="dark"] .card-header.bg-white {
        background: #1b2735 !important;
        color: #e6edf3 !important;
    }
/* bg-light (light gray) — adapt to dark surface */
[data-bs-theme="dark"] .bg-light, [data-bs-theme="dark"] .card.bg-light, [data-bs-theme="dark"] .card-header.bg-light, [data-bs-theme="dark"] .form-control.bg-light {
        background-color: #243042 !important;
        color: #e6edf3 !important;
    }
[data-bs-theme="dark"] .modal-footer.bg-light { background-color: #1b2735 !important; }
/* But preserve white badges that are intentionally white pills */
[data-bs-theme="dark"] .badge.bg-white { background: rgba(255,255,255,.95) !important; }
[data-bs-theme="dark"] .badge.bg-light.text-dark { background: #2b3a4a !important; color: #e6edf3 !important; }
/* Alerts: brighten text and use translucent bg in dark mode */
[data-bs-theme="dark"] .alert-success { background: rgba(25, 135, 84, .15); color: #6ee7b7; border-color: rgba(25, 135, 84, .35); }
[data-bs-theme="dark"] .alert-danger { background: rgba(220, 53, 69, .15); color: #fca5a5; border-color: rgba(220, 53, 69, .35); }
[data-bs-theme="dark"] .alert-warning { background: rgba(255, 193, 7, .15); color: #fcd34d; border-color: rgba(255, 193, 7, .35); }
[data-bs-theme="dark"] .alert-info { background: rgba(13, 202, 240, .15); color: #93c5fd; border-color: rgba(13, 202, 240, .35); }
/* SweetAlert2 popup */
[data-bs-theme="dark"] .swal2-popup { background: #1b2735; color: #cbd5e1; }
[data-bs-theme="dark"] .swal2-title { color: #e6edf3; }
[data-bs-theme="dark"] .swal2-html-container { color: #cbd5e1; }

/* ─── Theme transition (smooth swap, except on first paint) ─────────────── */
html.bltx-theme-ready,
html.bltx-theme-ready body,
html.bltx-theme-ready .card,
html.bltx-theme-ready .app-header,
html.bltx-theme-ready .app-sidebar,
html.bltx-theme-ready .table,
html.bltx-theme-ready .form-control,
html.bltx-theme-ready .form-select,
html.bltx-theme-ready .page-title-bar { transition: background-color .2s ease, color .2s ease, border-color .2s ease; }

/* ─── Rel ikon: ikon submenu lurus di bawah ikon menu induknya ─────────
   Saat sidebar tertutup, AdminLTE memaksa rel selebar 3,1rem dan setiap
   .nav-link selebar 2,1rem. Padding kiri submenu yang 2,6rem (indentasi
   yang disengaja untuk mode terbuka) jadi LEBIH BESAR dari lebar
   nav-link itu sendiri, sehingga ruang isinya habis dan ikon submenu
   terdorong keluar — tidak lagi sebaris dengan ikon induknya.

   Di sini padding kiri submenu disamakan dengan induknya (1,1rem, lihat
   .sidebar-menu .nav-link di atas) sehingga kedua ikon berbagi sumbu
   vertikal yang sama.

   Dibatasi :not(:hover) — begitu rel di-hover dan melebar, indentasi
   2,6rem berlaku kembali. Tampilan sidebar terbuka tidak berubah. */
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu .nav-treeview .nav-link {
    padding-left: 1.1rem;
    padding-right: 0;
}

/* ─── PEMUSATAN IKON SAAT SIDEBAR MENYUSUT (menyeluruh) ─────────────────
   Penelusuran lewat DevTools menunjukkan rantainya begini saat menyusut:

     .app-sidebar (rel, lebar penuh)
       └ .sidebar-wrapper
           └ nav.mt-2
               └ ul.sidebar-menu   <- flex column, align-items bawaan
                   └ li.nav-item   <- lebar 38,58px, menempel KIRI
                       └ a.nav-link

   Ruang kosong di kanan muncul karena salah satu lapisan itu lebih sempit
   dari rel dan rata kiri. Alih-alih menebak lapisan mana (tiga percobaan
   sebelumnya menebak .nav-link dan meleset), aturan di bawah memaksa
   SELURUH rantai selebar rel lalu memusatkan isinya.

   Aman karena dibatasi .sidebar-collapse:not(:hover) — hanya berlaku saat
   menyusut. Tampilan sidebar terbuka tidak tersentuh sama sekali. */

.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-wrapper,
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-wrapper > nav,
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu,
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu > .nav-item {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}
/* ul sebagai flex column: paksa anaknya membentang penuh. */
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu {
    align-items: stretch !important;
}
/* nav-link mengisi penuh li, isinya dipusatkan. */
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu > .nav-item > .nav-link {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    gap: 0 !important;
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    /* border kiri 3px adalah penanda menu aktif — dipertahankan, lalu
       diimbangi border kanan transparan supaya isinya tepat di tengah. */
    border-right: 3px solid transparent !important;
}
/* Ikon: lepas lebar tetap 20px dan margin agar benar-benar terpusat. */
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu > .nav-item > .nav-link .nav-icon,
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-menu > .nav-item > .nav-link > i:first-child {
    width: auto !important;
    margin: 0 !important;
    flex: 0 0 auto !important;
}
/* Brand ikut dipusatkan. */
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-brand,
.sidebar-mini.sidebar-collapse .app-sidebar:not(:hover) .sidebar-brand .brand-link {
    width: 100% !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    justify-content: center !important;
}


/* ─── Sidebar collapse arrow flips ────────────────────────────────────── */
[data-lte-toggle="sidebar"]    { cursor: pointer; }

/* ─── No flash on first load when sidebar is collapsed by preference ──── */
html.sidebar-collapse-init body.sidebar-collapse,
html.sidebar-collapse-init .app-sidebar,
html.sidebar-collapse-init .app-header,
html.sidebar-collapse-init .app-main,
html.sidebar-collapse-init .app-footer {
    transition: none !important;
}

/* ═════════════════════════════════════════════════════════════════════════
   GLOBAL SQUARE CORNERS — per user request: semua card, tabel, tombol,
   kolom, sisinya tidak rounded tapi square.
   ═════════════════════════════════════════════════════════════════════════ */
.card,
.card-header,
.card-body,
.card-footer,
.btn,
.btn-sm,
.btn-lg,
.btn-group,
.btn-group > .btn,
.form-control,
.form-select,
.form-control-sm,
.form-select-sm,
.input-group,
.input-group-text,
.input-group > *,
.modal-content,
.modal-header,
.modal-body,
.modal-footer,
.alert,
.badge,
.dropdown-menu,
.list-group,
.list-group-item,
.nav-pills .nav-link,
.nav-tabs .nav-link,
.page-link,
.pagination,
.table,
.table th,
.table td,
.table-responsive,
.progress,
.progress-bar,
.toast,
.popover,
.tooltip-inner,
.breadcrumb,
.swal2-popup,
.swal2-styled,
.select2-container--default .select2-selection,
.select2-dropdown,
.dataTables_wrapper .dataTables_filter input,
.dataTables_wrapper .dataTables_length select,
.dataTables_paginate .paginate_button {
    border-radius: 0 !important;
}
/* Login card already square via inline override in includes/login.php */

/* ═════════════════════════════════════════════════════════════════════════
   AGGRESSIVE DARK MODE CATCH-ALL
   These rules override page-specific inline <style> blocks that hardcode
   light colors. They target common custom class names used across the
   project so we don't need to edit each page individually.
   ═════════════════════════════════════════════════════════════════════════ */
[data-bs-theme="dark"] .cart-section,
[data-bs-theme="dark"] .cart-item,
[data-bs-theme="dark"] .loading-content,
[data-bs-theme="dark"] .toast,
[data-bs-theme="dark"] .scanner-container,
[data-bs-theme="dark"] .scan-result,
[data-bs-theme="dark"] .scan-input-box,
[data-bs-theme="dark"] .filter-section,
[data-bs-theme="dark"] .quick-stat,
[data-bs-theme="dark"] .summary-row,
[data-bs-theme="dark"] .info-row,
[data-bs-theme="dark"] .item-box,
[data-bs-theme="dark"] .item-card,
[data-bs-theme="dark"] .panel-box,
[data-bs-theme="dark"] .data-row,
[data-bs-theme="dark"] .summary-box,
[data-bs-theme="dark"] .stat-box,
[data-bs-theme="dark"] .number-box,
[data-bs-theme="dark"] .total-box,
[data-bs-theme="dark"] .info-box,
[data-bs-theme="dark"] .receipt-section,
[data-bs-theme="dark"] .calc-section,
[data-bs-theme="dark"] .yc-section,
[data-bs-theme="dark"] .ti-section,
[data-bs-theme="dark"] .in-section {
    background-color: #1b2735 !important;
    color: #cbd5e1 !important;
    border-color: #2b3a4a !important;
}

/* Anything explicitly hardcoded white in inline styles — last-resort */
[data-bs-theme="dark"] [style*="background:#fff"],
[data-bs-theme="dark"] [style*="background: #fff"],
[data-bs-theme="dark"] [style*="background:#FFFFFF"],
[data-bs-theme="dark"] [style*="background:#ffffff"],
[data-bs-theme="dark"] [style*="background:white"],
[data-bs-theme="dark"] [style*="background-color:#fff"],
[data-bs-theme="dark"] [style*="background-color: #fff"],
[data-bs-theme="dark"] [style*="background-color:#ffffff"],
[data-bs-theme="dark"] [style*="background-color:white"] {
    background-color: #1b2735 !important;
}

/* Specifically catch the very common #f8f9fa / #f1f5f9 / #e2e8f0 light bgs */
[data-bs-theme="dark"] [style*="background:#f8f9fa"],
[data-bs-theme="dark"] [style*="background-color:#f8f9fa"],
[data-bs-theme="dark"] [style*="background:#f1f5f9"],
[data-bs-theme="dark"] [style*="background-color:#f1f5f9"],
[data-bs-theme="dark"] [style*="background:#fafafa"],
[data-bs-theme="dark"] [style*="background-color:#fafafa"] {
    background-color: #243042 !important;
}
</style>

<?php if (isset($extra_css)) echo $extra_css; ?>

<?php
/* Skrip pra-terap berbasis localStorage DIHAPUS.
   Skrip itu berada di dalam <head>, dan di titik itu document.body masih
   null sehingga baris `document.body && document.body.classList.add(...)`
   tidak pernah dijalankan — kode mati. Penggantinya: kelas sidebar-collapse
   pada <body> dan sidebar-collapse-init pada <html> kini sama-sama dirender
   server dari $_sb_collapsed, jadi sudah benar sejak byte pertama tanpa
   perlu JavaScript sama sekali. */
?>
</head>

<?php
/* KELAS `sidebar-mini` WAJIB ADA — ini inti perbaikannya.
   addSidebarBreakPoint() pada adminlte.min.js dijalankan setiap DOM siap
   dan setiap resize, dengan logika (versi rapi dari kode terminifikasi):

       innerWidth <= breakpoint
         ? collapse()
         : ( !body.contains('sidebar-mini') && expand(),
             body.contains('sidebar-mini') && body.contains('sidebar-collapse') && collapse() )

   Tanpa `sidebar-mini`, cabang kedua bernilai true sehingga expand()
   dipanggil TANPA SYARAT di setiap load. expand() menghapus
   `sidebar-collapse` dan menambah `sidebar-open`, sehingga:
     1. sidebar yang dirender tertutup dibuka paksa  -> terlihat berkedip,
     2. preferensi tertutup tidak pernah bertahan,
     3. perubahan kelas itu memicu MutationObserver di footer.php, yang lalu
        MENIMPA cookie menjadi '0' — preferensi pengguna terhapus sendiri.
   Dengan `sidebar-mini`, expand() tidak pernah dipanggil dan justru
   collapse() yang dijalankan saat cookie menyatakan tertutup.

   `sidebar-open` kini BERSYARAT. Sebelumnya ditulis permanen, sehingga saat
   tertutup body memuat `sidebar-open` DAN `sidebar-collapse` sekaligus —
   dua kelas yang saling bertentangan. */
?>
<body class="layout-fixed sidebar-expand-lg sidebar-mini<?php echo $_sb_collapsed ? ' sidebar-collapse' : ' sidebar-open'; ?>">
<?php if ($_sb_collapsed): ?>
<script>
/* `sidebar-collapse-init` hanya bertugas MEREDAM transisi pada lukisan
   pertama, supaya sidebar yang memang tertutup tidak terlihat beranimasi
   menutup saat halaman dibuka. Setelah lukisan pertama selesai, kelas itu
   HARUS dilepas — kalau dibiarkan, transisi ikut mati selamanya dan tombol
   buka/tutup sidebar akan terasa mematah (langsung meloncat tanpa animasi).
   requestFrame ganda memastikan pelepasan terjadi setelah frame pertama
   benar-benar dilukis. */
(function () {
    var el = document.documentElement;
    function release() { el.classList.remove('sidebar-collapse-init'); }
    if (window.requestAnimationFrame) {
        requestAnimationFrame(function () { requestAnimationFrame(release); });
    } else {
        setTimeout(release, 50);
    }
})();
</script>
<?php endif; ?>

<!-- ════════════════ App Wrapper ════════════════ -->
<div class="app-wrapper">

  <!-- ════════════════ App Header ════════════════ -->
  <nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">

      <!-- Sidebar toggle (AdminLTE handles it via data-lte-toggle) -->
      <ul class="navbar-nav">
        <li class="nav-item">
          <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Toggle Sidebar">
            <i class="bi bi-list" style="font-size:1.35rem;"></i>
          </a>
        </li>
      </ul>

      <?php
      // ─── Dynamic breadcrumb (Home / [Module] / [Page]) ───────────────────
      // Maps the current_page to a breadcrumb trail using the lang file.
      $_bc_map = [
          // page_basename => [parent_lang_key, current_lang_key]
          'dashboard'           => [null, 'dashboard'],
          // Hub Purchase. Entri 'purchases' LAMA sengaja dipertahankan (bukan
          // dihapus) agar tautan lama yang belum terlacak tidak kehilangan
          // breadcrumb-nya. Elemen KETIGA = URL induk; hanya entri Purchase
          // yang memilikinya, sehingga modul lain tetap menampilkan induk
          // sebagai teks biasa dan perilakunya sama sekali tidak berubah.
          'pr_dahsboard_gate'   => ['purchases', 'purchase_order', 'purchases/pr_dahsboard_gate.php'],
          'purchases'           => ['purchases', 'purchase_invoice'],
          'purchase_add'        => ['purchases', 'add_purchase', 'purchases/pr_dahsboard_gate.php'],
          'purchase_edit'       => ['purchases', 'edit_purchase', 'purchases/pr_dahsboard_gate.php'],
          'purchase_view'       => ['purchases', 'view', 'purchases/pr_dahsboard_gate.php'],
          'po_add'              => ['purchases', 'add_purchase_order', 'purchases/pr_dahsboard_gate.php'],
          'po_edit'             => ['purchases', 'edit_purchase_order', 'purchases/pr_dahsboard_gate.php'],
          'po_view'             => ['purchases', 'view', 'purchases/pr_dahsboard_gate.php'],
          'suppliers'           => ['purchases', 'suppliers', 'purchases/pr_dahsboard_gate.php'],
          'categories'          => ['purchases', 'categories', 'purchases/pr_dahsboard_gate.php'],
          'units'               => ['purchases', 'units', 'purchases/pr_dahsboard_gate.php'],
          'returns'             => ['purchases', 'returns', 'purchases/pr_dahsboard_gate.php'],
          'goods_receipt'       => ['purchases', 'goods_receipt', 'purchases/pr_dahsboard_gate.php'],
          'goods_receipt_add'   => ['purchases', 'gr_add', 'purchases/pr_dahsboard_gate.php'],
          'goods_receipt_edit'  => ['purchases', 'gr_edit', 'purchases/pr_dahsboard_gate.php'],
          'goods_receipt_view'  => ['purchases', 'view', 'purchases/pr_dahsboard_gate.php'],
          'purchase_return_add'  => ['purchases', 'pr_add', 'purchases/pr_dahsboard_gate.php'],
          'purchase_return_edit' => ['purchases', 'pr_edit', 'purchases/pr_dahsboard_gate.php'],
          'purchase_return_view' => ['purchases', 'view', 'purchases/pr_dahsboard_gate.php'],
          // Hub Inventory. Elemen KETIGA = URL induk yang bisa diklik,
          // pola yang sama persis dengan hub Purchase di atas.
          'inventory'           => [null, 'inventory'],
          'inventory_view'      => ['inventory', 'view',              'inventory/inventory_gate.php?tab=inventory_data'],
          'inventory_edit'      => ['inventory', 'edit',              'inventory/inventory_gate.php?tab=inventory_data'],
          'print_barcode'       => ['inventory', 'print',             'inventory/inventory_gate.php?tab=inventory_data'],
          'stock_opname'        => ['inventory', 'stock_opname',      'inventory/inventory_gate.php?tab=inventory_data'],
          'stock_opname_view'   => ['inventory', 'view',              'inventory/inventory_gate.php?tab=inventory_data'],
          'stock_opname_print'  => ['inventory', 'print',             'inventory/inventory_gate.php?tab=inventory_data'],
          'take_items'          => ['inventory', 'take_items',        'inventory/inventory_gate.php?tab=inventory_data'],
          'in_items'            => ['inventory', 'added_items',       'inventory/inventory_gate.php?tab=inventory_data'],
          'employees'           => ['hr_payroll', 'employees',        'payroll/payroll_gate.php'],
          'employee_add'        => ['hr_payroll', 'add_employee',     'payroll/payroll_gate.php?tab=employees'],
          'employee_edit'       => ['hr_payroll', 'edit_employee',    'payroll/payroll_gate.php?tab=employees'],
          'employee_view'       => ['hr_payroll', 'view',             'payroll/payroll_gate.php?tab=employees'],
          'attendance'          => ['hr_payroll', 'attendance',       'payroll/payroll_gate.php?tab=attendance'],
          'shifts'              => ['hr_payroll', 'shifts',           'payroll/payroll_gate.php?tab=shifts'],
          'shift_schedules'     => ['hr_payroll', 'shift_schedules',  'payroll/payroll_gate.php?tab=shift_schedules'],
          'holidays'            => ['hr_payroll', 'holidays',         'payroll/payroll_gate.php?tab=holidays'],
          'leave_requests'      => ['hr_payroll', 'leave_requests',   'payroll/payroll_gate.php?tab=leave_requests'],
          'cash_advances'       => ['hr_payroll', 'cash_advances',    'payroll/payroll_gate.php?tab=cash_advances'],
          'kpi_employees'       => ['hr_payroll', 'kpi_employees',    'payroll/payroll_gate.php?tab=kpi'],
          'kpi_indicators'      => ['hr_payroll', 'kpi_manage_indicators', 'payroll/payroll_gate.php?tab=kpi'],
          'kpi_periods'         => ['hr_payroll', 'kpi_manage_periods',    'payroll/payroll_gate.php?tab=kpi'],
          'kpi_evaluate'        => ['hr_payroll', 'kpi_evaluate',      'payroll/payroll_gate.php?tab=kpi'],
          'kpi_view'            => ['hr_payroll', 'kpi_view',          'payroll/payroll_gate.php?tab=kpi'],
          'departments'         => ['hr_payroll', 'departments',       'payroll/payroll_gate.php?tab=departments'],
          'positions'           => ['hr_payroll', 'positions',         'payroll/payroll_gate.php?tab=positions'],
          'religions'           => ['hr_payroll', 'religions',         'payroll/payroll_gate.php?tab=religions'],
          'payroll_index'       => ['hr_payroll', 'payroll_dashboard', 'payroll/payroll_gate.php'],
          'payroll_periods'     => ['hr_payroll', 'payroll_periods',   'payroll/payroll_gate.php?tab=payroll_periods'],
          'payroll_list'        => ['hr_payroll', 'payroll_list',      'payroll/payroll_gate.php?tab=payroll_list'],
          'payroll_calculate'   => ['hr_payroll', 'payroll',           'payroll/payroll_gate.php?tab=payroll_periods'],
          'payroll_report'      => ['hr_payroll', 'payroll_report',    'payroll/payroll_gate.php?tab=payroll_list'],
          'payroll_settings'    => ['hr_payroll', 'payroll_settings',  'payroll/payroll_gate.php?tab=payroll_settings'],
          'settings'            => [null, 'settings'],
          'users'               => ['settings', 'users'],
          'profile_user'        => ['settings', 'profile'],
      ];

      // Hub pages: when current_page === hub key, look at ?tab= to refine
      $_bc_hub_tabs = [
          // Hub Inventory (inventory_gate.php). Elemen KETIGA = URL induk
          // sehingga breadcrumb "Inventory" bisa diklik dan kembali ke hub.
          'inventory' => [
              'inventory_data'     => ['inventory', 'inventory_data',     'inventory/inventory_gate.php?tab=inventory_data'],
              'inventory_in'       => ['inventory', 'inventory_in',       'inventory/inventory_gate.php?tab=inventory_data'],
              'inventory_out'      => ['inventory', 'inventory_out',      'inventory/inventory_gate.php?tab=inventory_data'],
              'stock_opname'       => ['inventory', 'stock_opname',       'inventory/inventory_gate.php?tab=inventory_data'],
              'warehouse_location' => ['inventory', 'warehouse_location', 'inventory/inventory_gate.php?tab=inventory_data'],
              'take_items'         => ['inventory', 'take_items',         'inventory/inventory_gate.php?tab=inventory_data'],
              'in_items'           => ['inventory', 'in_items',           'inventory/inventory_gate.php?tab=inventory_data'],
          ],
          'purchases' => [
              'invoice'        => ['purchases', 'purchase_invoice'],
              'order'          => ['purchases', 'purchase_order'],
              'goods_receipt'  => ['purchases', 'goods_receipt'],
              'returns'        => ['purchases', 'returns'],
          ],
          // Hub Purchase yang aktif. Memuat KETUJUH tab — tiga terakhir
          // (Suppliers/Categories/Units) belum pernah ada di peta lama karena
          // dulu ketiganya halaman lepas, bukan tab.
          'pr_dahsboard_gate' => [
              'order'          => ['purchases', 'purchase_order',   'purchases/pr_dahsboard_gate.php'],
              'goods_receipt'  => ['purchases', 'goods_receipt',    'purchases/pr_dahsboard_gate.php'],
              'invoice'        => ['purchases', 'purchase_invoice', 'purchases/pr_dahsboard_gate.php'],
              'returns'        => ['purchases', 'returns',          'purchases/pr_dahsboard_gate.php'],
              'suppliers'      => ['purchases', 'suppliers',        'purchases/pr_dahsboard_gate.php'],
              'categories'     => ['purchases', 'categories',       'purchases/pr_dahsboard_gate.php'],
              'units'          => ['purchases', 'units',            'purchases/pr_dahsboard_gate.php'],
          ],
          // Hub HR & Payroll. Elemen KETIGA = URL induk yang bisa diklik,
          // pola sama persis dengan hub Purchase & Inventory di atas.
          'payroll_gate' => [
              'dashboard'        => ['hr_payroll', 'payroll_dashboard', 'payroll/payroll_gate.php'],
              'employees'        => ['hr_payroll', 'employees',         'payroll/payroll_gate.php'],
              'attendance'       => ['hr_payroll', 'attendance',        'payroll/payroll_gate.php'],
              'shifts'           => ['hr_payroll', 'shifts',            'payroll/payroll_gate.php'],
              'shift_schedules'  => ['hr_payroll', 'shift_schedules',   'payroll/payroll_gate.php'],
              'holidays'         => ['hr_payroll', 'holidays',          'payroll/payroll_gate.php'],
              'payroll_periods'  => ['hr_payroll', 'payroll_periods',   'payroll/payroll_gate.php'],
              'payroll_list'     => ['hr_payroll', 'payroll_list',      'payroll/payroll_gate.php'],
              'leave_requests'   => ['hr_payroll', 'leave_requests',    'payroll/payroll_gate.php'],
              'cash_advances'    => ['hr_payroll', 'cash_advances',     'payroll/payroll_gate.php'],
              'kpi'              => ['hr_payroll', 'kpi_employees',     'payroll/payroll_gate.php'],
              'departments'      => ['hr_payroll', 'departments',       'payroll/payroll_gate.php'],
              'positions'        => ['hr_payroll', 'positions',         'payroll/payroll_gate.php'],
              'religions'        => ['hr_payroll', 'religions',         'payroll/payroll_gate.php'],
              'payroll_settings' => ['hr_payroll', 'payroll_settings',  'payroll/payroll_gate.php'],
              'salary_components' => ['hr_payroll', 'salary_components', 'payroll/payroll_gate.php'],
              'org_chart'         => ['hr_payroll', 'org_chart', 'payroll/payroll_gate.php'],
              'approvals'         => ['hr_payroll', 'approvals', 'payroll/payroll_gate.php'],
              'tax_settings'      => ['hr_payroll', 'tax_brackets', 'payroll/payroll_gate.php'],
              'assets'            => ['hr_payroll', 'assets', 'payroll/payroll_gate.php'],
              'recruitment'       => ['hr_payroll', 'recruitment', 'payroll/payroll_gate.php'],
              'onboarding'        => ['hr_payroll', 'onboarding_tasks', 'payroll/payroll_gate.php'],
              'appraisal'         => ['hr_payroll', 'appraisal', 'payroll/payroll_gate.php'],
              'attendance_import' => ['hr_payroll', 'attendance_import', 'payroll/payroll_gate.php'],
              'payslip_email'     => ['hr_payroll', 'payslip_email', 'payroll/payroll_gate.php'],
              'employee_portal'   => ['hr_payroll', 'employee_portal', 'payroll/payroll_gate.php'],
          ],
          'settings' => [
              'general'     => ['settings', 'general'],
              'purchase'    => ['settings', 'purchase_settings'],
              'users'       => ['settings', 'users'],
              'currency'    => ['settings', 'currency'],
              'news'        => ['settings', 'news'],
              'activity'    => ['settings', 'recent_activity_logs'],
              'permissions' => ['settings', 'permissions'],
              'backup_database' => ['settings', 'backup_database'],
          ],
      ];

      $_bc = $_bc_map[$current_page] ?? [null, null];
      // Perhalus lewat ?tab=.
      // $__bc_tab didahulukan: hub Purchase menitipkan tab yang BENAR-BENAR
      // ditampilkan, karena pada URL tanpa ?tab= halaman jatuh ke tab pertama
      // yang diizinkan — dan tab itu berbeda-beda per user sesuai permission.
      // $_GET['tab'] tetap dipakai sebagai cadangan untuk hub lain.
      if (isset($_bc_hub_tabs[$current_page])) {
          $_active_tab = (isset($__bc_tab) && $__bc_tab !== '')
                       ? (string)$__bc_tab
                       : (string)($_GET['tab'] ?? '');
          if ($_active_tab !== '' && isset($_bc_hub_tabs[$current_page][$_active_tab])) {
              $_bc = $_bc_hub_tabs[$current_page][$_active_tab];
          }
      }
      // Elemen ketiga (opsional) = URL induk. Hanya entri Purchase yang
      // mengisinya, sehingga induk breadcrumb modul lain tetap teks biasa.
      $_bc_parent_url = $_bc[2] ?? null;
      ?>
      <ol class="breadcrumb d-none d-md-flex mb-0 ms-2 align-self-center bltx-header-bc">
        <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>"><i class="bi bi-house-door"></i> <?= htmlspecialchars($LANG['home']) ?></a></li>
        <?php if (!empty($_bc[0])): ?>
          <?php if ($_bc_parent_url !== null): ?>
            <li class="breadcrumb-item"><a href="<?= url($_bc_parent_url) ?>"><?= htmlspecialchars($LANG[$_bc[0]]) ?></a></li>
          <?php else: ?>
            <li class="breadcrumb-item"><?= htmlspecialchars($LANG[$_bc[0]]) ?></li>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($_bc[1])): ?>
          <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($LANG[$_bc[1]]) ?></li>
        <?php endif; ?>
      </ol>

      <!-- Right side -->
      <ul class="navbar-nav ms-auto">

        <!-- Theme toggle (dark/light) -->
        <li class="nav-item">
          <a class="nav-link" href="#" role="button" id="bltxThemeToggle"
             aria-label="Toggle theme" data-current-theme="<?php echo $_theme; ?>"
             title="<?php echo htmlspecialchars($LANG['toggle_theme']); ?>">
            <?php if ($_theme === 'dark'): ?>
              <i class="bi bi-sun-fill" id="bltxThemeIcon"></i>
            <?php else: ?>
              <i class="bi bi-moon-stars-fill" id="bltxThemeIcon"></i>
            <?php endif; ?>
          </a>
        </li>

        <!-- Language -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-1" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-translate"></i>
            <span class="d-none d-sm-inline"><?php echo strtoupper($_SESSION['lang']); ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_bltx_switch_base, ENT_QUOTES, 'UTF-8'); ?>lang=en"><i class="bi bi-globe"></i> English</a></li>
            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_bltx_switch_base, ENT_QUOTES, 'UTF-8'); ?>lang=id"><i class="bi bi-globe"></i> Indonesia</a></li>
            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_bltx_switch_base, ENT_QUOTES, 'UTF-8'); ?>lang=ch"><i class="bi bi-globe"></i> 中文</a></li>
          </ul>
        </li>

        <!-- Currency -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-1" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-currency-exchange"></i>
            <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['currency']); ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_bltx_switch_base, ENT_QUOTES, 'UTF-8'); ?>currency=IDR"><i class="bi bi-cash"></i> IDR (Rp)</a></li>
            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_bltx_switch_base, ENT_QUOTES, 'UTF-8'); ?>currency=USD"><i class="bi bi-cash"></i> USD ($)</a></li>
            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_bltx_switch_base, ENT_QUOTES, 'UTF-8'); ?>currency=CNY"><i class="bi bi-cash"></i> CNY (¥)</a></li>
          </ul>
        </li>

        <!-- Profile -->
        <li class="nav-item dropdown">
          <?php
          // If profile_image isn't in the session yet (older login session,
          // or it was changed elsewhere), pull it once and cache to session.
          if (!isset($_SESSION['profile_image']) && !empty($_SESSION['user_id'])) {
              $_pi_stmt = $conn->prepare("SELECT profile_image FROM users WHERE id=? LIMIT 1");
              $_pi_stmt->bind_param('i', $_SESSION['user_id']);
              $_pi_stmt->execute();
              $_pi_stmt->bind_result($_pi_val);
              if ($_pi_stmt->fetch()) {
                  $_SESSION['profile_image'] = $_pi_val;
              }
              $_pi_stmt->close();
          }
          $_pi_file = $_SESSION['profile_image'] ?? '';
          $_pi_path = UPLOAD_PATH . 'profile/' . $_pi_file;
          $_pi_ok   = ($_pi_file !== '' && file_exists($_pi_path));
          ?>
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?php if ($_pi_ok): ?>
              <img src="<?php echo url('uploads/profile/' . rawurlencode($_pi_file)) . '?v=' . filemtime($_pi_path); ?>" class="rounded-circle" width="32" height="32" alt="Profile" loading="lazy" style="object-fit:cover;">
            <?php else: ?>
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary text-white" style="width:32px;height:32px;font-size:1rem;flex-shrink:0;"><i class="bi bi-person-fill"></i></span>
            <?php endif; ?>
            <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= url('settings/profile_user.php') ?>"><i class="bi bi-person-circle"></i> <?php echo $LANG['profile']; ?></a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= url('logout.php') ?>" onclick="try{sessionStorage.removeItem('inventoryOpen');sessionStorage.removeItem('hrPayrollOpen');sessionStorage.removeItem('settingsOpen');}catch(e){}"><i class="bi bi-box-arrow-right"></i> <?php echo $LANG['logout']; ?></a></li>
          </ul>
        </li>

      </ul>
    </div>
  </nav>

  <!-- ════════════════ App Sidebar ════════════════ -->
  <aside class="app-sidebar shadow" data-bs-theme="dark">

    <!-- Sidebar Brand -->
    <div class="sidebar-brand">
      <a href="<?= url('dashboard.php') ?>" class="brand-link">
        <?php
        $site_logo = getSetting('site_logo');
        if ($site_logo && file_exists(UPLOAD_PATH . 'logos/' . $site_logo)) {
            echo '<img src="' . url('uploads/logos/' . $site_logo) . '" alt="Logo" class="brand-image" loading="lazy">';
        } else {
            echo '<img src="' . url('assets/arw-favicon.svg') . '" class="brand-image" alt="ARW">';
        }
        ?>
        <span class="brand-text">ARW System</span>
      </a>
    </div>

    <!-- Sidebar Menu -->
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column"
            data-lte-toggle="treeview"
            role="navigation"
            aria-label="Main navigation"
            data-accordion="false">

<?php
// ─── Dashboard ────────────────────────────────────────────────
if (_canAccess('dashboard', 'can_access')) {
    $active = ($current_page === 'dashboard') ? 'active' : '';
    echo '<li class="nav-item"><a class="nav-link '.$active.'" href="'.url('dashboard.php').'">';
    echo '<i class="nav-icon bi bi-speedometer2"></i><p>'.htmlspecialchars($LANG['dashboard']).'</p>';
    echo '</a></li>';
}

// ─── Purchases (treeview: Purchase Invoice + Purchase Order + Goods Receipt + Returns) ──
// Modul 'purchases' dan 'purchase_orders' dibaca LANGSUNG dari database lewat
// helper terpusat di includes/global_permission.php (bukan cache session),
// supaya permission yang baru diatur admin langsung berlaku tanpa user harus
// logout lalu login lagi.
$_can_purchases = function_exists('canAccessPurchase')        ? canAccessPurchase()        : false;
$_can_po        = function_exists('canAccessPurchaseOrderDb') ? canAccessPurchaseOrderDb() : false;
$_can_returns   = _canAccess('returns', 'can_access');
// Modul BARU 'goods_receipt' — dibaca lewat helper terpusat di
// includes/global_permission.php (bukan cache session), supaya permission
// yang baru diatur admin langsung berlaku tanpa user harus login ulang.
$_can_gr        = function_exists('canAccessGoodsReceipt') ? canAccessGoodsReceipt() : false;
$_can_supp      = _canAccess('suppliers',  'can_access');
$_can_cats      = _canAccess('categories', 'can_access');
$_can_units     = _canAccess('units',      'can_access');

// GERBANG INDUK: modul 'purchase' (tunggal) harus ON supaya menu Purchase
// muncul di sidebar — sejajar dengan gate 'inventory', 'payroll', dan
// 'settings'. Tanpa gate ini, menu tetap muncul begitu SALAH SATU
// sub-modulnya aktif. Superadmin selalu lolos di dalam _dbCan().
$_can_purchase_gate = function_exists('canAccessPurchaseGate') ? canAccessPurchaseGate() : false;

if ($_can_purchase_gate &&
    ($_can_purchases || $_can_po || $_can_gr || $_can_returns
     || $_can_supp || $_can_cats || $_can_units)):
    $_pp_invoice = ['purchases', 'purchase_add', 'purchase_edit', 'purchase_view'];
    $_pp_order   = ['po_add', 'po_edit', 'po_view'];
    $_pp_gr      = ['goods_receipt', 'goods_receipt_add', 'goods_receipt_edit', 'goods_receipt_view'];
    $_pp_returns = ['returns',
                    'purchase_return_add', 'purchase_return_edit', 'purchase_return_view'];
    // Tab aktif pada hub. Hub sekarang pr_dahsboard_gate.php; purchases.php
    // dipertahankan sebagai pengalih, jadi KEDUA nama tetap dikenali di sini
    // supaya menu tidak pernah kehilangan sorotan aktifnya.
    // Default halaman hub adalah 'order', sehingga URL tanpa parameter tab
    // diperlakukan sebagai tab Purchase Order. Halaman purchase_add/edit/view
    // TIDAK memakai parameter tab, jadi default ini hanya berlaku di hub.
    $_hub_pages = ['pr_dahsboard_gate', 'purchases'];
    $_on_hub    = in_array($current_page, $_hub_pages, true);

    $_tab = (string)($_GET['tab'] ?? '');
    if ($_on_hub && $_tab === '') $_tab = 'order';

    // Tab non-invoice; dipakai untuk mengecualikan sorotan Purchase Invoice.
    $_other_tabs = ['order', 'goods_receipt', 'returns', 'suppliers', 'categories', 'units'];

    // Bagian pertama tetap dipertahankan untuk purchase_add / purchase_edit /
    // purchase_view yang merupakan halaman MANDIRI tanpa parameter tab.
    // Bagian kedua mengenali hub: tanpa itu, membuka ?tab=invoice membuat
    // SELURUH variabel $_on_* bernilai false, sehingga $_in_purchases juga
    // false, kelas menu-open tidak tercetak, dan aturan
    // `.nav-treeview { display: none }` menyembunyikan seluruh submenu.
    $_on_invoice    = (in_array($current_page, $_pp_invoice, true)
                       && !in_array($_tab, $_other_tabs, true))
                      || ($_on_hub && $_tab === 'invoice');
    $_on_order      = in_array($current_page, $_pp_order, true)
                      || ($_on_hub && $_tab === 'order');
    $_on_gr         = in_array($current_page, $_pp_gr, true)
                      || ($_on_hub && $_tab === 'goods_receipt');
    $_on_returns    = in_array($current_page, $_pp_returns, true)
                      || ($_on_hub && $_tab === 'returns');
    // Tiga halaman berikut kini menjadi tab hub, tetapi tetap bisa dibuka
    // sebagai halaman mandiri — keduanya dikenali.
    $_on_suppliers  = $current_page === 'suppliers'
                      || ($_on_hub && $_tab === 'suppliers');
    $_on_categories = $current_page === 'categories'
                      || ($_on_hub && $_tab === 'categories');
    $_on_units      = $current_page === 'units'
                      || ($_on_hub && $_tab === 'units');

    $_in_purchases = $_on_invoice || $_on_order || $_on_gr || $_on_returns
                     || $_on_suppliers || $_on_categories || $_on_units;
?>
          <li class="nav-item <?= $_in_purchases ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link <?= $_in_purchases ? 'active' : '' ?>">
              <i class="nav-icon bi bi-cart-fill"></i>
              <p>
                <?= htmlspecialchars($LANG['purchases']) ?>
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if ($_can_po): ?>
              <li class="nav-item">
                <a class="nav-link <?= $_on_order ? 'active' : '' ?>" href="<?= url('purchases/pr_dahsboard_gate.php?tab=order') ?>">
                  <i class="nav-icon bi bi-file-earmark-text"></i>
                  <p><?= htmlspecialchars($LANG['purchase_order']) ?></p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($_can_gr): ?>
              <li class="nav-item">
                <a class="nav-link <?= $_on_gr ? 'active' : '' ?>" href="<?= url('purchases/pr_dahsboard_gate.php?tab=goods_receipt') ?>">
                  <i class="nav-icon bi bi-box-seam"></i>
                  <p><?= htmlspecialchars($LANG['goods_receipt']) ?></p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($_can_purchases): ?>
              <li class="nav-item">
                <a class="nav-link <?= $_on_invoice ? 'active' : '' ?>" href="<?= url('purchases/pr_dahsboard_gate.php?tab=invoice') ?>">
                  <i class="nav-icon bi bi-receipt"></i>
                  <p><?= htmlspecialchars($LANG['purchase_invoice']) ?></p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($_can_returns): ?>
              <li class="nav-item">
                <a class="nav-link <?= $_on_returns ? 'active' : '' ?>" href="<?= url('purchases/pr_dahsboard_gate.php?tab=returns') ?>">
                  <i class="nav-icon bi bi-arrow-return-left"></i>
                  <p><?= htmlspecialchars($LANG['returns']) ?></p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($_can_supp): ?>
              <li class="nav-item">
                <a class="nav-link <?= $_on_suppliers ? 'active' : '' ?>" href="<?= url('purchases/pr_dahsboard_gate.php?tab=suppliers') ?>">
                  <i class="nav-icon bi bi-people-fill"></i>
                  <p><?= htmlspecialchars($LANG['suppliers']) ?></p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($_can_cats): ?>
              <li class="nav-item">
                <a class="nav-link <?= $_on_categories ? 'active' : '' ?>" href="<?= url('purchases/pr_dahsboard_gate.php?tab=categories') ?>">
                  <i class="nav-icon bi bi-tags-fill"></i>
                  <p><?= htmlspecialchars($LANG['categories']) ?></p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($_can_units): ?>
              <li class="nav-item">
                <a class="nav-link <?= $_on_units ? 'active' : '' ?>" href="<?= url('purchases/pr_dahsboard_gate.php?tab=units') ?>">
                  <i class="nav-icon bi bi-rulers"></i>
                  <p><?= htmlspecialchars($LANG['units']) ?></p>
                </a>
              </li>
              <?php endif; ?>
            </ul>
          </li>
<?php endif; ?>

<?php
// ─── Inventory (collapsible treeview) ─────────────────────────
// Dibaca lewat helper terpusat inventoryModuleAllowed() di
// includes/global_permission.php — LANGSUNG dari tabel `permissions`,
// bukan dari cache $_SESSION['_perms']. Cache itu hanya diisi sekali saat
// login dan tidak pernah dibatalkan oleh settings_permissions.php, sehingga
// pencabutan izin lewat jalur cache baru berlaku setelah user login ulang.
// Helper ini SEKALIGUS menegakkan gerbang induk modul 'inventory', jadi
// tidak ada lagi dua tempat berbeda yang memutuskan hak akses Inventory.
// TIDAK ada modul permission baru — semuanya modul yang sudah ada.
// Superadmin selalu lolos di dalam _dbCan().
$_can_inventory     = function_exists('canAccessInventoryGate') ? canAccessInventoryGate() : _canAccess('inventory', 'can_access');
$_can_inv_data      = function_exists('inventoryModuleAllowed') ? inventoryModuleAllowed('inventory_data')     : false;
$_can_inv_in        = function_exists('inventoryModuleAllowed') ? inventoryModuleAllowed('inventory_in')       : false;
$_can_inv_out       = function_exists('inventoryModuleAllowed') ? inventoryModuleAllowed('inventory_out')      : false;
$_can_inv_location  = function_exists('inventoryModuleAllowed') ? inventoryModuleAllowed('warehouse_location') : false;
$_can_take_items    = function_exists('inventoryModuleAllowed') ? inventoryModuleAllowed('take_items')         : false;
$_can_in_items      = function_exists('inventoryModuleAllowed') ? inventoryModuleAllowed('in_items')           : false;
$_can_stock_opname  = function_exists('inventoryModuleAllowed') ? inventoryModuleAllowed('stock_opname')       : false;
$_show_inventory    = $_can_inventory && ($_can_inv_data || $_can_inv_in || $_can_inv_out || $_can_inv_location || $_can_take_items || $_can_in_items || $_can_stock_opname);
if ($_show_inventory):
?>
          <li class="nav-item <?= $_in_inventory ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link <?= $_in_inventory ? 'active' : '' ?>">
              <i class="nav-icon bi bi-boxes"></i>
              <p>
                <?= htmlspecialchars($LANG['inventory']) ?>
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if ($_can_inv_data): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_inventory && !$_in_take_items && !$_in_in_items && !$_in_stock_opname && (!$_inv_tab || $_inv_tab === 'inventory_data')) ? 'active' : '' ?>"
                  href="<?= url('inventory/inventory_gate.php?tab=inventory_data') ?>">
                  <i class="nav-icon bi bi-box-seam"></i><p><?= htmlspecialchars($LANG['inventory_data']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_inv_in): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_inventory && !$_in_take_items && !$_in_in_items && !$_in_stock_opname && $_inv_tab === 'inventory_in') ? 'active' : '' ?>"
                  href="<?= url('inventory/inventory_gate.php?tab=inventory_in') ?>">
                  <i class="nav-icon bi bi-box-arrow-in-down"></i><p><?= htmlspecialchars($LANG['inventory_in']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_inv_out): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_inventory && !$_in_take_items && !$_in_in_items && !$_in_stock_opname && $_inv_tab === 'inventory_out') ? 'active' : '' ?>"
                  href="<?= url('inventory/inventory_gate.php?tab=inventory_out') ?>">
                  <i class="nav-icon bi bi-box-arrow-up"></i><p><?= htmlspecialchars($LANG['inventory_out']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_stock_opname): ?>
              <li class="nav-item"><a class="nav-link <?= $_in_stock_opname ? 'active' : '' ?>"
                  href="<?= url('inventory/inventory_gate.php?tab=stock_opname') ?>">
                  <i class="nav-icon bi bi-clipboard-check"></i><p><?= htmlspecialchars($LANG['stock_opname']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_inv_location): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_inventory && !$_in_take_items && !$_in_in_items && !$_in_stock_opname && $_inv_tab === 'warehouse_location') ? 'active' : '' ?>"
                  href="<?= url('inventory/inventory_gate.php?tab=warehouse_location') ?>">
                  <i class="nav-icon bi bi-geo-alt-fill"></i><p><?= htmlspecialchars($LANG['warehouse_location']) ?></p></a></li>
              <?php endif; ?>

              <?php
              $_has_submenu_before_take = ($_can_inv_data || $_can_inv_in || $_can_inv_out || $_can_inv_location || $_can_stock_opname);
              if ($_can_take_items): ?>
              <?php if ($_has_submenu_before_take): ?><li><hr class="sidebar-submenu-divider"></li><?php endif; ?>
              <li class="nav-item"><a class="nav-link <?= $_in_take_items ? 'active' : '' ?>"
                  href="<?= url('inventory/take_items.php') ?>">
                  <i class="nav-icon bi bi-box-arrow-up"></i><p><?= htmlspecialchars($LANG['take_items']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_in_items): ?>
              <li class="nav-item"><a class="nav-link <?= $_in_in_items ? 'active' : '' ?>"
                  href="<?= url('inventory/in_items.php') ?>">
                  <i class="nav-icon bi bi-box-arrow-in-down"></i><p><?= htmlspecialchars($LANG['added_items']) ?></p></a></li>
              <?php endif; ?>
            </ul>
          </li>
<?php endif; ?>

<?php
// ─── HR & Payroll (collapsible treeview) ──────────────────────
$_can_payroll           = _canAccess('payroll',           'can_access');
$_can_payroll_dashboard = _canAccess('payroll_dashboard', 'can_access');
$_can_employees         = _canAccess('employees',         'can_access');
$_can_attendance        = _canAccess('attendance',        'can_access');
$_can_shifts            = _canAccess('shifts',            'can_access');
$_can_shift_schedules   = _canAccess('shift_schedules',   'can_access');
$_can_holidays          = _canAccess('holidays',          'can_access');
$_can_payroll_periods   = _canAccess('payroll_periods',   'can_access');
$_can_payroll_list      = _canAccess('payroll_list',      'can_access');
$_can_leave_requests    = _canAccess('leave_requests',    'can_access');
$_can_cash_advances     = _canAccess('cash_advances',     'can_access');
$_can_kpi               = _canAccess('kpi',               'can_access');
$_can_departments       = _canAccess('departments',       'can_access');
$_can_positions         = _canAccess('positions',         'can_access');
$_can_religions         = _canAccess('religions',         'can_access');
$_can_payroll_settings  = _canAccess('payroll_settings',  'can_access');
$_show_payroll = $_can_payroll && (
    $_can_payroll_dashboard || $_can_employees || $_can_attendance || $_can_shifts ||
    $_can_shift_schedules || $_can_holidays || $_can_payroll_periods || $_can_payroll_list ||
    $_can_leave_requests || $_can_cash_advances || $_can_kpi || $_can_departments || $_can_positions ||
    $_can_religions || $_can_payroll_settings
);
if ($_show_payroll):
?>
          <li class="nav-item <?= $_in_payroll ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link <?= $_in_payroll ? 'active' : '' ?>">
              <i class="nav-icon bi bi-people-fill"></i>
              <p>
                <?= htmlspecialchars($LANG['hr_payroll']) ?>
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if ($_can_payroll_dashboard): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'dashboard') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/payroll_index.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('dashboard') ?>">
                  <i class="nav-icon bi bi-speedometer2"></i><p><?= htmlspecialchars($LANG['payroll_dashboard']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_employees): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'employees') || (!$_is_pay_hub && (preg_match('#/(employees|employee_)#', $_SERVER['REQUEST_URI']))) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('employees') ?>">
                  <i class="nav-icon bi bi-person-badge-fill"></i><p><?= htmlspecialchars($LANG['employees']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_attendance): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'attendance') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/attendance.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('attendance') ?>">
                  <i class="nav-icon bi bi-calendar-check-fill"></i><p><?= htmlspecialchars($LANG['attendance']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_shifts): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'shifts') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/shifts.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('shifts') ?>">
                  <i class="nav-icon bi bi-clock-fill"></i><p><?= htmlspecialchars($LANG['shifts']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_shift_schedules): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'shift_schedules') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/shift_schedules.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('shift_schedules') ?>">
                  <i class="nav-icon bi bi-calendar3"></i><p><?= htmlspecialchars($LANG['shift_schedules']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_holidays): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'holidays') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/holidays.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('holidays') ?>">
                  <i class="nav-icon bi bi-calendar-event-fill"></i><p><?= htmlspecialchars($LANG['holidays']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_payroll_periods): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'payroll_periods') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/payroll_periods.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('payroll_periods') ?>">
                  <i class="nav-icon bi bi-calendar-month-fill"></i><p><?= htmlspecialchars($LANG['payroll_periods']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_payroll_list): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'payroll_list') || (!$_is_pay_hub && (preg_match('#/payroll_(list|calculate|slip|report)#', $_SERVER['REQUEST_URI']))) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('payroll_list') ?>">
                  <i class="nav-icon bi bi-receipt"></i><p><?= htmlspecialchars($LANG['payroll_list']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_leave_requests): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'leave_requests') || (!$_is_pay_hub && (preg_match('#/(leave_requests|leave_balance)#', $_SERVER['REQUEST_URI']))) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('leave_requests') ?>">
                  <i class="nav-icon bi bi-file-earmark-check-fill"></i><p><?= htmlspecialchars($LANG['leave_requests']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_cash_advances): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'cash_advances') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/cash_advances.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('cash_advances') ?>">
                  <i class="nav-icon bi bi-cash-coin"></i><p><?= htmlspecialchars($LANG['cash_advances']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_kpi): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'kpi') || (!$_is_pay_hub && (preg_match('#/kpi_#', $_SERVER['REQUEST_URI']))) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('kpi') ?>">
                  <i class="nav-icon bi bi-graph-up-arrow"></i><p><?= htmlspecialchars($LANG['kpi_employees']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_departments): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'departments') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/departments.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('departments') ?>">
                  <i class="nav-icon bi bi-diagram-3-fill"></i><p><?= htmlspecialchars($LANG['departments']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_positions): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'positions') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/positions.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('positions') ?>">
                  <i class="nav-icon bi bi-briefcase-fill"></i><p><?= htmlspecialchars($LANG['positions']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_religions): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'religions') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/religions.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('religions') ?>">
                  <i class="nav-icon bi bi-journal-bookmark-fill"></i><p><?= htmlspecialchars($LANG['religions']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_payroll_settings): ?>
              <li class="nav-item"><a class="nav-link <?= ($_is_pay_hub && $_pay_tab === 'payroll_settings') || (!$_is_pay_hub && (strpos($_SERVER['REQUEST_URI'], '/payroll_settings.php') !== false)) ? 'active' : '' ?>"
                  href="<?= payrollGateUrl('payroll_settings') ?>">
                  <i class="nav-icon bi bi-gear-wide-connected"></i><p><?= htmlspecialchars($LANG['payroll_settings']) ?></p></a></li>
              <?php endif; ?>
            </ul>
          </li>
<?php endif; ?>

<?php if (_canAccess('dreport', 'can_access')): ?>
          <li class="nav-item">
            <a class="nav-link <?= $_in_dreport ? 'active' : '' ?>" href="<?= url('arw_pos/manual_dreport.php') ?>">
              <i class="nav-icon bi bi-journal-text"></i>
              <p><?= htmlspecialchars($LANG['cashier_report']) ?></p>
            </a>
          </li>
<?php endif; ?>

<?php
// ─── Suppliers / Categories / Units ───────────────────────────
// DIHAPUS. Ketiganya kini menjadi SUB MENU Purchase (tab pada
// pr_dahsboard_gate.php), jadi entri mandiri di sini hanya akan
// menggandakan menu yang sama.
//
// Konsekuensi yang disengaja: ketiganya sekarang ikut tunduk pada
// GERBANG Purchase. Selama gate 'purchase' OFF, ketiganya tidak muncul
// di mana pun — persis seperti sub menu Purchase lainnya.
?>

<?php
// ─── Settings (collapsible treeview) ──────────────────────────
$_can_settings          = _canAccess('settings',           'can_access');
$_can_settings_general  = _canAccess('settings_general',   'can_access');
// Modul BARU 'settings_purchase' — dibaca lewat helper terpusat di
// includes/global_permission.php (bukan cache session), supaya permission
// yang baru diatur admin langsung berlaku tanpa user harus login ulang.
$_can_settings_purchase = function_exists('canAccessSettingsPurchase') ? canAccessSettingsPurchase() : false;
$_can_settings_users    = _canAccess('users',              'can_access');
$_can_settings_currency = _canAccess('settings_currency',  'can_access');
$_can_settings_news     = _canAccess('settings_news',      'can_access');
$_can_settings_activity = _canAccess('settings_activity',  'can_access');
// Modul BARU 'settings_backup' — pola sama dengan 'settings_purchase' di atas
// (baca langsung dari database, bukan cache session).
$_can_settings_backup   = function_exists('canAccessSettingsBackup') ? canAccessSettingsBackup() : false;
$_show_settings = $_can_settings && ($_can_settings_general || $_can_settings_users || $_can_settings_purchase || $_can_settings_currency || $_can_settings_news || $_can_settings_activity || $_can_settings_backup);
if ($_show_settings):
?>
          <li class="nav-item <?= $_in_settings ? 'menu-open' : '' ?>">
            <a href="#" class="nav-link <?= $_in_settings ? 'active' : '' ?>">
              <i class="nav-icon bi bi-gear-fill"></i>
              <p>
                <?= htmlspecialchars($LANG['settings']) ?>
                <i class="nav-arrow bi bi-chevron-right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if ($_can_settings_general): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_settings && $_settings_tab === 'general') ? 'active' : '' ?>"
                  href="<?= url('settings/settings_gate.php?tab=general') ?>">
                  <i class="nav-icon bi bi-sliders"></i><p><?= htmlspecialchars($LANG['general']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_settings_users): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_settings && $_settings_tab === 'users') ? 'active' : '' ?>"
                  href="<?= url('settings/settings_gate.php?tab=users') ?>">
                  <i class="nav-icon bi bi-person-badge"></i><p><?= htmlspecialchars($LANG['users']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_settings_purchase): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_settings && $_settings_tab === 'purchase') ? 'active' : '' ?>"
                  href="<?= url('settings/settings_gate.php?tab=purchase') ?>">
                  <i class="nav-icon bi bi-file-earmark-text"></i><p><?= htmlspecialchars($LANG['purchase_settings']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_settings_currency): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_settings && $_settings_tab === 'currency') ? 'active' : '' ?>"
                  href="<?= url('settings/settings_gate.php?tab=currency') ?>">
                  <i class="nav-icon bi bi-currency-exchange"></i><p><?= htmlspecialchars($LANG['currency']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_settings_news): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_settings && $_settings_tab === 'news') ? 'active' : '' ?>"
                  href="<?= url('settings/settings_gate.php?tab=news') ?>">
                  <i class="nav-icon bi bi-newspaper"></i><p><?= htmlspecialchars($LANG['news']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_settings_activity): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_settings && $_settings_tab === 'activity') ? 'active' : '' ?>"
                  href="<?= url('settings/settings_gate.php?tab=activity') ?>">
                  <i class="nav-icon bi bi-shield-check"></i><p><?= htmlspecialchars($LANG['activity']) ?></p></a></li>
              <?php endif; ?>
              <?php if ($_can_settings_backup): ?>
              <li class="nav-item"><a class="nav-link <?= ($_in_settings && $_settings_tab === 'backup_database') ? 'active' : '' ?>"
                  href="<?= url('settings/settings_gate.php?tab=backup_database') ?>">
                  <i class="nav-icon bi bi-database-down"></i><p><?= htmlspecialchars($LANG['backup_database']) ?></p></a></li>
              <?php endif; ?>
            </ul>
          </li>
<?php endif; ?>

        </ul>
      </nav>
    </div>
  </aside>

  <!-- ════════════════ App Main ════════════════ -->
  <main class="app-main">
    <div class="app-content">
      <div class="container-fluid">

        <!-- legacy `.content` wrapper kept open here so every page's body markup keeps working -->
        <div class="content">

<?php if (!empty($_no_access)): ?>
<div class="d-flex flex-column align-items-center justify-content-center" style="min-height:60vh;">
    <div class="text-center">
        <i class="bi bi-shield-lock" style="font-size:4rem;color:#667eea;opacity:.5;"></i>
        <h4 class="mt-3 text-muted"><?php echo $LANG['access_denied']; ?></h4>
        <p class="text-muted"><?php echo $LANG['no_permission']; ?></p>
        <a href="<?= url('logout.php') ?>" class="btn btn-outline-secondary mt-2">
            <i class="bi bi-box-arrow-right"></i> <?php echo $LANG['logout']; ?>
        </a>
    </div>
</div>
<?php endif; ?>