<?php
// File: arw_pos/manual_dreport_add.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/manual_dreport_functions.php';
/* PRASYARAT PENYATUAN CSRF — lihat RENCANA-PENYATUAN.md.
   Berkas ini memakai fungsi CSRF modulnya sendiri; begitu fungsi itu
   diteruskan ke bltx_csrf_*, includes/global_permission.php harus termuat.

   DIMUAT SETELAH berkas fungsi modul, dan itu WAJIB. Kedua berkas sama-sama
   mendefinisikan requireYesterdayCashViewAccess(), tetapi versi di
   manual_dreport_functions.php TIDAK berpagar function_exists. Kalau
   global_permission.php dimuat lebih dulu, definisi keduanya bentrok dan PHP
   berhenti dengan 'Cannot redeclare' -> HTTP 500.

   Urutan ini juga MENJAGA PERILAKU LAMA: implementasi yang menang tetap milik
   manual_dreport_functions.php (memakai _getDreportPerm()), bukan milik
   global_permission.php (memakai checkPermission()). Keduanya tidak identik,
   jadi pemenangnya tidak boleh berubah.

   Urutan tidak mempengaruhi ketersediaan bltx_csrf_*: PHP menyelesaikan
   panggilan fungsi saat dijalankan, bukan saat di-include. */
require_once __DIR__ . '/../includes/global_permission.php';

// Access: kasir & superadmin only
requireDreportAccess();

$isSuperadmin = isDreportSuperadmin();

$page_title = 'Add Cashier Report';
$LANG       = loadLanguage();
$csrfToken  = generateCsrfToken();

// ================================================================
// KASIR: auto-detect cashier name dari user login (tanpa auto-detect shift)
// ================================================================
$kasirName = ''; // nama kasir dari session/users

if (!$isSuperadmin) {
    global $conn;

    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);

    // Ambil full_name dari tabel users berdasarkan user yang sedang login
    if ($sessionUserId > 0) {
        $stmtU = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $stmtU->bind_param('i', $sessionUserId);
        $stmtU->execute();
        $userRow = $stmtU->get_result()->fetch_assoc();
        $stmtU->close();
        $kasirName = $userRow['full_name'] ?? ($_SESSION['full_name'] ?? ($_SESSION['name'] ?? ''));
    }

    if ($kasirName === '') {
        $kasirName = $_SESSION['full_name'] ?? ($_SESSION['name'] ?? '');
    }
}

// ================================================================
// HANDLE POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(url('arw_pos/manual_dreport_add.php'));
        exit;
    }

    // ---- Poin 5: System Revenue wajib — server-side validation ----
    $postSysRev = (float)str_replace(['.', ','], ['', '.'], $_POST['system_revenue'] ?? '');
    if ($postSysRev <= 0) {
        bltx_flash_error($LANG['system_revenue_required']);
        redirect(url('arw_pos/manual_dreport_add.php'));
        exit;
    }

    // summary image (wajib)
    $imageFile  = '';
    $cameraData = $_POST['camera_image_data'] ?? '';
    if ($cameraData !== '') {
        $imageFile = saveDreportCameraByType($cameraData, 'summary');
    } elseif (!empty($_FILES['report_image']['tmp_name'])) {
        $imageFile = saveDreportImageByType($_FILES['report_image'], 'summary');
    }

    // yesterday cash image (wajib)
    $ycImageFile  = '';
    $ycCameraData = $_POST['yc_camera_image_data'] ?? '';
    if ($ycCameraData !== '') {
        $ycImageFile = saveDreportCameraByType($ycCameraData, 'yesterday_cash');
    } elseif (!empty($_FILES['yc_report_image']['tmp_name'])) {
        $ycImageFile = saveDreportImageByType($_FILES['yc_report_image'], 'yesterday_cash');
    }

    // rpos image (wajib)
    $rposImageFile  = '';
    $rposCameraData = $_POST['rpos_camera_image_data'] ?? '';
    if ($rposCameraData !== '') {
        $rposImageFile = saveDreportCameraByType($rposCameraData, 'rpos');
    } elseif (!empty($_FILES['rpos_report_image']['tmp_name'])) {
        $rposImageFile = saveDreportImageByType($_FILES['rpos_report_image'], 'rpos');
    }

    $paymentMethodIds   = $_POST['payment_method_id']   ?? [];
    $paymentMethodNames = $_POST['payment_method_name'] ?? [];
    $amounts            = $_POST['amount']               ?? [];
    $items = [];
    foreach ($paymentMethodIds as $k => $pmId) {
        $amt = (float)str_replace(['.', ','], ['', '.'], $amounts[$k] ?? '0');
        if ($amt > 0) {
            $items[] = [
                'payment_method_id'   => ($pmId > 0 ? (int)$pmId : null),
                'payment_method_name' => sanitize($paymentMethodNames[$k] ?? ''),
                'amount'              => $amt,
            ];
        }
    }

    // Tentukan cashier_name: kasir dari session, superadmin dari POST
    if ($isSuperadmin) {
        $postCashierName = sanitize(trim($_POST['cashier_name'] ?? ''));
    } else {
        $postCashierName = $kasirName;
    }

    // ---- Poin 1 & 2: yesterday_cash_total security ----
    // Non-superadmin: nilai dikunci dari server (re-fetch DB, tidak percaya POST).
    //   → Gunakan last entry's total_cash (last shift's balance).
    // Superadmin: boleh kirim nilai apapun melalui POST.
    //   → Field name: yesterday_cash_total (beda hari, suffix='') atau
    //                 yesterday_cash_total_1 / _2 / ... (hari sama, per-shift).
    //   → Kita simpan nilai dari field pertama yang ada di POST.
    if (!$isSuperadmin) {
        // Re-fetch dari DB untuk menghindari POST tampering
        $ycServerEntries = getDreportYesterdayCashMulti(date('Y-m-d'));
        $ycLastServer    = !empty($ycServerEntries) ? $ycServerEntries[count($ycServerEntries) - 1] : null;
        $ycTotal         = $ycLastServer ? (float)$ycLastServer['total_cash'] : 0.0;
    } else {
        // Superadmin: baca dari POST — field '' (beda hari) atau '_1' (hari sama)
        $ycTotalRaw = $_POST['yesterday_cash_total']
                   ?? $_POST['yesterday_cash_total_1']
                   ?? '0';
        $ycTotal    = (float)str_replace(['.', ','], ['', '.'], $ycTotalRaw);
    }

    $data = [
        // Tanggal: kasir selalu hari ini, superadmin bebas
        'report_date'    => $isSuperadmin
                            ? sanitize($_POST['report_date'] ?? date('Y-m-d'))
                            : date('Y-m-d'),
        'cashier_name'   => $postCashierName,
        'shift_id'       => (int)($_POST['shift_id'] ?? 0),
        'system_revenue'         => $postSysRev,
        'notes'                  => sanitize($_POST['notes'] ?? ''),
        'image_file'             => $imageFile,
        'yesterday_cash_total'   => $ycTotal,
        'yesterday_cash_usage'   => (float)str_replace(['.', ','], ['', '.'], $_POST['yesterday_cash_usage'] ?? '0'),
        'yesterday_cash_optional'=> (float)str_replace(['.', ','], ['', '.'], $_POST['yesterday_cash_optional'] ?? '0'),
        'yesterday_cash_balance' => (float)str_replace(['.', ','], ['', '.'], $_POST['yesterday_cash_balance'] ?? '0'),
        'yesterday_cash_image'   => $ycImageFile,
        'notes_cash'             => sanitize($_POST['notes_cash'] ?? ''),
        'rpos_image'             => $rposImageFile,
    ];

    // Optional items (yesterday cash optional rows)
    $optNames   = $_POST['optional_name']   ?? [];
    $optAmounts = $_POST['optional_amount'] ?? [];
    $optionalItems = [];
    foreach ($optNames as $k => $optName) {
        $optAmt = (float)str_replace(['.', ','], ['', '.'], $optAmounts[$k] ?? '0');
        if ($optAmt != 0 || trim($optName) !== '') {
            $optionalItems[] = [
                'name'   => sanitize(trim($optName)),
                'amount' => $optAmt,
            ];
        }
    }

    $newId = createDreport($data, $items, $optionalItems);
    if ($newId > 0) {
        bltx_flash_success($LANG['dreport_added']);
        redirect(url('arw_pos/manual_dreport.php'));
    } else {
        bltx_flash_error($LANG['dreport_add_error']);
        redirect(url('arw_pos/manual_dreport_add.php'));
    }
    exit;
}

// ================================================================
// FETCH FORM DATA
// ================================================================
$paymentMethods = getDreportPaymentMethods();
$shifts         = getDreportShifts();
$dreportUsers   = $isSuperadmin ? getDreportAllUsers() : [];

// Auto-fetch yesterday cash entries (single or multi-shift) for pre-filling the form
$ycEntries    = getDreportYesterdayCashMulti(date('Y-m-d'));
$ycIsSameDay  = !empty($ycEntries) && $ycEntries[0]['is_same_day'];
// ycLastEntry: the last entry drives the balance base (last shift's balance)
$ycLastEntry  = !empty($ycEntries) ? $ycEntries[count($ycEntries) - 1] : null;
// ycBalanceBase: value pre-filled in balance card = last entry's total_cash
$ycBalanceBase = $ycLastEntry ? (float)$ycLastEntry['total_cash'] : 0.0;
// ycLastFieldId: JS uses this to read the base value in recalcYcBalance
$ycLastFieldId = $ycLastEntry ? ('add_yesterday_cash_total' . $ycLastEntry['field_suffix']) : 'add_yesterday_cash_total';

include __DIR__ . '/../includes/header.php';

// ---- Alerts ----
/* CATATAN: tidak ada '<?php' di sini — titik ini SUDAH berada di dalam
   blok PHP yang dibuka di atas. Tag pembuka kedua memicu
   'syntax error, unexpected token "<"'. Penutup ?> di bawah tetap ada,
   karena setelahnya memang kembali ke HTML. */
/* RONDE B — pembaca flash ikut dipindah ke helper terpusat.
   Setter-nya kini menulis ke kunci bersama 'success'/'error', jadi blok
   yang membaca kunci lama TIDAK AKAN PERNAH menyala lagi — pesannya
   hilang diam-diam. Saluran sukses tetap dimatikan ('') —
   halaman ini tidak pernah menampilkan pesan sukses; pesan itu milik
   halaman daftar setelah redirect. */
bltx_render_flash('dreport', ['success' => '']);
?>

<!-- Page Header -->
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-plus-circle"></i><?= $LANG['add_cashier_report'] ?></h2>
    <div class="page-actions">
        <a href="<?= url('arw_pos/manual_dreport.php') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= $LANG['back'] ?>
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="<?= url('arw_pos/manual_dreport_add.php') ?>" enctype="multipart/form-data" id="addDreportForm">
            <input type="hidden" name="action"     value="add">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="row g-3">
                <!-- Date -->
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $LANG['date'] ?> <span class="text-danger">*</span></label>
                    <?php if ($isSuperadmin): ?>
                    <input type="date" name="report_date" id="add_report_date" class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                    <?php else: ?>
                    <div class="input-group">
                        <input type="date" name="report_date" id="add_report_date" class="form-control bg-light"
                               value="<?= date('Y-m-d') ?>" required readonly>
                        <span class="input-group-text text-muted" title="<?= $LANG['date_locked'] ?>">
                            <i class="bi bi-lock-fill"></i>
                        </span>
                    </div>
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i><?= $LANG['date_locked_hint'] ?></small>
                    <?php endif; ?>
                </div>
                <!-- Shift -->
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $LANG['shifts'] ?> <span class="text-danger">*</span></label>
                    <select name="shift_id" id="add_shift_id" class="form-select" required>
                        <option value=""><?= $LANG['select_shift'] ?></option>
                        <?php foreach ($shifts as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$isSuperadmin): ?>
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i><?= $LANG['shift_select_hint'] ?></small>
                    <?php endif; ?>
                </div>
                <!-- Cashier Name -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['cashier_name'] ?> <span class="text-danger">*</span></label>
                    <?php if ($isSuperadmin): ?>
                    <select name="cashier_name" id="add_cashier_name" class="form-select" required>
                        <option value=""><?= $LANG['select_cashier'] ?></option>
                        <?php foreach ($dreportUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['full_name']) ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <div class="input-group">
                        <input type="text" class="form-control bg-light"
                               value="<?= htmlspecialchars($kasirName) ?>"
                               readonly>
                        <span class="input-group-text text-muted" title="<?= $LANG['cashier_locked'] ?>">
                            <i class="bi bi-lock-fill"></i>
                        </span>
                    </div>
                    <input type="hidden" name="cashier_name" value="<?= htmlspecialchars($kasirName) ?>">
                    <?php if ($kasirName === ''): ?>
                    <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= $LANG['cashier_name_not_found'] ?></small>
                    <?php else: ?>
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i><?= $LANG['cashier_auto_hint'] ?></small>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <!-- System Revenue (manual) — wajib diisi (Poin 5) -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['system_revenue'] ?> <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="add_system_revenue_display" class="form-control" placeholder="0"
                               inputmode="numeric" oninput="syncSystemRevenue(this); recalcManualTotal()" required>
                        <input type="hidden" name="system_revenue" id="add_system_revenue" value="0">
                    </div>
                    <small class="text-muted"><?= $LANG['system_revenue_manual_hint'] ?></small>
                </div>

                <!-- Payment Method Items -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['payment_breakdown'] ?> <span class="text-danger">*</span></label>
                    <div id="add_items_container">
                    </div>
                    <button type="button" class="btn btn-success btn-sm mt-1" onclick="addDreportRow()">
                        <i class="bi bi-plus-circle me-1"></i><?= $LANG['add_row'] ?>
                    </button>
                </div>

                <!-- Totals summary -->
                <div class="col-12">
                    <div class="card bg-light border-0">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small"><?= $LANG['total_manual'] ?></span>
                                <span class="fw-semibold" id="add_total_manual_display">Rp 0</span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="text-muted small"><?= $LANG['system_revenue'] ?></span>
                                <span class="fw-semibold" id="add_sysrev_display">Rp 0</span>
                            </div>
                            <hr class="my-1">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold"><?= $LANG['difference'] ?></span>
                                <span class="fw-bold" id="add_diff_display">Rp 0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes of Daily Reports -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['notes_daily_report'] ?></label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="<?= $LANG['notes_placeholder'] ?>"></textarea>
                </div>

                <!-- Divider -->
                <div class="col-12"><hr class="my-1"></div>

                <!-- Yesterday Cash Section Heading (Poin 7) -->
                <div class="col-12">
                    <h6 class="fw-bold text-secondary mb-0"><i class="bi bi-cash-coin me-1"></i><?= $LANG['yesterday_cash_section'] ?></h6>
                </div>

                <!-- Yesterday Cash Section: single (beda hari) atau multi-shift (hari sama) -->
                <?php
                $ycDisplayEntries = !empty($ycEntries) ? $ycEntries : [[
                    'total_cash'   => 0.0,
                    'field_suffix' => '',
                    'shift_name'   => '',
                    'label'        => 'yesterday_cash_total',
                    'is_same_day'  => false,
                    'is_last'      => true,
                ]];
                foreach ($ycDisplayEntries as $ycEntry):
                    $sfx        = $ycEntry['field_suffix'];
                    $dispId     = 'add_yc_total_display'    . $sfx;
                    $hiddenId   = 'add_yesterday_cash_total' . $sfx;
                    $hiddenName = 'yesterday_cash_total'     . $sfx;
                    $ycVal      = (float)$ycEntry['total_cash'];
                    // Label: same-day multi → "Yesterday Cash Total (Morning)" etc.
                    if ($ycEntry['label'] === 'yesterday_cash_total_shift' && $ycEntry['shift_name'] !== '') {
                        $ycLabel = sprintf(
                            htmlspecialchars($LANG['yesterday_cash_total_shift']),
                            '<strong>' . htmlspecialchars($ycEntry['shift_name']) . '</strong>'
                        );
                    } else {
                        $ycLabel = htmlspecialchars($LANG['yesterday_cash_total']);
                    }
                ?>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $ycLabel ?> <span class="text-danger">*</span></label>
                    <?php if ($isSuperadmin): ?>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="<?= $dispId ?>" class="form-control" placeholder="0"
                               inputmode="numeric"
                               value="<?= number_format($ycVal, 0, ',', '.') ?>"
                               oninput="syncYcField(this,'<?= $hiddenId ?>'); recalcYcBalance('add')">
                        <input type="hidden" name="<?= $hiddenName ?>" id="<?= $hiddenId ?>"
                               value="<?= $ycVal ?>">
                    </div>
                    <?php else: ?>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="<?= $dispId ?>" class="form-control bg-light" placeholder="0"
                               inputmode="numeric"
                               value="<?= number_format($ycVal, 0, ',', '.') ?>"
                               readonly>
                        <span class="input-group-text text-muted"
                              title="<?= htmlspecialchars($LANG['date_locked']) ?>">
                            <i class="bi bi-lock-fill"></i>
                        </span>
                        <input type="hidden" name="<?= $hiddenName ?>" id="<?= $hiddenId ?>"
                               value="<?= $ycVal ?>">
                    </div>
                    <?php endif; ?>
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i><?= $LANG['yesterday_cash_auto_hint'] ?></small>
                </div>
                <?php endforeach; ?>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $LANG['yesterday_cash_usage'] ?> <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="add_yc_usage_display" class="form-control" placeholder="0"
                               inputmode="numeric" value=""
                               oninput="syncYcField(this,'add_yesterday_cash_usage'); recalcYcBalance('add')">
                        <input type="hidden" name="yesterday_cash_usage" id="add_yesterday_cash_usage" value="0">
                    </div>
                </div>
                <!-- Yesterday Cash Optional Rows — before balance card -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-plus-square me-1 text-primary"></i><?= $LANG['optional_column_label'] ?>
                    </label>
                    <div id="add_optional_container"></div>
                    <button type="button" class="btn btn-primary btn-sm mt-1" onclick="addOptionalRow('add')">
                        <i class="bi bi-plus-circle me-1"></i><?= $LANG['add_optional_row'] ?>
                    </button>
                    <div class="mt-2">
                        <small class="text-muted"><i class="bi bi-info-circle me-1"></i><?= $LANG['yesterday_cash_optional_hint'] ?></small>
                    </div>
                    <input type="hidden" name="yesterday_cash_optional" id="add_yesterday_cash_optional" value="0">
                </div>

                <!-- Yesterday Cash Balance (auto calc):
                     base = last shift's total_cash − yesterday_cash_usage + optional_total (computed in JS) -->
                <div class="col-12">
                    <div class="card bg-light border-0">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small"><?= $LANG['yesterday_cash_optional_total'] ?></span>
                                <span class="fw-semibold" id="add_yc_optional_display">Rp 0</span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="text-muted small"><?= $LANG['yesterday_cash_balance'] ?></span>
                                <span class="fw-bold" id="add_yc_balance_display">Rp <?= number_format($ycBalanceBase, 0, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="yesterday_cash_balance" id="add_yesterday_cash_balance"
                           value="<?= $ycBalanceBase ?>">
                    <!-- JS needs to know which hidden field is the "last" base for recalc -->
                    <input type="hidden" id="add_yc_last_field_id" value="<?= htmlspecialchars($ycLastFieldId) ?>">
                </div>

                <!-- Notes of Cash (opsional) -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['notes_cash'] ?></label>
                    <textarea name="notes_cash" class="form-control" rows="2" placeholder="<?= $LANG['notes_placeholder'] ?>"></textarea>
                </div>

                <!-- Divider after Notes of Cash (Poin 8) -->
                <div class="col-12"><hr class="my-1"></div>

                <!-- Supporting Document Section Heading (Poin 9) -->
                <div class="col-12">
                    <h6 class="fw-bold text-secondary mb-0"><i class="bi bi-file-earmark-image me-1"></i><?= $LANG['supporting_document_section'] ?></h6>
                </div>

                <!-- Supporting Document Photos — sejajar 3 kolom (md ke atas), tumpuk di mobile -->
                <div class="col-12">
                    <div class="row g-3">

                        <!-- Summary Photo (wajib) — urutan pertama (Poin 10) -->
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold"><?= $LANG['summary_photo'] ?> <span class="text-danger">*</span></label>
                            <input type="hidden" name="camera_image_data" id="add_camera_image_data" value="">
                            <div id="add_camera_area">
                                <button type="button" class="btn btn-primary btn-sm" id="add_open_camera_btn"
                                        onclick="openDreportCamera('add')">
                                    <i class="bi bi-camera me-1"></i><?= $LANG['take_photo'] ?>
                                </button>
                            </div>
                            <div id="add_camera_stream" class="mt-2" style="display:none">
                                <video id="add_camera_video" autoplay playsinline
                                       style="max-width:100%;max-height:260px;border-radius:.375rem;background:#000"></video>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="captureDreportPhoto('add','img_preview')">
                                        <i class="bi bi-camera-fill me-1"></i><?= $LANG['capture'] ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeDreportCamera('add')">
                                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                                    </button>
                                </div>
                            </div>
                            <canvas id="add_camera_canvas" style="display:none"></canvas>
                            <div id="img_preview" class="mt-2"></div>
                            <small class="text-muted"><?= $LANG['image_hint_jpg'] ?></small>
                        </div>

                        <!-- Yesterday Cash Photo second (Poin 10) - moved below Summary -->
                        <!-- Yesterday Cash Photo (wajib) — urutan kedua (Poin 10) -->
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold"><?= $LANG['yesterday_cash_image'] ?> <span class="text-danger">*</span></label>
                            <input type="hidden" name="yc_camera_image_data" id="add_yc_camera_image_data" value="">
                            <div id="add_yc_camera_area">
                                <button type="button" class="btn btn-primary btn-sm" id="add_yc_open_camera_btn"
                                        onclick="openDreportCamera('add_yc')">
                                    <i class="bi bi-camera me-1"></i><?= $LANG['take_photo'] ?>
                                </button>
                            </div>
                            <div id="add_yc_camera_stream" class="mt-2" style="display:none">
                                <video id="add_yc_camera_video" autoplay playsinline
                                       style="max-width:100%;max-height:260px;border-radius:.375rem;background:#000"></video>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="captureDreportPhoto('add_yc','add_yc_img_preview')">
                                        <i class="bi bi-camera-fill me-1"></i><?= $LANG['capture'] ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeDreportCamera('add_yc')">
                                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                                    </button>
                                </div>
                            </div>
                            <canvas id="add_yc_camera_canvas" style="display:none"></canvas>
                            <div id="add_yc_img_preview" class="mt-2"></div>
                            <small class="text-muted"><?= $LANG['image_hint_jpg'] ?></small>
                        </div>

                        <!-- rPos Document Photo (wajib) — urutan ketiga (Poin 10) -->
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold"><?= $LANG['rpos_image'] ?> <span class="text-danger">*</span></label>
                            <input type="hidden" name="rpos_camera_image_data" id="add_rpos_camera_image_data" value="">
                            <div id="add_rpos_camera_area">
                                <button type="button" class="btn btn-primary btn-sm" id="add_rpos_open_camera_btn"
                                        onclick="openDreportCamera('add_rpos')">
                                    <i class="bi bi-camera me-1"></i><?= $LANG['take_photo'] ?>
                                </button>
                            </div>
                            <div id="add_rpos_camera_stream" class="mt-2" style="display:none">
                                <video id="add_rpos_camera_video" autoplay playsinline
                                       style="max-width:100%;max-height:260px;border-radius:.375rem;background:#000"></video>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="captureDreportPhoto('add_rpos','add_rpos_img_preview')">
                                        <i class="bi bi-camera-fill me-1"></i><?= $LANG['capture'] ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeDreportCamera('add_rpos')">
                                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                                    </button>
                                </div>
                            </div>
                            <canvas id="add_rpos_camera_canvas" style="display:none"></canvas>
                            <div id="add_rpos_img_preview" class="mt-2"></div>
                            <small class="text-muted"><?= $LANG['image_hint_jpg'] ?></small>
                        </div>

                    </div>
                </div>

                <!-- Submit -->
                <div class="col-12 d-flex gap-2 justify-content-end pt-2">
                    <a href="<?= url('arw_pos/manual_dreport.php') ?>" class="btn btn-sm btn-secondary">
                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                    </a>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-circle me-1"></i><?= $LANG['save'] ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.dreport-item-row { align-items: center; }
.dreport-diff-pos { color: #198754 !important; }
.dreport-diff-neg { color: #dc3545 !important; }
.dreport-diff-zero{ color: #6c757d !important; }
.pm-combo-wrap { position: relative; }
.pm-combo-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1055;
    background: #fff;
    border: 1px solid #ced4da;
    border-radius: 0 0 .375rem .375rem;
    max-height: 200px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
}
.pm-combo-dropdown.show { display: block; }
.pm-combo-item {
    padding: 7px 12px;
    cursor: pointer;
    font-size: .875rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pm-combo-item:hover, .pm-combo-item.active { background: #e9f3ff; color: #0d6efd; }
.pm-combo-item.pm-combo-empty { color: #adb5bd; cursor: default; }
.pm-combo-item.pm-combo-empty:hover { background: transparent; }
</style>

<script>
var paymentMethods = <?= json_encode($paymentMethods, JSON_HEX_TAG) ?>;
var dreportApiUrl  = <?= json_encode(url('arw_pos/manual_dreport.php')) ?>;
var langStrings = {
    select_payment_method: <?= json_encode($LANG['select_payment_method']) ?>,
    no_results: <?= json_encode($LANG['no_results']) ?>
};

function formatAmountInput(el) {
    let raw = el.value.replace(/[^0-9]/g, '');
    if (raw === '') { el.value = ''; return; }
    el.value = parseInt(raw, 10).toLocaleString('id-ID');
}

function parseAmount(str) {
    return parseFloat((str || '0').replace(/\./g, '').replace(',', '.')) || 0;
}

function formatRp(num) {
    return 'Rp ' + Math.abs(num).toLocaleString('id-ID');
}

function recalcManualTotal() {
    var inputs = document.querySelectorAll('#add_items_container .dreport-amount-input');
    var total = 0;
    inputs.forEach(function(inp) { total += parseAmount(inp.value); });

    var sysRev = parseFloat(document.getElementById('add_system_revenue').value) || 0;
    var diff   = total - sysRev;

    document.getElementById('add_total_manual_display').textContent = formatRp(total);
    document.getElementById('add_sysrev_display').textContent       = formatRp(sysRev);

    var diffEl = document.getElementById('add_diff_display');
    diffEl.textContent = diff > 0 ? '+' + formatRp(diff) : diff < 0 ? '-' + formatRp(diff) : formatRp(0);
    diffEl.className   = 'fw-bold ' + (diff > 0 ? 'dreport-diff-pos' : diff < 0 ? 'dreport-diff-neg' : 'dreport-diff-zero');
}

function syncSystemRevenue(el) {
    var raw = el.value.replace(/[^0-9]/g, '');
    if (raw === '') { el.value = ''; document.getElementById('add_system_revenue').value = 0; return; }
    el.value = parseInt(raw, 10).toLocaleString('id-ID');
    document.getElementById('add_system_revenue').value = parseInt(raw, 10);
}

function resolvePaymentMethodId(name) {
    var found = paymentMethods.find(function(pm) {
        return pm.name.trim().toLowerCase() === (name || '').trim().toLowerCase();
    });
    return found ? found.id : 0;
}

/* ---- Custom Combobox ---- */
function renderDropdown(dropdown, query) {
    var q = (query || '').trim().toLowerCase();
    var filtered = q === ''
        ? paymentMethods
        : paymentMethods.filter(function(pm) { return pm.name.toLowerCase().indexOf(q) !== -1; });

    if (filtered.length === 0) {
        dropdown.innerHTML = '<div class="pm-combo-item pm-combo-empty">' + langStrings.no_results + '</div>';
    } else {
        dropdown.innerHTML = filtered.map(function(pm) {
            return '<div class="pm-combo-item" data-id="' + pm.id + '" data-name="' + pm.name.replace(/"/g,'&quot;') + '">'
                 + pm.name.replace(/</g,'&lt;') + '</div>';
        }).join('');
        dropdown.querySelectorAll('.pm-combo-item').forEach(function(item) {
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                var row     = dropdown.closest('.dreport-item-row');
                var text    = row.querySelector('.dreport-pm-text');
                var hid     = row.querySelector('.dreport-pm-hidden');
                var nameHid = row.querySelector('.dreport-pm-name');
                text.value    = item.dataset.name;
                hid.value     = item.dataset.id;
                nameHid.value = item.dataset.name;
                closeAllDropdowns();
            });
        });
    }
    dropdown.classList.add('show');
}

function closeAllDropdowns() {
    document.querySelectorAll('.pm-combo-dropdown.show').forEach(function(d) { d.classList.remove('show'); });
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.pm-combo-wrap')) closeAllDropdowns();
});

function initCombo(textInput) {
    var wrap     = textInput.closest('.pm-combo-wrap');
    var dropdown = wrap.querySelector('.pm-combo-dropdown');
    var hidden   = wrap.querySelector('.dreport-pm-hidden');
    var nameHid  = wrap.querySelector('.dreport-pm-name');

    textInput.addEventListener('focus', function() {
        renderDropdown(dropdown, textInput.value);
    });
    textInput.addEventListener('click', function() {
        renderDropdown(dropdown, textInput.value);
    });
    textInput.addEventListener('input', function() {
        hidden.value  = resolvePaymentMethodId(textInput.value) || 0;
        nameHid.value = textInput.value;
        renderDropdown(dropdown, textInput.value);
    });
    textInput.addEventListener('blur', function() {
        setTimeout(function() { dropdown.classList.remove('show'); }, 150);
    });
}

function addDreportRow(pmId, amount) {
    pmId   = pmId   || '';
    amount = amount || '';
    var pmName = '';
    if (pmId) {
        var found = paymentMethods.find(function(pm) { return pm.id == pmId; });
        if (found) pmName = found.name;
    }
    var html = '<div class="row g-2 mb-2 dreport-item-row">'
        + '<div class="col-5 col-sm-5"><div class="pm-combo-wrap">'
        + '<input type="text" class="form-control form-control-sm dreport-pm-text"'
        + ' placeholder="' + (langStrings.select_payment_method || 'Payment Method') + '"'
        + ' value="' + pmName.replace(/"/g,'&quot;') + '" autocomplete="off">'
        + '<input type="hidden" name="payment_method_id[]"   class="dreport-pm-hidden" value="' + (pmId || '0') + '">'
        + '<input type="hidden" name="payment_method_name[]" class="dreport-pm-name"   value="' + pmName.replace(/"/g,'&quot;') + '">'
        + '<div class="pm-combo-dropdown"></div>'
        + '</div></div>'
        + '<div class="col-5 col-sm-6"><div class="input-group input-group-sm">'
        + '<span class="input-group-text">Rp</span>'
        + '<input type="text" name="amount[]" class="form-control dreport-amount-input" placeholder="0" inputmode="numeric"'
        + ' value="' + (amount ? parseInt(amount).toLocaleString('id-ID') : '') + '"'
        + ' oninput="formatAmountInput(this);recalcManualTotal()">'
        + '</div></div>'
        + '<div class="col-2 col-sm-1"><button type="button" class="btn btn-danger btn-sm w-100" onclick="removeDreportRow(this)">'
        + '<i class="bi bi-dash"></i></button></div>'
        + '</div>';
    var container = document.getElementById('add_items_container');
    container.insertAdjacentHTML('beforeend', html);
    var lastRow   = container.lastElementChild;
    initCombo(lastRow.querySelector('.dreport-pm-text'));
}

function removeDreportRow(btn) {
    btn.closest('.dreport-item-row').remove();
    recalcManualTotal();
}

function openDreportCamera(prefix) {
    var streamDiv = document.getElementById(prefix + '_camera_stream');
    var video     = document.getElementById(prefix + '_camera_video');
    streamDiv.style.display = 'block';
    document.getElementById(prefix + '_open_camera_btn').style.display = 'none';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
        .then(function(stream) {
            video._stream = stream;
            video.srcObject = stream;
        })
        .catch(function() {
            alert('Cannot access camera.');
            closeDreportCamera(prefix);
        });
}

function captureDreportPhoto(prefix, previewId) {
    var video  = document.getElementById(prefix + '_camera_video');
    var canvas = document.getElementById(prefix + '_camera_canvas');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    var dataUri = canvas.toDataURL('image/jpeg', 0.92);

    // Check size ~5MB (base64 is ~1.37x raw)
    var oversize_msg = <?= json_encode($LANG['image_oversize_alert']) ?>;
    var approxBytes = Math.round((dataUri.length - 'data:image/jpeg;base64,'.length) * 0.75);
    if (approxBytes > 5 * 1024 * 1024) {
        alert(oversize_msg);
        closeDreportCamera(prefix);
        return;
    }

    // Map prefix to hidden input id
    var hiddenMap = {
        'add':      'add_camera_image_data',
        'add_yc':   'add_yc_camera_image_data',
        'add_rpos': 'add_rpos_camera_image_data',
    };
    var hiddenId = hiddenMap[prefix] || (prefix + '_camera_image_data');
    document.getElementById(hiddenId).value = dataUri;
    document.getElementById(previewId || 'img_preview').innerHTML =
        '<img src="' + dataUri + '" class="img-thumbnail mt-1" style="max-height:180px;max-width:100%;object-fit:cover">';
    closeDreportCamera(prefix);
}

function closeDreportCamera(prefix) {
    var video = document.getElementById(prefix + '_camera_video');
    if (video && video._stream) {
        video._stream.getTracks().forEach(function(t) { t.stop(); });
        video._stream = null;
    }
    if (video) video.srcObject = null;
    var streamDiv = document.getElementById(prefix + '_camera_stream');
    if (streamDiv) streamDiv.style.display = 'none';
    var btn = document.getElementById(prefix + '_open_camera_btn');
    if (btn) btn.style.display = '';
}

function syncYcField(el, hiddenId) {
    var raw = el.value.replace(/[^0-9]/g, '');
    if (raw === '') { el.value = ''; document.getElementById(hiddenId).value = 0; return; }
    el.value = parseInt(raw, 10).toLocaleString('id-ID');
    document.getElementById(hiddenId).value = parseInt(raw, 10);
}

function recalcOptionalTotal(prefix) {
    var inputs = document.querySelectorAll('#' + prefix + '_optional_container .dreport-optional-amount');
    var total = 0;
    inputs.forEach(function(inp) { total += parseAmount(inp.value); });
    var optHidden = document.getElementById(prefix + '_yesterday_cash_optional');
    if (optHidden) optHidden.value = total;
    var optDisplay = document.getElementById(prefix + '_yc_optional_display');
    if (optDisplay) optDisplay.textContent = 'Rp ' + Math.abs(total).toLocaleString('id-ID');
    return total;
}

function addOptionalRow(prefix, name, amount) {
    name   = name   || '';
    amount = amount || '';
    var langName   = <?= json_encode($LANG['optional_name']) ?>;
    var langAmount = <?= json_encode($LANG['optional_amount']) ?>;
    var html = '<div class="row g-2 mb-2 dreport-optional-row align-items-center">'
        + '<div class="col-5 col-sm-6">'
        + '<input type="text" name="optional_name[]" class="form-control form-control-sm"'
        + ' placeholder="' + langName + '"'
        + ' value="' + name.replace(/"/g, '&quot;') + '">'
        + '</div>'
        + '<div class="col-5 col-sm-5"><div class="input-group input-group-sm">'
        + '<span class="input-group-text">Rp</span>'
        + '<input type="text" name="optional_amount[]" class="form-control dreport-optional-amount"'
        + ' placeholder="0" inputmode="numeric"'
        + ' value="' + (amount ? parseInt(amount).toLocaleString('id-ID') : '') + '"'
        + ' oninput="formatAmountInput(this); recalcOptionalTotal(\'' + prefix + '\'); recalcYcBalance(\'' + prefix + '\')">'
        + '</div></div>'
        + '<div class="col-2 col-sm-1">'
        + '<button type="button" class="btn btn-danger btn-sm w-100"'
        + ' onclick="removeOptionalRow(this, \'' + prefix + '\')">'
        + '<i class="bi bi-dash"></i></button>'
        + '</div>'
        + '</div>';
    document.getElementById(prefix + '_optional_container').insertAdjacentHTML('beforeend', html);
}

function removeOptionalRow(btn, prefix) {
    btn.closest('.dreport-optional-row').remove();
    recalcOptionalTotal(prefix);
    recalcYcBalance(prefix);
}

function recalcYcBalance(prefix) {
    // Balance base = last shift's total_cash value (tracked via add_yc_last_field_id)
    var lastFieldIdEl = document.getElementById(prefix + '_yc_last_field_id');
    var lastFieldId   = lastFieldIdEl ? lastFieldIdEl.value : (prefix + '_yesterday_cash_total');
    var baseEl        = document.getElementById(lastFieldId);
    var total    = baseEl ? (parseFloat(baseEl.value) || 0) : 0;
    var usage    = parseFloat(document.getElementById(prefix + '_yesterday_cash_usage').value) || 0;
    var optional = recalcOptionalTotal(prefix);
    var bal      = total - usage + optional;
    document.getElementById(prefix + '_yesterday_cash_balance').value = bal;
    document.getElementById(prefix + '_yc_balance_display').textContent = 'Rp ' + Math.abs(bal).toLocaleString('id-ID');
    var el = document.getElementById(prefix + '_yc_balance_display');
    el.className = 'fw-bold ' + (bal > 0 ? 'dreport-diff-pos' : bal < 0 ? 'dreport-diff-neg' : 'dreport-diff-zero');
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#add_items_container .dreport-amount-input').forEach(function(inp){
        formatAmountInput(inp);
    });
    recalcManualTotal();

    // Poin 5: client-side validation — system_revenue wajib > 0 sebelum submit
    var addForm = document.querySelector('form[method="post"]');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            var sysRev = parseFloat(document.getElementById('add_system_revenue').value) || 0;
            if (sysRev <= 0) {
                e.preventDefault();
                var msg = <?= json_encode($LANG['system_revenue_required']) ?>;
                var el  = document.getElementById('add_system_revenue_display');
                el.classList.add('is-invalid');
                el.focus();
                // Show inline error if not already shown
                if (!el.nextElementSibling || !el.nextElementSibling.classList.contains('invalid-feedback')) {
                    var fb = document.createElement('div');
                    fb.className = 'invalid-feedback d-block';
                    fb.textContent = msg;
                    el.parentNode.insertAdjacentElement('afterend', fb);
                }
            }
        });
        document.getElementById('add_system_revenue_display').addEventListener('input', function() {
            this.classList.remove('is-invalid');
            var fb = this.parentNode.nextElementSibling;
            if (fb && fb.classList.contains('invalid-feedback')) fb.remove();
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>