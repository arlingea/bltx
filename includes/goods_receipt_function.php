<?php
/**
 * goods_receipt_function.php
 * File: public_html/bltx/includes/goods_receipt_function.php
 *
 * Helper functions for the GOODS RECEIPT module.
 *
 * Mengikuti konvensi file existing:
 *   - includes/purchases_function.php        → prefix pf_
 *   - includes/purchase_orders_function.php  → prefix po_
 *   - file ini                               → prefix gr_
 *
 * Return shape untuk action handler sama persis dengan file existing:
 *   ['success' => bool, 'message_key' => string, ...]
 *
 * ATURAN MODUL INI (dari requirement):
 *   - Purchase Order TIDAK PERNAH diubah isinya oleh Goods Receipt.
 *     Qty/harga PO hanya dibaca sebagai REFERENCE dan di-snapshot ke baris GR.
 *     Kolom purchase_order_items.received_qty SENGAJA TIDAK ditulis supaya
 *     baris PO existing benar-benar utuh; jumlah yang sudah diterima selalu
 *     dihitung ulang dari tabel goods_receipt_items.
 *   - Yang boleh berubah pada PO hanyalah kolom `status` (partial/received),
 *     karena itu memang diminta secara eksplisit oleh requirement.
 *   - TIDAK ADA update inventory / stock movement / stock balance sama sekali.
 *   - Identitas user di-snapshot (created_by_name, updated_by_name,
 *     cancelled_by_name) supaya histori tetap menampilkan nama asli walaupun
 *     user dihapus, dinonaktifkan, atau berganti nama.
 *
 * DO NOT access this file directly — must be included from an authenticated page.
 */

if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

if (!function_exists('gr_csrfToken')) {

/* ============================================================
   TABLE EXISTENCE
   ============================================================ */

/**
 * Deteksi apakah tabel Goods Receipt sudah dibuat.
 * Sama polanya dengan po_tablesExist() supaya halaman tidak fatal error
 * kalau SQL migrasi belum dijalankan.
 */
function gr_tablesExist(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;

    $report_mode = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW TABLES LIKE 'goods_receipts'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report_mode);
    return $cached = $ok;
}

/**
 * Deteksi apakah kolom purchases.gr_id sudah ada (bagian dari SQL migrasi).
 * Dipakai supaya "Create Purchase Invoice from GR" tidak pernah menghasilkan
 * SQL error kalau migrasi belum lengkap.
 */
function gr_purchasesHasGrColumn(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;

    $report_mode = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW COLUMNS FROM `purchases` LIKE 'gr_id'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report_mode);
    return $cached = $ok;
}

/* ============================================================
   CSRF (session-scoped, pola sama dengan pf_ / po_)
   ============================================================ */

/* RONDE PENYATUAN CSRF — fungsi di bawah kini hanya MENERUSKAN.

   Kunci sesi lama 'gr_csrf_token' tidak dipakai lagi. Token, cara pembentukan,
   dan cara pembandingannya (hash_equals) sekarang satu untuk seluruh sistem:
   bltx_csrf_token() / bltx_csrf_valid() di includes/global_permission.php.

   Nama fungsi dan tanda tangannya SENGAJA tidak diubah, sehingga tidak satu
   pun pemanggil di seluruh modul ini perlu disentuh — pola yang sama dengan
   penyatuan _dbCan() di Tahap 0.1.

   Tidak ada jaring pengaman function_exists di sini, dan itu disengaja.
   Kalau global_permission.php suatu saat tidak termuat, fatal error yang
   nyaring jauh lebih baik daripada diam-diam kembali ke token lama —
   formulir akan dibentuk dengan satu token lalu diperiksa dengan token lain,
   dan gagalnya tanpa penjelasan. Seluruh jalur pemanggil sudah diverifikasi
   memuat global_permission.php pada ronde prasyarat. */
function gr_csrfToken(): string {
    return bltx_csrf_token();
}

function gr_csrfVerify(string $token): bool {
    return bltx_csrf_valid($token);
}

function gr_csrfRegenerate(): string {
    /* Rotasi token DIHILANGKAN (lihat purchase_settings.php Tahap 2).
       Token kini dipakai bersama seluruh sistem; memutarnya di satu modul
       membatalkan formulir yang sedang terbuka di modul lain. Fungsi ini
       dipertahankan agar pemanggilnya tidak perlu diubah, dan mengembalikan
       token yang berlaku sekarang. */
    return bltx_csrf_token();
}

/* ============================================================
   USER IDENTITY SNAPSHOT
   ============================================================ */

/**
 * Nama user yang sedang login, untuk di-SNAPSHOT ke transaksi.
 * Sumber utama session (tanpa query), fallback ke tabel users bila session
 * tidak menyimpan full_name. Nilai ini disimpan permanen di baris transaksi
 * sehingga histori tidak pernah kehilangan nama walaupun user dihapus.
 */
function gr_currentUserName(mysqli $conn): string {
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    if ($name !== '') return $name;

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && trim((string)$row['full_name']) !== '') {
                return trim((string)$row['full_name']);
            }
        }
    }
    return trim((string)($_SESSION['username'] ?? ''));
}


/**
 * Deteksi apakah kolom Payment Information pada goods_receipts sudah ada
 * (SQL sql/goods_receipt_payment_info.sql sudah dijalankan). Dipakai agar
 * halaman tetap berfungsi walaupun migrasi belum dijalankan.
 */
function gr_hasPaymentColumns(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $report = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW COLUMNS FROM `goods_receipts` LIKE 'payment_by'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report);
    return $cached = $ok;
}

/**
 * Deteksi apakah kolom Opsi Pembayaran pada goods_receipts sudah ada
 * (SQL sql/goods_receipt_payment_status.sql sudah dijalankan) DAN sudah
 * dimigrasikan ke sql/goods_receipt_payment_status_cancel.sql, yaitu
 * enum-nya ikut memuat nilai 'cancel'.
 * Pola sama persis dengan gr_hasPaymentColumns() supaya halaman tetap
 * berfungsi penuh dengan alur lama walaupun migrasi belum dijalankan.
 */
function gr_hasPaymentStatusColumn(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $report = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        // SHOW COLUMNS mengembalikan tipe data enum secara persis, mis.
        // "enum('paid','unpaid','cancel')". Nilai 'cancel' wajib ikut
        // dideteksi: tanpa itu filter Cancel di halaman daftar tidak
        // pernah bisa mencocokkan baris mana pun.
        $res  = @$conn->query("SHOW COLUMNS FROM `goods_receipts` LIKE 'payment_status'");
        $col  = $res ? $res->fetch_assoc() : null;
        $type = (string)($col['Type'] ?? '');
        $ok   = ($type !== '') && strpos($type, 'cancel') !== false;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report);
    return $cached = $ok;
}

/**
 * Sama seperti gr_hasPaymentStatusColumn(), tetapi TIDAK mensyaratkan nilai
 * 'cancel' ada di dalam enum — dipakai oleh jalur penulisan (create / record
 * payment) yang hanya pernah menulis 'paid' / 'unpaid', sehingga perilaku
 * lamanya tidak berubah bila migrasi cancel belum dijalankan.
 */
function gr_hasPaymentStatusBaseColumn(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $report = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW COLUMNS FROM `goods_receipts` LIKE 'payment_status'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report);
    return $cached = $ok;
}

/**
 * Deteksi apakah kolom cancel_reason sudah ada. Dipisah dari deteksi di atas
 * supaya pembatalan tetap jalan walaupun migrasi dijalankan sebagian.
 */
function gr_hasCancelReasonColumn(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $report = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW COLUMNS FROM `goods_receipts` LIKE 'cancel_reason'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report);
    return $cached = $ok;
}

/**
 * Status pembayaran satu baris GR, dinormalkan.
 * Baris yang dibuat sebelum migrasi (kolomnya belum ada / masih NULL)
 * dianggap 'paid', karena alur lama MEWAJIBKAN pembayaran diisi saat
 * penerimaan. Dengan begitu dokumen lama tidak pernah ikut terkunci.
 *
 * Dokumen berstatus 'cancelled' selalu dinormalkan menjadi 'cancel' —
 * uang tidak pernah keluar untuk GR yang dibatalkan, dan inilah nilai
 * yang disimpan migrasi sql/goods_receipt_payment_status_cancel.sql
 * sehingga filter server-side (gr_buildListWhere()) dan tampilan baris
 * memakai aturan yang sama persis.
 */
function gr_paymentStatus($gr): string {
    if (!is_array($gr)) return 'paid';
    if (trim((string)($gr['status'] ?? '')) === 'cancelled') return 'cancel';
    $val = trim((string)($gr['payment_status'] ?? ''));
    if ($val === 'cancel') return 'cancel';
    return $val === 'unpaid' ? 'unpaid' : 'paid';
}

/** Kelas warna badge untuk status pembayaran GR. */
function gr_paymentStatusColor(string $paymentStatus): string {
    if ($paymentStatus === 'cancel') return 'secondary';
    return $paymentStatus === 'unpaid' ? 'warning' : 'success';
}

/** Label status pembayaran dari language file aktif (tanpa fallback string). */
function gr_paymentStatusLabel(string $paymentStatus): string {
    global $LANG;
    if ($paymentStatus === 'cancel') return $LANG['gr_payment_cancel'];
    return $paymentStatus === 'unpaid'
        ? $LANG['gr_payment_unpaid']
        : $LANG['gr_payment_paid'];
}

/**
 * Baca opsi pembayaran yang dipilih di form penerimaan barang.
 * Hanya dua nilai yang diterima; apa pun di luar itu jatuh ke 'now'
 * sehingga perilaku default identik dengan alur lama (bayar langsung).
 */
function gr_normalizePaymentOption(array $post): string {
    $opt = (string)($post['payment_option'] ?? 'now');
    return $opt === 'deferred' ? 'deferred' : 'now';
}

/**
 * Terjemahkan ID user menjadi NAMA lengkapnya untuk disimpan permanen.
 * Nama inilah yang ditulis ke payment_by / payer_name, sehingga tetap ada
 * walaupun akun user dihapus di kemudian hari.
 */
function gr_userNameById(mysqli $conn, $userId): string {
    $userId = (int)$userId;
    if ($userId <= 0) return '';
    static $memo = [];
    if (array_key_exists($userId, $memo)) return $memo[$userId];
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return $memo[$userId] = '';
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $memo[$userId] = ($row && trim((string)$row['full_name']) !== '') ? trim((string)$row['full_name']) : '';
}

/**
 * Ambil & validasi Payment Information dari $_POST.
 * payment_by / payer_name dikonversi dari ID user menjadi NAMA sebelum
 * disimpan, sehingga tetap ada walaupun akun user dihapus.
 *
 * $required = true  → keempat field WAJIB (opsi Bayar Langsung).
 * $required = false → keempat field boleh kosong dan baris disimpan tanpa
 *                     data pembayaran (opsi Pembayaran Tertunda). Kalau
 *                     ternyata terisi sebagian, tetap divalidasi penuh agar
 *                     tidak ada data pembayaran yang setengah jadi.
 *
 * @return array ['data' => array, 'error' => string|null]
 */
function gr_collectPaymentInfo(mysqli $conn, array $post, bool $required = true): array {
    $method = trim((string)($post['payment_method'] ?? ''));
    $byId   = (int)($post['payment_by'] ?? 0);
    $payId  = (int)($post['payer_name'] ?? 0);
    $shift  = (int)($post['payer_shift'] ?? 0);

    $allEmpty = ($method === '' && $byId <= 0 && $payId <= 0 && $shift <= 0);
    if (!$required && $allEmpty) {
        // Pembayaran tertunda: baris disimpan tanpa data pembayaran sama
        // sekali. Nilainya diisi nanti lewat gr_recordPayment().
        return ['data' => [
            'payment_method' => null,
            'payment_by'     => null,
            'payer_name'     => null,
            'payer_shift'    => null,
        ], 'error' => null];
    }

    if ($method === '' || $byId <= 0 || $payId <= 0 || $shift <= 0) {
        return ['data' => [], 'error' => 'gr_payment_info_required'];
    }
    $byName  = gr_userNameById($conn, $byId);
    $payName = gr_userNameById($conn, $payId);
    if ($byName === '' || $payName === '') {
        return ['data' => [], 'error' => 'gr_payment_info_required'];
    }
    return ['data' => [
        'payment_method' => $method,
        'payment_by'     => $byName,
        'payer_name'     => $payName,
        'payer_shift'    => $shift,
    ], 'error' => null];
}


/**
 * Sumber gambar dokumen: "camera" (Take Photo) atau "browse" (pilih berkas).
 * Nilainya dikirim halaman lewat input tersembunyi document_source dan hanya
 * dipakai untuk pencatatan activity log.
 */
function gr_documentSource(array $post): string {
    $src = (string)($post['document_source'] ?? 'browse');
    return in_array($src, ['camera', 'browse'], true) ? $src : 'browse';
}

/**
 * Rangkai satu baris deskripsi activity log yang lengkap dari pasangan
 * label => nilai. Nilai kosong / null otomatis dilewati agar log tetap ringkas.
 */
function gr_logLine(array $parts): string {
    $out = [];
    foreach ($parts as $label => $value) {
        if ($value === null) continue;
        $value = trim((string)$value);
        if ($value === '') continue;
        $out[] = $label . '=' . $value;
    }
    return implode('; ', $out);
}

/* ============================================================
   NUMBER GENERATOR
   ============================================================ */

/**
 * Nomor Goods Receipt.
 * Format : #PREFIX + YYYYMMDD + NNN   contoh  #GR20260822001
 *   - PREFIX dari setting "gr_prefix" (Settings > General), default "GR"
 *   - sequence berjalan per BULAN kalender, reset ke 001 saat ganti bulan
 * Persis pola po_generateNumber() supaya konsisten dengan sistem existing.
 */
function gr_generateNumber(mysqli $conn): string {
    $prefix    = (string) getSetting('gr_prefix', 'GR');
    $dateFull  = date('Ymd');
    $dateMonth = date('Ym');
    $next      = 1;

    if (gr_tablesExist($conn)) {
        $like = '#' . $prefix . $dateMonth . '%';
        $stmt = $conn->prepare(
            "SELECT gr_number FROM goods_receipts
              WHERE gr_number LIKE ?
              ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $last = '';
            $stmt->bind_result($last);
            if ($stmt->fetch() && $last !== '') {
                $seq  = (int) substr($last, -3);
                $next = $seq + 1;
            }
            $stmt->close();
        }
    }

    return '#' . $prefix . $dateFull . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/* ============================================================
   FORMAT HELPERS
   ============================================================ */

/** 5.00 → "5", 1.50 → "1.5" — aturan sama dengan po_formatQty(). */
function gr_formatQty($qty): string {
    $str = number_format((float)$qty, 2, '.', '');
    $str = rtrim($str, '0');
    $str = rtrim($str, '.');
    return $str === '' ? '0' : $str;
}

/** Kelas warna badge untuk status GR. */
function gr_statusColor(string $status): string {
    switch ($status) {
        case 'received':  return 'success';
        case 'cancelled': return 'danger';
        default:          return 'secondary'; // draft
    }
}

/** Label status GR dari language file aktif (tanpa fallback string). */
function gr_statusLabel(string $status): string {
    global $LANG;
    $map = [
        'draft'     => $LANG['gr_status_draft'],
        'received'  => $LANG['gr_status_received'],
        'cancelled' => $LANG['gr_status_cancelled'],
    ];
    return $map[$status] ?? $status;
}

/* ============================================================
   LIST + COUNT (server-side filter & pagination)
   ============================================================ */

/**
 * Bangun potongan WHERE + parameter untuk daftar GR.
 * Dipakai bersama oleh gr_getList() dan gr_countList() supaya filter
 * keduanya tidak pernah berbeda.
 *
 * @return array [string $where, string $types, array $params]
 */
function gr_buildListWhere(array $f): array {
    $where  = ' WHERE 1=1';
    $types  = '';
    $params = [];

    // ── REPORT DATA SCOPE (Own Data Only) ─────────────────────────────────
    // Bila permission cakupan data modul 'goods_receipt' diset "Own Data
    // Only", daftar & hitungan hanya mencakup GR yang dibuat sendiri.
    // Ditaruh di sini supaya gr_getList() dan gr_countList() TIDAK PERNAH
    // memakai aturan yang berbeda (paginasi tetap akurat).
    if (function_exists('canViewAllGoodsReceipt')) {
        $scopeOwner = permScopeOwnerId(canViewAllGoodsReceipt());
        if ($scopeOwner !== null) {
            $where   .= ' AND gr.created_by = ?';
            $params[] = $scopeOwner;
            $types   .= 'i';
        }
    }

    $search = trim((string)($f['search'] ?? ''));
    if ($search !== '') {
        $where .= " AND (gr.gr_number LIKE ? OR gr.po_number LIKE ? OR gr.supplier_name LIKE ?
                         OR gr.delivery_note_number LIKE ? OR gr.created_by_name LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 5; $i++) { $params[] = $like; $types .= 's'; }
    }
    if (!empty($f['status'])) {
        $where   .= ' AND gr.status = ?';
        $params[] = (string)$f['status'];
        $types   .= 's';
    }
    // Filter Payment Status. Dilewati sepenuhnya bila migrasi payment_status
    // belum dijalankan, supaya query tidak pernah menyebut kolom yang belum
    // ada. Kombinasi status='received' + payment_status='unpaid' menghasilkan
    // daftar hutang usaha: barang sudah diterima, uang belum keluar.
    //
    // 'unpaid' dan 'paid' HARUS eksklusif satu sama lain. Sebelumnya kolom
    // payment_status pada baris berstatus cancelled ikut terbawa ('unpaid'
    // bila GR dibatalkan sebelum pembayarannya dicatat), sehingga memilih
    // Unpaid menampilkan campuran unpaid + dokumen cancel. Sejak nilai
    // 'cancel' ditulis ke kolomnya (lihat gr_cancel() dan sql/
    // goods_receipt_payment_status_cancel.sql), pembanding langsung di bawah
    // ini sudah cukup; klausa tambahan di belakang tetap dipertahankan agar
    // data lama yang belum termigrasi tidak kembali bocor ke filter Unpaid.
    if (!empty($f['payment_status']) && !empty($f['__has_payment_status'])) {
        $ps = (string)$f['payment_status'];
        if ($ps === 'cancel') {
            $where   .= " AND (gr.payment_status = 'cancel' OR gr.status = 'cancelled')";
        } elseif ($ps === 'unpaid') {
            $where   .= " AND gr.payment_status = 'unpaid' AND gr.status <> 'cancelled'";
        } else {
            $where   .= " AND gr.payment_status = ? AND gr.status <> 'cancelled'";
            $params[] = $ps;
            $types   .= 's';
        }
    }
    if (!empty($f['supplier_id'])) {
        $where   .= ' AND gr.supplier_id = ?';
        $params[] = (int)$f['supplier_id'];
        $types   .= 'i';
    }
    if (!empty($f['po_id'])) {
        $where   .= ' AND gr.po_id = ?';
        $params[] = (int)$f['po_id'];
        $types   .= 'i';
    }
    if (!empty($f['from_date'])) {
        $where   .= ' AND gr.receipt_date >= ?';
        $params[] = (string)$f['from_date'];
        $types   .= 's';
    }
    if (!empty($f['to_date'])) {
        $where   .= ' AND gr.receipt_date <= ?';
        $params[] = (string)$f['to_date'];
        $types   .= 's';
    }
    return [$where, $types, $params];
}

/**
 * Daftar Goods Receipt — kolom selektif, index-friendly, dengan LIMIT/OFFSET.
 * Tidak pernah SELECT * dan tidak pernah merender seluruh tabel sekaligus.
 */
function gr_getList(mysqli $conn, array $filters = [], int $limit = 25, int $offset = 0): array {
    if (!gr_tablesExist($conn)) return [];

    [$where, $types, $params] = gr_buildListWhere($filters);

    // Batas atas dinaikkan menjadi 5000 karena daftar Goods Receipt kini
    // dirender dengan DataTables client-side (paginasi & pencarian dilakukan
    // di browser, sama seperti daftar Return). Batas ini tetap ada sebagai
    // pengaman agar query tidak pernah unbounded.
    $limit  = max(1, min(5000, $limit));
    $offset = max(0, $offset);

    // Kolom payment_status hanya disebut bila migrasinya sudah dijalankan.
    // Bila belum, dikirim sebagai konstanta 'paid' supaya seluruh pemakai
    // hasil query ini menerima bentuk baris yang sama tanpa perlu mengecek.
    $payCol = gr_hasPaymentStatusColumn($conn)
        ? 'gr.payment_status'
        : "'paid' AS payment_status";

    $sql = "SELECT gr.id, gr.gr_number, gr.po_id, gr.po_number, gr.supplier_id,
                   gr.supplier_name, gr.receipt_date, gr.delivery_note_number,
                   gr.status, gr.total_amount, gr.created_by_name, gr.created_at,
                   $payCol
              FROM goods_receipts gr"
         . $where
         . " ORDER BY gr.receipt_date DESC, gr.id DESC
             LIMIT ? OFFSET ?";

    $params[] = $limit;  $types .= 'i';
    $params[] = $offset; $types .= 'i';

    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

/** Jumlah total baris untuk paginasi (filter identik dengan gr_getList()). */
function gr_countList(mysqli $conn, array $filters = []): int {
    if (!gr_tablesExist($conn)) return 0;

    [$where, $types, $params] = gr_buildListWhere($filters);
    $sql = "SELECT COUNT(*) AS c FROM goods_receipts gr" . $where;

    if ($params) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $res = $conn->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
    }
    return (int)($row['c'] ?? 0);
}

/* ============================================================
   SINGLE RECORD
   ============================================================ */

/** Satu Goods Receipt lengkap dengan barisnya. */
function gr_getOne(mysqli $conn, int $id): ?array {
    if (!gr_tablesExist($conn)) return null;

    $stmt = $conn->prepare(
        "SELECT gr.*, s.name AS supplier_current_name, po.po_number AS po_current_number,
                po.status AS po_status
           FROM goods_receipts gr
           LEFT JOIN suppliers       s  ON s.id  = gr.supplier_id
           LEFT JOIN purchase_orders po ON po.id = gr.po_id
          WHERE gr.id = ?"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $gr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$gr) return null;

    $gr['items'] = gr_getItems($conn, $id);
    return $gr;
}

/** Baris-baris satu Goods Receipt. */
function gr_getItems(mysqli $conn, int $grId): array {
    $stmt = $conn->prepare(
        "SELECT gi.*, u.name AS unit_name, u.symbol AS unit_symbol, c.name AS category_name
           FROM goods_receipt_items gi
           LEFT JOIN units      u ON u.id = gi.unit_id
           LEFT JOIN categories c ON c.id = gi.category_id
          WHERE gi.gr_id = ?
          ORDER BY gi.id ASC"
    );
    $rows = [];
    if ($stmt) {
        $stmt->bind_param('i', $grId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    }
    return $rows;
}

/* ============================================================
   PO ↔ GR AGGREGATION
   ============================================================ */

/**
 * Total accepted_quantity per baris PO, dihitung HANYA dari Goods Receipt
 * berstatus 'received'. Dokumen draft belum dianggap penerimaan nyata dan
 * dokumen cancelled tidak pernah dihitung. Ini sumber kebenaran tunggal
 * untuk "sudah diterima berapa" — bukan purchase_order_items.received_qty,
 * yang sengaja tidak pernah ditulis agar baris PO existing tetap utuh.
 *
 * @param int $excludeGrId  GR yang sedang diedit (dikecualikan agar tidak
 *                          dihitung dua kali saat validasi form edit)
 * @return array  [po_item_id => float accepted]
 */
/**
 * Deteksi apakah kolom penanda tutup baris sudah ada
 * (sql/goods_receipt_close_line.sql sudah dijalankan).
 * Pola sama dengan gr_hasPaymentColumns() / gr_hasPaymentStatusColumn():
 * selama migrasi belum jalan, seluruh halaman berperilaku persis seperti
 * sebelumnya tanpa error apa pun.
 */
/**
 * Deteksi kolom purchase_staff_name pada goods_receipts
 * (sql/goods_receipt_purchase_staff.sql sudah dijalankan).
 * Pola sama dengan gr_hasPaymentColumns() / gr_hasCloseLineColumn().
 */
function gr_hasPurchaseStaffColumn(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $report = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW COLUMNS FROM `goods_receipts` LIKE 'purchase_staff_name'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report);
    return $cached = $ok;
}

/**
 * Ambil BAGIAN NAMA dari kontak Shipping Address sebuah PO.
 *
 * purchase_orders.company_contact dibentuk oleh po_composeContact() dengan
 * format "Nama - Telepon", dan menjadi "Nama" saja bila teleponnya kosong.
 * Fungsi ini mengembalikan bagian namanya saja, tanpa nomor telepon.
 *
 * Pemisahan dilakukan pada " - " TERAKHIR, dan ekor di belakangnya hanya
 * dianggap nomor telepon bila benar-benar berpola telepon (angka, +, -,
 * spasi, titik, kurung). Dengan begitu:
 *   "Arlin - 08132020695"   -> "Arlin"
 *   "Arlin - 0823-6334-9011" -> "Arlin"
 *   "Arlin"                  -> "Arlin"      (tanpa telepon)
 *   "Jean - Luc Picard"      -> "Jean - Luc Picard"  (ekor bukan telepon,
 *                                jadi seluruhnya diperlakukan sebagai nama)
 * Tanpa penjagaan itu, nama yang kebetulan mengandung " - " akan terpotong.
 */
function gr_contactPersonName(?string $contact): string {
    $c = trim((string)$contact);
    if ($c === '') return '';

    $pos = mb_strrpos($c, ' - ', 0, 'UTF-8');
    if ($pos === false) return $c;

    $name = trim(mb_substr($c, 0, $pos, 'UTF-8'));
    $tail = trim(mb_substr($c, $pos + 3, null, 'UTF-8'));

    if ($name !== '' && $tail !== '' && preg_match('/^[0-9+().\-\s]+$/', $tail)) {
        return $name;
    }
    return $c;
}

function gr_hasCloseLineColumn(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $report = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW COLUMNS FROM `goods_receipt_items` LIKE 'close_line'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report);
    return $cached = $ok;
}

/**
 * Kumpulan po_item_id yang sisanya sudah dinyatakan TIDAK AKAN DATANG LAGI.
 *
 * Menyaring gr.status = 'received' sama seperti gr_acceptedByPoItem(),
 * sehingga penanda pada Goods Receipt yang DIBATALKAN otomatis tidak
 * dihitung — PO kembali ke 'partial' dengan sendirinya tanpa perlu kode
 * pembersih terpisah.
 *
 * @return array<int,bool> po_item_id => true
 */
function gr_closedPoItems(mysqli $conn, int $poId, int $excludeGrId = 0): array {
    $out = [];
    if (!gr_tablesExist($conn) || $poId <= 0 || !gr_hasCloseLineColumn($conn)) return $out;

    $sql = "SELECT DISTINCT gi.po_item_id
              FROM goods_receipt_items gi
              JOIN goods_receipts gr ON gr.id = gi.gr_id
             WHERE gr.po_id = ?
               AND gr.status = 'received'
               AND gi.po_item_id IS NOT NULL
               AND gi.close_line = 1";
    $types  = 'i';
    $params = [$poId];
    if ($excludeGrId > 0) {
        $sql   .= " AND gr.id <> ?";
        $types .= 'i';
        $params[] = $excludeGrId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return $out;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $out[(int)$r['po_item_id']] = true;
    $stmt->close();
    return $out;
}

function gr_acceptedByPoItem(mysqli $conn, int $poId, int $excludeGrId = 0): array {
    $out = [];
    if (!gr_tablesExist($conn) || $poId <= 0) return $out;

    $sql = "SELECT gi.po_item_id, SUM(gi.accepted_quantity) AS qty
              FROM goods_receipt_items gi
              JOIN goods_receipts gr ON gr.id = gi.gr_id
             WHERE gr.po_id = ?
               AND gr.status = 'received'
               AND gi.po_item_id IS NOT NULL";
    $types  = 'i';
    $params = [$poId];
    if ($excludeGrId > 0) {
        $sql .= " AND gr.id <> ?";
        $types   .= 'i';
        $params[] = $excludeGrId;
    }
    $sql .= " GROUP BY gi.po_item_id";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return $out;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $out[(int)$r['po_item_id']] = (float)$r['qty'];
    }
    $stmt->close();
    return $out;
}

/**
 * Data PO untuk dijadikan REFERENCE saat membuat / mengedit Goods Receipt.
 * PO tidak diubah sama sekali — hanya dibaca.
 *
 * Setiap baris PO diperkaya dengan:
 *   already_received  = total accepted dari GR lain
 *   remaining         = quantity PO - already_received  (minimal 0)
 */
function gr_getPoReference(mysqli $conn, int $poId, int $excludeGrId = 0): ?array {
    if (!function_exists('po_getOne')) return null;
    $po = po_getOne($conn, $poId);
    if (!$po) return null;

    $accepted = gr_acceptedByPoItem($conn, $poId, $excludeGrId);
    // Baris yang sisanya sudah ditutup oleh GR lain. Sisa qty-nya dipaksa 0
    // dan ditandai, sehingga form penerimaan berikutnya bisa mengunci baris
    // itu alih-alih menawarkannya sebagai barang yang masih bisa diterima.
    $closed   = gr_closedPoItems($conn, $poId, $excludeGrId);
    foreach ($po['items'] as $idx => $it) {
        $already   = (float)($accepted[(int)$it['id']] ?? 0);
        $isClosed  = !empty($closed[(int)$it['id']]);
        $po['items'][$idx]['already_received'] = $already;
        $po['items'][$idx]['line_closed']      = $isClosed ? 1 : 0;
        $rem = (float)$it['quantity'] - $already;
        $po['items'][$idx]['remaining'] = ($isClosed || $rem <= 0) ? 0.0 : $rem;
    }
    return $po;
}

/**
 * Hitung ulang status Purchase Order berdasarkan seluruh Goods Receipt valid.
 *
 * Aturan:
 *   - PO 'cancelled' TIDAK PERNAH disentuh.
 *   - total accepted memenuhi semua baris PO  → 'received'
 *   - ada accepted tapi belum penuh           → 'partial'
 *   - tidak ada accepted sama sekali          → status TIDAK diturunkan,
 *     hanya dikembalikan dari 'partial'/'received' ke 'approved' bila memang
 *     seluruh GR-nya sudah dibatalkan/dihapus.
 *
 * Hanya kolom `status` pada purchase_orders yang berubah. Item PO, harga PO,
 * qty PO, tanggal PO tetap utuh.
 */
function gr_recalcPoStatus(mysqli $conn, int $poId): void {
    if ($poId <= 0 || !gr_tablesExist($conn)) return;

    $stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;

    $current = (string)$row['status'];
    if ($current === 'cancelled') return; // jangan pernah menyentuh PO yang dibatalkan

    // Ambil qty PO per baris
    $ordered = [];
    $s2 = $conn->prepare("SELECT id, quantity FROM purchase_order_items WHERE po_id = ?");
    if (!$s2) return;
    $s2->bind_param('i', $poId);
    $s2->execute();
    $r2 = $s2->get_result();
    while ($x = $r2->fetch_assoc()) $ordered[(int)$x['id']] = (float)$x['quantity'];
    $s2->close();

    if (!$ordered) return;

    $accepted   = gr_acceptedByPoItem($conn, $poId, 0);
    // Baris yang sisanya sudah dinyatakan tidak akan datang lagi.
    $closed     = gr_closedPoItems($conn, $poId, 0);
    $totalAcc   = 0.0;
    $allFilled  = true;
    $anyClosed  = false;
    foreach ($ordered as $poItemId => $qty) {
        $acc       = (float)($accepted[$poItemId] ?? 0);
        $totalAcc += $acc;
        // Sebuah baris dianggap TUNTAS bila qty-nya sudah terpenuhi, ATAU
        // bila sisanya sudah dinyatakan tidak tersedia. Tanpa cabang kedua
        // ini, PO yang barangnya memang tidak akan pernah lengkap akan
        // terjebak di 'partial' selamanya.
        if (!empty($closed[$poItemId])) { $anyClosed = true; continue; }
        if ($acc + 0.00001 < $qty) $allFilled = false;
    }

    // $anyClosed dipakai pada cabang terakhir: PO yang SELURUH sisanya
    // ditutup padahal belum ada barang diterima sama sekali (misal seluruh
    // item dibatalkan supplier) tetap dinyatakan 'received', bukan
    // dikembalikan ke 'approved' seolah belum ada penerimaan apa pun.
    if ($allFilled)          $new = 'received';
    elseif ($totalAcc > 0)   $new = 'partial';
    elseif ($anyClosed)      $new = 'received';
    else                     $new = in_array($current, ['partial', 'received'], true) ? 'approved' : $current;

    if ($new === $current) return;

    $s3 = $conn->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
    if ($s3) {
        $s3->bind_param('si', $new, $poId);
        $s3->execute();
        $s3->close();
        if (function_exists('logActivity')) {
            logActivity('po_status', "PO #{$poId} status recalculated by goods receipt: {$current} to {$new}");
        }
    }
}

/* ============================================================
   NORMALISASI ITEM DARI $_POST
   ============================================================ */

/**
 * Ubah array $_POST menjadi baris GR yang sudah divalidasi.
 * Semua nilai numerik dipaksa ke tipe yang benar; baris tanpa nama item
 * atau tanpa qty yang berarti akan dilewati.
 *
 * @return array ['items' => array, 'error' => string|null]
 */
function gr_normalizePostedItems(array $post): array {
    $names     = $post['item_name']         ?? [];
    $poItemIds = $post['po_item_id']        ?? [];
    $invIds    = $post['inventory_id']      ?? [];
    $ordered   = $post['ordered_quantity']  ?? [];
    $received  = $post['received_quantity'] ?? [];
    $rejected  = $post['rejected_quantity'] ?? [];
    $units     = $post['unit_id']           ?? [];
    $cats      = $post['category_id']       ?? [];
    $poPrices  = $post['po_unit_price']     ?? [];
    $prices    = $post['unit_price']        ?? [];
    $discs     = $post['discount']          ?? [];
    $rounds    = $post['price_rounding']    ?? [];
    $itemNotes = $post['item_notes']        ?? [];
    // Penanda "sisa barang tidak tersedia". Checkbox yang tidak dicentang
    // tidak ikut terkirim oleh browser, jadi nilainya dibaca per-indeks
    // baris lewat hidden input pendamping (lihat form: close_line[$i]).
    $closeLine = $post['close_line']         ?? [];

    if (!is_array($names)) {
        return ['items' => [], 'error' => 'gr_items_required'];
    }

    $items = [];
    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        if ($name === '') continue;

        $recv = (float)($received[$i] ?? 0);
        $rej  = (float)($rejected[$i] ?? 0);
        $ord  = (float)($ordered[$i]  ?? 0);

        if ($recv < 0 || $rej < 0 || $ord < 0) {
            return ['items' => [], 'error' => 'gr_negative_qty'];
        }
        if ($rej > $recv) {
            return ['items' => [], 'error' => 'gr_rejected_gt_received'];
        }

        $accepted = $recv - $rej;
        // Nilai berformat ribuan dari input currency -> float
        $price    = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($prices[$i] ?? 0) : (float)($prices[$i] ?? 0);
        $disc     = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($discs[$i] ?? 0) : (float)($discs[$i] ?? 0);
        $round    = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($rounds[$i] ?? 0) : (float)($rounds[$i] ?? 0);
        if ($price < 0 || $disc < 0) {
            return ['items' => [], 'error' => 'gr_negative_amount'];
        }

        $poItemId = (int)($poItemIds[$i] ?? 0) ?: null;
        $noteVal  = trim((string)($itemNotes[$i] ?? ''));
        $isClosed = !empty($closeLine[$i]) && (string)$closeLine[$i] !== '0';

        // Penutupan baris hanya bermakna untuk baris yang BERASAL DARI PO.
        // Item manual di luar PO tidak punya sisa qty yang bisa ditutup.
        if ($isClosed && $poItemId === null) {
            return ['items' => [], 'error' => 'gr_close_line_needs_po'];
        }
        // Menutup baris berarti menyatakan sisa barang senilai sekian TIDAK
        // AKAN PERNAH DATANG, dan sejak itu PO berhenti tampil sebagai
        // pekerjaan yang belum selesai. Alasannya wajib tertulis supaya
        // keputusan itu tetap bisa dipertanggungjawabkan di kemudian hari.
        if ($isClosed && $noteVal === '') {
            return ['items' => [], 'error' => 'gr_close_line_reason_required'];
        }
        // Baris yang qty-nya justru sudah terpenuhi tidak perlu ditutup —
        // mencegah penanda dipasang tanpa sebab sehingga datanya tetap bersih.
        if ($isClosed && $ord > 0 && $accepted + 0.00001 >= $ord) {
            return ['items' => [], 'error' => 'gr_close_line_not_needed'];
        }

        $items[] = [
            'po_item_id'        => $poItemId,
            'inventory_id'      => (int)($invIds[$i] ?? 0) ?: null,
            'item_name'         => $name,
            'ordered_quantity'  => $ord,
            'received_quantity' => $recv,
            'accepted_quantity' => $accepted,
            'rejected_quantity' => $rej,
            'close_line'        => $isClosed ? 1 : 0,
            'unit_id'           => (int)($units[$i] ?? 0) ?: null,
            'category_id'       => (int)($cats[$i] ?? 0) ?: null,
            'po_unit_price'     => (float)($poPrices[$i] ?? 0),
            'unit_price'        => $price,
            'discount'          => $disc,
            'price_rounding'    => $round,
            'total_price'       => ($accepted * $price) - $disc + $round,
            'notes'             => $noteVal !== '' ? $noteVal : null,
        ];
    }

    if (!$items) {
        return ['items' => [], 'error' => 'gr_items_required'];
    }
    return ['items' => $items, 'error' => null];
}

/**
 * Validasi qty terhadap sisa PO — item PO tidak boleh diterima melebihi
 * jumlah yang dipesan dikurangi yang sudah diterima di GR lain.
 * Item TAMBAHAN (po_item_id NULL) tidak divalidasi karena memang tidak
 * terdapat pada PO.
 *
 * @return string|null  message_key bila gagal, null bila lolos
 */
function gr_validateAgainstPo(mysqli $conn, int $poId, array $items, int $excludeGrId = 0): ?string {
    if ($poId <= 0) return null;

    $ordered = [];
    $stmt = $conn->prepare("SELECT id, quantity FROM purchase_order_items WHERE po_id = ?");
    if (!$stmt) return 'db_error';
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $ordered[(int)$r['id']] = (float)$r['quantity'];
    $stmt->close();

    $already = gr_acceptedByPoItem($conn, $poId, $excludeGrId);
    // Baris yang sisanya sudah dinyatakan tidak akan datang lagi oleh Goods
    // Receipt LAIN. GR yang sedang disunting dikecualikan lewat $excludeGrId
    // supaya penanda miliknya sendiri tidak memblokir penyimpanannya sendiri.
    $closed  = gr_closedPoItems($conn, $poId, $excludeGrId);

    foreach ($items as $it) {
        $poItemId = (int)($it['po_item_id'] ?? 0);
        if ($poItemId <= 0) continue;             // item tambahan → lewati
        if (!isset($ordered[$poItemId])) {
            return 'gr_po_item_mismatch';          // anti ID tampering
        }
        // PENGUNCIAN: sekali sisa baris dinyatakan tidak tersedia, baris itu
        // tidak boleh menerima barang lagi. Kalau ternyata supplier tetap
        // mengirimnya, penutupan harus DIBATALKAN dulu lewat Edit pada Goods
        // Receipt yang memasang penandanya.
        if (!empty($closed[$poItemId]) && (float)$it['accepted_quantity'] > 0.00001) {
            return 'gr_line_closed_locked';
        }
        $limit = $ordered[$poItemId] - (float)($already[$poItemId] ?? 0);
        if ((float)$it['accepted_quantity'] > $limit + 0.00001) {
            return 'gr_qty_exceeds_remaining';
        }
    }
    return null;
}

/* ============================================================
   CREATE
   ============================================================ */

/**
 * Simpan Goods Receipt baru (transaksional).
 * Semua validasi dilakukan server-side; tidak ada nilai yang dipercaya
 * langsung dari form.
 */
function gr_create(mysqli $conn, array $post): array {
    if (!isLoggedIn())                 return ['success' => false, 'message_key' => 'no_permission'];
    if (!canCreateGoodsReceipt())      return ['success' => false, 'message_key' => 'no_permission'];
    if (!gr_csrfVerify((string)($post['csrf_token'] ?? '')))
                                       return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!gr_tablesExist($conn))        return ['success' => false, 'message_key' => 'gr_tables_missing'];

    $poId         = (int)($post['po_id'] ?? 0);
    $receiptDate  = trim((string)($post['receipt_date'] ?? ''));
    $deliveryNote = trim((string)($post['delivery_note_number'] ?? ''));
    $notes        = trim((string)($post['notes'] ?? ''));
    $varReason    = trim((string)($post['variance_reason'] ?? ''));
    $status       = (string)($post['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'received'], true)) $status = 'draft';

    if ($receiptDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
        return ['success' => false, 'message_key' => 'gr_receipt_date_required'];
    }

    $overallDisc  = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($post['overall_discount'] ?? 0) : (float)($post['overall_discount'] ?? 0);
    $overallRound = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($post['overall_price_rounding'] ?? 0) : (float)($post['overall_price_rounding'] ?? 0);
    $tax          = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($post['tax'] ?? 0) : (float)($post['tax'] ?? 0);

    // ── OPSI PEMBAYARAN ───────────────────────────────────────────────────
    //   'now'      → bayar langsung: keempat field Payment Information WAJIB,
    //                payment_status = 'paid', Create Invoice langsung terbuka.
    //   'deferred' → pembayaran tertunda: field pembayaran tidak diminta,
    //                payment_status = 'unpaid'. BARANG TETAP DITERIMA lewat
    //                jalur yang sama persis (item, status GR, dan perhitungan
    //                status PO tidak dibedakan sedikit pun). Pembayarannya
    //                dicatat kemudian lewat gr_recordPayment().
    // Bila migrasi payment_status belum dijalankan, opsi dipaksa ke 'now'
    // supaya tidak ada baris unpaid yang tersimpan tanpa kolom penampungnya.
    $hasPayStatus  = gr_hasPaymentStatusBaseColumn($conn);
    $paymentOption = $hasPayStatus ? gr_normalizePaymentOption($post) : 'now';
    $paymentStatus = ($paymentOption === 'deferred') ? 'unpaid' : 'paid';

    // Payment Information — payment_by & payer_name disimpan sebagai NAMA
    // (bukan ID) agar permanen walaupun akun user dihapus.
    $hasPay = gr_hasPaymentColumns($conn);
    $pay    = ['payment_method' => null, 'payment_by' => null, 'payer_name' => null, 'payer_shift' => null];
    if ($hasPay) {
        $payRes = gr_collectPaymentInfo($conn, $post, ($paymentOption === 'now'));
        if ($payRes['error'] !== null) {
            return ['success' => false, 'message_key' => $payRes['error']];
        }
        $pay = $payRes['data'];
    }

    // Dokumen = FOTO INVOICE SUPPLIER. Memakai pf_handleDocumentUpload() yang
    // sama dengan purchase_add.php, sehingga file tersimpan di folder yang sama
    // (uploads/purchase/) dan dipakai ulang oleh Purchase Invoice tanpa upload
    // dua kali.
    //
    // WAJIB pada KEDUA opsi pembayaran. Invoice supplier ikut datang bersama
    // barang, jadi keberadaannya tidak ada hubungannya dengan kapan uang
    // dibayarkan. Syarat ini sengaja BERDIRI SENDIRI — tidak lagi menempel
    // pada $hasPay — supaya memilih Pembayaran Tertunda tidak pernah membuat
    // upload invoice ikut menjadi opsional.
    $document = null;
    if (function_exists('pf_handleDocumentUpload')) {
        $document = pf_handleDocumentUpload('') ?: null;
    }
    if ($document === null) {
        return ['success' => false, 'message_key' => 'gr_document_required'];
    }

    $norm = gr_normalizePostedItems($post);
    if ($norm['error'] !== null) {
        return ['success' => false, 'message_key' => $norm['error']];
    }
    $items = $norm['items'];

    // NAMA purchase staff, di-SNAPSHOT sebagai TEKS sehingga permanen:
    //   - GR DENGAN PO  → dari kontak Shipping Address PO (diisi di bawah)
    //   - GR TANPA PO   → dari dropdown Purchase Staff pada form
    // gr_userNameById() adalah fungsi yang SAMA dengan yang dipakai
    // payment_by dan payer_name, jadi tidak ada mekanisme baru.
    $purchaseStaffName = '';
    if ($poId <= 0) {
        $__psId = (int)($post['purchase_staff_id'] ?? 0);
        if ($__psId > 0) $purchaseStaffName = (string)gr_userNameById($conn, $__psId);

        // WAJIB pada GR tanpa PO yang dibayar langsung — sejajar dengan
        // purchase_add.php yang menandai field ini required. Tidak diminta
        // saat Pembayaran Tertunda, karena dropdown-nya memang tidak
        // ditampilkan pada opsi tersebut.
        if ($purchaseStaffName === ''
            && gr_hasPurchaseStaffColumn($conn)
            && gr_normalizePaymentOption($post) === 'now') {
            return ['success' => false, 'message_key' => 'gr_purchase_staff_required'];
        }
    }

    // Header PO (snapshot) + supplier
    $poNumber     = null;
    $supplierId   = (int)($post['supplier_id'] ?? 0) ?: null;
    $supplierName = null;

    if ($poId > 0) {
        $stmt = $conn->prepare(
            // company_contact diambil sekalian di query yang sudah ada —
            // tidak ada query tambahan — untuk menurunkan NAMA purchase staff.
            "SELECT po.po_number, po.supplier_id, po.status, po.company_contact,
                    s.name AS supplier_name
               FROM purchase_orders po
               LEFT JOIN suppliers s ON s.id = po.supplier_id
              WHERE po.id = ? LIMIT 1"
        );
        if (!$stmt) return ['success' => false, 'message_key' => 'db_error'];
        $stmt->bind_param('i', $poId);
        $stmt->execute();
        $poRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$poRow) return ['success' => false, 'message_key' => 'po_not_found'];
        if ($poRow['status'] === 'cancelled') {
            return ['success' => false, 'message_key' => 'gr_po_cancelled'];
        }
        $poNumber     = $poRow['po_number'];
        $supplierId   = $poRow['supplier_id'] !== null ? (int)$poRow['supplier_id'] : null;
        $supplierName = $poRow['supplier_name'];

        // GR DENGAN PO: purchase staff = NAMA pada kontak Shipping Address
        // milik PO, tanpa nomor telepon. Dropdown pada form TIDAK dipakai
        // di jalur ini — kalaupun ikut terkirim, nilainya diabaikan.
        $purchaseStaffName = gr_contactPersonName($poRow['company_contact'] ?? '');

        $err = gr_validateAgainstPo($conn, $poId, $items, 0);
        if ($err !== null) return ['success' => false, 'message_key' => $err];
    } elseif ($supplierId) {
        $stmt = $conn->prepare("SELECT name FROM suppliers WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $supplierId);
            $stmt->execute();
            $sr = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $supplierName = $sr['name'] ?? null;
        }
    }

    $subtotal = 0.0;
    foreach ($items as $it) $subtotal += (float)$it['total_price'];
    $totalAmount = $subtotal - $overallDisc + $overallRound + $tax;

    $userId   = (int)$_SESSION['user_id'];
    $userName = gr_currentUserName($conn);

    $conn->begin_transaction();
    try {
        // gr_number UNIQUE — retry beberapa kali bila dua GR disimpan bersamaan
        $attempts = 0;
        $ok       = false;
        $grId     = 0;
        $grNumber = '';
        // Jejak pencatat pembayaran hanya diisi bila memang dibayar saat ini.
        // Untuk opsi tertunda ketiganya NULL dan baru terisi oleh
        // gr_recordPayment() ketika pembayaran benar-benar dilakukan.
        $paidAt     = ($paymentStatus === 'paid') ? date('Y-m-d H:i:s') : null;
        $paidById   = ($paymentStatus === 'paid') ? $userId   : null;
        $paidByName = ($paymentStatus === 'paid') ? $userName : null;

        do {
            $grNumber = gr_generateNumber($conn);
            $poIdParam = $poId > 0 ? $poId : null;
            if ($hasPay && $hasPayStatus) {
                $stmt = $conn->prepare(
                    "INSERT INTO goods_receipts
                        (gr_number, po_id, po_number, supplier_id, supplier_name, receipt_date,
                         delivery_note_number, status, notes, variance_reason,
                         overall_discount, overall_price_rounding, tax, total_amount,
                         created_by, created_by_name,
                         payment_method, payment_by, payer_name, payer_shift, document,
                         payment_status, paid_at, paid_by, paid_by_name)
                     VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?, ?,?,?,?,?, ?,?,?,?)"
                );
                if (!$stmt) throw new Exception('db_error');
                $stmt->bind_param(
                    'sisissssssddddis' . 'sssis' . 'ssis',
                    $grNumber, $poIdParam, $poNumber, $supplierId, $supplierName, $receiptDate,
                    $deliveryNote, $status, $notes, $varReason,
                    $overallDisc, $overallRound, $tax, $totalAmount,
                    $userId, $userName,
                    $pay['payment_method'], $pay['payment_by'], $pay['payer_name'], $pay['payer_shift'], $document,
                    $paymentStatus, $paidAt, $paidById, $paidByName
                );
                $ok    = $stmt->execute();
                $isDup = (!$ok && (int)$conn->errno === 1062);
                if ($ok) $grId = (int)$stmt->insert_id;
                $stmt->close();
                $attempts++;
                continue;
            }
            if ($hasPay) {
                $stmt = $conn->prepare(
                    "INSERT INTO goods_receipts
                        (gr_number, po_id, po_number, supplier_id, supplier_name, receipt_date,
                         delivery_note_number, status, notes, variance_reason,
                         overall_discount, overall_price_rounding, tax, total_amount,
                         created_by, created_by_name,
                         payment_method, payment_by, payer_name, payer_shift, document)
                     VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?, ?,?,?,?,?)"
                );
                if (!$stmt) throw new Exception('db_error');
                $stmt->bind_param(
                    'sisissssssddddis' . 'sssis',
                    $grNumber, $poIdParam, $poNumber, $supplierId, $supplierName, $receiptDate,
                    $deliveryNote, $status, $notes, $varReason,
                    $overallDisc, $overallRound, $tax, $totalAmount,
                    $userId, $userName,
                    $pay['payment_method'], $pay['payment_by'], $pay['payer_name'], $pay['payer_shift'], $document
                );
                $ok    = $stmt->execute();
                $isDup = (!$ok && (int)$conn->errno === 1062);
                if ($ok) $grId = (int)$stmt->insert_id;
                $stmt->close();
                $attempts++;
                continue;
            }
            $stmt = $conn->prepare(
                "INSERT INTO goods_receipts
                    (gr_number, po_id, po_number, supplier_id, supplier_name, receipt_date,
                     delivery_note_number, status, notes, variance_reason,
                     overall_discount, overall_price_rounding, tax, total_amount,
                     created_by, created_by_name)
                 VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?)"
            );
            if (!$stmt) throw new Exception('db_error');
            // 16 kolom → 16 karakter tipe:
            //  s gr_number | i po_id | s po_number | i supplier_id | s supplier_name
            //  s receipt_date | s delivery_note | s status | s notes | s variance_reason
            //  d discount | d rounding | d tax | d total | i created_by | s created_by_name
            $stmt->bind_param(
                'sisissssssddddis',
                $grNumber, $poIdParam, $poNumber, $supplierId, $supplierName, $receiptDate,
                $deliveryNote, $status, $notes, $varReason,
                $overallDisc, $overallRound, $tax, $totalAmount,
                $userId, $userName
            );
            $ok    = $stmt->execute();
            $isDup = (!$ok && (int)$conn->errno === 1062);
            if ($ok) $grId = (int)$stmt->insert_id;
            $stmt->close();
            $attempts++;
        } while ($isDup && $attempts < 3);

        if (!$ok) throw new Exception('gr_number_conflict');

        gr_insertItems($conn, $grId, $items);

        // Snapshot NAMA purchase staff. Ditulis sebagai UPDATE terpisah,
        // BUKAN ditambahkan ke ketiga cabang INSERT di atas: cabang-cabang itu
        // sudah bercabang menurut ketersediaan kolom pembayaran, dan menambah
        // satu kolom lagi berarti mengubah enam pasang daftar kolom sekaligus
        // beserta string tipe bind_param-nya — rawan keliru tanpa manfaat.
        // Dilewati bila migrasinya belum dijalankan.
        if ($purchaseStaffName !== '' && gr_hasPurchaseStaffColumn($conn)) {
            $stmtPS = $conn->prepare(
                "UPDATE goods_receipts SET purchase_staff_name = ? WHERE id = ?"
            );
            if ($stmtPS) {
                $stmtPS->bind_param('si', $purchaseStaffName, $grId);
                $stmtPS->execute();
                $stmtPS->close();
            }
        }


        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message_key' => 'db_error'];
    }

    // Status PO dihitung ulang hanya untuk GR yang benar-benar diterima
    if ($poId > 0 && $status === 'received') {
        gr_recalcPoStatus($conn, $poId);
    }

    gr_csrfRegenerate();
    if (function_exists('logActivity')) {
        // Catat SELURUH data penting penerimaan barang, bukan hanya nomornya
        $accTotal = 0.0; $rejTotal = 0.0; $extraCnt = 0;
        foreach ($items as $__it) {
            $accTotal += (float)$__it['accepted_quantity'];
            $rejTotal += (float)$__it['rejected_quantity'];
            if (empty($__it['po_item_id'])) $extraCnt++;
        }
        logActivity('gr_create', 'Goods Receipt created | ' . gr_logLine([
            'GR'             => $grNumber,
            'PO'             => $poNumber,
            'Supplier'       => $supplierName,
            'Receipt date'   => $receiptDate,
            'Delivery note'  => $deliveryNote,
            'Status'         => $status,
            'Items'          => count($items),
            'Closed lines'   => gr_closedLinesLogLine($items),
            'Extra items'    => $extraCnt ?: null,
            'Accepted qty'   => gr_formatQty($accTotal),
            'Rejected qty'   => $rejTotal > 0 ? gr_formatQty($rejTotal) : null,
            'Discount'       => $overallDisc > 0 ? $overallDisc : null,
            'Rounding'       => $overallRound != 0 ? $overallRound : null,
            'Tax'            => $tax > 0 ? $tax : null,
            'Total'          => $totalAmount,
            'Purchase staff' => $purchaseStaffName !== '' ? $purchaseStaffName : null,
            'Payment option' => $hasPayStatus ? ($paymentOption === 'deferred' ? 'Deferred' : 'Pay now') : null,
            'Payment status' => $hasPayStatus ? $paymentStatus : null,
            'Payment method' => $pay['payment_method'] ?? null,
            'Payment by'     => $pay['payment_by'] ?? null,
            'Payer'          => $pay['payer_name'] ?? null,
            'Payer shift'    => $pay['payer_shift'] ?? null,
            'Document'       => $document,
            'Photo source'   => $document ? gr_documentSource($post) : null,
            'Variance note'  => $varReason,
            'By'             => $userName,
        ]));
    }
    return ['success' => true, 'message_key' => 'save_success', 'gr_id' => $grId, 'gr_number' => $grNumber];
}

/** Insert baris-baris GR (dipakai create & update). */
/**
 * Ringkasan baris yang ditutup, untuk activity log.
 * Menyertakan nama item, kekurangan qty, dan alasannya — supaya catatan log
 * bisa berdiri sendiri tanpa harus membuka dokumennya lagi.
 * Mengembalikan NULL bila tidak ada baris yang ditutup, sehingga gr_logLine()
 * melewatkannya dan baris log tetap ringkas.
 */
function gr_closedLinesLogLine(array $items): ?string {
    $parts = [];
    foreach ($items as $it) {
        if (empty($it['close_line'])) continue;
        $short = (float)($it['ordered_quantity'] ?? 0) - (float)($it['accepted_quantity'] ?? 0);
        $parts[] = sprintf(
            '%s (short %s: %s)',
            (string)($it['item_name'] ?? '-'),
            gr_formatQty($short > 0 ? $short : 0),
            (string)($it['notes'] ?? '-')
        );
    }
    return $parts ? implode('; ', $parts) : null;
}

function gr_insertItems(mysqli $conn, int $grId, array $items): void {
    // Kolom close_line hanya disebut bila migrasinya sudah dijalankan,
    // sehingga penyimpanan tetap berhasil pada server yang belum dimigrasi.
    $hasClose = gr_hasCloseLineColumn($conn);

    $stmt = $hasClose
        ? $conn->prepare(
            "INSERT INTO goods_receipt_items
                (gr_id, po_item_id, inventory_id, item_name,
                 ordered_quantity, received_quantity, accepted_quantity, rejected_quantity,
                 close_line,
                 unit_id, category_id, po_unit_price, unit_price, discount, price_rounding,
                 total_price, notes)
             VALUES (?,?,?,?, ?,?,?,?, ?, ?,?,?,?,?,?, ?,?)"
          )
        : $conn->prepare(
            "INSERT INTO goods_receipt_items
                (gr_id, po_item_id, inventory_id, item_name,
                 ordered_quantity, received_quantity, accepted_quantity, rejected_quantity,
                 unit_id, category_id, po_unit_price, unit_price, discount, price_rounding,
                 total_price, notes)
             VALUES (?,?,?,?, ?,?,?,?, ?,?,?,?,?,?, ?,?)"
          );
    if (!$stmt) throw new Exception('db_error');

    foreach ($items as $it) {
        $closeVal = (int)($it['close_line'] ?? 0);
        if ($hasClose) {
            $stmt->bind_param(
                'iiis' . 'dddd' . 'i' . 'iidddd' . 'ds',
                $grId,
                $it['po_item_id'],
                $it['inventory_id'],
                $it['item_name'],
                $it['ordered_quantity'],
                $it['received_quantity'],
                $it['accepted_quantity'],
                $it['rejected_quantity'],
                $closeVal,
                $it['unit_id'],
                $it['category_id'],
                $it['po_unit_price'],
                $it['unit_price'],
                $it['discount'],
                $it['price_rounding'],
                $it['total_price'],
                $it['notes']
            );
        } else {
            $stmt->bind_param(
                'iiis' . 'dddd' . 'iidddd' . 'ds',
                $grId,
                $it['po_item_id'],
                $it['inventory_id'],
                $it['item_name'],
                $it['ordered_quantity'],
                $it['received_quantity'],
                $it['accepted_quantity'],
                $it['rejected_quantity'],
                $it['unit_id'],
                $it['category_id'],
                $it['po_unit_price'],
                $it['unit_price'],
                $it['discount'],
                $it['price_rounding'],
                $it['total_price'],
                $it['notes']
            );
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('db_error');
        }
    }
    $stmt->close();
}

/* ============================================================
   UPDATE
   ============================================================ */

/**
 * Update Goods Receipt (transaksional).
 * GR yang sudah 'cancelled' tidak bisa diubah lagi (histori terkunci).
 * GR yang sudah dipakai sebagai sumber Purchase Invoice juga terkunci supaya
 * invoice tidak menjadi tidak konsisten dengan dokumen penerimaannya.
 */
function gr_update(mysqli $conn, int $grId, array $post): array {
    if (!isLoggedIn())             return ['success' => false, 'message_key' => 'no_permission'];
    if (!canUpdateGoodsReceipt())  return ['success' => false, 'message_key' => 'no_permission'];
    if (!gr_csrfVerify((string)($post['csrf_token'] ?? '')))
                                   return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!gr_tablesExist($conn))    return ['success' => false, 'message_key' => 'gr_tables_missing'];

    $existing = gr_getOne($conn, $grId);
    if (!$existing) return ['success' => false, 'message_key' => 'gr_not_found'];
    if ($existing['status'] === 'cancelled') {
        return ['success' => false, 'message_key' => 'gr_cancelled_locked'];
    }
    if (gr_countInvoicesByGr($conn, $grId) > 0) {
        return ['success' => false, 'message_key' => 'gr_has_invoice_locked'];
    }

    $receiptDate  = trim((string)($post['receipt_date'] ?? ''));
    $deliveryNote = trim((string)($post['delivery_note_number'] ?? ''));
    $notes        = trim((string)($post['notes'] ?? ''));
    $varReason    = trim((string)($post['variance_reason'] ?? ''));
    $status       = (string)($post['status'] ?? $existing['status']);
    if (!in_array($status, ['draft', 'received'], true)) $status = 'draft';

    if ($receiptDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
        return ['success' => false, 'message_key' => 'gr_receipt_date_required'];
    }

    // ── PENGAMAN AKUNTABILITAS ────────────────────────────────────────────
    // GR yang barangnya SUDAH DITERIMA tetapi BELUM DIBAYAR adalah hutang
    // usaha: barang sudah di gudang, uang belum keluar. Mengubah qty atau
    // harga pada kondisi itu menggeser nilai hutang ke supplier, jadi wajib
    // disertai keterangan. Memakai field variance_reason YANG SUDAH ADA di
    // form, sehingga tidak ada kolom maupun field baru.
    // gr_paymentStatus() SELALU mengembalikan 'cancel' untuk dokumen yang
    // dibatalkan, sehingga syarat "masih hutang" di bawah ini otomatis tidak
    // pernah terpenuhi pada dokumen cancel — pembayarannya tidak ikut
    // terbaca sebagai unpaid.
    $hasPayStatus = gr_hasPaymentStatusBaseColumn($conn);
    $payStatusNow = gr_paymentStatus($existing);
    if ($hasPayStatus
        && $existing['status'] === 'received'
        && $payStatusNow === 'unpaid'
        && trim((string)($post['variance_reason'] ?? '')) === '') {
        return ['success' => false, 'message_key' => 'gr_edit_reason_required'];
    }

    $overallDisc  = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($post['overall_discount'] ?? 0) : (float)($post['overall_discount'] ?? 0);
    $overallRound = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($post['overall_price_rounding'] ?? 0) : (float)($post['overall_price_rounding'] ?? 0);
    $tax          = function_exists('pf_parsePostedAmount') ? pf_parsePostedAmount($post['tax'] ?? 0) : (float)($post['tax'] ?? 0);

    // ── FOTO INVOICE SUPPLIER ─────────────────────────────────────────────
    // PERBAIKAN: sebelumnya kolom `document` tidak pernah ikut di-UPDATE dan
    // pf_handleDocumentUpload() tidak pernah dipanggil di fungsi ini, sehingga
    // penggantian foto invoice lewat halaman edit selalu dibuang diam-diam.
    // Pola pemanggilannya disamakan dengan purchases_function.php: nama file
    // lama dioper sebagai argumen, sehingga bila user TIDAK memilih berkas
    // baru, foto lama dipertahankan apa adanya.
    $hasPay   = gr_hasPaymentColumns($conn);
    $document = (string)($existing['document'] ?? '');
    if (function_exists('pf_handleDocumentUpload')) {
        $document = pf_handleDocumentUpload($document);
    }
    $document = ($document !== '') ? $document : null;

    // Data pembayaran TIDAK pernah ditulis dari halaman edit. Satu-satunya
    // pintu penulisannya adalah gr_recordPayment() lewat modal Record Payment
    // di goods_receipt_view.php, supaya tidak ada dua tempat yang menulis
    // kolom yang sama. Nilai lama dibaca di sini hanya untuk pembanding log.
    $pay = [
        'payment_method' => $existing['payment_method'] ?? null,
        'payment_by'     => $existing['payment_by']     ?? null,
        'payer_name'     => $existing['payer_name']     ?? null,
        'payer_shift'    => $existing['payer_shift']    ?? null,
    ];

    $norm = gr_normalizePostedItems($post);
    if ($norm['error'] !== null) {
        return ['success' => false, 'message_key' => $norm['error']];
    }
    $items = $norm['items'];

    $poId = (int)($existing['po_id'] ?? 0);
    if ($poId > 0) {
        $err = gr_validateAgainstPo($conn, $poId, $items, $grId);
        if ($err !== null) return ['success' => false, 'message_key' => $err];
    }

    $subtotal = 0.0;
    foreach ($items as $it) $subtotal += (float)$it['total_price'];
    $totalAmount = $subtotal - $overallDisc + $overallRound + $tax;

    $userId   = (int)$_SESSION['user_id'];
    $userName = gr_currentUserName($conn);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE goods_receipts
                SET receipt_date = ?, delivery_note_number = ?, status = ?,
                    notes = ?, variance_reason = ?,
                    overall_discount = ?, overall_price_rounding = ?, tax = ?, total_amount = ?,
                    updated_by = ?, updated_by_name = ?, document = ?
              WHERE id = ?"
        );
        if (!$stmt) throw new Exception('db_error');
        $stmt->bind_param(
            'sssss' . 'dddd' . 'issi',
            $receiptDate, $deliveryNote, $status, $notes, $varReason,
            $overallDisc, $overallRound, $tax, $totalAmount,
            $userId, $userName, $document, $grId
        );
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('db_error'); }
        $stmt->close();

        $del = $conn->prepare("DELETE FROM goods_receipt_items WHERE gr_id = ?");
        if (!$del) throw new Exception('db_error');
        $del->bind_param('i', $grId);
        $del->execute();
        $del->close();

        gr_insertItems($conn, $grId, $items);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message_key' => 'db_error'];
    }

    if ($poId > 0) gr_recalcPoStatus($conn, $poId);

    gr_csrfRegenerate();
    if (function_exists('logActivity')) {
        // Catat FIELD APA SAJA yang berubah, lengkap dengan nilai lama -> baru
        $changes = [];
        $cmp = [
            'Receipt date'   => [$existing['receipt_date'],          $receiptDate],
            'Delivery note'  => [$existing['delivery_note_number'],  $deliveryNote],
            'Status'         => [$existing['status'],                $status],
            'Notes'          => [$existing['notes'],                 $notes],
            'Variance note'  => [$existing['variance_reason'],       $varReason],
            'Discount'       => [(float)$existing['overall_discount'],       $overallDisc],
            'Rounding'       => [(float)$existing['overall_price_rounding'], $overallRound],
            'Tax'            => [(float)$existing['tax'],                    $tax],
            'Total'          => [(float)$existing['total_amount'],           $totalAmount],
        ];
        // Data pembayaran tidak lagi bisa diubah dari halaman edit, jadi tidak
        // ada perbandingan lama->baru untuk keempat field itu. Yang tersisa
        // dan memang bisa diganti dari sini adalah foto invoice supplier.
        if ($hasPay) {
            $cmp['Document'] = [$existing['document'] ?? '', $document];
        }
        foreach ($cmp as $label => $pair) {
            if ((string)$pair[0] === (string)$pair[1]) continue;
            $changes[] = $label . ': "' . trim((string)$pair[0]) . '" -> "' . trim((string)$pair[1]) . '"';
        }
        if (count($existing['items']) !== count($items)) {
            $changes[] = 'Item count: ' . count($existing['items']) . ' -> ' . count($items);
        }
        $changes[] = 'Items rewritten: ' . count($items);
        $__closed  = gr_closedLinesLogLine($items);
        if ($__closed !== null) $changes[] = 'Closed lines: ' . $__closed;
        if ($hasPay && (string)($existing['document'] ?? '') !== (string)$document) {
            $changes[] = 'Photo source: ' . gr_documentSource($post);
        }

        logActivity('gr_update', 'Goods Receipt updated | ' . gr_logLine([
            'GR' => $existing['gr_number'],
            'PO' => $existing['po_number'],
            'By' => $userName,
        ]) . ' | Changes: ' . (count($changes) ? implode('; ', $changes) : 'none'));
    }
    return ['success' => true, 'message_key' => 'save_success'];
}

/* ============================================================
   RECORD PAYMENT  (pelunasan Goods Receipt berstatus unpaid)
   ============================================================ */

/**
 * Catat pembayaran untuk Goods Receipt yang dibuat dengan opsi
 * PEMBAYARAN TERTUNDA.
 *
 * SATU-SATUNYA pintu penulisan kolom pembayaran di seluruh sistem. Halaman
 * edit sengaja tidak pernah menulis kolom-kolom ini supaya tidak ada dua
 * tempat yang menulis data yang sama (anti-double).
 *
 * Yang disentuh HANYA kolom pembayaran:
 *   payment_method, payment_by, payer_name, payer_shift,
 *   payment_status, paid_at, paid_by, paid_by_name
 * Baris item, qty, harga, diskon, pajak, total, status GR, dan status
 * Purchase Order TIDAK PERNAH ikut ditulis ulang di sini.
 *
 * payment_by / payer_name disimpan sebagai NAMA (bukan ID) sehingga tetap
 * terbaca walaupun akun user yang bersangkutan dihapus kemudian.
 */
function gr_recordPayment(mysqli $conn, int $grId, array $post): array {
    if (!isLoggedIn())            return ['success' => false, 'message_key' => 'no_permission'];
    if (!canUpdateGoodsReceipt()) return ['success' => false, 'message_key' => 'no_permission'];
    if (!gr_csrfVerify((string)($post['csrf_token'] ?? '')))
                                  return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!gr_tablesExist($conn))   return ['success' => false, 'message_key' => 'gr_tables_missing'];
    if (!gr_hasPaymentStatusBaseColumn($conn) || !gr_hasPaymentColumns($conn)) {
        return ['success' => false, 'message_key' => 'gr_tables_missing'];
    }

    $existing = gr_getOne($conn, $grId);
    if (!$existing) return ['success' => false, 'message_key' => 'gr_not_found'];
    if ($existing['status'] === 'cancelled') {
        return ['success' => false, 'message_key' => 'gr_cancelled_locked'];
    }
    // Pembayaran hanya masuk akal untuk barang yang memang sudah diterima.
    if ($existing['status'] !== 'received') {
        return ['success' => false, 'message_key' => 'gr_payment_needs_received'];
    }
    // PENGAMAN: sekali tercatat lunas, data pembayaran terkunci. Uang sudah
    // berpindah, jadi angkanya tidak boleh berubah lagi diam-diam. Hanya
    // superadmin yang boleh menimpanya.
    if (gr_paymentStatus($existing) === 'paid' && !isSuperAdmin()) {
        return ['success' => false, 'message_key' => 'gr_already_paid'];
    }

    // Keempat field WAJIB — di titik ini pembayaran benar-benar dilakukan.
    $payRes = gr_collectPaymentInfo($conn, $post, true);
    if ($payRes['error'] !== null) {
        return ['success' => false, 'message_key' => $payRes['error']];
    }
    $pay = $payRes['data'];

    // ── TANGGAL PEMBAYARAN ──────────────────────────────────────────────
    // Dipilih user lewat date picker di modal Record Payment (default tanggal
    // sekarang, ditampilkan DD-MM-YYYY, dikirim ke server dalam Y-m-d).
    // Divalidasi ketat sebagai tanggal kalender yang benar-benar valid — bukan
    // sekadar cocok pola regex — supaya nilai rekayasa seperti 31-02-2026
    // tidak lolos. Tanggal inilah yang nanti dipakai sebagai Input Date saat
    // Purchase Invoice dibuat dari GR ini (lihat gr_createInvoiceFromGr()).
    $paymentDateRaw = trim((string)($post['payment_date'] ?? ''));
    $paymentDateObj = DateTime::createFromFormat('Y-m-d', $paymentDateRaw);
    if (!$paymentDateObj || $paymentDateObj->format('Y-m-d') !== $paymentDateRaw) {
        return ['success' => false, 'message_key' => 'gr_payment_date_invalid'];
    }
    $paymentDate = $paymentDateRaw;

    $userId   = (int)$_SESSION['user_id'];
    $userName = gr_currentUserName($conn);
    // Kalender diambil dari pilihan user di atas; jam:menit:detik tetap dari
    // waktu penyimpanan aktual, sehingga perilaku pencatatan waktu lama tidak
    // berubah — hanya tanggalnya saja yang kini bisa disesuaikan user.
    $paidAt   = $paymentDate . ' ' . date('H:i:s');

    /* Catatan pembayaran dari modal Record Payment.
       DIGABUNG, bukan menimpa: goods_receipts.notes bisa sudah terisi saat GR
       dibuat, dan menimpanya akan menghilangkan catatan penerimaan barang.
       Kalau sudah ada isinya, catatan pembayaran ditambah di baris baru.
       Kolom inilah yang dibaca saat purchase invoice dibuat dari GR (baris
       ~2019), jadi catatannya terbawa ke invoice tanpa perubahan lain. */
    $payNote = trim((string)($post['notes'] ?? ''));
    if (mb_strlen($payNote) > 500) { $payNote = mb_substr($payNote, 0, 500); }
    $notesLama = trim((string)($existing['notes'] ?? ''));
    if ($notesLama !== '' && $payNote !== '') {
        $notesBaru = $notesLama . "\n" . $payNote;
    } elseif ($payNote !== '') {
        $notesBaru = $payNote;
    } else {
        $notesBaru = $notesLama;
    }

    $stmt = $conn->prepare(
        "UPDATE goods_receipts
            SET payment_method = ?, payment_by = ?, payer_name = ?, payer_shift = ?,
                notes = ?,
                payment_status = 'paid', paid_at = ?, paid_by = ?, paid_by_name = ?
          WHERE id = ?"
    );
    if (!$stmt) return ['success' => false, 'message_key' => 'db_error'];
    $stmt->bind_param(
        'sssi' . 's' . 'sisi',
        $pay['payment_method'], $pay['payment_by'], $pay['payer_name'], $pay['payer_shift'],
        $notesBaru,
        $paidAt, $userId, $userName, $grId
    );
    $okExec = $stmt->execute();
    $stmt->close();
    if (!$okExec) return ['success' => false, 'message_key' => 'db_error'];

    gr_csrfRegenerate();
    if (function_exists('logActivity')) {
        logActivity('gr_payment', 'Goods Receipt payment recorded | ' . gr_logLine([
            'GR'             => $existing['gr_number'],
            'PO'             => $existing['po_number'],
            'Supplier'       => $existing['supplier_name'],
            'Total'          => (float)$existing['total_amount'],
            'Previous state' => gr_paymentStatus($existing),
            'Payment method' => $pay['payment_method'],
            'Payment by'     => $pay['payment_by'],
            'Payer'          => $pay['payer_name'],
            'Payer shift'    => $pay['payer_shift'],
            'Paid at'        => $paidAt,
            'Recorded by'    => $userName,
        ]));
    }
    return ['success' => true, 'message_key' => 'gr_payment_recorded'];
}

/* ============================================================
   CANCEL  (pengganti DELETE untuk dokumen yang sudah berjalan)
   ============================================================ */

/**
 * Batalkan Goods Receipt.
 * Baris GR TIDAK dihapus — statusnya menjadi 'cancelled' sehingga histori
 * dan audit trail tetap utuh (requirement: DELETE = FORBIDDEN untuk transaksi
 * yang sudah berjalan). Status PO dihitung ulang setelahnya.
 */
function gr_cancel(mysqli $conn, int $grId, string $csrf, string $reason = ''): array {
    if (!isLoggedIn())             return ['success' => false, 'message_key' => 'no_permission'];
    if (!canCancelGoodsReceipt())  return ['success' => false, 'message_key' => 'no_permission'];
    if (!gr_csrfVerify($csrf))     return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!gr_tablesExist($conn))    return ['success' => false, 'message_key' => 'gr_tables_missing'];

    $stmt = $conn->prepare("SELECT gr_number, po_id, status FROM goods_receipts WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false, 'message_key' => 'db_error'];
    $stmt->bind_param('i', $grId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message_key' => 'gr_not_found'];
    if ($row['status'] === 'cancelled') {
        return ['success' => false, 'message_key' => 'gr_already_cancelled'];
    }
    if (gr_countInvoicesByGr($conn, $grId) > 0) {
        return ['success' => false, 'message_key' => 'gr_has_invoice_locked'];
    }
    if (gr_countReturnsByGr($conn, $grId) > 0) {
        return ['success' => false, 'message_key' => 'gr_has_return_locked'];
    }

    // ── PENGAMAN AKUNTABILITAS ────────────────────────────────────────────
    // Membatalkan GR berstatus 'received' berarti menghapus catatan
    // penerimaan atas barang yang SECARA FISIK SUDAH ADA DI GUDANG — dan
    // bila pembayarannya tertunda, sekaligus melenyapkan hutang ke supplier
    // dari sistem. Karena itu alasannya wajib ditulis dan disimpan permanen
    // di kolom cancel_reason, bukan sekadar lewat di activity log.
    // GR berstatus 'draft' tidak dikenai syarat ini: belum ada dampak apa pun.
    $reason      = trim($reason);
    $hasReasonCol = gr_hasCancelReasonColumn($conn);
    if ($row['status'] === 'received' && $hasReasonCol && $reason === '') {
        return ['success' => false, 'message_key' => 'gr_cancel_reason_required'];
    }

    $userId   = (int)$_SESSION['user_id'];
    $userName = gr_currentUserName($conn);

    // ── STATUS PEMBAYARAN Ikut DIPERBARUI ───────────────────────────────
    // Dokumen yang dibatalkan tidak pernah punya uang keluar, jadi kolom
    // payment_status ditulis 'cancel' — bukan dibiarkan membawa nilai lama
    // ('unpaid'). Inilah yang membuat filter "Unpaid" di halaman daftar
    // benar-benar hanya berisi hutang usaha yang masih hidup.
    // Ditulis hanya bila enum-nya sudah mendukung 'cancel' (sql/
    // goods_receipt_payment_status_cancel.sql); bila belum, pembatalan tetap
    // jalan seperti sebelumnya dan halaman daftar menutupinya lewat klausa
    // `OR gr.status = 'cancelled'` di gr_buildListWhere().
    $setPayCancel = gr_hasPaymentStatusColumn($conn);

    if ($hasReasonCol) {
        $reasonParam = ($reason !== '') ? $reason : null;
        if ($setPayCancel) {
            $upd = $conn->prepare(
                "UPDATE goods_receipts
                    SET status = 'cancelled', cancelled_by = ?, cancelled_by_name = ?,
                        cancelled_at = NOW(), cancel_reason = ?, payment_status = 'cancel'
                  WHERE id = ?"
            );
            if (!$upd) return ['success' => false, 'message_key' => 'db_error'];
            $upd->bind_param('issi', $userId, $userName, $reasonParam, $grId);
        } else {
            $upd = $conn->prepare(
                "UPDATE goods_receipts
                    SET status = 'cancelled', cancelled_by = ?, cancelled_by_name = ?,
                        cancelled_at = NOW(), cancel_reason = ?
                  WHERE id = ?"
            );
            if (!$upd) return ['success' => false, 'message_key' => 'db_error'];
            $upd->bind_param('issi', $userId, $userName, $reasonParam, $grId);
        }
    } else {
        if ($setPayCancel) {
            $upd = $conn->prepare(
                "UPDATE goods_receipts
                    SET status = 'cancelled', cancelled_by = ?, cancelled_by_name = ?, cancelled_at = NOW(),
                        payment_status = 'cancel'
                  WHERE id = ?"
            );
            $typesForBind = 'isi';
        } else {
            $upd = $conn->prepare(
                "UPDATE goods_receipts
                    SET status = 'cancelled', cancelled_by = ?, cancelled_by_name = ?, cancelled_at = NOW()
                  WHERE id = ?"
            );
            $typesForBind = 'isi';
        }
        if (!$upd) return ['success' => false, 'message_key' => 'db_error'];
        $upd->bind_param($typesForBind, $userId, $userName, $grId);
    }
    $okExec = $upd->execute();
    $upd->close();
    if (!$okExec) return ['success' => false, 'message_key' => 'db_error'];

    if (!empty($row['po_id'])) gr_recalcPoStatus($conn, (int)$row['po_id']);

    gr_csrfRegenerate();
    if (function_exists('logActivity')) {
        logActivity('gr_cancel', 'Goods Receipt cancelled | ' . gr_logLine([
            'GR'            => $row['gr_number'],
            'Status before' => $row['status'],
            'Reason'        => $reason !== '' ? $reason : null,
            'Cancelled by'  => $userName,
        ]));
    }
    return ['success' => true, 'message_key' => 'gr_cancelled_success'];
}

/* ============================================================
   TRACEABILITY / HISTORY
   ============================================================ */

/** Daftar Goods Receipt milik satu PO (untuk PO View). */
function gr_getByPo(mysqli $conn, int $poId): array {
    $rows = [];
    if (!gr_tablesExist($conn) || $poId <= 0) return $rows;
    // Sama seperti gr_getList(): kolom payment_status hanya disebut bila
    // migrasinya sudah dijalankan, selebihnya dikirim sebagai 'paid'.
    $payCol = gr_hasPaymentStatusColumn($conn)
        ? 'payment_status'
        : "'paid' AS payment_status";
    $stmt = $conn->prepare(
        "SELECT id, gr_number, receipt_date, status, total_amount, created_by_name,
                $payCol
           FROM goods_receipts
          WHERE po_id = ?
          ORDER BY receipt_date ASC, id ASC"
    );
    if (!$stmt) return $rows;
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

/** Purchase Invoice yang bersumber dari satu GR. */
function gr_getInvoicesByGr(mysqli $conn, int $grId): array {
    $rows = [];
    if ($grId <= 0 || !gr_purchasesHasGrColumn($conn)) return $rows;
    $stmt = $conn->prepare(
        "SELECT id, input_date, invoice_date FROM purchases WHERE gr_id = ? ORDER BY id ASC"
    );
    if (!$stmt) return $rows;
    $stmt->bind_param('i', $grId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function gr_countInvoicesByGr(mysqli $conn, int $grId): int {
    return count(gr_getInvoicesByGr($conn, $grId));
}

/** Purchase Invoice yang bersumber dari satu PO (kolom po_id sudah existing). */
function gr_getInvoicesByPo(mysqli $conn, int $poId): array {
    $rows = [];
    if ($poId <= 0) return $rows;
    $hasGr = gr_purchasesHasGrColumn($conn);
    $sql = $hasGr
        ? "SELECT p.id, p.input_date, p.invoice_date, p.gr_id, g.gr_number
             FROM purchases p
             LEFT JOIN goods_receipts g ON g.id = p.gr_id
            WHERE p.po_id = ? ORDER BY p.id ASC"
        : "SELECT p.id, p.input_date, p.invoice_date, NULL AS gr_id, NULL AS gr_number
             FROM purchases p
            WHERE p.po_id = ? ORDER BY p.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

/** Jumlah Purchase Return (flow baru) yang mereferensikan satu GR. */
function gr_countReturnsByGr(mysqli $conn, int $grId): int {
    if ($grId <= 0 || !function_exists('pret_tablesExist')) return 0;
    if (!pret_tablesExist($conn)) return 0;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM purchase_returns WHERE gr_id = ? AND status <> 'cancelled'"
    );
    if (!$stmt) return 0;
    $stmt->bind_param('i', $grId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

/* ============================================================
   PURCHASE INVOICE DRAFT DARI GOODS RECEIPT
   ============================================================ */

/**
 * Buat Purchase Invoice draft dari Goods Receipt.
 *
 * Invoice memakai data AKTUAL yang benar-benar diterima:
 *   quantity   = accepted_quantity  (bukan qty PO, bukan received sebelum reject)
 *   unit_price = harga aktual GR    (bukan harga PO)
 *
 * Menulis ke tabel EXISTING `purchases` + `purchase_items` sehingga seluruh
 * halaman invoice existing (view/edit/export) langsung bekerja tanpa perubahan.
 * Relasi ditulis ke purchases.po_id (kolom existing) dan purchases.gr_id
 * (kolom baru) untuk traceability dua arah.
 *
 * payment_method sengaja dibiarkan memakai DEFAULT tabel supaya sistem tidak
 * menebak metode pembayaran; user melengkapinya lewat halaman edit invoice
 * yang sudah ada.
 *
 * $inputDateRaw — TANGGAL INPUT DATE PILIHAN USER (format Y-m-d), berasal dari
 * modal "Input Date" pada goods_receipt_view.php. Parameter OPSIONAL: bila
 * dikirim kosong, perilaku lama dipertahankan seratus persen sehingga tidak
 * ada pemanggil lain yang terpengaruh.
 */
function gr_createInvoiceFromGr(mysqli $conn, int $grId, string $csrf, string $inputDateRaw = ''): array {
    if (!isLoggedIn())          return ['success' => false, 'message_key' => 'no_permission'];
    if (!canCreatePurchase())   return ['success' => false, 'message_key' => 'no_permission'];
    if (!gr_csrfVerify($csrf))  return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!gr_tablesExist($conn)) return ['success' => false, 'message_key' => 'gr_tables_missing'];
    if (!gr_purchasesHasGrColumn($conn)) {
        return ['success' => false, 'message_key' => 'gr_tables_missing'];
    }

    // ── INPUT DATE PILIHAN USER ───────────────────────────────────────────
    // Divalidasi PALING AWAL, sebelum satu pun baris dibaca atau ditulis,
    // supaya nilai rekayasa tidak pernah sampai ke transaksi database.
    //
    // Validasinya kalender sungguhan (DateTime::createFromFormat + round-trip
    // compare), BUKAN pola regex — sama persis dengan cara payment_date
    // divalidasi di gr_recordPayment(), sehingga nilai seperti 2026-02-31
    // tidak lolos.
    //
    // Batas maxDate = hari ini DIPERIKSA ULANG di sisi server. Batas yang
    // sama sudah dipasang di date picker, tetapi batas di browser hanya
    // kenyamanan — POST bisa dikirim langsung tanpa lewat halaman, jadi
    // aturannya harus ditegakkan di sini.
    $chosenInputDate = '';
    $inputDateRaw    = trim($inputDateRaw);
    if ($inputDateRaw !== '') {
        $__d = DateTime::createFromFormat('Y-m-d', $inputDateRaw);
        if (!$__d || $__d->format('Y-m-d') !== $inputDateRaw) {
            return ['success' => false, 'message_key' => 'gr_invoice_date_invalid'];
        }
        if ($inputDateRaw > date('Y-m-d')) {
            return ['success' => false, 'message_key' => 'gr_invoice_date_invalid'];
        }
        $chosenInputDate = $inputDateRaw;
    }

    $gr = gr_getOne($conn, $grId);
    if (!$gr) return ['success' => false, 'message_key' => 'gr_not_found'];
    if ($gr['status'] !== 'received') {
        return ['success' => false, 'message_key' => 'gr_invoice_needs_received'];
    }
    // GERBANG PEMBAYARAN.
    // Purchase Invoice hanya boleh terbit setelah pembayaran benar-benar
    // dicatat. Untuk GR bayar langsung syarat ini otomatis terpenuhi sejak
    // penerimaan; untuk GR tertunda, terbuka setelah gr_recordPayment().
    // Baris lama yang dibuat sebelum migrasi bernilai 'paid', jadi tidak ada
    // dokumen lama yang ikut terkunci.
    if (gr_paymentStatus($gr) !== 'paid') {
        return ['success' => false, 'message_key' => 'gr_invoice_needs_paid'];
    }
    if (gr_countInvoicesByGr($conn, $grId) > 0) {
        return ['success' => false, 'message_key' => 'gr_invoice_exists'];
    }

    // Hanya baris dengan accepted_quantity > 0 yang ditagihkan
    $billable = [];
    foreach ($gr['items'] as $it) {
        if ((float)$it['accepted_quantity'] > 0) $billable[] = $it;
    }
    if (!$billable) {
        return ['success' => false, 'message_key' => 'gr_no_accepted_items'];
    }

    $userId      = (int)$_SESSION['user_id'];
    $receiptDate = (string)$gr['receipt_date'];
    // ── URUTAN SUMBER INPUT DATE ──────────────────────────────────────────
    //   1. TANGGAL YANG DIPILIH USER di modal "Input Date" saat menekan
    //      tombol Create Purchase Invoice. Inilah sumber utamanya sekarang:
    //      angka inilah yang muncul di kolom Input Date pada
    //      purchases_invoice.php.
    //   2. goods_receipts.paid_at — cadangan untuk pemanggil yang tidak
    //      mengirim tanggal (perilaku lama dipertahankan utuh).
    //   3. Tanggal penerimaan GR — cadangan terakhir bila paid_at kosong.
    // Invoice Date TIDAK ikut berubah oleh perubahan ini.
    $inputDate   = $chosenInputDate !== ''
                 ? $chosenInputDate
                 : (!empty($gr['paid_at']) ? date('Y-m-d', strtotime((string)$gr['paid_at'])) : $receiptDate);
    $invoiceDate = $receiptDate;
    $supplierId  = $gr['supplier_id'] !== null ? (int)$gr['supplier_id'] : null;
    $poId        = $gr['po_id'] !== null ? (int)$gr['po_id'] : null;
    $notes       = trim((string)($gr['notes'] ?? ''));
    $overallDisc = (float)$gr['overall_discount'];
    $overallRnd  = (float)$gr['overall_price_rounding'];
    $tax         = (float)$gr['tax'];

    $conn->begin_transaction();
    try {
        // purchases.purchase_staff_id & created_by menyimpan NAMA user (bukan ID)
        // sejak migrasi identitas permanen, jadi yang dikirim adalah nama.
        $userName = gr_currentUserName($conn);

        // ── NAMA PURCHASE STAFF UNTUK INVOICE ─────────────────────────────
        // Sebelumnya kolom ini selalu diisi $userName, yaitu nama orang yang
        // MENGKLIK tombol Create Invoice — sering bukan purchase staff yang
        // sebenarnya. Urutan sumber sekarang:
        //   1. snapshot goods_receipts.purchase_staff_name (permanen)
        //   2. NAMA pada kontak Shipping Address PO — cadangan untuk GR lama
        //      yang dibuat sebelum migrasi, sehingga dokumen lama pun ikut
        //      benar tanpa perlu perbaikan data
        //   3. $userName — perilaku lama, dipakai bila keduanya kosong
        $staffName = trim((string)($gr['purchase_staff_name'] ?? ''));
        if ($staffName === '' && $poId > 0) {
            $stmtC = $conn->prepare("SELECT company_contact FROM purchase_orders WHERE id = ? LIMIT 1");
            if ($stmtC) {
                $stmtC->bind_param('i', $poId);
                $stmtC->execute();
                $rowC = $stmtC->get_result()->fetch_assoc();
                $stmtC->close();
                if ($rowC) $staffName = gr_contactPersonName($rowC['company_contact'] ?? '');
            }
        }
        if ($staffName === '') $staffName = $userName;

        // Payment Information & dokumen DISALIN dari Goods Receipt supaya
        // datanya sinkron dan user tidak perlu mengisi ulang (anti-double).
        // payment_by & payer_name sudah berupa NAMA di kedua tabel, jadi
        // penyalinannya 1:1 tanpa konversi. File dokumen TIDAK diunggah ulang —
        // nama file yang sama dipakai kedua dokumen.
        $payMethod = null; $payBy = null; $payerNm = null; $payShift = null;
        if (gr_hasPaymentColumns($conn)) {
            $payMethod = ($gr['payment_method'] !== null && $gr['payment_method'] !== '') ? (string)$gr['payment_method'] : null;
            $payBy     = (string)($gr['payment_by'] ?? '');
            $payerNm   = (string)($gr['payer_name'] ?? '');
            $payShift  = !empty($gr['payer_shift']) ? (int)$gr['payer_shift'] : null;
        }
        $doc = !empty($gr['document']) ? (string)$gr['document'] : null;

        if ($payMethod !== null) {
            $stmt = $conn->prepare(
                "INSERT INTO purchases
                    (input_date, invoice_date, purchase_staff_id, supplier_id, po_id, gr_id,
                     notes, overall_discount, overall_price_rounding, tax, created_by,
                     payment_method, payment_by, payer_name, payer_shift, document)
                 VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?)"
            );
            if (!$stmt) throw new Exception('db_error');
            $stmt->bind_param(
                'sssiii' . 'sddds' . 'sssis',
                $inputDate, $invoiceDate, $staffName, $supplierId, $poId, $grId,
                $notes, $overallDisc, $overallRnd, $tax, $userName,
                $payMethod, $payBy, $payerNm, $payShift, $doc
            );
        } else {
        $stmt = $conn->prepare(
            "INSERT INTO purchases
                (input_date, invoice_date, purchase_staff_id, supplier_id, po_id, gr_id,
                 notes, overall_discount, overall_price_rounding, tax, created_by)
             VALUES (?,?,?,?,?,?, ?,?,?,?,?)"
        );
        if (!$stmt) throw new Exception('db_error');
        $stmt->bind_param(
            'sssiii' . 'sddds',
            $inputDate, $invoiceDate, $staffName, $supplierId, $poId, $grId,
            $notes, $overallDisc, $overallRnd, $tax, $userName
        );
        }
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('db_error'); }
        $purchaseId = (int)$stmt->insert_id;
        $stmt->close();

        $si = $conn->prepare(
            "INSERT INTO purchase_items
                (purchase_id, item_name, quantity, unit_id, unit_price, discount,
                 price_rounding, category_id, total_price)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        if (!$si) throw new Exception('db_error');

        foreach ($billable as $it) {
            $qty   = (float)$it['accepted_quantity'];
            $price = (float)$it['unit_price'];
            $disc  = (float)$it['discount'];
            $rnd   = (float)$it['price_rounding'];
            $total = ($qty * $price) - $disc + $rnd;
            $unitId = $it['unit_id'] !== null ? (int)$it['unit_id'] : null;
            $catId  = $it['category_id'] !== null ? (int)$it['category_id'] : null;
            $name   = (string)$it['item_name'];

            $si->bind_param(
                'isdi' . 'ddd' . 'id',
                $purchaseId, $name, $qty, $unitId,
                $price, $disc, $rnd,
                $catId, $total
            );
            if (!$si->execute()) { $si->close(); throw new Exception('db_error'); }
        }
        $si->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message_key' => 'db_error'];
    }

    gr_csrfRegenerate();
    if (function_exists('logActivity')) {
        // Tanggal Input Date dicatat lengkap beserta ASAL-USULNYA. Tanpa
        // penanda "chosen by user", tanggal hasil pilihan manual dan tanggal
        // hasil perhitungan otomatis akan terlihat sama persis di log dan
        // selisih tanggal tidak akan pernah bisa ditelusuri saat audit.
        logActivity('gr_invoice',
            "Purchase Invoice #{$purchaseId} generated from Goods Receipt {$gr['gr_number']} | " .
            gr_logLine([
                'GR'           => $gr['gr_number'],
                'PO'           => $gr['po_number'],
                'Supplier'     => $gr['supplier_name'],
                'Input Date'   => $inputDate . ($chosenInputDate !== '' ? ' (chosen by user)' : ' (auto)'),
                'Invoice Date' => $invoiceDate,
                'Items'        => count($billable),
                'Total'        => (float)$gr['total_amount'],
                'Staff'        => $staffName,
                'Created by'   => $userName,
            ])
        );
    }
    return ['success' => true, 'message_key' => 'gr_invoice_created', 'purchase_id' => $purchaseId];
}

/* ============================================================
   PO vs GR COMPARISON (dipakai po_view.php & goods_receipt_view.php)
   ============================================================ */

/**
 * Ringkasan perbandingan PO vs seluruh Goods Receipt-nya.
 * Semua angka dihitung dari database, PO tidak diubah sama sekali.
 *
 * Tiap baris berisi:
 *   item_name, ordered, received, remaining, po_price, actual_price, price_variance
 * actual_price = rata-rata tertimbang harga aktual dari GR (0 bila belum ada).
 */
function gr_poComparison(mysqli $conn, int $poId): array {
    $out = [];
    if ($poId <= 0) return $out;

    $stmt = $conn->prepare(
        "SELECT pi.id, pi.item_name, pi.quantity, pi.unit_price,
                u.symbol AS unit_symbol, u.name AS unit_name
           FROM purchase_order_items pi
           LEFT JOIN units u ON u.id = pi.unit_id
          WHERE pi.po_id = ?
          ORDER BY pi.id ASC"
    );
    if (!$stmt) return $out;
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    $poItems = [];
    while ($r = $res->fetch_assoc()) $poItems[(int)$r['id']] = $r;
    $stmt->close();
    if (!$poItems) return $out;

    // Agregasi GR per baris PO: total accepted + total nilai (untuk harga rata-rata)
    $agg = [];
    if (gr_tablesExist($conn)) {
        $s2 = $conn->prepare(
            "SELECT gi.po_item_id,
                    SUM(gi.accepted_quantity)                      AS acc,
                    SUM(gi.accepted_quantity * gi.unit_price)      AS val
               FROM goods_receipt_items gi
               JOIN goods_receipts gr ON gr.id = gi.gr_id
              WHERE gr.po_id = ? AND gr.status = 'received' AND gi.po_item_id IS NOT NULL
              GROUP BY gi.po_item_id"
        );
        if ($s2) {
            $s2->bind_param('i', $poId);
            $s2->execute();
            $r2 = $s2->get_result();
            while ($x = $r2->fetch_assoc()) {
                $agg[(int)$x['po_item_id']] = ['acc' => (float)$x['acc'], 'val' => (float)$x['val']];
            }
            $s2->close();
        }
    }

    $closed = gr_closedPoItems($conn, $poId, 0);

    foreach ($poItems as $id => $it) {
        $acc      = (float)($agg[$id]['acc'] ?? 0);
        $val      = (float)($agg[$id]['val'] ?? 0);
        $poPrice  = (float)$it['unit_price'];
        $actual   = $acc > 0 ? ($val / $acc) : 0.0;
        $ordered  = (float)$it['quantity'];
        $rem      = $ordered - $acc;
        // Baris yang sisanya sudah dinyatakan tidak akan datang lagi tidak
        // boleh terus tampil sebagai "masih kurang sekian" — urusannya sudah
        // selesai, dan angka itulah yang jadi catatan kegagalan supplier.
        $isClosed = !empty($closed[$id]);

        $out[] = [
            'po_item_id'     => $id,
            'item_name'      => $it['item_name'],
            'unit'           => $it['unit_symbol'] ?: ($it['unit_name'] ?: ''),
            'ordered'        => $ordered,
            'received'       => $acc,
            'remaining'      => ($isClosed || $rem <= 0) ? 0.0 : $rem,
            'line_closed'    => $isClosed,
            'closed_short'   => ($isClosed && $rem > 0) ? $rem : 0.0,
            'variance_qty'   => $acc - $ordered,
            'po_price'       => $poPrice,
            'actual_price'   => $actual,
            'price_variance' => $acc > 0 ? ($actual - $poPrice) : 0.0,
            'has_receipt'    => $acc > 0,
        ];
    }
    return $out;
}

/* ── REPORT DATA SCOPE : record-level guard ───────────────────────────────
 * true bila user boleh membuka satu Goods Receipt tertentu.
 * Mencegah user ber-scope "Own Data Only" membuka dokumen milik orang lain
 * lewat manipulasi URL (?id=).
 */
function gr_scopeAllows(?array $gr): bool {
    if (!$gr) return false;
    if (!function_exists('canViewAllGoodsReceipt')) return true;
    return permScopeAllowsRecord(canViewAllGoodsReceipt(), $gr['created_by'] ?? 0);
}

} // end if (!function_exists('gr_csrfToken'))