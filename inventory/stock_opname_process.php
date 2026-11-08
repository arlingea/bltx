<?php
/**
 * stock_opname_process.php
 * File: public_html/bltx/inventory/stock_opname_process.php
 *
 * Endpoint proses (JSON) untuk seluruh aksi Stock Opname. Semua perubahan
 * data lewat sini — POST-only, CSRF wajib, permission dicek ulang di server
 * untuk setiap aksi (tidak pernah percaya tombol yang disembunyikan di UI).
 *
 * Pola meniru invHandleProcess() di includes/inventory_function.php:
 * header JSON, cek login, switch($_POST['action']), permission -> CSRF ->
 * validasi input -> panggil fungsi -> echo json_encode(...).
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once BASE_PATH . '/includes/global_permission.php';
require_once BASE_PATH . '/includes/inventory_function.php';

requireStockOpnameAccess(); // 403 + redirect kalau modul sendiri tidak bisa diakses sama sekali

$user_id = (int)($_SESSION['user_id'] ?? 0);
$action  = $_POST['action'] ?? '';

switch ($action) {

    /* ——— CREATE (New Stock Opname: pilih lokasi -> snapshot) ——— */
    case 'create':
        if (!canCreateStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $location_id = (int)($_POST['location_id'] ?? 0);
        $notes       = trim((string)($_POST['notes'] ?? ''));
        if ($location_id <= 0) {
            echo json_encode(['success' => false, 'message' => $LANG['so_invalid_location']]);
            exit;
        }
        echo json_encode(soCreateOpname($conn, $location_id, $notes, $user_id));
        break;

    /* ——— SAVE COUNTING (bisa dipanggil berkali-kali selama status=counting) ——— */
    case 'save_counting':
        if (!canUpdateStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $opname_id = (int)($_POST['id'] ?? 0);
        $itemsRaw  = $_POST['items'] ?? '[]';
        $items     = json_decode((string)$itemsRaw, true);
        if ($opname_id <= 0 || !is_array($items)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        echo json_encode(soSaveCounting($conn, $opname_id, $items, $user_id));
        break;

    /* ——— START COUNTING (draft -> counting) ——— */
    case 'start_counting':
        if (!canUpdateStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        echo json_encode(soStartCounting($conn, $id, $user_id));
        break;

    /* ——— SUBMIT FOR REVIEW (counting -> review) ——— */
    case 'submit_review':
        if (!canUpdateStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        echo json_encode(soSubmitReview($conn, $id, $user_id));
        break;

    /* ——— APPROVE (review -> approved) ——— */
    case 'approve':
        if (!canApproveStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        echo json_encode(soApprove($conn, $id, $user_id));
        break;

    /* ——— POST (approved -> posted; update inventory.quantity sesuai hasil hitung fisik) ——— */
    case 'post':
        if (!canPostStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        echo json_encode(soPost($conn, $id, $user_id));
        break;

    /* ——— CANCEL (dari draft/counting/review/approved -> cancelled) ——— */
    case 'cancel':
        if (!canCancelStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        echo json_encode(soCancel($conn, $id, $user_id, $reason));
        break;

    /* ——— DELETE (draft saja) ——— */
    case 'delete':
        if (!canDeleteStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        echo json_encode(soDeleteDraft($conn, $id, $user_id));
        break;

    /* ——— UPDATE NOTES (draft/counting saja) ——— */
    case 'update_notes':
        if (!canUpdateStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        if (!invValidateCsrf($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        echo json_encode(soUpdateNotes($conn, $id, $notes, $user_id));
        break;

    /* ——— GET ITEMS (refresh tabel counting via AJAX tanpa reload halaman) ——— */
    case 'get_items':
        if (!canReadStockOpname()) {
            echo json_encode(['success' => false, 'message' => 'No permission']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        $result = soGetOpnameItems($conn, $id);
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
exit;