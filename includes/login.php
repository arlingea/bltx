<?php
/* ═════════════════════════════════════════════════════════════════════════
 * includes/login.php
 * ────────────────────────────────────────────────────────────────────────
 * Login page logic + view. Included ONLY by /index.php.
 * ═════════════════════════════════════════════════════════════════════════ */

if (!defined('BLTX_LOGIN_ENTRY')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

require_once __DIR__ . '/config.php';

$error = '';

// Inisialisasi bahasa di halaman login berdasarkan cookie tersimpan (jika ada)
if (!isset($_SESSION['lang'])) {
    // Cek cookie guest atau default setting
    $_guest_lang_cookie = '__bltx_lang_guest';
    if (isset($_COOKIE[$_guest_lang_cookie]) && in_array($_COOKIE[$_guest_lang_cookie], ['en', 'id', 'ch'], true)) {
        $_SESSION['lang'] = $_COOKIE[$_guest_lang_cookie];
    } else {
        $_SESSION['lang'] = getSetting('language', 'en');
    }
}
$LANG = loadLanguage($_SESSION['lang']);

// Already logged in → redirect to first accessible page
if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? '';
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $dest = getFirstAccessiblePage($uid, $role);
    if ($dest) {
        redirect($dest);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_unset();
        session_destroy();
    }
    $error = $LANG['no_permission'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = $LANG['fill_required_fields'];
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, full_name, email, role, is_active, profile_image FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            [$id, $db_username, $db_password, $full_name, $email, $role, $is_active, $profile_image] =
                [(int)$row['id'], $row['username'], $row['password'], $row['full_name'], $row['email'], $row['role'], (int)$row['is_active'], $row['profile_image']];

            if ($is_active == 1) {
                if (password_verify($password, $db_password)) {
                    $_SESSION['user_id']       = $id;
                    $_SESSION['username']      = $db_username;
                    $_SESSION['full_name']     = $full_name;
                    $_SESSION['email']         = $email;
                    $_SESSION['role']          = $role;
                    $_SESSION['profile_image'] = $profile_image;
                    $_SESSION['login_time']    = time();

                    // Load preferensi Bahasa spesifik User dari Cookie jika ada, jika tidak pakai default sistem
                    $user_lang_cookie = '__bltx_lang_' . $id;
                    if (isset($_COOKIE[$user_lang_cookie]) && in_array($_COOKIE[$user_lang_cookie], ['en', 'id', 'ch'], true)) {
                        $_SESSION['lang'] = $_COOKIE[$user_lang_cookie];
                    }

                    // Load preferensi Mata Uang spesifik User dari Cookie jika ada
                    $user_curr_cookie = '__bltx_curr_' . $id;
                    if (isset($_COOKIE[$user_curr_cookie]) && in_array($_COOKIE[$user_curr_cookie], ['IDR', 'USD', 'CNY'], true)) {
                        $_SESSION['currency'] = $_COOKIE[$user_curr_cookie];
                    } else {
                        $_SESSION['currency'] = getSetting('currency', 'IDR');
                    }

                    if (function_exists('loadPermissionsToSession')) {
                        loadPermissionsToSession();
                    }

                    logActivity($id, 'login', "User '{$db_username}' logged in");

                    $next = $_GET['next'] ?? $_POST['next'] ?? '';
                    if ($next !== '' && preg_match('#^/[^/\\\\]#', $next) && !preg_match('#//#', $next)) {
                        if (strpos($next, '/index.php') === false) {
                            redirect($next);
                        }
                    }

                    $dest = getFirstAccessiblePage($id, $role);
                    if ($dest) {
                        redirect($dest);
                    } else {
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            $p = session_get_cookie_params();
                            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                            session_unset();
                            session_destroy();
                        }
                        $error = $LANG['no_permission'];
                    }
                } else {
                    $error = $LANG['login_failed'];
                }
            } else {
                $error = $LANG['account_inactive'];
            }
        } else {
            $error = $LANG['login_failed'];
        }
        $stmt->close();
    }
}

$_theme = (isset($_COOKIE['__bltx_theme']) && $_COOKIE['__bltx_theme'] === 'dark') ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'en'); ?>" data-bs-theme="<?php echo $_theme; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="<?php echo $_theme === 'dark' ? 'dark' : 'light'; ?>">
<meta name="theme-color" content="<?php echo $_theme === 'dark' ? '#1e293b' : '#f8fafc'; ?>">
<title><?php echo $LANG['login']; ?> &mdash; <?php echo APP_NAME; ?></title>
<link rel="icon" href="<?= url('assets/arw-favicon.svg') ?>" type="image/svg+xml">

<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/adminlte/css/adminlte.min.css') ?>">

<style>
/* ═════════════════════════════════════════════════════════════════════════
   Kitchen-Themed Animated Background & Integrated UI System (Light Blue Accent)
   ═════════════════════════════════════════════════════════════════════════ */
:root {
    --brand-primary:   #3b82f6; /* Light Blue Accent */
    --brand-primary-hover: #2563eb;
    --card-bg-light:   rgba(255, 255, 255, 0.9);
    --text-main:       #0f172a;
    --text-muted:      #475569;
    --border-color:    rgba(203, 213, 225, 0.6);
    --input-bg:        rgba(248, 250, 252, 0.8);
}

[data-bs-theme="dark"] {
    --card-bg-light:   rgba(15, 23, 42, 0.82);
    --text-main:       #f8fafc;
    --text-muted:      #94a3b8;
    --border-color:    rgba(51, 65, 85, 0.8);
    --input-bg:        rgba(30, 41, 59, 0.7);
}

html, body { 
    height: 100%; 
    margin: 0; 
}

body.login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    /* Font diseragamkan dengan includes/header.php. Sebelumnya halaman login
       memakai 'Inter' — font yang TIDAK ada di assets/vendor/, jadi browser
       selalu jatuh ke system-ui dan tampilannya berbeda dari halaman lain. */
    font-family: 'Source Sans 3', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    color: var(--text-main);
    position: relative;
    overflow: hidden;
    background-color: #f1f5f9;
}

[data-bs-theme="dark"] body.login-page {
    background-color: #0f172a;
}

/* ── Brighter, More Transparent Kitchen Ambience Background Animation ── */
body.login-page::before {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    z-index: 0;
    background: 
        radial-gradient(circle at 20% 30%, rgba(59, 130, 246, 0.15) 0%, transparent 45%),
        radial-gradient(circle at 80% 70%, rgba(14, 165, 233, 0.12) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(245, 158, 11, 0.08) 0%, transparent 55%),
        linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #f1f5f9 100%);
    animation: kitchenAmbience 18s ease infinite alternate;
}

[data-bs-theme="dark"] body.login-page::before {
    background: 
        radial-gradient(circle at 20% 30%, rgba(59, 130, 246, 0.2) 0%, transparent 45%),
        radial-gradient(circle at 80% 70%, rgba(14, 165, 233, 0.15) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(245, 158, 11, 0.1) 0%, transparent 55%),
        linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
}

/* Floating Cooking Element Emulations */
body.login-page::after {
    content: "🍳 🍲 👨‍🍳 🔪 🍅 🥕 🥘 🌿";
    position: absolute;
    width: 100%;
    height: 100%;
    z-index: 0;
    font-size: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: space-around;
    opacity: 0.12;
    filter: blur(0.5px);
    animation: floatingKitchen 25s linear infinite;
    pointer-events: none;
    letter-spacing: 4rem;
}

@keyframes kitchenAmbience {
    0% { transform: scale(1) translate(0, 0) rotate(0deg); }
    50% { transform: scale(1.06) translate(-1.5%, 2%) rotate(1deg); }
    100% { transform: scale(1.02) translate(1.5%, -1.5%) rotate(-1deg); }
}

@keyframes floatingKitchen {
    0% { transform: translateY(105vh) rotate(0deg); }
    100% { transform: translateY(-105vh) rotate(360deg); }
}

.login-box {
    width: 100%;
    max-width: 400px;
    position: relative;
    z-index: 1;
    animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Card Styling (Harmonized Glassmorphism) */
.card.login-card {
    background-color: var(--card-bg-light);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08), 0 0 1px 1px rgba(255, 255, 255, 0.4) inset;
    overflow: hidden;
}

[data-bs-theme="dark"] .card.login-card {
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4), 0 0 1px 1px rgba(255, 255, 255, 0.05) inset;
}

.card.login-card .card-body {
    padding: 2.25rem 2rem;
}

/* Header Logo / Branding */
.login-header {
    text-align: center;
    margin-bottom: 1.75rem;
}

.login-header img {
    width: 52px;
    height: 52px;
    object-fit: contain;
    margin-bottom: 0.75rem;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
}

.login-box-msg {
    color: var(--text-muted);
    font-size: 0.875rem;
    margin: 0;
}

/* Form Controls & Inputs */
.login-card .input-group {
    background-color: var(--input-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.25s ease;
}

.login-card .input-group:focus-within {
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
    background-color: var(--card-bg-light);
}

.login-card .input-group-text {
    background: transparent;
    border: none;
    color: var(--text-muted);
    padding-left: 1.1rem;
    padding-right: 0.5rem;
}

.login-card .form-control {
    background: transparent;
    border: none;
    color: var(--text-main);
    padding: 0.75rem 0.75rem 0.75rem 0.25rem;
    font-size: 0.95rem;
}

.login-card .form-control:focus {
    box-shadow: none;
    background: transparent;
    color: var(--text-main);
}

.login-card .form-control::placeholder {
    color: var(--text-muted);
    opacity: 0.7;
}

/* Password Toggle Icon */
.pw-toggle {
    cursor: pointer;
    background: transparent !important;
    border: none !important;
    color: var(--text-muted);
    padding-right: 1.1rem;
    padding-left: 0.5rem;
    transition: color 0.15s ease;
}
.pw-toggle:hover {
    color: var(--brand-primary);
}

/* Submit Button */
.btn-login {
    background-color: var(--brand-primary);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    font-weight: 700;
    font-size: 0.95rem;
    width: 100%;
    margin-top: 0.5rem;
    transition: background-color 0.15s ease, transform 0.1s ease;
    position: relative;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
}

.btn-login:hover:not(:disabled) {
    background-color: var(--brand-primary-hover);
    color: #fff;
}

.btn-login:active:not(:disabled) { 
    transform: translateY(1px); 
}

.btn-login:disabled { 
    opacity: 0.7; 
    cursor: not-allowed; 
}

.btn-login.loading .login-text { 
    visibility: hidden; 
}

.btn-login.loading::after {
    content: "";
    position: absolute;
    left: 50%; 
    top: 50%;
    margin-left: -9px; 
    margin-top: -9px;
    width: 18px; 
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.45);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}

@keyframes spin { 
    to { transform: rotate(360deg); } 
}

/* System Title & Footer Note */
.sys-title {
    text-align: center;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin: 1.25rem 0 0;
    padding-top: 1rem;
    border-top: 1px dashed var(--border-color);
}

.footer-note {
    text-align: center;
    font-size: 0.78rem;
    color: var(--text-muted);
    margin-top: 1.5rem;
    opacity: 0.8;
}

[data-bs-theme="dark"] .footer-note {
    color: rgba(255, 255, 255, 0.6);
}

/* Alerts */
.alert {
    border-radius: 12px;
    font-size: 0.875rem;
    padding: 0.75rem 1rem;
    border: none;
    backdrop-filter: blur(8px);
}

[data-bs-theme="dark"] .alert-danger {
    background: rgba(239, 68, 68, 0.2);
    color: #fca5a5;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

[data-bs-theme="light"] .alert-danger {
    background: rgba(254, 226, 226, 0.9);
    color: #dc2626;
    border: 1px solid rgba(239, 68, 68, 0.2);
}
</style>
</head>
<body class="login-page">

<div class="login-box">
    <div class="card login-card">
        <div class="card-body">
            
            <div class="login-header">
                <img src="<?= url('assets/arw-favicon.svg') ?>" alt="Logo">
                <p class="login-box-msg"><?= htmlspecialchars($LANG['sign_in_to_continue']) ?></p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                <div class="flex-grow-1"><?= htmlspecialchars($error) ?></div>
                <button type="button" class="btn-close shadow-none" aria-label="Close" onclick="this.closest('.alert').remove();"></button>
            </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate id="loginForm" autocomplete="on">
                <?php if (!empty($_GET['next'])): ?>
                <input type="hidden" name="next" value="<?= htmlspecialchars($_GET['next'], ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>

                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input class="form-control" type="text" name="username" id="lUsername"
                           placeholder="<?= htmlspecialchars($LANG['username']) ?>"
                           autocomplete="username" required autofocus spellcheck="false">
                </div>

                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input class="form-control" type="password" name="password" id="lPassword"
                           placeholder="<?= htmlspecialchars($LANG['password']) ?>"
                           autocomplete="current-password" required>
                    <span class="input-group-text pw-toggle" id="lPwToggle" role="button" aria-label="Toggle password visibility" tabindex="0">
                        <i class="bi bi-eye" id="lPwIcon"></i>
                    </span>
                </div>

                <button type="submit" class="btn btn-login" id="loginBtn">
                    <span class="login-text">
                        <?= htmlspecialchars($LANG['login']) ?> <i class="bi bi-arrow-right ms-1"></i>
                    </span>
                </button>

                <?php $system_title = getSetting('system_title'); if ($system_title): ?>
                <p class="sys-title"><?= htmlspecialchars($system_title) ?></p>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <p class="footer-note">&copy; <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?>. All rights reserved.</p>
</div>

<script src="<?= url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>" defer></script>
<script>
(function () {
    var form = document.getElementById('loginForm');
    if (form) {
        form.addEventListener('submit', function () {
            var btn = document.getElementById('loginBtn');
            if (btn) { btn.classList.add('loading'); btn.disabled = true; }
        });
    }
    var pwInput  = document.getElementById('lPassword');
    var pwToggle = document.getElementById('lPwToggle');
    var pwIcon   = document.getElementById('lPwIcon');
    function togglePw() {
        if (!pwInput || !pwIcon) return;
        var hidden = pwInput.type === 'password';
        pwInput.type = hidden ? 'text' : 'password';
        pwIcon.classList.toggle('bi-eye', !hidden);
        pwIcon.classList.toggle('bi-eye-slash', hidden);
    }
    if (pwToggle) {
        pwToggle.addEventListener('click', togglePw);
        pwToggle.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePw(); }
        });
    }
})();
</script>

</body>
</html>