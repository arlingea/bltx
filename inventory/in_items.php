<?php
/*
 * Added Items (Inventory In) — Mobile Scanner Page
 * Version: 3.0.0
 * Integrated with header.php & footer.php of the main system.
 * Login & permission use the main session (config.php).
 * Functions are loaded from includes/added_items_functions.php.
 *
 * Developer: AG +62 823 6334 9011
 */

// ── Direct-access guard ──────────────────────────────────────────────────────
if (!defined('BASE_PATH') && !isset($_SERVER['HTTP_HOST'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access not permitted.');
}

// ── Error reporting ──────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once __DIR__ . '/../includes/config.php';

// ── Permission check — main session ─────────────────────────────────────────
if (!isLoggedIn()) {
    redirect(url('index.php'));
}

// Seluruh keputusan izin diambil dari includes/global_permission.php.
// requireInItemsAccess() menegakkan DUA lapis sekaligus — gerbang induk
// modul 'inventory' dan izin modul 'in_items' — dan membacanya LANGSUNG dari
// tabel `permissions`, bukan dari cache $_SESSION['_perms'] yang hanya diisi
// saat login. Superadmin selalu lolos di dalam _dbCan().
// TIDAK ada modul permission baru: 'in_items' sudah ada sebelumnya.
require_once BASE_PATH . '/includes/global_permission.php';
requireInItemsAccess();

// ── Load functions library ───────────────────────────────────────────────────
require_once BASE_PATH . '/includes/added_items_functions.php';

// ── Language ─────────────────────────────────────────────────────────────────
// Bahasa default sistem adalah English (en); nilainya ditetapkan di header.php
// dan disimpan di $_SESSION['lang'].
//
// TANPA FALLBACK LANGUAGE. Array $lang_defaults yang dulu berisi ~55 pasangan
// kunci-nilai berbahasa Inggris DIHAPUS seluruhnya: array itu adalah fallback
// language terselubung — ketika sebuah kunci belum ada di berkas bahasa yang
// aktif, halaman diam-diam menampilkan teks Inggris sehingga kunci yang hilang
// tidak pernah ketahuan, dan pengguna berbahasa Indonesia/Mandarin melihat
// campuran dua bahasa. Seluruh kunci yang dipakai halaman ini kini tersedia
// LENGKAP di ketiga berkas bahasa (en.php, id.php, ch.php), tanpa duplikat,
// sehingga fallback tidak diperlukan lagi.
$current_lang = $_SESSION['lang'] ?? 'en';
$lang         = loadLanguage($current_lang);
$LANG         = $lang;

// ── Initialize cart session ───────────────────────────────────────────────────
if (!isset($_SESSION['in_cart'])) {
    $_SESSION['in_cart'] = [];
}

// ── AJAX dispatch — must run before any HTML output ──────────────────────────
if (isset($_POST['ajax'])) {
    ai_dispatch_ajax();
    // ai_dispatch_ajax() always exits; safety net:
    ai_json(false, null, 'Unknown action');
}

// ── Page variables ────────────────────────────────────────────────────────────
$page_title  = $lang['added_items'];
$system_name = getSetting('system_title', APP_NAME);
$currency    = getSetting('currency', 'IDR');

// News for header.php
$news_content = '';
$stmt_news = $conn->prepare("SELECT content FROM news WHERE is_active = 1 LIMIT 1");
if ($stmt_news) {
    $stmt_news->execute();
    $stmt_news->bind_result($news_content);
    $stmt_news->fetch();
    $stmt_news->close();
}

// Generate initial reference
$initial_reference = ai_generateReference($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'USR');

ob_end_clean();

// ── Main system header ────────────────────────────────────────────────────────
include BASE_PATH . '/includes/header.php';
?>
    <style>
        :root {
            --ai-primary:   #0d6efd;
            --ai-success:   #198754;
            --ai-danger:    #dc3545;
            --ai-warning:   #ffc107;
            --ai-secondary: #6c757d;
        }

        /* ── PAGE HEADER ────────────────────────────────────────────── */
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

        /* ── CARD SECTIONS ───────────────────────────────────────────── */
        .scanner-container,
        .manual-input,
        .cart-section {
            background: #fff;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        /* ── READER ──────────────────────────────────────────────────── */
        #reader {
            border-radius: 8px;
            overflow: hidden;
            max-width: 100%;
        }

        /* ── CART ITEM ───────────────────────────────────────────────── */
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

        /* ── QTY CONTROLS ────────────────────────────────────────────── */
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
            border-color: var(--ai-primary);
            box-shadow: 0 0 0 0.2rem rgba(13,110,253,.25);
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

        /* ── LOADING OVERLAY ─────────────────────────────────────────── */
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

        /* ── TOAST MELAYANG ──────────────────────────────────────────
           Gaya diseragamkan dengan notifikasi melayang di
           purchases/goods_receipt.php: position:fixed di pojok kanan atas
           viewport, memakai komponen .alert Bootstrap + ikon Bootstrap,
           bayangan shadow-lg, dan menghilang sendiri lewat transisi opacity.
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

        /* ── RESPONSIVE ──────────────────────────────────────────────── */
        @media (max-width: 576px) {
            .cart-item          { font-size: 0.9rem; }
            .qty-input          { width: 60px; }
            #historyTable       { font-size: 0.85rem; }
            #historyTable thead th { padding: 0.5rem 0.3rem; font-size: 0.75rem; }
            #historyTable tbody td { padding: 0.5rem 0.3rem; }
            .page-header h5     { font-size: 1rem; }
        }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    </style>
</head>

<div class="container-fluid p-0">

    <!-- ======================================================
         PAGE HEADER
    ====================================================== -->
    <div class="page-title-bar">
        <h2 class="page-title-text">
            <i class="bi bi-box-arrow-in-down"></i>
            <?php echo htmlspecialchars($lang['added_items']); ?>
        </h2>
    </div>

    <!-- ======================================================
         SCANNER SECTION
    ====================================================== -->
    <div class="scanner-container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">
                <i class="bi bi-qr-code-scan"></i>
                <?php echo htmlspecialchars($lang['scan_product']); ?>
            </h6>
            <button class="btn btn-sm btn-primary" onclick="toggleScanner()">
                <i class="bi bi-camera" id="scannerIcon"></i>
                <span id="scannerText"><?php echo htmlspecialchars($lang['scanner_active']); ?></span>
            </button>
        </div>
        <div id="reader" style="display:none;"></div>
    </div>

    <!-- ======================================================
         MANUAL INPUT
    ====================================================== -->
    <div class="manual-input">
        <form id="manualForm">
            <div class="input-group">
                <input type="text" id="manualCode" class="form-control"
                       placeholder="<?php echo htmlspecialchars($lang['enter_code']); ?>">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle"></i>
                    <?php echo htmlspecialchars($lang['add_to_cart']); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- ======================================================
         CART SECTION
    ====================================================== -->
    <div class="cart-section">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">
                <i class="bi bi-cart3"></i>
                <?php echo htmlspecialchars($lang['cart']); ?>
                (<span id="cartCount">0</span>)
            </h6>
            <button class="btn btn-sm btn-danger" onclick="clearCart()">
                <i class="bi bi-trash"></i>
                <?php echo htmlspecialchars($lang['clear']); ?>
            </button>
        </div>

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
                    <?php echo htmlspecialchars($lang['reference_number']); ?>
                    <span class="text-danger">*</span>
                </label>
                <input type="text" id="reference" class="form-control"
                       value="<?php echo htmlspecialchars($initial_reference); ?>" readonly>
            </div>

            <div class="mb-3">
                <label class="form-label"><?php echo htmlspecialchars($lang['notes']); ?></label>
                <textarea id="notes" class="form-control" rows="3"></textarea>
            </div>

            <button class="btn btn-primary btn-sm w-100" id="submitBtn"
                    onclick="showConfirmModal()"
                    <?php echo !canCreateInItems() ? 'disabled title="' . htmlspecialchars($lang['no_permission']) . '"' : ''; ?>>
                <i class="bi bi-check-circle"></i>
                <?php echo htmlspecialchars($lang['complete']); ?>
            </button>
        </div>
    </div>

    <!-- ======================================================
         HISTORY SECTION
    ====================================================== -->
    <div class="cart-section">
        <h6 class="mb-3">
            <i class="bi bi-clock-history"></i>
            <?php echo htmlspecialchars($lang['history']); ?>
        </h6>
        <div class="table-responsive">
            <table id="historyTable" class="table table-sm table-striped table-hover" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th><?php echo htmlspecialchars($lang['date']);      ?></th>
                        <th><?php echo htmlspecialchars($lang['sku']);       ?></th>
                        <th><?php echo htmlspecialchars($lang['item_name']); ?></th>
                        <th><?php echo htmlspecialchars($lang['quantity']);  ?></th>
                        <th><?php echo htmlspecialchars($lang['reference']); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
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
                    <p><strong><?php echo htmlspecialchars($lang['confirm_items_message_in']); ?></strong></p>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i>
                        <?php echo htmlspecialchars($lang['cancel']); ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="submitTransaction()">
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
            <div class="spinner-border text-primary" role="status" style="width:3rem;height:3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3" id="loadingText">
                <?php echo htmlspecialchars($lang['processing']); ?>...
            </p>
        </div>
    </div>

    <!-- ======================================================
         TOAST MELAYANG — wadah notifikasi
         Diisi oleh showToast() dengan markup .alert yang polanya sama
         persis dengan notifikasi melayang di goods_receipt.php.
    ====================================================== -->
    <div class="bltx-toast-stack" id="bltxToastStack" aria-live="polite" aria-atomic="true"></div>

</div><!-- /.container-fluid -->

<?php include BASE_PATH . '/includes/footer.php'; ?>

<!-- html5-qrcode — after footer JS so jQuery is available -->
<script src="<?php echo url('assets/vendor/html5-qrcode/html5-qrcode.min.js'); ?>"></script>

<script>
/* ==========================================================================
   ADDED ITEMS (in_items) — CLIENT SCRIPT
   Version: 3.0.0
   ========================================================================== */

let html5QrCode   = null;
let scannerActive = false;
let historyTable  = null;
let lastScannedCode = '';
let lastScanTime    = 0;

const selfUrl  = '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>';
const userName = <?php echo bltx_js($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>;

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
    itemsSuccessfullyAdd:    <?php echo bltx_js($lang['items_successfully_add']) ?>,
    reference:               <?php echo bltx_js($lang['reference']) ?>,
    failedToSaveTransaction: <?php echo bltx_js($lang['failed_to_save_transaction']) ?>,
    success:                 <?php echo bltx_js($lang['success']) ?>,
    warning:                 <?php echo bltx_js($lang['warning']) ?>,
    info:                    <?php echo bltx_js($lang['info']) ?>,
    sku:                     <?php echo bltx_js($lang['sku']) ?>,
    itemName:                <?php echo bltx_js($lang['item_name']) ?>,
    quantity:                <?php echo bltx_js($lang['quantity']) ?>,
    processing:              <?php echo bltx_js($lang['processing']) ?>,
    enterCode:               <?php echo bltx_js($lang['enter_code']) ?>,
    itemNotFound:            <?php echo bltx_js($lang['item_not_found']) ?>,
    itemInactive:            <?php echo bltx_js($lang['item_inactive_cannot_add']) ?>,
    noPermission:            <?php echo bltx_js($lang['no_permission']) ?>,
    noData:                  <?php echo bltx_js($lang['no_data']) ?>,
    search:                  <?php echo bltx_js($lang['search']) ?>,
    first:                   <?php echo bltx_js($lang['first']) ?>,
    last:                    <?php echo bltx_js($lang['last']) ?>,
    next:                    <?php echo bltx_js($lang['next']) ?>,
    previous:                <?php echo bltx_js($lang['previous']) ?>,
};

// --------------------------------------------------------------------------
// Reference code generator
// --------------------------------------------------------------------------
function generateReferenceCode() {
    const now     = new Date();
    const pad     = n => String(n).padStart(2, '0');
    const ts      = now.getFullYear() + pad(now.getMonth()+1) + pad(now.getDate())
                  + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    const initials = userName.replace(/[^a-zA-Z]/g, '').substring(0, 3).toUpperCase().padEnd(3, 'X');
    const ref = 'IN' + initials + '-SC' + ts;
    $('#reference').val(ref);
    return ref;
}

// --------------------------------------------------------------------------
// Document ready
// --------------------------------------------------------------------------
$(document).ready(function () {
    loadCart();

    // Init DataTable — wait for DataTables from footer
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
            // Debounce: ignore same code within 1 second
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
        console.error('Stop scanner error:', err);
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
                addToCart(code, 1);
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
                showToast(translations.addedToCart, 'success');
                $('#manualCode').val('');
            } else {
                showToast(res.message, 'error');
            }
        },
        error: function () {
            showToast(translations.networkError, 'error');
        },
    });
}

function updateCartQuantity(code, newQty) {
    if (newQty <= 0) {
        showToast(translations.quantityGreaterThanZero, 'error');
        return;
    }
    $.ajax({
        url:      selfUrl,
        method:   'POST',
        data:     { ajax: 'update_cart', code: code, quantity: newQty },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                refreshCart(res.data);
            } else {
                showToast(res.message, 'error');
            }
        },
        error: function () {
            showToast(translations.networkError, 'error');
        },
    });
}

function updateCartQuantityFromInput(code, value) {
    const newQty = parseFloat(value);
    if (isNaN(newQty) || newQty <= 0) {
        showToast(translations.quantityGreaterThanZero, 'error');
        loadCart();
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
            showToast(translations.networkError, 'error');
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
            showToast(translations.networkError, 'error');
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

        // Image
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
         * satuan lain dipaksa min="1", step="1", dan parseInt(), sehingga
         * penambahan pecahan pada satuan apa pun selain kg/gr tidak mungkin
         * dan parseInt(0.5) bahkan menampilkan 0.
         *
         * Kolom `inventory`.`quantity` bertipe DECIMAL(10,2), jadi satuan
         * terkecil yang benar-benar bisa disimpan adalah 0.01 untuk SEMUA
         * satuan. Nilai itulah yang dipakai sebagai step dan minimum. */
        const qtyNum     = Math.round(parseFloat(item.quantity) * 100) / 100;
        const step       = '0.01';
        const minVal     = '0.01';
        const displayVal = trimQty(qtyNum);

        // Tombol +/- melangkah 1 selama kuantitasnya masih bilangan bulat,
        // dan 0.01 begitu nilainya pecahan — jadi barang satuan tetap nyaman
        // ditambah per unit, sementara nilai pecahan tetap bisa disetel halus.
        const stepBtn  = Number.isInteger(qtyNum) ? 1 : 0.01;
        const qtyMinus = Math.max(0.01, Math.round((qtyNum - stepBtn) * 100) / 100);
        const qtyPlus  = Math.round((qtyNum + stepBtn) * 100) / 100;

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
                               onchange="updateCartQuantityFromInput('${escHtml(code)}', this.value)"
                               onkeypress="if(event.key==='Enter'){this.blur();}">
                        <button class="btn btn-sm btn-success btn-circle"
                                onclick="updateCartQuantity('${escHtml(code)}', ${qtyPlus})">
                            <i class="bi bi-plus-circle"></i>
                        </button>
                    </div>
                    <small class="text-muted">${escHtml(item.unit_symbol || '')}</small>
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
                    <th>${escHtml(translations.sku)}</th>
                    <th>${escHtml(translations.itemName)}</th>
                    <th>${escHtml(translations.quantity)}</th>
                </tr></thead><tbody>`;

                for (const code in cart) {
                    const item = cart[code];
                    itemsHtml += `
                        <tr>
                            <td>${escHtml(item.product_code)}</td>
                            <td>${escHtml(item.item_name)}</td>
                            <td>${trimQty(item.quantity)} ${escHtml(item.unit_symbol || '')}</td>
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
        data:     { ajax: 'submit_in', reference: ref, notes: notes },
        dataType: 'json',
        success: function (res) {
            showLoading(false);
            if (res.success) {
                $('#successMessage').text(
                    `${res.data.count} ${translations.itemsSuccessfullyAdd}. ${translations.reference}: ${res.data.reference}`
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
        error: function () {
            showLoading(false);
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
            { data: 'date_time' },
            { data: 'product_code' },
            { data: 'item_name' },
            { data: 'quantity_display' },
            { data: 'reference_number' },
        ],
        order:      [[0, 'desc']],
        ordering:   false,
        pageLength: 25,
        responsive: true,
        autoWidth:  false,
        language: {
            // Seluruh label DataTables kini berasal dari berkas bahasa aktif.
            // Nilai cadangan berbahasa Inggris ('Search:', 'No data available',
            // dan seterusnya) DIHAPUS karena itu fallback language terselubung:
            // kunci-kuncinya sudah tersedia lengkap di en.php / id.php / ch.php.
            processing:  translations.processing + '...',
            search:      translations.search,
            emptyTable:  translations.noData,
            zeroRecords: translations.noData,
            paginate: {
                first:    translations.first,
                last:     translations.last,
                next:     translations.next,
                previous: translations.previous,
            },
        },
    });
}

// --------------------------------------------------------------------------
// UI HELPERS
// --------------------------------------------------------------------------
function showLoading(show, text) {
    if (!text) text = translations.processing + '...';
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
   Tidak bergantung pada komponen Toast JavaScript Bootstrap, jadi notifikasi
   tetap muncul walaupun bundel JS-nya gagal dimuat.
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
// CLEANUP on unload
// --------------------------------------------------------------------------
$(window).on('beforeunload', function () {
    if (html5QrCode) {
        try { html5QrCode.stop(); } catch (e) {}
    }
});
</script>