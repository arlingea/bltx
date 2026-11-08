<?php
/**
 * purchases_function.php
 * Shared helper functions for purchases.php, purchase_add.php, purchase_edit.php, and purchase_view.php
 * Location: public_html/bltx/includes/purchases_function.php
 *
 * DO NOT access this file directly — must be included from an authenticated page.
 */

if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ============================================================
//  STRING HELPERS
// ============================================================

/* ═══════════════════════════════════════════════════════════════════════
 * IDENTITAS USER PERMANEN PADA PURCHASE INVOICE
 *
 * Empat kolom EXISTING pada tabel `purchases` kini menyimpan NAMA user,
 * bukan lagi ID user:
 *     purchase_staff_id, created_by, payment_by, payer_name
 *
 * Tidak ada kolom baru. purchase_staff_id dan created_by diubah tipenya
 * menjadi VARCHAR(100) lewat sql/user_name_permanent_purchases.sql;
 * payment_by dan payer_name memang sudah VARCHAR(100) sejak awal.
 *
 * Akibatnya nama tersimpan permanen di dalam baris invoice, sehingga tetap
 * tampil walaupun akun user dihapus, dinonaktifkan, atau berganti nama.
 *
 * Form tetap mengirim ID user seperti biasa. Konversi ID -> nama dilakukan
 * di sisi server tepat sebelum disimpan, lewat pf_userNameById().
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Terjemahkan ID user menjadi nama lengkapnya, untuk disimpan permanen
 * ke baris transaksi.
 *
 * Mengembalikan string kosong bila ID tidak valid atau tidak ditemukan,
 * sehingga kolom NOT NULL tetap aman terisi.
 */
function pf_userNameById($conn, $userId) {
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

    $name = ($row && trim((string)$row['full_name']) !== '') ? trim((string)$row['full_name']) : '';
    return $memo[$userId] = $name;
}

/**
 * Highlight occurrences of $word inside $text (HTML-safe).
 * Returns the full escaped string with matched portions wrapped in <mark>.
 * If $word is empty, returns htmlspecialchars($text) unchanged.
 *
 * @param  string $text  Raw (unescaped) text to display.
 * @param  string $word  Search term to highlight (may be empty).
 * @return string  Safe HTML string.
 */
function pf_highlight($text, $word) {
    if ($word === '') return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return preg_replace(
        '/(' . preg_quote(htmlspecialchars($word, ENT_QUOTES, 'UTF-8'), '/') . ')/i',
        '<mark>$1</mark>',
        htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
    );
}

// ============================================================
//  FORMAT HELPERS
// ============================================================

/**
 * Format quantity — strips trailing zeros after decimal point.
 * Examples: 5.00 → "5",  1.50 → "1.5",  1.25 → "1.25"
 *
 * @param  float  $value
 * @return string
 */
function pf_formatQuantity($value) {
    $value = (float)$value;
    if (floor($value) == $value) {
        return number_format($value, 0, ',', '.');
    }
    $formatted = number_format($value, 2, ',', '.');
    $formatted = rtrim($formatted, '0');
    $formatted = rtrim($formatted, ',');
    return $formatted;
}

/**
 * Split a formatted currency string into [symbol, value].
 * Used for rendering the currency symbol left-aligned and the
 * numeric value right-aligned inside a single table cell.
 *
 * Formats produced by formatCurrency():
 *   "Rp 1.234.567"  →  ['Rp', '1.234.567']
 *   "$1,234.56"     →  ['$',  '1,234.56']
 *   "¥1,234.56"     →  ['¥',  '1,234.56']
 *
 * @param  float       $amount
 * @param  string|null $currency
 * @return array  [symbol_string, value_string]
 */
function pf_splitCurrency($amount, $currency = null) {
    $formatted = formatCurrency($amount, $currency);
    if (strncmp($formatted, 'Rp ', 3) === 0) {
        return ['Rp', substr($formatted, 3)];
    }
    if ($formatted !== '' && ($formatted[0] === '$' || $formatted[0] === '¥')) {
        return [$formatted[0], substr($formatted, 1)];
    }
    return ['', $formatted];
}

/**
 * Return the HTML fragment for a two-part currency cell:
 *   <span class="cur-sym">Rp</span><span class="cur-val">1.234.567</span>
 *
 * @param  float       $amount
 * @param  string|null $currency
 * @return string  Safe HTML
 */
function pf_currencyCell($amount, $currency = null) {
    [$sym, $val] = pf_splitCurrency($amount, $currency);
    return '<span class="cur-sym">' . htmlspecialchars($sym, ENT_QUOTES, 'UTF-8') . '</span>'
         . '<span class="cur-val">' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Get currency symbol string for JS usage.
 * Returns 'Rp', '$', or '¥' based on current currency setting.
 *
 * @return string
 */
function pf_getCurrencySymbol() {
    $currency = getSetting('currency', 'IDR');
    switch ($currency) {
        case 'USD': return '$';
        case 'CNY': return '¥';
        default:    return 'Rp';
    }
}

/**
 * Get currency code for JS usage (IDR, USD, CNY).
 *
 * @return string
 */
function pf_getCurrencyCode() {
    return getSetting('currency', 'IDR');
}

/**
 * Format a raw numeric amount for display in currency input fields (display value).
 * IDR: integer with thousand separator (.), USD/CNY: decimal with 2 places.
 *
 * @param  float  $amount
 * @return string
 */
function pf_formatInputDisplay($amount) {
    $currency = getSetting('currency', 'IDR');
    $amount   = (float)$amount;
    if ($currency === 'IDR') {
        return number_format($amount, 0, ',', '.');
    }
    return number_format($amount, 2, '.', ',');
}

// ============================================================
//  FORM DATA RETRIEVAL
// ============================================================

/**
 * Fetch active suppliers list for dropdown.
 *
 * @param  mysqli $conn
 * @return mysqli_result
 */
function pf_fetchSuppliers($conn) {
    return $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
}

/**
 * Fetch active categories list for dropdown.
 *
 * @param  mysqli $conn
 * @return mysqli_result
 */
function pf_fetchCategories($conn) {
    return $conn->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC");
}

/**
 * Fetch active units list for dropdown.
 *
 * @param  mysqli $conn
 * @return mysqli_result
 */
function pf_fetchUnits($conn) {
    return $conn->query("SELECT id, name, symbol FROM units WHERE is_active = 1 ORDER BY name ASC");
}

/**
 * Fetch active users list for staff/payer dropdowns.
 *
 * @param  mysqli $conn
 * @return mysqli_result
 */
function pf_fetchUsers($conn) {
    return $conn->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");
}

/**
 * Fetch active payment methods for dropdown.
 *
 * @param  mysqli $conn
 * @return mysqli_result
 */
function pf_fetchPaymentMethods($conn) {
    return $conn->query("SELECT id, name, code FROM payment_methods WHERE is_active = 1 ORDER BY name ASC");
}

/**
 * Fetch active employee shifts for dropdown.
 *
 * @param  mysqli $conn
 * @return mysqli_result
 */
function pf_fetchShifts($conn) {
    return $conn->query("SELECT id, name, code FROM employee_shifts WHERE is_active = 1 ORDER BY id ASC");
}

// ============================================================
//  DATA RETRIEVAL
// ============================================================

/**
 * Fetch header data for a single purchase.
 *
 * @param  mysqli $conn
 * @param  int    $id
 * @return array|null  Associative array or null if not found.
 */
function pf_getPurchaseData($conn, $id) {
    $id   = (int)$id;
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.input_date,
            p.invoice_date,
            p.overall_discount,
            p.overall_price_rounding,
            p.tax,
            p.purchase_staff_id,
            p.supplier_id,
            p.payment_method,
            p.payment_by,
            p.payer_name,
            p.payer_shift,
            COALESCE(pb.full_name, p.payment_by) AS payment_by_name,
            COALESCE(pn.full_name, p.payer_name)  AS payer_name_label,
            es.name        AS payer_shift_name,
            pm.name        AS payment_method_name,
            p.notes,
            p.document,
            s.name         AS supplier_name,
            COALESCE(u.full_name,  p.created_by)        AS created_by_name,
            COALESCE(ps.full_name, p.purchase_staff_id) AS purchase_staff_name
        FROM   purchases p
        LEFT JOIN suppliers       s  ON p.supplier_id       = s.id
        LEFT JOIN users           u  ON p.created_by        REGEXP '^[0-9]+$' AND u.id  = CAST(p.created_by        AS UNSIGNED)
        LEFT JOIN users           ps ON p.purchase_staff_id REGEXP '^[0-9]+$' AND ps.id = CAST(p.purchase_staff_id AS UNSIGNED)
        LEFT JOIN users           pb ON p.payment_by        REGEXP '^[0-9]+$' AND pb.id = CAST(p.payment_by        AS UNSIGNED)
        LEFT JOIN users           pn ON p.payer_name        REGEXP '^[0-9]+$' AND pn.id = CAST(p.payer_name        AS UNSIGNED)
        LEFT JOIN payment_methods pm ON p.payment_method    = pm.id
        LEFT JOIN employee_shifts es ON p.payer_shift       = es.id
        WHERE p.id = ?
    ");
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $data ?: null;
}

/**
 * Fetch all line-items for a purchase.
 *
 * @param  mysqli $conn
 * @param  int    $purchase_id
 * @return array  (possibly empty)
 */
function pf_getPurchaseItems($conn, $purchase_id) {
    $purchase_id = (int)$purchase_id;
    $stmt = $conn->prepare("
        SELECT
            pi.item_name,
            pi.quantity,
            pi.unit_price,
            pi.discount,
            pi.price_rounding,
            pi.total_price,
            pi.unit_id,
            pi.category_id,
            c.name  AS category_name,
            u.name  AS unit_name
        FROM   purchase_items pi
        LEFT JOIN categories c ON pi.category_id = c.id
        LEFT JOIN units      u ON pi.unit_id     = u.id
        WHERE  pi.purchase_id = ?
        ORDER  BY pi.id ASC
    ");
    if (!$stmt) return [];

    $stmt->bind_param('i', $purchase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items  = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

/**
 * Fetch suppliers list for filter dropdown.
 *
 * @param  mysqli $conn
 * @return array
 */
function pf_getSuppliersList($conn) {
    $list   = [];
    $result = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $list[] = $row;
        }
    }
    return $list;
}

/**
 * Fetch active payment methods for filter dropdown.
 *
 * @param  mysqli $conn
 * @return array
 */
function pf_getPaymentMethodsList($conn) {
    $list   = [];
    $result = $conn->query("SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $list[] = $row;
        }
    }
    return $list;
}

/**
 * Fetch active users for "Payment By" filter dropdown.
 *
 * @param  mysqli $conn
 * @return array
 */
function pf_getPaymentByList($conn) {
    $list   = [];
    $result = $conn->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $list[] = $row;
        }
    }
    return $list;
}

/**
 * Fetch paginated / filtered purchases list.
 * All user-supplied filter values are bound via prepared statements.
 *
 * @param  mysqli $conn
 * @param  array  $filters  Keys: from_date, to_date, supplier_id, payment_id, payment_by_id
 * @return mysqli_result|false
 */
function pf_getPurchasesList($conn, array $filters = []) {
    // ── CATATAN KINERJA ───────────────────────────────────────────────────
    // Total per invoice DULU dihitung dengan subquery berkorelasi:
    //     (SELECT SUM(pi.total_price) FROM purchase_items pi
    //       WHERE pi.purchase_id = p.id)
    // Bentuk itu dijalankan ULANG untuk SETIAP baris purchases, sehingga pada
    // 2.500+ invoice ia menembak purchase_items ribuan kali.
    //
    // Sekarang penjumlahannya dilakukan SEKALI dalam derived table `pit` yang
    // di-JOIN, jadi purchase_items hanya dilewati satu kali.
    //
    // HASILNYA IDENTIK, termasuk untuk invoice yang belum punya item:
    // pit.items_total sengaja TIDAK dibungkus COALESCE supaya tetap NULL
    // persis seperti perilaku subquery lama (NULL - x + y + z = NULL, dan di
    // PHP (float)NULL = 0.0). Membungkusnya dengan COALESCE justru akan
    // MENGUBAH angka pada invoice tanpa item yang punya pajak/pembulatan.
    //
    // Tiga LEFT JOIN ke `users` yang memakai REGEXP SENGAJA DIPERTAHANKAN apa
    // adanya: pencocokannya adalah eq_ref ke PRIMARY KEY users (satu lookup
    // per baris) dan REGEXP di sana hanya penjaga agar nilai non-numerik tidak
    // ikut ter-CAST. Mengubahnya berisiko menggeser nilai historis pada baris
    // lama yang masih menyimpan ID, sementara keuntungannya tidak signifikan.
    //
    // Kolom yang dikembalikan TIDAK dikurangi sedikit pun, supaya kontrak
    // fungsi ini tetap sama untuk pemanggil mana pun di masa depan.
    // ──────────────────────────────────────────────────────────────────────
    $base = "
        SELECT
            p.id,
            p.input_date,
            p.invoice_date,
            p.document,
            p.notes,
            p.overall_discount,
            p.overall_price_rounding,
            p.tax,
            s.name        AS supplier_name,
            COALESCE(u.full_name,  p.created_by) AS created_by_name,
            COALESCE(pb.full_name, p.payment_by) AS payment_by_name,
            COALESCE(pn.full_name, p.payer_name) AS payer_name_label,
            pm.name       AS payment_method_name,
            es.name       AS payer_shift_name,
            pit.items_total - p.overall_discount + p.overall_price_rounding + p.tax AS grand_total
        FROM   purchases p
        LEFT JOIN (
                   SELECT purchase_id, SUM(total_price) AS items_total
                     FROM   purchase_items
                    GROUP  BY purchase_id
                  ) pit ON pit.purchase_id = p.id
        LEFT JOIN suppliers       s  ON p.supplier_id       = s.id
        LEFT JOIN users           u  ON p.created_by REGEXP '^[0-9]+$' AND u.id  = CAST(p.created_by AS UNSIGNED)
        LEFT JOIN users           pb ON p.payment_by REGEXP '^[0-9]+$' AND pb.id = CAST(p.payment_by AS UNSIGNED)
        LEFT JOIN users           pn ON p.payer_name REGEXP '^[0-9]+$' AND pn.id = CAST(p.payer_name AS UNSIGNED)
        LEFT JOIN payment_methods pm ON p.payment_method    = pm.id
        LEFT JOIN employee_shifts es ON p.payer_shift       = es.id
        WHERE 1=1
    ";

    $where  = '';
    $params = [];
    $types  = '';

    $from = isset($filters['from_date']) ? trim($filters['from_date']) : '';
    $to   = isset($filters['to_date'])   ? trim($filters['to_date'])   : '';
    if ($from !== '' && $to !== '') {
        $where   .= " AND p.input_date BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;
        $types   .= 'ss';
    }

    $sup = isset($filters['supplier_id']) ? (int)$filters['supplier_id'] : 0;
    if ($sup > 0) {
        $where   .= " AND p.supplier_id = ?";
        $params[] = $sup;
        $types   .= 'i';
    }

    $pm = isset($filters['payment_id']) ? (int)$filters['payment_id'] : 0;
    if ($pm > 0) {
        $where   .= " AND p.payment_method = ?";
        $params[] = $pm;
        $types   .= 'i';
    }

    // Dropdown filter tetap mengirim ID user, sedangkan kolom p.payment_by
    // kini menyimpan NAMA. ID diterjemahkan dulu menjadi nama, lalu dicocokkan
    // ke keduanya: nama (baris baru & baris hasil konversi) atau ID dalam
    // bentuk teks (baris lama milik user yang sudah terlanjur dihapus).
    $pb = isset($filters['payment_by_id']) ? (int)$filters['payment_by_id'] : 0;
    if ($pb > 0) {
        $pb_name  = pf_userNameById($conn, $pb);
        $where   .= " AND (p.payment_by = ? OR p.payment_by = ?)";
        $params[] = $pb_name;
        $params[] = (string)$pb;
        $types   .= 'ss';
    }

    $sql = $base . $where . " ORDER BY p.input_date DESC, p.id DESC";

    if (empty($params)) {
        return $conn->query($sql);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// ============================================================
//  CSRF HELPERS
// ============================================================

/**
 * Get (or create) the CSRF token for the purchases module.
 *
 * @return string
 */
/* RONDE PENYATUAN CSRF — fungsi di bawah kini hanya MENERUSKAN.

   Kunci sesi lama 'purchases_csrf' tidak dipakai lagi. Token, cara pembentukan,
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
function pf_csrfToken() {
    return bltx_csrf_token();
}

/**
 * Validate a CSRF token value against the stored token.
 *
 * @param  string $token
 * @return bool
 */
function pf_csrfValid($token) {
    return bltx_csrf_valid($token);
}

/**
 * Regenerate CSRF token after consuming it (post-delete/save).
 *
 * @return string  New token
 */
function pf_csrfRegenerate() {
    /* Rotasi token DIHILANGKAN (lihat purchase_settings.php Tahap 2).
       Token kini dipakai bersama seluruh sistem; memutarnya di satu modul
       membatalkan formulir yang sedang terbuka di modul lain. Fungsi ini
       dipertahankan agar pemanggilnya tidak perlu diubah, dan mengembalikan
       token yang berlaku sekarang. */
    return bltx_csrf_token();
}

// ============================================================
//  FILE UPLOAD HELPER
// ============================================================

/**
 * Handle purchase document file upload.
 * Returns filename (relative, no path) on success, or existing filename if no new upload.
 * Returns '' if no file and no existing.
 *
 * @param  string $existing_filename  Current filename from DB (for edit mode)
 * @return string
 */
function pf_handleDocumentUpload($existing_filename = '', $keep_old_file = false) {
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['document']['tmp_name'];
        $file_name = $_FILES['document']['name'];
        $file_size = $_FILES['document']['size'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

        if (in_array($file_ext, $allowed, true) && $file_size <= MAX_FILE_SIZE) {
            $new_name = 'invoice_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
            $dest     = UPLOAD_PATH . 'purchase/' . $new_name;
            if (move_uploaded_file($file_tmp, $dest)) {
                // Remove old file if replacing
                if (!$keep_old_file && !empty($existing_filename)) {
                    $old = UPLOAD_PATH . 'purchase/' . basename($existing_filename);
                    if (file_exists($old)) @unlink($old);
                }
                return $new_name;
            }
        }
    }
    return $existing_filename;
}

// ============================================================
//  INSERT / UPDATE HELPERS
// ============================================================

/**
 * Normalise posted item rows. Rows without a name or with qty <= 0 are skipped
 * (same rule as before). Returns ['rows' => [...], 'error' => message_key|''].
 */
function pf_prepareItems($items_post) {
    $rows = [];
    if (!is_array($items_post)) return ['rows' => [], 'error' => 'purchase_at_least_one_item'];
    foreach ($items_post as $item) {
        $item_name = trim($item['item_name'] ?? '');
        $quantity  = (float)($item['quantity'] ?? 0);
        if ($item_name === '' || $quantity <= 0) continue;
        $unit_id     = (int)($item['unit_id'] ?? 0);
        $category_id = (int)($item['category_id'] ?? 0);
        if ($unit_id <= 0 || $category_id <= 0) {
            return ['rows' => [], 'error' => 'purchase_incomplete_form'];
        }
        $unit_price     = pf_parsePostedAmount($item['unit_price'] ?? '0');
        $discount       = pf_parsePostedAmount($item['discount'] ?? '0');
        $price_rounding = pf_parsePostedAmount($item['price_rounding'] ?? '0');
        $rows[] = [
            'item_name'      => $item_name,
            'quantity'       => $quantity,
            'unit_id'        => $unit_id,
            'unit_price'     => $unit_price,
            'discount'       => $discount,
            'price_rounding' => $price_rounding,
            'category_id'    => $category_id,
            'total'          => ($quantity * $unit_price) - $discount + $price_rounding,
        ];
    }
    if (!$rows) return ['rows' => [], 'error' => 'purchase_at_least_one_item'];
    return ['rows' => $rows, 'error' => ''];
}

/**
 * Insert prepared item rows for a purchase. Must run inside the caller's
 * transaction; throws on any database failure so the caller can roll back.
 */
function pf_saveItems($conn, $purchase_id, array $rows, $delete_existing = false) {
    if ($delete_existing) {
        $del = $conn->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
        if (!$del) throw new RuntimeException('prepare delete items failed');
        $del->bind_param('i', $purchase_id);
        if (!$del->execute()) throw new RuntimeException('delete items failed');
        $del->close();
    }

    $stmt_item = $conn->prepare("
        INSERT INTO purchase_items (
            purchase_id, item_name, quantity, unit_id,
            unit_price, discount, price_rounding,
            category_id, total_price
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt_item) throw new RuntimeException('prepare insert items failed');

    foreach ($rows as $r) {
        $stmt_item->bind_param(
            "isdidddid",
            $purchase_id,
            $r['item_name'],
            $r['quantity'],
            $r['unit_id'],
            $r['unit_price'],
            $r['discount'],
            $r['price_rounding'],
            $r['category_id'],
            $r['total']
        );
        if (!$stmt_item->execute()) throw new RuntimeException('insert item failed');
    }
    $stmt_item->close();
}

/** Remove an uploaded purchase document file (used for rollback/cleanup). */
function pf_removeDocumentFile($filename) {
    if ($filename === '' || $filename === null) return;
    $path = UPLOAD_PATH . 'purchase/' . basename($filename);
    if (file_exists($path)) @unlink($path);
}

/**
 * Parse a posted amount that may be formatted (IDR: "1.234.567", USD/CNY: "1,234.56")
 * or plain numeric.
 *
 * @param  string|float $value
 * @return float
 */
function pf_parsePostedAmount($value) {
    $value = (string)$value;
    $currency = getSetting('currency', 'IDR');
    if ($currency === 'IDR') {
        // IDR: thousand sep = '.', decimal sep = ','
        $value = str_replace('.', '', $value);   // remove thousand sep
        $value = str_replace(',', '.', $value);  // decimal sep → '.'
    } else {
        // USD / CNY: thousand sep = ',', decimal sep = '.'
        $value = str_replace(',', '', $value);   // remove thousand sep
    }
    return (float)$value;
}

// ============================================================
//  EXPORT HELPERS
// ============================================================

/**
 * Fetch all data required for a single-purchase export (purchase_view export).
 * Returns ['purchase' => array|null, 'items' => array, 'grand_total' => float]
 *
 * @param  mysqli $conn
 * @param  int    $purchase_id
 * @return array
 */
function pf_exportPurchaseViewData($conn, $purchase_id) {
    $purchase_id = (int)$purchase_id;
    $purchase    = pf_getPurchaseData($conn, $purchase_id);
    if (!$purchase) {
        return ['purchase' => null, 'items' => [], 'grand_total' => 0.0];
    }
    $items       = pf_getPurchaseItems($conn, $purchase_id);
    $grand_total = 0.0;
    foreach ($items as $item) {
        $grand_total += (float)$item['total_price'];
    }
    return [
        'purchase'    => $purchase,
        'items'       => $items,
        'grand_total' => $grand_total,
    ];
}

// ============================================================
//  ACTION HANDLERS
// ============================================================

/**
 * Delete a purchase (POST-based, high-security).
 * Validates: login → permission → CSRF → row existence → file → DB delete.
 *
 * @param  mysqli $conn
 * @param  int    $id
 * @param  string $csrf_token
 * @return array  ['success' => bool, 'message_key' => string]
 */
function pf_deletePurchase($conn, $id, $csrf_token) {
    if (!isLoggedIn()) {
        return ['success' => false, 'message_key' => 'no_permission'];
    }
    if (!checkPermission('purchases', 'can_delete')) {
        return ['success' => false, 'message_key' => 'no_permission'];
    }
    if (!pf_csrfValid($csrf_token)) {
        return ['success' => false, 'message_key' => 'invalid_csrf'];
    }

    $id   = (int)$id;
    $stmt = $conn->prepare("SELECT document FROM purchases WHERE id = ?");
    if (!$stmt) {
        return ['success' => false, 'message_key' => 'db_error'];
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'message_key' => 'not_found'];
    }

    // Delete associated document file if it exists
    if (!empty($row['document'])) {
        $file = UPLOAD_PATH . 'purchase/' . basename($row['document']);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    $del = $conn->prepare("DELETE FROM purchases WHERE id = ?");
    if (!$del) {
        return ['success' => false, 'message_key' => 'db_error'];
    }
    $del->bind_param('i', $id);
    if (!$del->execute()) {
        $del->close();
        return ['success' => false, 'message_key' => 'db_error'];
    }
    $del->close();

    pf_csrfRegenerate();
    logActivity('delete_purchase', "Deleted purchase ID: {$id}");
    return ['success' => true, 'message_key' => 'delete_success'];
}

/**
 * Handle Add Purchase POST request.
 * High-security: login check → permission → CSRF → sanitize → insert.
 * Returns ['success' => bool, 'message_key' => string, 'insert_id' => int]
 *
 * @param  mysqli $conn
 * @param  array  $post   $_POST
 * @param  array  $files  $_FILES
 * @param  int    $user_id
 * @return array
 */
function pf_addPurchase($conn, $post, $files, $user_id) {
    if (!isLoggedIn()) {
        return ['success' => false, 'message_key' => 'no_permission', 'insert_id' => 0];
    }
    if (!checkPermission('purchases', 'can_create')) {
        return ['success' => false, 'message_key' => 'no_permission', 'insert_id' => 0];
    }
    if (!pf_csrfValid($post['csrf_token'] ?? '')) {
        return ['success' => false, 'message_key' => 'invalid_csrf', 'insert_id' => 0];
    }

    $input_date        = trim($post['input_date'] ?? '');
    $invoice_date      = trim($post['invoice_date'] ?? '');
    $purchase_staff_id = (int)($post['purchase_staff_id'] ?? 0);
    $payment_by        = (int)($post['payment_by'] ?? 0);
    $payer_name        = (int)($post['payer_name'] ?? 0);
    $payer_shift       = (int)($post['payer_shift'] ?? 0);
    $supplier_id       = (int)($post['supplier_id'] ?? 0);
    $payment_method    = (int)($post['payment_method'] ?? 0);
    $notes             = trim($post['notes'] ?? '');
    $overall_discount       = pf_parsePostedAmount($post['overall_discount']       ?? '0');
    $overall_price_rounding = pf_parsePostedAmount($post['overall_price_rounding'] ?? '0');
    $tax                    = pf_parsePostedAmount($post['tax']                    ?? '0');

    // Validate mandatory fields
    if (!$input_date || !$invoice_date || !$purchase_staff_id || !$supplier_id || !$payment_method) {
        return ['success' => false, 'message_key' => 'purchase_incomplete_form', 'insert_id' => 0];
    }
    $prepared = pf_prepareItems($post['items'] ?? null);
    if ($prepared['error'] !== '') {
        return ['success' => false, 'message_key' => $prepared['error'], 'insert_id' => 0];
    }

    // Terjemahkan ID user menjadi NAMA agar tersimpan permanen di baris ini.
    // Validasi wajib di atas tetap memakai ID sehingga aturannya tidak berubah.
    $purchase_staff_name = pf_userNameById($conn, $purchase_staff_id);
    $payment_by_name     = pf_userNameById($conn, $payment_by);
    $payer_name_value    = pf_userNameById($conn, $payer_name);
    $created_by_name     = pf_userNameById($conn, $user_id);

    $document = pf_handleDocumentUpload('');

    // Header + seluruh item disimpan dalam SATU transaksi: gagal satu, batal semua.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO purchases
                (input_date, invoice_date, purchase_staff_id, payment_by, payer_name, payer_shift,
                 supplier_id, payment_method, notes, overall_discount, overall_price_rounding, tax, document, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new RuntimeException('prepare insert purchase failed');
        // Type string: s,s,s,s,s,i,i,i,s,d,d,d,s,s = 14 params matching 14 placeholders
        // Empat kolom identitas user kini bertipe teks dan diisi NAMA, bukan ID.
        $stmt->bind_param(
            "sssssiiisdddss",
            $input_date,
            $invoice_date,
            $purchase_staff_name,
            $payment_by_name,
            $payer_name_value,
            $payer_shift,
            $supplier_id,
            $payment_method,
            $notes,
            $overall_discount,
            $overall_price_rounding,
            $tax,
            $document,
            $created_by_name
        );

        if (!$stmt->execute()) throw new RuntimeException('insert purchase failed');
        $insert_id = (int)$conn->insert_id;
        $stmt->close();

        pf_saveItems($conn, $insert_id, $prepared['rows'], false);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        pf_removeDocumentFile($document);
        error_log('[purchase add] ' . $e->getMessage());
        return ['success' => false, 'message_key' => 'db_error', 'insert_id' => 0];
    }
    pf_csrfRegenerate();
    logActivity('add_purchase', "Added new purchase ID: {$insert_id}");

    return ['success' => true, 'message_key' => 'save_success', 'insert_id' => $insert_id];
}

/**
 * Handle Edit Purchase POST request.
 * High-security: login check → permission → CSRF → sanitize → update.
 * Returns ['success' => bool, 'message_key' => string]
 *
 * @param  mysqli $conn
 * @param  int    $id
 * @param  array  $post   $_POST
 * @param  array  $files  $_FILES
 * @param  string $existing_document  Current document filename from DB
 * @return array
 */
function pf_editPurchase($conn, $id, $post, $files, $existing_document = '') {
    if (!isLoggedIn()) {
        return ['success' => false, 'message_key' => 'no_permission'];
    }
    if (!checkPermission('purchases', 'can_update')) {
        return ['success' => false, 'message_key' => 'no_permission'];
    }
    if (!pf_csrfValid($post['csrf_token'] ?? '')) {
        return ['success' => false, 'message_key' => 'invalid_csrf'];
    }

    $id                = (int)$id;
    $input_date        = trim($post['input_date'] ?? '');
    $invoice_date      = trim($post['invoice_date'] ?? '');
    $purchase_staff_id = (int)($post['purchase_staff_id'] ?? 0);
    $payment_by        = (int)($post['payment_by'] ?? 0);
    $payer_name        = (int)($post['payer_name'] ?? 0);
    $payer_shift       = (int)($post['payer_shift'] ?? 0);
    $supplier_id       = (int)($post['supplier_id'] ?? 0);
    $payment_method    = (int)($post['payment_method'] ?? 0);
    $notes             = trim($post['notes'] ?? '');
    $overall_discount       = pf_parsePostedAmount($post['overall_discount']       ?? '0');
    $overall_price_rounding = pf_parsePostedAmount($post['overall_price_rounding'] ?? '0');
    $tax                    = pf_parsePostedAmount($post['tax']                    ?? '0');

    if (!$input_date || !$invoice_date || !$purchase_staff_id || !$supplier_id || !$payment_method) {
        return ['success' => false, 'message_key' => 'purchase_incomplete_form'];
    }
    $prepared = pf_prepareItems($post['items'] ?? null);
    if ($prepared['error'] !== '') {
        return ['success' => false, 'message_key' => $prepared['error']];
    }

    // Terjemahkan ID user menjadi NAMA agar tersimpan permanen di baris ini.
    // created_by TIDAK ikut diperbarui di sini, sama seperti sebelumnya:
    // pembuat asli invoice tidak berubah walaupun invoice-nya disunting.
    $purchase_staff_name = pf_userNameById($conn, $purchase_staff_id);
    $payment_by_name     = pf_userNameById($conn, $payment_by);
    $payer_name_value    = pf_userNameById($conn, $payer_name);

    // Identitas historis: bila akun user-nya sudah dihapus/dinonaktifkan,
    // namanya tidak ada lagi di dropdown. Halaman edit mengirim nilai -1 untuk
    // kasus itu, sehingga pf_userNameById() mengembalikan string kosong.
    //
    // Nama penggantinya diambil ULANG DARI DATABASE, bukan dari $post, supaya
    // nama historis tidak bisa dipalsukan lewat manipulasi form.
    if ($purchase_staff_name === '' || $payment_by_name === '' || $payer_name_value === '') {
        $stmt_hist = $conn->prepare(
            "SELECT purchase_staff_id, payment_by, payer_name FROM purchases WHERE id = ? LIMIT 1"
        );
        if ($stmt_hist) {
            $stmt_hist->bind_param('i', $id);
            $stmt_hist->execute();
            $row_hist = $stmt_hist->get_result()->fetch_assoc();
            $stmt_hist->close();
            if ($row_hist) {
                if ($purchase_staff_name === '') $purchase_staff_name = (string)$row_hist['purchase_staff_id'];
                if ($payment_by_name     === '') $payment_by_name     = (string)$row_hist['payment_by'];
                if ($payer_name_value    === '') $payer_name_value    = (string)$row_hist['payer_name'];
            }
        }
    }

    // File lama baru dihapus SETELAH database berhasil disimpan.
    $document = pf_handleDocumentUpload($existing_document, true);

    // Header + seluruh item disimpan dalam SATU transaksi: gagal satu, batal semua.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            UPDATE purchases SET
                input_date=?, invoice_date=?, purchase_staff_id=?,
                payment_by=?, payer_name=?, payer_shift=?,
                supplier_id=?, payment_method=?, notes=?,
                overall_discount=?, overall_price_rounding=?, tax=?, document=?
            WHERE id=?
        ");
        if (!$stmt) throw new RuntimeException('prepare update purchase failed');
        // Type string: s,s,s,s,s,i,i,i,s,d,d,d,s,i = 14 params matching 14 placeholders
        // Tiga kolom identitas user kini bertipe teks dan diisi NAMA, bukan ID.
        $stmt->bind_param(
            "sssssiiisdddsi",
            $input_date,
            $invoice_date,
            $purchase_staff_name,
            $payment_by_name,
            $payer_name_value,
            $payer_shift,
            $supplier_id,
            $payment_method,
            $notes,
            $overall_discount,
            $overall_price_rounding,
            $tax,
            $document,
            $id
        );

        if (!$stmt->execute()) throw new RuntimeException('update purchase failed');
        $stmt->close();

        pf_saveItems($conn, $id, $prepared['rows'], true);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        if ($document !== $existing_document) pf_removeDocumentFile($document);
        error_log('[purchase edit] ' . $e->getMessage());
        return ['success' => false, 'message_key' => 'db_error'];
    }
    if ($document !== $existing_document) pf_removeDocumentFile($existing_document);
    pf_csrfRegenerate();
    logActivity('edit_purchase', "Edited purchase ID: {$id}");

    return ['success' => true, 'message_key' => 'save_success'];
}