<?php
// File: includes/export/backup_database.php
require_once __DIR__ . '/../config.php';

// Do not access directly — must be logged in dan punya izin 'settings_backup'
// (superadmin selalu full control; role lain mengikuti tabel `permissions`)
if (!isLoggedIn()) redirect(url('index.php'));
if (!isSuperAdmin() && !(function_exists('canAccessSettingsBackup') && canAccessSettingsBackup())) {
    redirect(url('dashboard.php'));
}

// ─────────────────────────────────────────────
// Backup file naming
// ─────────────────────────────────────────────
$backupName = 'backup_' . DB_NAME . '_' . date('Y-m-d_H-i-s');
$filename   = $backupName . '.sql';

// Header agar browser mendownload file sebagai .sql langsung
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// ─────────────────────────────────────────────
// SQL Header — phpMyAdmin-compatible
// ─────────────────────────────────────────────
echo "-- ARW Management System Database Backup\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- Database: " . DB_NAME . "\n";
echo "-- Server version: MariaDB/MySQL\n";
echo "-- PHP Version: " . PHP_VERSION . "\n\n";

echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
echo "START TRANSACTION;\n";
echo "SET time_zone = \"+00:00\";\n\n";

echo "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
echo "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
echo "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
echo "/*!40101 SET NAMES utf8mb4 */;\n\n";

// Matikan sementara foreign key checks agar proses import tabel berelasi tidak error
echo "SET FOREIGN_KEY_CHECKS = 0;\n";
echo "SET AUTOCOMMIT = 0;\n\n";

// ─────────────────────────────────────────────
// Discover all tables dynamically
// ─────────────────────────────────────────────
$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    $tables[] = $row[0];
}

// ─────────────────────────────────────────────
// Correct import order — respects FK dependencies
// ─────────────────────────────────────────────
$tableOrder = [
    // Base tables — no foreign keys
    'users',
    'categories',
    'suppliers',
    'units',
    'warehouse_locations',
    'payment_methods',
    'manual_payment_methods',
    'settings',
    'news',
    // HR: parent tables
    'religions',
    'departments',
    'positions',
    'employee_shifts',
    'overtime_settings',
    'payroll_periods',
    // HR: tables depending on parent HR tables
    'employees',
    'attendance',
    'cash_advances',
    'employee_allowances',
    'employee_salary_components',
    'leave_requests',
    'payroll',
    // Inventory
    'inventory',
    'inventory_in',
    'inventory_out',
    // Purchases
    'purchases',
    'purchase_items',
    'returns',
    // Manual daily reports — parent first, then children
    'manual_daily_reports',
    'manual_daily_report_items',
    'manual_dreport_optional_items',
    // Access & logs (depends on users)
    'permissions',
    'activity_logs',
];

// Sort tables per $tableOrder; any new/unlisted tables will be appended automatically at the end safely
usort($tables, function ($a, $b) use ($tableOrder) {
    $posA = array_search($a, $tableOrder);
    $posB = array_search($b, $tableOrder);
    
    if ($posA !== false && $posB !== false) return $posA - $posB;
    if ($posA !== false) return -1;
    if ($posB !== false) return 1;
    
    // Jika tabel baru tidak ada di daftar $tableOrder, urutkan secara alfabetis
    return strcmp($a, $b);
});

// ─────────────────────────────────────────────
// Dump each table: structure + data
// ─────────────────────────────────────────────
foreach ($tables as $table) {
    echo "-- --------------------------------------------------------\n";
    echo "-- Table structure for table `{$table}`\n";
    echo "-- --------------------------------------------------------\n\n";

    echo "DROP TABLE IF EXISTS `{$table}`;\n";

    $createResult = $conn->query("SHOW CREATE TABLE `{$table}`");
    if (!$createResult) continue;
    $createRow = $createResult->fetch_row();
    
    echo $createRow[1] . ";\n\n";

    // ── Data dump ──
    $dataResult = $conn->query("SELECT * FROM `{$table}`");
    if (!$dataResult) continue;

    $numFields  = $dataResult->field_count;
    $fieldNames = [];
    $fieldMeta  = $dataResult->fetch_fields();
    foreach ($fieldMeta as $field) {
        $fieldNames[] = '`' . $field->name . '`';
    }
    $fieldList  = implode(', ', $fieldNames);

    $hasData    = false;
    $batchRows  = [];
    $batchSize  = 100; // Batch rows untuk mengoptimalkan performa insert

    while ($row = $dataResult->fetch_row()) {
        if (!$hasData) {
            echo "-- Dumping data for table `{$table}`\n\n";
            $hasData = true;
        }

        $values = [];
        for ($j = 0; $j < $numFields; $j++) {
            if ($row[$j] === null) {
                $values[] = 'NULL';
            } else {
                $escaped   = $conn->real_escape_string($row[$j]);
                $escaped   = str_replace("\n", "\\n", $escaped);
                $escaped   = str_replace("\r", "\\r", $escaped);
                $values[]  = "'" . $escaped . "'";
            }
        }
        $batchRows[] = '(' . implode(', ', $values) . ')';

        // Flush batch jika sudah mencapai batas $batchSize
        if (count($batchRows) >= $batchSize) {
            echo "INSERT INTO `{$table}` ({$fieldList}) VALUES\n";
            echo implode(",\n", $batchRows) . ";\n";
            $batchRows = [];
        }
    }

    // Flush sisa baris terakhir
    if (!empty($batchRows)) {
        echo "INSERT INTO `{$table}` ({$fieldList}) VALUES\n";
        echo implode(",\n", $batchRows) . ";\n";
    }

    echo "\n";
}

// ─────────────────────────────────────────────
// SQL Footer — Re-enable checks & commit
// ─────────────────────────────────────────────
echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "COMMIT;\n";

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
exit;