<?php
// ═══════════════════════════════════════════════════════════════════════════
//  PARTIAL: TAB PURCHASE INVOICE
//
//  Di-include oleh pr_dahsboard_gate.php ketika ?tab=invoice aktif.
//  Akses langsung ke berkas ini (mis. /purchases/purchases_invoice.php)
//  ditolak dengan 403.
//
//  SELURUH urusan tab ini kini berada di berkas ini: handler POST hapus,
//  data dropdown, parameter filter, query daftar invoice, token CSRF,
//  string bahasa untuk JS, gaya khusus tab, dan seluruh skripnya.
//  pr_dahsboard_gate.php sekarang murni hub/gate + gaya bersama antar tab.
// ═══════════════════════════════════════════════════════════════════════════

// ── Penjaga akses langsung ────────────────────────────────────────────────
// $result dan $csrf_token DULU dipakai sebagai bukti "saya di-include oleh
// hub". Keduanya sekarang dibuat DI DALAM berkas ini sendiri, sehingga tidak
// lagi bisa membuktikan apa pun. Buktinya diganti ke $active_tab dan
// $PURCHASE_TABS — dua variabel yang HANYA ada bila gate benar-benar sudah
// berjalan (dibuat di pr_dahsboard_gate.php setelah gerbang izin lolos).
// Kekuatan perlindungannya sama: membuka berkas ini langsung tetap 403.
if (!defined('APP_NAME') || !isset($LANG, $active_tab, $PURCHASE_TABS)) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// Dimuat di sini supaya partial ini mandiri. require_once bersifat idempoten,
// jadi tidak ada beban tambahan bila gate sudah memuatnya lebih dulu.
require_once __DIR__ . '/../includes/purchases_function.php';

// ── POST: Delete purchase invoice (existing logic, unchanged) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $del_id   = (int)($_POST['del_id']     ?? 0);
    $del_csrf = (string)($_POST['csrf_token'] ?? '');
    $del_result = pf_deletePurchase($conn, $del_id, $del_csrf);

    if ($del_result['success']) {
        $_SESSION['success'] = $LANG['delete_success'];
    } else {
        $key = $del_result['message_key'];
        if ($key === 'invalid_csrf') {
            $_SESSION['error'] = $LANG['invalid_csrf'];
        } elseif ($key === 'no_permission') {
            $_SESSION['error'] = $LANG['no_permission'];
        } else {
            $_SESSION['error'] = $LANG['error'];
        }
    }
    // Tab dicantumkan eksplisit: default tab halaman ini sekarang 'order',
    // sedangkan penghapusan invoice harus kembali ke tab Purchase Invoice.
    redirect(url('purchases/pr_dahsboard_gate.php?tab=invoice'));
}

// ── Dropdown data for filter ────────────────────────────────────────────────
$suppliers_list       = pf_getSuppliersList($conn);
$payment_methods_list = pf_getPaymentMethodsList($conn);
$payment_by_list      = pf_getPaymentByList($conn);

// ── Filter parameters (GET) ─────────────────────────────────────────────────
$filter_supplier   = isset($_GET['filter_supplier'])   ? (int)$_GET['filter_supplier']   : 0;
$filter_payment    = isset($_GET['filter_payment'])    ? (int)$_GET['filter_payment']    : 0;
$filter_payment_by = isset($_GET['filter_payment_by']) ? (int)$_GET['filter_payment_by'] : 0;
$filter_from       = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$filter_to         = isset($_GET['to_date'])   ? trim($_GET['to_date'])   : '';

// ── Fetch purchases list ────────────────────────────────────────────────────
$result = pf_getPurchasesList($conn, [
    'from_date'     => $filter_from,
    'to_date'       => $filter_to,
    'supplier_id'   => $filter_supplier,
    'payment_id'    => $filter_payment,
    'payment_by_id' => $filter_payment_by,
]);
// ── CSRF token tab ini ────────────────────────────────────────────────────
$csrf_token = pf_csrfToken();   // dipakai form hapus di bagian bawah berkas

// ── All PHP-resolved JS strings (avoids any inline PHP inside $extra_js) ───
//
// TAHAP 3 — addslashes() DIGANTI substr(bltx_js(...), 1, -1).
//
// Nilai-nilai di bawah disisipkan ke dalam literal "..." JavaScript di bagian
// bawah berkas. addslashes() hanya melindungi tanda kutip; ia TIDAK menutup
// <\/script>. Dua di antara nilai ini — filter_from dan filter_to — berasal
// langsung dari $_GET['from_date'] dan $_GET['to_date'], jadi isinya
// sepenuhnya ditentukan pengunjung.
//
// bltx_js() menghasilkan literal JSON; substr(...,1,-1) membuang tanda kutip
// pembungkusnya sehingga hasilnya pas dimasukkan ke dalam "..." yang sudah ada
// di sisi JS — TIDAK ADA satu pun baris emisi di bawah yang perlu diubah.
// JSON_HEX_TAG mengubah < menjadi \u003C, jadi <\/script> tidak lagi bisa lolos.
$js_lang = [
    'clear'           => substr(bltx_js($LANG['clear']), 1, -1),
    'apply'           => substr(bltx_js($LANG['apply']), 1, -1),
    'filter_from'     => substr(bltx_js($filter_from), 1, -1),
    'filter_to'       => substr(bltx_js($filter_to), 1, -1),
    // Dipakai HANYA oleh tombol Reset Filter pada tab Purchase Invoice
    // (window.location.href = L.purchasesUrl). Tab dicantumkan eksplisit
    // karena default tab halaman ini sekarang 'order'.
    'purchases_url'   => substr(bltx_js(url('purchases/pr_dahsboard_gate.php?tab=invoice')), 1, -1),
    'view_url'        => substr(bltx_js(url('purchases/purchase_view.php')), 1, -1),
    'export_url'      => substr(bltx_js(url('includes/export/export_purchases.php')), 1, -1),
    'processing'      => substr(bltx_js($LANG['processing']), 1, -1),
    'search'          => substr(bltx_js($LANG['search']), 1, -1),
    'show'            => substr(bltx_js($LANG['show']), 1, -1),
    'entries'         => substr(bltx_js($LANG['entries']), 1, -1),
    'showing'         => substr(bltx_js($LANG['showing']), 1, -1),
    'to'              => substr(bltx_js($LANG['to']), 1, -1),
    'of'              => substr(bltx_js($LANG['of']), 1, -1),
    'from'            => substr(bltx_js($LANG['from']), 1, -1),
    'total'           => substr(bltx_js($LANG['total']), 1, -1),
    'filter_word'     => substr(bltx_js($LANG['filter']), 1, -1),
    'first'           => substr(bltx_js($LANG['first']), 1, -1),
    'last'            => substr(bltx_js($LANG['last']), 1, -1),
    'next'            => substr(bltx_js($LANG['next']), 1, -1),
    'previous'        => substr(bltx_js($LANG['previous']), 1, -1),
    'no_data'         => substr(bltx_js($LANG['no_data']), 1, -1),
    'no_matching'     => substr(bltx_js($LANG['no_matching']), 1, -1),
    'load_error'      => substr(bltx_js($LANG['load_error']), 1, -1),
    'date_range'      => substr(bltx_js($LANG['date_range']), 1, -1),
    'supplier'        => substr(bltx_js($LANG['supplier']), 1, -1),
    'payment_method'  => substr(bltx_js($LANG['payment_method']), 1, -1),
    'payment_by'      => substr(bltx_js($LANG['payment_by']), 1, -1),
    'export_all_data' => substr(bltx_js($LANG['export_all_data']), 1, -1),
    'all'             => substr(bltx_js($LANG['all']), 1, -1),
    'no_filter'       => substr(bltx_js($LANG['no_filter']), 1, -1),
    'confirm_export'  => substr(bltx_js($LANG['confirm_export']), 1, -1),
    'export_to'       => substr(bltx_js($LANG['export_to']), 1, -1),
    'yes_export'      => substr(bltx_js($LANG['yes_export']), 1, -1),
    'cancel'          => substr(bltx_js($LANG['cancel']), 1, -1),
    'exporting'       => substr(bltx_js($LANG['exporting']), 1, -1),
    'please_wait'     => substr(bltx_js($LANG['please_wait']), 1, -1),
    'export_success'  => substr(bltx_js($LANG['export_success']), 1, -1),
    'file_downloaded' => substr(bltx_js($LANG['file_downloaded']), 1, -1),
    'confirm'         => substr(bltx_js($LANG['confirm']), 1, -1),
    'confirm_delete'  => substr(bltx_js($LANG['confirm_delete']), 1, -1),
    'yes'             => substr(bltx_js($LANG['yes']), 1, -1),
    'loading'         => substr(bltx_js($LANG['loading']), 1, -1),
];

/* ── Aset & skrip tab Purchase Invoice ────────────────────────────────────
   TITIK PENTING: memakai .= (menambah), BUKAN = (menimpa).
   pr_dahsboard_gate.php sudah mengisi $extra_js dengan gaya BERSAMA seluruh
   tab sebelum berkas ini di-include. Kalau di sini memakai =, gaya bersama
   itu akan terhapus dan keenam tab lain ikut rusak tampilannya.

   $extra_js dicetak oleh footer.php (baris 338), yaitu SETELAH jQuery,
   Bootstrap, DataTables, dan SweetAlert2 dimuat. Karena itu skrip di bawah
   tidak perlu menunggu DOMContentLoaded untuk memastikan library tersedia —
   perilakunya sama persis seperti saat kode ini masih berada di gate. */
$extra_js = (isset($extra_js) ? $extra_js : '') . '
<link rel="stylesheet" href="' . url('assets/vendor/daterangepicker/daterangepicker.css') . '">
<style>
/* ══════════════════════════════════════════════════════════════════════════
   GAYA KHUSUS TAB PURCHASE INVOICE

   Seluruh aturan di berkas ini hanya dipakai oleh tab Purchase Invoice —
   sudah diverifikasi tidak ada satu pun partial tab lain yang memakainya.

   Gaya BERSAMA (.table, .card, h4, .alert, .btn-group-sm, img, dan aturan
   responsifnya) SENGAJA TIDAK ikut dipindah ke sini; semuanya tetap di
   pr_dahsboard_gate.php karena ketujuh tab memakainya.
   ══════════════════════════════════════════════════════════════════════ */
/* ── Currency cell: symbol left, value right ─────────────────── */
.cur-cell          { display:flex; justify-content:space-between; align-items:center; gap:4px; white-space:nowrap; }
.cur-sym           { flex-shrink:0; text-align:left; }
.cur-val           { flex:1; text-align:right; font-weight:600; }
/* ── Filter bar ──────────────────────────────────────────────── */
.filter-bar        { display:flex; flex-direction:column; gap:.75rem; margin-bottom:1rem; padding:.75rem 1rem; background:#f8f9fa; border-radius:.5rem; border:1px solid #dee2e6; box-sizing:border-box; width:100%; }
.filter-row        { display:flex; flex-wrap:nowrap; gap:.75rem; align-items:flex-end; width:100%; box-sizing:border-box; }
.filter-row.filter-actions { flex-wrap:wrap; align-items:center; gap:.5rem; }
.filter-item       { display:flex; flex-direction:column; gap:.25rem; flex:1 1 0%; min-width:0; overflow:hidden; }
.filter-bar label  { font-size:.8rem; font-weight:600; margin-bottom:0; white-space:nowrap; color:#495057; overflow:hidden; text-overflow:ellipsis; }
.filter-bar .form-control,
.filter-bar .form-select { height:36px; font-size:.875rem; width:100%; min-width:0; box-sizing:border-box; }
.daterangepicker-input { background-color:#fff !important; cursor:pointer; }
/* Dark mode for filter bar — flat selectors, universal browser support */
[data-bs-theme="dark"] .filter-bar { background:#1b2735; border-color:#2b3a4a; }
[data-bs-theme="dark"] .filter-bar label { color:#cbd5e1; }
[data-bs-theme="dark"] .daterangepicker-input { background-color:#0f172a !important; color:#e6edf3; border-color:#2b3a4a; }
/* ── Invoice thumbnail ───────────────────────────────────────── */
.invoice-thumbnail { display:block; margin:0 auto; width:80px; height:60px; object-fit:cover; cursor:pointer; border-radius:4px; transition:transform .2s ease, opacity .2s ease; background:#f8f9fa; opacity:0; }
.invoice-thumbnail.loaded { opacity:1; }
.invoice-thumbnail:hover  { transform:scale(1.1); }
/* ── Purchase view modal ─────────────────────────────────────── */
/* Header modal SENGAJA tidak diberi warna sama sekali.
   Dua aturan lama (gradient ungu + filter:invert pada tombol close) sudah
   DIHAPUS supaya modal ini memakai tampilan bawaan Bootstrap — putih bersih
   dan netral, sama seperti modal lain di aplikasi. Bonusnya, dark mode ikut
   benar dengan sendirinya karena tidak ada lagi warna yang dipaksakan. */
/* ── Skeleton tabel Purchase Invoice ─────────────────────────── */
/* Kelas .pv-table-loading DIPASANG OLEH JAVASCRIPT (lihat bagian bawah
   berkas ini), bukan tertulis di HTML. Jadi kalau JavaScript mati, tidak ada
   yang pernah menyembunyikan tabel. */
#pvTableZone.pv-table-loading { display:none; }
.pv-skeleton { padding:2.5rem 0; text-align:center; }
/* ── Responsive (khusus elemen tab ini) ──────────────────────── */
@media (max-width:992px){
    .filter-row{flex-wrap:wrap;} .filter-item{flex:1 1 calc(50% - .375rem);min-width:0;}
}
@media (max-width:768px){
    .invoice-thumbnail{width:60px;height:45px;}
    .filter-row{flex-wrap:wrap;} .filter-item{flex:1 1 100%;min-width:0;} .filter-row.filter-actions .btn{flex:1 1 auto;}
}
@media (max-width:576px){
    .invoice-thumbnail{width:50px;height:38px;}
}
</style>

<script src="' . url('assets/vendor/moment/moment.min.js') . '"></script>
<script src="' . url('assets/vendor/daterangepicker/daterangepicker.min.js') . '"></script>
<script>
/* ── Daterangepicker init — dijalankan langsung setelah library ter-load ── */
/* $extra_js di-echo di footer.php setelah jQuery & DOM sudah siap,         */
/* sehingga tidak perlu $(document).ready di sini.                           */
(function ($) {
    if (!$.fn.daterangepicker) return;
    var clear  = "' . substr(bltx_js($LANG['clear']), 1, -1) . '";
    var apply  = "' . substr(bltx_js($LANG['apply']), 1, -1) . '";
    var fromV  = "' . substr(bltx_js($filter_from), 1, -1) . '";
    var toV    = "' . substr(bltx_js($filter_to), 1, -1)   . '";

    /* ══════════════════════════════════════════════════════════════════
       Filter date range (tab Purchase Invoice)

       DUA FORMAT YANG BERBEDA, DAN INI DISENGAJA:
         - TAMPILAN di kotak teks : DD-MM-YYYY  (permintaan user)
         - NILAI yang dikirim     : YYYY-MM-DD  (WAJIB, jangan diubah)

       Kenapa nilainya wajib YYYY-MM-DD: isi #filter_from_date /
       #filter_to_date masuk langsung ke
           WHERE p.input_date BETWEEN ? AND ?
       di pf_getPurchasesList(), dan ke includes/export/export_purchases.php.
       Kalau ikut diubah jadi DD-MM-YYYY, filter dan export akan diam-diam
       mengembalikan hasil KOSONG tanpa pesan error apa pun.

       maxDate = hari ini: tanggal setelah hari ini tidak bisa dipilih.
       ══════════════════════════════════════════════════════════════════ */
    var DISPLAY_FMT = "DD-MM-YYYY";   /* untuk mata pengguna   */
    var VALUE_FMT   = "YYYY-MM-DD";   /* untuk server & query  */

    $("#filter_date_range").daterangepicker({
        autoUpdateInput: false,
        maxDate: moment(),
        locale: { cancelLabel: clear, applyLabel: apply, format: DISPLAY_FMT }
    });
    $("#filter_date_range").on("apply.daterangepicker", function (ev, picker) {
        /* Kotak teks memakai format tampilan ... */
        $(this).val(picker.startDate.format(DISPLAY_FMT) + " - " + picker.endDate.format(DISPLAY_FMT));
        /* ... sedangkan input tersembunyi tetap format nilai. */
        $("#filter_from_date").val(picker.startDate.format(VALUE_FMT));
        $("#filter_to_date").val(picker.endDate.format(VALUE_FMT));
    });
    $("#filter_date_range").on("cancel.daterangepicker", function () {
        $(this).val(""); $("#filter_from_date, #filter_to_date").val("");
    });
    /* Nilai dari query string datang dalam YYYY-MM-DD (hasil submit filter),
       jadi harus diterjemahkan dulu ke DD-MM-YYYY sebelum ditampilkan. */
    if (fromV && toV) {
        var mFrom = moment(fromV, VALUE_FMT, true);
        var mTo   = moment(toV,   VALUE_FMT, true);
        if (mFrom.isValid() && mTo.isValid()) {
            $("#filter_date_range").val(mFrom.format(DISPLAY_FMT) + " - " + mTo.format(DISPLAY_FMT));
            $("#filter_date_range").data("daterangepicker").setStartDate(mFrom);
            $("#filter_date_range").data("daterangepicker").setEndDate(mTo);
        } else {
            $("#filter_date_range").val(fromV + " - " + toV);
        }
    }

    /* Export date range (jika ada) */
    if ($("#export_date_range").length) {
        $("#export_date_range").daterangepicker({
            autoUpdateInput: false,
            locale: { cancelLabel: clear, applyLabel: apply, format: "YYYY-MM-DD" }
        });
        $("#export_date_range").on("apply.daterangepicker", function (ev, picker) {
            $(this).val(picker.startDate.format("YYYY-MM-DD") + " - " + picker.endDate.format("YYYY-MM-DD"));
        });
        $("#export_date_range").on("cancel.daterangepicker", function () { $(this).val(""); });
    }
}(jQuery));
</script>
<script>
(function ($) {
"use strict";

/* ── Server-resolved strings ─────────────────────────────────────────────── */
var L = {
    clear:         "' . $js_lang['clear'] . '",
    apply:         "' . $js_lang['apply'] . '",
    filterFrom:    "' . $js_lang['filter_from'] . '",
    filterTo:      "' . $js_lang['filter_to'] . '",
    purchasesUrl:  "' . $js_lang['purchases_url'] . '",
    viewUrl:       "' . $js_lang['view_url'] . '",
    exportUrl:     "' . $js_lang['export_url'] . '",
    processing:    "' . $js_lang['processing'] . '",
    search:        "' . $js_lang['search'] . '",
    show:          "' . $js_lang['show'] . '",
    entries:       "' . $js_lang['entries'] . '",
    showing:       "' . $js_lang['showing'] . '",
    toLbl:         "' . $js_lang['to'] . '",
    ofLbl:         "' . $js_lang['of'] . '",
    fromLbl:       "' . $js_lang['from'] . '",
    totalLbl:      "' . $js_lang['total'] . '",
    filterWord:    "' . $js_lang['filter_word'] . '",
    first:         "' . $js_lang['first'] . '",
    last:          "' . $js_lang['last'] . '",
    next:          "' . $js_lang['next'] . '",
    previous:      "' . $js_lang['previous'] . '",
    noData:        "' . $js_lang['no_data'] . '",
    noMatching:    "' . $js_lang['no_matching'] . '",
    loadError:     "' . $js_lang['load_error'] . '",
    dateRange:     "' . $js_lang['date_range'] . '",
    supplier:      "' . $js_lang['supplier'] . '",
    payMethod:     "' . $js_lang['payment_method'] . '",
    payBy:         "' . $js_lang['payment_by'] . '",
    exportAll:     "' . $js_lang['export_all_data'] . '",
    all:           "' . $js_lang['all'] . '",
    noFilter:      "' . $js_lang['no_filter'] . '",
    confirmExport: "' . $js_lang['confirm_export'] . '",
    exportTo:      "' . $js_lang['export_to'] . '",
    yesExport:     "' . $js_lang['yes_export'] . '",
    cancel:        "' . $js_lang['cancel'] . '",
    exporting:     "' . $js_lang['exporting'] . '",
    pleaseWait:    "' . $js_lang['please_wait'] . '",
    expSuccess:    "' . $js_lang['export_success'] . '",
    fileDl:        "' . $js_lang['file_downloaded'] . '",
    confirm:       "' . $js_lang['confirm'] . '",
    confirmDel:    "' . $js_lang['confirm_delete'] . '",
    yes:           "' . $js_lang['yes'] . '",
    loading:       "' . $js_lang['loading'] . '"
};

/* ── DataTables date-eu sort plugin ─────────────────────────────────────── */
$.extend($.fn.dataTableExt.oSort, {
    "date-eu-pre":  function (a) {
        if (!a || a === "-") return 0;
        var p = a.split("/");
        return (p.length === 3) ? parseInt(p[2] + p[1] + p[0], 10) : 0;
    },
    "date-eu-asc":  function (a, b) { return a - b; },
    "date-eu-desc": function (a, b) { return b - a; }
});

/* ── Lazy-load image helper ─────────────────────────────────────────────── */
var _observer = ("IntersectionObserver" in window)
    ? new IntersectionObserver(function (entries, obs) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var img = entry.target, src = img.dataset.src;
            if (!src) return;
            var t = new Image();
            t.onload  = function () { img.src = src; img.classList.add("loaded"); };
            t.onerror = function () { img.style.opacity = "0.3"; };
            t.src = src;
            obs.unobserve(img);
        });
    }, { rootMargin: "100px" })
    : null;

function lazyLoad() {
    document.querySelectorAll(".invoice-thumbnail:not(.loaded)").forEach(function (img) {
        if (!_observer) {
            var src = img.dataset.src;
            if (src) { img.src = src; img.classList.add("loaded"); }
        } else if (img.src.indexOf("data:image/gif") !== -1) {
            _observer.observe(img);
        }
    });
}

/* ═══════════════════════════════════════════════════════════════
   DOM READY
═══════════════════════════════════════════════════════════════ */
$(document).ready(function () {

    /* ── 1. Date-range picker: sudah di-init pada blok script terpisah di atas ── */

    /* ── 2. Reset filter button ── */
    $("#btnResetFilter").on("click", function () {
        $("#filter_date_range").val("");
        $("#filter_from_date, #filter_to_date").val("");
        $("#filter_supplier, #filter_payment, #filter_payment_by").val("0");
        window.location.href = L.purchasesUrl;
    });

    /* ══════════════════════════════════════════════════════════════════
       3. DataTable — inisialisasi DITUNDA satu frame

       Tabel Purchase Invoice mencetak seluruh baris sekaligus. Bila
       DataTables langsung berjalan di dalam ready(), browser harus
       menyelesaikan layout ribuan baris DULU sebelum apa pun tampil, lalu
       DataTables membuang hampir semuanya dan menyisakan 25 baris. Kerja
       layout besar itu terbuang, dan itulah jeda yang terasa.

       Dengan requestAnimationFrame berlapis dua, browser dipersilakan
       melukis kerangka halaman + skeleton lebih dulu, baru pekerjaan berat
       dijalankan. SELURUH OPSI DATATABLES DI BAWAH INI TIDAK ADA YANG
       DIUBAH — hanya WAKTU pemanggilannya yang bergeser, sehingga tampilan
       dan perilaku akhirnya sama persis.

       try/finally: apa pun yang terjadi pada init, tabel WAJIB ditampilkan
       kembali supaya data tidak pernah tersembunyi permanen.
       ══════════════════════════════════════════════════════════════════ */
    var dt = null;

    function pvInitPurchasesTable() {
        var $tbl = $("#purchasesTable");
        if (!$tbl.length) return;   /* tab lain: tabel ini tidak ada */

        /* Explicit dom ensures length/search/info/pagination always render */
        dt = $tbl.DataTable({
            dom:
                "<\"row mb-2\"<\"col-sm-12 col-md-6\"l><\"col-sm-12 col-md-6\"f>>" +
                "<\"row\"<\"col-sm-12\"tr>>" +
                "<\"row mt-2\"<\"col-sm-12 col-md-5\"i><\"col-sm-12 col-md-7\"p>>",
            order:       [[0, "desc"]],
            pageLength:  25,
            lengthMenu:  [[25, 50, 100, 250, 500], [25, 50, 100, 250, 500]],
            responsive:  true,
            autoWidth:   false,
            deferRender: true,
            columnDefs: [
                { targets: [0, 1], type: "date-eu" },
                { targets: [-1],   orderable: false, searchable: false }
            ],
            language: {
                processing:  L.processing,
                search:      L.search + ":",
                lengthMenu:  L.show + " _MENU_ " + L.entries,
                info:        L.showing + " _START_ " + L.toLbl + " _END_ " + L.ofLbl + " _TOTAL_ " + L.entries,
                infoEmpty:   L.showing + " 0 " + L.toLbl + " 0 " + L.ofLbl + " 0 " + L.entries,
                infoFiltered:"(" + L.filterWord + " " + L.fromLbl + " _MAX_ " + L.totalLbl + " " + L.entries + ")",
                paginate:    { first: L.first, last: L.last, next: L.next, previous: L.previous },
                emptyTable:  L.noData,
                zeroRecords: L.noMatching
            },
            /* Fire lazy-load after every page draw (pagination, search, sort) */
            drawCallback: function () { lazyLoad(); }
        });
    }

    function pvBootPurchasesTable() {
        try {
            pvInitPurchasesTable();
        } finally {
            if (typeof window.pvRevealTable === "function") window.pvRevealTable();
        }
    }

    if (window.requestAnimationFrame) {
        requestAnimationFrame(function () { requestAnimationFrame(pvBootPurchasesTable); });
    } else {
        pvBootPurchasesTable();
    }

    /* 4. Pratinjau gambar invoice -- memakai lightbox BERSAMA di
       includes/footer.php. Sekitar 85 baris JS zoom/geser/cubit dihapus.

       Blok ini berada di dalam string PHP $extra_js yang dibuka di baris 150.
       Percobaan sebelumnya memakai  typeof x === (kutip)function(kutip)  dan
       membuat seluruh halaman blank: PHP membaca tanda kutip itu sebagai
       penutup string, lalu kata function berikutnya sebagai kata kunci PHP.
       Pemeriksaan di bawah karena itu TIDAK memakai tanda kutip sama sekali. */
    window.openInvoiceModal = function (src) {
        if (window.bltxPhotoLightbox) { window.bltxPhotoLightbox(src); }
    };

    /* Event delegation — catches thumbnails on any page / after DataTable redraw */
    $(document).on("click", ".invoice-thumbnail", function () {
        var src = this.dataset.src;
        if (!src) return;
        openInvoiceModal(src);
    });

    /* ── 5. Purchase view modal — AJAX with event delegation ── */
    var pvModalEl = document.getElementById("purchaseViewModal");
    var pvModal   = pvModalEl ? new bootstrap.Modal(pvModalEl) : null;
    var pvLoading = document.getElementById("pvLoading");
    var pvContent = document.getElementById("pvContent");

    /* Delegation on document: works even after DataTable pagination redraws the rows */
    $(document).on("click", ".btn-view-purchase", function () {
        var id = $(this).data("id");
        if (!pvModal || !id) return;

        pvLoading.style.display = "block";
        pvContent.innerHTML     = "";
        pvModal.show();

        fetch(L.viewUrl + "?id=" + encodeURIComponent(id) + "&modal=1", {
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(function (r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            return r.text();
        })
        .then(function (html) {
            pvLoading.style.display = "none";
            pvContent.innerHTML     = html;
            /* Re-execute any <script> tags injected by purchase_view modal fragment */
            pvContent.querySelectorAll("script").forEach(function (s) {
                var ns = document.createElement("script");
                if (s.src) { ns.src = s.src; } else { ns.textContent = s.textContent; }
                document.head.appendChild(ns);
            });
        })
        .catch(function () {
            pvLoading.style.display = "none";
            pvContent.innerHTML = "<div class=\"alert alert-danger m-3\">" + L.loadError + "</div>";
        });
    });

    if (pvModalEl) {
        pvModalEl.addEventListener("hidden.bs.modal", function () {
            if (pvContent) pvContent.innerHTML     = "";
            if (pvLoading) pvLoading.style.display = "block";
        });
    }

    /* ── Inject _pvLang so pvModalExport() (loaded from modal fragment) can use SweetAlert strings ── */
    window._pvLang = {
        confirmExport: L.confirmExport,
        exportTo:      L.exportTo,
        yesExport:     L.yesExport,
        cancel:        L.cancel,
        exporting:     L.exporting,
        pleaseWait:    L.pleaseWait,
        expSuccess:    L.expSuccess,
        fileDl:        L.fileDl,
        purchase:      "' . substr(bltx_js($LANG['purchase']), 1, -1) . '",
        details:       "' . substr(bltx_js($LANG['details']), 1, -1)  . '"
    };

    /* ── Wire modal footer export button to pvModalExport() ──
       Tombol Export PDF beserta wiring-nya SUDAH DIHAPUS; tinggal Excel. */
    var pvBtnExcel = document.getElementById("pvModalBtnExcel");
    if (pvBtnExcel) {
        pvBtnExcel.addEventListener("click", function () {
            if (typeof window.pvModalExport === "function") window.pvModalExport("excel");
        });
    }

    /* ── Initial lazy-load for images already visible on page load ── */
    lazyLoad();

}); /* end ready */

/* ════════════════════════════════════════════════════════════════
   Global helpers (called from onclick attributes)
════════════════════════════════════════════════════════════════ */
window.exportData = function (type) {
    var fromVal = $("#filter_from_date").val() || "";
    var toVal   = $("#filter_to_date").val()   || "";
    var dateRange = (fromVal && toVal) ? fromVal + " - " + toVal : "";

    var selSup = document.getElementById("filter_supplier");
    var selPay = document.getElementById("filter_payment");
    var selPB  = document.getElementById("filter_payment_by");
    var desc   = [];

    if (dateRange) desc.push("<strong>" + L.dateRange + ":</strong> " + dateRange);
    if (selSup && selSup.value !== "0") desc.push("<strong>" + L.supplier  + ":</strong> " + selSup.options[selSup.selectedIndex].text);
    if (selPay && selPay.value !== "0") desc.push("<strong>" + L.payMethod + ":</strong> " + selPay.options[selPay.selectedIndex].text);
    if (selPB  && selPB.value  !== "0") desc.push("<strong>" + L.payBy     + ":</strong> " + selPB.options[selPB.selectedIndex].text);

    var fHtml = "", wHtml = "";
    if (desc.length === 0) {
        wHtml = "<div class=\"alert alert-warning mt-2\"><i class=\"bi bi-exclamation-triangle\"></i> " + L.exportAll + "</div>";
        fHtml = "<div style=\"text-align:left;margin-top:10px;\"><strong>" + L.filterWord + ":</strong> " + L.all + " (" + L.noFilter + ")</div>";
    } else {
        fHtml = "<div style=\"text-align:left;margin-top:10px;\">" + desc.join("<br>") + "</div>";
    }

    Swal.fire({
        title: L.confirmExport,
        html:  "<div style=\"text-align:left;\"><strong>" + L.exportTo + ":</strong> " + type.toUpperCase() + "</div>" + fHtml + wHtml,
        icon:  "question",
        showCancelButton:   true,
        confirmButtonColor: type === "excel" ? "#28a745" : "#dc3545",
        cancelButtonColor:  "#6c757d",
        confirmButtonText:  "<i class=\"bi bi-check-circle\"></i> " + L.yesExport,
        cancelButtonText:   "<i class=\"bi bi-x-circle\"></i> " + L.cancel,
        reverseButtons:     true
    }).then(function (res) {
        if (!res.isConfirmed) return;
        var form   = document.getElementById("filterForm");
        var params = new URLSearchParams(new FormData(form));
        params.delete("tab");   /* field navigasi tab, bukan parameter export */
        params.set("type", type);
        params.set("data", "purchases");
        if (fromVal && toVal) { params.set("from_date", fromVal); params.set("to_date", toVal); }

        Swal.fire({
            title: L.exporting, html: L.pleaseWait,
            allowOutsideClick: false, didOpen: function () { Swal.showLoading(); }
        });
        window.location.href = L.exportUrl + "?" + params.toString();
        setTimeout(function () {
            Swal.close();
            Swal.fire({ icon: "success", title: L.expSuccess, text: L.fileDl, timer: 2000, showConfirmButton: false });
        }, 2000);
    });
};

window.confirmDeletePurchase = function (id) {
    Swal.fire({
        title:              L.confirm,
        text:               L.confirmDel,
        icon:               "warning",
        showCancelButton:   true,
        confirmButtonColor: "#d33",
        cancelButtonColor:  "#3085d6",
        confirmButtonText:  "<i class=\"bi bi-trash\"></i> " + L.yes,
        cancelButtonText:   "<i class=\"bi bi-x-circle\"></i> " + L.cancel,
        reverseButtons:     true
    }).then(function (res) {
        if (res.isConfirmed) {
            document.getElementById("deleteFormId").value = id;
            document.getElementById("deleteForm").submit();
        }
    });
};

}(jQuery));
</script>
';
?>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
   blok HTML+JS yang isinya identik dengan berkas lain. */
bltx_render_flash('pv');
?>

<div class="card">
    <div class="card-header">
                <div class="card-title">
            <i class="bi bi-funnel"></i> <?php echo $LANG['purchase_invoice']; ?></div>
        <div class="card-tools">
            <?php if (canCreatePurchase()): ?>
                <a href="<?php echo url('purchases/purchase_add.php'); ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> <?php echo $LANG['add']; ?> <?php echo $LANG['purchases']; ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">

        <!-- Filter bar -->
        <?php if (canExportPurchase()): ?>
        <form method="GET" action="<?php echo url('purchases/pr_dahsboard_gate.php'); ?>" id="filterForm">
            <!-- Form GET: query string pada action DIABAIKAN browser, jadi tab
                 harus dikirim sebagai field. Tanpa ini hasil filter akan
                 mendarat di tab default halaman (Purchase Order).
                 Karena itu action sengaja ditulis TANPA ?tab=invoice —
                 menambahkannya hanya akan menyesatkan pembaca kode, sebab
                 browser membuangnya sebelum permintaan dikirim. -->
            <input type="hidden" name="tab" value="invoice">
            <div class="filter-bar">
                <div class="filter-row filter-inputs">

                    <div class="filter-item">
                        <label for="filter_date_range"><i class="bi bi-calendar-range"></i> <?php echo $LANG['from_date']; ?></label>
                        <input type="text" id="filter_date_range"
                               class="form-control daterangepicker-input"
                               placeholder="<?php echo htmlspecialchars($LANG['from_date']); ?> - <?php echo htmlspecialchars($LANG['to_date']); ?>"
                               readonly>
                        <input type="hidden" id="filter_from_date" name="from_date" value="<?php echo htmlspecialchars($filter_from); ?>">
                        <input type="hidden" id="filter_to_date"   name="to_date"   value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>

                    <div class="filter-item">
                        <label for="filter_supplier"><i class="bi bi-building"></i> <?php echo $LANG['supplier']; ?></label>
                        <select id="filter_supplier" name="filter_supplier" class="form-select">
                            <option value="0"><?php echo $LANG['all']; ?></option>
                            <?php foreach ($suppliers_list as $sup): ?>
                                <option value="<?php echo (int)$sup['id']; ?>" <?php echo ($filter_supplier == $sup['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sup['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label for="filter_payment"><i class="bi bi-credit-card"></i> <?php echo $LANG['payment_method']; ?></label>
                        <select id="filter_payment" name="filter_payment" class="form-select">
                            <option value="0"><?php echo $LANG['all']; ?></option>
                            <?php foreach ($payment_methods_list as $pm): ?>
                                <option value="<?php echo (int)$pm['id']; ?>" <?php echo ($filter_payment == $pm['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pm['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label for="filter_payment_by"><i class="bi bi-person"></i> <?php echo $LANG['payment_by']; ?></label>
                        <select id="filter_payment_by" name="filter_payment_by" class="form-select">
                            <option value="0"><?php echo $LANG['all']; ?></option>
                            <?php foreach ($payment_by_list as $pb): ?>
                                <option value="<?php echo (int)$pb['id']; ?>" <?php echo ($filter_payment_by == $pb['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pb['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div class="filter-row filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel-fill"></i> <?php echo $LANG['filter']; ?>
                    </button>
                    <button type="button" id="btnResetFilter" class="btn btn-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> <?php echo $LANG['reset']; ?>
                    </button>
                    <button type="button" id="btnExportExcel" onclick="exportData('excel')" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-excel"></i> <?php echo $LANG['export_excel']; ?>
                    </button>
                </div>
            </div>
        </form>

<script>
/* ══════════════════════════════════════════════════════════════════════════
   EKSPOR HANYA BOLEH SAAT "From Date" TERISI
   Tanpa rentang tanggal, ekspor akan menarik SELURUH riwayat pembelian —
   berkasnya besar dan prosesnya berat. Karena itu tombol ekspor dikunci
   selama From Date masih kosong.

   Dua lapis, keduanya di berkas ini saja:
     1. Tombol di-disable + diberi keterangan lewat atribut title, sehingga
        alasannya terlihat sebelum diklik. Tombol disabled tidak memicu
        onclick, jadi ini sudah cukup untuk pemakaian normal.
     2. Penahan pada fase CAPTURE sebagai jaring pengaman, untuk kasus nilai
        tanggal berubah lewat jalur yang tidak memancarkan event (misalnya
        skrip lain menulis langsung ke input tersembunyi).

   Nilai dibaca dari #filter_from_date — input tersembunyi yang diisi oleh
   daterangepicker, bukan dari kotak teks yang tampil.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var fromEl = document.getElementById('filter_from_date');
        /* Tombol Export PDF sudah dihapus dari halaman ini, jadi yang dikunci
           tinggal Export Excel. Bentuknya tetap array supaya tombol ekspor
           lain di masa depan cukup didaftarkan di sini. */
        var btns   = [document.getElementById('btnExportExcel')].filter(Boolean);
        if (!fromEl || !btns.length) return;

        var MSG = <?= json_encode($LANG['export_needs_from_date'], JSON_UNESCAPED_UNICODE) ?>;

        function hasFrom() { return (fromEl.value || '').trim() !== ''; }

        function sync() {
            var ok = hasFrom();
            btns.forEach(function (b) {
                b.disabled = !ok;
                b.classList.toggle('disabled', !ok);
                if (ok) { b.removeAttribute('title'); }
                else    { b.setAttribute('title', MSG); }
            });
        }

        /* Jaring pengaman: hentikan klik sebelum onclick sempat berjalan. */
        btns.forEach(function (b) {
            b.addEventListener('click', function (e) {
                if (hasFrom()) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: '', text: MSG,
                                timer: 3000, showConfirmButton: false });
                }
            }, true);
        });

        fromEl.addEventListener('change', sync);
        fromEl.addEventListener('input',  sync);

        /* daterangepicker memancarkan event-nya lewat jQuery pada kotak teks. */
        if (window.jQuery) {
            jQuery('#filter_date_range').on('apply.daterangepicker cancel.daterangepicker', function () {
                setTimeout(sync, 0);
            });
        }

        /* Tombol Reset mengosongkan filter, termasuk tanggal. */
        var reset = document.getElementById('btnResetFilter');
        if (reset) reset.addEventListener('click', function () { setTimeout(sync, 0); });

        sync();
    });
})();
</script>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════════════
             SKELETON SAAT DATATABLES MENYIAPKAN TABEL
             Elemen ini kosong di HTML dan baru diisi oleh skrip di bawah
             tabel. Artinya bila JavaScript mati, tidak ada apa pun yang
             menyembunyikan tabel — daftar tetap tampil apa adanya.
             ══════════════════════════════════════════════════════════════ -->
        <div id="pvTableSkeleton"></div>

        <!-- Purchases table -->
        <div class="table-responsive" id="pvTableZone">
            <table class="table table-striped table-hover" id="purchasesTable">
                <thead>
                    <tr>
                        <th><?php echo $LANG['input_date']; ?></th>
                        <th><?php echo $LANG['invoice_date']; ?></th>
                        <th><?php echo $LANG['supplier']; ?></th>
                        <th><?php echo $LANG['payment_by']; ?></th>
                        <th><?php echo $LANG['payment_method']; ?></th>
                        <th><?php echo $LANG['document']; ?></th>
                        <th><?php echo $LANG['total']; ?></th>
                        <th><?php echo $LANG['actions']; ?></th>
                    </tr>
                </thead>
                <tbody>
<?php
/* ══════════════════════════════════════════════════════════════════════════
   BARIS TABEL — DICETAK RINGKAS

   ELEMEN, KELAS, ATRIBUT, URUTAN KOLOM, DAN ISI SETIAP SEL SAMA PERSIS
   dengan versi sebelumnya. Yang berubah hanya CARA mencetaknya.

   Kenapa diubah: template lama menuliskan indentasi template PHP ke dalam
   HTML hasil. Pada 2.500+ invoice, spasi kosong itu saja menjadi jutaan
   karakter yang harus dikirim ke browser dan diurai ulang — padahal browser
   membuangnya. Mencetak ringkas memangkas ukuran halaman drastis tanpa
   mengubah satu piksel pun tampilan, karena spasi antar-tag di dalam tabel
   memang tidak dirender.

   Tiga hal lain yang ikut dirapikan, semuanya di luar loop:
     - Pemeriksaan izin (canRead/canUpdate/canDelete) dulu dipanggil SEKALI
       PER BARIS. Untuk 2.500 baris itu ribuan pemanggilan yang hasilnya
       selalu sama. Sekarang dievaluasi sekali saja.
     - url() dan htmlspecialchars($LANG[...]) juga dulu dipanggil per baris;
       sekarang disiapkan sekali sebagai awalan/teks tetap.
     - Potongan HTML yang tidak berubah antar baris dirakit sekali.
   ══════════════════════════════════════════════════════════════════════ */
$__can_view   = canReadPurchase();
$__can_edit   = canUpdatePurchase();
$__can_delete = canDeletePurchase();

$__url_doc    = url('uploads/purchase/');
$__url_edit   = url('purchases/purchase_edit.php?id=');
$__t_view     = htmlspecialchars($LANG['view']);
$__t_edit     = htmlspecialchars($LANG['edit']);
$__t_delete   = htmlspecialchars($LANG['delete']);

/* Placeholder 1x1 transparan untuk lazy-load (sama seperti sebelumnya). */
$__img_ph     = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
$__no_doc     = '<span class="text-muted">-</span>';

if ($result):
    while ($row = $result->fetch_assoc()):
        $__id = (int)$row['id'];

        if (!empty($row['document'])) {
            $__doc = '<img src="' . $__img_ph . '" class="invoice-thumbnail"'
                   . ' data-src="' . $__url_doc . htmlspecialchars($row['document']) . '"'
                   . ' alt="Invoice" width="80" height="60">';
        } else {
            $__doc = $__no_doc;
        }

        $__actions = '';
        if ($__can_view) {
            $__actions .= '<button type="button" class="btn btn-sm btn-info btn-view-purchase"'
                        . ' data-id="' . $__id . '" title="' . $__t_view . '">'
                        . '<i class="bi bi-eye"></i></button>';
        }
        if ($__can_edit) {
            $__actions .= '<a href="' . $__url_edit . $__id . '" class="btn btn-sm btn-warning"'
                        . ' title="' . $__t_edit . '">'
                        . '<i class="bi bi-pencil"></i></a>';
        }
        if ($__can_delete) {
            $__actions .= '<button type="button" class="btn btn-sm btn-danger"'
                        . ' onclick="confirmDeletePurchase(' . $__id . ')"'
                        . ' title="' . $__t_delete . '">'
                        . '<i class="bi bi-trash"></i></button>';
        }

        echo '<tr>'
           . '<td>' . date('d/m/Y', strtotime($row['input_date']))   . '</td>'
           . '<td>' . date('d/m/Y', strtotime($row['invoice_date'])) . '</td>'
           . '<td>' . htmlspecialchars($row['supplier_name']       ?? '-') . '</td>'
           . '<td>' . htmlspecialchars($row['payment_by_name']     ?? '-') . '</td>'
           . '<td>' . htmlspecialchars($row['payment_method_name'] ?? '-') . '</td>'
           . '<td>' . $__doc . '</td>'
           . '<td><div class="cur-cell">' . pf_currencyCell((float)$row['grand_total']) . '</div></td>'
           . '<td><div class="btn btn-sm-group btn-group-sm">' . $__actions . '</div></td>'
           . '</tr>' . "\n";
    endwhile;
endif;
?>
                </tbody>
            </table>
        </div>

        <script>
        /* ══════════════════════════════════════════════════════════════════
           PENYEMBUNYIAN SEMENTARA SEBELUM DATATABLES SIAP
           Tabel ini mencetak seluruh baris invoice sekaligus. Bila browser
           me-layout dan melukis ribuan baris itu lebih dulu, lalu DataTables
           membuangnya dan menyisakan 25 baris, kerja layout besar tadi
           terbuang percuma — inilah jeda yang terasa saat halaman dibuka.

           Zona tabel di-display:none-kan SEBELUM layout terjadi, lalu
           dibuka kembali oleh pr_dahsboard_gate.php tepat setelah DataTables
           selesai. Tidak ada satu pun elemen, kelas, atau gaya tabel yang
           berubah — hanya urutan penggambarannya.

           Skrip ini sengaja diletakkan SETELAH tabel (bukan di <head>) supaya
           berjalan segera saat elemen sudah ada, tanpa menunggu DOMContentLoaded.

           JARING PENGAMAN: bila karena sebab apa pun DataTables tidak pernah
           jalan, timer di bawah tetap menampilkan kembali tabelnya.
           ══════════════════════════════════════════════════════════════════ */
        (function () {
            var zone = document.getElementById('pvTableZone');
            var skel = document.getElementById('pvTableSkeleton');
            if (!zone || !skel) return;

            zone.classList.add('pv-table-loading');
            skel.className = 'pv-skeleton';
            skel.innerHTML =
                '<div class="spinner-border text-primary" role="status">' +
                '<span class="visually-hidden"><?php echo htmlspecialchars($LANG['loading'], ENT_QUOTES); ?></span>' +
                '</div>' +
                '<p class="mt-2 mb-0 text-muted small"><?php echo htmlspecialchars($LANG['loading'], ENT_QUOTES); ?></p>';

            window.pvRevealTable = function () {
                zone.classList.remove('pv-table-loading');
                skel.className   = '';
                skel.innerHTML   = '';
            };

            setTimeout(function () {
                if (typeof window.pvRevealTable === 'function') window.pvRevealTable();
            }, 8000);
        })();
        </script>

    </div>
</div>

<!-- Modal gambar invoice DIHAPUS -- digantikan lightbox bersama di
     includes/footer.php (#bltxLightboxModal). -->

<!-- Purchase view modal (AJAX) -->
<div class="modal fade" id="purchaseViewModal" tabindex="-1" aria-labelledby="purchaseViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="purchaseViewModalLabel">
                    <i class="bi bi-eye"></i> <?php echo htmlspecialchars($LANG['view'] . ' ' . ($LANG['purchase'])); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars($LANG['close']); ?>"></button>
            </div>
            <div class="modal-body p-0">
                <div id="pvLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden"><?php echo htmlspecialchars($LANG['loading']); ?></span>
                    </div>
                    <p class="mt-2 text-muted"><?php echo htmlspecialchars($LANG['loading']); ?></p>
                </div>
                <div id="pvContent"></div>
            </div>
            <div class="modal-footer">
                <?php if (canExportPurchase()): ?>
                <button type="button" class="btn btn-success btn-sm" id="pvModalBtnExcel">
                    <i class="bi bi-file-earmark-excel"></i> <?php echo htmlspecialchars($LANG['export_excel']); ?>
                </button>
                <?php endif; ?>
                <!-- btn-sm ditambahkan agar tingginya sama persis dengan
                     tombol Export Excel di sebelahnya. -->
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> <?php echo htmlspecialchars($LANG['close']); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden POST form for secure delete -->
<!-- ══════════════════════════════════════════════════════════════════════
     FORM HAPUS INVOICE

     Action WAJIB menyertakan ?tab=invoice, dan ini bukan sekadar kerapian:
     handler POST-nya kini berada DI DALAM berkas ini, dan berkas ini hanya
     di-include oleh gate ketika tab invoice yang aktif. Tanpa ?tab=invoice,
     gate jatuh ke tab pertama yang diizinkan (Purchase Order), partial ini
     tidak pernah dimuat, dan penghapusan tidak akan pernah dieksekusi.

     Sebelumnya action menunjuk ke purchases.php, yang hanya mengalihkan
     dengan HTTP 302. Pada 302 browser mengubah POST menjadi GET dan membuang
     isi form, sehingga $_POST tidak pernah sampai ke handler. Sekarang POST
     mendarat LANGSUNG di gate — tanpa pengalihan, tanpa kehilangan data.
     ══════════════════════════════════════════════════════════════════════ -->
<form id="deleteForm" method="POST" action="<?php echo url('purchases/pr_dahsboard_gate.php?tab=invoice'); ?>" style="display:none;">
    <input type="hidden" name="action"     value="delete">
    <input type="hidden" name="del_id"     id="deleteFormId" value="">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
</form>