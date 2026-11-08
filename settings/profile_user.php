<?php
/* =============================================================================
   settings/profile_user.php
   -----------------------------------------------------------------------------
   User's own profile page: profile picture, username, email, PHONE, password.

   Scope of this refactor (nothing else was touched):
     1. New "Phone" field, rendered immediately AFTER the Email field, backed by
        the new `users`.`phone` column (see profile_user_phone_migration.sql).
     2. Access guard is now purely session based — only a logged-in user may
        reach this page (the old HTTP_REFERER test wrongly 403'd logged-in users
        who typed the URL or used a bookmark).
     3. Full multi-language, NO fallback strings, English as the hard default.
     4. Raw-value storage + escape-on-output, so ' " & ! * and newlines survive
        a round trip without ever becoming &amp; or a literal \r\n.
     5. Per-column activity logging, now including the phone number.

   Asset loading (bootstrap, bootstrap-icons, datatables, jquery, sweetalert2 …)
   is entirely handled by includes/header.php + includes/footer.php through
   url('assets/vendor/...'), so this page contains no hard-coded paths.

   Multi-currency: this page renders no monetary value, so no formatCurrency()
   call is needed. The navbar currency switcher (?currency=) keeps working
   because the form posts to itself without an `action` attribute.
   ========================================================================== */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';

/* -----------------------------------------------------------------------------
   1. ACCESS GUARD — halaman mandiri, hanya untuk sesi yang sudah login.

   Memakai bltx_guard_page() dari Tahap 0.3. Berbeda dari versi lama yang
   membalas 403 polos: pengunjung yang belum login sekarang DIALIHKAN ke
   halaman login lengkap dengan ?next=, sehingga setelah masuk ia kembali
   ke halaman ini — bukan menabrak layar teks kosong.

   Tanpa argumen modul: setiap orang berhak menyunting profilnya sendiri,
   jadi tidak ada permission yang perlu diperiksa.
-------------------------------------------------------------------------------*/
bltx_guard_page();

/* -----------------------------------------------------------------------------
   2. LANGUAGE — per-user session language, English as the hard default
   Resolved BEFORE the POST branch so validation messages come out in the
   language the user actually selected, not in the global DB setting.
   Same precedence header.php uses: session -> DB setting -> 'en'.
-------------------------------------------------------------------------------*/
$lang_code = $_SESSION['lang'] ?? getSetting('language', 'en');
if (!in_array($lang_code, ['en', 'id', 'ch'], true)) {
    $lang_code = 'en';
}
$LANG = loadLanguage($lang_code);

$page_title = $LANG['profile'];

/* -----------------------------------------------------------------------------
   3. LOCAL HELPERS
   pu_in()  keeps the value exactly as typed (SQL safety = prepared statement,
            XSS safety = pu_esc() at output). It only normalises line endings
            and strips control characters, so &, ', ", !, * and spaces survive.
   pu_esc() single escape point for every echoed value.
-------------------------------------------------------------------------------*/
if (!function_exists('pu_in')) {
    function pu_in($value, int $max_len = 0): string {
        $value = (string)$value;
        // CRLF / CR -> LF, so a literal \r\n can never be stored
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Drop control characters, keep LF (\x0A) and TAB (\x09)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = trim($value);
        if ($max_len > 0) {
            $value = function_exists('mb_substr')
                ? mb_substr($value, 0, $max_len, 'UTF-8')
                : substr($value, 0, $max_len);
        }
        return $value;
    }
}
if (!function_exists('pu_esc')) {
    function pu_esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
/** Permissive international phone check: allowed characters + 7..20 digits. */
if (!function_exists('pu_valid_phone')) {
    function pu_valid_phone(string $phone): bool {
        if (!preg_match('/^[0-9+()\.\-\s]+$/', $phone)) {
            return false;
        }
        $digits = strlen(preg_replace('/\D+/', '', $phone));
        return ($digits >= 7 && $digits <= 20);
    }
}

/* -----------------------------------------------------------------------------
   4. LOAD CURRENT USER — one prepared statement, primary-key lookup, LIMIT 1
   Cheapest possible read; scales identically no matter how large `users` grows.
-------------------------------------------------------------------------------*/
$user_id = (int)$_SESSION['user_id'];
$user    = null;

$stmt = $conn->prepare(
    "SELECT id, username, full_name, email, phone, role, is_active, profile_image
       FROM users
      WHERE id = ?
      LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

if (!$user) {
    // Session points at a row that no longer exists — send back to the login page.
    redirect(url('index.php'));
}

// Normalise to plain strings so every later comparison is strict and predictable
$user['username']      = (string)($user['username']      ?? '');
$user['full_name']     = (string)($user['full_name']     ?? '');
$user['email']         = (string)($user['email']         ?? '');
$user['phone']         = (string)($user['phone']         ?? '');
$user['profile_image'] = (string)($user['profile_image'] ?? '');
$user['role']          = (string)($user['role']          ?? '');

/* -----------------------------------------------------------------------------
   5. FORM SUBMISSION
-------------------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $self = url('settings/profile_user.php');

    /* Token CSRF diperiksa PALING AWAL, sebelum satu nilai pun dibaca dari
       $_POST. Sebelumnya halaman ini tidak punya token sama sekali, sehingga
       situs lain bisa memaksa perubahan username, email, dan KATA SANDI
       korban hanya dengan formulir tersembunyi. */
    bltx_csrf_require($self);

    // Column widths: username VARCHAR(50), email VARCHAR(100), phone VARCHAR(25)
    $username_input = pu_in($_POST['username'] ?? '', 50);
    $email_input    = pu_in($_POST['email']    ?? '', 100);
    $phone_input    = pu_in($_POST['phone']    ?? '', 25);
    $password_input = (isset($_POST['password']) && $_POST['password'] !== '') ? $_POST['password'] : null;

    // 5a. Required fields (also blocks an Inspect-Element bypass of `required`)
    if ($username_input === '' || $email_input === '') {
        bltx_flash_error($LANG['fill_required_fields']);
        redirect($self);
    }

    // 5b. Email format
    if (!filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
        bltx_flash_error($LANG['invalid_email_format']);
        redirect($self);
    }

    // 5c. Phone format — optional field, only validated when filled in
    if ($phone_input !== '' && !pu_valid_phone($phone_input)) {
        bltx_flash_error($LANG['invalid_phone_format']);
        redirect($self);
    }

    // 5d. Duplicate username (ignoring the row being edited)
    $dup = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
    if ($dup) {
        $dup->bind_param('si', $username_input, $user_id);
        $dup->execute();
        $dup->store_result();
        $is_duplicate = ($dup->num_rows > 0);
        $dup->close();
        if ($is_duplicate) {
            bltx_flash_error(sprintf($LANG['username_already_used'], $username_input));
            redirect($self);
        }
    }

    // 5e. Per-column change tracking (drives the activity log)
    $photo_changed    = false;
    $username_changed = ($username_input !== $user['username']);
    $email_changed    = ($email_input    !== $user['email']);
    $phone_changed    = ($phone_input    !== $user['phone']);
    $password_changed = ($password_input !== null);

    $uploaded_file = $user['profile_image'];

    // 5f. Profile picture upload (extension + real MIME type both verified)
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $tmp_name      = $_FILES['profile_image']['tmp_name'];
        $ext           = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];

        $image_info = @getimagesize($tmp_name);

        if (in_array($ext, $allowed_exts, true) && $image_info && in_array($image_info['mime'], $allowed_mimes, true)) {
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
            $upload_dir   = UPLOAD_PATH . 'profile/';

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $target_path = $upload_dir . $new_filename;

            if (move_uploaded_file($tmp_name, $target_path)) {
                // Remove the previous file so the server does not accumulate orphans
                if ($user['profile_image'] !== '' && is_file($upload_dir . $user['profile_image'])) {
                    @unlink($upload_dir . $user['profile_image']);
                }
                $uploaded_file             = $new_filename;
                $_SESSION['profile_image'] = $uploaded_file; // keep the navbar avatar in sync
                $photo_changed             = true;
            }
        } else {
            bltx_flash_error($LANG['invalid_file_type']);
            redirect($self);
        }
    }

    // 5g. Nothing changed -> skip the write entirely
    if (!$photo_changed && !$username_changed && !$email_changed && !$phone_changed && !$password_changed) {
        $_SESSION['swal_no_changes'] = $LANG['no_changes'];
        redirect($self);
    }

    // 5h. Write. Empty phone is stored as NULL, never as an empty string.
    $phone_db = ($phone_input === '') ? null : $phone_input;

    if ($password_changed) {
        $hashed_pass = password_hash($password_input, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "UPDATE users
                SET username = ?, email = ?, phone = ?, password = ?, profile_image = ?
              WHERE id = ?
              LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('sssssi', $username_input, $email_input, $phone_db, $hashed_pass, $uploaded_file, $user_id);
        }
    } else {
        $stmt = $conn->prepare(
            "UPDATE users
                SET username = ?, email = ?, phone = ?, profile_image = ?
              WHERE id = ?
              LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('ssssi', $username_input, $email_input, $phone_db, $uploaded_file, $user_id);
        }
    }

    if ($stmt && $stmt->execute()) {
        $stmt->close();

        // Keep the session in sync with what the navbar / other pages read
        $_SESSION['username'] = $username_input;
        $_SESSION['email']    = $email_input;

        /* == ACTIVITY LOG — one entry per changed column (kept in English,
              matching the convention used by the rest of the system) == */
        $actor = ($user['full_name'] !== '') ? $user['full_name'] : $user['username'];

        if ($photo_changed) {
            logActivity('UPDATE_PROFILE', "{$actor} updated the profile picture (new file: '{$uploaded_file}').");
        }
        if ($username_changed) {
            logActivity('UPDATE_PROFILE', "{$actor} changed the username from '{$user['username']}' to '{$username_input}'.");
        }
        if ($email_changed) {
            logActivity('UPDATE_PROFILE', "{$actor} changed the email from '{$user['email']}' to '{$email_input}'.");
        }
        if ($phone_changed) {
            $old_phone = ($user['phone'] === '') ? '(empty)' : $user['phone'];
            $new_phone = ($phone_input   === '') ? '(empty)' : $phone_input;
            logActivity('UPDATE_PROFILE', "{$actor} changed the phone number from '{$old_phone}' to '{$new_phone}'.");
        }
        if ($password_changed) {
            logActivity('UPDATE_PROFILE', "{$actor} updated the password.");
        }

        bltx_flash_success($LANG['update_success']);
        redirect($self);
    }

    if ($stmt) {
        $stmt->close();
    }
    bltx_flash_error($LANG['update_failed']);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">

            <div class="page-title-bar">
                <h2 class="page-title-text">
                    <i class="bi bi-person-circle"></i> <?php echo pu_esc($LANG['profile']); ?>
                </h2>
            </div>

            <?php
            /* Toast melayang (A4.1) lewat helper Tahap 0.4. Menggantikan dua
               blok alert inline yang mendorong konten turun, dan sekarang
               menampung juga pesan validasi yang dulu memakai modal
               SweetAlert2 — B2 poin 5: SweetAlert2 hanya untuk dialog
               konfirmasi, tidak untuk notifikasi hasil. */
            bltx_render_flash('profile');
            ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <?php bltx_csrf_field(); ?>

                        <div class="text-center mb-4">
                            <div id="profilePicWrapper">
                                <?php if ($user['profile_image'] !== '' && is_file(UPLOAD_PATH . 'profile/' . $user['profile_image'])): ?>
                                    <img src="<?php echo url('uploads/profile/' . rawurlencode($user['profile_image'])); ?>"
                                         class="rounded-circle" width="120" height="120" alt="Profile" style="object-fit:cover; border: 3px solid #e9ecef;">
                                <?php else: ?>
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary text-white"
                                          style="width:120px;height:120px;font-size:3rem;flex-shrink:0; border: 3px solid #e9ecef;">
                                        <i class="bi bi-person-fill"></i>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Full Name, Role & Status -->
                            <div class="mt-3">
                                <h5 class="mb-1 fw-bold"><?php echo pu_esc($user['full_name'] !== '' ? $user['full_name'] : $user['username']); ?></h5>
                                <div class="d-flex justify-content-center flex-wrap gap-2 mt-2">
                                    <span class="badge bg-primary px-3 py-2 text-uppercase"><?php echo pu_esc($user['role']); ?></span>
                                    <?php if (!empty($user['is_active'])): ?>
                                        <span class="badge bg-success px-3 py-2"><?php echo pu_esc($LANG['active']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger px-3 py-2"><?php echo pu_esc($LANG['inactive']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="pu_profile_image" class="form-label fw-semibold"><i class="bi bi-image text-muted"></i> <?php echo pu_esc($LANG['profile_image']); ?></label>
                            <input type="file" id="pu_profile_image" name="profile_image" class="form-control" accept="image/png, image/jpeg, image/gif" onchange="showProfilePreview(this)">
                        </div>

                        <div class="mb-3">
                            <label for="pu_username" class="form-label fw-semibold"><i class="bi bi-person text-muted"></i> <?php echo pu_esc($LANG['username']); ?></label>
                            <input type="text" id="pu_username" name="username" class="form-control" maxlength="50" value="<?php echo pu_esc($user['username']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="pu_email" class="form-label fw-semibold"><i class="bi bi-envelope text-muted"></i> <?php echo pu_esc($LANG['email']); ?></label>
                            <input type="email" id="pu_email" name="email" class="form-control" maxlength="100" value="<?php echo pu_esc($user['email']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="pu_phone" class="form-label fw-semibold"><i class="bi bi-telephone text-muted"></i> <?php echo pu_esc($LANG['phone']); ?></label>
                            <input type="tel" id="pu_phone" name="phone" class="form-control" maxlength="25" inputmode="tel" autocomplete="tel"
                                   value="<?php echo pu_esc($user['phone']); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="pu_password" class="form-label fw-semibold"><i class="bi bi-key text-muted"></i> <?php echo pu_esc($LANG['password']); ?> <small class="text-secondary">(<?php echo pu_esc($LANG['leave_blank']); ?>)</small></label>
                            <input type="password" id="pu_password" name="password" class="form-control" autocomplete="new-password">
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-between">
                            <a href="javascript:history.back()" class="btn btn-sm btn-secondary px-4">
                                <i class="bi bi-arrow-left"></i> <?php echo pu_esc($LANG['back']); ?>
                            </a>
                            <button type="submit" class="btn btn-sm btn-primary px-4">
                                <i class="bi bi-save2"></i> <?php echo pu_esc($LANG['save']); ?>
                            </button>
                        </div>

                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
// Real-time photo preview with border styling
function showProfilePreview(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var container = document.getElementById('profilePicWrapper');
            container.innerHTML = '<img src="' + e.target.result + '" class="rounded-circle" width="120" height="120" alt="Profile Preview" style="object-fit:cover; border: 3px solid #0d6efd;">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<!-- SweetAlert2 triggers (SweetAlert2 itself is loaded by includes/footer.php) -->
<?php
/* Blok notifikasi SweetAlert2 di sini DIHAPUS. Seluruh pesan hasil kini
   lewat bltx_render_flash() di atas. SweetAlert2 tetap dipakai di berkas
   lain, tetapi hanya untuk dialog konfirmasi ya/tidak. */
?>

<?php if (isset($_SESSION['swal_no_changes'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: 'info',
        title: <?php echo json_encode($LANG['no_changes'], JSON_UNESCAPED_UNICODE); ?>,
        html: <?php echo json_encode($_SESSION['swal_no_changes'], JSON_UNESCAPED_UNICODE); ?>,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: <?php echo json_encode($LANG['close'], JSON_UNESCAPED_UNICODE); ?>
    });
});
</script>
<?php unset($_SESSION['swal_no_changes']); endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>