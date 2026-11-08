<?php
/* =============================================================================
   settings/settings_gate.php  —  HUB TAB SETTINGS

   RINGKASAN PERUBAHAN TAHAP 2
     1. Tujuh blok <li> navigasi dan tujuh blok <div class="tab-pane"> yang
        isinya nyaris identik (±190 baris salinan) diganti SATU tabel
        definisi $SETTINGS_TABS + dua perulangan. Menambah tab baru sekarang
        berarti menambah satu baris di tabel itu, bukan menyalin 27 baris
        di dua tempat.
     2. Konstanta SETTINGS_GATE didefinisikan sebelum fragmen di-include,
        sehingga bltx_guard_fragment('SETTINGS_GATE') bisa memastikan sebuah
        fragmen benar-benar dipanggil oleh gate INI, bukan gate lain.
     3. Guard halaman memakai bltx_guard_page() dari Tahap 0.3.
     4. <link> DataTables CSS DIHAPUS — includes/header.php baris 205 sudah
        memuat berkas yang sama persis.
     5. Pesan "File ... tidak ditemukan" yang dulu ditulis keras dalam bahasa
        Indonesia kini memakai $LANG.
     6. require '../includes/config.php' memakai __DIR__, seperti baris
        berikutnya — path relatif bergantung pada direktori kerja.

   TIDAK diubah: daftar tab, urutan tab, ikon, aturan permission tiap tab,
   dan cara bahasa dimuat. Semuanya dipindahkan apa adanya ke tabel di bawah.
   ========================================================================== */
require_once __DIR__ . '/../includes/config.php';
// Helper permission terpusat — diperlukan oleh tab Settings > Purchase
// (canAccessSettingsPurchase) dan tab Settings > Backup Database
// (canAccessSettingsBackup). File ini aman dipanggil berulang.
require_once __DIR__ . '/../includes/global_permission.php';

// Superadmin lolos otomatis di dalam checkPermission().
bltx_guard_page('settings', 'can_access');

/* ===============================
   BAHASA
=============================== */
$current_lang      = $_SESSION['lang'] ?? 'en';
$lang_file         = __DIR__ . "/../languages/{$current_lang}.php";
$default_lang_file = __DIR__ . '/../languages/en.php';

if (file_exists($lang_file)) {
    $lang = require $lang_file;
} elseif (file_exists($default_lang_file)) {
    $lang = require $default_lang_file;
} else {
    $lang = [];
}

$LANG = $lang;

/* ===============================
   PENYIAPAN HALAMAN
   (Sengaja TIDAK menyetel $page_title di sini — beda dengan index.php
   yang lama — sehingga tab browser tidak menampilkan "Settings - ...".)
=============================== */
$active_tab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'general';

/* =============================================================================
   TABEL DEFINISI TAB

   Satu-satunya tempat daftar tab ditulis. Dipakai dua kali di bawah: sekali
   untuk membentuk navigasi, sekali untuk memuat isi tab yang aktif.

     key   : nilai ?tab= dan id elemen
     file  : nama berkas fragmen di folder ini
     icon  : kelas bootstrap-icon
     label : kunci di $LANG
     can   : closure yang mengembalikan true bila pengguna boleh membuka tab

   Aturan permission tiap tab disalin PERSIS dari versi sebelumnya, termasuk
   dua tab yang memakai helper sendiri (purchase & backup_database).
   ========================================================================== */
$SETTINGS_TABS = [
    'general' => [
        'file' => 'general.php',
        'icon' => 'bi-sliders',
        'label' => 'general',
        'can' => function () { return isSuperAdmin() || checkPermission('settings_general', 'can_access'); },
    ],
    'users' => [
        'file' => 'users.php',
        'icon' => 'bi-person-badge',
        'label' => 'users',
        'can' => function () { return isSuperAdmin() || checkPermission('users', 'can_access'); },
    ],
    'purchase' => [
        'file' => 'purchase_settings.php',
        'icon' => 'bi-file-earmark-text',
        'label' => 'purchase_settings',
        'can' => function () { return canAccessSettingsPurchase(); },
    ],
    'currency' => [
        'file' => 'currency.php',
        'icon' => 'bi-currency-exchange',
        'label' => 'currency',
        'can' => function () { return isSuperAdmin() || checkPermission('settings_currency', 'can_access'); },
    ],
    'news' => [
        'file' => 'news.php',
        'icon' => 'bi-newspaper',
        'label' => 'news',
        'can' => function () { return isSuperAdmin() || checkPermission('settings_news', 'can_access'); },
    ],
    'activity' => [
        'file' => 'activity.php',
        'icon' => 'bi-shield-check',
        'label' => 'activity',
        'can' => function () { return isSuperAdmin() || checkPermission('settings_activity', 'can_access'); },
    ],
    'backup_database' => [
        'file' => 'settings_db_backup.php',
        'icon' => 'bi-database-down',
        'label' => 'backup_database',
        'can' => function () { return isSuperAdmin() || canAccessSettingsBackup(); },
    ],
];

/* Penanda bahwa fragmen di folder ini dipanggil oleh gate INI. Dibaca oleh
   bltx_guard_fragment('SETTINGS_GATE') di tiap fragmen. */
if (!defined('SETTINGS_GATE')) {
    define('SETTINGS_GATE', true);
}

/* ===============================
   HEADER
=============================== */
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid p-0">

    <!-- Navigasi Tab -->
    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <?php foreach ($SETTINGS_TABS as $key => $tab): ?>
            <?php if (!$tab['can']()) { continue; } ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $active_tab === $key ? 'active' : ''; ?>"
                   id="<?php echo bltx_e($key); ?>-tab"
                   href="<?php echo bltx_e(url('settings/settings_gate.php?tab=' . $key)); ?>"
                   role="tab"
                   aria-controls="<?php echo bltx_e($key); ?>"
                   aria-selected="<?php echo $active_tab === $key ? 'true' : 'false'; ?>">
                    <i class="bi <?php echo bltx_e($tab['icon']); ?>"></i> <?php echo bltx_e($LANG[$tab['label']] ?? $tab['label']); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Isi Tab -->
    <div class="tab-content" id="settingsTabContent">
        <?php foreach ($SETTINGS_TABS as $key => $tab): ?>
        <div class="tab-pane <?php echo $active_tab === $key ? 'show active' : ''; ?>"
             id="<?php echo bltx_e($key); ?>"
             role="tabpanel"
             aria-labelledby="<?php echo bltx_e($key); ?>-tab"
             tabindex="0">
            <?php
            /* Isi hanya dimuat untuk tab yang sedang aktif — perilaku ini
               sama seperti sebelumnya, dan itulah yang menjaga halaman
               Settings tetap ringan meski punya tujuh tab. */
            if ($active_tab === $key) {
                if (!$tab['can']()) {
                    echo '<div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> '
                       . bltx_e($LANG['no_permission']) . '</div>';
                } else {
                    $file = __DIR__ . '/' . $tab['file'];
                    if (file_exists($file)) {
                        include $file;
                    } else {
                        echo '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> '
                           . bltx_e($LANG['file_not_found'] ?? 'File not found') . ': '
                           . bltx_e($tab['file']) . '</div>';
                    }
                }
            }
            ?>
        </div>
        <?php endforeach; ?>
    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
