<?php
/* ═══════════════════════════════════════════════════════════════════════════
 * includes/purchase_orders_function.php
 *
 * Helper functions for the Purchase Orders feature. Mirrors the conventions
 * used in includes/purchases_function.php (CSRF, prepared statements, return
 * shape: ['success' => bool, 'message_key' => str, 'data' => mixed]).
 *
 * (Updated) Everything Purchase-Order-related — data access, the "purchase_
 * orders" permission wrappers, and the shared PO "document" HTML renderer
 * used by po_view.php / includes/export/po_export_pdf.php /
 * includes/export/po_export_image.php — now lives in this single file so
 * there is one place to look, and no function is defined twice anywhere else.
 * ═══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('po_csrfToken')) {

/* ── Detect whether PO tables exist (graceful handling if migration not run) ── */
function po_tablesExist(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    // Temporarily suppress mysqli exceptions (some configs set MYSQLI_REPORT_STRICT)
    $report_mode = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW TABLES LIKE 'purchase_orders'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report_mode); // restore
    return $cached = $ok;
}

/* ── CSRF (session-scoped, same pattern as purchases) ───────────────────── */
/* RONDE PENYATUAN CSRF — fungsi di bawah kini hanya MENERUSKAN.

   Kunci sesi lama 'po_csrf_token' tidak dipakai lagi. Token, cara pembentukan,
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
function po_csrfToken(): string {
    return bltx_csrf_token();
}

function po_csrfVerify(string $token): bool {
    return bltx_csrf_valid($token);
}

/* ── Generate a new PO reference number ──────────────────────────────────
 * Format : #PREFIX + YYYYMMDD + NNN   e.g.  #BSB20260725001
 *   - PREFIX is the "po_prefix" setting (Settings → General), default "BSB"
 *   - YYYYMMDD is today's date (the date the PO is created)
 *   - NNN is a sequence that keeps counting up for the whole calendar month
 *     and goes back to 001 as soon as a new month starts
 * ────────────────────────────────────────────────────────────────────────── */
function po_generateNumber(mysqli $conn): string {
    $prefix    = (string) getSetting('po_prefix', 'BSB');
    $dateFull  = date('Ymd');
    $dateMonth = date('Ym');
    $next      = 1;

    if (po_tablesExist($conn)) {
        $like = '#' . $prefix . $dateMonth . '%';
        $stmt = $conn->prepare(
            "SELECT po_number FROM purchase_orders
              WHERE po_number LIKE ?
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

/* ── List POs with filters (for the hub) ────────────────────────────────── */
function po_getList(mysqli $conn, array $filters = []): array {
    if (!po_tablesExist($conn)) return [];   // graceful: migration not run yet
    $sql = "SELECT po.*,
                   s.name AS supplier_name,
                   (SELECT COUNT(*) FROM purchase_order_items pi WHERE pi.po_id = po.id) AS items_count
              FROM purchase_orders po
              LEFT JOIN suppliers s ON s.id = po.supplier_id
             WHERE 1=1";
    $params = [];
    $types  = '';

    // ── REPORT DATA SCOPE (Own Data Only) ─────────────────────────────────
    // Kalau permission cakupan data modul 'purchase_orders' diset ke
    // "Own Data Only", daftar hanya menampilkan PO yang dibuat sendiri.
    // Diambil dari includes/global_permission.php supaya aturannya identik
    // dengan halaman detail dan export. Bila diset "All Data" (atau belum
    // pernah diatur), tidak ada filter tambahan sama sekali sehingga
    // perilaku lama tidak berubah.
    if (function_exists('canViewAllPurchaseOrder')) {
        $__scopeOwner = permScopeOwnerId(canViewAllPurchaseOrder());
        if ($__scopeOwner !== null) {
            $sql .= ' AND po.created_by = ?';
            $params[] = $__scopeOwner;
            $types   .= 'i';
        }
    }

    if (!empty($filters['status'])) {
        $sql .= ' AND po.status = ?';
        $params[] = $filters['status'];
        $types   .= 's';
    }
    if (!empty($filters['supplier_id'])) {
        $sql .= ' AND po.supplier_id = ?';
        $params[] = (int)$filters['supplier_id'];
        $types   .= 'i';
    }
    if (!empty($filters['from_date'])) {
        $sql .= ' AND po.order_date >= ?';
        $params[] = $filters['from_date'];
        $types   .= 's';
    }
    if (!empty($filters['to_date'])) {
        $sql .= ' AND po.order_date <= ?';
        $params[] = $filters['to_date'];
        $types   .= 's';
    }
    $sql .= ' ORDER BY po.order_date DESC, po.id DESC';

    $rows = [];
    if ($params) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return $rows;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    return $rows;
}

/* ── Get a single PO with its items ───────────────────────────────────────
 * (Updated) Items now also carry unit_symbol (e.g. "Kg") plus, when the row
 * is linked to the catalog, the inventory item's photo/code — needed for the
 * "Gambar"/"Qty" columns of the PO document. Rows added before this update
 * (inventory_id NULL) are untouched and simply show no photo, exactly as
 * before. */
function po_getOne(mysqli $conn, int $id): ?array {
    if (!po_tablesExist($conn)) return null;
    // created_by_full_name kini dibaca dari kolom PERMANEN
    // purchase_orders.created_by_name, sehingga nama pembuat PO tetap terbaca
    // setelah akun users-nya dihapus.
    //
    // uc.phone masih diambil lewat LEFT JOIN karena nomor telepon memang
    // properti akun yang hidup (dipakai HANYA sebagai cadangan pengisian blok
    // "Contact" bila kolom snapshot company_contact masih kosong pada PO lama).
    // LEFT JOIN sehingga baris PO tetap terbaca utuh walaupun akunnya sudah
    // dihapus — nomornya saja yang kosong, dokumennya tidak pernah hilang.
    $stmt = $conn->prepare(
        "SELECT po.*, s.name AS supplier_name,
                po.created_by_name AS created_by_full_name,
                uc.phone           AS created_by_phone
           FROM purchase_orders po
           LEFT JOIN suppliers s ON s.id = po.supplier_id
           LEFT JOIN users     uc ON uc.id = po.created_by
          WHERE po.id = ?"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $po  = $res->fetch_assoc();
    $stmt->close();
    if (!$po) return null;

    // Items
    $stmt2 = $conn->prepare(
        "SELECT pi.*, u.name AS unit_name, u.symbol AS unit_symbol, c.name AS category_name,
                inv.item_image AS inventory_image, inv.product_code AS inventory_code
           FROM purchase_order_items pi
           LEFT JOIN units u       ON u.id = pi.unit_id
           LEFT JOIN categories c  ON c.id = pi.category_id
           LEFT JOIN inventory inv ON inv.id = pi.inventory_id
          WHERE pi.po_id = ?
          ORDER BY pi.id ASC"
    );
    $items = [];
    if ($stmt2) {
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $r = $stmt2->get_result();
        while ($it = $r->fetch_assoc()) $items[] = $it;
        $stmt2->close();
    }
    $po['items'] = $items;
    return $po;
}

/* ── Delete a draft PO (transactional, items + header) ──────────────────── */
function po_delete(mysqli $conn, int $id, string $csrf): array {
    if (!po_tablesExist($conn)) {
        return ['success' => false, 'message_key' => 'po_tables_missing'];
    }
    if (!po_csrfVerify($csrf)) {
        return ['success' => false, 'message_key' => 'invalid_csrf'];
    }
    if (!_dbCan('purchase_orders', 'can_delete')) {
        return ['success' => false, 'message_key' => 'no_permission'];
    }

    // Only allow delete on draft
    $stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = ?");
    if (!$stmt) return ['success' => false, 'message_key' => 'db_error'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message_key' => 'not_found'];
    if ($row['status'] !== 'draft' && !isSuperAdmin()) {
        return ['success' => false, 'message_key' => 'po_only_draft_can_delete'];
    }

    $conn->begin_transaction();
    try {
        $s1 = $conn->prepare("DELETE FROM purchase_order_items WHERE po_id = ?");
        $s1->bind_param('i', $id); $s1->execute(); $s1->close();
        $s2 = $conn->prepare("DELETE FROM purchase_orders WHERE id = ?");
        $s2->bind_param('i', $id); $s2->execute(); $s2->close();
        $conn->commit();
        if (function_exists('logActivity')) logActivity('po_delete', 'Deleted purchase order #' . $id);
        return ['success' => true];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message_key' => 'db_error'];
    }
}

/* ── Aggregate items_total for a PO line array ─────────────────────────── */
function po_calculateItemsTotal(array $items): float {
    $total = 0.0;
    foreach ($items as $it) {
        $total += (float)($it['total_price'] ?? 0);
    }
    return $total;
}

/* ═══════════════════════════════════════════════════════════════════════
 * Permission wrappers for the "purchase_orders" module
 *
 * The module itself, and its can_create/can_read/can_update/can_delete/
 * can_access/can_export flags, already exist in the generic permission
 * system (includes/config.php + Settings → Permissions). These wrappers
 * just give "purchase_orders" the same named-function convenience already
 * used for "purchases" (requirePurchaseCreate(), canUpdatePurchase(), ...
 * in includes/global_permission.php), so every PO page reads the same way
 * without redeclaring the check logic in each file. They intentionally
 * redirect to the PO hub on failure — the exact behaviour po_add.php /
 * po_edit.php already had — rather than pulling in global_permission.php's
 * redirectNoAccess(), which sends the user somewhere else entirely.
 * ═══════════════════════════════════════════════════════════════════════ */
function requirePurchaseOrderCreate(): void {
    if (!isLoggedIn()) { redirect(url('index.php')); }
    if (!_dbCan('purchase_orders', 'can_create')) { redirect(url('purchases/pr_dahsboard_gate.php?tab=order')); }
}
function requirePurchaseOrderUpdate(): void {
    if (!isLoggedIn()) { redirect(url('index.php')); }
    if (!_dbCan('purchase_orders', 'can_update')) { redirect(url('purchases/pr_dahsboard_gate.php?tab=order')); }
}
function canAccessPurchaseOrder(): bool { return _dbCan('purchase_orders', 'can_access'); }
function canReadPurchaseOrder(): bool   { return _dbCan('purchase_orders', 'can_read'); }
function canCreatePurchaseOrder(): bool { return _dbCan('purchase_orders', 'can_create'); }
function canUpdatePurchaseOrder(): bool { return _dbCan('purchase_orders', 'can_update'); }
function canDeletePurchaseOrder(): bool { return _dbCan('purchase_orders', 'can_delete'); }
function canExportPurchaseOrder(): bool { return _dbCan('purchase_orders', 'can_export'); }

/* ═══════════════════════════════════════════════════════════════════════
 * Data needed for the PO "document" (Shipping address / Supplier / items
 * with photo & unit / totals), shared by po_view.php and both export files.
 * ═══════════════════════════════════════════════════════════════════════ */

/* "Shipping address" block = the business's own info, from Settings →
 * General (see settings/general.php, "Purchase Order Settings" card). */
/**
 * Deteksi apakah kolom snapshot purchase_orders.company_contact sudah ada
 * (SQL sql/purchase_settings.sql sudah dijalankan). Dipakai oleh po_add.php
 * agar halaman tetap berfungsi walaupun migrasi belum dijalankan.
 */
function po_hasCompanyContactColumn(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $report = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW COLUMNS FROM `purchase_orders` LIKE 'company_contact'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report);
    return $cached = $ok;
}

/**
 * Rangkai satu baris "Contact" dari nama + nomor telepon.
 * Format mengikuti data yang sudah ada di sistem: "Nama - Telepon".
 * Bila salah satunya kosong, hanya bagian yang terisi yang dipakai.
 *
 * Hasilnya dipotong maksimal 100 karakter karena kolom penyimpanannya,
 * purchase_orders.company_contact, bertipe VARCHAR(100) — pemotongan
 * dilakukan di PHP (multibyte-safe) supaya nama berkarakter non-latin
 * tidak pernah terpotong di tengah byte dan tidak memicu error MySQL
 * strict mode.
 */
function po_composeContact($fullName, $phone): string {
    $name = trim((string)$fullName);
    $tel  = trim((string)$phone);

    if ($name !== '' && $tel !== '') $out = $name . ' - ' . $tel;
    elseif ($name !== '')            $out = $name;
    else                             $out = $tel;

    if ($out === '') return '';
    return function_exists('mb_substr')
        ? mb_substr($out, 0, 100, 'UTF-8')
        : substr($out, 0, 100);
}

/**
 * Contact untuk blok "Shipping address" pada dokumen Purchase Order:
 * data USER YANG SEDANG LOGIN, dibaca langsung dari tabel `users`
 * (kolom full_name dan kolom phone).
 *
 * Nilai ini di-SNAPSHOT sebagai TEKS ke purchase_orders.company_contact saat
 * PO disimpan (po_add.php), sehingga:
 *   - dokumen PO lama tidak ikut berubah kalau user mengganti nama/telepon,
 *   - datanya TIDAK ikut terhapus ketika akun user terkait dihapus.
 * Session tidak dipakai sebagai sumber karena session hanya menyimpan
 * full_name dan tidak pernah menyimpan phone.
 */
function po_currentUserContact(mysqli $conn): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return $cached = '';

    $stmt = $conn->prepare("SELECT full_name, phone FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return $cached = '';
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $cached = '';

    return $cached = po_composeContact($row['full_name'] ?? '', $row['phone'] ?? '');
}

function po_getShippingInfo(?array $po = null): array {
    // Contact di-SNAPSHOT ke baris purchase_orders saat PO dibuat, berisi
    // users.full_name + users.phone milik user yang membuat PO. Karena yang
    // tersimpan adalah TEKS, dokumen PO tetap utuh walaupun akun user-nya
    // dihapus. Urutan sumber:
    //   1. snapshot purchase_orders.company_contact  → permanen, prioritas utama
    //   2. data live pembuat PO (users.full_name + users.phone) → hanya untuk
    //      PO lama yang snapshot-nya masih kosong
    //   3. pengaturan po_company_contact → cadangan terakhir (perilaku lama)
    $contact = '';
    if ($po !== null) {
        if (isset($po['company_contact']) && trim((string)$po['company_contact']) !== '') {
            $contact = (string)$po['company_contact'];
        } else {
            $contact = po_composeContact(
                $po['created_by_full_name'] ?? '',
                $po['created_by_phone'] ?? ''
            );
        }
    }
    if ($contact === '') {
        $contact = (string) getSetting('po_company_contact', '');
    }

    return [
        'prefix'  => getSetting('po_prefix', 'BSB'),
        'store'   => getSetting('po_company_name', ''),
        'address' => getSetting('po_company_address', ''),
        'gmaps'   => getSetting('po_company_gmaps', ''),
        'contact' => $contact,
        'email'   => getSetting('po_company_email', ''),
    ];
}

/* "Supplier" block = the chosen row from the suppliers table. */
function po_getSupplierDetail(mysqli $conn, ?int $supplierId): ?array {
    if (empty($supplierId)) return null;
    $stmt = $conn->prepare(
        "SELECT id, name, address, contact_person, phone, email
           FROM suppliers WHERE id = ?"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $supplierId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* Full inventory catalog for the item picker in po_add.php / po_edit.php.
 * Small enough (dozens–low hundreds of rows) to embed directly, same
 * approach already used for the item picker in inventory_in_add.php. */
function po_getInventoryForPicker(mysqli $conn): array {
    $sql = "SELECT i.id, i.product_code, i.item_name, i.unit_price, i.item_image,
                   i.unit_id, i.category_id, u.symbol AS unit_symbol
              FROM inventory i
              LEFT JOIN units u ON u.id = i.unit_id
             ORDER BY i.item_name ASC";
    $rows = [];
    $res = $conn->query($sql);
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
    return $rows;
}

/* Bundle everything the document renderer needs for one PO. */
function po_getDocumentBundle(mysqli $conn, int $id): ?array {
    $po = po_getOne($conn, $id);
    if (!$po) return null;
    return [
        'po'       => $po,
        'shipping' => po_getShippingInfo($po),
        'supplier' => po_getSupplierDetail($conn, $po['supplier_id'] ?? null),
    ];
}

/* ── Small formatting helpers used by the document renderer ─────────────── */

// Strip trailing decimal zeros (1.00 → 1, 1.50 → 1.5) — same rule already
// used by includes/export/export_purchases.php's formatQty().
function po_formatQty($qty): string {
    $str = number_format((float)$qty, 2, '.', '');
    $str = rtrim($str, '0');
    $str = rtrim(rtrim($str, '.'), '.');
    return $str === '' ? '0' : $str;
}

// "Sabtu, 25 Juli 2026" / "Saturday, July 25, 2026" / "2026年7月25日 星期六" —
// built from a fixed name table instead of setlocale(), which shared hosting
// often does not have the matching system locale installed for.
function po_formatDateLong(?string $dateStr, string $langCode = 'en'): string {
    if (empty($dateStr)) return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return htmlspecialchars($dateStr);

    $d = (int) date('j', $ts);
    $m = (int) date('n', $ts);
    $y = (int) date('Y', $ts);
    $w = (int) date('w', $ts);

    if ($langCode === 'ch') {
        $daysCh = ['星期日','星期一','星期二','星期三','星期四','星期五','星期六'];
        return $y . '年' . $m . '月' . $d . '日 ' . $daysCh[$w];
    }
    if ($langCode === 'id') {
        $daysId   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        $monthsId = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        return $daysId[$w] . ', ' . $d . ' ' . $monthsId[$m] . ' ' . $y;
    }
    $daysEn   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $monthsEn = ['', 'January','February','March','April','May','June','July','August','September','October','November','December'];
    return $daysEn[$w] . ', ' . $monthsEn[$m] . ' ' . $d . ', ' . $y;
}

// Read a local image file and return it as a base64 data: URI. Used only for
// the PDF export — dompdf runs with isRemoteEnabled=false (same as
// includes/export/export_purchases.php), so <img src="http://..."> tags are
// never fetched; embedding the bytes directly is what makes photos show up
// in the PDF at all.
function po_imageDataUri(string $absPath): ?string {
    if (!is_file($absPath) || !is_readable($absPath)) return null;
    $data = @file_get_contents($absPath);
    if ($data === false || $data === '') return null;
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'png':  $mime = 'image/png';  break;
        case 'gif':  $mime = 'image/gif';  break;
        case 'webp': $mime = 'image/webp'; break;
        default:     $mime = 'image/jpeg'; break;
    }
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

// Currency symbol/rate cache — one settings lookup per request, not per row.
function _po_curCache(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $currency = getSetting('currency', 'IDR');
    switch ($currency) {
        case 'USD': $sym = '$';  break;
        case 'CNY': $sym = '¥'; break;
        default:    $sym = 'Rp'; break;
    }
    $cache = ['currency' => $currency, 'sym' => $sym];
    return $cache;
}

function po_formatCurrencyValue(float $amount): string {
    $c = _po_curCache();
    switch ($c['currency']) {
        case 'USD': return number_format($amount / (float) getSetting('usd_rate', 15000), 2, '.', ',');
        case 'CNY': return number_format($amount / (float) getSetting('cny_rate', 2100), 2, '.', ',');
        default:    return number_format($amount, 0, ',', '.');
    }
}

// "Rp" left / value right in one bordered cell, e.g. the "Harga"/"Total"
// columns of the example PO — same visual trick as export_purchases.php's
// curCell(), reused here so the document looks the same as every other
// exported document in the app.
function po_currencyCellHtml(float $amount): string {
    $sym = _po_curCache()['sym'];
    $val = po_formatCurrencyValue($amount);
    return '<table class="po-doc-cur"><tr>'
         . '<td class="sym">' . htmlspecialchars($sym) . '</td>'
         . '<td class="val">' . htmlspecialchars($val) . '</td>'
         . '</tr></table>';
}

/* ═══════════════════════════════════════════════════════════════════════
 * Shared PO "document" renderer
 *
 * Produces the exact layout from the attached example (title+reference block,
 * Shipping address / Supplier side by side, item table with photo/qty/price/
 * total, totals block, notes) as a single self-contained HTML fragment. Pure
 * tables + plain CSS only (no flexbox/grid) so the SAME markup renders
 * correctly in a normal browser, in dompdf (PDF export), and when captured by
 * html2canvas (JPEG export) — one template, three consumers, per the "no
 * duplicate function" / easy-to-maintain requirement.
 *
 * $mode: 'web' (default, <img> via UPLOAD_URL) | 'pdf' (<img> via base64 data
 * URI, since dompdf cannot fetch remote/http images with isRemoteEnabled off).
 * ═══════════════════════════════════════════════════════════════════════ */
/* CATATAN PERATAAN BLOK TOTAL (aturan .po-doc-totals-* di bawah)
 * Kolom kosong .pad menempati lebar No + Gambar + Deskripsi + Qty
 * (4% + 9% + 33% + 9% = 55%), sehingga tulisan Total / Discount / VAT /
 * Rounding / Grand Total, garis horizontal di atas Grand Total, dan
 * nilainya semua mulai TEPAT di garis kolom Price pada tabel item.
 * Persentasenya sengaja memakai skala yang sama dengan .po-doc-items
 * (jumlah 99%) supaya penyesuaian lebar oleh browser dan dompdf identik
 * di kedua tabel.
 * Aturan .grand .pad { border-top:0 } membuat garis Grand Total berhenti
 * di kolom Price dan tidak menyeberang ke area Deskripsi/Qty.
 *
 * PENTING: isi return di bawah adalah STRING BERKUTIP GANDA. Jangan pernah
 * menulis tanda kutip ganda, $, atau \ di dalamnya — string akan terputus
 * dan menghasilkan parse error. Semua penjelasan ditulis di sini, di luar
 * string, bukan sebagai komentar CSS di dalamnya.
 */
/* ── Label dwibahasa untuk dokumen PO ────────────────────────────────────
 * Mengembalikan "Label 中文" (contoh: "No 序号", "Notes 备注").
 *
 * Nilai Mandarin diambil dari kunci "<key>_cn" pada file bahasa yang sedang
 * aktif, JADI TIDAK ADA satu pun string Mandarin yang ditulis langsung
 * (hardcode) di dalam kode ini — semuanya tetap lewat file multi-language.
 *
 * Bila bahasa aktif sudah Mandarin, $LANG[$key] dan $LANG[$key.'_cn']
 * bernilai sama persis; fungsi mendeteksi hal itu dan hanya mengeluarkan
 * SATU teks, sehingga tidak pernah tampil dobel ("序号 序号").
 *
 * Hasilnya berupa HTML yang sudah di-escape, jadi dipakai langsung dengan
 * <?= po_docLabel('...') ?> TANPA htmlspecialchars() lagi.
 * ──────────────────────────────────────────────────────────────────────── */
function po_docLabel(string $key): string {
    global $LANG;
    $base = (string) ($LANG[$key] ?? '');
    $cn   = (string) ($LANG[$key . '_cn'] ?? '');
    if ($cn === '' || $cn === $base) {
        return htmlspecialchars($base);
    }
    return htmlspecialchars($base) . ' <span class="po-doc-cn">' . htmlspecialchars($cn) . '</span>';
}

function po_documentCss(): string {
    return "
.po-doc { font-family: 'Noto Sans SC', Arial, sans-serif; color:#212529; font-size:12px; }
.po-doc table { border-collapse: collapse; width:100%; }
.po-doc td, .po-doc th { padding:4px 6px; vertical-align: top; }

.po-doc-head { border:1px solid #333; margin-bottom:10px; }
.po-doc-head td { border:0; }
.po-doc-title-cell { font-size:19px; font-weight:bold; text-align:center; vertical-align:middle; width:52%; border-right:1px solid #333; }
.po-doc .po-doc-title-cell { vertical-align:middle; }
.po-doc .po-doc-title-en { font-size:19px; font-weight:bold; line-height:1.15; }
.po-doc .po-doc-title-cn { font-size:19px; font-weight:bold; line-height:1.25; letter-spacing:1.2em; text-indent:1.2em; }
.po-doc .po-doc-meta-cell { padding:0 6px; }
.po-doc .po-doc-meta-first { padding-top:4px; }
.po-doc .po-doc-meta-last { padding-bottom:4px; }
.po-doc-meta-table td { padding:1px 4px; font-size:11px; }
.po-doc-meta-label { width:40%; white-space:nowrap; color:#333; }
.po-doc-meta-sep { width:4%; }
.po-doc-meta-val { font-weight:bold; }
.po-doc .po-doc-cn { font-style:normal; }

.po-doc-parties { border:1px solid #333; margin-bottom:10px; }
.po-doc-parties td, .po-doc-parties th { border:1px solid #333; }
.po-doc-parties-h { text-align:center; font-style:italic; font-weight:bold; background:#f2f2f2; }
.po-doc-parties-body { width:50%; vertical-align:top; }
.po-doc-kv td { padding:1px 4px; font-size:11px; border:0; }
.po-doc-kv .k { width:34%; white-space:nowrap; color:#333; }
.po-doc-kv .s { width:4%; }
.po-doc-kv .v { font-weight:bold; word-break:break-word; }
.po-doc-kv .v a { color:#0d6efd; }
.po-doc-kv .v-strong { font-weight:bold; }

.po-doc-items { border:1px solid #333; margin-bottom:0; }
.po-doc-items th { background:#f2f2f2; border:1px solid #333; text-align:center; font-weight:bold; font-style:italic; font-size:11px; }
.po-doc-items td { border:1px solid #333; font-size:11px; vertical-align:middle; }
.po-doc .po-doc-items .c-img img { display:block; margin:0 auto; }
.po-doc-items .c-no { width:4%; text-align:center; }
.po-doc-items .c-img { width:9%; text-align:center; }
.po-doc-items .c-desc { width:33%; }
.po-doc-items .c-qty { width:9%; text-align:center; white-space:nowrap; }
.po-doc-items .c-price { width:22%; }
.po-doc-items .c-total { width:22%; }

.po-doc-cur td { border:0 !important; padding:0 !important; font-size:11px; }
.po-doc-cur .sym { width:26%; text-align:left; }
.po-doc-cur .val { text-align:right; }

.po-doc-totals-wrap { width:100%; margin-top:8px; }
.po-doc-totals td { border:0; padding:2px 6px; font-size:11px; }
.po-doc-totals .pad { width:55%; padding:0; }
.po-doc-totals .lbl { width:19%; color:#333; }
.po-doc-totals .sep { width:3%; }
.po-doc-totals .val { width:22%; text-align:right; }
.po-doc-totals .grand td { border-top:1px solid #333; font-weight:bold; font-size:13px; padding-top:6px; }
.po-doc-totals .grand .pad { border-top:0; }

.po-doc-notes { margin-top:16px; border:1px solid #333; }
.po-doc-notes td { border:0; padding:8px; font-size:11px; }
.po-doc-notes .lbl { font-weight:bold; display:block; margin-bottom:4px; }
";
}

function po_renderDocumentBody(array $bundle, string $mode = 'web'): string {
    global $LANG;
    $po       = $bundle['po'];
    $shipping = $bundle['shipping'];
    $supplier = $bundle['supplier'];
    $langCode = getSetting('language', 'en');
    $items    = $po['items'] ?? [];

    ob_start();
    ?>
<div class="po-doc">

    <table class="po-doc-head">
        <tr>
            <td class="po-doc-title-cell" rowspan="3"><?php
                $__poTitleEn = (string) ($LANG['po_doc_title'] ?? '');
                $__poTitleCn = (string) ($LANG['po_doc_title_cn'] ?? '');
            ?><div class="po-doc-title-en"><?= htmlspecialchars($__poTitleEn) ?></div><?php
                if ($__poTitleCn !== '' && $__poTitleCn !== $__poTitleEn):
            ?><div class="po-doc-title-cn"><?= htmlspecialchars($__poTitleCn) ?></div><?php
                endif;
            ?></td>
            <td class="po-doc-meta-cell po-doc-meta-first">
                <table class="po-doc-meta-table"><tr>
                    <td class="po-doc-meta-label"><?= po_docLabel('po_doc_reference') ?></td>
                    <td class="po-doc-meta-sep">:</td>
                    <td class="po-doc-meta-val"><?= htmlspecialchars($po['po_number']) ?></td>
                </tr></table>
            </td>
        </tr>
        <tr>
            <td class="po-doc-meta-cell">
                <table class="po-doc-meta-table"><tr>
                    <td class="po-doc-meta-label"><?= po_docLabel('po_doc_order_date') ?></td>
                    <td class="po-doc-meta-sep">:</td>
                    <td class="po-doc-meta-val"><?= htmlspecialchars(po_formatDateLong($po['order_date'] ?? null, $langCode)) ?></td>
                </tr></table>
            </td>
        </tr>
        <tr>
            <td class="po-doc-meta-cell po-doc-meta-last">
                <table class="po-doc-meta-table"><tr>
                    <td class="po-doc-meta-label"><?= po_docLabel('po_doc_delivery_date') ?></td>
                    <td class="po-doc-meta-sep">:</td>
                    <td class="po-doc-meta-val"><?= htmlspecialchars(po_formatDateLong($po['expected_date'] ?? null, $langCode)) ?></td>
                </tr></table>
            </td>
        </tr>
    </table>

    <table class="po-doc-parties">
        <tr>
            <th class="po-doc-parties-h"><?= po_docLabel('po_doc_shipping_address') ?></th>
            <th class="po-doc-parties-h"><?= po_docLabel('po_doc_supplier') ?></th>
        </tr>
        <tr>
            <td class="po-doc-parties-body">
                <table class="po-doc-kv">
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_store']) ?></td><td class="s">:</td><td class="v v-strong"><?= htmlspecialchars($shipping['store'] ?: '-') ?></td></tr>
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_address']) ?></td><td class="s">:</td><td class="v"><?= htmlspecialchars($shipping['address'] ?: '-') ?></td></tr>
                    <?php if (!empty($shipping['gmaps'])): ?>
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_gmaps']) ?></td><td class="s">:</td><td class="v"><a href="<?= htmlspecialchars($shipping['gmaps'], ENT_QUOTES) ?>"><?= htmlspecialchars($shipping['gmaps']) ?></a></td></tr>
                    <?php endif; ?>
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_contact']) ?></td><td class="s">:</td><td class="v"><?= htmlspecialchars($shipping['contact'] ?: '-') ?></td></tr>
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_email']) ?></td><td class="s">:</td><td class="v"><?= htmlspecialchars($shipping['email'] ?: '-') ?></td></tr>
                </table>
            </td>
            <td class="po-doc-parties-body">
                <table class="po-doc-kv">
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_company']) ?></td><td class="s">:</td><td class="v v-strong"><?= htmlspecialchars($supplier['name'] ?? ($po['supplier_name'] ?? '-')) ?></td></tr>
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_address']) ?></td><td class="s">:</td><td class="v"><?= htmlspecialchars($supplier['address'] ?? '-') ?></td></tr>
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_contact']) ?></td><td class="s">:</td>
                        <td class="v"><?php
                            $supContact = trim(($supplier['contact_person'] ?? '') . (!empty($supplier['phone']) ? ' - ' . $supplier['phone'] : ''));
                            echo htmlspecialchars($supContact !== '' ? $supContact : '-');
                        ?></td>
                    </tr>
                    <tr><td class="k"><?= htmlspecialchars($LANG['po_doc_email']) ?></td><td class="s">:</td><td class="v"><?= htmlspecialchars($supplier['email'] ?? '-') ?></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="po-doc-items">
        <thead>
            <tr>
                <th class="c-no"><?= po_docLabel('po_doc_col_no') ?></th>
                <th class="c-img"><?= po_docLabel('po_doc_col_image') ?></th>
                <th class="c-desc"><?= po_docLabel('po_doc_col_desc') ?></th>
                <th class="c-qty"><?= po_docLabel('po_doc_col_qty') ?></th>
                <th class="c-price"><?= po_docLabel('po_doc_col_price') ?></th>
                <th class="c-total"><?= po_docLabel('po_doc_col_total') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($items as $it): $n++; ?>
            <tr>
                <td class="c-no"><?= $n ?></td>
                <td class="c-img"><?php
                    $imgPath = $it['inventory_image'] ?? null;
                    $imgHtml = '';
                    if (!empty($imgPath)) {
                        if ($mode === 'pdf') {
                            $dataUri = po_imageDataUri(UPLOAD_PATH . ltrim($imgPath, '/'));
                            if ($dataUri) $imgHtml = '<img src="' . $dataUri . '" width="40" height="40" alt="">';
                        } else {
                            $src = UPLOAD_URL . ltrim($imgPath, '/');
                            $imgHtml = '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" width="40" height="40" alt="">';
                        }
                    }
                    echo $imgHtml !== '' ? $imgHtml : '';
                ?></td>
                <td class="c-desc"><?= htmlspecialchars($it['item_name']) ?></td>
                <td class="c-qty"><?= htmlspecialchars(po_formatQty($it['quantity']) . ' ' . ($it['unit_symbol'] ?: $it['unit_name'] ?: '')) ?></td>
                <td class="c-price"><?= po_currencyCellHtml((float)$it['unit_price']) ?></td>
                <td class="c-total"><?= po_currencyCellHtml((float)$it['total_price']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="po-doc-totals-wrap">
        <table class="po-doc-totals">
            <tr><td class="pad"></td><td class="lbl"><?= htmlspecialchars($LANG['po_doc_total_label']) ?></td><td class="sep">:</td><td class="val"><?= po_currencyCellHtml(po_calculateItemsTotal($items)) ?></td></tr>
            <tr><td class="pad"></td><td class="lbl"><?= htmlspecialchars($LANG['discount']) ?></td><td class="sep">:</td><td class="val"><?= number_format((float)$po['overall_discount'], 0, ',', '.') ?></td></tr>
            <tr><td class="pad"></td><td class="lbl"><?= htmlspecialchars($LANG['po_doc_ppn_label']) ?></td><td class="sep">:</td><td class="val"><?= number_format((float)$po['tax'], 0, ',', '.') ?></td></tr>
            <tr><td class="pad"></td><td class="lbl"><?= htmlspecialchars($LANG['po_doc_rounding_label']) ?></td><td class="sep">:</td><td class="val"><?= number_format((float)$po['overall_price_rounding'], 0, ',', '.') ?></td></tr>
            <tr class="grand"><td class="pad"></td><td class="lbl"><?= htmlspecialchars($LANG['grand_total']) ?></td><td class="sep">:</td><td class="val"><?= po_currencyCellHtml((float)$po['total_amount']) ?></td></tr>
        </table>
    </div>

    <?php if (!empty($po['notes'])): ?>
    <table class="po-doc-notes">
        <tr><td>
            <span class="lbl"><?= po_docLabel('po_doc_notes_label') ?>:</span>
            <?= nl2br(htmlspecialchars($po['notes'])) ?>
        </td></tr>
    </table>
    <?php endif; ?>

</div>
    <?php
    return (string) ob_get_clean();
}

} // end if (!function_exists)

/* ── REPORT DATA SCOPE : record-level guard ───────────────────────────────
 * Mengembalikan true bila user boleh membuka satu PO tertentu.
 * Dipakai oleh po_view.php / po_edit.php agar user ber-scope "Own Data Only"
 * tidak bisa membuka PO milik orang lain lewat manipulasi URL (?id=).
 * Superadmin dan user ber-scope "All Data" selalu true.
 */
if (!function_exists('po_scopeAllows')) {
    function po_scopeAllows(?array $po): bool {
        if (!$po) return false;
        if (!function_exists('canViewAllPurchaseOrder')) return true;
        return permScopeAllowsRecord(canViewAllPurchaseOrder(), $po['created_by'] ?? 0);
    }
}