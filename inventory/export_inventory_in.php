<?php
// File: inventory/export_inventory_in.php
require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

// Multi-language support
$current_lang = $_SESSION['language'] ?? 'en';
$lang_file = __DIR__ . "/../languages/{$current_lang}.php";

if (file_exists($lang_file)) {
    $lang = require $lang_file;
} elseif (file_exists(__DIR__ . '/../languages/en.php')) {
    $lang = require __DIR__ . '/../languages/en.php';
} else {
    $lang = [];
}

$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'excel';
$from_date = !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

// Build WHERE clause with filters
$where_clauses = ["DATE(ii.created_at) BETWEEN '$from_date' AND '$to_date'"];

if ($user_id > 0) {
    $where_clauses[] = "ii.created_by = $user_id";
}

if ($category_id > 0) {
    $where_clauses[] = "i.category_id = $category_id";
}

$where_sql = implode(' AND ', $where_clauses);

// Get inventory in data with filters
// Nama pelaku dibaca LANGSUNG dari kolom permanen inventory_in.created_by_name
// yang diisi saat baris ditulis, sehingga tetap ikut ter-export walaupun akun
// user-nya sudah dihapus. Alias `full_name` dipertahankan agar tidak ada baris
// tampilan yang perlu diubah.
$sql = "SELECT ii.*, i.product_code, i.item_name, i.brand, u.symbol,
               ii.created_by_name AS full_name,
               c.name as category_name
        FROM inventory_in ii
        JOIN inventory i ON ii.inventory_id = i.id
        LEFT JOIN units u ON ii.unit_id = u.id
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE $where_sql
        ORDER BY ii.created_at DESC";

$result = $conn->query($sql);
$data = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

// Function to format quantity
function formatQty($qty) {
    $qty = floatval($qty);
    return ($qty == floor($qty)) ? number_format($qty, 0) : rtrim(rtrim(number_format($qty, 2), '0'), '.');
}

// --- GENERATE FILE ---
if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Cache-Control: max-age=0');
    header('Content-Disposition: attachment;filename="inventory_in_' . $from_date . '_to_' . $to_date . '.xls"');

    echo '<table border="1">';
    echo '<tr><th colspan="9" style="text-align:center; font-weight:bold; font-size:16px;">' . ($lang['inventory_in']) . '</th></tr>';
    echo '<tr><th colspan="9" style="text-align:center; font-size:14px;">Period: ' . date('d/m/Y', strtotime($from_date)) . ' - ' . date('d/m/Y', strtotime($to_date)) . '</th></tr>';
    echo '<tr>';
    echo '<th>' . ($lang['no']) . '</th>';
    echo '<th>' . ($lang['transaction_date']) . '</th>';
    echo '<th>' . ($lang['product_code']) . '</th>';
    echo '<th>' . ($lang['item_name']) . '</th>';
    echo '<th>' . ($lang['category']) . '</th>';
    echo '<th>' . ($lang['quantity']) . '</th>';
    echo '<th>' . ($lang['reference_number']) . '</th>';
    echo '<th>' . ($lang['notes']) . '</th>';
    echo '<th>' . ($lang['created_by']) . '</th>';
    echo '</tr>';
    
    if (count($data) > 0) {
        $no = 1;
        foreach ($data as $r) {
            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            echo '<td>' . date('d/m/Y H:i:s', strtotime($r['created_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($r['product_code']) . '</td>';
            echo '<td>' . htmlspecialchars($r['item_name']) . '</td>';
            echo '<td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>';
            echo '<td>' . formatQty($r['quantity']) . ' ' . htmlspecialchars($r['symbol'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['reference_number'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['notes'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['full_name'] ?? '-') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="9" style="text-align:center;">' . ($lang['no_data']) . '</td></tr>';
    }

    echo '</table>';
    logActivity('export_excel', 'Exported inventory in data to Excel (Period: ' . $from_date . ' to ' . $to_date . ')');

} elseif ($type === 'pdf') {

    // Path to dompdf (relative from current file location)
    require_once __DIR__ . '/../assets/vendor/dompdf/autoload.inc.php';

    $html = "<html><head><style>
        body { font-family: Arial, sans-serif; }
        h3 { text-align: center; margin-bottom: 5px; }
        .period { text-align: center; margin-bottom: 15px; font-size: 12px; }
        table { border-collapse: collapse; width: 100%; font-size: 9px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .no-data { text-align: center; padding: 20px; }
    </style></head><body>";

    $html .= '<h3>' . ($lang['inventory_in']) . '</h3>';
    $html .= '<div class="period">Period: ' . date('d/m/Y', strtotime($from_date)) . ' - ' . date('d/m/Y', strtotime($to_date)) . '</div>';
    $html .= '<table><tr>';
    $html .= '<th>' . ($lang['no']) . '</th>';
    $html .= '<th>' . ($lang['transaction_date']) . '</th>';
    $html .= '<th>' . ($lang['product_code']) . '</th>';
    $html .= '<th>' . ($lang['item_name']) . '</th>';
    $html .= '<th>' . ($lang['category']) . '</th>';
    $html .= '<th>' . ($lang['quantity']) . '</th>';
    $html .= '<th>' . ($lang['reference_number']) . '</th>';
    $html .= '<th>' . ($lang['notes']) . '</th>';
    $html .= '<th>' . ($lang['created_by']) . '</th>';
    $html .= '</tr>';

    if (count($data) > 0) {
        $no = 1;
        foreach ($data as $r) {
            $html .= '<tr>
                <td>' . $no++ . '</td>
                <td>' . date('d/m/Y H:i:s', strtotime($r['created_at'])) . '</td>
                <td>' . htmlspecialchars($r['product_code']) . '</td>
                <td>' . htmlspecialchars($r['item_name']) . '</td>
                <td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>
                <td>' . formatQty($r['quantity']) . ' ' . htmlspecialchars($r['symbol'] ?? '') . '</td>
                <td>' . htmlspecialchars($r['reference_number'] ?? '-') . '</td>
                <td>' . htmlspecialchars($r['notes'] ?? '-') . '</td>
                <td>' . htmlspecialchars($r['full_name'] ?? '-') . '</td>
            </tr>';
        }
    } else {
        $html .= '<tr><td colspan="9" class="no-data">' . ($lang['no_data']) . '</td></tr>';
    }

    $html .= '</table></body></html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'inventory_in_' . $from_date . '_to_' . $to_date . '.pdf';
    $dompdf->stream($filename, ["Attachment" => true]);

    logActivity('export_pdf', 'Exported inventory in data to PDF (Period: ' . $from_date . ' to ' . $to_date . ')');
}