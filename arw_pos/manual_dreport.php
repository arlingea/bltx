<?php
// File: arw_pos/manual_dreport.php
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

// Access: kasir, manager & superadmin only
requireDreportAccess();

// Manager = user yang punya can_update tapi bukan full delete (atau superadmin)
function isDreportManager(): bool {
    $perm = _getDreportPerm();
    return (bool)$perm['can_update'];
}

// Permission helper: superadmin full control, selain itu dari database
function dreportCan(string $action): bool {
    if (isSuperAdmin()) return true;
    return checkPermission('dreport', $action);
}

// ================================================================
// [BARU] PERMISSION CAKUPAN DATA — "Full Data" vs "Data Sendiri"
// ================================================================
// 2 pilihan permission baru untuk modul dreport, disimpan di kolom
// permissions.can_view_all dan diatur lewat Settings > Permissions:
//   • can_view_all = 1    → FULL DATA    : melihat SEMUA laporan cashier —
//                            meng-override restriksi otomatis Kasir di bawah
//                            jika user ini kebetulan kena restriksi itu.
//   • can_view_all = 0    → DATA SENDIRI : hanya laporan yang DIBUAT SENDIRI
//                            (dicocokkan dari manual_daily_reports.created_by_user).
//   • can_view_all = NULL → BELUM DIATUR : mengikuti perilaku LAMA sepenuhnya
//                            (restriksi otomatis Kasir di bawah TIDAK diubah).
// Superadmin SELALU full akses (dicek lebih dulu, tanpa query ke DB).
// Query dibuat ringan & sengaja TIDAK di-cache ke session, supaya perubahan
// yang disimpan lewat settings_permissions.php langsung berlaku di request
// berikutnya (tidak perlu logout/login dulu).
// Return: 'full' | 'own' | null (null = belum diatur / ikut default lama).
function dreportGetViewScope(): ?string {
    if (isSuperAdmin()) return 'full';

    global $conn;
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['role'] ?? '';
    if (!$conn || !$uid) return null;

    // Prioritas SAMA seperti _getDreportPerm() di manual_dreport_functions.php:
    // override per-user (jika user ini punya baris custom permission di modul
    // manapun) lebih diutamakan daripada default role.
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM permissions WHERE user_id = ?");
    if (!$chk) return null;
    $chk->bind_param("i", $uid);
    $chk->execute();
    $has_custom = (int)$chk->get_result()->fetch_assoc()['cnt'];
    $chk->close();

    if ($has_custom > 0) {
        $stmt = $conn->prepare("SELECT can_view_all FROM permissions WHERE user_id = ? AND module = 'dreport'");
        if (!$stmt) return null;
        $stmt->bind_param("i", $uid);
    } else {
        $stmt = $conn->prepare("SELECT can_view_all FROM permissions WHERE user_id IS NULL AND role = ? AND module = 'dreport'");
        if (!$stmt) return null;
        $stmt->bind_param("s", $role);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['can_view_all'] === null) return null; // belum diatur → ikut default lama
    return ((int)$row['can_view_all'] === 1) ? 'full' : 'own';
}

$page_title = 'Cashier Report';
$LANG       = loadLanguage($_SESSION['lang'] ?? 'en');
$currentRole= $_SESSION['role'] ?? '';
$userId     = (int)($_SESSION['user_id'] ?? 0);
$csrfToken  = generateCsrfToken();

// ================================================================
// GET SYSTEM REVENUE VIA AJAX
// ================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'system_revenue') {
    requireDreportAccess();
    header('Content-Type: application/json');
    $date    = sanitize($_GET['date']     ?? date('Y-m-d'));
    $shiftId = (int)($_GET['shift_id'] ?? 0);
    $rev = getDreportSystemRevenue($date, $shiftId);
    echo json_encode(['revenue' => $rev, 'formatted' => number_format($rev, 0, ',', '.')]);
    exit;
}

// ================================================================
// HANDLE DELETE (POST)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && dreportCan('can_delete')) {
    $action = sanitize($_POST['action'] ?? '');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        bltx_flash_error($LANG['invalid_csrf']);
        redirect(url('arw_pos/manual_dreport.php'));
        exit;
    }

    if ($action === 'delete') {
        $delId = (int)($_POST['report_id'] ?? 0);
        if (deleteDreport($delId)) {
            bltx_flash_success($LANG['dreport_deleted']);
        } else {
            bltx_flash_error($LANG['dreport_delete_error']);
        }
        redirect(url('arw_pos/manual_dreport.php'));
        exit;
    }
}

// ================================================================
// FETCH VIEW DATA
// ================================================================
$paymentMethods        = getDreportPaymentMethods();
$shifts                = getDreportShifts();
$dreportUsers          = getDreportAllUsers(); // All users for filter (superadmin sees all)
$headerYesterdayCash   = getDreportHeaderYesterdayCash();

$filterDateFrom = sanitize($_GET['date_from'] ?? '');
$filterDateTo   = sanitize($_GET['date_to']   ?? '');
$filterShift    = (int)($_GET['shift_id']      ?? 0);
$filterCashier  = sanitize($_GET['cashier_name'] ?? '');

$filters = [
    'date_from'   => $filterDateFrom,
    'date_to'     => $filterDateTo,
    'shift_id'    => $filterShift   > 0 ? $filterShift   : '',
    'cashier_name' => $filterCashier !== '' ? $filterCashier : '',
];
if (isDreportKasir()) {
    $filters['restrict_cashier_name'] = $_SESSION['full_name'] ?? '';
}

$reports = getDreportList($filters);

// ================================================================
// [BARU] TERAPKAN PERMISSION CAKUPAN DATA (Full Data vs Data Sendiri)
// ================================================================
// dreportGetViewScope() mengembalikan salah satu dari 3 kemungkinan:
//   • 'full' → admin EKSPLISIT memilih Full Data untuk user ini. Ini
//     meng-override restriksi otomatis Kasir di atas (kalau ada) — query
//     ULANG tanpa restrict_cashier_name. getDreportList() itu sendiri
//     TIDAK diubah sama sekali; di sini hanya dipanggil lagi dengan filter
//     yang berbeda.
//   • 'own' → admin EKSPLISIT memilih Data Sendiri. Hasil dipersempit di
//     PHP ke baris yang created_by_user-nya = user yang sedang login.
//   • null → BELUM PERNAH DIATUR admin. TIDAK ADA perubahan apa pun —
//     $reports tetap persis hasil asli di atas (restriksi Kasir otomatis
//     tetap berlaku seperti sebelum fitur ini ada, atau tetap tanpa
//     restriksi kalau user itu bukan Kasir).
if (!isSuperAdmin()) {
    $dreportScope = dreportGetViewScope();

    if ($dreportScope === 'full' && isset($filters['restrict_cashier_name'])) {
        $filtersFullOverride = $filters;
        unset($filtersFullOverride['restrict_cashier_name']);
        $reports = getDreportList($filtersFullOverride);
    } elseif ($dreportScope === 'own') {
        // created_by_user kini menyimpan NAMA, bukan lagi ID user, sehingga
        // pencocokan harus memakai nama. Nama pembanding dibaca dari tabel
        // users agar tetap benar walau nama diubah admin di tengah sesi.
        // Pembandingan case-insensitive dan tanpa spasi tepi supaya selisih
        // penulisan tidak membuat laporan sendiri jadi tak terlihat.
        $ownName = '';
        if ($userId > 0) {
            $stmtOwn = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            if ($stmtOwn) {
                $stmtOwn->bind_param('i', $userId);
                $stmtOwn->execute();
                $rowOwn = $stmtOwn->get_result()->fetch_assoc();
                $stmtOwn->close();
                if ($rowOwn) $ownName = (string)$rowOwn['full_name'];
            }
        }
        $ownKey  = mb_strtolower(trim($ownName), 'UTF-8');
        $reports = array_values(array_filter($reports, function ($r) use ($ownKey) {
            if ($ownKey === '') return false;
            return mb_strtolower(trim((string)($r['created_by_user'] ?? '')), 'UTF-8') === $ownKey;
        }));
    }
}

include __DIR__ . '/../includes/header.php';

/* CATATAN: tidak ada '<?php' di sini — titik ini SUDAH berada di dalam
   blok PHP yang dibuka di atas. Tag pembuka kedua memicu
   'syntax error, unexpected token "<"'. Penutup ?> di bawah tetap ada,
   karena setelahnya memang kembali ke HTML. */
/* RONDE B — pembaca flash ikut dipindah ke helper terpusat.
   Setter-nya kini menulis ke kunci bersama 'success'/'error', jadi blok
   yang membaca kunci lama TIDAK AKAN PERNAH menyala lagi — pesannya
   hilang diam-diam. Sekaligus mengganti dua alert inline
   dengan toast melayang (A4.1). */
bltx_render_flash('dreport');
?>

<!-- Page Header -->
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-journal-text"></i><?= $LANG['cashier_report'] ?></h2>
    <div class="page-actions">
        <div class="dreport-header-yc d-flex align-items-center gap-1"
             title="<?= htmlspecialchars($LANG['yesterday_cash_total']) ?>">
            <span class="dreport-header-yc-label small fw-semibold text-muted">
                <?= $LANG['yesterday_cash_total'] ?>:
            </span>
            <span class="dreport-header-yc-value fw-bold text-primary fs-6">
                <?= dreportFormatMoney($headerYesterdayCash) ?>
            </span>
            <a href="#" class="dreport-yc-view-icon ms-1 text-primary"
               title="<?= htmlspecialchars($LANG['yesterday_cash_view_detail']) ?>"
               onclick="openYesterdayCashView();return false;"
               style="line-height:1;font-size:1rem;opacity:0.75;transition:opacity 0.15s;"
               onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.75'">
                <i class="bi bi-eye"></i>
            </a>
        </div>
        <?php if (dreportCan('can_create')): ?>
        <a href="<?= url('arw_pos/manual_dreport_add.php') ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-circle"></i><?= $LANG['add'] ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isSuperAdmin() || dreportCan('can_export')): ?>
<!-- Filter Bar — superadmin or users with export permission -->
<div class="card border-0 shadow-sm">
    <div class="card-body pb-0">
        <div class="filter-section">
            <div class="row g-3">
                <!-- Date Range Picker -->
                <div class="col-md-4 col-sm-6">
                    <label for="dreport_date_range" class="form-label small fw-semibold mb-1"><?= $LANG['date_range'] ?></label>
                    <input type="text" class="form-control form-control-sm daterangepicker-input"
                           id="dreport_date_range" placeholder="<?= $LANG['select_date_range'] ?>" readonly>
                    <input type="hidden" id="dreport_from_date" value="<?= htmlspecialchars($filterDateFrom) ?>">
                    <input type="hidden" id="dreport_to_date"   value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>
                <!-- Shift -->
                <div class="col-md-4 col-sm-6">
                    <label for="dreport_shift" class="form-label small fw-semibold mb-1"><?= $LANG['shifts'] ?></label>
                    <select id="dreport_shift" class="form-select form-select-sm">
                        <option value=""><?= $LANG['all'] ?></option>
                        <?php foreach ($shifts as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filterShift == $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Cashier -->
                <div class="col-md-4 col-sm-6">
                    <label for="dreport_cashier" class="form-label small fw-semibold mb-1"><?= $LANG['cashier_name'] ?></label>
                    <select id="dreport_cashier" class="form-select form-select-sm">
                        <option value=""><?= $LANG['all'] ?></option>
                        <?php foreach ($dreportUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['full_name']) ?>"
                                <?= $filterCashier === $u['full_name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <!-- Tombol Filter, Reset & Export PDF di bawah -->
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary btn-sm" onclick="applyDreportFilter()">
                            <i class="bi bi-funnel me-1"></i><?= $LANG['filter'] ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="resetDreportFilter()">
                            <i class="bi bi-arrow-clockwise me-1"></i><?= $LANG['reset'] ?>
                        </button>
                        <?php if (isSuperAdmin() || dreportCan('can_export')): ?>
                        <?php
                        /* Tombol Export PDF baru bisa ditekan setelah rentang tanggal
                           dipilih. Saat halaman dimuat, statusnya ditentukan langsung
                           dari server berdasarkan filter yang aktif di URL, sehingga
                           tombol tidak sempat "berkedip" aktif lalu mati lagi.
                           Setelah itu dreportSyncExportButton() yang menjaganya setiap
                           kali rentang tanggal dipilih atau dibersihkan. */
                        $dreportExportReady = ($filterDateFrom !== '' && $filterDateTo !== '');
                        ?>
                        <?php
                        /* Checkbox "hanya shift terakhir" DILETAKKAN DI DALAM
                           tombolnya, memakai btn-group: satu <button> untuk aksi
                           export, satu <label class="btn"> menempel rapat yang
                           memuat checkbox dan ikon info.

                           TIDAK memakai <input> di dalam <button> — HTML melarang
                           konten interaktif bersarang di dalam button, dan browser
                           akan menelan kliknya sehingga checkbox mustahil dicentang
                           dan klik justru menjalankan export. <label> boleh memuat
                           <input>, jadi bentuk ini sah dan tetap terbaca sebagai
                           satu tombol.

                           Teks labelnya sengaja tidak dirender; isinya pindah ke
                           tooltip pada ikon info, memakai kunci bahasa yang sudah
                           ada sehingga tidak ada kunci baru yang perlu dibuat. */
                        $dreportLastShiftOnlyLabel = htmlspecialchars($LANG['dreport_export_last_shift_only'], ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-danger"
                                    id="dreport_export_btn"
                                    onclick="exportDreportPdf()"
                                    <?= $dreportExportReady ? '' : 'disabled' ?>
                                    <?= $dreportExportReady ? '' : 'title="' . htmlspecialchars($LANG['select_date_range'], ENT_QUOTES, 'UTF-8') . '"' ?>>
                                <i class="bi bi-file-earmark-pdf me-1"></i><?= $LANG['export_pdf_dreport'] ?>
                            </button>
                            <label class="btn btn-danger d-inline-flex align-items-center gap-2 mb-0<?= $dreportExportReady ? '' : ' disabled' ?>"
                                   for="dreport_pdf_last_shift">
                                <input class="form-check-input m-0" type="checkbox" id="dreport_pdf_last_shift"
                                       <?= $dreportExportReady ? '' : 'disabled' ?>>
                                <i class="bi bi-info-circle dreport-ls-info"
                                   role="button" tabindex="0"
                                   data-bs-toggle="tooltip" data-bs-trigger="click"
                                   data-bs-title="<?= $dreportLastShiftOnlyLabel ?>"></i>
                            </label>
                        </div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-success"
                                    id="dreport_export_xls_btn"
                                    onclick="exportDreportExcel()"
                                    <?= $dreportExportReady ? '' : 'disabled' ?>
                                    <?= $dreportExportReady ? '' : 'title="' . htmlspecialchars($LANG['select_date_range'], ENT_QUOTES, 'UTF-8') . '"' ?>>
                                <i class="bi bi-file-earmark-excel me-1"></i><?= $LANG['export_excel'] ?>
                            </button>
                            <label class="btn btn-success d-inline-flex align-items-center gap-2 mb-0<?= $dreportExportReady ? '' : ' disabled' ?>"
                                   for="dreport_xls_last_shift">
                                <input class="form-check-input m-0" type="checkbox" id="dreport_xls_last_shift"
                                       <?= $dreportExportReady ? '' : 'disabled' ?>>
                                <i class="bi bi-info-circle dreport-ls-info"
                                   role="button" tabindex="0"
                                   data-bs-toggle="tooltip" data-bs-trigger="click"
                                   data-bs-title="<?= $dreportLastShiftOnlyLabel ?>"></i>
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Report Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="dreport-table-wrap">
            <table class="table table-striped table-hover mb-0" id="dreportTable">
                <thead>
                    <tr>
                        <th><?= $LANG['date'] ?></th>
                        <th><?= $LANG['cashier_name'] ?></th>
                        <th><?= $LANG['shifts'] ?></th>
                        <th class="text-end"><?= $LANG['system_revenue'] ?></th>
                        <th class="text-end"><?= $LANG['total_manual'] ?></th>
                        <th class="text-end"><?= $LANG['difference'] ?></th>
                        <th class="text-center" width="60" data-priority="1"><?= $LANG['summary_photo'] ?></th>
                        <th class="text-center" width="60" data-priority="1"><?= $LANG['yesterday_cash_image'] ?></th>
                        <th class="text-center" width="60" data-priority="1"><?= $LANG['rpos_image'] ?></th>
                        <th class="text-center" width="130" data-priority="1"><?= $LANG['actions'] ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Pre-compute date groups for visual grouping (wrapper per same date).
                // Data per row is NOT changed — each $r renders exactly as before.
                // We only add CSS classes: date-group-first (top border), date-group-last (bottom border),
                // date-group-middle (no top/bottom border between rows of the same date).
                $dateGroups = [];
                foreach ($reports as $idx => $r) {
                    $dateGroups[$r['report_date']][] = $idx;
                }
                // Build a lookup: index => position in its date group ('first','middle','last','only')
                $rowPosition = [];
                $rowGroupParity = [];
                $rowGroupSize = [];
                $rowFirstImage = [];
                $groupOrder = 0;
                foreach ($dateGroups as $date => $indices) {
                    $count  = count($indices);
                    $parity = ($groupOrder % 2 === 0) ? 'even' : 'odd';
                    // Ambil image_file dari baris paling atas (indeks pertama) dalam grup
                    $firstImage = $reports[$indices[0]]['image_file'] ?? '';
                    foreach ($indices as $pos => $idx) {
                        if ($count === 1) {
                            $rowPosition[$idx] = 'only';
                        } elseif ($pos === 0) {
                            $rowPosition[$idx] = 'first';
                        } elseif ($pos === $count - 1) {
                            $rowPosition[$idx] = 'last';
                        } else {
                            $rowPosition[$idx] = 'middle';
                        }
                        $rowGroupParity[$idx] = $parity;
                        $rowGroupSize[$idx]   = $count;
                        $rowFirstImage[$idx]  = $firstImage;
                    }
                    $groupOrder++;
                }
                foreach ($reports as $idx => $r):
                    $shiftPastel = [
                        'shift-pastel-1', // biru muda
                        'shift-pastel-2', // hijau muda
                        'shift-pastel-3', // kuning muda
                        'shift-pastel-4', // merah muda
                        'shift-pastel-5', // ungu muda
                        'shift-pastel-6', // oranye muda
                        'shift-pastel-7', // tosca muda
                    ];
                    $shiftKey   = strtolower(trim($r['shift_name'] ?? ''));
                    $shiftHash  = $shiftKey !== '' ? abs(crc32($shiftKey)) % count($shiftPastel) : 0;
                    $shiftClass = $shiftPastel[$shiftHash];
                    $pos        = $rowPosition[$idx] ?? 'only';
                    $parity     = $rowGroupParity[$idx] ?? 'odd';
                    $groupSize  = $rowGroupSize[$idx]   ?? 1;
                    $firstImage = $rowFirstImage[$idx]  ?? '';
                    $trClass    = 'dreport-group-' . $pos . ' dreport-group-' . $parity;
                ?>
                    <tr class="<?= $trClass ?>">
                        <td data-order="<?= htmlspecialchars($r['report_date']) ?>">
                            <?= htmlspecialchars(date('d/m/Y', strtotime($r['report_date']))) ?>
                        </td>
                        <td><?= htmlspecialchars($r['cashier_name'] ?? '-') ?></td>
                        <td>
                            <span class="badge shift-badge <?= $shiftClass ?>">
                                <?= htmlspecialchars($r['shift_name'] ?? '-') ?>
                            </span>
                        </td>
                        <td class="text-end"><?= dreportFormatMoney((float)$r['system_revenue']) ?></td>
                        <td class="text-end"><?= dreportFormatMoney((float)$r['total_manual']) ?></td>
                        <td class="text-end fw-semibold <?= dreportDiffClass((float)$r['difference']) ?>">
                            <?= dreportDiffLabel((float)$r['difference']) ?>
                        </td>
                        <?php if ($pos === 'first' || $pos === 'only'): ?>
                        <td class="text-center dreport-photo-cell dreport-photo-visible"
                            data-group-size="<?= $groupSize ?>"
                            data-group-pos="<?= $pos ?>">
                            <div class="dreport-photo-anchor">
                                <?php if ($firstImage !== ''): ?>
                                <a href="#" title="<?= $LANG['summary_photo'] ?>"
                                   onclick="openPhotoModal('<?= htmlspecialchars(addslashes(rawurlencode($firstImage)), ENT_QUOTES) ?>','summary');return false;">
                                    <img src="<?= url('uploads/manual_daily_reports/upload_dreport/') . htmlspecialchars(rawurlencode($firstImage)) ?>"
                                         class="dreport-thumb"
                                         alt="<?= $LANG['summary_photo'] ?>"
                                         loading="lazy">
                                </a>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.75rem;">-</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php else: ?>
                        <td class="dreport-photo-cell dreport-photo-hidden"
                            data-group-size="<?= $groupSize ?>"
                            data-group-pos="<?= $pos ?>"></td>
                        <?php endif; ?>
                        <?php
                        // rPos photo — show for every row (not grouped)
                        $rposImg = $r['rpos_image'] ?? '';
                        $ycImg   = $r['yesterday_cash_image'] ?? '';
                        ?>
                        <td class="text-center">
                            <?php if ($ycImg !== ''): ?>
                            <a href="#" title="<?= $LANG['yesterday_cash_image'] ?>"
                               onclick="openPhotoModal('<?= htmlspecialchars(addslashes(rawurlencode($ycImg)), ENT_QUOTES) ?>','cash');return false;">
                                <img src="<?= url('uploads/manual_daily_reports/upload_dreport_cash/') . htmlspecialchars(rawurlencode($ycImg)) ?>"
                                     class="dreport-thumb"
                                     alt="<?= $LANG['yesterday_cash_image'] ?>"
                                     loading="lazy">
                            </a>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:0.75rem;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($rposImg !== ''): ?>
                            <a href="#" title="<?= $LANG['rpos_image'] ?>"
                               onclick="openPhotoModal('<?= htmlspecialchars(addslashes(rawurlencode($rposImg)), ENT_QUOTES) ?>','rpos');return false;">
                                <img src="<?= url('uploads/manual_daily_reports/upload_dreport_rpos/') . htmlspecialchars(rawurlencode($rposImg)) ?>"
                                     class="dreport-thumb"
                                     alt="<?= $LANG['rpos_image'] ?>"
                                     loading="lazy">
                            </a>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:0.75rem;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-info btn-sm"
                                    title="<?= $LANG['view'] ?>"
                                    onclick="viewDreport(<?= (int)$r['id'] ?>)">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php if (dreportCan('can_update')): ?>
                            <a href="<?= url('arw_pos/manual_dreport_edit.php?id=' . (int)$r['id']) ?>"
                               class="btn btn-warning btn-sm"
                               title="<?= $LANG['edit'] ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (dreportCan('can_delete')): ?>
                            <button class="btn btn-danger btn-sm"
                                    title="<?= $LANG['delete'] ?>"
                                    onclick="deleteDreport(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes(date('d/m/Y', strtotime($r['report_date']))), ENT_QUOTES) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<!-- YESTERDAY CASH VIEW MODAL -->
<div class="modal fade" id="yesterdayCashViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title mb-0 d-flex align-items-center"><i class="bi bi-cash-stack me-2 text-primary"></i><?= htmlspecialchars($LANG['yesterday_cash_view_title']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="yesterdayCashViewBody">
                <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($LANG['close']) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewDreportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title mb-0 d-flex align-items-center"><i class="bi bi-eye me-2 text-primary"></i><?= $LANG['view_report'] ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewDreportBody">
                <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i><?= $LANG['close'] ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- PHOTO LIGHTBOX
     Markup lightbox halaman ini DIHAPUS. Pratinjau gambar kini memakai
     bltxPhotoLightbox() milik includes/footer.php, yang sudah menyediakan
     modal (#bltxLightboxModal), elemen gambar, tombol tutup, serta zoom/pan/
     pinch sendiri. Pola ini sama dengan inventory/inventory_data.php dan
     purchases/purchases_invoice.php.

     purchases/purchase_view.php SENGAJA tidak ikut dialihkan: berkas itu
     dimuat via AJAX ke dalam modal induk dan memakai div overlay, bukan modal
     Bootstrap. -->

<!-- DELETE FORM (hidden) -->
<?php if (dreportCan('can_delete')): ?>
<form method="POST" action="<?= url('arw_pos/manual_dreport.php') ?>" id="deleteDreportForm">
    <input type="hidden" name="action"     value="delete">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="report_id"  id="delete_report_id">
</form>
<?php endif; ?>

<script>
var isSuperadmin      = <?= dreportCan('can_delete') ? 'true' : 'false' ?>;
var dreportUploadUrl  = <?= json_encode(url('uploads/manual_daily_reports/upload_dreport/')) ?>;
var dreportUploadUrlYc   = <?= json_encode(url('uploads/manual_daily_reports/upload_dreport_cash/')) ?>;
var dreportUploadUrlRpos = <?= json_encode(url('uploads/manual_daily_reports/upload_dreport_rpos/')) ?>;
var langConfirmDelete = <?= json_encode($LANG['confirm_delete']) ?>;
var langConfirm       = <?= json_encode($LANG['confirm']) ?>;
var langYes           = <?= json_encode($LANG['yes']) ?>;
var langCancel        = <?= json_encode($LANG['cancel']) ?>;
</script>

<!-- Daterangepicker CSS — LOCAL -->
<link rel="stylesheet" href="<?= url('assets/vendor/daterangepicker/daterangepicker.css') ?>">

<style>
/* Yesterday Cash Total header badge */
.dreport-header-yc {
    background: #f0f4ff;
    border: 1px solid #c7d7ff;
    border-radius: 0.5rem;
    padding: 0.35rem 0.75rem;
    white-space: nowrap;
}
.dreport-header-yc-label {
    font-size: 0.8rem;
}
.dreport-header-yc-value {
    font-size: 1rem;
}
@media (max-width: 576px) {
    .dreport-header-yc { padding: 0.25rem 0.5rem; }
    .dreport-header-yc-label { font-size: 0.72rem; }
    .dreport-header-yc-value { font-size: 0.9rem; }
}
/* Card styling */
.card {
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    border: 1px solid rgba(0,0,0,0.125);
}
.card-body {
    padding: 1.5rem;
}
.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0,0,0,0.125);
    padding: 1rem 1.5rem;
}
h5 {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}
/* Table styling */
.table {
    margin-bottom: 0;
    border-collapse: separate !important;
    border-spacing: 0 0.35rem !important;
}
.table th, .table td {
    vertical-align: middle;
    padding: 0.75rem;
}
.table th {
    white-space: nowrap;
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
.table td {
    word-break: break-word;
}
/* Nonaktifkan striped per-baris bawaan Bootstrap — warna kini per grup */
.table-striped tbody tr:nth-of-type(odd) {
    background-color: transparent;
}
/* Warna zebra per grup: genap biru muda lembut, ganjil abu sangat muda — konsisten semua baris dalam 1 grup */
tr.dreport-group-odd td    { background-color: #f0f4ff !important; }
tr.dreport-group-even td   { background-color: #f8f9fa !important; }
.table-hover tbody tr:hover td {
    background-color: rgba(13,110,253,0.10) !important;
}
/* Filter section */
.filter-section {
    background-color: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border: 1px solid #dee2e6;
}
.filter-section .row {
    align-items: end;
}
/* Button group custom */
.btn-group-custom {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.btn-group-custom .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.daterangepicker-input {
    cursor: pointer;
}
/* Diff colors */
.dreport-diff-pos  { color: #198754 !important; }
.dreport-diff-neg  { color: #dc3545 !important; }
.dreport-diff-zero { color: #6c757d !important; }
#viewDreportBody table { font-size: 0.93rem; }
/* Performance optimization */
* {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.table {
    will-change: contents;
}
/* DataTables responsive styling */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    padding: 0.5rem 0;
}
/* Responsive */
@media (max-width: 992px) {
    .card-body { padding: 1rem; }
    .card-header { padding: 0.75rem 1rem; }
    .table th, .table td { padding: 0.5rem; font-size: 0.875rem; }
    h5 { font-size: 1.1rem; }
    .filter-section { padding: 0.75rem; }
}
@media (max-width: 768px) {
    h5 { font-size: 1rem; }
    .card-body { padding: 0.75rem; }
    .card-header { padding: 0.5rem 0.75rem; }
    .filter-section { padding: 0.5rem; }
    .btn-group-custom { width: 100%; margin-top: 0.5rem; }
    .btn-group-custom .btn { flex: 1; justify-content: center; }
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter { text-align: left; margin-bottom: 0.5rem; }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate { text-align: center; margin-top: 0.5rem; }
}
@media (max-width: 576px) {
    .table th, .table td { padding: 0.4rem; font-size: 0.8rem; }
    h5 { font-size: 0.95rem; }
    .btn-group-custom .btn { font-size: 0.85rem; padding: 0.375rem 0.5rem; }
}
@media (max-width: 400px) {
    .table th, .table td { padding: 0.3rem; font-size: 0.75rem; }
    .card-body { padding: 0.5rem; }
    .btn.btn-sm { padding: 0.25rem 0.4rem; font-size: 0.78rem; }
    .shift-badge { font-size: 0.7rem; padding: 0.25em 0.45em; }
}
/* Date group visual wrapper — rounded box per group, tanpa jarak antar baris */
tr.dreport-group-only td,
tr.dreport-group-first td,
tr.dreport-group-middle td,
tr.dreport-group-last td {
    border-left: none;
    border-right: none;
}
tr.dreport-group-only td,
tr.dreport-group-first td {
    border-top: 1.5px solid #adb5bd !important;
}
tr.dreport-group-only td,
tr.dreport-group-last td {
    border-bottom: 1.5px solid #adb5bd !important;
}
tr.dreport-group-only td:first-child,
tr.dreport-group-first td:first-child,
tr.dreport-group-middle td:first-child,
tr.dreport-group-last td:first-child {
    border-left: 1.5px solid #adb5bd !important;
}
tr.dreport-group-only td:last-child,
tr.dreport-group-first td:last-child,
tr.dreport-group-middle td:last-child,
tr.dreport-group-last td:last-child {
    border-right: 1.5px solid #adb5bd !important;
}
/* Rounded corners hanya pada sudut luar grup */
tr.dreport-group-first td:first-child,
tr.dreport-group-only td:first-child {
    border-top-left-radius: 0.5rem !important;
}
tr.dreport-group-first td:last-child,
tr.dreport-group-only td:last-child {
    border-top-right-radius: 0.5rem !important;
}
tr.dreport-group-last td:first-child,
tr.dreport-group-only td:first-child {
    border-bottom-left-radius: 0.5rem !important;
}
tr.dreport-group-last td:last-child,
tr.dreport-group-only td:last-child {
    border-bottom-right-radius: 0.5rem !important;
}
/* Hapus border top pada baris middle dan last agar tidak ada garis antar baris dalam grup */
tr.dreport-group-middle td,
tr.dreport-group-last td {
    border-top: none !important;
}
/* Hapus border bottom pada baris first dan middle agar tidak ada garis antar baris dalam grup */
tr.dreport-group-first td,
tr.dreport-group-middle td {
    border-bottom: none !important;
}
/* Hilangkan jarak vertikal antar baris dalam grup yang sama
   dengan override border-spacing hanya pada baris non-first */
tr.dreport-group-middle,
tr.dreport-group-last {
    margin-top: 0 !important;
}
/* Paksa border-spacing 0 secara vertikal untuk baris dalam grup
   menggunakan negative margin-top melalui pseudo-element pada td */
tr.dreport-group-middle td,
tr.dreport-group-last td {
    margin-top: -0.35rem;
    position: relative;
    top: -0.35rem;
}
/* Jarak antara grup (hanya baris pertama/only yang mendapat padding top) */
tr.dreport-group-first td,
tr.dreport-group-only td {
    padding-top: 0.8rem !important;
}
tr.dreport-group-last td,
tr.dreport-group-only td {
    padding-bottom: 0.8rem !important;
}
/* Shift badge — warna solid tegas, kontras tinggi, mudah dibedakan */
.shift-badge {
    font-size: 0.78rem;
    font-weight: 600;
    padding: 0.35em 0.65em;
    border-radius: 0.375rem;
    color: #fff !important;
    letter-spacing: 0.01em;
}
.shift-pastel-1 { background-color: #e53935; } /* merah   */
.shift-pastel-2 { background-color: #f57c00; } /* oranye  */
.shift-pastel-3 { background-color: #f9a825; color: #222 !important; } /* kuning  */
.shift-pastel-4 { background-color: #2e7d32; } /* hijau   */
.shift-pastel-5 { background-color: #1565c0; } /* biru    */
.shift-pastel-6 { background-color: #6a1b9a; } /* ungu    */
.shift-pastel-7 { background-color: #00838f; } /* teal    */
/* Foto thumbnail di kolom tabel */
.dreport-thumb {
    width: 52px;
    height: 52px;
    object-fit: cover;
    border-radius: 0.3rem;
    border: 1px solid #dee2e6;
    display: block;
    margin: 0 auto;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    cursor: zoom-in;
}
.dreport-thumb:hover {
    transform: scale(1.08);
    box-shadow: 0 2px 8px rgba(0,0,0,0.18);
}
.dreport-photo-cell {
    min-width: 64px;
    border-left:   none !important;
    border-right:  none !important;
}
/* Foto yang terlihat (first/only) */
.dreport-photo-visible {
    padding: 0.4rem !important;
    border-top:    1.5px solid #adb5bd !important;
    border-bottom: none !important;
    position: relative;
    overflow: visible !important;
}
/* Border bawah photo cell hanya pada baris last/only */
tr.dreport-group-only  td.dreport-photo-visible,
tr.dreport-group-last  td.dreport-photo-visible {
    border-bottom: 1.5px solid #adb5bd !important;
}
/* Anchor: posisi absolut, di-center secara vertikal oleh JS */
.dreport-photo-anchor {
    position: absolute;
    left: 0;
    right: 0;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
    /* top & height di-set oleh JS */
}
/* Sel tersembunyi (middle/last): tidak ada padding, tidak ada border, tinggi 0 */
.dreport-photo-hidden {
    padding: 0 !important;
    border-top:    none !important;
    border-bottom: none !important;
    border-left:   none !important;
    border-right:  none !important;
    background-color: transparent !important;
}
/* DataTables scrollX: kontrol (search, length, info, paginate) berada di luar scroll area */
.dreport-table-wrap .dataTables_wrapper .dataTables_scroll {
    overflow: visible;
}
.dreport-table-wrap .dataTables_wrapper .dataTables_scrollBody {
    overflow-x: auto !important;
    overflow-y: visible !important;
}
.dreport-table-wrap .dataTables_wrapper .dataTables_scrollHead {
    overflow: hidden !important;
}
/* Pastikan tabel di dalam scroll body tidak wrap */
.dreport-table-wrap .dataTables_wrapper .dataTables_scrollBody table,
.dreport-table-wrap .dataTables_wrapper .dataTables_scrollHead table {
    min-width: 900px;
}
/* Controls (search, length, info, paginate) tidak ikut scroll — posisi statis di atas/bawah */
.dreport-table-wrap .dataTables_wrapper .dataTables_length,
.dreport-table-wrap .dataTables_wrapper .dataTables_filter,
.dreport-table-wrap .dataTables_wrapper .dataTables_info,
.dreport-table-wrap .dataTables_wrapper .dataTables_paginate {
    position: static;
    width: auto;
    overflow: visible;
}
</style>

<script>
function viewDreport(id) {
    var modal = new bootstrap.Modal(document.getElementById('viewDreportModal'));
    document.getElementById('viewDreportBody').innerHTML =
        '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
    modal.show();

    var viewUrl = <?= json_encode(url('arw_pos/manual_dreport_view.php')) ?> + '?id=' + id;
    fetch(viewUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
    })
    .then(function(html) {
        document.getElementById('viewDreportBody').innerHTML = html;
    })
    .catch(function(err) {
        document.getElementById('viewDreportBody').innerHTML =
            '<div class="alert alert-danger m-3"><i class="bi bi-exclamation-triangle me-2"></i>' + err.message + '</div>';
    });
}

function openYesterdayCashView() {
    var modal = new bootstrap.Modal(document.getElementById('yesterdayCashViewModal'));
    document.getElementById('yesterdayCashViewBody').innerHTML =
        '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
    modal.show();

    var viewUrl = <?= json_encode(url('arw_pos/manual_yesterdaycash_view.php')) ?>;
    fetch(viewUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
    })
    .then(function(html) {
        document.getElementById('yesterdayCashViewBody').innerHTML = html;
    })
    .catch(function(err) {
        document.getElementById('yesterdayCashViewBody').innerHTML =
            '<div class="alert alert-danger m-3"><i class="bi bi-exclamation-triangle me-2"></i>' + err.message + '</div>';
    });
}

function deleteDreport(id, dateLabel) {
    if (!isSuperadmin) return;
    Swal.fire({
        title:               langConfirm,
        text:                langConfirmDelete + ' (' + dateLabel + ')?',
        icon:                'warning',
        showCancelButton:    true,
        confirmButtonColor:  '#d33',
        cancelButtonColor:   '#3085d6',
        confirmButtonText:   langYes,
        cancelButtonText:    langCancel,
        reverseButtons:      true
    }).then(function(result){
        if (result.isConfirmed) {
            document.getElementById('delete_report_id').value = id;
            document.getElementById('deleteDreportForm').submit();
        }
    });
}

/**
 * Pratinjau foto — kini mendelegasikan ke bltxPhotoLightbox() di
 * includes/footer.php. Seluruh mesin zoom/pan/pinch dan tombol tutup ada di
 * sana, jadi tidak perlu digandakan di halaman ini.
 *
 * Nama fungsi ini DIPERTAHANKAN persis karena dipanggil dari tiga atribut
 * onclick pada tabel; bltxPhotoLightbox() menerima URL penuh, sementara
 * pemanggilnya mengirim nama berkas + jenis folder, sehingga pemetaan folder
 * tetap dilakukan di sini.
 */
function openPhotoModal(encodedFilename, type) {
    var baseUrl = dreportUploadUrl; // default: summary
    if (type === 'rpos')      baseUrl = dreportUploadUrlRpos;
    else if (type === 'cash') baseUrl = dreportUploadUrlYc;
    if (window.bltxPhotoLightbox) { window.bltxPhotoLightbox(baseUrl + encodedFilename); }
}
</script>

<!-- Moment.js + Daterangepicker JS — LOCAL (no CDN) -->
<script>
/* ═══════════════════════════════════════════════════════════════════════
   PEMUATAN MOMENT.JS + DATERANGEPICKER — BERTAHAP, SETELAH jQuery SIAP

   MASALAH YANG DIPERBAIKI
   Sebelumnya kedua library ini dimuat dengan <script src> biasa tepat di
   posisi ini, padahal includes/footer.php — tempat jQuery dimuat — baru
   di-include di BARIS TERAKHIR file ini. Akibatnya daterangepicker.min.js
   dieksekusi saat window.jQuery masih undefined, sehingga pembungkus
   UMD-nya:
       root.daterangepicker = factory(root.moment, root.jQuery);
   memanggil factory dengan $ = undefined dan gagal pada baris
       $.fn.daterangepicker = function (...) { ... }
   Plugin tidak pernah terdaftar, initDreportDaterange() melakukan return
   diam-diam di pemeriksaan awalnya, dan kotak Date Range TIDAK MEMUNCULKAN
   APA PUN saat diklik — tanpa pesan error yang terlihat pengguna.

   Pola pemuatan bertahap di bawah SAMA PERSIS dengan yang sudah terbukti
   berjalan di inventory/inventory_out.php, inventory/inventory_in.php,
   inventory/stock_opname.php, dan purchases/goods_receipt_view.php.
   Seluruh path tetap lewat url('assets/vendor/...') — tidak ada hardcode.

   Library yang sudah dimuat halaman lain tidak dimuat ulang.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
    var MOMENT_SRC = <?= json_encode(url('assets/vendor/moment/moment.min.js'), JSON_UNESCAPED_SLASHES) ?>;
    var PICKER_SRC = <?= json_encode(url('assets/vendor/daterangepicker/daterangepicker.min.js'), JSON_UNESCAPED_SLASHES) ?>;

    function loadScript(src, next) {
        var s = document.createElement('script');
        s.src    = src;
        s.async  = false;
        s.onload = function () { if (next) next(); };
        document.head.appendChild(s);
    }
    function needMoment(next) {
        if (typeof moment !== 'undefined') { next && next(); return; }
        loadScript(MOMENT_SRC, next);
    }
    function needPicker(next) {
        if (typeof jQuery.fn.daterangepicker !== 'undefined') { next && next(); return; }
        loadScript(PICKER_SRC, next);
    }

    /* jQuery dimuat footer.php di akhir halaman. Pada DOMContentLoaded ia
       sudah pasti ada, tetapi penantian berbatas di bawah dipasang sebagai
       pengaman bila urutan script berubah di masa depan. Dibatasi 100x50ms
       agar tidak pernah menjadi loop tak berujung. */
    var tries = 0;
    function boot() {
        if (typeof jQuery === 'undefined') {
            if (++tries > 100) return;
            setTimeout(boot, 50);
            return;
        }
        needMoment(function () { needPicker(initDreportDaterange); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

/* Dua format yang dipakai di halaman ini — didefinisikan sekali di sini
   supaya seluruh fungsi memakai acuan yang sama.

   DUA FORMAT BERBEDA, DAN INI DISENGAJA (pola yang sama dengan
   purchases/purchases_invoice.php):
     - TAMPILAN di kotak teks : DD-MM-YYYY  (permintaan user)
     - NILAI yang dikirim     : YYYY-MM-DD  (WAJIB, jangan diubah)

   Kenapa nilainya wajib YYYY-MM-DD: isi #dreport_from_date /
   #dreport_to_date dipakai apa adanya pada
       WHERE dr.report_date >= ?  AND  dr.report_date <= ?
   di dreportGetReports() (includes/manual_dreport_functions.php) dan di
   includes/export/export_dreport.php. Kolom manual_daily_reports.report_date
   bertipe DATE, sehingga bila nilainya ikut diubah menjadi DD-MM-YYYY,
   filter dan export akan mengembalikan hasil KOSONG tanpa pesan error
   apa pun. */
var DREPORT_DISPLAY_FMT = 'DD-MM-YYYY';   /* untuk mata pengguna  */
var DREPORT_VALUE_FMT   = 'YYYY-MM-DD';   /* untuk server & query */

/** Ubah nilai YYYY-MM-DD menjadi tampilan DD-MM-YYYY. */
function dreportFmtDate(v) {
    if (!v) return '';
    if (typeof moment !== 'undefined') {
        var m = moment(v, DREPORT_VALUE_FMT, true);
        if (m.isValid()) return m.format(DREPORT_DISPLAY_FMT);
    }
    return v;
}

/**
 * Tombol Export PDF dan Export Excel hanya aktif bila rentang tanggal sudah
 * dipilih. Dipanggil setelah picker siap, setiap kali rentang diterapkan, dan
 * setiap kali rentang dibersihkan.
 *
 * Kedua tombol disetir dalam satu fungsi supaya keduanya mustahil berbeda
 * status — versi lama hanya mengenal satu id tombol.
 */
function dreportSyncExportButton() {
    var from  = document.getElementById('dreport_from_date');
    var to    = document.getElementById('dreport_to_date');
    var ready = !!(from && to && from.value && to.value);

    /* Tiap kelompok = tombol export + checkbox "hanya shift terakhir" yang
       menempel di dalamnya. Ketiganya (tombol, checkbox, label pembungkus)
       disetir bersama supaya mustahil berbeda status: tanpa ini checkbox tetap
       bisa dicentang padahal tombolnya mati, dan labelnya tetap tampak aktif. */
    var grup = [
        { btn: 'dreport_export_btn',     box: 'dreport_pdf_last_shift' },
        { btn: 'dreport_export_xls_btn', box: 'dreport_xls_last_shift' }
    ];

    for (var i = 0; i < grup.length; i++) {
        var btn = document.getElementById(grup[i].btn);
        if (btn) {
            btn.disabled = !ready;
            if (ready) {
                btn.removeAttribute('title');
            } else {
                btn.setAttribute('title', <?= json_encode((string)$LANG['select_date_range'], JSON_UNESCAPED_UNICODE) ?>);
            }
        }

        var box = document.getElementById(grup[i].box);
        if (box) {
            box.disabled = !ready;
            /* Label harus ikut ditandai: Bootstrap tidak pernah menurunkan
               tampilan disabled dari <input> ke <label class="btn"> yang
               membungkusnya, jadi tanpa kelas ini separuh kanan tombol tetap
               terlihat hidup padahal isinya sudah mati. */
            var lbl = box.closest('label');
            if (lbl) lbl.classList.toggle('disabled', !ready);
        }
    }
}

function initDreportDaterange() {
    if (typeof $ === 'undefined' || typeof $.fn.daterangepicker === 'undefined') return;

    var $input = $('#dreport_date_range');
    if (!$input.length) return;

    $input.daterangepicker({
        autoUpdateInput: false,
        /* maxDate = hari ini: tanggal setelah hari ini tidak bisa dipilih. */
        maxDate: moment(),
        locale: {
            cancelLabel: <?= json_encode((string)$LANG['clear'], JSON_UNESCAPED_UNICODE) ?>,
            applyLabel:  <?= json_encode((string)$LANG['apply'], JSON_UNESCAPED_UNICODE) ?>,
            format: DREPORT_DISPLAY_FMT
        }
    });

    $input.on('apply.daterangepicker', function (ev, picker) {
        /* Kotak teks memakai format tampilan ... */
        $(this).val(picker.startDate.format(DREPORT_DISPLAY_FMT) + ' - ' + picker.endDate.format(DREPORT_DISPLAY_FMT));
        /* ... sedangkan input tersembunyi tetap format nilai. */
        $('#dreport_from_date').val(picker.startDate.format(DREPORT_VALUE_FMT));
        $('#dreport_to_date').val(picker.endDate.format(DREPORT_VALUE_FMT));
        dreportSyncExportButton();
    });

    $input.on('cancel.daterangepicker', function () {
        $(this).val('');
        $('#dreport_from_date').val('');
        $('#dreport_to_date').val('');
        dreportSyncExportButton();
    });

    /* Nilai awal dari filter aktif di URL. Input tersembunyi sudah diisi PHP
       dalam format YYYY-MM-DD, jadi kotak teks tinggal ditampilkan dalam
       format DD-MM-YYYY, dan rentangnya ikut ter-highlight saat picker
       dibuka kembali. */
    var f = $('#dreport_from_date').val();
    var t = $('#dreport_to_date').val();
    if (f && t) {
        var mF = moment(f, DREPORT_VALUE_FMT, true);
        var mT = moment(t, DREPORT_VALUE_FMT, true);
        if (mF.isValid() && mT.isValid()) {
            $input.val(mF.format(DREPORT_DISPLAY_FMT) + ' - ' + mT.format(DREPORT_DISPLAY_FMT));
            $input.data('daterangepicker').setStartDate(mF);
            $input.data('daterangepicker').setEndDate(mT);
        }
    }

    dreportSyncExportButton();
}

function applyDreportFilter() {
    /* Tanggal dibaca dari INPUT TERSEMBUNYI yang berisi YYYY-MM-DD, BUKAN
       dari kotak teks yang kini menampilkan DD-MM-YYYY. Ini wajib karena
       nilai date_from / date_to dipakai apa adanya pada perbandingan
       terhadap kolom report_date bertipe DATE — mengirim DD-MM-YYYY akan
       menghasilkan tabel kosong tanpa pesan error. Cara baca ini sama
       dengan yang sudah dipakai exportDreportPdf().
       URL yang dihasilkan tetap ?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD,
       sehingga bookmark dan tombol Back yang lama tetap berfungsi. */
    var dateFrom  = $('#dreport_from_date').val();
    var dateTo    = $('#dreport_to_date').val();
    var shiftId   = $('#dreport_shift').val();
    var cashier   = $('#dreport_cashier').val();
    var params    = [];
    if (dateFrom && dateTo) {
        params.push('date_from=' + encodeURIComponent(dateFrom));
        params.push('date_to='   + encodeURIComponent(dateTo));
    }
    if (shiftId)  params.push('shift_id='     + encodeURIComponent(shiftId));
    if (cashier)  params.push('cashier_name=' + encodeURIComponent(cashier));
    window.location.href = <?= json_encode(url('arw_pos/manual_dreport.php')) ?>
        + (params.length ? '?' + params.join('&') : '');
}

function resetDreportFilter() {
    window.location.href = <?= json_encode(url('arw_pos/manual_dreport.php')) ?>;
}

/**
 * Inti bersama untuk Export PDF dan Export Excel.
 *
 * Dipisahkan supaya kedua tombol memakai persis logika yang sama: pengaman
 * rentang tanggal, ringkasan filter pada dialog konfirmasi, dan penyusunan
 * URL. Menggandakan blok ini menjadi dua salinan hanya akan membuat keduanya
 * perlahan berbeda.
 *
 * @param kind        'pdf' | 'excel' — dikirim sebagai ?type= ke export_dreport.php
 * @param label       teks yang muncul di baris "Export to:" pada dialog
 * @param checkboxId  id checkbox "hanya shift terakhir" milik tombol tersebut
 */
function dreportRunExport(kind, label, checkboxId) {
    var dateFrom = $('#dreport_from_date').val();
    var dateTo   = $('#dreport_to_date').val();

    /* Pengaman kedua. Tombolnya memang sudah disabled sampai rentang tanggal
       dipilih, tetapi atribut disabled di HTML bisa dilepas lewat DevTools —
       jadi atribut itu BUKAN pengaman. Pemeriksaan di sini memastikan export
       tidak pernah berjalan tanpa rentang tanggal. */
    if (!dateFrom || !dateTo) {
        dreportSyncExportButton();
        return;
    }

    var shiftId  = $('#dreport_shift').val();
    var cashier  = $('#dreport_cashier').val();
    var shiftSel = document.getElementById('dreport_shift');
    var cashierSel = document.getElementById('dreport_cashier');

    var filterDesc = [];
    if (dateFrom && dateTo) {
        /* Ditampilkan dalam format DD-MM-YYYY agar sama dengan kotak filter.
           Nilai yang dikirim ke export_dreport.php di bawah tetap YYYY-MM-DD. */
        filterDesc.push('<strong><?= addslashes($LANG['date_range']) ?>:</strong> ' + dreportFmtDate(dateFrom) + ' - ' + dreportFmtDate(dateTo));
    }
    if (shiftId && shiftSel) {
        filterDesc.push('<strong><?= addslashes($LANG['shifts']) ?>:</strong> ' + shiftSel.options[shiftSel.selectedIndex].text);
    }
    if (cashier && cashierSel && cashierSel.value !== '') {
        filterDesc.push('<strong><?= addslashes($LANG['cashier_name']) ?>:</strong> ' + cashierSel.options[cashierSel.selectedIndex].text);
    }

    /* Status checkbox ikut ditampilkan di dialog konfirmasi. Tanpa ini
       pengguna tidak punya cara memastikan mode mana yang akan terunduh
       sebelum berkasnya jadi. */
    var lastShiftBox  = document.getElementById(checkboxId);
    var lastShiftOnly = !!(lastShiftBox && lastShiftBox.checked);
    if (lastShiftOnly) {
        filterDesc.push('<strong><?= addslashes($LANG['dreport_export_last_shift_only']) ?></strong>');
    }

    var filterHtml  = '';
    var warningHtml = '';
    if (filterDesc.length === 0) {
        warningHtml = '<div class="alert alert-warning mt-2"><i class="bi bi-exclamation-triangle"></i> <?= addslashes($LANG['export_all_data']) ?></div>';
        filterHtml  = '<div style="text-align:left;margin-top:10px;"><strong><?= addslashes($LANG['filter']) ?>:</strong> <?= addslashes($LANG['all']) ?> (<?= addslashes($LANG['no_filter']) ?>)</div>';
    } else {
        filterHtml = '<div style="text-align:left;margin-top:10px;">' + filterDesc.join('<br>') + '</div>';
    }

    Swal.fire({
        title: '<?= addslashes($LANG['confirm_export']) ?>',
        html: '<div style="text-align:left;"><strong><?= addslashes($LANG['export_to']) ?>:</strong> ' + label + '</div>' + filterHtml + warningHtml,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle"></i> <?= addslashes($LANG['yes_export']) ?>',
        cancelButtonText: '<i class="bi bi-x-circle"></i> <?= addslashes($LANG['cancel']) ?>',
        reverseButtons: true
    }).then(function(result) {
        if (result.isConfirmed) {
            var params = [];
            if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
            if (dateTo)   params.push('date_to='   + encodeURIComponent(dateTo));
            if (shiftId)  params.push('shift_id='  + encodeURIComponent(shiftId));
            if (cashier)  params.push('cashier_name=' + encodeURIComponent(cashier));
            params.push('type=' + encodeURIComponent(kind));
            if (lastShiftOnly) params.push('last_shift_only=1');
            var exportUrl = <?= json_encode(url('includes/export/export_dreport.php')) ?>
                + (params.length ? '?' + params.join('&') : '');

            Swal.fire({
                title: '<?= addslashes($LANG['exporting']) ?>',
                html: '<?= addslashes($LANG['please_wait']) ?>',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            window.location.href = exportUrl;

            setTimeout(function() {
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: '<?= addslashes($LANG['export_success']) ?>',
                    text: '<?= addslashes($LANG['file_downloaded']) ?>',
                    timer: 2000,
                    showConfirmButton: false
                });
            }, 2000);
        }
    });
}

/* Dua pembungkus tipis. Nama exportDreportPdf() DIPERTAHANKAN persis seperti
   semula karena sudah dipanggil dari atribut onclick tombol PDF. */
function exportDreportPdf() {
    dreportRunExport('pdf', 'PDF', 'dreport_pdf_last_shift');
}

function exportDreportExcel() {
    dreportRunExport('excel', 'Excel', 'dreport_xls_last_shift');
}

/* ── Tooltip ikon info pada checkbox "hanya shift terakhir" ──────────────────
   DIINISIALISASI DI DOMContentLoaded, bukan saat skrip dibaca: includes/footer.php
   memuat bootstrap.bundle SETELAH blok ini, jadi objek bootstrap belum ada pada
   saat parse. Ketika DOMContentLoaded menyala, footer sudah dieksekusi.

   preventDefault() + stopPropagation() pada ikon itu wajib, bukan hiasan. Ikonnya
   berada DI DALAM <label>, sehingga tanpa keduanya setiap klik pada ikon ikut
   men-toggle checkbox — pengguna menekan tanda tanya lalu diam-diam mengubah mode
   export tanpa menyadarinya. */
document.addEventListener('DOMContentLoaded', function () {
    var icons = document.querySelectorAll('.dreport-ls-info');
    if (!icons.length) return;

    Array.prototype.forEach.call(icons, function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
        });
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            new bootstrap.Tooltip(el);
        }
    });
});
</script>

<script>
// Initialize DataTable — pola sama dengan inventory_out.php
function initDreportTable() {
    $(document).ready(function() {
        // Destroy existing instance jika ada (hindari double-init)
        if ($.fn.DataTable.isDataTable('#dreportTable')) {
            $('#dreportTable').DataTable().destroy();
        }

        $('#dreportTable').DataTable({
            responsive: false,
            scrollX: true,
            scrollCollapse: true,
            pageLength: 25,
            order: [[0, 'desc']],
            columns: [
                { type: 'string' },   // Date (data-order)
                null,                 // Cashier
                null,                 // Shift
                null,                 // System Revenue
                null,                 // Manual Total
                null,                 // Difference
                { orderable: false }, // Summary Photo
                { orderable: false }, // Yesterday Cash Photo
                { orderable: false }, // rPos Photo
                { orderable: false }  // Actions
            ],
            language: {
                search:       "<?= $LANG['search'] ?>:",
                lengthMenu:   "<?= $LANG['show'] ?> _MENU_ <?= $LANG['entries'] ?> <?= $LANG['per_page'] ?>",
                info:         "<?= $LANG['showing'] ?> _START_ <?= $LANG['to'] ?> _END_ <?= $LANG['of'] ?> _TOTAL_ <?= $LANG['entries'] ?>",
                infoEmpty:    "<?= $LANG['showing'] ?> 0 <?= $LANG['to'] ?> 0 <?= $LANG['of'] ?> 0 <?= $LANG['entries'] ?>",
                infoFiltered: "(<?= $LANG['filtered_from'] ?> _MAX_ <?= $LANG['total_entries'] ?>)",
                paginate: {
                    first:    "<?= $LANG['first'] ?>",
                    last:     "<?= $LANG['last'] ?>",
                    next:     "<?= $LANG['next'] ?>",
                    previous: "<?= $LANG['previous'] ?>"
                },
                emptyTable:  "<?= $LANG['no_data'] ?>",
                zeroRecords: "<?= $LANG['no_data'] ?>"
            }
        });

        // Center foto setelah setiap draw (paging, sort, search)
        $('#dreportTable').on('draw.dt', function() {
            centerDreportPhotos();
        });

        // Re-center setelah DataTables Responsive menyembunyikan/menampilkan kolom di mobile
        $('#dreportTable').on('responsive-display.dt responsive-resize.dt', function() {
            centerDreportPhotos();
        });
    });
}

/**
 * Vertical-merge foto kolom Photo:
 * Mengukur jarak dari top td foto ke bottom tr terakhir dalam grup
 * menggunakan getBoundingClientRect() relatif ke td foto — akurat di semua
 * ukuran layar termasuk mobile dengan border-spacing dan CSS transform.
 */
function centerDreportPhotos() {
    // setTimeout memberi waktu DataTables Responsive selesai mengatur DOM di mobile
    // (double-rAF tidak cukup saat Responsive sedang hide/show kolom)
    setTimeout(function() {
        document.querySelectorAll('#dreportTable tbody td.dreport-photo-visible').forEach(function(td) {
            var anchor = td.querySelector('.dreport-photo-anchor');
            if (!anchor) return;

            // Skip jika kolom tidak tampil
            if (td.offsetWidth === 0) return;

            var groupSize = parseInt(td.getAttribute('data-group-size'), 10) || 1;
            var pos       = td.getAttribute('data-group-pos') || 'only';

            // Reset anchor
            anchor.style.top       = '';
            anchor.style.height    = '';
            anchor.style.transform = '';

            if (groupSize <= 1 || pos === 'only') {
                anchor.style.top       = '50%';
                anchor.style.height    = 'auto';
                anchor.style.transform = 'translateY(-50%)';
                return;
            }

            // Kumpulkan semua tr data dalam grup menggunakan data-group-size
            // Telusuri SEMUA tr di tbody, skip semua baris non-data (child rows DataTables)
            var parentTr = td.closest('tr');
            var tbody    = parentTr.closest('tbody');
            var allTrs   = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr')) : [];
            var groupTrs = [];
            for (var i = 0; i < allTrs.length; i++) {
                var pc = allTrs[i].querySelector('td.dreport-photo-cell');
                if (!pc) continue; // skip child rows DataTables (tidak punya dreport-photo-cell)
                if (parseInt(pc.getAttribute('data-group-size'), 10) === groupSize) {
                    // Pastikan ini masih satu grup yang sama dengan parentTr
                    // dengan cara cek apakah parentTr ada di groupTrs pertama kali ditemukan
                    if (groupTrs.length === 0) {
                        if (allTrs[i] !== parentTr) continue; // belum sampai parentTr
                    }
                    groupTrs.push(allTrs[i]);
                    if (groupTrs.length === groupSize) break;
                } else if (groupTrs.length > 0) {
                    break; // sudah masuk grup berbeda
                }
            }

            if (groupTrs.length === 0) groupTrs = [parentTr];

            // Ukur: top td foto → bottom td foto-cell terakhir dalam grup
            // Gunakan rect dari td (bukan tr) karena td sudah ter-apply CSS
            // top:-0.35rem sehingga posisi visualnya akurat di semua ukuran layar.
            var tdRect   = td.getBoundingClientRect();
            var lastTr   = groupTrs[groupTrs.length - 1];
            var lastTd   = lastTr.querySelector('td.dreport-photo-cell') || lastTr.lastElementChild;
            var lastRect = lastTd.getBoundingClientRect();

            // totalHeight = jarak dari top td foto sampai bottom td terakhir dalam grup
            var totalHeight = lastRect.bottom - tdRect.top;

            if (totalHeight <= 0) {
                // Fallback jika rect tidak tersedia (hidden/off-screen)
                totalHeight = groupTrs.reduce(function(s, r) { return s + r.offsetHeight; }, 0);
            }

            anchor.style.top       = '0';
            anchor.style.height    = totalHeight + 'px';
            anchor.style.transform = 'none';
        });
    }, 50);
}

// Initialize setelah jQuery loaded — sama dengan inventory_out.php
if (typeof jQuery !== 'undefined') {
    initDreportTable();
    // Center foto setelah halaman pertama kali load
    window.addEventListener('load', function() { centerDreportPhotos(); });
    // Re-center saat window di-resize (responsive) — debounced
    var _dreportResizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(_dreportResizeTimer);
        _dreportResizeTimer = setTimeout(centerDreportPhotos, 150);
    });
} else {
    window.addEventListener('load', function() {
        setTimeout(function() {
            if (typeof jQuery !== 'undefined') {
                initDreportTable();
                centerDreportPhotos();
            }
        }, 1000);
    });
}
</script><?php include __DIR__ . '/../includes/footer.php'; ?>