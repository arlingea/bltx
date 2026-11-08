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
/* Tampilan kartu, tabel, tombol, form, modal, badge, dan DataTables
   mengikuti gaya global AdminLTE (includes/header.php + footer.php),
   sama seperti modul Purchase, Inventory, dan Settings. Yang tersisa
   di sini hanya aturan khusus payroll. */
.payroll-shell .table { margin-bottom: 0; }
.payroll-shell .stat-card .stat-value { font-size: 1.35rem; font-weight: 700; }
.payroll-shell .stat-card .stat-label { font-size: .78rem; color: var(--bs-secondary-color); }
</style>
';
?>

<div class="payroll-shell">



<!-- ══ NAV TAB — urutan persis submenu sidebar ═══════════════════════════ -->
<ul class="nav nav-tabs mb-4 payroll-tabs" role="tablist">
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
