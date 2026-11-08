<?php

require_once '../includes/config.php';
require_once '../includes/global_permission.php';


header('Content-Type: application/json');

/* TAHAP 4 — TIGA BARIS error_log DIHAPUS KARENA MEMBOCORKAN DATA.

   Yang dihapus:
     error_log("POST data: "    . print_r($_POST, true));
     error_log("SESSION data: " . print_r($_SESSION, true));

   Keduanya membuang ISI PENUH $_POST dan $_SESSION ke berkas log setiap kali
   halaman Warehouse Location dipakai. $_SESSION memuat token CSRF, user_id,
   role, dan nama pengguna. Token CSRF yang tertulis di berkas log
   MEMBATALKAN gunanya sebagai perlindungan: siapa pun yang bisa membaca log
   bisa memakainya. Sejak Tahap 0 log diarahkan ke /logs/php-error.log, jadi
   isinya kini terkumpul di satu berkas yang mudah ditemukan.

   Baris penanda "=== PROCESS LOCATION START ===" ikut dihapus karena tanpa
   kedua baris di bawahnya ia tidak menjelaskan apa-apa.

   TIGA PULUH LIMA error_log LAIN DI BERKAS INI SENGAJA DIBIARKAN: isinya
   hanya nama aksi, id, dan pesan kesalahan database — berguna untuk
   menelusuri masalah, dan tidak memuat data rahasia. Kalau Anda ingin
   berkas log lebih ringkas, itu pembersihan tersendiri yang perlu Anda
   putuskan, bukan bagian dari migrasi UI. */

/* ===============================
   AUTH CHECK
=============================== */
if (!isset($_SESSION['user_id'])) {
    error_log("ERROR: Session not found");
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please login again.'
    ]);
    exit;
}

$role = $_SESSION['role'] ?? '';
error_log("User role: " . $role);

$permissions = getPermissions($role, 'warehouse_location');
// error_log array permission dihapus — isinya seluruh matriks izin pengguna.

/* ===============================
   ACTION
=============================== */
$action = $_POST['action'] ?? '';
error_log("Action: " . $action);

if ($action === '') {
    error_log("ERROR: No action provided");
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action. No action specified.'
    ]);
    exit;
}

/* ===============================
   ADD
=============================== */
if ($action === 'add') {
    error_log("=== ADD ACTION ===");
    
    if (empty($permissions['can_create'])) {
        error_log("ERROR: No create permission");
        echo json_encode([
            'success' => false, 
            'message' => 'You do not have permission to create locations'
        ]);
        exit;
    }

    $code = trim($_POST['location_code'] ?? '');
    $name = trim($_POST['location_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'active' ? 1 : 0;

    error_log("Add data - Code: $code, Name: $name, Status: $status");

    if ($code === '' || $name === '') {
        error_log("ERROR: Required fields missing");
        echo json_encode([
            'success' => false, 
            'message' => 'Location code and name are required'
        ]);
        exit;
    }

    // Check if location code already exists - TANPA get_result()
    $check_stmt = $conn->prepare("SELECT id FROM warehouse_locations WHERE location_code = ?");
    if (!$check_stmt) {
        error_log("ERROR: Prepare check failed - " . $conn->error);
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    
    $check_stmt->bind_param("s", $code);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows > 0) {
        error_log("ERROR: Location code already exists");
        $check_stmt->close();
        echo json_encode([
            'success' => false, 
            'message' => 'Location code already exists'
        ]);
        exit;
    }
    $check_stmt->close();

    // Insert new location
    $stmt = $conn->prepare("
        INSERT INTO warehouse_locations 
        (location_code, location_name, description, is_active, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    
    if (!$stmt) {
        error_log("ERROR: Prepare failed - " . $conn->error);
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    
    $stmt->bind_param("sssi", $code, $name, $desc, $status);

    if ($stmt->execute()) {
        $insert_id = $stmt->insert_id;
        error_log("SUCCESS: Location added with ID " . $insert_id);
        $stmt->close();
        echo json_encode([
            'success' => true, 
            'message' => 'Location added successfully',
            'id' => $insert_id
        ]);
    } else {
        error_log("ERROR: Execute failed - " . $stmt->error);
        $stmt->close();
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to add location: ' . $stmt->error
        ]);
    }
    exit;
}

/* ===============================
   EDIT
=============================== */
if ($action === 'edit') {
    error_log("=== EDIT ACTION ===");
    
    if (empty($permissions['can_update'])) {
        error_log("ERROR: No update permission");
        echo json_encode([
            'success' => false, 
            'message' => 'You do not have permission to update locations'
        ]);
        exit;
    }

    $id   = (int) ($_POST['location_id'] ?? 0);
    $code = trim($_POST['location_code'] ?? '');
    $name = trim($_POST['location_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'active' ? 1 : 0;

    error_log("Edit data - ID: $id, Code: $code, Name: $name, Status: $status");

    if ($id <= 0 || $code === '' || $name === '') {
        error_log("ERROR: Invalid data for edit");
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid data. ID, code and name are required'
        ]);
        exit;
    }

    // Check if location exists - TANPA get_result()
    $check_stmt = $conn->prepare("SELECT id FROM warehouse_locations WHERE id = ?");
    if (!$check_stmt) {
        error_log("ERROR: Prepare check failed - " . $conn->error);
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows === 0) {
        error_log("ERROR: Location not found");
        $check_stmt->close();
        echo json_encode([
            'success' => false, 
            'message' => 'Location not found'
        ]);
        exit;
    }
    $check_stmt->close();

    // Check if location code already exists for other records - TANPA get_result()
    $check_stmt2 = $conn->prepare("SELECT id FROM warehouse_locations WHERE location_code = ? AND id != ?");
    if (!$check_stmt2) {
        error_log("ERROR: Prepare check2 failed - " . $conn->error);
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    
    $check_stmt2->bind_param("si", $code, $id);
    $check_stmt2->execute();
    $check_stmt2->store_result();
    
    if ($check_stmt2->num_rows > 0) {
        error_log("ERROR: Location code already exists for another record");
        $check_stmt2->close();
        echo json_encode([
            'success' => false, 
            'message' => 'Location code already exists for another location'
        ]);
        exit;
    }
    $check_stmt2->close();

    // Update location
    $stmt = $conn->prepare("
        UPDATE warehouse_locations
        SET location_code=?, location_name=?, description=?, is_active=?
        WHERE id=?
    ");
    
    if (!$stmt) {
        error_log("ERROR: Prepare failed - " . $conn->error);
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    
    $stmt->bind_param("sssii", $code, $name, $desc, $status, $id);

    if ($stmt->execute()) {
        error_log("SUCCESS: Location updated");
        $stmt->close();
        echo json_encode([
            'success' => true, 
            'message' => 'Location updated successfully'
        ]);
    } else {
        error_log("ERROR: Execute failed - " . $stmt->error);
        $stmt->close();
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to update location: ' . $stmt->error
        ]);
    }
    exit;
}

/* ===============================
   DELETE
=============================== */
if ($action === 'delete') {
    error_log("=== DELETE ACTION ===");
    
    if (empty($permissions['can_delete'])) {
        error_log("ERROR: No delete permission");
        echo json_encode([
            'success' => false, 
            'message' => 'You do not have permission to delete locations'
        ]);
        exit;
    }

    $id = (int) ($_POST['location_id'] ?? 0);
    error_log("Delete ID: " . $id);

    if ($id <= 0) {
        error_log("ERROR: Invalid ID for delete");
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid location ID'
        ]);
        exit;
    }

    // Check if location exists - TANPA get_result()
    $check_stmt = $conn->prepare("SELECT id FROM warehouse_locations WHERE id = ?");
    if (!$check_stmt) {
        error_log("ERROR: Prepare check failed - " . $conn->error);
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows === 0) {
        error_log("ERROR: Location not found for delete");
        $check_stmt->close();
        echo json_encode([
            'success' => false, 
            'message' => 'Location not found'
        ]);
        exit;
    }
    $check_stmt->close();

    // Delete location
    $stmt = $conn->prepare("DELETE FROM warehouse_locations WHERE id=?");
    
    if (!$stmt) {
        error_log("ERROR: Prepare failed - " . $conn->error);
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $conn->error
        ]);
        exit;
    }
    
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        error_log("SUCCESS: Location deleted");
        $stmt->close();
        echo json_encode([
            'success' => true, 
            'message' => 'Location deleted successfully'
        ]);
    } else {
        error_log("ERROR: Execute failed - " . $stmt->error);
        $stmt->close();
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to delete location: ' . $stmt->error
        ]);
    }
    exit;
}

/* ===============================
   FALLBACK
=============================== */
error_log("ERROR: Unknown action - " . $action);
echo json_encode([
    'success' => false,
    'message' => 'Unknown action: ' . $action
]);
exit;