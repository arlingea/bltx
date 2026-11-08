<?php
/* =============================================================================
   settings/general.php  —  FRAGMEN TAB dari settings/settings_gate.php

   Baris pertama di bawah adalah stanza guard baku Tahap 0.3. Dua baris,
   bukan satu, dan itu disengaja: bila berkas ini dibuka langsung lewat URL,
   config.php dan global_permission.php belum pernah termuat sehingga
   bltx_guard_fragment() BELUM ADA. Memanggilnya begitu saja menghasilkan
   fatal error 500, bukan 403 yang rapi. Baris pertama itulah yang tetap
   bekerja tanpa bergantung pada apa pun.
   ========================================================================== */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment();

$__general_self = url('settings/settings_gate.php?tab=general');

/* ===============================
   SIMPAN PENGATURAN UMUM
   POST -> proses -> redirect, sehingga refresh tidak mengulang kiriman.
=============================== */
if (isset($_POST['save_general'])) {

    // 1) CSRF lebih dulu, sebelum apa pun dibaca dari $_POST.
    bltx_csrf_require($__general_self);

    // 2) Izin per aksi.
    if (!isSuperAdmin() && !checkPermission('settings_general', 'can_update')) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__general_self);
    }

    /* 3) Validasi sisi server.
       Sebelumnya seluruh nilai $_POST diteruskan apa adanya ke
       updateSetting(). `language` dan `currency` kini dibatasi ke daftar
       putih, dan `pagination` dipaksa berada di rentang yang sama dengan
       atribut min/max pada formulir — atribut HTML saja tidak menahan
       kiriman yang dibuat di luar browser. */
    $allowed_languages  = ['en', 'id', 'ch'];
    $allowed_currencies = ['IDR', 'USD', 'CNY'];

    /* site_name, site_logo, dan timezone DIHAPUS dari halaman ini.

       timezone memang tidak pernah dibaca kode mana pun — config.php memaku
       date_default_timezone_set('Asia/Jakarta'), jadi dropdown itu tidak
       pernah berpengaruh.

       site_name dan site_logo BERBEDA: keduanya masih dibaca.
         site_name -> payroll/payroll_slip.php (kop slip gaji),
                      payroll/attendance_photo.php (judul + heading)
         site_logo -> includes/header.php (brand sidebar)
       Menghapus kolomnya berarti nilainya TERKUNCI pada isi terakhir dan
       tidak bisa lagi diubah dari layar mana pun. Barisnya sengaja TIDAK
       dihapus dari tabel settings supaya ketiga pembaca itu tetap mendapat
       nilainya, bukan jatuh ke default.

       currency dan currency_symbol DITAMBAHKAN: keduanya dibaca 25 tempat
       (17 + 8) tetapi sebelumnya tidak punya layar sama sekali — mata uang
       dasar hanya bisa diganti lewat phpMyAdmin. */
    $fields = ['system_title', 'footer_text', 'language', 'pagination', 'currency', 'currency_symbol'];
    foreach ($fields as $field) {
        if (!isset($_POST[$field])) {
            continue;
        }
        $value = $_POST[$field];

        if ($field === 'language' && !in_array($value, $allowed_languages, true)) {
            continue;
        }
        if ($field === 'currency' && !in_array($value, $allowed_currencies, true)) {
            continue;
        }
        if ($field === 'currency_symbol') {
            // Simbol bebas diketik (Rp, $, ¥, RM, ...), tapi dibatasi supaya
            // tidak ada yang menyuntikkan teks panjang ke 25 titik tampilan
            // yang membacanya.
            $value = mb_substr(trim($value), 0, 10);
            if ($value === '') { continue; }
        }
        if ($field === 'pagination') {
            $value = (int)$value;
            if ($value < 5)   { $value = 5;   }
            if ($value > 100) { $value = 100; }
        }

        updateSetting($field, $value);
    }
    
    // CATAT AKTIVITAS — sebutkan field mana yang dikirim, bukan sekadar
    // "Updated general settings", supaya log bisa dipakai menelusuri
    // perubahan (B2 poin 15).
    logActivity(
        $_SESSION['user_id'],
        'update_settings',
        'Updated general settings: ' . implode(', ', array_intersect($fields, array_keys($_POST)))
    );

    bltx_flash_success($LANG['settings_saved']);
    redirect($__general_self);
}
?>

<div class="row">
    <div class="col-12">
        
        <?php
        /* Toast melayang (A4.1) lewat helper Tahap 0.4. Menggantikan 16 baris
           HTML+JS yang sebelumnya disalin di sini, dan kini juga menampilkan
           pesan GAGAL — sebelumnya hanya sukses yang punya notifikasi, jadi
           token CSRF salah atau izin kurang berlalu tanpa tanda apa pun. */
        bltx_render_flash('general');
        ?>
        
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-sliders"></i> <?php echo bltx_e($LANG['general_settings']); ?>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php bltx_csrf_field(); ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['store']); ?></label>
                            <input type="text" class="form-control" name="system_title" value="<?php echo bltx_e(getSetting('system_title')); ?>">
                            <small class="form-text text-muted">
                                <?php echo bltx_e($LANG['login_page_title']); ?>
                            </small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <?php
                            /* Jenis kolom disamakan dengan Store: sebelumnya
                               <textarea rows="3">. Konsekuensinya baris baru
                               tidak lagi bisa diketik — isi saat ini satu baris,
                               jadi tidak ada yang hilang. */
                            ?>
                            <label class="form-label"><?php echo bltx_e($LANG['footer_text']); ?></label>
                            <input type="text" class="form-control" name="footer_text" value="<?php echo bltx_e(getSetting('footer_text')); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['currency']); ?></label>
                            <select class="form-select" name="currency">
                                <option value="IDR" <?=getSetting('currency')=='IDR'?'selected':''?>>IDR — Rupiah</option>
                                <option value="USD" <?=getSetting('currency')=='USD'?'selected':''?>>USD — US Dollar</option>
                                <option value="CNY" <?=getSetting('currency')=='CNY'?'selected':''?>>CNY — 人民币</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['currency_symbol']); ?></label>
                            <input type="text" class="form-control" name="currency_symbol" maxlength="10" value="<?php echo bltx_e(getSetting('currency_symbol')); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['language']); ?></label>
                            <select class="form-select" name="language">
                                <option value="en" <?=getSetting('language')=='en'?'selected':''?>>English</option>
                                <option value="id" <?=getSetting('language')=='id'?'selected':''?>>Indonesia</option>
                                <option value="ch" <?=getSetting('language')=='ch'?'selected':''?>>中文</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['pagination']); ?></label>
                            <input type="number" class="form-control" name="pagination" value="<?php echo bltx_e(getSetting('pagination')); ?>" min="5" max="100">
                            <small class="form-text text-muted">
                                <?php echo bltx_e($LANG['items_per_page']); ?>
                            </small>
                        </div>
                    </div>

                    <div class="text-end">
                        <?php if (isSuperAdmin() || checkPermission('settings_general', 'can_update')): ?>
                        <button type="submit" name="save_general" class="btn btn-sm btn-primary">
                            <i class="bi bi-save"></i> <?php echo bltx_e($LANG['save']); ?>
                        </button>
                        <?php else: ?>
                        <span class="text-muted small"><i class="bi bi-lock"></i> <?php echo bltx_e($LANG['no_permission']); ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
    </div>
</div>