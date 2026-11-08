<?php
/**
 * File: public_html/bltx/settings/purchase_settings.php
 *
 * Disertakan (include) dari settings/settings_gate.php — TIDAK BOLEH diakses
 * langsung lewat URL.
 *
 * ISI HALAMAN (2 CARD, masing-masing punya tombol Save sendiri):
 *   CARD 1 — Document Numbering
 *            po_prefix, gr_prefix, purchase_prefix, return_prefix,
 *            po_default_note
 *   CARD 2 — Store Info for the PO Document (Shipping Address)
 *            po_company_name, po_company_address, po_company_gmaps,
 *            po_company_email
 *
 * Kolom Contact (po_company_contact) SUDAH DIHAPUS dari halaman ini karena
 * tidak dipakai lagi: contact pada dokumen PO diambil dari snapshot
 * purchase_orders.company_contact / data pembuat PO (lihat
 * includes/purchase_orders_function.php :: po_getShippingInfo()).
 * Baris setting `po_company_contact` di database SENGAJA TIDAK disentuh
 * sama sekali supaya fallback lama pada dokumen PO tetap utuh.
 *
 * PERMISSION
 *   Memakai helper yang SUDAH ADA di includes/global_permission.php —
 *   TIDAK ada permission baru, TIDAK ada permission ganda:
 *     canAccessSettingsPurchase()  → modul 'settings_purchase', can_access
 *     canUpdateSettingsPurchase()  → modul 'settings_purchase', can_update
 *   Keduanya dibaca LANGSUNG dari tabel `permissions` lewat _dbCan(), dan
 *   superadmin selalu full control tanpa menyentuh database.
 *
 * KEAMANAN (POST tradisional)
 *   1. Guard akses langsung lewat bltx_guard_fragment('SETTINGS_GATE')
 *      (mencakup bootstrap + wajib login) + wajib can_access.
 *   2. Hanya menerima REQUEST_METHOD = POST.
 *   3. Token CSRF per-sesi lewat helper terpusat bltx_csrf_* , dibandingkan
 *      dengan hash_equals(). Sejak Tahap 2 token TIDAK lagi diputar tiap
 *      simpan — lihat catatan pada blok CSRF di bawah.
 *   4. Permission diperiksa ULANG di server pada setiap penyimpanan.
 *   5. Field yang diproses memakai WHITELIST — isi $_POST tidak pernah
 *      diiterasi apa adanya.
 *   6. Semua query memakai prepared statement (tidak ada string interpolasi).
 *   7. Batas panjang + validasi tipe (e-mail, URL) di sisi server.
 *   8. Output di-escape dengan bltx_e() (htmlspecialchars ENT_QUOTES, UTF-8).
 *   9. Pola PRG (Post/Redirect/Get) — refresh tidak mengirim ulang form.
 */

/* ============================================================
   GUARD — TIDAK BOLEH DIAKSES LANGSUNG, WAJIB SUDAH LOGIN
   ============================================================ */
/* Stanza baku Tahap 0.3. bltx_guard_fragment() mencakup dua lapis yang
   dulu ditulis terpisah di sini (aplikasi ter-bootstrap + wajib login);
   lapis ketiga, izin tab, tetap diperiksa sendiri karena spesifik halaman. */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment('SETTINGS_GATE');

if (!function_exists('canAccessSettingsPurchase') || !canAccessSettingsPurchase()) {
    bltx_deny();
}

/* ============================================================
   CSRF — memakai helper terpusat bltx_csrf_* (Tahap 2).

   Tiga fungsi ps_csrf* DIHAPUS. Berkas ini dulu memegang kunci sesi
   sendiri, $_SESSION['settings_purchase_csrf'] — mekanisme KESEMBILAN di
   sistem ini dan yang KEDUA di dalam folder settings/. B1 poin 7 melarang
   mekanisme kedua untuk hal yang sudah punya mekanisme.

   SATU PERILAKU YANG HILANG, DISENGAJA: versi lama memperbarui token setiap
   kali penyimpanan berhasil (ps_csrfRegenerate). Token terpusat berlaku
   per-sesi dan tidak diputar. Alasannya: memutar token bersama akan
   membatalkan formulir yang sedang terbuka di tab Settings lain, sehingga
   penyimpanan di sana gagal tanpa sebab yang jelas bagi pengguna.
   Perlindungan CSRF-nya sendiri tidak berkurang — token tetap rahasia,
   per-sesi, dan dibandingkan dengan hash_equals.
   ============================================================ */

/* ============================================================
   ESCAPE OUTPUT — memakai bltx_e() dari Tahap 0.4.
   ps_e() DIHAPUS; isinya identik (htmlspecialchars ENT_QUOTES, UTF-8).
   ============================================================ */

/* ============================================================
   PEMBACA SETTING MASSAL — SATU QUERY UNTUK SEMUA KEY
   Menggantikan pemanggilan getSetting() satu per satu (dulu 10 query
   per render). Kolom settings.setting_key ber-UNIQUE KEY sehingga
   IN (...) memakai index dan tetap ringan walau tabel membesar.
   Mengembalikan null untuk key yang barisnya memang belum ada, supaya
   bisa dibedakan dari nilai kosong.
   ============================================================ */
if (!function_exists('ps_getSettings')) {
    function ps_getSettings(array $keys): array {
        global $conn;

        $out = array_fill_keys($keys, null);
        if (!$keys) return $out;

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types        = str_repeat('s', count($keys));

        $stmt = $conn->prepare(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)"
        );
        if (!$stmt) return $out;

        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $out[$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        $stmt->close();

        return $out;
    }
}

/**
 * Simpan satu baris pengaturan (UPSERT).
 *
 * ALASAN TIDAK MEMAKAI updateSetting() LANGSUNG:
 * updateSetting() di includes/config.php hanya menjalankan
 *     UPDATE settings SET setting_value = ? WHERE setting_key = ?
 * Perintah itu TIDAK membuat baris baru. Untuk pengaturan yang barisnya
 * belum pernah ada di tabel `settings` — po_default_note, gr_prefix,
 * purchase_prefix, return_prefix — UPDATE mengenai 0 baris sehingga
 * nilainya tidak pernah tersimpan dan getSetting() selalu mengembalikan
 * nilai default. Itulah sebabnya default note tidak muncul di form PO baru.
 *
 * Fungsi ini menutupi celah tersebut KHUSUS untuk halaman ini:
 * coba UPDATE dulu (perilaku lama, tidak berubah); kalau tidak ada baris
 * yang terpengaruh DAN key-nya memang belum ada, barulah INSERT.
 *
 * includes/config.php SENGAJA TIDAK diubah supaya perilaku penyimpanan
 * pengaturan di seluruh modul lain tetap persis seperti sekarang.
 */
if (!function_exists('ps_saveSetting')) {
    function ps_saveSetting(string $key, string $value): bool {
        global $conn;

        // 1) Coba perbarui baris yang sudah ada
        $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $value, $key);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        // affected_rows = 0 bisa berarti dua hal: baris belum ada, ATAU
        // nilainya memang tidak berubah. Karena itu keberadaan baris dicek
        // dulu sebelum memutuskan untuk INSERT.
        if ($affected > 0) return true;

        $chk = $conn->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
        if (!$chk) return false;
        $chk->bind_param('s', $key);
        $chk->execute();
        $exists = (bool) $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($exists) return true;   // baris ada, nilainya memang sama

        // 2) Baris belum ada -> buat baru
        $ins = $conn->prepare(
            "INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'text')"
        );
        if (!$ins) return false;
        $ins->bind_param('ss', $key, $value);
        $ok = $ins->execute();
        $ins->close();
        return $ok;
    }
}

/* ============================================================
   PEMBERSIH INPUT
   Menyimpan teks MENTAH (tanpa htmlspecialchars) supaya tanda baca
   tidak berubah menjadi entity di database. Yang dibuang hanya byte
   kontrol yang memang tidak boleh ada.
   ============================================================ */
if (!function_exists('ps_cleanLine')) {
    /**
     * Field satu baris: byte kontrol (termasuk CR/LF/TAB) diganti spasi.
     * Isi teks TIDAK diubah selain itu — spasi, ' " & ! * dan tanda baca
     * lain disimpan persis seperti yang diketik.
     */
    function ps_cleanLine($raw, int $maxLen): string {
        $v = is_string($raw) ? $raw : '';
        $r = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v);
        $v = ($r === null) ? preg_replace('/[\x00-\x1F\x7F]/', ' ', $v) : $r;   // fallback: byte non-UTF-8
        $v = trim((string)$v);
        return function_exists('mb_substr') ? mb_substr($v, 0, $maxLen, 'UTF-8') : substr($v, 0, $maxLen);
    }
}
if (!function_exists('ps_cleanBlock')) {
    /** Field multi-baris: enter DIPERTAHANKAN, CRLF dinormalkan ke LF. */
    function ps_cleanBlock($raw, int $maxLen): string {
        $v = is_string($raw) ? $raw : '';
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $r = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = ($r === null) ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) : $r;
        $v = trim((string)$v);
        return function_exists('mb_substr') ? mb_substr($v, 0, $maxLen, 'UTF-8') : substr($v, 0, $maxLen);
    }
}

/* ============================================================
   FLASH MESSAGE — memakai bltx_flash_* + bltx_render_flash (Tahap 0.4).

   ps_flashSet() / ps_flashPull() DIHAPUS. Kunci sesi 'ps_flash' yang dulu
   dipakai di sini adalah kunci flash KEEMPAT di sistem, di samping
   'success', 'error', dan 'swal_*'.
   ============================================================ */

/* ============================================================
   DEFINISI FIELD PER CARD — SATU-SATUNYA SUMBER KEBENARAN.
   Dipakai oleh proses simpan (whitelist) sekaligus oleh render form,
   sehingga menambah field di masa depan cukup di satu tempat.
   ============================================================ */
$PS_GROUPS = [
    // CARD 1 — Document Numbering
    'numbering' => [
        'po_prefix'        => ['mode' => 'line',  'max' => 20,   'default' => 'BSB'],
        'gr_prefix'        => ['mode' => 'line',  'max' => 20,   'default' => 'GR'],
        'purchase_prefix'  => ['mode' => 'line',  'max' => 20,   'default' => 'INV'],
        'return_prefix'    => ['mode' => 'line',  'max' => 20,   'default' => 'RET'],
        'po_default_note'  => ['mode' => 'block', 'max' => 2000, 'default' => ''],
    ],
    // CARD 2 — Store Info for the PO Document (Shipping Address)
    'store' => [
        'po_company_name'    => ['mode' => 'line', 'max' => 150, 'default' => ''],
        'po_company_address' => ['mode' => 'line', 'max' => 255, 'default' => ''],
        'po_company_gmaps'   => ['mode' => 'line', 'max' => 255, 'default' => '', 'validate' => 'url',   'label' => 'po_doc_gmaps'],
        'po_company_email'   => ['mode' => 'line', 'max' => 150, 'default' => '', 'validate' => 'email', 'label' => 'po_doc_email'],
    ],
];

/* Semua key yang dipakai halaman ini — untuk satu kali pembacaan massal. */
$PS_ALL_KEYS = array_merge(
    array_keys($PS_GROUPS['numbering']),
    array_keys($PS_GROUPS['store'])
);

/* ============================================================
   PROSES SIMPAN — POST TRADISIONAL, DUA TOMBOL TERPISAH
   ============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (isset($_POST['save_po_numbering']) || isset($_POST['save_po_store_info']))) {

    $ps_redirect = url('settings/settings_gate.php?tab=purchase');

    // 1) Permission (diperiksa ulang di server — tombol yang disembunyikan
    //    di HTML bukan pengaman).
    if (!canUpdateSettingsPurchase()) {
        bltx_flash_error((string)$LANG['no_permission']);
        redirect($ps_redirect);
    }

    // 2) CSRF
    bltx_csrf_require($ps_redirect);

    // 3) Tentukan grup mana yang disimpan (hanya satu per submit)
    $ps_group  = isset($_POST['save_po_numbering']) ? 'numbering' : 'store';
    $ps_fields = $PS_GROUPS[$ps_group];

    // 4) Ambil nilai lama SEKALI untuk seluruh key grup ini (1 query)
    $ps_current = ps_getSettings(array_keys($ps_fields));

    // 5) Bersihkan + validasi (whitelist; $_POST tidak pernah diiterasi)
    $ps_clean = [];
    foreach ($ps_fields as $key => $spec) {
        $raw = $_POST[$key] ?? '';
        $val = ($spec['mode'] === 'block')
             ? ps_cleanBlock($raw, (int)$spec['max'])
             : ps_cleanLine($raw, (int)$spec['max']);

        if ($val !== '' && isset($spec['validate'])) {
            $valid = true;
            if ($spec['validate'] === 'email') {
                $valid = (bool) filter_var($val, FILTER_VALIDATE_EMAIL);
            } elseif ($spec['validate'] === 'url') {
                $valid = (bool) filter_var($val, FILTER_VALIDATE_URL)
                         && preg_match('#^https?://#i', $val) === 1;
            }
            if (!$valid) {
                bltx_flash_error($LANG['error_occurred'] . ' — ' . $LANG[$spec['label']]);
                redirect($ps_redirect);
            }
        }
        $ps_clean[$key] = $val;
    }

    // 6) Simpan HANYA yang benar-benar berubah + kumpulkan detail log
    $ps_changes = [];
    $ps_failed  = false;

    // Pemendek nilai agar baris log tetap terbaca; enter ditulis sebagai \n
    // supaya satu perubahan tetap satu baris di halaman Activity.
    $ps_cut = static function ($v): string {
        $v   = str_replace("\n", '\\n', (string)$v);
        $len = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
        if ($len <= 200) return $v;
        return (function_exists('mb_substr') ? mb_substr($v, 0, 200, 'UTF-8') : substr($v, 0, 200)) . '…';
    };

    foreach ($ps_clean as $key => $new) {
        $old = $ps_current[$key];               // null = baris belum ada
        if ($old !== null && $old === $new) continue;

        if (!ps_saveSetting($key, $new)) { $ps_failed = true; continue; }

        $ps_changes[] = $key . ": '" . $ps_cut($old ?? '') . "' -> '" . $ps_cut($new) . "'";
    }

    // 7) Catat aktivitas selengkap-lengkapnya (hanya bila ada perubahan)
    if ($ps_changes) {
        $ps_section = ($ps_group === 'numbering')
                    ? 'Document Numbering'
                    : 'Store Info for the PO Document (Shipping Address)';
        logActivity(
            (int)$_SESSION['user_id'],
            'update_settings',
            'Purchase settings — ' . $ps_section
            . " updated by '" . ($_SESSION['username'] ?? '') . "' (ID " . (int)$_SESSION['user_id'] . ')'
            . ' | ' . implode('; ', $ps_changes)
        );
    }

    // 8) PRG — token TIDAK diputar; lihat catatan pada blok CSRF di atas.
    if ($ps_failed) {
        bltx_flash_error((string)$LANG['error_occurred']);
    } elseif ($ps_changes) {
        bltx_flash_success((string)$LANG['settings_saved']);
    } else {
        bltx_flash_success((string)$LANG['no_changes']);
    }
    redirect($ps_redirect);
}

/* ============================================================
   DATA UNTUK RENDER — SATU QUERY UNTUK SELURUH HALAMAN
   ============================================================ */
$ps_values = ps_getSettings($PS_ALL_KEYS);
$ps_val = static function (string $key) use ($ps_values, $PS_GROUPS): string {
    $spec = $PS_GROUPS['numbering'][$key] ?? $PS_GROUPS['store'][$key] ?? ['default' => ''];
    $v    = $ps_values[$key] ?? null;
    return ($v === null) ? (string)$spec['default'] : $v;
};

$ps_token    = bltx_csrf_token();
$ps_canSave  = canUpdateSettingsPurchase();
$ps_action   = url('settings/settings_gate.php?tab=purchase');
?>

<div class="row g-3">
    <div class="col-12">

        <!-- ══════════════════════════════════════════════════════════════
             CARD 1 — DOCUMENT NUMBERING
             ══════════════════════════════════════════════════════════════ -->
        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-hash"></i> <?php echo bltx_e($LANG['po_settings_numbering_section']); ?>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo bltx_e($ps_action); ?>" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo bltx_e($ps_token); ?>">

                    <div class="row">
                        <div class="col-12 col-sm-6 col-lg-3 mb-3">
                            <label class="form-label" for="ps_po_prefix"><?php echo bltx_e($LANG['po_settings_prefix_label']); ?></label>
                            <input type="text" class="form-control" id="ps_po_prefix" name="po_prefix"
                                   value="<?php echo bltx_e($ps_val('po_prefix')); ?>" maxlength="20">
                            <small class="form-text text-muted"><?php echo bltx_e($LANG['po_settings_prefix_help']); ?></small>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3 mb-3">
                            <label class="form-label" for="ps_gr_prefix"><?php echo bltx_e($LANG['po_settings_gr_prefix_label']); ?></label>
                            <input type="text" class="form-control" id="ps_gr_prefix" name="gr_prefix"
                                   value="<?php echo bltx_e($ps_val('gr_prefix')); ?>" maxlength="20">
                            <small class="form-text text-muted"><?php echo bltx_e($LANG['po_settings_gr_prefix_help']); ?></small>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3 mb-3">
                            <label class="form-label" for="ps_purchase_prefix"><?php echo bltx_e($LANG['po_settings_invoice_prefix_label']); ?></label>
                            <input type="text" class="form-control" id="ps_purchase_prefix" name="purchase_prefix"
                                   value="<?php echo bltx_e($ps_val('purchase_prefix')); ?>" maxlength="20">
                            <small class="form-text text-muted"><?php echo bltx_e($LANG['po_settings_invoice_prefix_help']); ?></small>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3 mb-3">
                            <label class="form-label" for="ps_return_prefix"><?php echo bltx_e($LANG['po_settings_return_prefix_label']); ?></label>
                            <input type="text" class="form-control" id="ps_return_prefix" name="return_prefix"
                                   value="<?php echo bltx_e($ps_val('return_prefix')); ?>" maxlength="20">
                            <small class="form-text text-muted"><?php echo bltx_e($LANG['po_settings_return_prefix_help']); ?></small>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- ── Default note Purchase Order ──────────────────── -->
                    <h6 class="text-muted mb-2"><?php echo bltx_e($LANG['po_settings_default_note_section']); ?></h6>
                    <div class="mb-3">
                        <label class="form-label" for="ps_po_default_note"><?php echo bltx_e($LANG['po_settings_default_note_label']); ?></label>
                        <textarea class="form-control" id="ps_po_default_note" name="po_default_note" rows="4"
                                  maxlength="2000"><?php echo bltx_e($ps_val('po_default_note')); ?></textarea>
                        <small class="form-text text-muted"><?php echo bltx_e($LANG['po_settings_default_note_help']); ?></small>
                    </div>

                    <div class="text-end">
                        <?php if ($ps_canSave): ?>
                        <button type="submit" name="save_po_numbering" value="1" class="btn btn-sm btn-primary">
                            <i class="bi bi-save"></i> <?php echo bltx_e($LANG['save']); ?>
                        </button>
                        <?php else: ?>
                        <span class="text-muted small"><i class="bi bi-lock"></i> <?php echo bltx_e($LANG['no_permission']); ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             CARD 2 — STORE INFO FOR THE PO DOCUMENT (SHIPPING ADDRESS)
             Kolom Contact SUDAH DIHAPUS di sini.
             ══════════════════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-geo-alt"></i> <?php echo bltx_e($LANG['po_settings_company_section']); ?>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo bltx_e($ps_action); ?>" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo bltx_e($ps_token); ?>">

                    <div class="row">
                        <div class="col-12 col-lg-6 mb-3">
                            <label class="form-label" for="ps_po_company_name"><?php echo bltx_e($LANG['po_doc_store']); ?></label>
                            <input type="text" class="form-control" id="ps_po_company_name" name="po_company_name"
                                   value="<?php echo bltx_e($ps_val('po_company_name')); ?>" maxlength="150">
                        </div>
                        <div class="col-12 col-lg-6 mb-3">
                            <label class="form-label" for="ps_po_company_address"><?php echo bltx_e($LANG['po_doc_address']); ?></label>
                            <input type="text" class="form-control" id="ps_po_company_address" name="po_company_address"
                                   value="<?php echo bltx_e($ps_val('po_company_address')); ?>" maxlength="255">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 col-lg-6 mb-3">
                            <label class="form-label" for="ps_po_company_gmaps"><?php echo bltx_e($LANG['po_doc_gmaps']); ?></label>
                            <input type="url" class="form-control" id="ps_po_company_gmaps" name="po_company_gmaps"
                                   value="<?php echo bltx_e($ps_val('po_company_gmaps')); ?>" maxlength="255"
                                   placeholder="https://maps.app.goo.gl/...">
                        </div>
                        <div class="col-12 col-lg-6 mb-3">
                            <label class="form-label" for="ps_po_company_email"><?php echo bltx_e($LANG['po_doc_email']); ?></label>
                            <input type="email" class="form-control" id="ps_po_company_email" name="po_company_email"
                                   value="<?php echo bltx_e($ps_val('po_company_email')); ?>" maxlength="150">
                        </div>
                    </div>

                    <div class="text-end">
                        <?php if ($ps_canSave): ?>
                        <button type="submit" name="save_po_store_info" value="1" class="btn btn-sm btn-primary">
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

<?php bltx_render_flash('purchase_settings'); ?>