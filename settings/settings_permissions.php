<?php
/* =============================================================================
   settings/settings_permissions.php

   Matriks permission — dimuat lewat AJAX ke dalam modal dari settings/users.php,
   yang sendirinya di-include oleh settings/settings_gate.php.

   AJAX DI SINI SENGAJA DIPERTAHANKAN. B2 poin 4 menyuruh CRUD memakai POST
   tradisional, dan itu sudah dikerjakan di seluruh tab lain. Halaman ini beda:
   ia bukan formulir CRUD di atas halaman, melainkan matriks 42 modul x 6 aksi
   di dalam modal yang memuat dan menyimpan tanpa meninggalkan daftar user.
   Mengubahnya menjadi POST biasa berarti memuat ulang seluruh tab Users setiap
   kali satu centang disimpan. Endpoint-nya sudah bertoken sejak awal.

   PERUBAHAN TAHAP 2
     1. Tiga blok guard (APP_NAME, BASE_PATH, isLoggedIn) jadi satu stanza baku
        Tahap 0.3, yang sekaligus memastikan pemanggilnya adalah SETTINGS_GATE.
     2. Pembentukan token dan dua pemeriksaan hash_equals memakai helper
        terpusat. Bentuk balasan JSON dan kode status HTTP TIDAK berubah,
        supaya kontrak dengan sisi klien tetap persis sama.
     3. Rotasi token setelah simpan dihapus — lihat catatan di dekat
        permJsonOut() penutup.
   ========================================================================== */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment('SETTINGS_GATE');

/* ===============================
   HELPER PERMISSION TERPUSAT
   Seluruh aturan delegasi (permission khusus) tinggal di
   includes/global_permission.php — file ini hanya memakainya.
=============================== */
require_once BASE_PATH . '/includes/global_permission.php';

/* ===============================
   CSRF — token dari helper terpusat. Kunci sesi dan cara bandingnya SAMA
   PERSIS seperti sebelumnya ($_SESSION['csrf_token'] + hash_equals).
=============================== */
$csrf_token = bltx_csrf_token();

/* ===============================
   HELPER LOKAL
=============================== */

/** Balas JSON lalu berhenti. JSON_UNESCAPED_* menjaga tanda baca
 *  (' " & * spasi) dan huruf Mandarin tetap utuh apa adanya. */
if (!function_exists('permJsonOut')) {
    function permJsonOut(array $payload): void {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Permission efektif seorang user untuk daftar modul tertentu.
 * Prioritas sama persis dengan seluruh sistem: baris role
 * (user_id IS NULL) sebagai dasar, lalu ditimpa baris user.
 * Hanya 2 query, berapa pun jumlah modulnya.
 */
if (!function_exists('permEffectiveForUser')) {
    function permEffectiveForUser(int $uid, string $role, array $modules): array {
        global $conn;

        $out = [];
        foreach ($modules as $m) {
            $out[$m] = [
                'can_create'   => 0,
                'can_read'     => 0,
                'can_update'   => 0,
                'can_delete'   => 0,
                'can_access'   => 0,
                'can_export'   => 0,
                'can_view_all' => null,
            ];
        }

        $apply = function ($res) use (&$out) {
            while ($r = $res->fetch_assoc()) {
                $mod = (string)$r['module'];
                if (!isset($out[$mod])) continue;
                $out[$mod] = [
                    'can_create'   => (int)$r['can_create'],
                    'can_read'     => (int)$r['can_read'],
                    'can_update'   => (int)$r['can_update'],
                    'can_delete'   => (int)$r['can_delete'],
                    'can_access'   => (int)$r['can_access'],
                    'can_export'   => (int)$r['can_export'],
                    'can_view_all' => ($r['can_view_all'] === null ? null : (int)$r['can_view_all']),
                ];
            }
        };

        $cols = "module, can_create, can_read, can_update, can_delete, can_access,
                 COALESCE(can_export, 0) AS can_export, can_view_all";

        if ($role !== '') {
            $stmt = $conn->prepare("SELECT $cols FROM permissions WHERE user_id IS NULL AND role = ?");
            if ($stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $apply($stmt->get_result());
                $stmt->close();
            }
        }

        if ($uid > 0) {
            $stmt = $conn->prepare("SELECT $cols FROM permissions WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $apply($stmt->get_result());
                $stmt->close();
            }
        }

        return $out;
    }
}

/** Ambil id + username + role target dalam satu query. */
if (!function_exists('permFetchTargetUser')) {
    function permFetchTargetUser(int $uid) {
        global $conn;
        $stmt = $conn->prepare("SELECT id, username, full_name, role FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $row : null;
    }
}

/* ===============================
   AJAX: LOAD PERMISSIONS BY USER ID
   Priority: role default -> ditimpa per-user
=============================== */
if (isset($_POST['ajax_load_permissions']) && $_POST['ajax_load_permissions'] === 'true') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Mode return-false + try/catch, alasan sama seperti pada handler
    // simpan di bawah: respons ke browser dijamin selalu JSON valid.
    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_OFF);
    }

    try {

    // Gerbang 1: berhak mengelola permission sama sekali?
    if (!permCanManagePermissions()) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_denied_manage']]);
    }

    // Gerbang 2: CSRF (dulu endpoint load belum punya validasi ini)
    /* Sengaja memakai bltx_csrf_valid() + permJsonOut(), BUKAN
       bltx_csrf_require(): yang kedua membalas dengan status HTTP 403,
       sedangkan klien di berkas ini membaca balasan lewat handler success
       $.ajax dan mengharapkan HTTP 200. Nilai yang dibandingkan identik. */
    if (!bltx_csrf_valid($_POST['csrf_token'] ?? '')) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['invalid_csrf']]);
    }

    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_load_error']]);
    }

    $target = permFetchTargetUser($uid);
    if (!$target) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_load_error']]);
    }

    // Gerbang 3: berhak mengelola AKUN INI? (batas target / rank / diri sendiri)
    if (!permCanManageTargetUser($uid, (string)$target['role'])) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_denied_target']]);
    }

    $modules = permModulesFromDatabase();
    $perms   = permEffectiveForUser($uid, (string)$target['role'], $modules);

    // Apakah user ini sudah punya baris permission sendiri?
    $has_custom = false;
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM permissions WHERE user_id = ?");
    if ($chk) {
        $chk->bind_param('i', $uid);
        $chk->execute();
        $has_custom = ((int)$chk->get_result()->fetch_assoc()['cnt'] > 0);
        $chk->close();
    }

    // Panel delegasi (permission khusus) — HANYA superadmin yang melihatnya
    $delegation = [
        'visible'       => false,
        'enabled'       => 0,
        'restrict_rank' => 0,
        'groups'        => [],
        'available'     => permDelegateColumnsExist(),
    ];
    if (isSuperAdmin()) {
        $delegation['visible'] = true;
        if (permDelegateColumnsExist()) {
            $d = $conn->prepare(
                "SELECT perm_delegate, perm_scope_groups, perm_target_rank
                   FROM permissions WHERE user_id = ? AND module = 'users' LIMIT 1"
            );
            if ($d) {
                $d->bind_param('i', $uid);
                $d->execute();
                $drow = $d->get_result()->fetch_assoc();
                $d->close();
                if ($drow) {
                    $delegation['enabled']       = (int)$drow['perm_delegate'];
                    $delegation['restrict_rank'] = (int)$drow['perm_target_rank'];
                    $raw                         = trim((string)$drow['perm_scope_groups']);
                    $delegation['groups']        = ($raw === '') ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
                }
            }
        }
    }

    permJsonOut([
        'success'     => true,
        'user_id'     => $uid,
        'username'    => (string)$target['username'],
        'role'        => (string)$target['role'],
        'has_custom'  => $has_custom,
        'is_super'    => isSuperAdmin(),
        'permissions' => $perms,
        'tree'        => permBuildTree($LANG),
        'bounds'      => permBoundsMatrix($modules),
        'delegation'  => $delegation,
    ]);

    } catch (Throwable $e) {
        permJsonOut([
            'success' => false,
            'message' => (string)$LANG['perm_load_error'] . ' [' . $e->getMessage() . ']',
        ]);
    }
}

/* ===============================
   AJAX: SAVE PERMISSIONS BY USER ID
   Traditional POST + CSRF + validasi ulang penuh di sisi server
=============================== */
if (isset($_POST['ajax_save_permissions']) && $_POST['ajax_save_permissions'] === 'true') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    /* -----------------------------------------------------------------
       JARING PENGAMAN A — MODE PELAPORAN mysqli.
       Sejak PHP 8.1 default mysqli adalah MYSQLI_REPORT_ERROR |
       MYSQLI_REPORT_STRICT, artinya prepare()/execute() yang gagal
       MELEMPAR mysqli_sql_exception, bukan mengembalikan false. Kalau
       tidak ditangkap, respons berubah jadi halaman HTML "Fatal error"
       sehingga jQuery gagal mem-parse JSON dan tombol Save terlihat
       "tidak melakukan apa-apa". Di blok ini kita kembalikan ke mode
       return-false lalu setiap kegagalan diperiksa manual dan pesan
       MySQL aslinya dikirim ke toast. Hanya berlaku untuk request AJAX
       ini karena skripnya langsung exit sesudahnya.
    ----------------------------------------------------------------- */
    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_OFF);
    }

    /* -----------------------------------------------------------------
       JARING PENGAMAN B — try/catch menyeluruh. Apa pun yang terjadi,
       respons ke browser DIJAMIN tetap JSON yang valid, tidak pernah
       lagi berupa halaman HTML error.
    ----------------------------------------------------------------- */
    try {
    if (!permCanManagePermissions()) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_denied_manage']]);
    }

    /* Sengaja memakai bltx_csrf_valid() + permJsonOut(), BUKAN
       bltx_csrf_require(): yang kedua membalas dengan status HTTP 403,
       sedangkan klien di berkas ini membaca balasan lewat handler success
       $.ajax dan mengharapkan HTTP 200. Nilai yang dibandingkan identik. */
    if (!bltx_csrf_valid($_POST['csrf_token'] ?? '')) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['invalid_csrf']]);
    }

    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_save_failed']]);
    }

    $target = permFetchTargetUser($uid);
    if (!$target) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_save_failed']]);
    }

    $target_role = (string)$target['role'];
    $uname       = (string)$target['username'];

    if (!permCanManageTargetUser($uid, $target_role)) {
        permJsonOut(['success' => false, 'message' => (string)$LANG['perm_denied_target']]);
    }

    $posted  = (isset($_POST['perm']) && is_array($_POST['perm'])) ? $_POST['perm'] : [];
    $modules = permModulesFromDatabase();
    $actions = permActionKeys();

    // KONDISI SEBELUM — dipakai untuk dua hal:
    //  1. mempertahankan nilai lama pada modul/aksi yang berada DI LUAR
    //     wewenang delegate (payload dari browser diabaikan untuk itu);
    //  2. menghitung diff untuk log aktivitas.
    $before = permEffectiveForUser($uid, $target_role, $modules);

    $rows    = [];
    $changes = [];

    foreach ($modules as $mod) {
        $row = ['module' => $mod];

        foreach ($actions as $act) {
            $old = (int)$before[$mod][$act];

            // VALIDASI ULANG DI SERVER. Checkbox yang di-disable di HTML
            // tidak pernah dipercaya — izinnya dihitung ulang di sini.
            if (permActionAllowed($mod, $act)) {
                $new = (isset($posted[$mod][$act]) && (string)$posted[$mod][$act] === '1') ? 1 : 0;
            } else {
                $new = $old;   // di luar wewenang -> nilai lama dipertahankan
            }

            $row[$act] = $new;
            if ($new !== $old) {
                $changes[] = $mod . '.' . $act . ' ' . $old . '->' . $new;
            }
        }

        $rows[] = $row;
    }

    /* ---------------------------------------------------------------
       PENULISAN 6 KOLOM AKSI.

       Dulu (kode asli Anda): 39 modul x (SELECT + UPDATE/INSERT) + 39
       upsert can_export = sekitar 117 query untuk satu kali Save.

       Versi sebelumnya dari saya memakai SATU statement multi-row yang
       diikat lewat call_user_func_array() dengan array referensi.
       Konstruksi itu perilakunya berbeda-beda antar versi PHP dan build
       mysqli, dan itulah tersangka utama kenapa Save gagal. Sekarang
       DIBUANG SEPENUHNYA.

       Gantinya: 1 prepare + 1 bind_param + execute berulang per modul.
       Variabel terikat by-reference secara alami, jadi cukup diganti
       nilainya tiap iterasi. Ini konstruksi mysqli paling portabel yang
       ada — jalan di PHP 5.6 sampai 8.4, mysqlnd maupun libmysqlclient.
       Biayanya tetap 1 prepare + 41 execute, bukan kembali ke 117 query.

       can_view_all SENGAJA TIDAK ikut di sini supaya kolom itu tidak
       pernah tersentuh saat admin hanya menyimpan modul lain.
       Aman karena unique key `user_module` (user_id, module) memang
       sudah ada di tabel permissions.
    --------------------------------------------------------------- */
    $stmt = $conn->prepare(
        "INSERT INTO permissions
             (user_id, role, module, can_create, can_read, can_update, can_delete, can_access, can_export)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             can_create = VALUES(can_create),
             can_read   = VALUES(can_read),
             can_update = VALUES(can_update),
             can_delete = VALUES(can_delete),
             can_access = VALUES(can_access),
             can_export = VALUES(can_export)"
    );
    if ($stmt === false) {
        permJsonOut([
            'success' => false,
            'message' => (string)$LANG['perm_save_failed'] . ' [DB prepare] ' . $conn->error,
        ]);
    }

    // Variabel terikat sekali, nilainya diganti tiap iterasi.
    $b_module = '';
    $b_create = 0;
    $b_read   = 0;
    $b_update = 0;
    $b_delete = 0;
    $b_access = 0;
    $b_export = 0;

    if (!$stmt->bind_param(
            'issiiiiii',
            $uid, $target_role, $b_module,
            $b_create, $b_read, $b_update, $b_delete, $b_access, $b_export
    )) {
        $err = $stmt->error;
        $stmt->close();
        permJsonOut([
            'success' => false,
            'message' => (string)$LANG['perm_save_failed'] . ' [DB bind] ' . $err,
        ]);
    }

    foreach ($rows as $r) {
        $b_module = $r['module'];
        $b_create = $r['can_create'];
        $b_read   = $r['can_read'];
        $b_update = $r['can_update'];
        $b_delete = $r['can_delete'];
        $b_access = $r['can_access'];
        $b_export = $r['can_export'];

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            permJsonOut([
                'success' => false,
                'message' => (string)$LANG['perm_save_failed']
                           . ' [DB execute: ' . $r['module'] . '] ' . $err,
            ]);
        }
    }
    $stmt->close();

    /* ---------------------------------------------------------------
       CAKUPAN DATA (can_view_all) — perilaku dipertahankan PERSIS:
       kolom hanya ditulis kalau radionya benar-benar dikirim browser.
       Kalau tidak dikirim, baris yang masih NULL TETAP NULL.
    --------------------------------------------------------------- */
    $scope_stmt = $conn->prepare(
        "INSERT INTO permissions
             (user_id, role, module, can_create, can_read, can_update, can_delete, can_access, can_export, can_view_all)
         VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, ?)
         ON DUPLICATE KEY UPDATE can_view_all = VALUES(can_view_all)"
    );
    if ($scope_stmt === false) {
        permJsonOut([
            'success' => false,
            'message' => (string)$LANG['perm_save_failed'] . ' [DB scope prepare] ' . $conn->error,
        ]);
    }
    if ($scope_stmt) {
        foreach (permScopeModules() as $smod) {
            if (!isset($posted[$smod]['can_view_all'])) continue;
            if (!permScopeAllowed($smod))               continue;

            $newScope = ((string)$posted[$smod]['can_view_all'] === '0') ? 0 : 1;
            $oldScope = isset($before[$smod]) ? $before[$smod]['can_view_all'] : null;

            if (!$scope_stmt->bind_param('issi', $uid, $target_role, $smod, $newScope)
                || !$scope_stmt->execute()) {
                $err = $scope_stmt->error;
                $scope_stmt->close();
                permJsonOut([
                    'success' => false,
                    'message' => (string)$LANG['perm_save_failed'] . ' [DB scope: ' . $smod . '] ' . $err,
                ]);
            }

            if ($oldScope === null || (int)$oldScope !== $newScope) {
                $changes[] = $smod . '.can_view_all ' . ($oldScope === null ? 'NULL' : (string)(int)$oldScope) . '->' . $newScope;
            }
        }
        $scope_stmt->close();
    }

    /* ---------------------------------------------------------------
       PERMISSION KHUSUS (DELEGASI) — HANYA superadmin yang boleh
       mengubahnya. Delegate tidak akan pernah bisa mengangkat delegate
       baru maupun memperluas batasnya sendiri.
    --------------------------------------------------------------- */
    if (isSuperAdmin() && permDelegateColumnsExist() && isset($_POST['deleg_submitted'])) {
        $valid_groups = array_keys(permGroupCatalog());

        $sel = (isset($_POST['deleg_groups']) && is_array($_POST['deleg_groups'])) ? $_POST['deleg_groups'] : [];
        $clean_groups = [];
        foreach ($sel as $g) {
            $g = (string)$g;
            if (in_array($g, $valid_groups, true) && !in_array($g, $clean_groups, true)) {
                $clean_groups[] = $g;
            }
        }
        // Semua grup tercentang = sama artinya dengan "tanpa batas grup"
        if (count($clean_groups) === count($valid_groups)) $clean_groups = [];

        $d_enabled = (isset($_POST['deleg_enabled']) && (string)$_POST['deleg_enabled'] === '1') ? 1 : 0;
        $d_rank    = (isset($_POST['deleg_rank'])    && (string)$_POST['deleg_rank']    === '1') ? 1 : 0;
        $d_groups  = implode(',', $clean_groups);

        $dstmt = $conn->prepare(
            "INSERT INTO permissions
                 (user_id, role, module, can_create, can_read, can_update, can_delete, can_access, can_export,
                  perm_delegate, perm_scope_groups, perm_target_rank)
             VALUES (?, ?, 'users', 0, 0, 0, 0, 0, 0, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 perm_delegate     = VALUES(perm_delegate),
                 perm_scope_groups = VALUES(perm_scope_groups),
                 perm_target_rank  = VALUES(perm_target_rank)"
        );
        if ($dstmt === false) {
            permJsonOut([
                'success' => false,
                'message' => (string)$LANG['perm_save_failed'] . ' [DB delegation prepare] ' . $conn->error,
            ]);
        }
        if ($dstmt) {
            if (!$dstmt->bind_param('isisi', $uid, $target_role, $d_enabled, $d_groups, $d_rank)
                || !$dstmt->execute()) {
                $err = $dstmt->error;
                $dstmt->close();
                permJsonOut([
                    'success' => false,
                    'message' => (string)$LANG['perm_save_failed'] . ' [DB delegation] ' . $err,
                ]);
            }
            $dstmt->close();
            $changes[] = 'delegation.enabled=' . $d_enabled
                       . ' groups=' . ($d_groups === '' ? '*' : $d_groups)
                       . ' lower_role_only=' . $d_rank;
        }
    }

    /* ---------------------------------------------------------------
       LOG AKTIVITAS LENGKAP — siapa mengubah apa, untuk siapa,
       dari nilai berapa ke nilai berapa.
    --------------------------------------------------------------- */
    $actor_id   = (int)($_SESSION['user_id'] ?? 0);
    $actor_name = (string)($_SESSION['username'] ?? ('ID:' . $actor_id));

    $detail = (count($changes) === 0)
        ? (string)$LANG['perm_no_changes']
        : implode('; ', $changes);

    $desc = "Permissions for '" . $uname . "' (ID " . $uid . ", role " . $target_role . ")"
          . " updated by '" . $actor_name . "' (ID " . $actor_id . ") | " . $detail;

    if (strlen($desc) > 60000) {
        $desc = substr($desc, 0, 60000) . ' ...[truncated]';
    }

    logActivity($actor_id, 'update_permissions', $desc);

    /* ROTASI TOKEN DIHAPUS (Tahap 2).

       Baris ini dulu memperbarui $_SESSION['csrf_token'] setiap kali
       penyimpanan berhasil. Token itu BUKAN milik halaman ini saja — ia
       dipakai bersama seluruh tab Settings. Jadi menyimpan permission di
       modal ini membatalkan formulir yang sedang terbuka di tab Users,
       Activity, Purchase, General, Currency, News, dan Profile: penyimpanan
       di sana gagal dengan pesan "token tidak sah" tanpa sebab yang terlihat
       oleh pengguna. Itu bukan efek dari penyatuan token di Tahap 2 —
       masalahnya sudah ada sejak dulu, penyatuan hanya membuatnya kelihatan.

       Kunci 'new_csrf' TETAP dikirim, berisi token yang sekarang, supaya kode
       sisi klien yang membacanya tidak perlu diubah sama sekali. */
    permJsonOut([
        'success'  => true,
        'message'  => (string)$LANG['perm_saved_success'],
        'changed'  => count($changes),
        'new_csrf' => bltx_csrf_token(),
    ]);

    } catch (Throwable $e) {
        /* Jaring pengaman terakhir. Apa pun yang dilempar — termasuk
           mysqli_sql_exception dari environment yang masih memakai mode
           laporan bawaan — dikonversi jadi JSON yang valid, lengkap
           dengan pesan aslinya supaya penyebabnya langsung terbaca di
           toast tanpa perlu membuka DevTools. */
        permJsonOut([
            'success' => false,
            'message' => (string)$LANG['perm_save_failed'] . ' [' . $e->getMessage() . ']',
        ]);
    }
}

/* ===============================
   DATA UNTUK RENDER MODAL
=============================== */

// Seluruh teks yang dipakai JavaScript dikirim sebagai SATU objek JSON.
// Cara ini menggantikan puluhan addslashes() yang dulu rawan rusak bila
// teks bahasa mengandung tanda kutip, ampersand, atau baris baru.
$perm_i18n = [
    'loading'        => $LANG['perm_loading'],
    'load_error'     => $LANG['perm_load_error'],
    'network_error'  => $LANG['perm_network_error'],
    'saving'         => $LANG['perm_saving'],
    'saved_success'  => $LANG['perm_saved_success'],
    'save_failed'    => $LANG['perm_save_failed'],
    'save_btn'       => $LANG['perm_save_btn'],
    'access'         => $LANG['perm_access'],
    'read'           => $LANG['perm_read'],
    'create'         => $LANG['perm_create'],
    'update'         => $LANG['perm_update'],
    'delete'         => $LANG['perm_delete'],
    'export'         => $LANG['perm_export'],
    'scope_label'    => $LANG['perm_dreport_scope_label'],
    'scope_full'     => $LANG['perm_dreport_scope_full'],
    'scope_own'      => $LANG['perm_dreport_scope_own'],
    'scope_unset'    => $LANG['perm_so_scope_unset'],
    'scope_desc'     => $LANG['perm_purchasing_scope_desc'],
    'locked'         => $LANG['perm_locked_badge'],
    'locked_hint'    => $LANG['perm_locked_hint'],
    'success'        => $LANG['success'],
    'error'          => $LANG['error'],
    'info'           => $LANG['info'],
    'warning'        => $LANG['warning'],
    'no_changes'     => $LANG['perm_no_changes'],
    'deleg_title'    => $LANG['perm_delegation_title'],
    'deleg_enable'   => $LANG['perm_delegation_enable'],
    'deleg_enable_d' => $LANG['perm_delegation_enable_desc'],
    'deleg_rank'     => $LANG['perm_delegation_rank'],
    'deleg_rank_d'   => $LANG['perm_delegation_rank_desc'],
    'deleg_groups'   => $LANG['perm_delegation_groups'],
    'deleg_groups_d' => $LANG['perm_delegation_groups_desc'],
    'deleg_missing'  => $LANG['perm_delegation_unavailable'],
];

$perm_json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!-- ================================================================
     PERMISSIONS MODAL — rendered inline, opened via JS from users.php
     Triggered by: managePermissions(userId, role, username)
================================================================ -->
<div class="modal fade" id="permModal" tabindex="-1" aria-labelledby="permModalLabel" aria-hidden="true">
    <div class="modal-dialog" style="width:650px;max-width:95vw;">
        <div class="modal-content bg-white">

            <!-- Modal Header — polos putih -->
            <div class="modal-header bg-white border-bottom">
                <h5 class="modal-title text-dark" id="permModalLabel">
                    <i class="bi bi-shield-lock-fill me-2 text-secondary"></i>
                    <?php echo htmlspecialchars($LANG['permissions'], ENT_QUOTES, 'UTF-8'); ?> &mdash;
                    <span id="perm_user_label" class="fw-bold"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars($LANG['close'], ENT_QUOTES, 'UTF-8'); ?>"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-3 bg-white">
                <form id="permForm" autocomplete="off">
                    <input type="hidden" name="user_id"    id="perm_user_id">
                    <input type="hidden" name="csrf_token" id="perm_csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- Info: showing role default -->
                    <div id="perm_source_info" class="alert alert-warning py-2 px-3 mb-3 d-none small" role="alert">
                        <i class="bi bi-info-circle me-1"></i>
                        <?php echo htmlspecialchars($LANG['perm_source_info'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <!-- Panel PERMISSION KHUSUS (delegasi) — hanya superadmin -->
                    <div id="perm_delegation_box" class="border bg-white p-2 mb-3 d-none" style="border-radius:6px;"></div>

                    <!-- Permissions Tree UI -->
                    <style>
                        .perm-group .group-toggle[aria-expanded="true"]  .icon-expand   { display: none !important; }
                        .perm-group .group-toggle[aria-expanded="false"] .icon-collapse { display: none !important; }
                        .perm-group .group-toggle[aria-expanded="true"]  .icon-collapse { display: inline-block !important; }
                        .perm-group .group-toggle[aria-expanded="false"] .icon-expand   { display: inline-block !important; }
                        .perm-module-row:hover { background-color: #fcfcfc; border-radius: 4px; }
                        .perm-scope-box { background: #fff; border: 1px dashed #dee2e6; }
                        #permToastArea { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
                        #permToastArea .toast { min-width: 260px; }
                        @media (max-width: 575.98px) {
                            #permToastArea { left: 1rem; right: 1rem; top: 0.75rem; }
                            .perm-actions-wrap { gap: 0.75rem !important; }
                        }
                    </style>
                    <div id="permTreeContainer" class="border p-2 mb-3" style="overflow-y:auto;max-height:60vh;background:#fff;border-radius:6px;">
                        <div id="permBody">
                            <div class="text-center text-muted py-4">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                <?php echo htmlspecialchars($LANG['perm_loading'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                    </div><!-- /tree scroll wrapper -->

                    <!-- Legend -->
                    <ul class="text-muted mt-1 lh-lg list-unstyled mb-0" style="font-size:0.72rem;">
                        <li>
                            <i class="bi bi-shield-fill text-secondary"></i> <strong><?php echo htmlspecialchars($LANG['perm_parent_gate_label'], ENT_QUOTES, 'UTF-8'); ?></strong>: <?php echo htmlspecialchars($LANG['perm_parent_gate_desc'], ENT_QUOTES, 'UTF-8'); ?>
                        </li>
                        <li>
                            <i class="bi bi-person-check text-secondary"></i> <strong><?php echo htmlspecialchars($LANG['perm_user_note'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </li>
                        <li>
                            <i class="bi bi-eye-fill text-muted"></i> <strong><?php echo htmlspecialchars($LANG['perm_dreport_scope_label'], ENT_QUOTES, 'UTF-8'); ?></strong> (<?php echo htmlspecialchars($LANG['perm_mod_dreport'], ENT_QUOTES, 'UTF-8'); ?>) = <?php echo htmlspecialchars($LANG['perm_dreport_scope_legend'], ENT_QUOTES, 'UTF-8'); ?>
                        </li>
                        <li>
                            <i class="bi bi-eye-fill text-muted"></i> <strong><?php echo htmlspecialchars($LANG['perm_dreport_scope_label'], ENT_QUOTES, 'UTF-8'); ?></strong> (<?php echo htmlspecialchars($LANG['stock_opname'], ENT_QUOTES, 'UTF-8'); ?>) = <?php echo htmlspecialchars($LANG['perm_so_scope_legend'], ENT_QUOTES, 'UTF-8'); ?>
                        </li>
                        <li>
                            <i class="bi bi-eye-fill text-muted"></i> <strong><?php echo htmlspecialchars($LANG['perm_dreport_scope_label'], ENT_QUOTES, 'UTF-8'); ?></strong> (<?php echo htmlspecialchars($LANG['perm_mod_purchase_orders'], ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($LANG['perm_mod_goods_receipt'], ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($LANG['perm_mod_returns'], ENT_QUOTES, 'UTF-8'); ?>) = <?php echo htmlspecialchars($LANG['perm_purchasing_scope_legend'], ENT_QUOTES, 'UTF-8'); ?>
                        </li>
                    </ul>
                </form>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer border-top bg-white">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i><?php echo htmlspecialchars($LANG['cancel'], ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="button" class="btn btn-sm btn-info" id="btnSavePerm" onclick="savePermissions()">
                    <i class="bi bi-check-circle me-1"></i><?php echo htmlspecialchars($LANG['perm_save_btn'], ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </div>

        </div>
    </div>
</div>

<!-- Wadah toast melayang khusus modal permission -->
<div id="permToastArea" aria-live="polite" aria-atomic="true"></div>

<script>
/* ================================================================
   PERMISSIONS MODAL — JavaScript
   Seluruh teks berasal dari PHP $LANG lewat satu objek JSON
   (PERM_I18N). Struktur grup/modul dan matriks izin dibangun di
   server, jadi tidak ada lagi daftar modul yang di-hardcode di sini.
================================================================ */
(function () {
    'use strict';

    var PERM_I18N   = <?php echo json_encode($perm_i18n, $perm_json_flags); ?>;
    var PERM_URL    = <?php echo json_encode(url('settings/settings_gate.php?tab=users'), $perm_json_flags | JSON_UNESCAPED_SLASHES); ?>;
    var PERM_ACTION = ['can_access', 'can_read', 'can_create', 'can_update', 'can_delete', 'can_export'];
    var PERM_ICON   = {
        can_access: 'bi-unlock',
        can_read:   'bi-eye',
        can_create: 'bi-plus-circle',
        can_update: 'bi-pencil-square',
        can_delete: 'bi-trash',
        can_export: 'bi-file-earmark-pdf'
    };
    var PERM_LABEL  = {
        can_access: PERM_I18N.access,
        can_read:   PERM_I18N.read,
        can_create: PERM_I18N.create,
        can_update: PERM_I18N.update,
        can_delete: PERM_I18N['delete'],
        can_export: PERM_I18N['export']
    };

    var state = { tree: [], bounds: { actions: {}, scope: {}, groups: {} }, isSuper: false };

    // ── Escape HTML: menjaga ' " & < > tampil apa adanya, tanpa XSS ──
    function esc(text) {
        if (text === null || typeof text === 'undefined') return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Toast melayang (menggantikan seluruh popup SweetAlert) ──────
    function permToast(message, type) {
        var kind  = type || 'info';
        var icons = {
            success: 'bi-check-circle-fill text-success',
            error:   'bi-exclamation-circle-fill text-danger',
            warning: 'bi-exclamation-triangle-fill text-warning',
            info:    'bi-info-circle-fill text-primary'
        };
        var titles = {
            success: PERM_I18N.success,
            error:   PERM_I18N.error,
            warning: PERM_I18N.warning,
            info:    PERM_I18N.info
        };

        var $toast = $(
            '<div class="toast show bg-white" role="alert" aria-live="assertive" aria-atomic="true">' +
            '  <div class="toast-header bg-white">' +
            '    <i class="bi ' + (icons[kind] || icons.info) + ' me-2"></i>' +
            '    <strong class="me-auto">' + esc(titles[kind] || titles.info) + '</strong>' +
            '    <button type="button" class="btn-close" aria-label="close"></button>' +
            '  </div>' +
            '  <div class="toast-body">' + esc(message) + '</div>' +
            '</div>'
        ).appendTo('#permToastArea');

        $toast.find('.btn-close').on('click', function () { $toast.remove(); });
        setTimeout(function () {
            $toast.fadeOut(200, function () { $toast.remove(); });
        }, 3500);
    }

    // ── Panel PERMISSION KHUSUS (hanya dirender untuk superadmin) ───
    function renderDelegation(deleg) {
        var $box = $('#perm_delegation_box');

        if (!deleg || !deleg.visible) {
            $box.addClass('d-none').empty();
            return;
        }

        var html = '';
        html += '<div class="fw-semibold text-dark mb-2" style="font-size:0.9rem;">';
        html += '<i class="bi bi-person-fill-gear me-2 text-secondary"></i>' + esc(PERM_I18N.deleg_title);
        html += '</div>';

        if (!deleg.available) {
            html += '<div class="alert alert-warning py-2 px-3 mb-0 small" role="alert">';
            html += '<i class="bi bi-exclamation-triangle me-1"></i>' + esc(PERM_I18N.deleg_missing);
            html += '</div>';
            $box.removeClass('d-none').html(html);
            return;
        }

        html += '<input type="hidden" id="deleg_submitted" value="1">';

        html += '<div class="form-check form-switch mb-1">';
        html += '  <input class="form-check-input" type="checkbox" id="deleg_enabled" ' + (deleg.enabled ? 'checked' : '') + ' style="cursor:pointer;">';
        html += '  <label class="form-check-label small" for="deleg_enabled" style="cursor:pointer;">' + esc(PERM_I18N.deleg_enable) + '</label>';
        html += '</div>';
        html += '<div class="text-muted mb-2" style="font-size:0.72rem;">' + esc(PERM_I18N.deleg_enable_d) + '</div>';

        html += '<div id="deleg_detail" class="' + (deleg.enabled ? '' : 'd-none') + '">';

        html += '  <div class="form-check form-switch mb-1">';
        html += '    <input class="form-check-input" type="checkbox" id="deleg_rank" ' + (deleg.restrict_rank ? 'checked' : '') + ' style="cursor:pointer;">';
        html += '    <label class="form-check-label small" for="deleg_rank" style="cursor:pointer;">' + esc(PERM_I18N.deleg_rank) + '</label>';
        html += '  </div>';
        html += '  <div class="text-muted mb-2" style="font-size:0.72rem;">' + esc(PERM_I18N.deleg_rank_d) + '</div>';

        html += '  <div class="small fw-semibold text-secondary mb-1">' + esc(PERM_I18N.deleg_groups) + '</div>';
        html += '  <div class="d-flex flex-wrap gap-3 mb-1">';

        var picked = deleg.groups || [];
        state.tree.forEach(function (g) {
            var on = (picked.length === 0) || (picked.indexOf(g.key) !== -1);
            html += '    <div class="form-check m-0">';
            html += '      <input class="form-check-input deleg-group" type="checkbox" value="' + esc(g.key) + '" id="deleg_g_' + esc(g.key) + '" ' + (on ? 'checked' : '') + ' style="cursor:pointer;">';
            html += '      <label class="form-check-label small" for="deleg_g_' + esc(g.key) + '" style="cursor:pointer;">' + esc(g.label) + '</label>';
            html += '    </div>';
        });

        html += '  </div>';
        html += '  <div class="text-muted" style="font-size:0.72rem;">' + esc(PERM_I18N.deleg_groups_d) + '</div>';
        html += '</div>';

        $box.removeClass('d-none').html(html);

        $('#deleg_enabled').on('change', function () {
            $('#deleg_detail').toggleClass('d-none', !this.checked);
        });
    }

    // ── Render pohon permission ─────────────────────────────────────
    function renderTree(perms) {
        var html = '';

        state.tree.forEach(function (group, gIdx) {
            var groupAllowed = state.isSuper || (state.bounds.groups[group.key] === true);

            // Grup di luar wewenang delegate yang sedang mengelola permission ini
            // disembunyikan TOTAL (bukan ditampilkan dengan badge "locked") —
            // supaya delegate hanya melihat bagian yang memang bisa ia atur.
            // Ini murni tampilan; validasi sesungguhnya tetap di server saat
            // Save (permActionAllowed/permScopeAllowed), HTML ini bukan pengaman.
            if (!groupAllowed) {
                return;
            }

            var modulesHtml = '';
            var anyModuleVisible = false;

            group.modules.forEach(function (mod) {
                var p = perms[mod.key] || {
                    can_create: 0, can_read: 0, can_update: 0,
                    can_delete: 0, can_access: 0, can_export: 0,
                    can_view_all: null
                };

                var actionsHtml = '';
                var visibleActionCount = 0;

                PERM_ACTION.forEach(function (act) {
                    var allowed = state.isSuper
                        || (!!state.bounds.actions[mod.key] && state.bounds.actions[mod.key][act] === true);

                    // Checkbox yang tidak aktif/di luar wewenang disembunyikan
                    // total, bukan ditampilkan disabled + ikon gembok.
                    if (!allowed) return;

                    visibleActionCount++;
                    var checked = p[act] ? 'checked' : '';

                    actionsHtml += '          <div class="form-check m-0 d-flex align-items-center">';
                    actionsHtml += '            <input class="form-check-input me-2" type="checkbox" name="perm[' + esc(mod.key) + '][' + act + ']" id="cb_' + esc(mod.key) + '_' + act + '" ' + checked + ' style="width:1.1rem;height:1.1rem;cursor:pointer;border-color:#a8b2bc;">';
                    actionsHtml += '            <label class="form-check-label small text-body" for="cb_' + esc(mod.key) + '_' + act + '" style="cursor:pointer;">';
                    actionsHtml += '<i class="bi ' + PERM_ICON[act] + ' me-1 text-muted"></i>' + esc(PERM_LABEL[act]);
                    actionsHtml += '</label>';
                    actionsHtml += '          </div>';
                });

                // Baris cakupan data — hanya untuk modul yang memang memakainya
                // DAN yang cakupannya berada dalam wewenang delegate ini.
                var scopeHtml = '';
                if (mod.scope) {
                    var scopeAllowed = state.isSuper || (state.bounds.scope[mod.key] === true);
                    if (scopeAllowed) {
                        var sAll   = (p.can_view_all === 1) ? 'checked' : '';
                        var sOwn   = (p.can_view_all === 0) ? 'checked' : '';
                        var sUnset = (p.can_view_all !== 1 && p.can_view_all !== 0);

                        scopeHtml += '        <div class="mt-2 ps-4 py-2 rounded perm-scope-box">';
                        scopeHtml += '          <div class="d-flex flex-wrap align-items-center gap-3">';
                        scopeHtml += '            <span class="small fw-semibold text-muted"><i class="bi bi-eye-fill me-1"></i>' + esc(PERM_I18N.scope_label) + ':</span>';
                        scopeHtml += '            <div class="form-check form-check-inline m-0">';
                        scopeHtml += '              <input class="form-check-input" type="radio" name="perm[' + esc(mod.key) + '][can_view_all]" id="' + esc(mod.key) + '_scope_all" value="1" ' + sAll + ' style="width:1.1rem;height:1.1rem;cursor:pointer">';
                        scopeHtml += '              <label class="form-check-label small" for="' + esc(mod.key) + '_scope_all" style="cursor:pointer;">' + esc(PERM_I18N.scope_full) + '</label>';
                        scopeHtml += '            </div>';
                        scopeHtml += '            <div class="form-check form-check-inline m-0">';
                        scopeHtml += '              <input class="form-check-input" type="radio" name="perm[' + esc(mod.key) + '][can_view_all]" id="' + esc(mod.key) + '_scope_own" value="0" ' + sOwn + ' style="width:1.1rem;height:1.1rem;cursor:pointer">';
                        scopeHtml += '              <label class="form-check-label small" for="' + esc(mod.key) + '_scope_own" style="cursor:pointer;">' + esc(PERM_I18N.scope_own) + '</label>';
                        scopeHtml += '            </div>';
                        if (sUnset) {
                            scopeHtml += '            <span class="badge text-bg-light border text-muted" style="font-size:0.68rem;font-weight:500;">' + esc(PERM_I18N.scope_unset) + '</span>';
                        }
                        scopeHtml += '          </div>';
                        scopeHtml += '          <div class="text-muted mt-1" style="font-size:0.72rem;">' + esc(PERM_I18N.scope_desc) + '</div>';
                        scopeHtml += '        </div>';
                    }
                }

                // Modul tanpa satupun checkbox aksi yang boleh ditampilkan, dan
                // tanpa baris cakupan yang boleh ditampilkan, ikut disembunyikan
                // total — supaya tidak ada baris modul yang tampil kosong.
                if (visibleActionCount === 0 && scopeHtml === '') {
                    return;
                }
                anyModuleVisible = true;

                modulesHtml += '      <div class="perm-module-row mb-3 p-1">';
                modulesHtml += '        <div class="fw-semibold text-secondary mb-1 d-flex align-items-center" style="font-size:0.88rem;">';
                modulesHtml += '          <i class="bi bi-arrow-return-right text-muted me-2" style="font-size:0.8rem;"></i>' + esc(mod.label);
                modulesHtml += '        </div>';
                modulesHtml += '        <div class="d-flex flex-wrap gap-3 ps-4 perm-actions-wrap">';
                modulesHtml += actionsHtml;
                modulesHtml += '        </div>';
                modulesHtml += scopeHtml;
                modulesHtml += '      </div>';
            });

            // Grup yang diizinkan tapi kebetulan tidak punya satupun modul yang
            // tampil (mis. seluruh modulnya di luar wewenang) ikut disembunyikan.
            if (!anyModuleVisible) {
                return;
            }

            html += '<div class="perm-group mb-1">';

            html += '  <div class="d-flex align-items-center fw-bold py-2 px-2 rounded group-toggle" style="cursor:pointer; transition:background 0.2s;" data-bs-toggle="collapse" data-bs-target="#group_' + gIdx + '" aria-expanded="false">';
            html += '    <i class="bi bi-plus-square-fill text-secondary me-2 icon-expand" style="font-size:1.15rem;"></i>';
            html += '    <i class="bi bi-dash-square-fill text-secondary me-2 icon-collapse d-none" style="font-size:1.15rem;"></i>';
            html += '    <i class="bi ' + esc(group.icon) + ' me-2 text-secondary"></i>';
            html += '    <span class="text-dark" style="font-size:0.95rem; letter-spacing:0.02em;">' + esc(group.label) + '</span>';
            html += '  </div>';

            html += '  <div class="collapse ms-3" id="group_' + gIdx + '">';
            html += '    <div class="border-start border-2 border-secondary border-opacity-25 py-2 ps-3 pe-1 mb-2">';
            html += modulesHtml;
            html += '    </div>';
            html += '  </div>';
            html += '</div>';
        });

        $('#permBody').html(html);
    }

    // ── managePermissions: buka modal dan muat data ─────────────────
    window.managePermissions = function (userId, role, username) {
        $('#perm_user_id').val(userId);
        $('#perm_user_label').text(String(username) + ' (' + String(role) + ')');
        $('#perm_source_info').addClass('d-none');
        $('#perm_delegation_box').addClass('d-none').empty();
        $('#permBody').html(
            '<div class="text-center text-muted py-4">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>' +
            esc(PERM_I18N.loading) + '</div>'
        );
        $('#permModal').modal('show');

        $.post(
            PERM_URL,
            {
                ajax_load_permissions: 'true',
                user_id:    userId,
                csrf_token: $('#perm_csrf_token').val()
            },
            function (resp) {
                if (!resp || !resp.success) {
                    var msg = (resp && resp.message) ? resp.message : PERM_I18N.load_error;
                    $('#permBody').html('<div class="text-danger text-center py-3">' + esc(msg) + '</div>');
                    permToast(msg, 'error');
                    return;
                }

                state.tree    = resp.tree   || [];
                state.bounds  = resp.bounds || { actions: {}, scope: {}, groups: {} };
                state.isSuper = (resp.is_super === true);

                $('#perm_source_info').toggleClass('d-none', !!resp.has_custom);

                renderDelegation(resp.delegation);
                renderTree(resp.permissions || {});
            },
            'json'
        ).fail(function () {
            $('#permBody').html('<div class="text-danger text-center py-3">' + esc(PERM_I18N.network_error) + '</div>');
            permToast(PERM_I18N.network_error, 'error');
        });
    };

    // ── savePermissions: kumpulkan data lalu POST ───────────────────
    window.savePermissions = function () {
        var userId = $('#perm_user_id').val();
        if (!userId) return;

        var data = {
            ajax_save_permissions: 'true',
            user_id:    userId,
            csrf_token: $('#perm_csrf_token').val()
        };

        state.tree.forEach(function (group) {
            group.modules.forEach(function (mod) {
                PERM_ACTION.forEach(function (act) {
                    var $cb = $('input[name="perm[' + mod.key + '][' + act + ']"]');
                    if (!$cb.length) return;
                    data['perm[' + mod.key + '][' + act + ']'] = $cb.is(':checked') ? '1' : '0';
                });

                // Cakupan data hanya dikirim bila admin benar-benar memilih
                // salah satu radio; kalau masih "belum diatur", field ini
                // SENGAJA tidak dikirim supaya nilai NULL tetap NULL.
                if (mod.scope) {
                    var v = $('input[name="perm[' + mod.key + '][can_view_all]"]:checked').val();
                    if (typeof v !== 'undefined') {
                        data['perm[' + mod.key + '][can_view_all]'] = v;
                    }
                }
            });
        });

        // Konfigurasi permission khusus — elemen ini hanya ada bila superadmin
        if ($('#deleg_submitted').length) {
            data['deleg_submitted'] = '1';
            data['deleg_enabled']   = $('#deleg_enabled').is(':checked') ? '1' : '0';
            data['deleg_rank']      = $('#deleg_rank').is(':checked')    ? '1' : '0';
            var groups = [];
            $('.deleg-group:checked').each(function () { groups.push($(this).val()); });
            data['deleg_groups'] = groups;
        }

        var $btn = $('#btnSavePerm');
        $btn.prop('disabled', true)
            .html('<div class="spinner-border spinner-border-sm me-1" role="status"></div> ' + esc(PERM_I18N.saving));

        $.ajax({
            url:      PERM_URL,
            type:     'POST',
            data:     data,
            dataType: 'json',
            success: function (resp) {
                if (resp && resp.success) {
                    if (resp.new_csrf) { $('#perm_csrf_token').val(resp.new_csrf); }
                    $('#perm_source_info').addClass('d-none');
                    permToast(
                        (resp.changed === 0) ? PERM_I18N.no_changes : PERM_I18N.saved_success,
                        (resp.changed === 0) ? 'info' : 'success'
                    );
                    $('#permModal').modal('hide');
                } else {
                    permToast((resp && resp.message) ? resp.message : PERM_I18N.save_failed, 'error');
                }
            },
            error: function (xhr) {
                /* Kalau server sempat mengirim HTML error (misalnya fatal
                   di luar jangkauan try/catch), jQuery gagal mem-parse JSON
                   dan dulu Save terlihat "diam saja". Sekarang potongan
                   respons aslinya ditampilkan di toast, jadi penyebabnya
                   terbaca langsung tanpa membuka DevTools. */
                var raw = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
                raw = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                permToast(
                    raw ? (PERM_I18N.save_failed + ' [' + raw.substring(0, 300) + ']')
                        : PERM_I18N.network_error,
                    'error'
                );
            },
            complete: function () {
                $btn.prop('disabled', false)
                    .html('<i class="bi bi-check-circle me-1"></i>' + esc(PERM_I18N.save_btn));
            }
        });
    };

    /* ------------------------------------------------------------------
       PENTING — JANGAN pasang binding jQuery di level atas file ini.

       includes/footer.php memuat jquery.min.js, dan footer.php baru
       di-include di settings/settings_gate.php — SESUDAH settings/users.php
       yang meng-include file ini (settings_permissions.php). Artinya pada
       saat blok <script> ini dieksekusi browser, variabel $ MASIH UNDEFINED.

       Versi sebelumnya memakai:
           $(document).on('click', '#btnSavePerm', ...);
       Baris itu dieksekusi saat parse, melempar "ReferenceError: $ is
       not defined", dan menghentikan sisa script. Dua assignment
       window.* di atas sudah terlanjur berhasil, sehingga tombol 🛡️
       tetap jalan (dipanggil lewat onclick, saat jQuery sudah ada)
       tetapi tombol Save tidak pernah punya handler — kliknya diam
       saja tanpa toast dan tanpa request ke server.

       Solusinya sama seperti kode asli: tombol Save memakai atribut
       onclick="savePermissions()" di HTML-nya. Atribut inline baru
       dievaluasi SAAT DIKLIK, ketika jQuery sudah pasti termuat, jadi
       aman terhadap urutan pemuatan script mana pun.

       Semua pemakaian $ lain di file ini berada DI DALAM fungsi,
       sehingga hanya berjalan setelah pengguna berinteraksi — aman.
    ------------------------------------------------------------------ */

}());
</script>