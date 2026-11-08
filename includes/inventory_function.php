<?php
/**
 * inventory_function.php
 * File: public_html/bltx/includes/inventory_function.php
 *
 * Kumpulan semua fungsi helper untuk modul inventory.
 * Dipindahkan dari: warehouse_location.php, inventory_data.php,
 *                   inventory_in.php, inventory_out.php, index.php
 *                   dan inventory_process.php.
 *
 * Include file ini satu kali dari index.php atau halaman inventory manapun.
 * Membutuhkan $conn (mysqli) dan config.php sudah di-load.
 */

/* ============================================================
   GUARD — HANYA BISA DI-INCLUDE, TIDAK BISA DIAKSES LANGSUNG
   ============================================================ */
if (!defined('BASE_PATH')) {
    http_response_code(403);
    exit('Direct access not allowed.');
}

/* ============================================================
   INCLUDE GLOBAL PERMISSION HELPER
   ============================================================ */
$_gp_file = __DIR__ . '/global_permission.php';
if (file_exists($_gp_file)) {
    require_once $_gp_file;
}

/* ============================================================
   FORMAT HELPERS
   ============================================================ */

/**
 * Format angka: 2 desimal, hapus trailing zeros setelah koma.
 * Contoh: 100.00 → "100", 10.50 → "10,5", 1234.12 → "1.234,12"
 *
 * @param  float|int|string $number
 * @return string
 */
if (!function_exists('invFormatNumber')) {
    function invFormatNumber($number): string {
        $formatted = number_format((float)$number, 2, ',', '.');
        $formatted = preg_replace('/,0+$/', '', $formatted);
        $formatted = preg_replace('/,(\d)0$/', ',$1', $formatted);
        return $formatted;
    }
}

/**
 * Format quantity: integer tanpa desimal, float dengan maksimal 2 desimal.
 * Contoh: 100.00 → "100", 10.5 → "10.5", 1.25 → "1.25"
 *
 * @param  float|int|string $qty
 * @return string
 */
if (!function_exists('invFormatQty')) {
    function invFormatQty($qty): string {
        $qty = (float)$qty;
        if ($qty == floor($qty)) {
            return number_format($qty, 0);
        }
        return rtrim(rtrim(number_format($qty, 2), '0'), '.');
    }
}

/* ============================================================
   SECURITY HELPERS
   ============================================================ */

/**
 * Generate CSRF token dan simpan ke session.
 * Gunakan di form: <input type="hidden" name="csrf_token" value="<?= invCsrfToken() ?>">
 *
 * @return string
 */
if (!function_exists('invCsrfToken')) {
/* RONDE PENYATUAN CSRF — fungsi di bawah kini hanya MENERUSKAN.

   Kunci sesi lama '_inv_csrf' tidak dipakai lagi. Token, cara pembentukan,
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
    function invCsrfToken(): string {
        return bltx_csrf_token();
    }
}

/**
 * Validasi CSRF token dari POST request.
 * Panggil di awal handler POST sebelum memproses data.
 *
 * @param  string $token  Token dari $_POST['csrf_token']
 * @return bool
 */
if (!function_exists('invValidateCsrf')) {
    function invValidateCsrf(string $token): bool {
        return bltx_csrf_valid($token);
    }
}

/**
 * Sanitasi string untuk output HTML — cegah XSS, tampilkan tanda baca dengan benar.
 * Gunakan ini menggantikan htmlspecialchars() agar ' " ! & * dll tampil dengan benar.
 *
 * @param  mixed  $value
 * @param  string $default  Nilai default jika null/empty
 * @return string
 */
if (!function_exists('invHtml')) {
    function invHtml($value, string $default = '-'): string {
        if ($value === null || $value === '') return htmlspecialchars($default, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/**
 * Decode HTML entities dari nilai yang sudah ter-encode di DB atau input.
 * Digunakan saat membaca nilai dari DB yang perlu ditampilkan di value="" attribute form.
 *
 * @param  mixed  $value
 * @return string
 */
if (!function_exists('invVal')) {
    /**
     * MENDESAK — versi lama fungsi ini TIDAK meng-escape apa pun.
     *
     * Isi lamanya:
     *     htmlspecialchars_decode(htmlspecialchars($value, ...), ENT_QUOTES)
     *
     * Meng-escape lalu langsung membatalkannya. Hasil akhirnya nilai MENTAH,
     * meski namanya terdengar seperti pembersih.
     *
     * Selama sanitize() masih meng-escape saat input, itu tidak berbahaya.
     * Tetapi sanitize() versi Tahap 1 SUDAH terpasang di server dan berhenti
     * meng-escape, sementara berkas ini belum — sehingga kedua pemakainya
     * menjadi lubang XSS yang AKTIF:
     *
     *     stock_opname_view.php:352   <textarea>...invVal($header['notes'])
     *     stock_opname_view.php:475   value="...invVal($item['remarks'])"
     *
     * Yang kedua berada di dalam atribut HTML. Catatan berisi
     *     " onmouseover=alert(1) x="
     * menutup atribut lebih awal dan skripnya berjalan di browser setiap orang
     * yang membuka stock opname itu.
     *
     * ENT_SUBSTITUTE dipakai supaya byte yang bukan UTF-8 sah diganti, bukan
     * membuat fungsi mengembalikan string kosong tanpa penjelasan.
     */
    function invVal($value): string {
        if ($value === null) return '';
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* ============================================================
   DATABASE HELPERS — INVENTORY DATA
   ============================================================ */

/**
 * Ambil detail satu item inventory berdasarkan ID.
 * Include join ke categories, suppliers, units, warehouse_locations.
 *
 * @param  int    $id
 * @return array|null  Associative array data item, atau null jika tidak ditemukan
 */
if (!function_exists('invGetItemById')) {
    function invGetItemById(int $id): ?array {
        global $conn;
        $stmt = $conn->prepare("
            SELECT i.*,
                   c.name  AS category_name,
                   s.name  AS supplier_name,
                   u.name  AS unit_name,
                   u.symbol AS unit_symbol,
                   wl.location_code,
                   wl.location_name
            FROM inventory i
            LEFT JOIN categories c        ON i.category_id  = c.id
            LEFT JOIN suppliers  s        ON i.supplier_id  = s.id
            LEFT JOIN units      u        ON i.unit_id      = u.id
            LEFT JOIN warehouse_locations wl ON i.location_id = wl.id
            WHERE i.id = ?
        ");
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item   = $result->fetch_assoc() ?: null;
        $stmt->close();
        if ($item) {
            $item['total_price'] = (float)$item['unit_price'] * (float)$item['quantity'];
        }
        return $item;
    }
}

/**
 * Hapus item inventory beserta file gambarnya.
 * Cek permission can_delete sebelum memanggil fungsi ini.
 *
 * @param  int    $id
 * @return array  ['success' => bool, 'message' => string]
 */
if (!function_exists('invDeleteItem')) {
    function invDeleteItem(int $id): array {
        global $conn;

        // Ambil nama file gambar
        $stmt = $conn->prepare('SELECT item_image FROM inventory WHERE id = ?');
        if (!$stmt) return ['success' => false, 'message' => 'DB prepare error'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($item_image);
        $stmt->fetch();
        $stmt->close();

        // Hapus record
        $stmt = $conn->prepare('DELETE FROM inventory WHERE id = ?');
        if (!$stmt) return ['success' => false, 'message' => 'DB prepare error'];
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            // Hapus file gambar jika ada
            if ($item_image && file_exists(UPLOAD_PATH . $item_image)) {
                @unlink(UPLOAD_PATH . $item_image);
            }
            logActivity('delete_inventory', "Deleted inventory ID: $id");
            return ['success' => true, 'message' => 'Item deleted successfully'];
        }
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
}

/* ============================================================
   DATABASE HELPERS — FILTER DROPDOWNS
   ============================================================ */

/**
 * Ambil daftar nama item distinct untuk dropdown filter inventory_data.
 *
 * @return mysqli_result|false
 */
if (!function_exists('invGetItemNames')) {
    function invGetItemNames() {
        global $conn;
        return $conn->query(
            "SELECT DISTINCT item_name FROM inventory ORDER BY item_name ASC"
        );
    }
}

/**
 * Ambil daftar kategori aktif untuk dropdown filter.
 *
 * @return mysqli_result|false
 */
if (!function_exists('invGetCategories')) {
    function invGetCategories() {
        global $conn;
        return $conn->query(
            "SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC"
        );
    }
}

/**
 * Ambil daftar supplier aktif untuk dropdown filter.
 *
 * @return mysqli_result|false
 */
if (!function_exists('invGetSuppliers')) {
    function invGetSuppliers() {
        global $conn;
        return $conn->query(
            "SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC"
        );
    }
}

/**
 * Ambil daftar lokasi gudang aktif untuk dropdown filter.
 *
 * @return mysqli_result|false
 */
if (!function_exists('invGetLocations')) {
    function invGetLocations() {
        global $conn;
        return $conn->query(
            "SELECT id, location_code, location_name FROM warehouse_locations WHERE is_active = 1 ORDER BY location_code ASC"
        );
    }
}

/**
 * Ambil daftar user untuk dropdown filter inventory_in / inventory_out.
 *
 * @return mysqli_result|false
 */
if (!function_exists('invGetUsers')) {
    function invGetUsers() {
        global $conn;
        // Daftar untuk dropdown filter. Selain user yang masih ada, daftar ini
        // juga menyertakan pelaku yang AKUNNYA SUDAH DIHAPUS, diambil dari kolom
        // NAMA PERMANEN di tabel transaksinya sendiri (inventory_out.taken_by_name
        // dan inventory_in.created_by_name). Sumbernya bukan lagi arsip terpisah,
        // melainkan data permanen di baris transaksi itu sendiri — sejalan dengan
        // seluruh tampilan lain yang kini membaca kolom permanen secara langsung.
        // Tanpa ini, riwayat milik user yang sudah dihapus tetap tampil di tabel
        // tetapi tidak bisa lagi difilter karena namanya hilang dari dropdown.
        return $conn->query(
            "SELECT id, full_name FROM users
             UNION
             SELECT io.taken_by, io.taken_by_name
             FROM inventory_out io
             LEFT JOIN users u ON u.id = io.taken_by
             WHERE io.taken_by IS NOT NULL
               AND io.taken_by_name IS NOT NULL AND io.taken_by_name <> ''
               AND u.id IS NULL
             UNION
             SELECT ii.created_by, ii.created_by_name
             FROM inventory_in ii
             LEFT JOIN users u2 ON u2.id = ii.created_by
             WHERE ii.created_by IS NOT NULL
               AND ii.created_by_name IS NOT NULL AND ii.created_by_name <> ''
               AND u2.id IS NULL
             ORDER BY full_name ASC"
        );
    }
}

/* ============================================================
   DATABASE HELPERS — INVENTORY DATA QUERY
   ============================================================ */

/**
 * Bangun dan jalankan query inventory data dengan filter.
 * Menggunakan prepared statement untuk keamanan.
 *
 * @param  array $filters  Associative: filter_item, filter_category, filter_supplier, filter_location, filter_min_stock
 * @return mysqli_result|false
 */
if (!function_exists('invQueryData')) {
    function invQueryData(array $filters) {
        global $conn;

        $conditions = ['1=1'];
        $params     = [];
        $types      = '';

        if (!empty($filters['filter_item'])) {
            $conditions[] = 'i.item_name = ?';
            $params[]     = $filters['filter_item'];
            $types       .= 's';
        }
        if (!empty($filters['filter_category'])) {
            $conditions[] = 'i.category_id = ?';
            $params[]     = (int)$filters['filter_category'];
            $types       .= 'i';
        }
        if (!empty($filters['filter_supplier'])) {
            $conditions[] = 'i.supplier_id = ?';
            $params[]     = (int)$filters['filter_supplier'];
            $types       .= 'i';
        }
        if (!empty($filters['filter_location'])) {
            $conditions[] = 'i.location_id = ?';
            $params[]     = (int)$filters['filter_location'];
            $types       .= 'i';
        }
        if (isset($filters['filter_min_stock']) && $filters['filter_min_stock'] !== '') {
            $conditions[] = 'i.quantity <= ?';
            $params[]     = (float)$filters['filter_min_stock'];
            $types       .= 'd';
        }

        $where = implode(' AND ', $conditions);

        $sql = "SELECT i.*,
                       c.name   AS category_name,
                       s.name   AS supplier_name,
                       u.name   AS unit_name,
                       u.symbol AS unit_symbol,
                       uqpu.symbol AS qpu_unit_symbol,
                       wl.location_code,
                       wl.location_name,
                       (i.quantity * i.unit_price) AS total_value
                FROM inventory i
                LEFT JOIN categories          c    ON i.category_id          = c.id
                LEFT JOIN suppliers           s    ON i.supplier_id          = s.id
                LEFT JOIN units               u    ON i.unit_id              = u.id
                LEFT JOIN units               uqpu ON i.qty_per_unit_unit_id = uqpu.id
                LEFT JOIN warehouse_locations wl   ON i.location_id          = wl.id
                WHERE $where
                ORDER BY i.created_at ASC, i.id ASC";

        if (empty($params)) {
            return $conn->query($sql);
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }
}

/* ============================================================
   DATABASE HELPERS — INVENTORY IN QUERY
   ============================================================ */

/**
 * Bangun dan jalankan query inventory_in dengan filter.
 *
 * @param  array $filters  Associative: from_date, to_date, user_id, category_id
 * @return mysqli_result|false
 */
if (!function_exists('invQueryIn')) {
    function invQueryIn(array $filters) {
        global $conn;

        $conditions = [];
        $params     = [];
        $types      = '';

        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $conditions[] = "DATE(ii.created_at) BETWEEN ? AND ?";
            $params[]     = $filters['from_date'];
            $params[]     = $filters['to_date'];
            $types       .= 'ss';
        }
        if (!empty($filters['user_id'])) {
            $conditions[] = 'ii.created_by = ?';
            $params[]     = (int)$filters['user_id'];
            $types       .= 'i';
        }
        if (!empty($filters['category_id'])) {
            $conditions[] = 'i.category_id = ?';
            $params[]     = (int)$filters['category_id'];
            $types       .= 'i';
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT ii.*, i.product_code, i.item_name, i.brand,
                       u.symbol,
                       -- Nama pencatat dibaca LANGSUNG dari kolom permanen
                       -- inventory_in.created_by_name yang diisi saat baris ditulis.
                       -- Tidak ada lagi JOIN ke `users` maupun ke arsip: nama sudah
                       -- tersimpan di barisnya sendiri sehingga tetap utuh setelah
                       -- akun users-nya dihapus, dan query jadi lebih ringan.
                       ii.created_by_name AS full_name,
                       c.name AS category_name
                FROM inventory_in ii
                JOIN  inventory  i   ON ii.inventory_id = i.id
                LEFT JOIN units  u   ON ii.unit_id      = u.id
                LEFT JOIN categories c ON i.category_id = c.id
                $where
                ORDER BY ii.created_at DESC";

        if (empty($params)) {
            return $conn->query($sql);
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }
}

/* ============================================================
   DATABASE HELPERS — INVENTORY OUT QUERY
   ============================================================ */

/**
 * Bangun dan jalankan query inventory_out dengan filter.
 *
 * @param  array $filters  Associative: from_date, to_date, item_id, taken_by
 * @return mysqli_result|false
 */
if (!function_exists('invQueryOut')) {
    function invQueryOut(array $filters) {
        global $conn;

        $conditions = [];
        $params     = [];
        $types      = '';

        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $conditions[] = "DATE(io.created_at) BETWEEN ? AND ?";
            $params[]     = $filters['from_date'];
            $params[]     = $filters['to_date'];
            $types       .= 'ss';
        }
        if (!empty($filters['item_id'])) {
            $conditions[] = 'io.inventory_id = ?';
            $params[]     = (int)$filters['item_id'];
            $types       .= 'i';
        }
        if (!empty($filters['taken_by'])) {
            $conditions[] = 'io.taken_by = ?';
            $params[]     = (int)$filters['taken_by'];
            $types       .= 'i';
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT io.id, io.transaction_date, io.reference_number,
                       i.product_code, i.item_name,
                       io.quantity, u.symbol AS unit_symbol,
                       -- Nama pengambil dibaca LANGSUNG dari kolom permanen
                       -- inventory_out.taken_by_name. Tanpa JOIN ke `users` maupun
                       -- arsip, sehingga nama tetap utuh setelah akunnya dihapus.
                       io.taken_by_name AS taker_name,
                       io.notes, io.created_at
                FROM inventory_out io
                JOIN  inventory i  ON io.inventory_id = i.id
                LEFT JOIN units u  ON io.unit_id      = u.id
                $where
                ORDER BY io.created_at DESC";

        if (empty($params)) {
            return $conn->query($sql);
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }
}

/* ============================================================
   DATABASE HELPERS — WAREHOUSE LOCATION QUERY
   ============================================================ */

/**
 * Ambil semua lokasi gudang dengan filter opsional.
 *
 * @param  bool   $active_only  Jika true, hanya lokasi is_active=1
 * @return mysqli_result|false
 */
if (!function_exists('invGetAllLocations')) {
    function invGetAllLocations(bool $active_only = false) {
        global $conn;
        $where = $active_only ? 'WHERE is_active = 1' : '';
        return $conn->query(
            "SELECT * FROM warehouse_locations $where ORDER BY location_code ASC"
        );
    }
}

/**
 * Tambah lokasi gudang baru.
 *
 * @param  string $code
 * @param  string $name
 * @param  string $description
 * @return array  ['success' => bool, 'message' => string]
 */
if (!function_exists('invAddLocation')) {
    function invAddLocation(string $code, string $name, string $description = ''): array {
        global $conn, $LANG;

        // Cek duplikat location_code
        $chk = $conn->prepare('SELECT id FROM warehouse_locations WHERE location_code = ?');
        if (!$chk) return ['success' => false, 'message' => 'DB error'];
        $chk->bind_param('s', $code);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            return ['success' => false, 'message' => $LANG['location_code_exists']];
        }
        $chk->close();

        $stmt = $conn->prepare(
            'INSERT INTO warehouse_locations (location_code, location_name, description, is_active, created_at, updated_at)
             VALUES (?, ?, ?, 1, NOW(), NOW())'
        );
        if (!$stmt) return ['success' => false, 'message' => 'DB error'];
        $stmt->bind_param('sss', $code, $name, $description);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            logActivity('add_warehouse_location', "Added location: $code - $name");
            return ['success' => true, 'message' => $LANG['location_added']];
        }
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
}

/**
 * Update lokasi gudang.
 *
 * @param  int    $id
 * @param  string $code
 * @param  string $name
 * @param  string $description
 * @return array  ['success' => bool, 'message' => string]
 */
if (!function_exists('invUpdateLocation')) {
    function invUpdateLocation(int $id, string $code, string $name, string $description = ''): array {
        global $conn, $LANG;

        // Cek duplikat location_code (kecuali ID ini sendiri)
        $chk = $conn->prepare('SELECT id FROM warehouse_locations WHERE location_code = ? AND id != ?');
        if (!$chk) return ['success' => false, 'message' => 'DB error'];
        $chk->bind_param('si', $code, $id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            return ['success' => false, 'message' => $LANG['location_code_exists']];
        }
        $chk->close();

        $stmt = $conn->prepare(
            'UPDATE warehouse_locations SET location_code = ?, location_name = ?, description = ?, updated_at = NOW() WHERE id = ?'
        );
        if (!$stmt) return ['success' => false, 'message' => 'DB error'];
        $stmt->bind_param('sssi', $code, $name, $description, $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            logActivity('update_warehouse_location', "Updated location ID: $id");
            return ['success' => true, 'message' => $LANG['location_updated']];
        }
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
}

/**
 * Hapus lokasi gudang.
 * Tidak bisa hapus jika masih digunakan oleh inventory.
 *
 * @param  int $id
 * @return array  ['success' => bool, 'message' => string]
 */
if (!function_exists('invDeleteLocation')) {
    function invDeleteLocation(int $id): array {
        global $conn, $LANG;

        // Cek apakah lokasi masih digunakan
        $chk = $conn->prepare('SELECT id FROM inventory WHERE location_id = ? LIMIT 1');
        if (!$chk) return ['success' => false, 'message' => 'DB error'];
        $chk->bind_param('i', $id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            return ['success' => false, 'message' => $LANG['location_in_use']];
        }
        $chk->close();

        $stmt = $conn->prepare('DELETE FROM warehouse_locations WHERE id = ?');
        if (!$stmt) return ['success' => false, 'message' => 'DB error'];
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            logActivity('delete_warehouse_location', "Deleted location ID: $id");
            return ['success' => true, 'message' => $LANG['location_deleted']];
        }
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
}

/* ============================================================
   PROCESS HANDLER — INVENTORY PROCESS (dari inventory_process.php)
   Menggantikan inventory_process.php sepenuhnya.
   Dipanggil dari index.php ketika ada POST dengan action tertentu.
   ============================================================ */

/**
 * Handle AJAX/POST request untuk inventory actions.
 * Menggantikan fungsi di inventory_process.php.
 * Dipanggil dari index.php atau file process inventory manapun.
 *
 * Actions yang didukung:
 *  - delete     → hapus item inventory (butuh can_delete)
 *  - get_item   → ambil detail item (butuh can_read)
 *
 * @return void  Output JSON langsung
 */
if (!function_exists('invHandleProcess')) {
    function invHandleProcess(): void {
        header('Content-Type: application/json; charset=utf-8');

        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => 'Not logged in']);
            exit;
        }

        $action = $_POST['action'] ?? '';

        switch ($action) {

            /* ——— DELETE ITEM ——— */
            case 'delete':
                if (!checkPermission('inventory_data', 'can_delete')) {
                    echo json_encode(['success' => false, 'message' => 'No permission']);
                    exit;
                }
                // Validasi CSRF
                if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                    exit;
                }
                $id     = (int)($_POST['id'] ?? 0);
                $result = invDeleteItem($id);
                echo json_encode($result);
                break;

            /* ——— GET ITEM DETAIL ——— */
            case 'get_item':
                if (!checkPermission('inventory_data', 'can_read')) {
                    echo json_encode(['success' => false, 'message' => 'No permission']);
                    exit;
                }
                $id   = (int)($_POST['id'] ?? 0);
                $item = invGetItemById($id);
                if ($item) {
                    echo json_encode(['success' => true, 'data' => $item]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Item not found']);
                }
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        exit;
    }
}


/* ============================================================================
   ============================================================================
   STOCK OPNAME MODULE
   ----------------------------------------------------------------------------
   Seluruh fungsi modul Inventory > Stock Opname dipindahkan ke sini dari
   includes/stock_opname_functions.php agar semua helper inventory berada
   dalam satu file dan tidak ada function yang terdefinisi dua kali.

   Helper umum di atas (invHtml, invVal, invFormatQty, invFormatNumber,
   invCsrfToken, invValidateCsrf, invGetAllLocations, invGetUsers, dsb.)
   DIPAKAI ULANG apa adanya oleh fungsi-fungsi di bawah — tidak ada versi
   kedua yang dibuat khusus untuk Stock Opname.

   Source of truth stok TETAP inventory.quantity. Bagian ini tidak pernah
   membuat saldo stok kedua — stock_opname_items.system_qty hanyalah snapshot
   baca-sekali yang diambil saat dokumen dibuat (lihat soCreateOpname()).
   ============================================================================
   ============================================================================ */

/* ============================================================
   SMALL DISPLAY / FORMAT HELPERS
   ============================================================ */

/**
 * Status dokumen -> variance per baris item (untuk badge di UI).
 * 'pending' = belum dihitung sama sekali (counted_qty masih NULL).
 */
if (!function_exists('soVarianceStatus')) {
    function soVarianceStatus($counted_qty, $difference_qty): string {
        if ($counted_qty === null) return 'pending';
        $diff = (float)$difference_qty;
        if (abs($diff) < 0.00001) return 'matched';
        return $diff > 0 ? 'over' : 'short';
    }
}

/** Bootstrap color suffix untuk badge status dokumen. */
if (!function_exists('soStatusBadgeClass')) {
    function soStatusBadgeClass(string $status): string {
        $map = [
            'draft'     => 'secondary',
            'counting'  => 'info',
            'review'    => 'warning',
            'approved'  => 'primary',
            'posted'    => 'success',
            'cancelled' => 'danger',
        ];
        return $map[$status] ?? 'secondary';
    }
}

/** Bootstrap color suffix untuk badge variance per baris item. */
if (!function_exists('soVarianceBadgeClass')) {
    function soVarianceBadgeClass(string $varianceStatus): string {
        $map = [
            'pending' => 'secondary',
            'matched' => 'success',
            'short'   => 'danger',
            'over'    => 'warning',
        ];
        return $map[$varianceStatus] ?? 'secondary';
    }
}

/** "CODE - Name" label lokasi, dengan fallback aman jika salah satu kosong. */
if (!function_exists('soLocationLabel')) {
    function soLocationLabel(?string $code, ?string $name): string {
        if ($code && $name) return $code . ' - ' . $name;
        return $code ?: ($name ?: '-');
    }
}

/**
 * Gabungkan nama pelaku dengan waktu aksinya untuk ditampilkan.
 * Contoh hasil: "Arlin - 21-08-2026 22:58:38"
 *
 * Kalau waktunya kosong/tidak valid, hanya nama yang dikembalikan — jadi aman
 * dipakai untuk dokumen lama yang kolom waktunya masih NULL.
 */
if (!function_exists('soActorWithDate')) {
    function soActorWithDate(?string $name, ?string $datetime): string {
        $name = trim((string)$name);
        if ($name === '') return '-';
        if (empty($datetime)) return $name;

        $ts = strtotime($datetime);
        if ($ts === false) return $name;

        return $name . ' - ' . date('d-m-Y H:i:s', $ts);
    }
}

/** Validasi string tanggal Y-m-d yang sebenarnya valid (bukan sekadar regex). */
if (!function_exists('soValidDate')) {
    function soValidDate(?string $date): ?string {
        if (!$date) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }
}

/* ============================================================
   TIDAK ADA LOCATION LOCK
   ============================================================
   Sebelumnya modul ini mencegah dibuatnya lebih dari satu Stock Opname
   aktif pada lokasi yang sama. Pembatasan tersebut DIHAPUS atas permintaan,
   karena barang yang sedang dihitung tidak selalu benar-benar berada di
   lokasi yang tercatat, sehingga mengunci lokasi justru menghambat.

   Sekarang: berapa pun jumlah Stock Opname boleh berjalan bersamaan pada
   lokasi mana pun. Keakuratan tetap terjaga karena system_qty adalah
   snapshot yang diambil sekali saat dokumen dibuat dan tidak pernah dibaca
   ulang — jadi variance tiap dokumen selalu dihitung terhadap kondisi stok
   pada saat dokumen itu sendiri dibuat.
   ============================================================ */

/* ============================================================
   OPNAME NUMBER GENERATOR — format SO-YYYYMMDD0001
   ============================================================ */
if (!function_exists('soGenerateOpnameNumber')) {
    function soGenerateOpnameNumber($conn, string $dateYmd): string {
        $prefix = 'SO-' . $dateYmd;
        $stmt = $conn->prepare(
            "SELECT opname_number FROM stock_opnames
             WHERE opname_number LIKE CONCAT(?, '%')
             ORDER BY opname_number DESC LIMIT 1 FOR UPDATE"
        );
        $like = $prefix;
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt->bind_result($last);
        $seq = 1;
        if ($stmt->fetch() && $last) {
            $seq = (int)substr($last, strlen($prefix)) + 1;
        }
        $stmt->close();
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }
}

/* ============================================================
   CREATE — New Stock Opname (snapshot semua item aktif di lokasi)
   ============================================================ */
if (!function_exists('soCreateOpname')) {
    function soCreateOpname($conn, int $location_id, string $notes, int $user_id): array {
        global $LANG;

        $notes = trim($notes);

        // Validasi lokasi
        $locStmt = $conn->prepare(
            "SELECT id, location_code, location_name FROM warehouse_locations WHERE id = ? AND is_active = 1"
        );
        $locStmt->bind_param('i', $location_id);
        $locStmt->execute();
        $locRow = $locStmt->get_result()->fetch_assoc();
        $locStmt->close();
        if (!$locRow) {
            return ['success' => false, 'message' => $LANG['so_invalid_location']];
        }

        // Tidak ada pengecekan lock lokasi — beberapa Stock Opname boleh
        // berjalan bersamaan pada lokasi yang sama (lihat catatan di atas).

        // Ambil item yang akan di-snapshot (baca dulu, di luar transaksi tulis, supaya window transaksi tetap singkat)
        $itemStmt = $conn->prepare(
            "SELECT id, unit_id, unit_price, quantity FROM inventory WHERE location_id = ? AND is_active = 1"
        );
        $itemStmt->bind_param('i', $location_id);
        $itemStmt->execute();
        $itemsResult = $itemStmt->get_result();
        $items = [];
        while ($row = $itemsResult->fetch_assoc()) {
            $items[] = $row;
        }
        $itemStmt->close();

        if (count($items) === 0) {
            return ['success' => false, 'message' => $LANG['so_no_items_in_location']];
        }

        $today   = date('Y-m-d');
        $dateYmd = date('Ymd');

        $attempts = 0;
        while ($attempts < 4) {
            $attempts++;
            $conn->begin_transaction();
            try {
                $opname_number = soGenerateOpnameNumber($conn, $dateYmd);

                $ins = $conn->prepare(
                    "INSERT INTO stock_opnames (opname_number, opname_date, location_id, status, notes, created_by, created_by_name)
                     VALUES (?, ?, ?, 'draft', ?, ?, (SELECT full_name FROM users WHERE id = ?))"
                );
                if (!$ins) {
                    throw new RuntimeException('prepare_header_failed');
                }
                $ins->bind_param('ssisii', $opname_number, $today, $location_id, $notes, $user_id, $user_id);
                if (!$ins->execute()) {
                    throw new RuntimeException($conn->error ?: 'insert_header_failed');
                }
                $opname_id = $ins->insert_id;
                $ins->close();

                $itemIns = $conn->prepare(
                    "INSERT INTO stock_opname_items
                        (stock_opname_id, inventory_id, system_qty, unit_id, unit_price, difference_qty, difference_value)
                     VALUES (?, ?, ?, ?, ?, 0, 0)"
                );
                if (!$itemIns) {
                    throw new RuntimeException('prepare_item_failed');
                }
                foreach ($items as $it) {
                    $itemIns->bind_param(
                        'iidid',
                        $opname_id,
                        $it['id'],
                        $it['quantity'],
                        $it['unit_id'],
                        $it['unit_price']
                    );
                    if (!$itemIns->execute()) {
                        throw new RuntimeException($conn->error ?: 'insert_item_failed');
                    }
                }
                $itemIns->close();

                $conn->commit();

                logActivity($user_id, 'create_stock_opname',
                    "Created Stock Opname {$opname_number} for location {$locRow['location_code']} (" . count($items) . " items)");

                return [
                    'success'       => true,
                    'message'       => $LANG['so_created_success'],
                    'id'            => $opname_id,
                    'opname_number' => $opname_number,
                ];

            } catch (Throwable $e) {
                $dupError = ((int)$conn->errno === 1062) || (stripos($e->getMessage(), 'Duplicate') !== false);
                $conn->rollback();
                if ($dupError && $attempts < 4) {
                    usleep(50000 * $attempts);
                    continue;
                }
                return ['success' => false, 'message' => $LANG['so_create_failed']];
            }
        }

        return ['success' => false, 'message' => $LANG['so_create_failed']];
    }
}

/* ============================================================
   READ — Header / Items / List query / Variance summary
   ============================================================ */

if (!function_exists('soGetOpnameHeader')) {
    function soGetOpnameHeader($conn, int $id): ?array {
        $stmt = $conn->prepare(
            "SELECT so.*,
                    wl.location_code, wl.location_name,
                    -- Nama pelaku dibaca LANGSUNG dari kolom permanen di dokumen
                    -- ini sendiri. Migrasi sudah memastikan seluruh baris lama
                    -- terisi, jadi cadangan JOIN ke `users` tidak diperlukan lagi.
                    so.created_by_name,
                    so.approved_by_name,
                    so.posted_by_name,
                    so.cancelled_by_name
             FROM stock_opnames so
             LEFT JOIN warehouse_locations wl ON so.location_id = wl.id
             WHERE so.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('soGetOpnameItems')) {
    function soGetOpnameItems($conn, int $opname_id) {
        $stmt = $conn->prepare(
            "SELECT soi.*,
                    i.product_code, i.item_name, i.brand,
                    u.name AS unit_name, u.symbol AS unit_symbol,
                    soi.counted_by_name
             FROM stock_opname_items soi
             JOIN inventory i     ON soi.inventory_id = i.id
             LEFT JOIN units u    ON soi.unit_id       = u.id
             WHERE soi.stock_opname_id = ?
             ORDER BY i.item_name ASC"
        );
        $stmt->bind_param('i', $opname_id);
        $stmt->execute();
        return $stmt->get_result();
    }
}

/**
 * List query dengan filter. Mengembalikan HEADER saja (client-side DataTables,
 * konsisten dengan pola invQueryData()/invQueryIn()/invQueryOut() yang sudah
 * ada — dataset opname per lokasi secara alami kecil, jadi pola ini tetap
 * sesuai tanpa perlu server-side pagination baru).
 *
 * @param array $filters opname_number, date_from, date_to, location_id, status, created_by
 */
if (!function_exists('soQueryOpnames')) {
    function soQueryOpnames($conn, array $filters) {
        $conditions = ['1=1'];
        $params     = [];
        $types      = '';

        if (!empty($filters['opname_number'])) {
            $conditions[] = 'so.opname_number LIKE ?';
            $params[]     = '%' . $filters['opname_number'] . '%';
            $types       .= 's';
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $conditions[] = 'so.opname_date BETWEEN ? AND ?';
            $params[]     = $filters['date_from'];
            $params[]     = $filters['date_to'];
            $types       .= 'ss';
        }
        if (!empty($filters['location_id'])) {
            $conditions[] = 'so.location_id = ?';
            $params[]     = (int)$filters['location_id'];
            $types       .= 'i';
        }
        if (!empty($filters['status'])) {
            $conditions[] = 'so.status = ?';
            $params[]     = $filters['status'];
            $types       .= 's';
        }
        if (!empty($filters['created_by'])) {
            $conditions[] = 'so.created_by = ?';
            $params[]     = (int)$filters['created_by'];
            $types       .= 'i';
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT so.*, wl.location_code, wl.location_name
                FROM stock_opnames so
                LEFT JOIN warehouse_locations wl ON so.location_id = wl.id
                WHERE $where
                ORDER BY so.created_at DESC";

        if (empty($params)) {
            return $conn->query($sql);
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }
}

/**
 * Ringkasan variance per opname (Total Items, Matched, Short, Over, Total
 * Variance Value) dalam SATU query agregat — bukan N+1 per baris list.
 *
 * @param  int[] $opname_ids kosongkan untuk mengambil semua opname
 * @return array keyed by stock_opname_id
 */
if (!function_exists('soGetVarianceSummaries')) {
    function soGetVarianceSummaries($conn, array $opname_ids = []): array {
        $sql = "SELECT stock_opname_id,
                       COUNT(*) AS total_items,
                       SUM(CASE WHEN counted_qty IS NOT NULL AND difference_qty = 0 THEN 1 ELSE 0 END) AS matched_count,
                       SUM(CASE WHEN counted_qty IS NOT NULL AND difference_qty < 0 THEN 1 ELSE 0 END) AS short_count,
                       SUM(CASE WHEN counted_qty IS NOT NULL AND difference_qty > 0 THEN 1 ELSE 0 END) AS over_count,
                       SUM(CASE WHEN counted_qty IS NULL THEN 1 ELSE 0 END) AS pending_count,
                       COALESCE(SUM(difference_value), 0) AS total_variance_value
                FROM stock_opname_items";
        $types  = '';
        $params = [];
        if (!empty($opname_ids)) {
            $ids = array_map('intval', $opname_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql   .= " WHERE stock_opname_id IN ($placeholders)";
            $types  = str_repeat('i', count($ids));
            $params = $ids;
        }
        $sql .= " GROUP BY stock_opname_id";

        if (empty($params)) {
            $result = $conn->query($sql);
        } else {
            $stmt = $conn->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        }

        $out = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $out[(int)$row['stock_opname_id']] = $row;
            }
        }
        return $out;
    }
}

/* ============================================================
   WORKFLOW — status transitions
   Semua transisi pakai pola "UPDATE ... WHERE id=? AND status='X'"
   lalu cek affected_rows===1. Ini optimistic-concurrency guard murah:
   kalau status sudah berubah (misal dua admin klik bersamaan), UPDATE
   tidak match satupun baris dan transisi ditolak dengan aman.
   ============================================================ */

if (!function_exists('soIsCancellable')) {
    function soIsCancellable(string $status): bool {
        return in_array($status, ['draft', 'counting', 'review', 'approved'], true);
    }
}

if (!function_exists('soStartCounting')) {
    function soStartCounting($conn, int $id, int $user_id): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        $stmt = $conn->prepare("UPDATE stock_opnames SET status = 'counting' WHERE id = ? AND status = 'draft'");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $changed = $ok && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }
        logActivity($user_id, 'start_counting_stock_opname', "Started counting for Stock Opname {$header['opname_number']}");
        return ['success' => true, 'message' => $LANG['so_counting_started']];
    }
}

/**
 * Simpan hasil counting. $items = [ ['item_id'=>int,'counted_qty'=>mixed,'remarks'=>string], ... ]
 * difference_qty / difference_value SELALU dihitung ulang di server dari
 * counted_qty - system_qty — nilai difference dari client (jika ada) diabaikan.
 * IDOR-safe: WHERE id=? AND stock_opname_id=? di level SQL, bukan asumsi trust dari client.
 */
if (!function_exists('soSaveCounting')) {
    function soSaveCounting($conn, int $opname_id, array $items, int $user_id): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $opname_id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        if ($header['status'] !== 'counting') {
            return ['success' => false, 'message' => $LANG['so_not_in_counting']];
        }

        $updStmt = $conn->prepare(
            "UPDATE stock_opname_items
                SET counted_qty      = ?,
                    difference_qty   = ? - system_qty,
                    difference_value = (? - system_qty) * unit_price,
                    remarks          = ?,
                    counted_by       = ?,
                    counted_by_name  = (SELECT full_name FROM users WHERE id = ?),
                    counted_at       = NOW()
              WHERE id = ? AND stock_opname_id = ?"
        );
        if (!$updStmt) {
            return ['success' => false, 'message' => $LANG['so_update_failed']];
        }

        $saved = 0;
        foreach ($items as $it) {
            $item_id = (int)($it['item_id'] ?? 0);
            if ($item_id <= 0) continue;
            if (!isset($it['counted_qty']) || $it['counted_qty'] === '' || $it['counted_qty'] === null) continue;
            if (!is_numeric($it['counted_qty'])) continue;
            $counted = (float)$it['counted_qty'];
            if ($counted < 0) continue; // kuantitas negatif tidak pernah valid — baris dilewati, bukan mengotori data

            $remarks = isset($it['remarks']) ? trim((string)$it['remarks']) : null;
            if ($remarks === '') $remarks = null;

            $updStmt->bind_param('dddsiiii', $counted, $counted, $counted, $remarks, $user_id, $user_id, $item_id, $opname_id);
            if ($updStmt->execute()) {
                $saved++;
            }
        }
        $updStmt->close();

        logActivity($user_id, 'save_counting_stock_opname',
            "Saved counting for Stock Opname {$header['opname_number']} ({$saved} item(s))");

        return ['success' => true, 'message' => $LANG['so_counting_saved'], 'saved_count' => $saved];
    }
}

if (!function_exists('soSubmitReview')) {
    function soSubmitReview($conn, int $id, int $user_id): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        if ($header['status'] !== 'counting') {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }

        $chk = $conn->prepare("SELECT COUNT(*) AS pending FROM stock_opname_items WHERE stock_opname_id = ? AND counted_qty IS NULL");
        $chk->bind_param('i', $id);
        $chk->execute();
        $pending = (int)($chk->get_result()->fetch_assoc()['pending'] ?? 0);
        $chk->close();
        if ($pending > 0) {
            $tpl = $LANG['so_items_not_counted'];
            return ['success' => false, 'message' => sprintf($tpl, $pending)];
        }

        $stmt = $conn->prepare("UPDATE stock_opnames SET status = 'review' WHERE id = ? AND status = 'counting'");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $changed = $ok && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }

        logActivity($user_id, 'submit_review_stock_opname', "Submitted Stock Opname {$header['opname_number']} for review");
        return ['success' => true, 'message' => $LANG['so_submitted_review']];
    }
}

if (!function_exists('soApprove')) {
    function soApprove($conn, int $id, int $user_id): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        if ($header['status'] !== 'review') {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }
        $stmt = $conn->prepare(
            "UPDATE stock_opnames
                SET status = 'approved',
                    approved_by = ?,
                    approved_by_name = (SELECT full_name FROM users WHERE id = ?),
                    approved_at = NOW()
              WHERE id = ? AND status = 'review'"
        );
        $stmt->bind_param('iii', $user_id, $user_id, $id);
        $ok = $stmt->execute();
        $changed = $ok && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }
        logActivity($user_id, 'approve_stock_opname', "Approved Stock Opname {$header['opname_number']}");
        return ['success' => true, 'message' => $LANG['so_approved']];
    }
}

/**
 * POST — the irreversible step. Full ACID transaction:
 * re-validate status + inventory refs -> update inventory.quantity ->
 * flip status to 'posted'.
 * Any failure anywhere rolls back the ENTIRE transaction (no partial post).
 *
 * CATATAN: Posting SENGAJA TIDAK membuat baris inventory_in / inventory_out.
 * Yang dibutuhkan dari Stock Opname hanyalah penyesuaian saldo stok agar
 * sesuai hasil hitung fisik, sehingga hasilnya cukup tercermin pada
 * inventory.quantity saja — bukan sebagai transaksi masuk/keluar barang
 * yang akan ikut muncul di daftar Items In / Add Items maupun Items Out.
 * Jejak audit penyesuaian tetap ada pada dokumen Stock Opname itu sendiri
 * (system_qty, counted_qty, difference_qty per item) dan pada activity log.
 */
if (!function_exists('soPost')) {
    function soPost($conn, int $id, int $user_id): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        if ($header['status'] !== 'approved') {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }

        $conn->begin_transaction();
        try {
            // Kunci baris header, verifikasi ulang status belum berubah sejak dibaca di atas
            $lockStmt = $conn->prepare("SELECT status FROM stock_opnames WHERE id = ? FOR UPDATE");
            $lockStmt->bind_param('i', $id);
            $lockStmt->execute();
            $lockRow = $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();
            if (!$lockRow || $lockRow['status'] !== 'approved') {
                throw new RuntimeException('status_changed');
            }

            $itemsStmt = $conn->prepare(
                "SELECT soi.id, soi.inventory_id, soi.counted_qty, soi.difference_qty, soi.unit_id,
                        i.id AS inv_exists
                 FROM stock_opname_items soi
                 LEFT JOIN inventory i ON soi.inventory_id = i.id
                 WHERE soi.stock_opname_id = ?
                 FOR UPDATE"
            );
            $itemsStmt->bind_param('i', $id);
            $itemsStmt->execute();
            $itemsRes = $itemsStmt->get_result();
            $lines = [];
            while ($row = $itemsRes->fetch_assoc()) {
                $lines[] = $row;
            }
            $itemsStmt->close();

            if (empty($lines)) {
                throw new RuntimeException('no_items');
            }

            $updPlus  = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
            $updMinus = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ?");

            $adjusted = 0;

            foreach ($lines as $line) {
                if ($line['inv_exists'] === null) {
                    throw new RuntimeException('invalid_inventory_' . $line['inventory_id']);
                }
                if ($line['counted_qty'] === null) {
                    throw new RuntimeException('uncounted_item_' . $line['id']);
                }
                $diff = (float)$line['difference_qty'];
                if (abs($diff) < 0.00001) {
                    continue; // MATCH — tidak perlu adjustment
                }

                $invId = (int)$line['inventory_id'];

                if ($diff > 0) {
                    $qty = $diff;
                    $updPlus->bind_param('di', $qty, $invId);
                    if (!$updPlus->execute()) {
                        throw new RuntimeException('update_qty_failed');
                    }
                } else {
                    $qty = abs($diff);
                    $updMinus->bind_param('di', $qty, $invId);
                    if (!$updMinus->execute()) {
                        throw new RuntimeException('update_qty_failed');
                    }
                }
                $adjusted++;
            }
            $updPlus->close();
            $updMinus->close();

            $updStatus = $conn->prepare(
                "UPDATE stock_opnames
                    SET status = 'posted',
                        posted_by = ?,
                        posted_by_name = (SELECT full_name FROM users WHERE id = ?),
                        posted_at = NOW()
                  WHERE id = ? AND status = 'approved'"
            );
            $updStatus->bind_param('iii', $user_id, $user_id, $id);
            if (!$updStatus->execute() || $updStatus->affected_rows !== 1) {
                throw new RuntimeException('status_update_failed');
            }
            $updStatus->close();

            $conn->commit();

            logActivity($user_id, 'post_stock_opname',
                "Posted Stock Opname {$header['opname_number']} - {$adjusted} item(s) adjusted");

            return [
                'success'  => true,
                'message'  => $LANG['so_posted'],
                'adjusted' => $adjusted,
            ];

        } catch (Throwable $e) {
            $conn->rollback();
            $reason = $e->getMessage();
            if ($reason === 'status_changed') {
                $msg = $LANG['so_status_changed_retry'];
            } elseif ($reason === 'no_items') {
                $msg = $LANG['so_no_items_to_post'];
            } elseif (strpos($reason, 'invalid_inventory_') === 0) {
                $msg = $LANG['so_invalid_inventory_ref'];
            } elseif (strpos($reason, 'uncounted_item_') === 0) {
                $msg = sprintf($LANG['so_items_not_counted'], 1);
            } else {
                $msg = $LANG['so_post_failed'];
            }
            return ['success' => false, 'message' => $msg];
        }
    }
}

if (!function_exists('soCancel')) {
    function soCancel($conn, int $id, int $user_id, string $reason): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        if (!soIsCancellable($header['status'])) {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }
        $reason = trim($reason);
        $reasonParam = $reason === '' ? null : $reason;

        $stmt = $conn->prepare(
            "UPDATE stock_opnames
                SET status = 'cancelled',
                    cancelled_by = ?,
                    cancelled_by_name = (SELECT full_name FROM users WHERE id = ?),
                    cancelled_at = NOW(),
                    cancel_reason = ?
              WHERE id = ? AND status IN ('draft','counting','review','approved')"
        );
        $stmt->bind_param('iisi', $user_id, $user_id, $reasonParam, $id);
        $ok = $stmt->execute();
        $changed = $ok && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }

        logActivity($user_id, 'cancel_stock_opname', "Cancelled Stock Opname {$header['opname_number']}");
        return ['success' => true, 'message' => $LANG['so_cancelled']];
    }
}

if (!function_exists('soDeleteDraft')) {
    function soDeleteDraft($conn, int $id, int $user_id): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        if ($header['status'] !== 'draft') {
            return ['success' => false, 'message' => $LANG['so_only_draft_deletable']];
        }
        $stmt = $conn->prepare("DELETE FROM stock_opnames WHERE id = ? AND status = 'draft'");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $changed = $ok && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) {
            return ['success' => false, 'message' => $LANG['so_only_draft_deletable']];
        }

        // stock_opname_items ikut terhapus otomatis via FK ON DELETE CASCADE
        logActivity($user_id, 'delete_stock_opname', "Deleted draft Stock Opname {$header['opname_number']}");
        return ['success' => true, 'message' => $LANG['so_deleted']];
    }
}

if (!function_exists('soUpdateNotes')) {
    function soUpdateNotes($conn, int $id, string $notes, int $user_id): array {
        global $LANG;
        $header = soGetOpnameHeader($conn, $id);
        if (!$header) {
            return ['success' => false, 'message' => $LANG['so_not_found']];
        }
        if (!in_array($header['status'], ['draft', 'counting'], true)) {
            return ['success' => false, 'message' => $LANG['so_invalid_status_transition']];
        }
        $notes = trim($notes);
        $stmt = $conn->prepare("UPDATE stock_opnames SET notes = ? WHERE id = ? AND status IN ('draft','counting')");
        $stmt->bind_param('si', $notes, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['success' => false, 'message' => $LANG['so_update_failed']];
        }
        logActivity($user_id, 'update_stock_opname', "Updated notes for Stock Opname {$header['opname_number']}");
        return ['success' => true, 'message' => $LANG['so_updated']];
    }
}