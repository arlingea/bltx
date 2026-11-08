<?php
ob_start();

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'bltxmyid_arlinkgea');
define('DB_PASS', '7773eeTR#5901@888');
define('DB_NAME', 'bltxmyid_bltx_bsb');

// Application Configuration
define('APP_NAME', 'ARW Management System');
define('APP_URL', 'https://bltx.my.id/bltx/');

// Base Path (filesystem) — config.php now lives in /includes/, so project root is one level up
define('BASE_PATH', realpath(__DIR__ . '/..'));

// Base URL (auto detect, ANTI rename folder & subfolder)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ? 'https://'
    : 'http://';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$baseDir = explode('/', trim($scriptDir, '/'))[0] ?? '';

define('BASE_URL', rtrim(
    $protocol . $_SERVER['HTTP_HOST'] . '/' . $baseDir,
'/'));

define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');
define('MAX_FILE_SIZE', 5242880);

// Session Configuration
ini_set('session.gc_maxlifetime', 3600);

// Timezone
date_default_timezone_set('Asia/Jakarta');

/* ─────────────────────────────────────────────────────────────────────
   ERROR REPORTING — TIDAK PERNAH TAMPIL DI LAYAR
   (Tahap 0, poin 4)

   Sebelumnya display_errors = 1. Itu membocorkan dua hal ke pengunjung:
     1. Pesan mysqli yang berisi NAMA USER DATABASE, contohnya
        "Access denied for user 'bltxmyid_arlinkgea'@'localhost'".
     2. Path absolut server di setiap warning/notice.

   Sekarang: error TETAP direkam lengkap (error_reporting E_ALL +
   log_errors), tetapi TIDAK pernah dicetak ke browser.

   EFEK SAMPING YANG MENGUNTUNGKAN: warning PHP yang selama ini tercetak
   di tengah blok <script> (mis. key bahasa yang belum ada) tidak lagi
   merusak JavaScript halaman — sebelumnya warning tersebut ikut masuk ke
   dalam JS dan mematikan skrip secara diam-diam.

   File log ditaruh di /logs/ dan dilindungi oleh /logs/.htaccess supaya
   isinya tidak bisa dibuka lewat browser.
   ───────────────────────────────────────────────────────────────────── */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

$_bltx_log_dir = __DIR__ . '/../logs';
if (!is_dir($_bltx_log_dir)) {
    @mkdir($_bltx_log_dir, 0755, true);
}
if (is_dir($_bltx_log_dir) && is_writable($_bltx_log_dir)) {
    ini_set('error_log', $_bltx_log_dir . '/php-error.log');
}
unset($_bltx_log_dir);

/**
 * Berhenti dengan pesan yang aman.
 *
 * Detail teknis ($detail) HANYA masuk ke log; pengunjung cuma melihat
 * kalimat umum. Dipakai untuk kegagalan koneksi database, satu-satunya
 * tempat di berkas ini yang bisa membocorkan kredensial.
 */
function _bltx_fail_safely(string $detail): void {
    error_log('[bltx] ' . $detail);
    http_response_code(503);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('Service temporarily unavailable. Please try again later.');
}

// Database Connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        _bltx_fail_safely('DB connect failed: ' . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    _bltx_fail_safely('DB connect exception: ' . $e->getMessage());
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        return $_SESSION ?? [];
    }
}

/**
 * TAHAP 1 — SIMPAN MENTAH, ESCAPE SAAT KELUAR.
 *
 * Versi lama melakukan EMPAT hal, tiga di antaranya merusak data:
 *
 *   trim()                  -> dipertahankan, satu-satunya yang benar
 *   stripslashes()          -> DIHAPUS. Membuang garis miring yang memang
 *                              bagian dari teks; nama seperti  A\B  jadi  AB.
 *   htmlspecialchars()      -> DIHAPUS. Inilah sumber &amp; dan &#039; di
 *                              database. Escaping HTML adalah urusan SAAT
 *                              MENCETAK, bukan saat menyimpan — kalau
 *                              dilakukan di sini, nilainya ikut salah di
 *                              tempat yang bukan HTML: ekspor Excel, PDF,
 *                              cetak barcode, dan pencarian SQL.
 *   real_escape_string()    -> DIHAPUS. Seluruh 21 berkas pemakai sanitize()
 *                              memakai prepared statement, jadi ini escaping
 *                              lapis kedua yang justru menyimpan garis miring
 *                              tambahan ke dalam kolom. Diverifikasi: tidak
 *                              ada satu pun nilai sanitize() yang dirangkai
 *                              langsung ke SQL.
 *
 * Perlindungan penggantinya sudah terpasang lebih dulu, tahap demi tahap:
 * setiap titik output kini memakai bltx_e(), bltx_js(), htmlspecialchars(),
 * atau helper escaping berkas masing-masing.
 *
 * Null ditangani eksplisit: sejak PHP 8.1 mengoper null ke trim() memicu
 * peringatan deprecation, dan formulir yang mengirim field kosong sering
 * menghasilkan null.
 */
function sanitize($data) {
    if ($data === null) {
        return '';
    }
    return trim((string)$data);
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

/**
 * Enforce login for the current request.
 * Skipped for: the login entry page (index.php at root which defines
 * BLTX_LOGIN_ENTRY) and pure include partials (which define BLTX_INTERNAL
 * before requiring config.php).
 *
 * If the user isn't logged in, redirect to the login page with a `?next=`
 * parameter so we can return after they sign in.
 */
function require_login() {
    // Skip when we're on the login entry page or in an internal include.
    if (defined('BLTX_LOGIN_ENTRY') || defined('BLTX_INTERNAL')) {
        return;
    }
    // Skip in CLI mode (cron / artisan-style scripts shouldn't redirect).
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (!isLoggedIn()) {
        // Build the "return-to" URL so the user lands on the page they wanted
        $next = $_SERVER['REQUEST_URI'] ?? '';
        $login = BASE_URL . '/index.php';
        if ($next !== '' && strpos($next, '/index.php') === false) {
            $login .= '?next=' . urlencode($next);
        }
        header('Location: ' . $login);
        exit;
    }
}

function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin';
}

function url($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

/* =============================================================
   PERMISSION SYSTEM — SUMBER KEBENARAN TUNGGAL
   (Tahap 0.1)

   Prioritas (tidak berubah):
     1. superadmin  → selalu true, TANPA query database sama sekali
     2. per-user    → WHERE user_id = N   (menimpa default role)
     3. default role→ WHERE user_id IS NULL AND role = ?
     4. tidak ada baris → false

   ─────────────────────────────────────────────────────────────
   KENAPA $_SESSION['_perms'] DIHAPUS

   Cache lama disimpan di sesi dan hanya diisi sekali saat login.
   Ketika admin mengubah permission lewat Settings > Permissions,
   sesi user yang sedang login TIDAK bisa dijangkau dari PHP —
   tidak ada cara wajar "meng-invalidate" sesi milik orang lain.
   Akibatnya pencabutan izin baru berlaku setelah korbannya logout
   lalu login lagi. Itulah alasan modul-modul baru (Stock Opname,
   Goods Receipt, Purchase gate) terpaksa mem-bypass cache lewat
   _dbCan() — dan dari situ lahir tiga jalur pemeriksaan izin.

   Sekarang cache-nya PER-REQUEST, bukan per-sesi:
     • diisi SATU kali per request lewat 2 query borongan
       (default role + override user), lalu dipakai ulang
       oleh seluruh pemeriksaan di request yang sama;
     • selalu mencerminkan isi tabel `permissions` DETIK ITU,
       sehingga masalah invalidasi hilang dengan sendirinya;
     • superadmin tetap nol query.

   EFEKNYA LEBIH CEPAT, BUKAN LEBIH LAMBAT: _dbCan() yang lama
   menembak 2 query PER MODUL. Halaman yang menyentuh 8 modul
   dulu ~16 query, sekarang tetap 2.

   ─────────────────────────────────────────────────────────────
   TIGA NAMA, SATU IMPLEMENTASI

     checkPermission()  ─┐
     _canAccess()       ─┼─→  _bltx_perm_row()  ─→  _bltx_perm_map()
     _dbCan()           ─┘        (global_permission.php mendelegasikan ke sini)

   Ketiga nama SENGAJA dipertahankan supaya folder payroll/ (94
   checkPermission + 17 _canAccess + 1 _dbCan) tidak perlu diedit
   satu baris pun.
   ============================================================= */

/**
 * Seluruh modul yang dikelola sistem — harus sama persis dengan DB.
 *
 * STATUS (diverifikasi Tahap 0 terhadap dump database):
 *   daftar ini berisi 42 modul; tabel `permissions` juga berisi
 *   42 modul distinct yang SAMA PERSIS. Tidak ada yang kurang,
 *   tidak ada yang lebih. Jangan "merapikan" tanpa membandingkan
 *   ulang dengan isi tabel.
 *
 * ── SENGAJA TIDAK ADA DI SINI (JANGAN DITAMBAHKAN) ────────────
 * Sepuluh modul berikut dipanggil oleh kode di folder payroll/,
 * tetapi TIDAK punya satu baris pun di tabel `permissions` dan
 * memang TIDAK BOLEH diaktifkan:
 *
 *   appraisal, approvals, assets, attendance_import,
 *   employee_portal, org_chart, payslip_email,
 *   recruitment, salary_components, tax_settings
 *
 * Alasannya: tabel database pendukungnya belum dibuat
 * (employee_assets, job_vacancies, appraisal_cycles,
 * onboarding_tasks, attendance_imports, attendance_import_rows,
 * payslip_email_log, asset_categories). Menyalakan izinnya akan
 * membuat halaman assets.php / recruitment.php / appraisal.php /
 * onboarding.php fatal error.
 *
 * Karena modul-modul itu tidak terdaftar, _bltx_perm_map() tidak
 * akan pernah menemukan barisnya dan pemeriksaannya mengembalikan
 * false — persis seperti perilaku sekarang. Ini BUKAN bug yang
 * perlu "diperbaiki".
 *
 * ── CATATAN STATUS TIGA MODUL (T3) — usulan, belum dieksekusi ──
 *   dashboard      : 21 baris di DB, TIDAK pernah dicek di kode
 *                    mana pun. Usul: biarkan; ia dipakai sebagai
 *                    kandidat landing page di getFirstAccessiblePage().
 *   kpi            : 14 baris di DB, dicek lewat _canAccess().
 *   stock_opname   : 13 baris di DB, dicek lewat _dbCan().
 *   Setelah Tahap 0 ketiganya melewati implementasi yang sama,
 *   jadi tidak ada lagi perbedaan perilaku antar jalur.
 */
function _allModules() {
    return [
        'dashboard',
        // 'purchase' (TUNGGAL) = parent gate modul Purchase, sejajar dengan
        // 'inventory' / 'payroll' / 'settings'. Berbeda dari 'purchases'
        // (JAMAK) yang merupakan modul halaman Purchase Invoice.
        'purchase',
        // 'goods_receipt' ditambahkan agar cache session sinkron dengan tabel
        // `permissions` (modul ini sudah ada di database dan sudah tampil di
        // Settings > Permissions, tetapi sebelumnya tidak pernah dimuat ke
        // $_SESSION['_perms'] sehingga checkPermission('goods_receipt', ...)
        // selalu mengembalikan false untuk non-superadmin).
        'purchases','purchase_orders','goods_receipt','suppliers','categories','units','returns',
        'inventory','inventory_data','inventory_in','inventory_out','warehouse_location','take_items','in_items','stock_opname',
        'payroll','payroll_dashboard','shifts','payroll_periods','payroll_list',
        'leave_requests','cash_advances','kpi','departments','positions','religions','payroll_settings',
        'employees','attendance','holidays', 'shift_schedules',
        'dreport',
        // 'settings_purchase' ditambahkan dengan alasan yang sama seperti
        // 'goods_receipt' di atas: barisnya sudah ada di tabel `permissions`
        // dan sudah bisa diatur di Settings > Permissions, tetapi belum pernah
        // ikut termuat ke cache session.
        'settings','settings_general','settings_purchase','settings_currency','settings_news','settings_activity',
        'users','settings_backup',
    ];
}

/* ─────────────────────────────────────────────────────────────────────
   MESIN IZIN — SATU-SATUNYA TEMPAT YANG MEMBACA TABEL `permissions`
   ───────────────────────────────────────────────────────────────────── */

/**
 * Peta izin milik user yang sedang login, untuk SATU request.
 *
 * Diisi sekali lalu dipakai ulang selama request berjalan. Bentuknya:
 *   ['inventory' => ['can_access'=>1,'can_read'=>1, ...], 'users' => [...]]
 *
 * PENTING — daftar modul TIDAK disaring dengan _allModules().
 * Yang dimuat adalah baris apa pun yang benar-benar ada di tabel untuk
 * role/user ini. Modul yang tidak punya baris otomatis absen dari peta
 * dan pemeriksaannya mengembalikan false. Ini menyamakan perilaku
 * checkPermission() lama (yang menyaring pakai _allModules()) dengan
 * _dbCan() lama (yang tidak menyaring). Karena _allModules() dan isi
 * tabel saat ini identik, tidak ada perbedaan hasil hari ini; ke depan
 * baris modul baru langsung berlaku tanpa harus mengedit _allModules().
 *
 * @param bool $reset true = buang isi cache, muat ulang saat dipanggil lagi.
 */
function _bltx_perm_map(bool $reset = false): array {
    static $map = null;

    if ($reset) { $map = null; return []; }
    if ($map !== null) return $map;

    global $conn;
    $map = [];

    if (!isLoggedIn()) return $map;

    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = (string)($_SESSION['role'] ?? '');

    $absorb = function ($res) use (&$map) {
        if (!$res) return;
        while ($row = $res->fetch_assoc()) {
            $map[$row['module']] = [
                'can_access' => (int)$row['can_access'],
                'can_read'   => (int)$row['can_read'],
                'can_create' => (int)$row['can_create'],
                'can_update' => (int)$row['can_update'],
                'can_delete' => (int)$row['can_delete'],
                'can_export' => (int)$row['can_export'],
            ];
        }
    };

    $cols = "module, can_access, can_read, can_create, can_update, can_delete,
             COALESCE(can_export, 0) AS can_export";

    // 1) Default milik role — jadi dasar.
    if ($role !== '') {
        $stmt = $conn->prepare(
            "SELECT {$cols} FROM permissions WHERE user_id IS NULL AND role = ?"
        );
        if ($stmt) {
            $stmt->bind_param("s", $role);
            $stmt->execute();
            $absorb($stmt->get_result());
            $stmt->close();
        }
    }

    // 2) Override milik user — prioritas tertinggi, menimpa dasar di atas.
    if ($uid > 0) {
        $stmt = $conn->prepare(
            "SELECT {$cols} FROM permissions WHERE user_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $absorb($stmt->get_result());
            $stmt->close();
        }
    }

    return $map;
}

/**
 * Satu baris izin untuk satu modul. Selalu mengembalikan array lengkap,
 * jadi pemanggil tidak perlu memeriksa isset().
 *
 * Dipakai juga oleh _dbPermissionRow() di includes/global_permission.php,
 * yang kini hanya meneruskan ke sini.
 */
function _bltx_perm_row(string $module): array {
    $map = _bltx_perm_map();
    return $map[$module] ?? [
        'can_access' => 0, 'can_read'   => 0, 'can_create' => 0,
        'can_update' => 0, 'can_delete' => 0, 'can_export' => 0,
    ];
}

/**
 * KOMPATIBILITAS — dipanggil oleh includes/login.php baris 86.
 *
 * Cache sesi $_SESSION['_perms'] sudah tidak dipakai lagi. Fungsi ini
 * DIPERTAHANKAN karena login.php termasuk berkas yang tidak boleh
 * disentuh di Tahap 0; menghapusnya akan membuat login fatal error.
 *
 * Sekarang tugasnya dua:
 *   1. membuang sisa cache lama dari sesi yang dibuat SEBELUM deploy ini
 *      (kalau dibiarkan, isinya hanya jadi sampah yang membingungkan);
 *   2. memanaskan cache per-request sekali di muka.
 */
function loadPermissionsToSession() {
    unset($_SESSION['_perms']);
    _bltx_perm_map(true);
    if (!isSuperAdmin()) {
        _bltx_perm_map();
    }
}

/**
 * Pemeriksaan izin. Superadmin selalu true tanpa menyentuh database.
 * Belum login → selalu false.
 */
function checkPermission($module, $action = 'can_access') {
    if (isSuperAdmin()) return true;
    if (!isLoggedIn())  return false;

    $allowed = ['can_access','can_read','can_create','can_update','can_delete','can_export'];
    if (!in_array($action, $allowed, true)) return false;

    $row = _bltx_perm_row((string)$module);
    return !empty($row[$action]);
}

/** Shorthand used in header.php and all pages */
if (!function_exists('_canAccess')) {
    function _canAccess(string $module, string $action = 'can_access'): bool {
        return checkPermission($module, $action);
    }
}

/**
 * Find first URL accessible to this user.
 * Covers ALL modules — sub-pages included.
 */
function getFirstAccessiblePage($uid, $role) {
    if ($role === 'superadmin') return url('dashboard.php');

    // All candidate landing pages — ordered by priority
    // NOTE: Parent gate modules (inventory, payroll, settings) are intentionally excluded
    // because their pages perform sub-module permission checks that would cause redirect
    // loops if only the parent gate has can_access=1 but sub-modules do not.
    // Sub-module entries below serve as the actual landing pages.
    $candidates = [
        'dashboard'          => url('dashboard.php'),
        // Hub Purchase kini pr_dahsboard_gate.php. Modul gate 'purchase'
        // (tunggal) SENGAJA TIDAK dimasukkan sebagai kandidat, mengikuti
        // catatan di atas: modul gerbang dikecualikan supaya tidak terjadi
        // redirect loop ketika hanya gate-nya yang aktif.
        'purchases'          => url('purchases/pr_dahsboard_gate.php?tab=invoice'),
        'purchase_orders'    => url('purchases/pr_dahsboard_gate.php?tab=order'),
        'goods_receipt'      => url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'),
        'suppliers'          => url('purchases/pr_dahsboard_gate.php?tab=suppliers'),
        'categories'         => url('purchases/pr_dahsboard_gate.php?tab=categories'),
        'units'              => url('purchases/pr_dahsboard_gate.php?tab=units'),
        'returns'            => url('purchases/returns.php'),
        'inventory_data'     => url('inventory/inventory_gate.php?tab=inventory_data'),
        'inventory_in'       => url('inventory/inventory_gate.php?tab=inventory_in'),
        'inventory_out'      => url('inventory/inventory_gate.php?tab=inventory_out'),
        'warehouse_location' => url('inventory/inventory_gate.php?tab=warehouse_location'),
        'take_items'         => url('inventory/take_items.php'),
        'in_items'           => url('inventory/in_items.php'),
        'stock_opname'       => url('inventory/inventory_gate.php?tab=stock_opname'),
        'payroll_dashboard'  => url('payroll/payroll_index.php'),
        'employees'          => url('payroll/employees.php'),
        'attendance'         => url('payroll/attendance.php'),
        'shifts'             => url('payroll/shifts.php'),
        'shift_schedules'    => url('payroll/shift_schedules.php'),
        'holidays'           => url('payroll/holidays.php'),
        'payroll_periods'    => url('payroll/payroll_periods.php'),
        'payroll_list'       => url('payroll/payroll_list.php'),
        'leave_requests'     => url('payroll/leave_requests.php'),
        'cash_advances'      => url('payroll/cash_advances.php'),
        'kpi'                => url('payroll/kpi_employees.php'),
        'departments'        => url('payroll/departments.php'),
        'positions'          => url('payroll/positions.php'),
        'religions'          => url('payroll/religions.php'),
        'payroll_settings'   => url('payroll/payroll_settings.php'),
        'dreport'            => url('arw_pos/manual_dreport.php'),
        'settings_general'   => url('settings/settings_gate.php?tab=general'),
        'settings_currency'  => url('settings/settings_gate.php?tab=currency'),
        'settings_news'      => url('settings/settings_gate.php?tab=news'),
        'settings_activity'  => url('settings/settings_gate.php?tab=activity'),
        'users'              => url('settings/settings_gate.php?tab=users'),
        'settings_backup'    => url('settings/settings_gate.php?tab=backup_database'),
    ];

    // ── GERBANG PURCHASE: cegah redirect loop ─────────────────────────────
    // Seluruh halaman Purchase memanggil requirePurchaseModule(), yang menolak
    // akses saat modul gerbang 'purchase' tidak aktif dan memanggil
    // redirectNoAccess() -> getFirstAccessiblePage() (fungsi ini).
    //
    // Tanpa penyaringan di bawah, kandidat Purchase tetap terpilih selama
    // sub-modulnya masih can_access=1 di $_SESSION['_perms'], sehingga user
    // dikembalikan ke halaman Purchase, ditolak lagi, dikembalikan lagi —
    // BROWSER MELAPORKAN "The page isn't redirecting properly".
    //
    // Ini kasus kebalikan dari catatan di atas: di sana gerbang aktif tetapi
    // sub-modul mati; di sini sub-modul aktif tetapi gerbangnya mati. Kedua
    // arah harus sama-sama dijaga.
    $_purchase_mods = ['purchases', 'purchase_orders', 'goods_receipt',
                       'suppliers', 'categories', 'units', 'returns'];
    // function_exists: config.php bisa dimuat sebelum global_permission.php.
    // Bila helper belum ada, kandidat dibiarkan (perilaku lama dipertahankan).
    if (function_exists('canAccessPurchaseGate') && !canAccessPurchaseGate()) {
        foreach ($_purchase_mods as $_pm) unset($candidates[$_pm]);
    }

    // Dibaca lewat checkPermission() — sumber tunggal Tahap 0.1. Sebelumnya
    // blok ini membaca $_SESSION['_perms'] langsung, sehingga user yang
    // izinnya baru saja diubah bisa dilempar ke halaman yang sebenarnya
    // sudah tidak boleh ia buka (atau sebaliknya, ditolak dari halaman yang
    // sudah boleh) sampai ia logout.
    foreach ($candidates as $mod => $dest) {
        if (checkPermission($mod, 'can_access')) {
            return $dest;
        }
    }

    // Tidak ada tujuan yang layak. Mengembalikan null membuat redirectNoAccess()
    // menampilkan halaman "tidak punya izin" alih-alih mengalihkan lagi —
    // inilah rem terakhir yang memutus rantai pengalihan.
    return null;
}

/**
 * Called when a page denies access.
 * Redirects to first accessible page.
 * If nothing accessible → show error HTML (NO redirect to index.php to prevent loops).
 */
function redirectNoAccess() {
    if (!isLoggedIn()) {
        redirect(url('index.php'));
        // exit is inside redirect()
    }

    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['role'] ?? '';
    $dest = getFirstAccessiblePage($uid, $role);

    if ($dest) {
        redirect($dest);
        // exit is inside redirect()
    }

    // Absolutely no accessible pages — show error page, do NOT redirect to index.php
    // (redirecting would cause an infinite loop if index.php redirects back here)
    _showNoPermissionPage();
}

/** Render a standalone error page and stop. */
function _showNoPermissionPage() {
    // Clean session cookie so browser stops sending it
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_unset();
        session_destroy();
    }
    $login_url = url('index.php');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>No Permission — ARW Management System</title>
<style>
  /* Font diseragamkan dengan includes/header.php — tumpukan yang sama
     dipakai seluruh halaman web sistem. */
  body{margin:0;font-family:'Source Sans 3', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
       background:#f0f4ff;display:flex;align-items:center;justify-content:center;min-height:100vh}
  .box{background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(79,142,247,.15);
       padding:48px 40px;text-align:center;max-width:420px;width:90%}
  .icon{font-size:3rem;margin-bottom:16px}
  h2{color:#1e2a38;margin:0 0 8px;font-size:1.4rem}
  p{color:#64748b;margin:0 0 28px;line-height:1.6}
  a{display:inline-block;background:linear-gradient(135deg,#4f8ef7,#2d6cdf);
    color:#fff;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:600;
    transition:opacity .2s}
  a:hover{opacity:.85}
</style>
</head>
<body>
<div class="box">
  <div class="icon">🔒</div>
  <h2>No Permission</h2>
  <p>Your account has no accessible pages.<br>Please contact your administrator.</p>
  <a href="{$login_url}">Back to Login</a>
</div>
</body>
</html>
HTML;
    exit;
}

function logActivity($action_or_user_id, $action_or_desc = null, $description = null) {
    global $conn;
    if (!isLoggedIn()) return;

    $setting = getSetting('activity_log_enabled');
    if ($setting != '1') return;

    if (is_numeric($action_or_user_id)) {
        $user_id = (int)$action_or_user_id;
        $action  = $action_or_desc;
        $desc    = $description;
    } else {
        $user_id = (int)$_SESSION['user_id'];
        $action  = $action_or_user_id;
        $desc    = $action_or_desc;
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    // ── Simpan NAMA pelaku ke dalam baris log ────────────────────────────
    // Nama disalin ke tabel activity_logs itu sendiri supaya riwayat tetap
    // terbaca setelah akun users dihapus. Tanpa ini, log hanya menyimpan
    // angka user_id yang sudah tidak menunjuk ke mana-mana.
    //
    // Ditulis hanya bila kolomnya memang sudah ada, sehingga berkas ini aman
    // diunggah SEBELUM maupun SESUDAH tahap1_activity_logs.sql dijalankan —
    // urutan deploy tidak lagi menentukan, dan pencatatan log tidak pernah
    // berhenti diam-diam karena kolomnya belum dibuat.
    if (_logActivityHasUserNameColumn($conn)) {
        $user_name = _logActivityUserName($conn, $user_id);
        $stmt = $conn->prepare(
            "INSERT INTO activity_logs (user_id, user_name, action, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("isssss", $user_id, $user_name, $action, $desc, $ip_address, $user_agent);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    // Jalur lama, dipakai selama kolom user_name belum ada.
    $stmt = $conn->prepare(
        "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param("issss", $user_id, $action, $desc, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Apakah kolom activity_logs.user_name sudah ada?
 *
 * Diperiksa SEKALI per request lalu disimpan di cache statis, jadi walaupun
 * logActivity() dipanggil berkali-kali, SHOW COLUMNS hanya jalan sekali.
 * Pola pemeriksaan ini sama dengan yang sudah dipakai
 * gr_purchasesHasGrColumn() di includes/goods_receipt_function.php.
 */
function _logActivityHasUserNameColumn($conn): bool
{
    static $has = null;
    if ($has !== null) return $has;

    $has = false;
    $res = @$conn->query("SHOW COLUMNS FROM `activity_logs` LIKE 'user_name'");
    if ($res) {
        $has = $res->num_rows > 0;
        $res->free();
    }
    return $has;
}

/**
 * Ambil nama lengkap pemilik $userId untuk disalin ke baris log.
 *
 * Tiga sumber, berurutan dari yang paling murah:
 *   1. Sesi          — untuk user yang sedang login, tanpa query sama sekali
 *   2. Tabel users   — untuk pencatatan atas nama user lain
 *   3. Arsip nama    — bila akunnya sudah telanjur dihapus
 *
 * Hasilnya di-cache per user_id, jadi beberapa kali logActivity() dalam satu
 * request tetap hanya menimbulkan paling banyak satu query tambahan.
 * Mengembalikan string kosong bila nama benar-benar tidak ditemukan; kolomnya
 * NOT NULL DEFAULT '' sehingga pencatatan log tetap berhasil.
 */
function _logActivityUserName($conn, int $userId): string
{
    static $cache = [];
    if ($userId <= 0) return '';
    if (isset($cache[$userId])) return $cache[$userId];

    $name = '';

    // 1. User yang sedang login — namanya sudah ada di sesi.
    if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
        $name = trim((string)($_SESSION['full_name'] ?? ''));
    }

    // 2. Tabel users.
    if ($name === '') {
        $stmt = $conn->prepare("SELECT `full_name` FROM `users` WHERE `id` = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) $name = trim((string)$row['full_name']);
        }
    }

    // 3. Arsip nama — akun sudah dihapus, tapi namanya masih tersimpan.
    if ($name === '') {
        $stmt = $conn->prepare("SELECT `full_name` FROM `user_name_archive` WHERE `user_id` = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) $name = trim((string)$row['full_name']);
        }
    }

    return $cache[$userId] = $name;
}

function getSetting($key, $default = null) {
    global $conn;
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    if (!$stmt) return $default;
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $stmt->bind_result($setting_value);
    if ($stmt->fetch()) {
        $stmt->close();
        return $setting_value;
    }
    $stmt->close();
    return $default;
}

function updateSetting($key, $value) {
    global $conn;
    $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
    $stmt->bind_param("ss", $value, $key);
    return $stmt->execute();
}

function formatCurrency($amount, $currency = null) {
    if ($currency === null) {
        // Poin 1: Mengubah logika untuk menggunakan preferensi sesi terlebih dahulu
        // alih-alih menarik dari database setting global yang menimpa mata uang orang lain.
        $currency = $_SESSION['currency'] ?? getSetting('currency', 'IDR');
    }
    $amount = floatval($amount);
    switch ($currency) {
        case 'USD':
            $rate = floatval(getSetting('usd_rate', 15000));
            return '$' . number_format($amount / $rate, 2);
        case 'CNY':
            $rate = floatval(getSetting('cny_rate', 2100));
            return '¥' . number_format($amount / $rate, 2);
        default:
            return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}

function loadLanguage($lang = null) {
    if ($lang === null) {
        $lang = getSetting('language', 'en');
    }
    // Backward compatibility: 'zh' is the legacy code for Chinese; the file is now 'ch.php'.
    if ($lang === 'zh') { $lang = 'ch'; }
    $lang_file = dirname(__DIR__) . "/languages/{$lang}.php";
    if (file_exists($lang_file)) {
        return include $lang_file;
    }
    return include dirname(__DIR__) . "/languages/en.php";
}

$LANG = loadLanguage();

/* ─────────────────────────────────────────────────────────────────────
   GLOBAL LOGIN GATE
   Every page that includes this config is forced through require_login().
   Pages that should be public define BLTX_LOGIN_ENTRY (the login page) or
   BLTX_INTERNAL (pure include partials) BEFORE requiring config.php.
   ───────────────────────────────────────────────────────────────────── */
require_login();
?>