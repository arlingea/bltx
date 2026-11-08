<?php
// File: arw_pos/manual_dreport_edit.php
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

// Access: user with can_update permission & superadmin
requireDreportAccess();

if (!isDreportSuperadmin() && !checkPermission('dreport', 'can_update')) {
    redirect(url('arw_pos/manual_dreport.php'));
    exit;
}

$isSuperadmin = isDreportSuperadmin();

$page_title = 'Edit Cashier Report';
$LANG       = loadLanguage();
$csrfToken  = generateCsrfToken();

// ================================================================
// HANDLE POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(url('arw_pos/manual_dreport_edit.php?id=' . (int)($_POST['report_id'] ?? 0)));
        exit;
    }

    $editId   = (int)($_POST['report_id'] ?? 0);
    $existing = getDreportById($editId);

    if (!$existing) {
        bltx_flash_error($LANG['dreport_not_found']);
        redirect(url('arw_pos/manual_dreport.php'));
        exit;
    }

    // ---- Poin 5: System Revenue wajib — server-side validation ----
    $postSysRev = (float)str_replace(['.', ','], ['', '.'], $_POST['system_revenue'] ?? '');
    if ($postSysRev <= 0) {
        bltx_flash_error($LANG['system_revenue_required']);
        redirect(url('arw_pos/manual_dreport_edit.php?id=' . $editId));
        exit;
    }

    // ---- summary image ----
    $imageFile = $existing['image_file'] ?? '';
    $cameraData = $_POST['camera_image_data'] ?? '';
    if ($cameraData !== '') {
        $newImg = saveDreportCameraByType($cameraData, 'summary');
        if ($newImg !== '') { deleteDreportImageByType($imageFile, 'summary'); $imageFile = $newImg; }
    } elseif (!empty($_FILES['report_image']['tmp_name'])) {
        $newImg = saveDreportImageByType($_FILES['report_image'], 'summary');
        if ($newImg !== '') { deleteDreportImageByType($imageFile, 'summary'); $imageFile = $newImg; }
    }
    $imageCleared = false;
    if (!empty($_POST['remove_image']) && $_POST['remove_image'] === '1') {
        deleteDreportImageByType($imageFile, 'summary');
        $imageFile    = '';
        $imageCleared = true;
    }

    // ---- yesterday cash image ----
    $ycImageFile  = $existing['yesterday_cash_image'] ?? '';
    $ycCameraData = $_POST['yc_camera_image_data'] ?? '';
    if ($ycCameraData !== '') {
        $newYc = saveDreportCameraByType($ycCameraData, 'yesterday_cash');
        if ($newYc !== '') { deleteDreportImageByType($ycImageFile, 'yesterday_cash'); $ycImageFile = $newYc; }
    } elseif (!empty($_FILES['yc_report_image']['tmp_name'])) {
        $newYc = saveDreportImageByType($_FILES['yc_report_image'], 'yesterday_cash');
        if ($newYc !== '') { deleteDreportImageByType($ycImageFile, 'yesterday_cash'); $ycImageFile = $newYc; }
    }
    $ycCleared = false;
    if (!empty($_POST['remove_yc_image']) && $_POST['remove_yc_image'] === '1') {
        deleteDreportImageByType($ycImageFile, 'yesterday_cash');
        $ycImageFile = '';
        $ycCleared   = true;
    }

    // ---- rpos image ----
    $rposImageFile  = $existing['rpos_image'] ?? '';
    $rposCameraData = $_POST['rpos_camera_image_data'] ?? '';
    if ($rposCameraData !== '') {
        $newRpos = saveDreportCameraByType($rposCameraData, 'rpos');
        if ($newRpos !== '') { deleteDreportImageByType($rposImageFile, 'rpos'); $rposImageFile = $newRpos; }
    } elseif (!empty($_FILES['rpos_report_image']['tmp_name'])) {
        $newRpos = saveDreportImageByType($_FILES['rpos_report_image'], 'rpos');
        if ($newRpos !== '') { deleteDreportImageByType($rposImageFile, 'rpos'); $rposImageFile = $newRpos; }
    }
    $rposCleared = false;
    if (!empty($_POST['remove_rpos_image']) && $_POST['remove_rpos_image'] === '1') {
        deleteDreportImageByType($rposImageFile, 'rpos');
        $rposImageFile = '';
        $rposCleared   = true;
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

    $data = [
        'report_date'   => sanitize($_POST['report_date']   ?? $existing['report_date']),
        'cashier_name'  => sanitize(trim($_POST['cashier_name'] ?? $existing['cashier_name'])),
        'shift_id'      => (int)($_POST['shift_id']         ?? $existing['shift_id']),
        'system_revenue'=> $postSysRev,
        'notes'         => sanitize($_POST['notes']         ?? ''),
        'image_file'    => $imageFile,
        'image_cleared' => $imageCleared,
        'yesterday_cash_total'         => (float)str_replace(['.', ','], ['', '.'], $_POST['yesterday_cash_total'] ?? '0'),
        'yesterday_cash_usage'         => (float)str_replace(['.', ','], ['', '.'], $_POST['yesterday_cash_usage'] ?? '0'),
        'yesterday_cash_optional'      => (float)str_replace(['.', ','], ['', '.'], $_POST['yesterday_cash_optional'] ?? '0'),
        'yesterday_cash_balance'       => (float)str_replace(['.', ','], ['', '.'], $_POST['yesterday_cash_balance'] ?? '0'),
        'yesterday_cash_image'         => $ycImageFile,
        'yesterday_cash_image_cleared' => $ycCleared,
        'notes_cash'                   => sanitize($_POST['notes_cash'] ?? ''),
        'rpos_image'                   => $rposImageFile,
        'rpos_image_cleared'           => $rposCleared,
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

    if (updateDreport($editId, $data, $items, $optionalItems)) {
        bltx_flash_success($LANG['dreport_updated']);
        redirect(url('arw_pos/manual_dreport.php'));
    } else {
        bltx_flash_error($LANG['dreport_update_error']);
        redirect(url('arw_pos/manual_dreport_edit.php?id=' . $editId));
    }
    exit;
}

// ================================================================
// FETCH REPORT TO EDIT
// ================================================================
$reportId    = (int)($_GET['id'] ?? 0);
$report      = $reportId > 0 ? getDreportById($reportId) : null;
$reportItems = $report ? getDreportItems($reportId) : [];
$reportOptionalItems = $report ? getDreportOptionalItems($reportId) : [];

if (!$report) {
    bltx_flash_error($LANG['dreport_not_found']);
    redirect(url('arw_pos/manual_dreport.php'));
    exit;
}

$paymentMethods = getDreportPaymentMethods();
$shifts         = getDreportShifts();
$dreportUsers   = getDreportAllUsers();

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
    <h2 class="page-title-text"><i class="bi bi-pencil-square"></i><?= $LANG['edit_cashier_report'] ?></h2>
    <div class="page-actions">
        <a href="<?= url('arw_pos/manual_dreport.php') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i><?= $LANG['back'] ?>
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="<?= url('arw_pos/manual_dreport_edit.php') ?>" enctype="multipart/form-data" id="editDreportForm">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="report_id"  value="<?= $reportId ?>">

            <div class="row g-3">
                <!-- Date -->
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $LANG['date'] ?> <span class="text-danger">*</span></label>
                    <input type="date" name="report_date" id="edit_report_date" class="form-control"
                           value="<?= htmlspecialchars($report['report_date']) ?>" required>
                </div>
                <!-- Shift -->
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $LANG['shifts'] ?> <span class="text-danger">*</span></label>
                    <select name="shift_id" id="edit_shift_id" class="form-select" required>
                        <option value=""><?= $LANG['select_shift'] ?></option>
                        <?php foreach ($shifts as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $report['shift_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?> (<?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Cashier Name -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['cashier_name'] ?> <span class="text-danger">*</span></label>
                    <select name="cashier_name" id="edit_cashier_name" class="form-select" required>
                        <option value=""><?= $LANG['select_cashier'] ?></option>
                        <?php foreach ($dreportUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['full_name']) ?>"
                                <?= $u['full_name'] === ($report['cashier_name'] ?? '') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['username']) ?>)
                        </option>
                        <?php endforeach; ?>
                        <?php
                        // If stored cashier_name is not in the users list (e.g. user deleted), add it as option
                        $existingCashier = $report['cashier_name'] ?? '';
                        $inList = false;
                        foreach ($dreportUsers as $u) {
                            if ($u['full_name'] === $existingCashier) { $inList = true; break; }
                        }
                        if (!$inList && $existingCashier !== ''):
                        ?>
                        <option value="<?= htmlspecialchars($existingCashier) ?>" selected>
                            <?= htmlspecialchars($existingCashier) ?> (<?= $LANG['inactive_user'] ?>)
                        </option>
                        <?php endif; ?>
                    </select>
                </div>
                <!-- System Revenue (manual) — wajib diisi (Poin 5) -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['system_revenue'] ?> <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="edit_system_revenue_display" class="form-control" placeholder="0"
                               inputmode="numeric"
                               value="<?= number_format((float)$report['system_revenue'], 0, ',', '.') ?>"
                               oninput="syncSystemRevenue(this); recalcManualTotal()" required>
                        <input type="hidden" name="system_revenue" id="edit_system_revenue" value="<?= (float)$report['system_revenue'] ?>">
                    </div>
                    <small class="text-muted"><?= $LANG['system_revenue_manual_hint'] ?></small>
                </div>

                <!-- Payment Method Items -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['payment_breakdown'] ?> <span class="text-danger">*</span></label>
                    <div id="edit_items_container">
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
                                <span class="fw-semibold" id="edit_total_manual_display">Rp 0</span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="text-muted small"><?= $LANG['system_revenue'] ?></span>
                                <span class="fw-semibold" id="edit_sysrev_display">Rp <?= number_format((float)$report['system_revenue'], 0, ',', '.') ?></span>
                            </div>
                            <hr class="my-1">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold"><?= $LANG['difference'] ?></span>
                                <span class="fw-bold" id="edit_diff_display">Rp 0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes of Daily Reports -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['notes_daily_report'] ?></label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="<?= $LANG['notes_placeholder'] ?>"><?= htmlspecialchars($report['notes'] ?? '') ?></textarea>
                </div>

                <!-- Divider -->
                <div class="col-12"><hr class="my-1"></div>

                <!-- Yesterday Cash Section Heading (Poin 7) -->
                <div class="col-12">
                    <h6 class="fw-bold text-secondary mb-0"><i class="bi bi-cash-coin me-1"></i><?= $LANG['yesterday_cash_section'] ?></h6>
                </div>

                <!-- Yesterday Cash Section -->
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $LANG['yesterday_cash_total'] ?> <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="edit_yc_total_display" class="form-control" placeholder="0"
                               inputmode="numeric"
                               value="<?= number_format((float)($report['yesterday_cash_total'] ?? 0), 0, ',', '.') ?>"
                               oninput="syncYcField(this,'edit_yesterday_cash_total'); recalcYcBalance('edit')">
                        <input type="hidden" name="yesterday_cash_total" id="edit_yesterday_cash_total"
                               value="<?= (float)($report['yesterday_cash_total'] ?? 0) ?>">
                    </div>
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i><?= $LANG['yesterday_cash_auto_hint'] ?></small>
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold"><?= $LANG['yesterday_cash_usage'] ?> <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="edit_yc_usage_display" class="form-control" placeholder="0"
                               inputmode="numeric"
                               value="<?= number_format((float)($report['yesterday_cash_usage'] ?? 0), 0, ',', '.') ?>"
                               oninput="syncYcField(this,'edit_yesterday_cash_usage'); recalcYcBalance('edit')">
                        <input type="hidden" name="yesterday_cash_usage" id="edit_yesterday_cash_usage"
                               value="<?= (float)($report['yesterday_cash_usage'] ?? 0) ?>">
                    </div>
                </div>
                <!-- Yesterday Cash Optional Rows — before balance card -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-plus-square me-1 text-primary"></i><?= $LANG['optional_column_label'] ?>
                    </label>
                    <div id="edit_optional_container"></div>
                    <button type="button" class="btn btn-primary btn-sm mt-1" onclick="addOptionalRow('edit')">
                        <i class="bi bi-plus-circle me-1"></i><?= $LANG['add_optional_row'] ?>
                    </button>
                    <div class="mt-2">
                        <small class="text-muted"><i class="bi bi-info-circle me-1"></i><?= $LANG['yesterday_cash_optional_hint'] ?></small>
                    </div>
                    <input type="hidden" name="yesterday_cash_optional" id="edit_yesterday_cash_optional"
                           value="<?= (float)($report['yesterday_cash_optional'] ?? 0) ?>">
                </div>

                <!-- Yesterday Cash Balance (auto calc) -->
                <div class="col-12">
                    <div class="card bg-light border-0">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small"><?= $LANG['yesterday_cash_optional_total'] ?></span>
                                <span class="fw-semibold" id="edit_yc_optional_display">Rp <?= number_format((float)($report['yesterday_cash_optional'] ?? 0), 0, ',', '.') ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="text-muted small"><?= $LANG['yesterday_cash_balance'] ?></span>
                                <?php $ycBal = (float)($report['yesterday_cash_balance'] ?? 0); ?>
                                <span class="fw-bold <?= $ycBal >= 0 ? 'dreport-diff-pos' : 'dreport-diff-neg' ?>"
                                      id="edit_yc_balance_display">
                                    Rp <?= number_format(abs($ycBal), 0, ',', '.') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="yesterday_cash_balance" id="edit_yesterday_cash_balance"
                           value="<?= (float)($report['yesterday_cash_balance'] ?? 0) ?>">
                </div>

                <!-- Notes of Cash (opsional) -->
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= $LANG['notes_cash'] ?></label>
                    <textarea name="notes_cash" class="form-control" rows="2" placeholder="<?= $LANG['notes_placeholder'] ?>"><?= htmlspecialchars($report['notes_cash'] ?? '') ?></textarea>
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

                        <!-- Current Summary Image + Summary Photo — urutan pertama (Poin 10) -->
                        <div class="col-12 col-md-4">
                            <?php if (!empty($report['image_file'])): ?>
                            <label class="form-label fw-semibold"><?= $LANG['current_image'] ?></label>
                            <div class="mb-2">
                                <a href="<?= dreportImageUrl($report['image_file']) ?>" target="_blank">
                                    <img src="<?= dreportImageUrl($report['image_file']) ?>" class="img-thumbnail" style="max-height:150px;object-fit:cover">
                                </a>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_img">
                                <label class="form-check-label small text-danger" for="remove_img">
                                    <?= $LANG['remove_image'] ?>
                                </label>
                            </div>
                            <?php endif; ?>
                            <label class="form-label fw-semibold"><?= $LANG['summary_photo'] ?> <span class="text-danger">*</span></label>
                            <input type="hidden" name="camera_image_data" id="edit_camera_image_data" value="">
                            <div id="edit_camera_area">
                                <button type="button" class="btn btn-primary btn-sm" id="edit_open_camera_btn"
                                        onclick="openDreportCamera('edit')">
                                    <i class="bi bi-camera me-1"></i><?= $LANG['take_photo'] ?>
                                </button>
                            </div>
                            <div id="edit_camera_stream" class="mt-2" style="display:none">
                                <video id="edit_camera_video" autoplay playsinline
                                       style="max-width:100%;max-height:260px;border-radius:.375rem;background:#000"></video>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="captureDreportPhoto('edit','img_preview')">
                                        <i class="bi bi-camera-fill me-1"></i><?= $LANG['capture'] ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeDreportCamera('edit')">
                                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                                    </button>
                                </div>
                            </div>
                            <canvas id="edit_camera_canvas" style="display:none"></canvas>
                            <div id="img_preview" class="mt-2"></div>
                            <small class="text-muted"><?= $LANG['image_hint_jpg'] ?></small>
                        </div>

                        <!-- Current Yesterday Cash Image + Yesterday Cash Photo — urutan kedua (Poin 10) -->
                        <div class="col-12 col-md-4">
                            <?php if (!empty($report['yesterday_cash_image'])): ?>
                            <label class="form-label fw-semibold"><?= $LANG['current_yesterday_cash_image'] ?></label>
                            <div class="mb-2">
                                <a href="<?= dreportImageUrlByType($report['yesterday_cash_image'], 'yesterday_cash') ?>" target="_blank">
                                    <img src="<?= dreportImageUrlByType($report['yesterday_cash_image'], 'yesterday_cash') ?>" class="img-thumbnail" style="max-height:150px;object-fit:cover">
                                </a>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="remove_yc_image" value="1" id="remove_yc_img">
                                <label class="form-check-label small text-danger" for="remove_yc_img">
                                    <?= $LANG['remove_yesterday_cash_image'] ?>
                                </label>
                            </div>
                            <?php endif; ?>
                            <label class="form-label fw-semibold"><?= $LANG['yesterday_cash_image'] ?> <span class="text-danger">*</span></label>
                            <input type="hidden" name="yc_camera_image_data" id="edit_yc_camera_image_data" value="">
                            <div id="edit_yc_camera_area">
                                <button type="button" class="btn btn-primary btn-sm" id="edit_yc_open_camera_btn"
                                        onclick="openDreportCamera('edit_yc')">
                                    <i class="bi bi-camera me-1"></i><?= $LANG['take_photo'] ?>
                                </button>
                            </div>
                            <div id="edit_yc_camera_stream" class="mt-2" style="display:none">
                                <video id="edit_yc_camera_video" autoplay playsinline
                                       style="max-width:100%;max-height:260px;border-radius:.375rem;background:#000"></video>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="captureDreportPhoto('edit_yc','edit_yc_img_preview')">
                                        <i class="bi bi-camera-fill me-1"></i><?= $LANG['capture'] ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeDreportCamera('edit_yc')">
                                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                                    </button>
                                </div>
                            </div>
                            <canvas id="edit_yc_camera_canvas" style="display:none"></canvas>
                            <div id="edit_yc_img_preview" class="mt-2"></div>
                            <small class="text-muted"><?= $LANG['image_hint_jpg'] ?></small>
                        </div>

                        <!-- Current rPos Image + rPos Document Photo — urutan ketiga (Poin 10) -->
                        <div class="col-12 col-md-4">
                            <?php if (!empty($report['rpos_image'])): ?>
                            <label class="form-label fw-semibold"><?= $LANG['current_rpos_image'] ?></label>
                            <div class="mb-2">
                                <a href="<?= dreportImageUrlByType($report['rpos_image'], 'rpos') ?>" target="_blank">
                                    <img src="<?= dreportImageUrlByType($report['rpos_image'], 'rpos') ?>" class="img-thumbnail" style="max-height:150px;object-fit:cover">
                                </a>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="remove_rpos_image" value="1" id="remove_rpos_img">
                                <label class="form-check-label small text-danger" for="remove_rpos_img">
                                    <?= $LANG['remove_rpos_image'] ?>
                                </label>
                            </div>
                            <?php endif; ?>
                            <label class="form-label fw-semibold"><?= $LANG['rpos_image'] ?> <span class="text-danger">*</span></label>
                            <input type="hidden" name="rpos_camera_image_data" id="edit_rpos_camera_image_data" value="">
                            <div id="edit_rpos_camera_area">
                                <button type="button" class="btn btn-primary btn-sm" id="edit_rpos_open_camera_btn"
                                        onclick="openDreportCamera('edit_rpos')">
                                    <i class="bi bi-camera me-1"></i><?= $LANG['take_photo'] ?>
                                </button>
                            </div>
                            <div id="edit_rpos_camera_stream" class="mt-2" style="display:none">
                                <video id="edit_rpos_camera_video" autoplay playsinline
                                       style="max-width:100%;max-height:260px;border-radius:.375rem;background:#000"></video>
                                <div class="mt-2 d-flex gap-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="captureDreportPhoto('edit_rpos','edit_rpos_img_preview')">
                                        <i class="bi bi-camera-fill me-1"></i><?= $LANG['capture'] ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeDreportCamera('edit_rpos')">
                                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                                    </button>
                                </div>
                            </div>
                            <canvas id="edit_rpos_camera_canvas" style="display:none"></canvas>
                            <div id="edit_rpos_img_preview" class="mt-2"></div>
                            <small class="text-muted"><?= $LANG['image_hint_jpg'] ?></small>
                        </div>

                    </div>
                </div>

                <!-- Submit -->
                <div class="col-12 d-flex gap-2 justify-content-end pt-2">
                    <a href="<?= url('arw_pos/manual_dreport.php') ?>" class="btn btn-sm btn-secondary">
                        <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                    </a>
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-check-circle me-1"></i><?= $LANG['update'] ?>
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
    var inputs = document.querySelectorAll('#edit_items_container .dreport-amount-input');
    var total = 0;
    inputs.forEach(function(inp) { total += parseAmount(inp.value); });

    var sysRev = parseFloat(document.getElementById('edit_system_revenue').value) || 0;
    var diff   = total - sysRev;

    document.getElementById('edit_total_manual_display').textContent = formatRp(total);
    document.getElementById('edit_sysrev_display').textContent       = formatRp(sysRev);

    var diffEl = document.getElementById('edit_diff_display');
    diffEl.textContent = diff > 0 ? '+' + formatRp(diff) : diff < 0 ? '-' + formatRp(diff) : formatRp(0);
    diffEl.className   = 'fw-bold ' + (diff > 0 ? 'dreport-diff-pos' : diff < 0 ? 'dreport-diff-neg' : 'dreport-diff-zero');
}

function syncSystemRevenue(el) {
    var raw = el.value.replace(/[^0-9]/g, '');
    if (raw === '') { el.value = ''; document.getElementById('edit_system_revenue').value = 0; return; }
    el.value = parseInt(raw, 10).toLocaleString('id-ID');
    document.getElementById('edit_system_revenue').value = parseInt(raw, 10);
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

function addDreportRow(pmId, amount, pmNameOverride) {
    pmId   = pmId   || '';
    amount = amount || '';
    var pmName = pmNameOverride || '';
    if (!pmName && pmId) {
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
    var container = document.getElementById('edit_items_container');
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

    var oversize_msg = <?= json_encode($LANG['image_oversize_alert']) ?>;
    var approxBytes = Math.round((dataUri.length - 'data:image/jpeg;base64,'.length) * 0.75);
    if (approxBytes > 5 * 1024 * 1024) {
        alert(oversize_msg);
        closeDreportCamera(prefix);
        return;
    }

    var hiddenMap = {
        'edit':      'edit_camera_image_data',
        'edit_yc':   'edit_yc_camera_image_data',
        'edit_rpos': 'edit_rpos_camera_image_data',
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
    var total    = parseFloat(document.getElementById(prefix + '_yesterday_cash_total').value) || 0;
    var usage    = parseFloat(document.getElementById(prefix + '_yesterday_cash_usage').value)  || 0;
    var optional = recalcOptionalTotal(prefix);
    var bal      = total - usage + optional;
    document.getElementById(prefix + '_yesterday_cash_balance').value = bal;
    var el = document.getElementById(prefix + '_yc_balance_display');
    el.textContent = 'Rp ' + Math.abs(bal).toLocaleString('id-ID');
    el.className   = 'fw-bold ' + (bal > 0 ? 'dreport-diff-pos' : bal < 0 ? 'dreport-diff-neg' : 'dreport-diff-zero');
}

var reportItems = <?= json_encode(array_map(function($item) {
    return [
        'payment_method_id' => $item['payment_method_id'],
        'method_name'       => $item['method_name'] ?? '',
        'amount'            => $item['amount'],
    ];
}, $reportItems), JSON_HEX_TAG) ?>;

var reportOptionalItems = <?= json_encode(array_map(function($opt) {
    return [
        'name'   => $opt['name']   ?? '',
        'amount' => $opt['amount'] ?? 0,
    ];
}, $reportOptionalItems), JSON_HEX_TAG) ?>;

document.addEventListener('DOMContentLoaded', function() {
    reportItems.forEach(function(item) {
        addDreportRow(item.payment_method_id, item.amount, item.method_name);
    });
    reportOptionalItems.forEach(function(opt) {
        addOptionalRow('edit', opt.name, opt.amount);
    });
    document.querySelectorAll('#edit_items_container .dreport-amount-input').forEach(function(inp){
        formatAmountInput(inp);
    });
    document.querySelectorAll('#edit_optional_container .dreport-optional-amount').forEach(function(inp){
        formatAmountInput(inp);
    });
    recalcManualTotal();
    recalcOptionalTotal('edit');
    recalcYcBalance('edit');

    // Poin 5: client-side validation — system_revenue wajib > 0 sebelum submit
    var editForm = document.querySelector('form[method="post"]');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            var sysRev = parseFloat(document.getElementById('edit_system_revenue').value) || 0;
            if (sysRev <= 0) {
                e.preventDefault();
                var msg = <?= json_encode($LANG['system_revenue_required']) ?>;
                var el  = document.getElementById('edit_system_revenue_display');
                el.classList.add('is-invalid');
                el.focus();
                if (!el.nextElementSibling || !el.nextElementSibling.classList.contains('invalid-feedback')) {
                    var fb = document.createElement('div');
                    fb.className = 'invalid-feedback d-block';
                    fb.textContent = msg;
                    el.parentNode.insertAdjacentElement('afterend', fb);
                }
            }
        });
        document.getElementById('edit_system_revenue_display').addEventListener('input', function() {
            this.classList.remove('is-invalid');
            var fb = this.parentNode.nextElementSibling;
            if (fb && fb.classList.contains('invalid-feedback')) fb.remove();
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>