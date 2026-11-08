<?php
/*
 * Take Items Module
 * Version: 2.1.0
 * Integrated with header.php & footer.php of the main system.
 * Login & permission use the main session (index.php).
 * Functions are loaded from includes/take_items_functions.php.
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once '../includes/config.php';

// ============================================================================
// PERMISSION CHECK — use main session, not take_user_id
// ============================================================================
if (!isLoggedIn()) {
    redirect(url('index.php'));
}

// Seluruh keputusan izin diambil dari includes/global_permission.php.
// requireTakeItemsAccess() menegakkan DUA lapis sekaligus — gerbang induk
// modul 'inventory' dan izin modul 'take_items' — dan membacanya LANGSUNG
// dari tabel `permissions`, bukan dari cache $_SESSION['_perms'] yang hanya
// diisi saat login. Superadmin selalu lolos di dalam _dbCan().
// TIDAK ada modul permission baru: 'take_items' sudah ada sebelumnya.
require_once BASE_PATH . '/includes/global_permission.php';
requireTakeItemsAccess();

// ============================================================================
// LOAD FUNCTIONS LIBRARY
// ============================================================================
require_once BASE_PATH . '/includes/take_items_functions.php';

// ============================================================================
// LANGUAGE HANDLING
// Default language: English (en)
// Language change is handled by header.php via GET ?lang=
// We mirror header.php: read from $_SESSION['lang']
// ============================================================================
// Bahasa default sistem adalah English (en); nilainya ditetapkan di
// header.php dan disimpan di $_SESSION['lang']. TANPA fallback language:
// berkas bahasa yang aktif dipakai apa adanya, dan seluruh kunci yang
// dipakai halaman ini tersedia lengkap di ketiga berkas bahasa
// (en.php, id.php, ch.php) sehingga tidak ada string yang kosong.
$current_lang = $_SESSION['lang'] ?? 'en';
$lang         = loadLanguage($current_lang);
$LANG         = $lang;

// ============================================================================
// INITIALIZE CART SESSION
// ============================================================================
if (!isset($_SESSION['take_cart'])) {
    $_SESSION['take_cart'] = [];
}

// ============================================================================
// AJAX DISPATCH — must run before any HTML output
// ============================================================================
if (isset($_POST['ajax'])) {
    ti_dispatch_ajax();
    // ti_dispatch_ajax() calls exit internally; if we reach here, action was unknown
    ti_json(false, null, 'Unknown action');
}

// ============================================================================
// PREPARE PAGE VARIABLES
// ============================================================================
$page_title  = $lang['take_item'];
$system_name = getSetting('system_title', APP_NAME);
$currency    = getSetting('currency', 'IDR');

// ============================================================================
// GET NEWS CONTENT (if is_active = 1) — used by header.php
// ============================================================================
$news_content = '';
$stmt = $conn->prepare("SELECT content FROM news WHERE is_active = 1 LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result($news_content);
    $stmt->fetch();
    $stmt->close();
}

// Clear buffer before HTML output
ob_end_clean();

// Include system header (renders <head>, navbar, sidebar)
include BASE_PATH . '/includes/header.php';
?>
    <style>
        :root {
            --primary-color: #28a745;
            --secondary-color: #6c757d;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
        }

        /* ========== PAGE HEADER ========== */
        .page-header {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e9ecef;
        }

        .page-header h5 {
            margin: 0;
            font-weight: 700;
            font-size: 1.15rem;
            color: #1e2a38;
        }

        .page-header p {
            margin: 0;
            font-size: 0.82rem;
            color: #6c757d;
        }

        /* ========== CARD SECTIONS ========== */
        .scanner-container,
        .manual-input,
        .cart-section {
            margin-bottom: 1rem;
        }

        .scanner-container:last-child,
        .manual-input:last-child,
        .cart-section:last-child {
            margin-bottom: 0;
        }

        /* ========== READER ========== */
        #reader {
            border-radius: 8px;
            overflow: hidden;
            max-width: 100%;
        }

        /* ========== CART ITEM ========== */
        .cart-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            border: 1px solid #e9ecef;
        }

        .cart-item-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            background: #fff;
        }

        .cart-item-image-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
        }

        /* ========== QTY CONTROLS ========== */
        .qty-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .qty-input {
            width: 70px;
            text-align: center;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 0.25rem;
            font-weight: 600;
        }

        .qty-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(40,167,69,.25);
        }

        .btn-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        /* ========== NAV TABS ========== */
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
        }

        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 0.75rem 1rem;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background: transparent;
        }

        /* ========== LOADING OVERLAY ========== */
        .loading-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-content {
            background: #fff;
            padding: 2rem;
            border-radius: 12px;
            text-align: center;
        }

        /* ========== TOAST MELAYANG ==========
           Gaya diseragamkan dengan notifikasi melayang di
           purchases/goods_receipt.php: position:fixed di pojok kanan atas
           viewport, memakai komponen .alert Bootstrap + ikon Bootstrap,
           bayangan shadow-lg, dan menghilang sendiri dengan transisi opacity.
           Notifikasi TIDAK mendorong konten halaman sedikit pun. */
        .bltx-toast-stack {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1090;
            min-width: 280px;
            max-width: 90vw;
            display: flex;
            flex-direction: column;
            gap: .5rem;
            pointer-events: none;
        }

        .bltx-toast-stack .alert {
            margin-bottom: 0;
            transition: opacity .35s ease;
            pointer-events: auto;
        }

        @media (max-width: 575.98px) {
            .bltx-toast-stack {
                left: .75rem;
                right: .75rem;
                min-width: 0;
                max-width: none;
            }
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 576px) {
            .cart-item { font-size: 0.9rem; }
            .qty-input { width: 60px; }

            #historyTable          { font-size: 0.85rem; }
            #historyTable thead th { padding: 0.5rem 0.3rem; font-size: 0.75rem; }
            #historyTable tbody td { padding: 0.5rem 0.3rem; }

            .page-header h5 { font-size: 1rem; }
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    </style>

<div class="container-fluid p-0">

    <!-- ======================================================
         PAGE HEADER — Title + Icon
    ====================================================== -->
    <div class="page-title-bar">
        <h2 class="page-title-text">
            <i class="bi bi-box-arrow-up"></i>
            <?php echo htmlspecialchars($lang['take_item']); ?>
        </h2>
    </div>

    <!-- ======================================================
         SCANNER SECTION
    ====================================================== -->
    <div class="card scanner-container">
        <div class="card-header">
            <div class="card-title">
                <i class="bi bi-qr-code-scan"></i>
                <?php echo htmlspecialchars($lang['scan_product']); ?>
            </div>
            <div class="card-tools">
            <button class="btn btn-sm btn-primary" onclick="toggleScanner()">
                <i class="bi bi-camera" id="scannerIcon"></i>
                <span id="scannerText"><?php echo htmlspecialchars($lang['scanner_active']); ?></span>
            </button>
            </div>
        </div>
        <div id="reader" style="display:none;"></div>
    </div>

    <!-- ======================================================
         MANUAL INPUT
    ====================================================== -->
    <div class="card manual-input">
        <div class="card-body">
        <form id="manualForm">
            <div class="input-group">
                <input type="text" id="manualCode" class="form-control"
                       placeholder="<?php echo htmlspecialchars($lang['enter_code']); ?>">
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-plus-circle"></i>
                    <?php echo htmlspecialchars($lang['add_to_cart']); ?>
                </button>
            </div>
        </form>
        </div>
    </div>

    <!-- ======================================================
         CART SECTION
    ====================================================== -->
    <div class="card cart-section">
        <div class="card-header">
            <div class="card-title">
                <i class="bi bi-cart3"></i>
                <?php echo htmlspecialchars($lang['cart']); ?>
                <span>(<span id="cartCount">0</span>)</span>
            </div>
            <div class="card-tools">
            <button class="btn btn-sm btn-danger" onclick="clearCart()">
                <i class="bi bi-trash"></i>
                <?php echo htmlspecialchars($lang['clear']); ?>
            </button>
            </div>
        </div>
        <div class="card-body">

        <div id="cartItems">
            <div class="text-center text-muted py-4">
                <i class="bi bi-cart-x" style="font-size:2rem;"></i>
                <p class="mt-2"><?php echo htmlspecialchars($lang['cart_empty']); ?></p>
            </div>
        </div>

        <div id="cartActions" style="display:none;">
            <hr>
            <div class="mb-3">
                <label class="form-label">
                    <?php echo htmlspecialchars($lang['reference']); ?>
                    <span class="text-danger">*</span>
                </label>
                <input type="text" id="reference" class="form-control" readonly>
            </div>

            <div class="mb-3">
                <label class="form-label"><?php echo htmlspecialchars($lang['notes']); ?></label>
                <textarea id="notes" class="form-control" rows="3"></textarea>
            </div>

            <button class="btn btn-success btn-sm w-100" id="submitBtn"
                    onclick="showConfirmModal()"
                    <?php echo !canCreateTakeItems() ? 'disabled title="' . htmlspecialchars($lang['no_permission']) . '"' : ''; ?>>
                <i class="bi bi-check-circle"></i>
                <?php echo htmlspecialchars($lang['complete']); ?>
            </button>
        </div>
        </div>
    </div>

    <!-- ======================================================
         HISTORY SECTION
    ====================================================== -->
    <div class="card cart-section">
        <div class="card-header">
            <div class="card-title">
            <i class="bi bi-clock-history"></i>
            <?php echo htmlspecialchars($lang['history']); ?>
        </div>
        </div>
        <div class="card-body">
        <div class="table-responsive">
            <table id="historyTable" class="table table-sm table-striped table-hover" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th><?php echo htmlspecialchars($lang['date']);      ?></th>
                        <th><?php echo htmlspecialchars($lang['item']);      ?></th>
                        <th><?php echo htmlspecialchars($lang['qty']);       ?></th>
                        <th><?php echo htmlspecialchars($lang['reference']); ?></th>
                        <th><?php echo htmlspecialchars($lang['notes']);     ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        </div>
    </div>

    <!-- ======================================================
         CONFIRM MODAL
    ====================================================== -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($lang['confirm']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>
                        <strong><?php echo htmlspecialchars($lang['confirm_items_message']); ?></strong>
                    </p>
                    <div id="confirmItemsList"></div>
                    <hr>
                    <p>
                        <strong><?php echo htmlspecialchars($lang['reference']); ?>:</strong>
                        <span id="confirmReference"></span>
                    </p>
                    <p>
                        <strong><?php echo htmlspecialchars($lang['notes']); ?>:</strong>
                        <span id="confirmNotes"></span>
                    </p>
                    <p class="text-danger">
                        <strong><?php echo htmlspecialchars($lang['warning']); ?>:</strong>
                        <?php echo htmlspecialchars($lang['stock_deduction_warning']); ?>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i>
                        <?php echo htmlspecialchars($lang['cancel']); ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-success" onclick="submitTransaction()">
                        <i class="bi bi-check-circle"></i>
                        <?php echo htmlspecialchars($lang['yes_save']); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================
         SUCCESS MODAL
    ====================================================== -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle"></i>
                        <?php echo htmlspecialchars($lang['success']); ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-check-circle text-success" style="font-size:4rem;"></i>
                    <p class="mt-3" id="successMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-success" data-bs-dismiss="modal">
                        <i class="bi bi-check"></i> OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================
         LOADING OVERLAY
    ====================================================== -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner-border text-success" role="status" style="width:3rem;height:3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3" id="loadingText">
                <?php echo htmlspecialchars($lang['processing']); ?>...
            </p>
        </div>
    </div>

    <!-- ======================================================
         TOAST MELAYANG — wadah notifikasi
         Diisi oleh showToast() dengan markup .alert yang sama persis
         polanya dengan notifikasi melayang di goods_receipt.php.
    ====================================================== -->
    <div class="bltx-toast-stack" id="bltxToastStack" aria-live="polite" aria-atomic="true"></div>

</div><!-- /.container-fluid -->

<?php include BASE_PATH . '/includes/footer.php'; ?>

<!-- html5-qrcode — loaded after footer JS so jQuery is available -->
<script src="<?php echo url('assets/vendor/html5-qrcode/html5-qrcode.min.js'); ?>"></script>

<script>
/* ==========================================================================
   TAKE ITEMS — CLIENT SCRIPT
   Version: 2.1.0
   ========================================================================== */

let html5QrCode   = null;
let scannerActive = false;
let historyTable  = null;
let lastScannedCode = '';
let lastScanTime    = 0;
const userName = <?php echo bltx_js($_SESSION['full_name'] ?? '') ?>;
const selfUrl  = '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>';

// --------------------------------------------------------------------------
// Translation strings (PHP → JS bridge)
// --------------------------------------------------------------------------
const translations = {
    scannerActive:           <?php echo bltx_js($lang['scanner_active']) ?>,
    scannerUnavailable:      <?php echo bltx_js($lang['scanner_unavailable']) ?>,
    scanned:                 <?php echo bltx_js($lang['scanned']) ?>,
    addedToCart:             <?php echo bltx_js($lang['added_to_cart']) ?>,
    quantityGreaterThanZero: <?php echo bltx_js($lang['quantity_greater_than_zero']) ?>,
    removeItem:              <?php echo bltx_js($lang['remove_item_confirm']) ?>,
    removed:                 <?php echo bltx_js($lang['removed']) ?>,
    clearCart:               <?php echo bltx_js($lang['clear_cart_confirm']) ?>,
    cleared:                 <?php echo bltx_js($lang['cleared']) ?>,
    cartEmpty:               <?php echo bltx_js($lang['cart_empty']) ?>,
    referenceRequired:       <?php echo bltx_js($lang['reference_required']) ?>,
    failedToLoadCart:        <?php echo bltx_js($lang['failed_to_load_cart']) ?>,
    networkError:            <?php echo bltx_js($lang['network_error']) ?>,
    error:                   <?php echo bltx_js($lang['error']) ?>,
    savingTransaction:       '<?php echo substr(bltx_js($lang['saving_transaction']), 1, -1) ?>...',
    itemsSuccessfullyTaken:  <?php echo bltx_js($lang['items_successfully_taken']) ?>,
    reference:               <?php echo bltx_js($lang['reference']) ?>,
    failedToSaveTransaction: <?php echo bltx_js($lang['failed_to_save_transaction']) ?>,
    success:                 <?php echo bltx_js($lang['success']) ?>,
    warning:                 <?php echo bltx_js($lang['warning']) ?>,
    info:                    <?php echo bltx_js($lang['info']) ?>,
    stock:                   <?php echo bltx_js($lang['stock']) ?>,
    sku:                     <?php echo bltx_js($lang['product_code']) ?>,
    itemName:                <?php echo bltx_js($lang['item_name']) ?>,
    quantity:                <?php echo bltx_js($lang['quantity']) ?>,
    processing:              <?php echo bltx_js($lang['processing']) ?>,
    enterCode:               <?php echo bltx_js($lang['enter_code']) ?>,
    outOfStock:              <?php echo bltx_js($lang['out_of_stock']) ?>,
    itemNotFound:            <?php echo bltx_js($lang['item_not_found']) ?>,
    itemInactive:            <?php echo bltx_js($lang['item_inactive_cannot_take']) ?>,
    qtyLimited:              <?php echo bltx_js($lang['qty_limited_to_stock']) ?>,
    noPermission:            <?php echo bltx_js($lang['no_permission']) ?>,
};

// --------------------------------------------------------------------------
// Reference code generator
// --------------------------------------------------------------------------
function generateReferenceCode() {
    const now     = new Date();
    const pad     = n => String(n).padStart(2, '0');
    const year    = now.getFullYear();
    const month   = pad(now.getMonth() + 1);
    const day     = pad(now.getDate());
    const hours   = pad(now.getHours());
    const minutes = pad(now.getMinutes());
    const seconds = pad(now.getSeconds());
    const initials = userName.substring(0, 3).toUpperCase();
    const refCode  = `TAKE${initials}-${year}${month}${day}${hours}${minutes}${seconds}`;
    $('#reference').val(refCode);
    return refCode;
}

// --------------------------------------------------------------------------
// Document ready
// --------------------------------------------------------------------------
$(document).ready(function () {
    generateReferenceCode();
    loadCart();

    // Init DataTable — wait for DataTables from footer to load
    if (typeof $.fn.DataTable !== 'undefined') {
        initHistoryTable();
    } else {
        const dtWait = setInterval(function () {
            if (typeof $.fn.DataTable !== 'undefined') {
                clearInterval(dtWait);
                initHistoryTable();
            }
        }, 100);
        setTimeout(function () { clearInterval(dtWait); }, 5000);
    }

    $('#manualForm').on('submit', function (e) {
        e.preventDefault();
        addItemByCode();
    });
});

// --------------------------------------------------------------------------
// SCANNER
// --------------------------------------------------------------------------
function toggleScanner() {
    scannerActive ? stopScanner() : startScanner();
}

function startScanner() {
    html5QrCode = new Html5Qrcode('reader');
    $('#reader').show();

    html5QrCode.start(
        { facingMode: 'environment' },
        {
            fps: 20,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0,
            formatsToSupport: [
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.CODE_93,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.ITF,
                Html5QrcodeSupportedFormats.CODABAR,
                Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true,
            },
        },
        function onScanSuccess(decodedText) {
            const now = Date.now();
            // Debounce: ignore same code scanned within 1 seconds
            if (decodedText === lastScannedCode && (now - lastScanTime) < 1000) return;
            lastScannedCode = decodedText;
            lastScanTime    = now;

            if (navigator.vibrate) navigator.vibrate(200);
            playBeep();

            $('#manualCode').val(decodedText);
            // Tanpa escHtml(): showToast() menaruh pesan lewat .text(), jadi
            // meng-escape di sini justru membuat tanda baca tampil sebagai
            // &amp; / &#039; di layar.
            showToast(translations.scanned + ': ' + decodedText, 'info');
            addItemByCode();
        }
    ).then(function () {
        scannerActive = true;
        $('#scannerText').text(translations.scannerActive);
        $('#scannerIcon').removeClass('bi-camera').addClass('bi-camera-video-off');
    }).catch(function (err) {
        console.error('Scanner error:', err);
        $('#reader').hide();
        showToast(translations.scannerUnavailable, 'warning');
    });
}

function stopScanner() {
    if (!html5QrCode) return;
    html5QrCode.stop().then(function () {
        scannerActive = false;
        $('#reader').hide();
        $('#scannerText').text('Scanner');
        $('#scannerIcon').removeClass('bi-camera-video-off').addClass('bi-camera');
    }).catch(function (err) {
        console.error('Stop error:', err);
    });
}

function playBeep() {
    try {
        const ctx  = new (window.AudioContext || window.webkitAudioContext)();
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 800;
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.1);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.1);
    } catch (e) {}
}

// --------------------------------------------------------------------------
// ITEM LOOKUP & CART OPERATIONS
// --------------------------------------------------------------------------
function addItemByCode() {
    const code = $('#manualCode').val().trim();
    if (!code) {
        showToast(translations.enterCode, 'warning');
        return;
    }

    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'get_item', code: code },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                /* PERBAIKAN INTI — pengambilan barang berjumlah kecil.
                 *
                 * Sebelumnya baris ini selalu addToCart(code, 1). Untuk barang
                 * yang sisa stoknya di bawah 1 (mis. 0.5 kg), permintaan 1 itu
                 * selalu melampaui stok sehingga server menjawab "stok tidak
                 * cukup" — persis keluhan "di bawah 1 kg tidak bisa diinput
                 * padahal stoknya masih ada".
                 *
                 * Sekarang kuantitas awal diambil dari sisa stok yang
                 * dilaporkan server: min(1, sisa stok), dibulatkan ke 2 desimal
                 * mengikuti presisi kolom DECIMAL(10,2) di database. Server
                 * tetap memvalidasi ulang dan tetap membatasi ke sisa stok,
                 * jadi tidak ada nilai yang bisa dipaksakan dari sisi klien. */
                var stock   = parseFloat(res.data.quantity);
                var suggest = parseFloat(res.data.suggest_qty);

                if (isNaN(suggest) || suggest <= 0) {
                    suggest = (!isNaN(stock) && stock < 1) ? stock : 1;
                }
                suggest = Math.round(suggest * 100) / 100;

                if (suggest <= 0) {
                    showToast(translations.outOfStock, 'error');
                    return;
                }

                addToCart(code, suggest);
            } else {
                showToast(res.message, 'error');
            }
        },
        error: function () {
            showToast(translations.networkError, 'error');
        },
    });
}

function addToCart(code, quantity) {
    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'add_to_cart', code: code, quantity: quantity },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                refreshCart(res.data);
                // Server mengirim pesan ketika kuantitas dibatasi ke sisa stok.
                // Pesan itu ditampilkan sebagai peringatan supaya pembatasan
                // tidak terjadi diam-diam; selain itu tampilkan pesan sukses.
                if (res.message) {
                    showToast(res.message, 'warning');
                } else {
                    showToast(translations.addedToCart, 'success');
                }
                $('#manualCode').val('');
            } else {
                showToast(res.message, 'error');
            }
        },
        error: function (xhr, status, error) {
            console.error('Add to cart error:', error);
            showToast(translations.networkError, 'error');
        },
    });
}

function updateCartQuantity(code, newQty) {
    if (newQty <= 0) {
        showToast(translations.quantityGreaterThanZero, 'error');
        return;
    }

    // Dibulatkan ke 2 desimal sebelum dikirim, mengikuti presisi kolom
    // DECIMAL(10,2) — supaya tombol +/- pada langkah 0.01 tidak menghasilkan
    // nilai seperti 0.30000000000000004.
    newQty = Math.round(parseFloat(newQty) * 100) / 100;

    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'update_cart_quantity', code: code, quantity: newQty },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                refreshCart(res.data);
                // Pesan hanya terisi bila server membatasi kuantitas ke sisa stok.
                if (res.message) showToast(res.message, 'warning');
            } else {
                showToast(res.message, 'error');
                // Server dapat ikut mengirim isi keranjang terbaru (mis. saat
                // barang dikeluarkan karena dinonaktifkan). Bila ada, dipakai
                // langsung supaya tidak perlu satu permintaan tambahan.
                if (res.data) { refreshCart(res.data); } else { refreshCartDisplay(); }
            }
        },
        error: function () {
            showToast(translations.error, 'error');
            refreshCartDisplay();
        },
    });
}

function updateCartQuantityFromInput(code, value) {
    const newQty = parseFloat(value);
    if (isNaN(newQty) || newQty <= 0) {
        showToast(translations.quantityGreaterThanZero, 'error');
        refreshCartDisplay();
        return;
    }
    updateCartQuantity(code, newQty);
}

function removeFromCart(code) {
    if (!confirm(translations.removeItem)) return;

    showLoading(true);
    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'remove_from_cart', code: code },
        dataType: 'json',
        success: function (res) {
            showLoading(false);
            if (res.success) {
                refreshCart(res.data);
                showToast(translations.removed, 'warning');
            }
        },
        error: function () {
            showLoading(false);
            showToast(translations.error, 'error');
        },
    });
}

function clearCart() {
    if (!confirm(translations.clearCart)) return;

    showLoading(true);
    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'clear_cart' },
        dataType: 'json',
        success: function (res) {
            showLoading(false);
            if (res.success) {
                refreshCart(res.data);
                showToast(translations.cleared, 'info');
            }
        },
        error: function () {
            showLoading(false);
            showToast(translations.error, 'error');
        },
    });
}

function loadCart() {
    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'get_cart' },
        dataType: 'json',
        success: function (res) {
            if (res.success) refreshCart(res.data);
        },
    });
}

function refreshCartDisplay() {
    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'get_cart' },
        dataType: 'json',
        success: function (res) {
            if (res.success) refreshCart(res.data);
        },
    });
}

// --------------------------------------------------------------------------
// CART RENDERING
// --------------------------------------------------------------------------
function refreshCart(cart) {
    const count = Object.keys(cart).length;
    $('#cartCount').text(count);

    if (count === 0) {
        $('#cartItems').html(`
            <div class="text-center text-muted py-4">
                <i class="bi bi-cart-x" style="font-size:2rem;"></i>
                <p class="mt-2">${escHtml(translations.cartEmpty)}</p>
            </div>
        `);
        $('#cartActions').hide();
        return;
    }

    let html = '';
    for (const code in cart) {
        const item = cart[code];

        // Image HTML
        let imageHtml = '';
        if (item.item_image) {
            imageHtml = `<img src="../uploads/${escHtml(item.item_image)}"
                              class="cart-item-image"
                              alt="${escHtml(item.item_name)}"
                              onerror="this.parentElement.innerHTML='<div class=\\'cart-item-image-placeholder\\'><i class=\\'bi bi-image\\'></i></div>'">`;
        } else {
            imageHtml = `<div class="cart-item-image-placeholder"><i class="bi bi-image"></i></div>`;
        }

        /* PERBAIKAN — presisi kuantitas berlaku untuk SEMUA satuan.
         *
         * Sebelumnya hanya satuan 'kg' dan 'gr' yang dianggap boleh pecahan;
         * satuan lain dipaksa min="1", step="1", dan parseInt(). Akibatnya
         * sisa stok pecahan pada satuan apa pun selain kg/gr tidak pernah
         * bisa dicapai — parseInt(0.5) bahkan menampilkan 0.
         *
         * Kolom `inventory`.`quantity` bertipe DECIMAL(10,2), jadi satuan
         * terkecil yang benar-benar bisa disimpan adalah 0.01 untuk SEMUA
         * satuan. Nilai itulah yang sekarang dipakai sebagai step dan minimum.
         *
         * Batas atas input diikat ke sisa stok, dan tombol minus tidak
         * pernah turun di bawah 0.01. */
        const stockNum   = Math.round(parseFloat(item.stock)    * 100) / 100;
        const qtyNum     = Math.round(parseFloat(item.quantity) * 100) / 100;
        const step       = '0.01';
        const minVal     = '0.01';
        const displayVal = trimQty(qtyNum);

        // Tombol +/- melangkah 1 untuk stok yang memang utuh, dan 0.01 begitu
        // sisa stoknya pecahan — jadi barang kiloan tetap nyaman ditambah per
        // satuan, sementara sisa 0.25 kg tetap bisa diambil sampai habis.
        const stepBtn    = (stockNum >= 1 && Number.isInteger(stockNum) && Number.isInteger(qtyNum)) ? 1 : 0.01;
        const qtyMinus   = Math.max(0.01, Math.round((qtyNum - stepBtn) * 100) / 100);
        const qtyPlus    = Math.min(stockNum, Math.round((qtyNum + stepBtn) * 100) / 100);

        const stockDisplay = `${trimQty(stockNum)} ${escHtml(item.unit_symbol)}`;

        html += `
            <div class="cart-item">
                <div class="d-flex gap-3 mb-2">
                    ${imageHtml}
                    <div class="flex-grow-1">
                        <strong>${escHtml(item.item_name)}</strong>
                        <br><small class="text-muted">${escHtml(item.product_code)}</small>
                    </div>
                    <button class="btn btn-sm btn-danger" style="height:fit-content;"
                            onclick="removeFromCart('${escHtml(code)}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="qty-controls">
                        <button class="btn btn-sm btn-secondary btn-circle"
                                onclick="updateCartQuantity('${escHtml(code)}', ${qtyMinus})">
                            <i class="bi bi-dash"></i>
                        </button>
                        <input type="number"
                               class="qty-input"
                               value="${displayVal}"
                               step="${step}"
                               min="${minVal}"
                               max="${stockNum}"
                               onchange="updateCartQuantityFromInput('${escHtml(code)}', this.value)"
                               onkeypress="if(event.key==='Enter'){this.blur();}">
                        <button class="btn btn-sm btn-success btn-circle"
                                onclick="updateCartQuantity('${escHtml(code)}', ${qtyPlus})">
                            <i class="bi bi-plus-circle"></i>
                        </button>
                    </div>
                    <small class="text-muted">
                        ${translations.stock}: ${stockDisplay}
                    </small>
                </div>
            </div>
        `;
    }

    $('#cartItems').html(html);
    $('#cartActions').show();
    $('#submitBtn').prop('disabled', false);
}

// --------------------------------------------------------------------------
// CONFIRM MODAL & SUBMIT
// --------------------------------------------------------------------------
function showConfirmModal() {
    const ref   = $('#reference').val().trim();
    const notes = $('#notes').val().trim();

    if (!ref) {
        showToast(translations.referenceRequired, 'error');
        return;
    }

    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'get_cart' },
        dataType: 'json',
        success: function (res) {
            if (res.success && res.data) {
                const cart  = res.data;
                const count = Object.keys(cart).length;

                if (count === 0) {
                    showToast(translations.cartEmpty, 'error');
                    return;
                }

                let itemsHtml = '<div class="table-responsive"><table class="table table-sm table-bordered">';
                itemsHtml += `<thead class="table-light"><tr>
                    <th>${translations.sku}</th>
                    <th>${translations.itemName}</th>
                    <th>${translations.quantity}</th>
                </tr></thead><tbody>`;

                for (const code in cart) {
                    const item = cart[code];
                    itemsHtml += `
                        <tr>
                            <td>${escHtml(item.product_code)}</td>
                            <td>${escHtml(item.item_name)}</td>
                            <td>${trimQty(item.quantity)} ${escHtml(item.unit_symbol)}</td>
                        </tr>
                    `;
                }

                itemsHtml += '</tbody></table></div>';

                $('#confirmItemsList').html(itemsHtml);
                $('#confirmReference').text(ref);
                $('#confirmNotes').text(notes || '-');

                $('#confirmModal').modal('show');
            } else {
                showToast(translations.failedToLoadCart, 'error');
            }
        },
        error: function () {
            showToast(translations.networkError, 'error');
        },
    });
}

function submitTransaction() {
    const ref   = $('#reference').val().trim();
    const notes = $('#notes').val().trim();

    if (!ref) {
        showToast(translations.referenceRequired, 'error');
        return;
    }

    $('#confirmModal').modal('hide');
    showLoading(true, translations.savingTransaction);

    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'submit_taking', reference: ref, notes: notes },
        dataType: 'json',
        success: function (res) {
            showLoading(false);
            if (res.success) {
                $('#successMessage').text(
                    `${res.data.count} ${translations.itemsSuccessfullyTaken}. ${translations.reference}: ${res.data.reference}`
                );
                $('#successModal').modal('show');
                refreshCart({});
                generateReferenceCode();
                $('#notes').val('');
                if (historyTable) historyTable.ajax.reload();
            } else {
                showToast(res.message, 'error');
            }
        },
        error: function (xhr, status, error) {
            showLoading(false);
            console.error('Submit error:', error);
            showToast(translations.failedToSaveTransaction, 'error');
        },
    });
}

// --------------------------------------------------------------------------
// HISTORY DATATABLE (server-side)
// --------------------------------------------------------------------------
function initHistoryTable() {
    if (historyTable) return;

    historyTable = $('#historyTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url:  selfUrl,
            type: 'POST',
            data: function (d) {
                d.ajax = 'get_history';
                return d;
            },
        },
        columns: [
            { data: 'transaction_date' },
            { data: 'item_name' },
            {
                data: null,
                render: function (row) {
                    const num       = parseFloat(row.quantity);
                    const formatted = num % 1 === 0
                        ? num.toFixed(0)
                        : String(parseFloat(num.toFixed(4))).replace(/\.?0+$/, '');
                    return formatted + (row.unit_symbol ? ' ' + row.unit_symbol : '');
                },
            },
            { data: 'reference_number' },
            { data: 'notes' },
        ],
        order:     [[0, 'desc']],
        ordering:  false,
        pageLength: 25,
        responsive: true,
        autoWidth:  false,
    });
}

// --------------------------------------------------------------------------
// UI HELPERS
// --------------------------------------------------------------------------
function showLoading(show, text) {
    if (text === undefined) text = translations.processing + '...';
    $('#loadingText').text(text);
    $('#loadingOverlay').css('display', show ? 'flex' : 'none');
}

/* --------------------------------------------------------------------------
   TOAST MELAYANG
   Bentuk dan perilakunya mengikuti notifikasi melayang di
   purchases/goods_receipt.php:
     - komponen .alert Bootstrap dengan varian warna sesuai jenis pesan
     - ikon Bootstrap di depan teks
     - tombol tutup manual (.btn-close)
     - menghilang sendiri setelah 4 detik lewat transisi opacity
   Tidak ada ketergantungan pada komponen Toast JavaScript Bootstrap, jadi
   notifikasi tetap muncul walaupun bundel JS-nya gagal dimuat.
   -------------------------------------------------------------------------- */
function showToast(message, type) {
    if (!type) type = 'info';

    const variants = {
        success: { css: 'alert-success', icon: 'bi-check-circle-fill' },
        error:   { css: 'alert-danger',  icon: 'bi-exclamation-circle-fill' },
        warning: { css: 'alert-warning', icon: 'bi-exclamation-triangle-fill' },
        info:    { css: 'alert-info',    icon: 'bi-info-circle-fill' },
    };
    const v = variants[type] || variants.info;

    const $stack = $('#bltxToastStack');
    if (!$stack.length) return;

    const $toast = $(
        '<div class="alert ' + v.css + ' alert-dismissible shadow-lg d-flex align-items-center gap-2" role="alert">' +
            '<i class="bi ' + v.icon + '"></i>' +
            '<span class="flex-grow-1"></span>' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
        '</div>'
    );

    // .text() dipakai (bukan .html()) supaya tanda baca seperti & ' " < >
    // tampil apa adanya dan TIDAK berubah menjadi &amp; / &#039;, sekaligus
    // tetap aman dari XSS karena isinya tidak pernah diperlakukan sebagai HTML.
    $toast.find('span').text(String(message == null ? '' : message));
    $stack.append($toast);

    // Penutupan manual: hapus elemennya agar tumpukan tidak menyisakan slot.
    $toast.on('click', '.btn-close', function () { $toast.remove(); });

    setTimeout(function () {
        $toast.css('opacity', '0');
        setTimeout(function () { $toast.remove(); }, 400);
    }, 4000);
}

function escHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
}

/* Tampilkan kuantitas apa adanya tanpa nol berlebih: 2 → "2", 0.50 → "0.5",
   0.25 → "0.25". Presisi maksimum 2 desimal, mengikuti kolom DECIMAL(10,2). */
function trimQty(value) {
    const num = Math.round(parseFloat(value) * 100) / 100;
    if (isNaN(num)) return '0';
    return String(parseFloat(num.toFixed(2)));
}

// --------------------------------------------------------------------------
// CLEANUP on page unload
// --------------------------------------------------------------------------
$(window).on('beforeunload', function () {
    if (html5QrCode) {
        try { html5QrCode.stop(); } catch (e) {}
    }
});
</script>