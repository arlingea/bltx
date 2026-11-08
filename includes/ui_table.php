<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 *  includes/ui_table.php
 *  ────────────────────────────────────────────────────────────────────────
 *  Reusable table UI helpers — AdminLTE v4 + Bootstrap 5 consistent.
 *
 *  GOAL:
 *  - All tables across the project render with one consistent markup.
 *  - Pages just CALL these functions — no need to repeat table-responsive,
 *    table-hover, datatable wiring, empty-state, etc.
 *  - Dark mode (data-bs-theme="dark") works automatically because we rely
 *    on Bootstrap 5 .table + AdminLTE styling.
 *
 *  USAGE (full call):
 *      ui_table_open('myTable', [
 *          'columns' => [
 *              ['label' => 'Name',  'width' => 180],
 *              ['label' => 'Email', 'class' => 'd-none d-md-table-cell'],
 *              ['label' => 'Status','align' => 'center', 'width' => 100],
 *              ['label' => 'Actions','align' => 'center', 'width' => 120],
 *          ],
 *          'striped'    => true,    // .table-striped (default true)
 *          'hover'      => true,    // .table-hover (default true)
 *          'small'      => false,   // .table-sm (default false)
 *          'bordered'   => false,   // .table-bordered (default false)
 *          'responsive' => true,    // wrap in .table-responsive (default true)
 *          'datatable'  => true,    // add .datatable class for JS auto-init (default true)
 *          'extra_class'=> '',      // append extra classes to <table>
 *      ]);
 *
 *      // ... your <tbody> rows (free PHP/HTML) ...
 *
 *      ui_table_close();
 *
 *  USAGE (helpers inside body):
 *      ui_table_empty(5, $LANG['no_data']);  // single-row empty placeholder
 *
 *  All output uses htmlspecialchars where appropriate. None of these helpers
 *  query the database or mutate state.
 * ════════════════════════════════════════════════════════════════════════════
 */

if (!function_exists('ui_table_open')) {

/**
 * Open a standardized table. Echoes the opening markup INCLUDING:
 *   <div class="table-responsive">
 *       <table class="table ...">
 *           <thead><tr>
 *               <th>...</th> ...
 *           </tr></thead>
 *           <tbody>
 *
 * Caller is responsible for rendering <tr><td>...</td></tr> rows, then
 * calling ui_table_close().
 *
 * @param string $id       HTML id for the <table>
 * @param array  $opts     {
 *     @type array  columns     Array of column definitions. Each item is:
 *                              ['label' => string,
 *                               'width' => int|string|null,
 *                               'align' => 'left'|'center'|'right'|null,
 *                               'class' => string|null,
 *                               'raw'   => bool (default false; if true, label is rendered as-is, no escape)]
 *     @type bool   striped     default true
 *     @type bool   hover       default true
 *     @type bool   small       default false
 *     @type bool   bordered    default false
 *     @type bool   responsive  default true
 *     @type bool   datatable   default true
 *     @type string extra_class default ''
 *     @type string thead_class default '' (e.g. 'table-light')
 *     @type string id_prefix   default ''
 *     @type string width       default '' — sets style="width: ..." on the table
 *
 *     ── TAHAP 0.5 — TAMBAHAN, SEMUANYA OPSIONAL ────────────────────────
 *     Tanpa opsi-opsi di bawah, perilaku fungsi ini SAMA PERSIS seperti
 *     sebelumnya. Signature tidak berubah, jadi 7 berkas yang sudah
 *     memakainya (purchases/categories, suppliers, units, payroll/positions,
 *     religions, settings/activity, includes/header.php) tidak terpengaruh.
 *
 *     @type bool   server_side default false — nyalakan mode server-side
 *                              DataTables untuk tabel bervolume besar (T9)
 *     @type string ajax_url    URL endpoint JSON; wajib bila server_side
 *     @type int    page_length default 0 = ikut setelan `pagination` di DB
 *     @type array  order       [nomor_kolom, 'asc'|'desc'], mis. [0, 'desc']
 *     @type array  columns_def daftar nama kolom untuk dikirim ke server,
 *                              berpasangan urut dengan `columns`
 *
 *     Selain itu, tiap entri di `columns` kini boleh memuat:
 *       'orderable' => false   kolom tidak bisa diurutkan (mis. kolom foto,
 *                              sakelar status, dan tombol aksi). Nomor
 *                              kolomnya dikumpulkan lalu dikirim sebagai
 *                              data-no-order, dan includes/footer.php yang
 *                              menerjemahkannya jadi columnDefs. Kolom tanpa
 *                              kunci ini tetap bisa diurutkan seperti dulu.
 * }
 */
function ui_table_open(string $id, array $opts = []): void
{
    $cols       = $opts['columns']     ?? [];
    $striped    = $opts['striped']     ?? true;
    $hover      = $opts['hover']       ?? true;
    $small      = $opts['small']       ?? false;
    $bordered   = $opts['bordered']    ?? false;
    $responsive = $opts['responsive']  ?? true;
    $datatable  = $opts['datatable']   ?? true;
    $extraCls   = trim((string)($opts['extra_class'] ?? ''));
    $theadCls   = trim((string)($opts['thead_class'] ?? ''));
    $width      = trim((string)($opts['width']       ?? ''));

    // ── Tahap 0.5: opsi server-side (opsional, default mati) ─────────────
    $serverSide = (bool)  ($opts['server_side'] ?? false);
    $ajaxUrl    = trim((string)($opts['ajax_url']    ?? ''));
    $pageLength = (int)   ($opts['page_length'] ?? 0);
    $order      = $opts['order']       ?? null;
    $colsDef    = $opts['columns_def'] ?? null;

    // Server-side tanpa endpoint tidak ada artinya; jangan diam-diam
    // menghasilkan tabel yang selamanya kosong.
    if ($serverSide && $ajaxUrl === '') {
        $serverSide = false;
    }

    // Kolom yang tidak boleh diurutkan (Tahap 2 — dipakai tabel Users).
    $noOrder = [];
    foreach (array_values($cols) as $__i => $__c) {
        if (is_array($__c) && array_key_exists('orderable', $__c) && $__c['orderable'] === false) {
            $noOrder[] = $__i;
        }
    }

    // Remember whether THIS table is a DataTable so ui_table_empty can
    // skip the colspan placeholder row (which causes the "Incorrect column
    // count" DataTables warning when present in an empty tbody).
    static $current_is_dt = null;
    $GLOBALS['__ui_table_current_is_dt'] = $datatable;

    // Build class list
    $classes = ['table', 'align-middle'];
    if ($striped)   $classes[] = 'table-striped';
    if ($hover)     $classes[] = 'table-hover';
    if ($small)     $classes[] = 'table-sm';
    if ($bordered)  $classes[] = 'table-bordered';
    if ($datatable) $classes[] = 'ui-datatable';   // JS hook (auto-init in footer.php)
    if ($extraCls !== '') $classes[] = $extraCls;

    $clsStr  = implode(' ', $classes);
    $idAttr  = $id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '';
    $widthAttr = $width !== '' ? ' style="width:' . htmlspecialchars($width, ENT_QUOTES) . '"' : '';

    /* ── Tahap 0.5 ────────────────────────────────────────────────────────
       Setelan diteruskan sebagai atribut data-*, BUKAN sebagai blok
       <script> init kedua. Init DataTables tetap SATU, yaitu yang sudah ada
       di includes/footer.php; ia yang membaca atribut-atribut ini. Menulis
       init kedua di halaman akan memicu peringatan "re-initialise".
       Tabel tanpa atribut ini berjalan client-side persis seperti dulu. */
    $dataAttr = '';
    if ($serverSide) {
        $dataAttr .= ' data-server-side="1"'
                  .  ' data-ajax-url="' . htmlspecialchars($ajaxUrl, ENT_QUOTES, 'UTF-8') . '"';
        if (is_array($colsDef) && !empty($colsDef)) {
            $dataAttr .= ' data-columns=\''
                      .  htmlspecialchars(json_encode(array_values($colsDef)), ENT_QUOTES, 'UTF-8')
                      .  '\'';
        }
    }
    if ($pageLength > 0) {
        $dataAttr .= ' data-page-length="' . $pageLength . '"';
    }
    if (!empty($noOrder)) {
        $dataAttr .= ' data-no-order="' . htmlspecialchars(json_encode($noOrder), ENT_QUOTES, 'UTF-8') . '"';
    }
    if (is_array($order) && count($order) === 2) {
        $dir = (strtolower((string)$order[1]) === 'desc') ? 'desc' : 'asc';
        $dataAttr .= ' data-order-col="' . (int)$order[0] . '" data-order-dir="' . $dir . '"';
    }

    if ($responsive) {
        echo '<div class="table-responsive">';
    }
    echo '<table' . $idAttr . ' class="' . htmlspecialchars($clsStr, ENT_QUOTES) . '"' . $widthAttr . $dataAttr . '>';

    // <thead>
    if (!empty($cols)) {
        $theadAttr = $theadCls !== '' ? ' class="' . htmlspecialchars($theadCls, ENT_QUOTES) . '"' : '';
        echo '<thead' . $theadAttr . '><tr>';
        foreach ($cols as $col) {
            ui_table_th($col);
        }
        echo '</tr></thead>';
    }

    echo '<tbody>';
    // Caller now renders <tr> rows themselves.
}

/**
 * Internal helper: render a single <th> cell from a column spec array.
 */
function ui_table_th(array $col): void
{
    $label = (string)($col['label'] ?? '');
    $raw   = (bool)  ($col['raw']   ?? false);
    $width = $col['width'] ?? null;
    $align = strtolower((string)($col['align'] ?? ''));
    $cls   = trim((string)($col['class'] ?? ''));

    // Build class
    $classes = [];
    if ($align === 'center') $classes[] = 'text-center';
    elseif ($align === 'right' || $align === 'end') $classes[] = 'text-end';
    elseif ($align === 'left' || $align === 'start') $classes[] = 'text-start';
    if ($cls !== '') $classes[] = $cls;
    $clsAttr = !empty($classes) ? ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES) . '"' : '';

    // Width: integer = px, string = literal value
    $styleAttr = '';
    if ($width !== null && $width !== '') {
        $w = is_int($width) ? ($width . 'px') : (string)$width;
        $styleAttr = ' style="width:' . htmlspecialchars($w, ENT_QUOTES) . '"';
    }

    echo '<th' . $clsAttr . $styleAttr . '>'
       . ($raw ? $label : htmlspecialchars($label, ENT_QUOTES, 'UTF-8'))
       . '</th>';
}

/**
 * Close the table opened by ui_table_open(). Closes </tbody></table>
 * and the optional .table-responsive wrapper.
 */
function ui_table_close(bool $responsive = true): void
{
    echo '</tbody></table>';
    if ($responsive) {
        echo '</div>';
    }
    // Clear the datatable-current flag set by ui_table_open.
    unset($GLOBALS['__ui_table_current_is_dt']);
}

/**
 * Render an "empty state" row that spans all columns.
 *
 * @param int    $colspan  number of <th> in the header
 * @param string $message  text shown to the user
 * @param string $icon     bootstrap-icon name (without the leading "bi bi-")
 */
function ui_table_empty(int $colspan, string $message = 'No data available', string $icon = 'inbox'): void
{
    // If the currently-open table is a DataTable, don't emit a colspan
    // placeholder row — DataTables shows its own "no data" message via
    // language.emptyTable, and a 1-cell row triggers the "Incorrect column
    // count" warning.
    if (!empty($GLOBALS['__ui_table_current_is_dt'])) {
        return;
    }
    $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $ico = htmlspecialchars($icon,    ENT_QUOTES, 'UTF-8');
    echo '<tr><td colspan="' . $colspan . '" class="text-center text-muted py-4">'
       . '<i class="bi bi-' . $ico . ' d-block mb-2" style="font-size:2rem;opacity:.45"></i>'
       . '<div class="small">' . $msg . '</div>'
       . '</td></tr>';
}

/**
 * Render a button group with the standard view/edit/delete actions for
 * a table row. Pass null to skip an action.
 *
 *   ui_table_actions([
 *       'view'   => url('item.php?id=1'),
 *       'edit'   => url('item_edit.php?id=1'),
 *       'delete' => "deleteItem(1)",   // JS function call (no url())
 *       'view_label' => 'View',
 *       'edit_label' => 'Edit',
 *       'delete_label'=> 'Delete',
 *   ]);
 */
function ui_table_actions(array $actions = []): void
{
    echo '<div class="btn-group btn-group-sm" role="group">';

    if (!empty($actions['view'])) {
        $u = htmlspecialchars((string)$actions['view'], ENT_QUOTES);
        $lbl = htmlspecialchars((string)($actions['view_label'] ?? 'View'), ENT_QUOTES);
        echo '<a href="' . $u . '" class="btn btn-outline-info" title="' . $lbl . '">'
           . '<i class="bi bi-eye"></i></a>';
    }
    if (!empty($actions['edit'])) {
        $u = htmlspecialchars((string)$actions['edit'], ENT_QUOTES);
        $lbl = htmlspecialchars((string)($actions['edit_label'] ?? 'Edit'), ENT_QUOTES);
        echo '<a href="' . $u . '" class="btn btn-outline-primary" title="' . $lbl . '">'
           . '<i class="bi bi-pencil-square"></i></a>';
    }
    if (!empty($actions['delete'])) {
        $onclick = htmlspecialchars((string)$actions['delete'], ENT_QUOTES);
        $lbl = htmlspecialchars((string)($actions['delete_label'] ?? 'Delete'), ENT_QUOTES);
        echo '<button type="button" class="btn btn-outline-danger" title="' . $lbl . '"'
           . ' onclick="' . $onclick . '"><i class="bi bi-trash"></i></button>';
    }

    echo '</div>';
}

/**
 * Render a Bootstrap badge with a status color.
 *
 *   ui_status_badge(1, 'Active', 'Inactive');
 *   ui_status_badge($row['status'], 'Approved', 'Pending', 'success', 'warning');
 */
function ui_status_badge($value, string $true_label = 'Active', string $false_label = 'Inactive',
                         string $true_color = 'success', string $false_color = 'secondary'): void
{
    $isTrue = (bool)$value && (int)$value !== 0;
    $color = $isTrue ? $true_color : $false_color;
    $label = $isTrue ? $true_label : $false_label;
    echo '<span class="badge bg-' . htmlspecialchars($color, ENT_QUOTES) . '">'
       . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/* ═══════════════════════════════════════════════════════════════════════════
   TAHAP 0.5 — DUKUNGAN SERVER-SIDE (T9)

   Dipakai oleh endpoint JSON yang melayani tabel dengan 'server_side' => true.
   Tidak ada satu pun fungsi lama yang berubah; semua di bawah ini BARU.

   Kerangka endpoint (contoh untuk Tahap 2–5):

       require_once __DIR__ . '/../includes/config.php';
       require_once __DIR__ . '/../includes/global_permission.php';
       require_once __DIR__ . '/../includes/ui_table.php';
       bltx_guard_ajax('settings_activity', 'can_read');

       $p = ui_table_server_params(['created_at', 'user_name', 'action']);
       // ... hitung $total, $filtered, dan $rows dengan prepared statement ...
       ui_table_server_json($p['draw'], $total, $filtered, $rows);
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Baca parameter permintaan DataTables dengan aman.
 *
 * Nama kolom untuk ORDER BY TIDAK PERNAH diambil mentah dari permintaan.
 * Klien hanya mengirim NOMOR kolom; nomor itu dipetakan ke daftar putih
 * $sortable_columns milik server. Inilah yang menutup jalur SQL injection
 * lewat parameter order, yang merupakan kesalahan paling umum pada
 * implementasi DataTables server-side.
 *
 * @param array  $sortable_columns daftar putih nama kolom, urut sesuai
 *                                 urutan kolom di tabel HTML
 * @param string $default_order    kolom default bila nomor tidak sah
 * @return array{draw:int,start:int,length:int,search:string,order_by:string,order_dir:string}
 */
function ui_table_server_params(array $sortable_columns = [], string $default_order = ''): array
{
    $draw   = isset($_REQUEST['draw'])   ? (int)$_REQUEST['draw']   : 1;
    $start  = isset($_REQUEST['start'])  ? (int)$_REQUEST['start']  : 0;
    $length = isset($_REQUEST['length']) ? (int)$_REQUEST['length'] : 25;

    if ($start  < 0)    { $start  = 0; }
    // -1 berarti "tampilkan semua" di DataTables. Sengaja dibatasi supaya
    // satu permintaan tidak bisa menarik 5.339 baris purchase_items sekaligus.
    if ($length <= 0 || $length > 200) { $length = 25; }

    $search = '';
    if (isset($_REQUEST['search']) && is_array($_REQUEST['search'])) {
        $search = trim((string)($_REQUEST['search']['value'] ?? ''));
    }

    $order_by  = $default_order !== '' ? $default_order : ($sortable_columns[0] ?? '');
    $order_dir = 'ASC';

    if (isset($_REQUEST['order'][0]) && is_array($_REQUEST['order'][0])) {
        $idx = (int)($_REQUEST['order'][0]['column'] ?? -1);
        $dir = strtolower((string)($_REQUEST['order'][0]['dir'] ?? 'asc'));
        if (isset($sortable_columns[$idx]) && $sortable_columns[$idx] !== '') {
            $order_by = $sortable_columns[$idx];
        }
        $order_dir = ($dir === 'desc') ? 'DESC' : 'ASC';
    }

    return [
        'draw'      => $draw,
        'start'     => $start,
        'length'    => $length,
        'search'    => $search,
        'order_by'  => $order_by,
        'order_dir' => $order_dir,
    ];
}

/**
 * Kirim balasan JSON dalam bentuk yang diharapkan DataTables, lalu berhenti.
 *
 * @param int   $draw            nilai draw dari ui_table_server_params()
 * @param int   $total           jumlah baris seluruhnya, tanpa filter
 * @param int   $filtered        jumlah baris setelah filter pencarian
 * @param array $rows            baris siap tampil (array of array)
 */
function ui_table_server_json(int $draw, int $total, int $filtered, array $rows): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $filtered,
        'data'            => array_values($rows),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Bangun potongan LIKE untuk pencarian di beberapa kolom sekaligus.
 *
 * Mengembalikan potongan SQL berisi tanda tanya (?) dan nilai-nilai yang
 * harus di-bind — jadi pemanggil tetap memakai prepared statement dan tidak
 * pernah menyambung nilai pencarian ke dalam SQL.
 *
 *   [$sql, $vals, $types] = ui_table_server_search('nama', ['p.name','s.name']);
 *   // $sql   : "(p.name LIKE ? OR s.name LIKE ?)"
 *   // $vals  : ['%nama%', '%nama%']
 *   // $types : "ss"
 *
 * @return array{0:string,1:array,2:string}
 */
function ui_table_server_search(string $search, array $columns): array
{
    $search = trim($search);
    if ($search === '' || empty($columns)) {
        return ['', [], ''];
    }
    $needle = '%' . $search . '%';
    $parts  = [];
    $vals   = [];
    foreach ($columns as $col) {
        $parts[] = $col . ' LIKE ?';
        $vals[]  = $needle;
    }
    return ['(' . implode(' OR ', $parts) . ')', $vals, str_repeat('s', count($vals))];
}

} // end if (!function_exists)
