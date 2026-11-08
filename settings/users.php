<?php
/* settings/users.php — FRAGMEN TAB dari settings/settings_gate.php.
   Stanza guard baku Tahap 0.3; lihat penjelasan dua-baris di general.php. */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment('SETTINGS_GATE');

/* Token CSRF dari helper terpusat. Kunci sesi dan cara bandingnya SAMA
   PERSIS seperti sebelumnya ($_SESSION['csrf_token'] + hash_equals); yang
   hilang hanya pembentukan token yang ditulis ulang di berkas ini. */
$csrfToken   = bltx_csrf_token();
$__users_self = url('settings/settings_gate.php?tab=users');

/* ===============================
   HELPER PERMISSION TERPUSAT
   Menyediakan permCanDeleteUser(), permSealUserNames(), dan
   permZeroFillNewUser() yang dipakai tombol Delete & aksi Add.
   require_once aman dipanggil berulang; settings_gate.php sudah
   memuatnya lebih dulu, ini hanya penjamin urutan.
=============================== */
require_once BASE_PATH . '/includes/global_permission.php';
require_once BASE_PATH . '/includes/ui_table.php';

/* ===============================
   ROUTE: Delegate permissions AJAX to settings_permissions.php
   Both ajax_load_permissions and ajax_save_permissions are handled there.
=============================== */
if (
    (isset($_POST['ajax_load_permissions']) && $_POST['ajax_load_permissions'] === 'true') ||
    (isset($_POST['ajax_save_permissions']) && $_POST['ajax_save_permissions'] === 'true')
) {
    require_once BASE_PATH . '/settings/settings_permissions.php';
    exit;
}

/* ===============================
   AJAX UPDATE STATUS - HARUS DI PALING ATAS
=============================== */
if (isset($_POST['ajax_update_status']) && $_POST['ajax_update_status'] == 'true') {
    ob_clean();
    header('Content-Type: application/json');

    /* TAHAP 2 — LUBANG YANG DITUTUP: endpoint ini sebelumnya TIDAK memeriksa
       token CSRF sama sekali, dan sisi JS-nya juga tidak mengirimkannya.
       Akibatnya sebuah situs lain cukup mem-POST ke URL ini selagi seorang
       pengelola sedang login untuk menonaktifkan akun mana pun yang berada
       di bawah rank-nya. bltx_csrf_require() mengenali permintaan XHR dan
       menjawab JSON 403, jadi bentuk balasannya tetap sesuai harapan klien.

       Gerbang izin di bawah TIDAK diubah: tetap permCanManageTargetUser(),
       fungsi terpusat yang sama dengan tombol Permissions. Menambah
       checkPermission('users','can_update') di sini akan menciptakan logic
       permission kedua untuk hal yang sudah punya satu (B1 poin 7). */
    bltx_csrf_require();

    
    if (isset($_POST['id']) && isset($_POST['is_active'])) {
        $id = intval($_POST['id']);
        $is_active = intval($_POST['is_active']);
        
        // Cek role user yang akan diubah
        $check = $conn->query("SELECT role FROM users WHERE id = $id");
        $target_role = ($check && $row = $check->fetch_assoc()) ? $row['role'] : '';
        
        if ($target_role === 'superadmin') {
            echo json_encode(['success' => false, 'message' => 'Cannot change superadmin status']);
            exit;
        }
        
        // Gerbang permrolerank — pakai fungsi terpusat yang SAMA dengan tombol
        // Permissions (permCanManageTargetUser di includes/global_permission.php),
        // supaya tidak ada logic permission ganda: superadmin selalu bisa, delegate
        // hanya untuk role di bawah rank-nya sesuai perm_target_rank, selain itu ditolak.
        if (!function_exists('permCanManageTargetUser') || !permCanManageTargetUser($id, $target_role)) {
            echo json_encode(['success' => false, 'message' => $LANG['perm_denied_target']]);
            exit;
        }
        
        // Mencegah user menonaktifkan diri sendiri
        if ($id === (int)$_SESSION['user_id'] && $is_active == 0) {
            echo json_encode(['success' => false, 'message' => 'You cannot deactivate your own account']);
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $is_active, $id);
        
        if ($stmt->execute()) {
            logActivity($_SESSION['user_id'], 'update_user_status', "Changed user ID $id status to " . ($is_active ? 'active' : 'inactive'));
            echo json_encode(['success' => true, 'message' => 'Status updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
    }
    exit;
}

/* ===============================
   HANDLE FORM SUBMISSION
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_update_status'])) {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' && (isSuperAdmin() || checkPermission('users', 'can_create'))) {
            bltx_csrf_require($__users_self);

            $username = sanitize($_POST['username']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $full_name = sanitize($_POST['full_name']);
            $role = sanitize($_POST['role']);

            // Validasi panjang username & full name sisi server (maxlength di HTML
            // bukan pengaman — validasi ulang di sini wajib untuk very high security)
            if (strlen($username) > 10) {
                $_SESSION['error'] = $LANG['username_too_long'];
                redirect(url('settings/settings_gate.php?tab=users'));
            }
            if (strlen($full_name) > 20) {
                $_SESSION['error'] = $LANG['full_name_too_long'];
                redirect(url('settings/settings_gate.php?tab=users'));
            }
            
            // Cek batas superadmin maksimal 2
            if ($role === 'superadmin') {
                $sa_check = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = 'superadmin'");
                $sa_count = ($sa_check) ? (int)$sa_check->fetch_assoc()['cnt'] : 0;
                if ($sa_count >= 2) {
                    $_SESSION['error'] = 'Superadmin limit reached (max 2)';
                    redirect(url('settings/settings_gate.php?tab=users'));
                }
            }

            // Cek duplikat username
            $dup_stmt = $conn->prepare("SELECT id, role FROM users WHERE username = ?");
            $dup_stmt->bind_param("s", $username);
            $dup_stmt->execute();
            $dup_result = $dup_stmt->get_result();
            if ($dup_result && $dup_result->num_rows > 0) {
                $dup_row = $dup_result->fetch_assoc();
                if ($dup_row['role'] === $role) {
                    $_SESSION['error'] = sprintf($LANG['username_already_used'], htmlspecialchars($username));
                    redirect(url('settings/settings_gate.php?tab=users'));
                }
                $username = $username . '_' . substr(uniqid(), -4);
            }

            // Kolom email tidak lagi diisi lewat modal ini (dihapus sesuai permintaan).
            // Kolom tetap NOT NULL di database — disimpan string kosong, aman & tidak
            // melanggar constraint.
            /* ─────────────────────────────────────────────────────────────
               PEMBUATAN AKUN + ZERO-FILL PERMISSION, DALAM SATU TRANSACTION.

               Akun baru WAJIB lahir tanpa permission apa pun. Tanpa
               langkah zero-fill, akun baru otomatis mewarisi 210 baris
               default role (permissions.user_id IS NULL) karena urutan
               baca permission adalah:
                   baris role (user_id IS NULL) -> DITIMPA -> baris user
               Dengan menulis baris milik user bernilai NOL untuk seluruh
               modul, default role tertimpa habis sehingga akun benar-benar
               kosong sampai admin mengaturnya.

               210 baris default role TIDAK DISENTUH SAMA SEKALI, jadi
               seluruh akun lama tidak terpengaruh sedikit pun.

               Kalau zero-fill gagal, seluruh transaction di-ROLLBACK
               sehingga akun TIDAK jadi dibuat — lebih baik gagal total
               daripada lahir akun setengah jadi yang mewarisi akses.
            ───────────────────────────────────────────────────────────── */
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (?, ?, ?, '', ?, 1)");
                if (!$stmt) {
                    throw new RuntimeException('prepare_insert_user_failed');
                }
                $stmt->bind_param("ssss", $username, $password, $full_name, $role);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('insert_user_failed');
                }
                $new_user_id = (int)$stmt->insert_id;
                $stmt->close();

                if ($new_user_id <= 0) {
                    throw new RuntimeException('insert_user_no_id');
                }

                if (!permZeroFillNewUser($new_user_id, $role)) {
                    throw new RuntimeException('zero_fill_failed');
                }

                $conn->commit();

                logActivity(
                    $_SESSION['user_id'],
                    'add_user',
                    "Added user: {$username} (ID {$new_user_id}, role {$role}) | "
                    . "permissions initialised to ZERO for all modules"
                );

                $_SESSION['success'] = $LANG['save_success']
                                     . ' ' . $LANG['new_user_zero_permission'];
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['error'] = $LANG['add_user_failed'];
            }
            redirect(url('settings/settings_gate.php?tab=users&success=1'));
            
        } elseif ($_POST['action'] === 'edit' && (isSuperAdmin() || checkPermission('users', 'can_update'))) {
            bltx_csrf_require($__users_self);

            $id = intval($_POST['id']);
            $username = sanitize($_POST['username']);
            $full_name = sanitize($_POST['full_name']);

            // Validasi panjang username & full name sisi server
            if (strlen($username) > 10) {
                $_SESSION['error'] = $LANG['username_too_long'];
                redirect(url('settings/settings_gate.php?tab=users'));
            }
            if (strlen($full_name) > 20) {
                $_SESSION['error'] = $LANG['full_name_too_long'];
                redirect(url('settings/settings_gate.php?tab=users'));
            }

            // Role SAAT INI di database (sebelum diedit) — dipakai untuk validasi
            // wewenang, BUKAN role baru yang dikirim, supaya permrolerank selalu
            // dicek terhadap kondisi target yang sebenarnya.
            $current_role_fetch = $conn->query("SELECT role FROM users WHERE id = $id");
            $current_target_role = ($current_role_fetch && $ctr = $current_role_fetch->fetch_assoc()) ? $ctr['role'] : '';

            // Gerbang permrolerank — fungsi terpusat yang SAMA dengan toggle status
            // & tombol Permissions (permCanManageTargetUser di global_permission.php),
            // supaya tidak ada logic permission ganda. HTML disabled di modal bukan
            // pengaman; ini validasi sesungguhnya.
            if (!isSuperAdmin() && (!function_exists('permCanManageTargetUser') || !permCanManageTargetUser($id, $current_target_role))) {
                $_SESSION['error'] = $LANG['perm_denied_target'];
                redirect(url('settings/settings_gate.php?tab=users'));
            }

            // Role baru: pakai yang dikirim user, atau pertahankan role saat ini
            // kalau tidak dikirim.
            $role = !empty($_POST['role']) ? sanitize($_POST['role']) : $current_target_role;

            // Non-superadmin tidak boleh menetapkan role yang rank-nya setara atau
            // lebih tinggi dari rank sendiri — mencegah eskalasi lewat request yang
            // dimanipulasi langsung (dropdown yang sudah difilter bukan pengaman).
            if (!isSuperAdmin() && function_exists('permRoleRank')) {
                $own_rank_for_edit = permRoleRank($_SESSION['role'] ?? '');
                if (permRoleRank($role) <= $own_rank_for_edit) {
                    $_SESSION['error'] = $LANG['perm_denied_target'];
                    redirect(url('settings/settings_gate.php?tab=users'));
                }
            }
            
            // Proteksi agar user yang sedang login tidak sengaja mengubah rolenya sendiri dari superadmin
            if ($id === (int)$_SESSION['user_id'] && $role !== 'superadmin') {
                $_SESSION['error'] = $LANG['cannot_change_own_role'];
                redirect(url('settings/settings_gate.php?tab=users'));
            }
            
            // Khusus superadmin status dipaksa tetap aktif (1)
            $is_active = ($role === 'superadmin') ? 1 : (isset($_POST['is_active']) ? 1 : 0);
            
            // Proteksi nonaktifkan diri sendiri
            if ($id === (int)$_SESSION['user_id'] && $is_active == 0) {
                $_SESSION['error'] = $LANG['cannot_deactivate_own_account'];
                redirect(url('settings/settings_gate.php?tab=users'));
            }
            
            // Cek duplikat username (exclude user yang sedang diedit)
            $dup_stmt2 = $conn->prepare("SELECT id, role FROM users WHERE username = ? AND id != ?");
            $dup_stmt2->bind_param("si", $username, $id);
            $dup_stmt2->execute();
            $dup_result2 = $dup_stmt2->get_result();
            if ($dup_result2 && $dup_result2->num_rows > 0) {
                $dup_row2 = $dup_result2->fetch_assoc();
                if ($dup_row2['role'] === $role) {
                    $_SESSION['error'] = sprintf($LANG['username_already_used'], htmlspecialchars($username));
                    redirect(url('settings/settings_gate.php?tab=users'));
                }
                $username = $username . '_' . substr(uniqid(), -4);
            }

            // Kolom email TIDAK ikut diupdate (field-nya dihapus dari modal edit) —
            // nilai email yang sudah ada di database dibiarkan apa adanya, tidak ditimpa kosong.
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET username=?, password=?, full_name=?, role=?, is_active=? WHERE id=?");
                $stmt->bind_param("ssssii", $username, $password, $full_name, $role, $is_active, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username=?, full_name=?, role=?, is_active=? WHERE id=?");
                $stmt->bind_param("sssii", $username, $full_name, $role, $is_active, $id);
            }

            if ($stmt->execute()) {
                logActivity($_SESSION['user_id'], 'update_user', "Updated user: $username (ID: $id)");
                $_SESSION['success'] = $LANG['update_success'];
            }
            redirect(url('settings/settings_gate.php?tab=users&success=1'));
        }
    }
}

/* ===============================
   HANDLE DELETE USER  (POST tradisional, very high security)

   URUTAN EKSEKUSI — setiap langkah wajib lolos sebelum ke langkah
   berikutnya, dan seluruhnya dibungkus SATU transaction:

     1. CSRF   : hash_equals() terhadap token sesi
     2. Target : id > 0 dan barisnya benar-benar ada (dikunci FOR UPDATE)
     3. Wewenang: permCanDeleteUser() di includes/global_permission.php —
                  memakai can_delete modul 'users' YANG SUDAH TERSEDIA di
                  database, ditambah permrolerank. Tidak ada modul baru,
                  tidak ada permission ganda.
     4. Diri sendiri  : ditolak
     5. Superadmin    : ditolak (permCanDeleteUser sudah menolak)
     6. SEGEL NAMA    : permSealUserNames() mengisi kolom nama permanen
                        di SELURUH tabel yang menyimpan users.id, hanya
                        pada baris yang namanya masih kosong. Ini jaring
                        pengaman supaya tidak ada nama pelaku yang hilang.
     7. Arsip nama    : REPLACE INTO user_name_archive dengan data
                        TERBARU sebelum barisnya lenyap.
     8. DELETE permissions WHERE user_id = ? AND user_id IS NOT NULL
                        Ditulis EKSPLISIT, tidak menumpang FK CASCADE,
                        supaya permission tetap terhapus walaupun FK-nya
                        suatu saat hilang (restore backup / pindah host).
                        Klausa `= ?` dengan bind integer TIDAK MUNGKIN
                        mencocokkan baris user_id IS NULL, jadi 210 baris
                        default role DIJAMIN tidak tersentuh.
     9. DELETE users WHERE id = ?
    10. Verifikasi   : sisa baris permission milik id itu HARUS 0
    11. COMMIT       — kalau satu saja gagal, ROLLBACK penuh
    12. Hapus foto profil (di luar transaction, setelah COMMIT sukses)
    13. logActivity() lengkap

   TIDAK ADA satu pun tabel transaksi yang dihapus di sini. Baris
   inventory, purchases, purchase_orders, goods_receipts, stock_opname,
   payroll, activity_logs, dan seluruh tabel lain tetap utuh.
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'delete') {

    // 1. CSRF
    bltx_csrf_require($__users_self);

    $del_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($del_id <= 0) {
        $_SESSION['error'] = $LANG['delete_user_failed'];
        redirect(url('settings/settings_gate.php?tab=users'));
    }

    // 4. Tidak pernah boleh menghapus akun sendiri (dicek lebih awal
    //    supaya pesannya spesifik, bukan sekadar "tidak berwenang").
    if ($del_id === (int)$_SESSION['user_id']) {
        $_SESSION['error'] = $LANG['cannot_delete_self'];
        redirect(url('settings/settings_gate.php?tab=users'));
    }

    $del_done  = false;
    $del_photo = '';
    $del_msg   = $LANG['delete_user_failed'];

    $conn->begin_transaction();
    try {
        // 2. Ambil & KUNCI baris target selama transaction berjalan,
        //    supaya tidak ada perubahan role/status di tengah jalan.
        $sel = $conn->prepare(
            "SELECT id, username, full_name, role, profile_image
               FROM users WHERE id = ? LIMIT 1 FOR UPDATE"
        );
        if (!$sel) {
            throw new RuntimeException('prepare_select_failed');
        }
        $sel->bind_param('i', $del_id);
        $sel->execute();
        $target = $sel->get_result()->fetch_assoc();
        $sel->close();

        if (!$target) {
            $del_msg = $LANG['delete_user_failed'];
            throw new RuntimeException('target_not_found');
        }

        $t_username  = (string)$target['username'];
        $t_full_name = (string)$target['full_name'];
        $t_role      = (string)$target['role'];
        $del_photo   = (string)($target['profile_image'] ?? '');

        // 5. Superadmin tidak pernah bisa dihapus.
        if ($t_role === 'superadmin') {
            $del_msg = $LANG['cannot_delete_superadmin'];
            throw new RuntimeException('target_is_superadmin');
        }

        // 3. Gerbang wewenang terpusat — fungsi yang sama dipakai untuk
        //    menampilkan/menyembunyikan tombolnya di tabel. HTML yang
        //    disembunyikan BUKAN pengaman; inilah validasi sebenarnya.
        if (!function_exists('permCanDeleteUser') || !permCanDeleteUser($del_id, $t_role)) {
            $del_msg = $LANG['perm_denied_target'];
            throw new RuntimeException('not_authorized');
        }

        // 6. SEGEL NAMA di seluruh tabel sebelum akunnya lenyap.
        $sealed = 0;
        if (function_exists('permSealUserNames')) {
            $sealed = permSealUserNames($del_id, $t_full_name);
        }

        // 7. Arsip nama diperbarui ke kondisi TERBARU. Tabel
        //    user_name_archive sengaja tanpa foreign key sehingga
        //    barisnya tidak ikut terhapus.
        $arc = $conn->prepare(
            "REPLACE INTO user_name_archive (user_id, username, full_name, role)
             VALUES (?, ?, ?, ?)"
        );
        if ($arc) {
            $arc->bind_param('isss', $del_id, $t_username, $t_full_name, $t_role);
            $arc->execute();
            $arc->close();
        }

        // 8. Hapus permission milik user ini SAJA.
        //    `user_id = ?` + bind integer tidak pernah cocok dengan NULL,
        //    dan `AND user_id IS NOT NULL` adalah sabuk pengaman kedua
        //    supaya 210 baris default role mustahil tersentuh.
        $perm_deleted = 0;
        $dp = $conn->prepare("DELETE FROM permissions WHERE user_id = ? AND user_id IS NOT NULL");
        if (!$dp) {
            throw new RuntimeException('prepare_delete_permissions_failed');
        }
        $dp->bind_param('i', $del_id);
        if (!$dp->execute()) {
            $dp->close();
            throw new RuntimeException('delete_permissions_failed');
        }
        $perm_deleted = $dp->affected_rows;
        $dp->close();

        // 9. Hapus barisnya di tabel users.
        $du = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'superadmin'");
        if (!$du) {
            throw new RuntimeException('prepare_delete_user_failed');
        }
        $du->bind_param('i', $del_id);
        if (!$du->execute() || $du->affected_rows !== 1) {
            $du->close();
            throw new RuntimeException('delete_user_failed');
        }
        $du->close();

        // 10. Verifikasi: tidak boleh ada sisa permission milik ID ini.
        $vf = $conn->prepare("SELECT COUNT(*) AS cnt FROM permissions WHERE user_id = ?");
        if ($vf) {
            $vf->bind_param('i', $del_id);
            $vf->execute();
            $left = (int)$vf->get_result()->fetch_assoc()['cnt'];
            $vf->close();
            if ($left > 0) {
                throw new RuntimeException('permission_leftover');
            }
        }

        // 11. Semua lolos.
        $conn->commit();
        $del_done = true;

        logActivity(
            $_SESSION['user_id'],
            'delete_user',
            "Deleted user '{$t_username}' (ID {$del_id}, role {$t_role}, name '{$t_full_name}') | "
            . "permission rows removed: {$perm_deleted} | "
            . "actor names sealed in {$sealed} row(s) | "
            . "no transaction data deleted"
        );

    } catch (Throwable $e) {
        $conn->rollback();
        $del_done = false;
    }

    // 12. Foto profil dihapus HANYA setelah COMMIT berhasil.
    //     basename() + pemeriksaan direktori mencegah nilai profile_image
    //     yang aneh menghapus berkas di luar folder uploads/profile.
    if ($del_done && $del_photo !== '') {
        $photo_dir  = rtrim(UPLOAD_PATH, '/\\') . '/profile/';
        $photo_file = $photo_dir . basename($del_photo);
        $real_dir   = realpath($photo_dir);
        $real_file  = realpath($photo_file);
        if ($real_dir !== false && $real_file !== false
            && strpos($real_file, $real_dir) === 0
            && is_file($real_file)) {
            @unlink($real_file);
        }
    }

    if ($del_done) {
        /* Rotasi token dihapus (Tahap 2). Token kini dipakai bersama seluruh
           tab Settings; memutarnya di sini membatalkan formulir yang sedang
           terbuka di tab lain, sehingga penyimpanan di sana gagal tanpa sebab
           yang jelas. Pengulangan permintaan yang sama tetap tidak berbahaya:
           penghapusan bersifat idempoten dan id yang sudah hilang ditolak. */
        $_SESSION['success'] = $LANG['delete_user_success'];
    } else {
        $_SESSION['error'] = $del_msg;
    }

    redirect(url('settings/settings_gate.php?tab=users'));
}

/* ===============================
   QUERY USERS
=============================== */
$superadmin_count_result = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = 'superadmin'");
$superadmin_count = ($superadmin_count_result) ? (int)$superadmin_count_result->fetch_assoc()['cnt'] : 0;

// Daftar role yang boleh dipilih di dropdown Add/Edit, mengikuti permrolerank —
// superadmin bebas semua role; selain itu hanya role dengan rank DI BAWAH rank
// akun yang sedang login (permRoleRank lebih besar = otoritas lebih rendah).
// Aturan & fungsi ini SAMA PERSIS dengan yang sudah dipakai toggle status &
// tombol Permissions (permCanManageTargetUser), supaya tidak ada logic
// permission ganda.
$all_role_options = [
    'admin'      => $LANG['admin'],
    'manager'    => $LANG['manager'],
    'supervisor' => $LANG['supervisor'],
    'hr'         => $LANG['hr'],
    'user'       => $LANG['user'],
    'cashier'    => $LANG['cashier'],
];
$own_role_rank = function_exists('permRoleRank') ? permRoleRank($_SESSION['role'] ?? '') : 99;
$assignable_roles = [];
foreach ($all_role_options as $role_key => $role_label) {
    if (isSuperAdmin() || (function_exists('permRoleRank') && permRoleRank($role_key) > $own_role_rank)) {
        $assignable_roles[$role_key] = $role_label;
    }
}

// Cek dan buat kolom last_activity secara otomatis jika belum ada
$check_activity_col = $conn->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
if ($check_activity_col && $check_activity_col->num_rows == 0) {
    @$conn->query("ALTER TABLE users ADD COLUMN last_activity DATETIME NULL");
}

// Update timestamp aktivitas user saat ini
if (isset($_SESSION['user_id'])) {
    $current_uid = (int)$_SESSION['user_id'];
    @$conn->query("UPDATE users SET last_activity = NOW() WHERE id = $current_uid");
}

// Daftar user: role selain superadmin TIDAK melihat baris superadmin di tabel.
// Difilter di level query (bukan di-skip saat render) supaya lebih cepat untuk
// data besar dan datanya memang tidak pernah terkirim ke browser non-superadmin.
if (isSuperAdmin()) {
    $result = $conn->query("SELECT id, username, full_name, email, role, is_active, profile_image, created_at, last_activity FROM users ORDER BY created_at DESC");
} else {
    $result = $conn->query("SELECT id, username, full_name, email, role, is_active, profile_image, created_at, last_activity FROM users WHERE role != 'superadmin' ORDER BY created_at DESC");
}
?>

<div class="row">
    
    <?php
    /* Toast melayang (A4.1) lewat helper Tahap 0.4 — menggantikan dua blok
       HTML+JS sepanjang ±34 baris yang isinya identik dengan berkas lain. */
    bltx_render_flash('users');
    ?>
    
    <div class="col-12">
        <div class="card">
            <div class="card-header">
            <div class="card-title">
                    <i class="bi bi-person-badge-fill"></i> <?php echo $LANG['users']; ?>
                </div>
                <?php if (isSuperAdmin() || checkPermission('users', 'can_create')): ?>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> <?php echo $LANG['add']; ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php
                /* Tabel manual diganti helper ui_table_* (item 2). Kolom foto,
                   status, dan aksi ditandai 'orderable' => false — persis
                   columnDefs targets [0, 5, 6] pada init lama. Urutan awal dan
                   panjang halaman juga dipindah ke sini, sehingga init
                   DataTables di berkas ini bisa dihapus dan tabel ini memakai
                   init tunggal di includes/footer.php seperti tabel lain. */
                ui_table_open('usersTable', [
                    'columns' => [
                        ['label' => $LANG['photo'],     'orderable' => false],
                        ['label' => $LANG['username']],
                        ['label' => $LANG['full_name']],
                        ['label' => $LANG['email']],
                        ['label' => $LANG['role']],
                        ['label' => $LANG['status'],    'orderable' => false],
                        ['label' => $LANG['actions'],   'orderable' => false,
                         'align' => 'center', 'width' => 180],
                    ],
                    'small'       => true,
                    'striped'     => false,
                    'page_length' => 25,
                    'order'       => [1, 'asc'],
                ]);
                ?>
                        <?php 
                        if ($result && $result->num_rows > 0):
                            while ($row = $result->fetch_assoc()): 
                                // Cek status online (aktif dalam 2 menit terakhir / 120 detik)
                                $is_online = false;
                                $last_seen_text = $LANG['offline'];
                                
                                if (!empty($row['last_activity'])) {
                                    $last_time = strtotime($row['last_activity']);
                                    if ((time() - $last_time) <= 120) {
                                        $is_online = true;
                                        $last_seen_text = $LANG['online_just_now'];
                                    } else {
                                        $last_seen_text = ($LANG['last_active']) . $row['last_activity'];
                                    }
                                }
                                
                                // Paksa user yang sedang memegang session ini bernilai online
                                if (isset($_SESSION['user_id']) && (int)$row['id'] === (int)$_SESSION['user_id']) {
                                    $is_online = true;
                                    $last_seen_text = $LANG['online_you'];
                                }
                        ?>
                            <tr>
                                <td>
                                    <?php if (!empty($row['profile_image'])): ?>
                                        <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                             data-src="<?php echo url('uploads/profile/' . htmlspecialchars($row['profile_image'])); ?>" 
                                             alt="<?php echo htmlspecialchars($row['username']); ?>"
                                             class="rounded-circle lazy-photo"
                                             width="36" height="36"
                                             style="object-fit:cover;">
                                    <?php else: ?>
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary text-white" style="width:36px;height:36px;font-size:1rem;">
                                            <i class="bi bi-person-fill"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td>
                                    <span class="d-inline-block rounded-circle align-middle bg-<?php echo $is_online ? 'success' : 'secondary'; ?> me-2" style="width: 10px; height: 10px;" title="<?php echo $last_seen_text; ?>" data-bs-toggle="tooltip"></span>
                                    <?php echo htmlspecialchars($row['full_name']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $row['role'] === 'superadmin' ? 'danger' : 'primary'; ?>">
                                        <?php echo $LANG[$row['role']] ?? ucfirst($row['role']); ?>
                                    </span>
                                </td>
                                <?php
                                /* Wewenang atas baris ini (permrolerank + delegate), dihitung SEKALI
                                   lalu dipakai ulang untuk: toggle status, tombol Edit, tombol
                                   Permissions, dan data yang dikirim ke modal Edit lewat JSON —
                                   supaya tidak ada logic permission ganda dan tidak query berulang.
                                   function_exists() menjaga halaman tetap hidup seandainya
                                   global_permission.php belum sempat termuat. */
                                $can_manage_perm = function_exists('permCanManageTargetUser')
                                    ? permCanManageTargetUser((int)$row['id'], (string)$row['role'])
                                    : (isSuperAdmin() && $row['role'] !== 'superadmin');
                                // Disisipkan ke $row supaya ikut ke JSON data-user yang dibaca JS
                                // saat modal Edit dibuka (untuk mengunci dropdown role & toggle
                                // aktif/nonaktif sesuai permrolerank).
                                $row['_rank_ok'] = $can_manage_perm ? 1 : 0;
                                ?>
                                <td>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" 
                                               class="form-check-input status-toggle" 
                                               data-id="<?php echo $row['id']; ?>" 
                                               <?php echo (!$can_manage_perm) ? 'disabled' : ''; ?> 
                                               <?php echo $row['is_active'] ? 'checked' : ''; ?>>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php
                                    // Tombol Edit — permission dari database: modul 'users' can_update
                                    // (atau superadmin), DITAMBAH target harus berada di bawah
                                    // permrolerank akun yang sedang login ($can_manage_perm, fungsi
                                    // yang sama dengan toggle status & tombol Permissions di atas).
                                    $can_edit_user = isSuperAdmin()
                                        || (checkPermission('users', 'can_update') && $can_manage_perm);
                                    ?>
                                    <?php if ($can_edit_user): ?>
                                    <button class="btn btn-warning btn-sm" 
                                            data-user='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>' 
                                            onclick="editUser(this)"
                                            title="<?php echo $LANG['edit']; ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($can_manage_perm): ?>
                                    <button type="button" class="btn btn-info btn-sm"
                                            data-perm-id="<?php echo (int)$row['id']; ?>"
                                            data-perm-role="<?php echo htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-perm-user="<?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="managePermissions(this.dataset.permId, this.dataset.permRole, this.dataset.permUser)"
                                            title="<?php echo htmlspecialchars($LANG['permissions'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="bi bi-shield-lock"></i>
                                    </button>
                                    <?php endif; ?>

                                    <?php
                                    /* Tombol Delete — wewenangnya dihitung oleh SATU fungsi
                                       terpusat di includes/global_permission.php yang memakai
                                       permission can_delete modul 'users' YANG SUDAH ADA di
                                       database (sebelumnya belum dipakai kode mana pun), jadi
                                       tidak ada modul baru dan tidak ada permission ganda.
                                       Fungsi yang sama dipanggil ulang di server saat POST
                                       diterima — menyembunyikan tombol BUKAN pengaman. */
                                    $can_delete_user = function_exists('permCanDeleteUser')
                                        ? permCanDeleteUser((int)$row['id'], (string)$row['role'])
                                        : false;
                                    ?>
                                    <?php if ($can_delete_user): ?>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            data-del-id="<?php echo (int)$row['id']; ?>"
                                            data-del-user="<?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="deleteUser(this.dataset.delId, this.dataset.delUser)"
                                            title="<?php echo htmlspecialchars($LANG['delete_user'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php 
                            endwhile;
                        else:
                            /* ui_table_empty() sengaja dipakai menggantikan baris
                               colspan manual: pada tabel yang di-DataTable-kan ia
                               TIDAK mencetak apa pun, karena satu baris bersel
                               tunggal di dalam <tbody> memicu peringatan
                               "Incorrect column count". Pesan kosongnya sudah
                               ditangani language.emptyTable di footer.php. */
                            ui_table_empty(7, $LANG['no_data']);
                        endif;
                        ui_table_close();
                        ?>
            </div>
        </div>
    </div>
    
</div>

<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('settings/settings_gate.php?tab=users'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus-fill text-primary"></i> <?php echo $LANG['add']; ?> <?php echo $LANG['user']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['username']; ?></label>
                        <input type="text" name="username" class="form-control" maxlength="10" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['password']; ?></label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['full_name']; ?></label>
                        <input type="text" name="full_name" class="form-control" maxlength="20" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['role']; ?></label>
                        <select name="role" class="form-select" required>
                            <?php foreach ($assignable_roles as $role_key => $role_label): ?>
                            <option value="<?php echo $role_key; ?>"><?php echo $role_label; ?></option>
                            <?php endforeach; ?>
                            <?php if (isSuperAdmin() && $superadmin_count < 2): ?>
                            <option value="superadmin"><?php echo $LANG['superadmin']; ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> <?php echo $LANG['cancel']; ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-circle"></i> <?php echo $LANG['save']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('settings/settings_gate.php?tab=users'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square text-warning"></i> <?php echo $LANG['edit']; ?> <?php echo $LANG['user']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="role" id="edit_role_hidden">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['username']; ?></label>
                        <input type="text" name="username" id="edit_username" class="form-control" maxlength="10" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">
                            <?php echo $LANG['password']; ?> 
                            <small class="text-muted">(<?php echo $LANG['leave_blank']; ?>)</small>
                        </label>
                        <input type="password" name="password" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['full_name']; ?></label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control" maxlength="20" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['role']; ?></label>
                        <select name="role" id="edit_role" class="form-select" required>
                            <?php foreach ($assignable_roles as $role_key => $role_label): ?>
                            <option value="<?php echo $role_key; ?>"><?php echo $role_label; ?></option>
                            <?php endforeach; ?>
                            <?php if (isSuperAdmin()): ?>
                            <option value="superadmin"><?php echo $LANG['superadmin']; ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                        <label class="form-check-label">
                            <?php echo $LANG['status']; ?> (<?php echo $LANG['active']; ?>)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> <?php echo $LANG['cancel']; ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-circle"></i> <?php echo $LANG['update']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     FORM DELETE — POST TRADISIONAL, BUKAN AJAX.
     Dikirim oleh deleteUser() setelah konfirmasi SweetAlert2. Memakai
     form biasa supaya alurnya identik dengan Add/Edit: server memvalidasi
     ulang seluruhnya, lalu redirect dan menampilkan notifikasi melayang
     lewat $_SESSION['success'] / $_SESSION['error'].
     ═══════════════════════════════════════════════════════════════ -->
<form method="POST" id="deleteUserForm"
      action="<?php echo url('settings/settings_gate.php?tab=users'); ?>"
      class="d-none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_user_id">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
</form>

<?php
// Include the permissions modal (rendered inline, opened via JS)
require_once BASE_PATH . '/settings/settings_permissions.php';
?>

<!-- TAHAP 2 — PEMUATAN ULANG ASET DIHAPUS.
     Berkas ini memuat lagi jQuery, DataTables (JS), dan SweetAlert2 (CSS+JS)
     lewat tag script src, padahal includes/footer.php sudah memuat keempatnya.
     jQuery yang dimuat dua kali mengganti instans pertama beserta seluruh
     plugin yang sudah terdaftar padanya — DataTables dan komponen Bootstrap
     bisa berhenti bekerja di tab ini tanpa satu pun pesan error. -->
<script>
(function (f) { document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', f) : f(); })(function() {
    
    /* Init DataTables lokal DIHAPUS. Tabel ini kini dibuka lewat
       ui_table_open(), yang memasang kelas .ui-datatable, dan
       includes/footer.php meng-inisialisasinya — satu init untuk seluruh
       sistem. Urutan awal, panjang halaman, dan kolom yang tidak bisa
       diurutkan disampaikan sebagai opsi ui_table_open() di atas. */

    // Sinkronkan hidden input role saat dropdown diubah
    $(document).on('change', '#edit_role', function() {
        $('#edit_role_hidden').val($(this).val());
    });

    // Status toggle dengan Konfirmasi SweetAlert2 sebelum eksekusi AJAX
    $(document).on('change', '.status-toggle', function(e) {
        e.preventDefault();
        
        const userId = $(this).data('id');
        const isActive = $(this).is(':checked') ? 1 : 0;
        const $toggle = $(this);
        
        Swal.fire({
            title: '<?php echo $LANG['confirm_status_change']; ?>',
            text: "<?php echo $LANG['confirm_status_text']; ?>",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: '<?php echo $LANG['yes']; ?>',
            cancelButtonText: '<?php echo $LANG['cancel']; ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?php echo url('settings/settings_gate.php?tab=users'); ?>',
                    type: 'POST',
                    data: {
                        ajax_update_status: 'true',
                        id:         userId,
                        is_active:  isActive,
                        // WAJIB: endpoint kini memeriksa token (Tahap 2).
                        csrf_token: <?php echo bltx_js(bltx_csrf_token()); ?>
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        $toggle.prop('disabled', true);
                    },
                    success: function(resp) {
                        if (resp.success) {
                            showFloatingAlert(<?php echo bltx_js($LANG['status_updated']); ?>, 'success');
                        } else {
                            showFloatingAlert(resp.message || <?php echo bltx_js($LANG['status_update_failed']); ?>, 'error');
                            $toggle.prop('checked', !$toggle.is(':checked'));
                        }
                    },
                    error: function(xhr, status, error) {
                        showFloatingAlert(<?php echo bltx_js($LANG['network_error']); ?> + error, 'error');
                        $toggle.prop('checked', !$toggle.is(':checked'));
                    },
                    complete: function() {
                        $toggle.prop('disabled', false);
                    }
                });
            } else {
                // Kembalikan posisi toggle jika dibatalkan
                $toggle.prop('checked', !$toggle.is(':checked'));
            }
        });
    });
});

function editUser(btn) {
    let d = JSON.parse($(btn).attr('data-user'));
    let currentStatusInTable = $(btn).closest('tr').find('.status-toggle').is(':checked');
    const superadminCount = <?php echo (int)$superadmin_count; ?>;

    $('#edit_id').val(d.id);
    $('#edit_username').val(d.username);
    $('#edit_full_name').val(d.full_name);
    $('#edit_role').val(d.role.toLowerCase());
    $('#edit_role_hidden').val(d.role.toLowerCase());
    
    const $saOption = $('#edit_role option[value="superadmin"]');
    if ($saOption.length) {
        if (d.role === 'superadmin' || superadminCount < 2) {
            $saOption.show().prop('disabled', false);
        } else {
            $saOption.hide().prop('disabled', true);
        }
    }
    
    $('#edit_is_active').prop('checked', currentStatusInTable);
    
    if (d.role.toLowerCase() === 'superadmin') {
        $('#edit_role').prop('disabled', true);
        $('#edit_is_active').prop('disabled', true);
        $('#edit_is_active').prop('checked', true);
    } else if (!d._rank_ok) {
        // Target di luar wewenang permrolerank akun yang sedang login (mis.
        // delegate dengan rank tidak mengizinkan) — dropdown role & toggle
        // aktif/nonaktif dikunci, field lain (username/full name/password)
        // tetap bisa diedit. Fungsi wewenangnya sama dengan yang dipakai
        // toggle status & tombol Permissions di tabel (permCanManageTargetUser).
        $('#edit_role').prop('disabled', true);
        $('#edit_is_active').prop('disabled', true);
    } else {
        $('#edit_role').prop('disabled', false);
        $('#edit_is_active').prop('disabled', false);
    }
    
    $('#editModal').modal('show');
}

/* ═══════════════════════════════════════════════════════════════
   HAPUS AKUN USER
   Konfirmasi SweetAlert2 lebih dulu, baru form POST tradisional
   dikirim. Nama user disisipkan lewat parameter %s pada string bahasa
   sehingga tanda baca seperti ' " & tetap tampil apa adanya.
   ═══════════════════════════════════════════════════════════════ */
function deleteUser(userId, username) {
    var tpl  = <?php echo json_encode((string)$LANG['confirm_delete_user_text'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var body = tpl.replace('%s', username);

    Swal.fire({
        title: <?php echo json_encode((string)$LANG['confirm_delete_user'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        text: body,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: <?php echo json_encode((string)$LANG['delete'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        cancelButtonText: <?php echo json_encode((string)$LANG['cancel'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        reverseButtons: true,
        focusCancel: true
    }).then(function (result) {
        if (result.isConfirmed) {
            $('#delete_user_id').val(userId);
            $('#deleteUserForm').trigger('submit');
        }
    });
}

/* Modal Permissions membuat token CSRF baru setiap kali Save berhasil,
   dan hanya field miliknya sendiri yang ikut diperbarui. Tanpa sinkron
   di bawah, tombol Add / Edit / Delete di halaman ini masih memegang
   token lama sehingga request berikutnya ditolak. Disalin saat modal
   ditutup, murni di dalam file ini. */
$(document).on('hidden.bs.modal', '#permModal', function () {
    var fresh = $('#perm_csrf_token').val();
    if (fresh) {
        $('#addModal input[name="csrf_token"], #editModal input[name="csrf_token"], #deleteUserForm input[name="csrf_token"]').val(fresh);
    }
});

// Lazy load photo dengan IntersectionObserver
(function() {
    const imgs = document.querySelectorAll('img.lazy-photo');
    if (!imgs.length) return;
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    observer.unobserve(img);
                }
            });
        }, { rootMargin: '100px' });
        imgs.forEach(function(img) { observer.observe(img); });
    } else {
        imgs.forEach(function(img) { img.src = img.dataset.src; });
    }
})();
</script>