<?php
// File: inventory/export_warehouse_location.php
// Purpose: Excel / PDF export for Warehouse Location list.
// Called from inventory/warehouse_location.php → whExport('excel'|'pdf')
require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

// Multi-language support (mirrors export_inventory_in.php convention)
$current_lang = $_SESSION['language'] ?? ($_SESSION['lang'] ?? 'en');
$lang_file = __DIR__ . "/../languages/{$current_lang}.php";

if (file_exists($lang_file)) {
    $lang = require $lang_file;
} elseif (file_exists(__DIR__ . '/../languages/en.php')) {
    $lang = require __DIR__ . '/../languages/en.php';
} else {
    $lang = [];
}

$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'excel';

// ── Optional filters (passed from DataTable client-side if needed) ─────────
$f_code   = isset($_GET['code'])   ? trim($_GET['code'])   : '';
$f_name   = isset($_GET['name'])   ? trim($_GET['name'])   : '';
$f_status = isset($_GET['status']) ? trim($_GET['status']) : '';   // '', '1', '0'

// ── Build WHERE ────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_code !== '') {
    $where[]  = 'location_code LIKE ?';
    $params[] = '%' . $f_code . '%';
    $types   .= 's';
}
if ($f_name !== '') {
    $where[]  = 'location_name LIKE ?';
    $params[] = '%' . $f_name . '%';
    $types   .= 's';
}
if ($f_status === '0' || $f_status === '1') {
    $where[]  = 'is_active = ?';
    $params[] = (int)$f_status;
    $types   .= 'i';
}
$where_sql = implode(' AND ', $where);

// ── Fetch data (prepared statement — same security posture as the page) ───
$sql = "SELECT id, location_code, location_name, description, is_active,
               created_at, updated_at
        FROM warehouse_locations
        WHERE $where_sql
        ORDER BY location_code ASC";

$data = [];
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

$today  = date('Y-m-d');
$L = [
    'title'      => $lang['warehouse_location'],
    'no'         => $lang['no'],
    'code'       => $lang['location_code'],
    'name'       => $lang['location_name'],
    'desc'       => $lang['description'],
    'status'     => $lang['status'],
    'active'     => $lang['active'],
    'inactive'   => $lang['inactive'],
    'created'    => $lang['created_at'],
    'updated'    => $lang['updated_at'],
    'no_data'    => $lang['no_data'],
    'report_on'  => $lang['report_on'],
];

// ── EXCEL (.xls — HTML table) ─────────────────────────────────────────────
if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Cache-Control: max-age=0');
    header('Content-Disposition: attachment;filename="warehouse_locations_' . $today . '.xls"');

    echo '<table border="1">';
    echo '<tr><th colspan="7" style="text-align:center;font-weight:bold;font-size:16px;">'
       . htmlspecialchars($L['title']) . '</th></tr>';
    echo '<tr><th colspan="7" style="text-align:center;font-size:12px;">'
       . htmlspecialchars($L['report_on']) . ': ' . date('d/m/Y H:i') . '</th></tr>';
    echo '<tr>';
    echo '<th>' . htmlspecialchars($L['no'])      . '</th>';
    echo '<th>' . htmlspecialchars($L['code'])    . '</th>';
    echo '<th>' . htmlspecialchars($L['name'])    . '</th>';
    echo '<th>' . htmlspecialchars($L['desc'])    . '</th>';
    echo '<th>' . htmlspecialchars($L['status'])  . '</th>';
    echo '<th>' . htmlspecialchars($L['created']) . '</th>';
    echo '<th>' . htmlspecialchars($L['updated']) . '</th>';
    echo '</tr>';

    if (count($data) > 0) {
        $no = 1;
        foreach ($data as $r) {
            $status_label = ((int)$r['is_active'] === 1) ? $L['active'] : $L['inactive'];
            echo '<tr>';
            echo '<td>' . $no++                                                  . '</td>';
            echo '<td>' . htmlspecialchars($r['location_code'])                  . '</td>';
            echo '<td>' . htmlspecialchars($r['location_name'])                  . '</td>';
            echo '<td>' . htmlspecialchars($r['description'] ?? '')              . '</td>';
            echo '<td>' . htmlspecialchars($status_label)                        . '</td>';
            echo '<td>' . date('d/m/Y H:i:s', strtotime($r['created_at']))       . '</td>';
            echo '<td>' . date('d/m/Y H:i:s', strtotime($r['updated_at']))       . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7" style="text-align:center;">' . htmlspecialchars($L['no_data']) . '</td></tr>';
    }
    echo '</table>';

    if (function_exists('logActivity')) {
        logActivity('export_excel', 'Exported warehouse locations to Excel');
    }
    exit;
}

// ── PDF (DomPDF) ───────────────────────────────────────────────────────────
if ($type === 'pdf') {
    require_once __DIR__ . '/../assets/vendor/dompdf/autoload.inc.php';

    $html  = '<html><head><style>
        body { font-family: Arial, sans-serif; }
        h3   { text-align: center; margin-bottom: 4px; }
        .meta{ text-align: center; margin-bottom: 14px; font-size: 11px; color:#555; }
        table{ border-collapse: collapse; width: 100%; font-size: 10px; }
        th,td{ border: 1px solid #000; padding: 5px; text-align: left; }
        th   { background-color: #f2f2f2; font-weight: bold; }
        .no-data { text-align: center; padding: 20px; }
        .badge-ok { background:#d1e7dd; padding:1px 6px; border-radius:3px; }
        .badge-no { background:#f8d7da; padding:1px 6px; border-radius:3px; }
    </style></head><body>';
    $html .= '<h3>' . htmlspecialchars($L['title']) . '</h3>';
    $html .= '<div class="meta">' . htmlspecialchars($L['report_on']) . ': ' . date('d/m/Y H:i') . '</div>';
    $html .= '<table><tr>';
    $html .= '<th>' . htmlspecialchars($L['no'])      . '</th>';
    $html .= '<th>' . htmlspecialchars($L['code'])    . '</th>';
    $html .= '<th>' . htmlspecialchars($L['name'])    . '</th>';
    $html .= '<th>' . htmlspecialchars($L['desc'])    . '</th>';
    $html .= '<th>' . htmlspecialchars($L['status'])  . '</th>';
    $html .= '<th>' . htmlspecialchars($L['created']) . '</th>';
    $html .= '<th>' . htmlspecialchars($L['updated']) . '</th>';
    $html .= '</tr>';

    if (count($data) > 0) {
        $no = 1;
        foreach ($data as $r) {
            $active     = ((int)$r['is_active'] === 1);
            $badgeClass = $active ? 'badge-ok' : 'badge-no';
            $statusText = $active ? $L['active'] : $L['inactive'];
            $html .= '<tr>'
                  .  '<td>' . $no++                                                              . '</td>'
                  .  '<td>' . htmlspecialchars($r['location_code'])                              . '</td>'
                  .  '<td>' . htmlspecialchars($r['location_name'])                              . '</td>'
                  .  '<td>' . htmlspecialchars($r['description'] ?? '')                          . '</td>'
                  .  '<td><span class="' . $badgeClass . '">' . htmlspecialchars($statusText) . '</span></td>'
                  .  '<td>' . date('d/m/Y H:i:s', strtotime($r['created_at']))                   . '</td>'
                  .  '<td>' . date('d/m/Y H:i:s', strtotime($r['updated_at']))                   . '</td>'
                  .  '</tr>';
        }
    } else {
        $html .= '<tr><td colspan="7" class="no-data">' . htmlspecialchars($L['no_data']) . '</td></tr>';
    }
    $html .= '</table></body></html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('warehouse_locations_' . $today . '.pdf', ['Attachment' => true]);

    if (function_exists('logActivity')) {
        logActivity('export_pdf', 'Exported warehouse locations to PDF');
    }
    exit;
}

// Unknown type — bounce back
header('Location: ' . url('inventory/inventory_gate.php?tab=warehouse_location'));
exit;
