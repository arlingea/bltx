<?php
// File: includes/export/export_inventory.php
// Moved from: inventory/inventory_export.php
// Requires: config.php (2 levels up), dompdf vendor
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    die('Access denied');
}
if (!checkPermission('inventory', 'can_access') && !checkPermission('inventory_data', 'can_export')) {
    die('Access denied');
}

$type = isset($_GET['type']) ? $_GET['type'] : 'excel';

// Get filter parameters
$filter_item = isset($_GET['filter_item']) ? $_GET['filter_item'] : '';
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$filter_supplier = isset($_GET['filter_supplier']) ? $_GET['filter_supplier'] : '';
$filter_location = isset($_GET['filter_location']) ? $_GET['filter_location'] : '';
$filter_min_stock = isset($_GET['filter_min_stock']) ? $_GET['filter_min_stock'] : '';

// Load language from session (respects active language selection)
if (!isset($LANG) || empty($LANG)) {
    $lang_key = $_SESSION['lang'] ?? getSetting('language', 'en');
    $LANG = loadLanguage($lang_key);
}

// Get currency symbol
$currency_symbol = getSetting('currency_symbol', 'Rp');

// Helper: format qty — hilangkan trailing zero (1.00→1, 1.10→1.1, 1.01→1.01)
function formatQty($qty) {
    $f   = floatval($qty);
    $str = number_format($f, 2, '.', '');
    $str = rtrim($str, '0');
    $str = rtrim($str, '.');
    return $str;
}

// Build query with filters
$query = "SELECT i.*,
          c.name as category_name,
          s.name as supplier_name,
          u.name as unit_name,
          u.symbol as unit_symbol,
          uqpu.symbol as qpu_unit_symbol,
          wl.location_code,
          wl.location_name,
          (i.quantity * i.unit_price) as total_value,
          i.created_by_name
          FROM inventory i
          LEFT JOIN categories c ON i.category_id = c.id
          LEFT JOIN suppliers s ON i.supplier_id = s.id
          LEFT JOIN units u ON i.unit_id = u.id
          LEFT JOIN warehouse_locations wl ON i.location_id = wl.id
          LEFT JOIN units uqpu ON i.qty_per_unit_unit_id = uqpu.id
          WHERE 1=1";

// Apply filters
if (!empty($filter_item)) {
    $query .= " AND i.item_name = '" . $conn->real_escape_string($filter_item) . "'";
}

if (!empty($filter_category)) {
    $query .= " AND i.category_id = " . intval($filter_category);
}

if (!empty($filter_supplier)) {
    $query .= " AND i.supplier_id = " . intval($filter_supplier);
}

if (!empty($filter_location)) {
    $query .= " AND i.location_id = " . intval($filter_location);
}

if (!empty($filter_min_stock)) {
    $query .= " AND i.quantity <= " . floatval($filter_min_stock);
}

// Order by item_name alphabetically
$query .= " ORDER BY i.item_name ASC";

$result = $conn->query($query);

if ($type == 'excel') {
    // Excel Export
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="inventory_report_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th, td { border: 1px solid #000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #4CAF50; color: white; font-weight: bold; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.bg-warning { background-color: #fff3cd; }';
    echo '.info { font-size: 9px; margin-bottom: 8px; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo '<h2>' . ($LANG['inventory_report']) . '</h2>';
    
    // Show filter info — selalu tampil, "All Data" jika tidak ada filter aktif
    echo '<p><strong>' . ($LANG['filter']) . ':</strong></p>';
    echo '<ul>';
    $excel_has_any = false;
    if (!empty($filter_item)) {
        echo '<li>' . ($LANG['item_name']) . ': ' . htmlspecialchars($filter_item) . '</li>';
        $excel_has_any = true;
    }
    if (!empty($filter_category)) {
        $cat_result = $conn->query("SELECT name FROM categories WHERE id = " . intval($filter_category));
        if ($cat_row = $cat_result->fetch_assoc()) {
            echo '<li>' . ($LANG['category']) . ': ' . htmlspecialchars($cat_row['name']) . '</li>';
            $excel_has_any = true;
        }
    }
    if (!empty($filter_supplier)) {
        $sup_result = $conn->query("SELECT name FROM suppliers WHERE id = " . intval($filter_supplier));
        if ($sup_row = $sup_result->fetch_assoc()) {
            echo '<li>' . ($LANG['supplier']) . ': ' . htmlspecialchars($sup_row['name']) . '</li>';
            $excel_has_any = true;
        }
    }
    if (!empty($filter_location)) {
        $loc_result = $conn->query("SELECT location_code, location_name FROM warehouse_locations WHERE id = " . intval($filter_location));
        if ($loc_row = $loc_result->fetch_assoc()) {
            echo '<li>' . ($LANG['location']) . ': ' . htmlspecialchars($loc_row['location_code']) . ' - ' . htmlspecialchars($loc_row['location_name']) . '</li>';
            $excel_has_any = true;
        }
    }
    if (!empty($filter_min_stock)) {
        echo '<li>' . ($LANG['min_stock']) . ': <= ' . number_format($filter_min_stock, 2) . '</li>';
        $excel_has_any = true;
    }
    if (!$excel_has_any) {
        echo '<li><em>All Data (no filter applied)</em></li>';
    }
    echo '</ul>';
    
    echo '<div class="info">Printed at: ' . date('d/m/Y H:i:s') . '</div>';
    
    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th>' . ($LANG['no']) . '</th>';
    echo '<th>' . ($LANG['product_code']) . '</th>';
    echo '<th>' . ($LANG['item_name']) . '</th>';
    echo '<th>' . ($LANG['brand']) . '</th>';
    echo '<th>' . ($LANG['category']) . '</th>';
    echo '<th>' . ($LANG['supplier']) . '</th>';
    echo '<th>' . ($LANG['location']) . '</th>';
    echo '<th>' . ($LANG['quantity']) . '</th>';
    echo '<th>' . ($LANG['unit_price']) . '</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $no = 1;
    $grand_total = 0;
    while ($row = $result->fetch_assoc()) {
        $low_stock_class = ($row['quantity'] <= $row['min_stock']) ? 'bg-warning' : '';
        $unit_sym = trim($row['unit_symbol'] ?? $row['unit_name'] ?? '');
        $qty_str  = formatQty($row['quantity']) . ($unit_sym ? ' ' . $unit_sym : '');
        // Build item name with qty_per_unit suffix
        $item_display = $row['item_name'];
        if (!empty($row['qty_per_unit']) && (float)$row['qty_per_unit'] > 0) {
            $qpu_val     = formatQty($row['qty_per_unit']);
            $qpu_sym_exp = trim($row['qpu_unit_symbol'] ?? '');
            $item_display .= ' (' . $qpu_val . ($qpu_sym_exp ? ' ' . $qpu_sym_exp : '') . ')';
        }
        echo '<tr class="' . $low_stock_class . '">';
        echo '<td class="text-center">' . $no++ . '</td>';
        echo '<td>' . htmlspecialchars($row['product_code']) . '</td>';
        echo '<td>' . htmlspecialchars($item_display) . '</td>';
        echo '<td>' . htmlspecialchars($row['brand'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['category_name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['supplier_name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['location_code'] ?? '-') . '</td>';
        echo '<td class="text-right">' . htmlspecialchars($qty_str) . '</td>';
        echo '<td class="text-right">' . number_format($row['unit_price'], 0, ',', '.') . '</td>';
        echo '</tr>';
        $grand_total += $row['total_value'];
    }
    
    echo '<tr style="background-color: #f0f0f0; font-weight: bold;">';
    echo '<td colspan="8" class="text-right">' . ($LANG['grand_total']) . ':</td>';
    echo '<td class="text-right">' . $currency_symbol . ' ' . number_format($grand_total, 0, ',', '.') . '</td>';
    echo '</tr>';
    
    echo '</tbody>';
    echo '</table>';
    echo '</body>';
    echo '</html>';
    
    // Log activity
    $filter_log = '';
    if (!empty($filter_item) || !empty($filter_category) || !empty($filter_supplier) || !empty($filter_location) || !empty($filter_min_stock)) {
        $filter_log = " with filters";
    }
    logActivity('export_excel', 'Exported inventory data to Excel' . $filter_log);
    
    } else if ($type == 'pdf') {

    if (ob_get_length()) {
        ob_end_clean();
    }

    require_once __DIR__ . '/../../assets/vendor/dompdf/autoload.inc.php';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    
    // Build HTML content
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 12mm 10mm 14mm 10mm; }
            body {
                font-family: DejaVu Sans, Arial, sans-serif;
                font-size: 9pt;
                margin: 0;
                padding: 0;
            }
            h2 {
                text-align: center;
                color: #333;
                margin-bottom: 5px;
                font-size: 13pt;
            }
            .info {
                font-size: 8pt;
                margin-bottom: 15px;
            }
            .filter-info {
                background-color: #f8f9fa;
                padding: 10px;
                margin-bottom: 15px;
                border: 1px solid #dee2e6;
                border-radius: 4px;
            }
            .filter-info strong {
                display: block;
                margin-bottom: 5px;
                font-size: 9pt;
            }
            .filter-info ul {
                margin: 5px 0;
                padding-left: 20px;
            }
            .filter-info li {
                font-size: 8pt;
                margin-bottom: 3px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            th, td {
                border: 1px solid #000;
                padding: 6px;
                text-align: left;
            }
            th {
                background-color: #4CAF50;
                color: white;
                font-weight: bold;
                text-align: center;
                font-size: 8pt;
                font-family: DejaVu Sans, Arial, sans-serif;
            }
            td {
                font-size: 7pt;
                font-family: DejaVu Sans, Arial, sans-serif;
            }
            .text-center {
                text-align: center;
            }
            .text-right {
                text-align: right;
            }
            .bg-warning {
                background-color: #fff3cd;
            }
            .total-row {
                background-color: #f0f0f0;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <h2>' . ($LANG['inventory_report']) . '</h2>';
    
    // Show filter info — selalu tampil, "All Data" jika tidak ada filter aktif
    $html .= '<div class="filter-info">';
    $html .= '<strong>' . ($LANG['filter']) . ':</strong>';
    $html .= '<ul>';
    $pdf_has_any = false;

    if (!empty($filter_item)) {
        $html .= '<li>' . ($LANG['item_name']) . ': ' . htmlspecialchars($filter_item) . '</li>';
        $pdf_has_any = true;
    }
    if (!empty($filter_category)) {
        $cat_result = $conn->query("SELECT name FROM categories WHERE id = " . intval($filter_category));
        if ($cat_row = $cat_result->fetch_assoc()) {
            $html .= '<li>' . ($LANG['category']) . ': ' . htmlspecialchars($cat_row['name']) . '</li>';
            $pdf_has_any = true;
        }
    }
    if (!empty($filter_supplier)) {
        $sup_result = $conn->query("SELECT name FROM suppliers WHERE id = " . intval($filter_supplier));
        if ($sup_row = $sup_result->fetch_assoc()) {
            $html .= '<li>' . ($LANG['supplier']) . ': ' . htmlspecialchars($sup_row['name']) . '</li>';
            $pdf_has_any = true;
        }
    }
    if (!empty($filter_location)) {
        $loc_result = $conn->query("SELECT location_code, location_name FROM warehouse_locations WHERE id = " . intval($filter_location));
        if ($loc_row = $loc_result->fetch_assoc()) {
            $html .= '<li>' . ($LANG['location']) . ': ' . htmlspecialchars($loc_row['location_code']) . ' - ' . htmlspecialchars($loc_row['location_name']) . '</li>';
            $pdf_has_any = true;
        }
    }
    if (!empty($filter_min_stock)) {
        $html .= '<li>' . ($LANG['min_stock']) . ': &le; ' . number_format($filter_min_stock, 2) . '</li>';
        $pdf_has_any = true;
    }
    if (!$pdf_has_any) {
        $html .= '<li><em>All Data (no filter applied)</em></li>';
    }

    $html .= '</ul>';
    $html .= '</div>';
    
    // Table
    $html .= '<table>
        <thead>
            <tr>
                <th>' . ($LANG['no']) . '</th>
                <th>' . ($LANG['product_code']) . '</th>
                <th>' . ($LANG['item_name']) . '</th>
                <th>' . ($LANG['brand']) . '</th>
                <th>' . ($LANG['category']) . '</th>
                <th>' . ($LANG['supplier']) . '</th>
                <th>' . ($LANG['location']) . '</th>
                <th>' . ($LANG['quantity']) . '</th>
                <th>' . ($LANG['unit_price']) . '</th>
            </tr>
        </thead>
        <tbody>';
    
    $no = 1;
    $grand_total = 0;
    
    while ($row = $result->fetch_assoc()) {
        $low_stock_class = ($row['quantity'] <= $row['min_stock']) ? 'bg-warning' : '';
        $unit_sym = trim($row['unit_symbol'] ?? $row['unit_name'] ?? '');
        $qty_str  = formatQty($row['quantity']) . ($unit_sym ? ' ' . $unit_sym : '');
        // Build item name with qty_per_unit suffix
        $item_display_pdf = $row['item_name'];
        if (!empty($row['qty_per_unit']) && (float)$row['qty_per_unit'] > 0) {
            $qpu_val_pdf     = formatQty($row['qty_per_unit']);
            $qpu_sym_exp_pdf = trim($row['qpu_unit_symbol'] ?? '');
            $item_display_pdf .= ' (' . $qpu_val_pdf . ($qpu_sym_exp_pdf ? ' ' . $qpu_sym_exp_pdf : '') . ')';
        }
        
        $html .= '<tr class="' . $low_stock_class . '">
            <td class="text-center">' . $no++ . '</td>
            <td>' . htmlspecialchars($row['product_code']) . '</td>
            <td>' . htmlspecialchars(mb_substr($item_display_pdf, 0, 35)) . '</td>
            <td>' . htmlspecialchars(substr($row['brand'] ?? '-', 0, 15)) . '</td>
            <td>' . htmlspecialchars(substr($row['category_name'] ?? '-', 0, 15)) . '</td>
            <td>' . htmlspecialchars(substr($row['supplier_name'] ?? '-', 0, 15)) . '</td>
            <td class="text-center">' . htmlspecialchars($row['location_code'] ?? '-') . '</td>
            <td class="text-right">' . htmlspecialchars($qty_str) . '</td>
            <td class="text-right">' . number_format($row['unit_price'], 0, ',', '.') . '</td>
        </tr>';
        
        $grand_total += $row['total_value'];
    }
    
    $html .= '<tr class="total-row">
            <td colspan="8" class="text-right">' . ($LANG['grand_total']) . ':</td>
            <td class="text-right">' . $currency_symbol . ' ' . number_format($grand_total, 0, ',', '.') . '</td>
        </tr>';
    
    $html .= '</tbody>
        </table>
    </body>
    </html>';
    
    // Load HTML to dompdf
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'landscape');
    
    // Render PDF
    $dompdf->render();
    
    // Footer via canvas — sama dengan export.php
    // @page margin kiri = kanan = 10mm, bottom = 14mm
    $canvas           = $dompdf->getCanvas();
    $font             = $dompdf->getFontMetrics()->getFont('Arial', 'normal');
    $pageW            = $canvas->get_width();
    $pageH            = $canvas->get_height();
    $margin_lr_pt     = 10 * (72 / 25.4);   // 10mm → pt ≈ 28.35pt = tepi kiri/kanan tabel
    $bottom_margin_pt = 14 * (72 / 25.4);   // 14mm → pt ≈ 39.69pt
    $y_footer         = $pageH - $bottom_margin_pt + 5; // tepat di bawah garis tabel terakhir

    // Kiri: "ARW Management System" — sejajar tepi kiri tabel
    $canvas->page_text($margin_lr_pt, $y_footer, "ARW Management System", $font, 7, [0, 0, 0]);

    // Center: "Printed at: dd/mm/yyyy hh:mm:ss" — center align sejajar footer
    $printed_at_text = "Printed at: " . date('d/m/Y H:i:s');
    $right_edge      = $pageW - $margin_lr_pt;
    $font_size       = 7;
    $canvas->page_script(function($pageNumber, $pageCount, $canvasObj, $fontMetrics)
        use ($font, $pageW, $margin_lr_pt, $right_edge, $y_footer, $font_size, $printed_at_text) {
            // Center: printed at
            $tw_center = $fontMetrics->getTextWidth($printed_at_text, $font, $font_size);
            $x_center  = ($pageW / 2) - ($tw_center / 2);
            $canvasObj->text($x_center, $y_footer, $printed_at_text, $font, $font_size, [0, 0, 0]);

            // Kanan: page number rata kanan sejajar tepi kanan tabel
            $text_page = "Page $pageNumber of $pageCount";
            $tw_right  = $fontMetrics->getTextWidth($text_page, $font, $font_size);
            $x_right   = $right_edge - $tw_right;
            $canvasObj->text($x_right, $y_footer, $text_page, $font, $font_size, [0, 0, 0]);
        }
    );

    // Output PDF
    $filename = 'inventory_report_' . date('Ymd_His') . '.pdf';
    $dompdf->stream($filename, array('Attachment' => 1));
    
    // Log activity
    $filter_log = '';
    if (!empty($filter_item) || !empty($filter_category) || !empty($filter_supplier) || !empty($filter_location) || !empty($filter_min_stock)) {
        $filter_log = " with filters";
    }
    logActivity('export_pdf', 'Exported inventory data to PDF' . $filter_log);
}

$conn->close();
exit;