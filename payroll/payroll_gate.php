<?php
// ═══════════════════════════════════════════════════════════════════════════
//  HR & PAYROLL — HUB / GATE
//
//  Pola mengikuti purchases/pr_dahsboard_gate.php: satu halaman yang
//  berperan sekaligus sebagai GERBANG (parent gate) dan sebagai WADAH TAB.
//
//  GERBANG
//    Modul 'payroll' adalah gerbang induk. Gate OFF => seluruh menu dan
//    halaman HR & Payroll tertutup, apa pun isi permission sub-modulnya.
//    Superadmin selalu lolos (ditangani di dalam _dbCan()).
//
//  TAB — urutan PERSIS sama dengan urutan submenu di sidebar:
//    Dashboard → Employees → Attendance → Shifts → Shift Schedules →
//    Holidays → Payroll Periods → Payroll List → Leave Requests →
//    Cash Advances → KPI → Departments → Positions → Religions →
//    Payroll Settings
//
//  TIDAK ADA MODUL PERMISSION BARU. Kelima belas modul di atas sudah ada
//  di tabel `permissions`; berkas ini hanya membungkusnya di belakang
//  gerbang 'payroll'. Daftar tab, berkas, ikon, dan key bahasanya
//  dipetakan terpusat di includes/global_permission.php
//  (payrollTabModules / payrollTabFiles / payrollTabIcons /
//  payrollTabLangKeys) sehingga hanya ditulis di satu tempat.
//
//  KONSTANTA PAYROLL_GATE
//    Didefinisikan SEBELUM berkas tab di-include. Setiap berkas tab
//    memeriksanya di baris pertama: bila TIDAK terdefinisi berarti berkas
//    itu dibuka langsung lewat URL lama, dan ia mengalihkan ke hub ini.
//    Dengan begitu bookmark lama tetap hidup dan tidak ada halaman kembar.
//
//  Berkas ini SENGAJA dijaga tetap tipis: gerbang izin, pemilihan tab,
//  breadcrumb, nav tab, include tab aktif, dan gaya bersama antar tab.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';
require_once __DIR__ . '/../includes/kpi_functions.php';

// Tidak boleh diakses langsung tanpa login.
if (!isLoggedIn()) redirect(url('index.php'));

// GERBANG dievaluasi SEBELUM header dicetak, supaya user yang tidak berhak
// tidak sempat melihat kerangka halaman sama sekali.
if (!canAccessPayrollGate()) {
    redirectNoAccess();
}

// ── Pemilihan tab aktif ───────────────────────────────────────────────────
// Whitelist ketat: nilai di luar daftar ini diabaikan.
//
// PENTING — tab yang TIDAK DIIZINKAN tidak boleh sekadar disembunyikan dari
// nav; ia harus ditolak sebagai NILAI $active_tab juga. Tanpa ini, mengetik
// ?tab=payroll_list pada akun yang izinnya dicabut akan tetap menyorot tab
// tersebut DAN tetap meng-include isinya.
$PAYROLL_TAB_MODULES = payrollTabModules();
$PAYROLL_TAB_FILES   = payrollTabFiles();
$PAYROLL_TAB_ICONS   = payrollTabIcons();
$PAYROLL_TAB_LANG    = payrollTabLangKeys();

// Tab yang benar-benar boleh dibuka oleh user ini, dalam urutan tampil.
$PAYROLL_TABS = payrollAllowedTabs();

// Gerbang sudah lolos di atas, tetapi bisa saja SELURUH sub-modul dicabut.
if (empty($PAYROLL_TABS)) redirectNoAccess();

$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : '';
// Tab tidak dikenal ATAU tidak diizinkan → jatuh ke tab pertama yang boleh.
if (!in_array($active_tab, $PAYROLL_TABS, true)) $active_tab = $PAYROLL_TABS[0];

// Titipan untuk breadcrumb di header.php.
// Perlu variabel tersendiri karena untuk URL tanpa ?tab= nilai $_GET['tab']
// kosong sementara halaman sebenarnya menampilkan tab pertama yang DIIZINKAN
// — dan tab itu berbeda-beda per user. Tanpa titipan ini breadcrumb akan
// menyebut tab yang salah.
$__bc_tab = $active_tab;

$page_title = $LANG['hr_payroll'];

// Konstanta penanda: berkas tab memeriksanya untuk membedakan "dimuat oleh
// hub" dari "dibuka langsung lewat URL lama".
if (!defined('PAYROLL_GATE')) define('PAYROLL_GATE', true);

include __DIR__ . '/../includes/header.php';

/* ══════════════════════════════════════════════════════════════════════════
   GAYA BERSAMA SELURUH TAB HR & PAYROLL

   Disamakan dengan hub Purchase (pr_dahsboard_gate.php): serba putih
   bersih, tanpa gradasi, sudut membulat lembut, garis tipis abu-abu, dan
   ukuran tombol yang seragam di seluruh tab.

   Hanya aturan yang berlaku untuk SEMUA tab yang tinggal di sini. Gaya
   khusus satu tab tetap berada di berkas tab-nya masing-masing.
   ══════════════════════════════════════════════════════════════════════════ */
$extra_js = '
<style>
/* ── Nav tab ─────────────────────────────────────────────────────────── */
.payroll-tabs {
    border-bottom: 1px solid #e5e9f0;
    margin-bottom: 1rem;
    gap: .125rem;
    flex-wrap: wrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}
.payroll-tabs::-webkit-scrollbar { height: 4px; }
.payroll-tabs::-webkit-scrollbar-thumb { background: #d7dee8; border-radius: 4px; }
.payroll-tabs .nav-link {
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: .5rem .5rem 0 0;
    color: #5a6b80;
    font-size: .875rem;
    font-weight: 500;
    padding: .5rem .85rem;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    background: transparent;
    transition: background-color .15s ease, color .15s ease;
}
.payroll-tabs .nav-link i { font-size: .95rem; }
.payroll-tabs .nav-link:hover { background: #f4f7fb; color: #1e2a38; }
.payroll-tabs .nav-link.active {
    background: #ffffff;
    color: #1e2a38;
    font-weight: 600;
    border-color: #e5e9f0;
    box-shadow: 0 -1px 0 #2563eb inset, 0 -2px 0 #2563eb inset;
}

/* ── Kartu & tabel ───────────────────────────────────────────────────── */
.payroll-shell .card {
    border: 1px solid #e9edf3;
    border-radius: .625rem;
    background: #ffffff;
    box-shadow: 0 1px 2px rgba(16,24,40,.04);
}
.payroll-shell .card-header {
    background: #ffffff;
    border-bottom: 1px solid #eef1f6;
    font-weight: 600;
    color: #1e2a38;
}
.payroll-shell .table { margin-bottom: 0; }
.payroll-shell .table > thead > tr > th {
    background: #f8fafc;
    color: #475569;
    font-size: .78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .02em;
    border-bottom: 1px solid #e9edf3;
    white-space: nowrap;
}
.payroll-shell .table > tbody > tr > td {
    font-size: .875rem;
    color: #1e2a38;
    vertical-align: middle;
    border-color: #f1f4f9;
}
.payroll-shell .table > tbody > tr:hover > td { background: #f9fbfd; }

/* ── Tombol: satu ukuran, satu model, di seluruh tab ─────────────────── */
.payroll-shell .btn {
    border-radius: .5rem;
    font-size: .8125rem;
    font-weight: 500;
    padding: .375rem .75rem;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    line-height: 1.4;
}
.payroll-shell .btn-sm { font-size: .78125rem; padding: .3rem .6rem; }
.payroll-shell .btn i  { font-size: .9rem; line-height: 1; }
.payroll-shell .btn-group-sm > .btn { padding: .25rem .5rem; }

/* ── Modal serba putih bersih ────────────────────────────────────────── */
.payroll-shell .modal-content,
.modal-content.payroll-modal {
    border: none;
    border-radius: .75rem;
    box-shadow: 0 12px 32px rgba(16,24,40,.12);
}
.payroll-shell .modal-header,
.modal-content.payroll-modal .modal-header {
    background: #ffffff;
    border-bottom: 1px solid #eef1f6;
    padding: 1rem 1.25rem;
}
.payroll-shell .modal-title,
.modal-content.payroll-modal .modal-title {
    font-size: 1rem; font-weight: 600; color: #1e2a38;
}
.payroll-shell .modal-footer,
.modal-content.payroll-modal .modal-footer {
    background: #ffffff;
    border-top: 1px solid #eef1f6;
    padding: .75rem 1.25rem;
}

/* ── Form ────────────────────────────────────────────────────────────── */
.payroll-shell .form-label { font-size: .8125rem; font-weight: 600; color: #475569; margin-bottom: .25rem; }
.payroll-shell .form-control,
.payroll-shell .form-select { border-radius: .5rem; border-color: #dfe5ee; font-size: .875rem; }
.payroll-shell .form-control:focus,
.payroll-shell .form-select:focus { border-color: #93b4f5; box-shadow: 0 0 0 .18rem rgba(37,99,235,.10); }

/* ── Badge & alert ───────────────────────────────────────────────────── */
.payroll-shell .badge { font-weight: 600; font-size: .72rem; border-radius: .375rem; padding: .3em .55em; }
.payroll-shell .alert { border-radius: .5rem; border: 1px solid #e9edf3; font-size: .875rem; }

/* ── Kartu ringkas (dipakai tab Dashboard) ───────────────────────────── */
.payroll-shell .stat-card { border: 1px solid #e9edf3; border-radius: .625rem; background: #ffffff; }
.payroll-shell .stat-card .stat-value { font-size: 1.35rem; font-weight: 700; color: #1e2a38; }
.payroll-shell .stat-card .stat-label { font-size: .78rem; color: #64748b; }

/* ── DataTables ──────────────────────────────────────────────────────── */
.payroll-shell .dataTables_wrapper .dataTables_filter input,
.payroll-shell .dataTables_wrapper .dataTables_length select {
    border-radius: .5rem; border: 1px solid #dfe5ee; font-size: .8125rem;
}
.payroll-shell .dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #2563eb !important; border-color: #2563eb !important; color: #fff !important;
    border-radius: .375rem;
}

/* ── Responsif ───────────────────────────────────────────────────────── */
@media (max-width: 767.98px) {
    .payroll-tabs { flex-wrap: nowrap; }
    .payroll-tabs .nav-link { padding: .45rem .6rem; font-size: .8125rem; }
    .payroll-shell .table-responsive { -webkit-overflow-scrolling: touch; }
    .payroll-shell .btn { padding: .35rem .6rem; }
}

/* ── Mode gelap: tetap konsisten, tidak memaksa putih ────────────────── */
[data-bs-theme="dark"] .payroll-tabs { border-bottom-color: #2b3a4a; }
[data-bs-theme="dark"] .payroll-tabs .nav-link { color: #94a3b8; }
[data-bs-theme="dark"] .payroll-tabs .nav-link:hover { background: #16202c; color: #e6edf3; }
[data-bs-theme="dark"] .payroll-tabs .nav-link.active { background: #0f172a; color: #e6edf3; border-color: #2b3a4a; }
[data-bs-theme="dark"] .payroll-shell .card,
[data-bs-theme="dark"] .payroll-shell .card-header,
[data-bs-theme="dark"] .payroll-shell .modal-content,
[data-bs-theme="dark"] .payroll-shell .modal-header,
[data-bs-theme="dark"] .payroll-shell .modal-footer { background: #0f172a; border-color: #2b3a4a; color: #e6edf3; }
[data-bs-theme="dark"] .payroll-shell .table > thead > tr > th { background: #16202c; color: #94a3b8; border-color: #2b3a4a; }
[data-bs-theme="dark"] .payroll-shell .table > tbody > tr > td { color: #e6edf3; border-color: #1f2b38; }
[data-bs-theme="dark"] .payroll-shell .table > tbody > tr:hover > td { background: #16202c; }
[data-bs-theme="dark"] .payroll-shell .stat-card { background: #0f172a; border-color: #2b3a4a; }
[data-bs-theme="dark"] .payroll-shell .stat-card .stat-value { color: #e6edf3; }
</style>
';
?>

<div class="payroll-shell">



<!-- ══ NAV TAB — urutan persis submenu sidebar ═══════════════════════════ -->
<ul class="nav nav-tabs payroll-tabs" role="tablist">
    <?php foreach ($PAYROLL_TAB_MODULES as $__tab => $__mod): ?>
        <?php if (!payrollModuleAllowed($__mod)) continue; ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $active_tab === $__tab ? 'active' : '' ?>"
               href="<?= payrollGateUrl($__tab) ?>"
               role="tab"
               aria-selected="<?= $active_tab === $__tab ? 'true' : 'false' ?>">
                <i class="bi <?= htmlspecialchars($PAYROLL_TAB_ICONS[$__tab]) ?>"></i>
                <?= htmlspecialchars($LANG[$PAYROLL_TAB_LANG[$__tab]]) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<!-- ══ ISI TAB — hanya tab aktif yang di-include ═════════════════════════ -->
<div class="tab-content" id="payrollTabContent">
    <?php foreach ($PAYROLL_TAB_MODULES as $__tab => $__mod): ?>
        <?php if (!payrollModuleAllowed($__mod)) continue; ?>
        <div class="tab-pane <?= $active_tab === $__tab ? 'show active' : '' ?>" role="tabpanel">
            <?php
            if ($active_tab === $__tab) {
                $__file = __DIR__ . '/' . $PAYROLL_TAB_FILES[$__tab];
                if (is_file($__file)) {
                    include $__file;
                } else {
                    echo '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>'
                       . htmlspecialchars($LANG['no_data']) . '</div>';
                }
            }
            ?>
        </div>
    <?php endforeach; ?>
</div>

</div><!-- /.payroll-shell -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
