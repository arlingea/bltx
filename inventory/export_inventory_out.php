<?php
// Adjust path to config.php (one level up from inventory folder)
require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) redirect('index.php');

// Multi-language support — pola sama dengan export_inventory_in.php.
// Sebelumnya berkas ini TIDAK memuat bahasa sama sekali, sehingga seluruh
// judul kolom pada berkas Excel/PDF hasil ekspor selalu berbahasa Inggris
// walau pengguna memilih Indonesia atau Mandarin.
$current_lang = $_SESSION['language'] ?? 'en';
$lang_file    = __DIR__ . "/../languages/{$current_lang}.php";
if (file_exists($lang_file)) {
    $lang = require $lang_file;
} elseif (file_exists(__DIR__ . '/../languages/en.php')) {
    $lang = require __DIR__ . '/../languages/en.php';
} else {
    $lang = [];
}
$L = function (string $k) use ($lang): string {
    return htmlspecialchars((string)($lang[$k] ?? $k), ENT_QUOTES, 'UTF-8');
};

$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'excel';
$from_date = !empty($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = !empty($_GET['to_date']) ? $_GET['to_date'] : '';
$item_id = !empty($_GET['item_id']) ? intval($_GET['item_id']) : '';
$taken_by = !empty($_GET['taken_by']) ? intval($_GET['taken_by']) : '';

// Build WHERE clause dynamically
$where_conditions = [];
if ($from_date && $to_date) {
    $where_conditions[] = "DATE(io.created_at) BETWEEN '$from_date' AND '$to_date'";
}
if ($item_id) {
    $where_conditions[] = "io.inventory_id = $item_id";
}
if ($taken_by) {
    $where_conditions[] = "io.taken_by = $taken_by";
}

$where_clause = '';
if (count($where_conditions) > 0) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get inventory out data with filters
$sql = "SELECT 
            MIN(io.transaction_date) as transaction_date,
            io.reference_number,
            GROUP_CONCAT(DISTINCT i.product_code ORDER BY i.product_code SEPARATOR ', ') as product_code,
            GROUP_CONCAT(DISTINCT i.item_name ORDER BY i.item_name SEPARATOR ', ') as item_name,
            GROUP_CONCAT(CONCAT(FORMAT(io.quantity, 2), ' ', IFNULL(u.symbol, '')) ORDER BY io.id SEPARATOR ', ') as quantity,
            MIN(io.taken_by_name) as taker_name,
            MIN(io.notes) as notes,
            MIN(io.created_at) as created_at
        FROM inventory_out io
        JOIN inventory i ON io.inventory_id = i.id
        LEFT JOIN units u ON io.unit_id = u.id
        $where_clause
        GROUP BY io.reference_number
        ORDER BY MIN(io.created_at) DESC";

$result = $conn->query($sql);
$data = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

// Get filter description for report header
$filter_description = [];
if ($from_date && $to_date) {
    $filter_description[] = 'Period: ' . date('d/m/Y', strtotime($from_date)) . ' - ' . date('d/m/Y', strtotime($to_date));
} else {
    $filter_description[] = 'Period: All Time';
}

if ($item_id) {
    $item_sql = "SELECT product_code, item_name FROM inventory WHERE id = $item_id";
    $item_result = $conn->query($item_sql);
    if ($item_result && $item_result->num_rows > 0) {
        $item_data = $item_result->fetch_assoc();
        $filter_description[] = 'Item: ' . htmlspecialchars($item_data['product_code']) . ' - ' . htmlspecialchars($item_data['item_name']);
    }
} else {
    $filter_description[] = 'Item: All';
}

if ($taken_by) {
    $user_sql = "SELECT full_name FROM users WHERE id = $taken_by";
    $user_result = $conn->query($user_sql);
    if ($user_result && $user_result->num_rows > 0) {
        $user_data = $user_result->fetch_assoc();
        $filter_description[] = 'Taken By: ' . htmlspecialchars($user_data['full_name']);
    }
} else {
    $filter_description[] = 'Taken By: All';
}

$filter_text = implode(' | ', $filter_description);

// --- GENERATE FILE ---
if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Cache-Control: max-age=0');
    $filename = 'inventory_out_' . date('Y-m-d_His') . '.xls';
    header('Content-Disposition: attachment;filename="' . $filename . '"');

    echo '<table border="1">';
    echo '<tr><th colspan="8" style="text-align:center; font-weight:bold; font-size:16px;">' . $L('inventory_out_report') . '</th></tr>';
    echo '<tr><th colspan="8" style="text-align:center; font-size:12px;">' . $filter_text . '</th></tr>';
    echo '<tr><th>' . $L('no') . '</th><th>' . $L('transaction_date') . '</th><th>' . $L('product_code') . '</th><th>' . $L('item_name') . '</th>';
    echo '<th>' . $L('quantity') . '</th><th>' . $L('taken_by') . '</th><th>' . $L('reference_number') . '</th><th>' . $L('notes') . '</th></tr>';
    
    if (count($data) > 0) {
        $no = 1;
        foreach ($data as $r) {
            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            echo '<td>' . date('d/m/Y H:i:s', strtotime($r['created_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($r['product_code']) . '</td>';
            echo '<td>' . htmlspecialchars($r['item_name']) . '</td>';
            echo '<td>' . htmlspecialchars($r['quantity']) . '</td>';
            echo '<td>' . htmlspecialchars($r['taker_name'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['reference_number'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['notes'] ?? '-') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="8" style="text-align:center;">No data available for the selected filter</td></tr>';
    }

    echo '</table>';
    
    // Log activity
    $log_desc = 'Exported inventory out data to Excel';
    if ($from_date && $to_date) {
        $log_desc .= ' (Period: ' . $from_date . ' to ' . $to_date . ')';
    }
    if ($item_id) {
        $log_desc .= ' (Item ID: ' . $item_id . ')';
    }
    if ($taken_by) {
        $log_desc .= ' (Taken By: User ID ' . $taken_by . ')';
    }
    logActivity('export_excel', $log_desc);

} elseif ($type === 'pdf') {

    // Path to dompdf (relative from current file location)
    require_once __DIR__ . '/../assets/vendor/dompdf/autoload.inc.php';

    $html = "<html><head><style>
        body { font-family: Arial, sans-serif; }
        h3 { text-align: center; margin-bottom: 5px; }
        .filter-info { text-align: center; margin-bottom: 15px; font-size: 10px; }
        table { border-collapse: collapse; width: 100%; font-size: 9px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .no-data { text-align: center; padding: 20px; }
    </style></head><body>";

    $html .= '<h3>Inventory Out Report</h3>';
    $html .= '<div class="filter-info">' . $filter_text . '</div>';
    $html .= '<table><tr>';
    $html .= '<th>' . $L('no') . '</th><th>' . $L('transaction_date') . '</th><th>' . $L('product_code') . '</th><th>' . $L('item_name') . '</th>';
    $html .= '<th>' . $L('quantity') . '</th><th>' . $L('taken_by') . '</th><th>' . $L('reference_number') . '</th><th>' . $L('notes') . '</th></tr>';

    if (count($data) > 0) {
        $no = 1;
        foreach ($data as $r) {
            $html .= '<tr>
                <td>' . $no++ . '</td>
                <td>' . date('d/m/Y H:i:s', strtotime($r['created_at'])) . '</td>
                <td>' . htmlspecialchars($r['product_code']) . '</td>
                <td>' . htmlspecialchars($r['item_name']) . '</td>
                <td>' . htmlspecialchars($r['quantity']) . '</td>
                <td>' . htmlspecialchars($r['taker_name'] ?? '-') . '</td>
                <td>' . htmlspecialchars($r['reference_number'] ?? '-') . '</td>
                <td>' . htmlspecialchars($r['notes'] ?? '-') . '</td>
            </tr>';
        }
    } else {
        $html .= '<tr><td colspan="8" class="no-data">No data available for the selected filter</td></tr>';
    }

    $html .= '</table></body></html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'inventory_out_' . date('Y-m-d_His') . '.pdf';
    $dompdf->stream($filename, ["Attachment" => true]);

    // Log activity
    $log_desc = 'Exported inventory out data to PDF';
    if ($from_date && $to_date) {
        $log_desc .= ' (Period: ' . $from_date . ' to ' . $to_date . ')';
    }
    if ($item_id) {
        $log_desc .= ' (Item ID: ' . $item_id . ')';
    }
    if ($taken_by) {
        $log_desc .= ' (Taken By: User ID ' . $taken_by . ')';
    }
    logActivity('export_pdf', $log_desc);
}