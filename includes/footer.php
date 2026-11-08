<?php
/* ═════════════════════════════════════════════════════════════════════════
   FOOTER — AdminLTE v4 Refactor
   Menutup app-content/app-main/app-wrapper yang dibuka di header.php
   dan memuat semua JS vendor LOKAL (no CDN).
   ═════════════════════════════════════════════════════════════════════════ */

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.4 © AG');
}
?>

        </div><!-- /.content (legacy page-content wrapper opened in header) -->
      </div><!-- /.container-fluid -->
    </div><!-- /.app-content -->
  </main><!-- /.app-main -->

  <!-- ════════════════ App Footer ════════════════ -->
  <footer class="app-footer">
    <div class="footer-text"><?php echo getSetting('footer_text', '© 2026 ARW Management System. All rights reserved.'); ?></div>
    <div class="footer-version">v<?php echo APP_VERSION; ?></div>
  </footer>

</div><!-- /.app-wrapper -->

<!-- ═════════ JS — LOCAL ONLY, NO CDN ═════════ -->
<script src="<?= url('assets/vendor/jquery/jquery.min.js') ?>"></script>
<!-- overlayscrollbars — WAJIB sebelum bootstrap dan adminlte.js.
     Urutan ini disalin dari starter.html bawaan AdminLTE 4.9.1:
       overlayscrollbars -> popper -> bootstrap -> adminlte
     Popper tidak dimuat terpisah karena bootstrap.bundle sudah memuatnya. -->
<script src="<?= url('assets/vendor/overlayscrollbars/overlayscrollbars.browser.es6.min.js') ?>"></script>
<script src="<?= url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= url('assets/vendor/adminlte/js/adminlte.min.js') ?>"></script>
<script src="<?= url('assets/vendor/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= url('assets/vendor/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script src="<?= url('assets/vendor/sweetalert2/sweetalert2.min.js') ?>"></script>

<script>
/* ═════════════════════════════════════════════════════════════════════
   Sidebar collapse-state persistence
   AdminLTE 4 toggles `.sidebar-collapse` on <body>. We mirror that to a
   cookie so the next request can pre-apply it BEFORE first paint and
   avoid a flash. Mobile breakpoint is intentionally ignored.
   ═════════════════════════════════════════════════════════════════════ */
(function () {
    function setCookie(name, val, days) {
        try {
            var d = new Date();
            d.setTime(d.getTime() + days*24*60*60*1000);
            document.cookie = name + '=' + val + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
        } catch (e) {}
    }
    function isDesktop() { return window.innerWidth >= 992; }

    document.addEventListener('DOMContentLoaded', function () {
        // Sync localStorage → cookie on first load (for SSR class)
        try {
            var min = (localStorage.getItem('sidebarMinimized') === '1') ? '1' : '0';
            setCookie('__bltx_sb_min', min, 365);
        } catch (e) {}

        // Watch body class changes (AdminLTE toggles `.sidebar-collapse`)
        var obs = new MutationObserver(function () {
            if (!isDesktop()) return;
            var collapsed = document.body.classList.contains('sidebar-collapse');
            try {
                localStorage.setItem('sidebarMinimized', collapsed ? '1' : '0');
                setCookie('__bltx_sb_min', collapsed ? '1' : '0', 365);
            } catch (e) {}
        });
        obs.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    });
})();
</script>

<style>
/* ═══════ DataTables Bootstrap-5 layout polish ═══════ */
.dataTables_wrapper .row:first-child { align-items: center; margin-bottom: .85rem; }
.dataTables_length, .dataTables_filter { display: flex; align-items: center; }
.dataTables_length label, .dataTables_filter label {
    display: flex; align-items: center; gap: .5rem;
    margin-bottom: 0; font-weight: 500;
}
.dataTables_filter { justify-content: flex-end; }
.dataTables_length select, .dataTables_filter input {
    border: 1px solid #d0d5dd;
    border-radius: .4rem;
    padding: .35rem .65rem;
    font-size: .9rem;
}
.dataTables_length select:focus, .dataTables_filter input:focus {
    outline: 0;
    border-color: var(--arw-primary, #0d6efd);
    box-shadow: 0 0 0 .2rem rgba(13,110,253,.12);
}
.dataTables_info { color: #6c757d; font-size: .88rem; }
.dataTables_paginate { display: flex; justify-content: flex-end; }
.dataTables_paginate .pagination { margin-bottom: 0; }
.dataTables_paginate .page-link { font-size: .88rem; padding: .4rem .7rem; }

@media (max-width: 767px) {
    .dataTables_wrapper .row:first-child { flex-direction: column; gap: .6rem; }
    .dataTables_filter, .dataTables_length { justify-content: flex-start; width: 100%; }
    .dataTables_filter input { width: 100%; }
    .dataTables_paginate { justify-content: center; margin-top: .8rem; }
    .dataTables_paginate .pagination { flex-wrap: wrap; gap: .2rem; justify-content: center; }
}
@media (max-width: 575px) {
    .dataTables_paginate .page-link {
        padding: .35rem .55rem; font-size: .85rem; min-width: 38px; text-align: center;
    }
}
@media (hover: none) and (pointer: coarse) {
    .dataTables_length select, .dataTables_filter input,
    .dataTables_paginate .page-link { min-height: 42px; }
}

/* Empty-state / loading helpers (drop-in for pages that need them) */
.empty-state {
    text-align: center; padding: 3rem 1rem; color: #6c757d;
}
.empty-state i { font-size: 3rem; opacity: .35; margin-bottom: .75rem; display: block; }
.empty-state h5 { color: #475569; margin-bottom: .35rem; }
.empty-state p  { color: #94a3b8; margin: 0; font-size: .9rem; }

.spinner-overlay {
    position: absolute; inset: 0; background: rgba(255,255,255,.7);
    display: flex; align-items: center; justify-content: center;
    z-index: 10; border-radius: inherit;
}
</style>

<script>
$(document).ready(function () {

    /* ─── DataTables defaults ─── */
    if ($.fn.dataTable) {
        $.extend(true, $.fn.dataTable.defaults, {
            language: {
                search:    "<?php echo $LANG['search']; ?>:",
                lengthMenu:"<?php echo $LANG['show']; ?> _MENU_ <?php echo $LANG['entries']; ?>",
                info:      "<?php echo $LANG['showing']; ?> _START_ <?php echo $LANG['to']; ?> _END_ <?php echo $LANG['of']; ?> _TOTAL_ <?php echo $LANG['entries']; ?>",
                paginate: {
                    first:    "<?php echo $LANG['first']; ?>",
                    last:     "<?php echo $LANG['last']; ?>",
                    next:     "<?php echo $LANG['next']; ?>",
                    previous: "<?php echo $LANG['previous']; ?>"
                },
                emptyTable:  "<?php echo $LANG['no_data']; ?>",
                zeroRecords: "<?php echo $LANG['no_matching']; ?>"
            },
            pageLength: <?php echo (int)getSetting('pagination', 10); ?>,
            responsive: true,
            autoWidth: false
        });
    }

    /* ─── Lazy image loading ─── */
    $('img').each(function () {
        if (!$(this).attr('loading')) $(this).attr('loading', 'lazy');
    });

    /* ─── Smooth scroll for in-page anchors ─── */
    $('a[href^="#"]').on('click', function (e) {
        var href = this.getAttribute('href');
        if (!href || href === '#') return;
        var target = $(href);
        if (target.length) {
            e.preventDefault();
            $('html, body').stop().animate({ scrollTop: target.offset().top - 70 }, 400);
        }
    });
});

/* ═══════ Global helpers used across pages (UNCHANGED API) ═══════ */
function confirmDelete(url) {
    Swal.fire({
        title: '<?php echo $LANG['confirm']; ?>',
        text:  '<?php echo $LANG['confirm_delete']; ?>',
        icon:  'warning',
        showCancelButton:  true,
        confirmButtonColor:'#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?php echo $LANG['yes']; ?>',
        cancelButtonText:  '<?php echo $LANG['cancel']; ?>',
        reverseButtons: true
    }).then(function (result) {
        if (result.isConfirmed) window.location.href = url;
    });
}

function previewImage(input, previewId) {
    if (input.files && input.files[0]) {
        var file = input.files[0];
        var maxSize = <?php echo (int)MAX_FILE_SIZE; ?>;

        if (file.size > maxSize) {
            Swal.fire({ icon:'error', title:'Error', text:'<?php echo $LANG['file_too_large']; ?>' });
            input.value = '';
            return;
        }
        if (!file.type.match('image.*')) {
            Swal.fire({ icon:'error', title:'Error', text:'<?php echo $LANG['invalid_file_type']; ?>' });
            input.value = '';
            return;
        }
        var reader = new FileReader();
        reader.onload = function (e) {
            $('#' + previewId).html('<img src="' + e.target.result + '" class="img-fluid rounded shadow-sm" style="max-height:300px;object-fit:cover;">');
        };
        reader.readAsDataURL(file);
    }
}

function viewInvoice(imagePath) {
    Swal.fire({
        imageUrl: imagePath,
        imageAlt: 'Invoice',
        showCloseButton: true,
        showConfirmButton: false,
        width: 'auto',
        customClass: { image: 'img-fluid' },
        imageHeight: window.innerHeight * 0.8
    });
}

/* ═══════════════════════════════════════════════════════════════════
   NOTIFIKASI MELAYANG untuk hasil AKSI AJAX (tanpa reload halaman).
   Tampilannya SAMA PERSIS dengan notifikasi PHP-session-flash yang
   dipakai di halaman lain (lihat settings/purchase_settings.php,
   purchases/suppliers.php): position:fixed pojok kanan atas, alert
   Bootstrap + shadow, auto-hilang 4 detik, bisa ditutup manual.
   Dipakai untuk respons AJAX (mis. toggle status, trigger backup)
   yang tidak melibatkan reload/redirect halaman.
   Pesan di-set lewat .text() sehingga otomatis ter-escape — tanda
   baca seperti ' " & tampil apa adanya, tidak jadi &amp; dan sejenisnya.
   ═══════════════════════════════════════════════════════════════════ */
function showFloatingAlert(message, type) {
    type = (type === 'error') ? 'error' : 'success';
    var alertCls = (type === 'error') ? 'alert-danger'  : 'alert-success';
    var icon     = (type === 'error') ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
    var zIndex   = (type === 'error') ? 1091 : 1090;

    $('#dynamicFlashToast').remove();

    var $wrap = $('<div>', { id: 'dynamicFlashToast' }).css({
        position: 'fixed', top: '1rem', right: '1rem', zIndex: zIndex,
        minWidth: '280px', maxWidth: 'min(420px, 90vw)',
        transition: 'opacity .35s ease'
    });
    var $alert = $(
        '<div class="alert ' + alertCls + ' alert-dismissible shadow-lg d-flex align-items-center gap-2 mb-0">' +
            '<i class="bi ' + icon + '"></i>' +
            '<span class="dynamic-flash-msg"></span>' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
        '</div>'
    );
    $alert.find('.dynamic-flash-msg').text(message);
    $wrap.append($alert).appendTo('body');

    setTimeout(function () {
        $wrap.css('opacity', '0');
        setTimeout(function () { $wrap.remove(); }, 400);
    }, 4000);
}
</script>

<!-- DataTable auto-init for any `.datatable` or `.ui-datatable` table on the page

     TAHAP 0.5 — init ini tetap SATU-SATUNYA init DataTables di seluruh
     sistem. Yang ditambahkan hanya pembacaan atribut data-* yang dipasang
     oleh ui_table_open():

       data-server-side="1"   → mode server-side (T9, tabel bervolume besar)
       data-ajax-url="..."    → endpoint JSON
       data-columns='[...]'   → nama kolom yang dikirim ke server
       data-page-length="25"  → menimpa setelan `pagination` dari database
       data-order-col / -dir  → urutan awal

     Tabel yang TIDAK punya atribut ini berjalan client-side persis seperti
     sebelumnya — tidak ada satu pun perilaku lama yang berubah. -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!$.fn.DataTable) return;
    $('.datatable, .ui-datatable').each(function () {
        if ($.fn.DataTable.isDataTable(this)) return;

        var $t         = $(this);
        var serverSide = $t.data('server-side') == 1;
        var ajaxUrl    = $t.data('ajax-url') || '';
        var pageLength = parseInt($t.data('page-length'), 10);
        var orderCol   = parseInt($t.data('order-col'), 10);
        var orderDir   = $t.data('order-dir') === 'desc' ? 'desc' : 'asc';
        var columns    = $t.data('columns') || null;
        var noOrder    = $t.data('no-order') || null;

        if (isNaN(pageLength) || pageLength <= 0) {
            pageLength = <?php echo (int)getSetting('pagination', 10); ?>;
        }

        var opts = {
            pageLength: pageLength,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            paging: true, searching: true, ordering: true, info: true,
            autoWidth: false, responsive: true,
            language: {
                search:    "<?php echo $LANG['search']; ?>:",
                lengthMenu:"<?php echo $LANG['show']; ?> _MENU_ <?php echo $LANG['entries']; ?>",
                info:      "<?php echo $LANG['showing']; ?> _START_ <?php echo $LANG['to']; ?> _END_ <?php echo $LANG['of']; ?> _TOTAL_ <?php echo $LANG['entries']; ?>",
                paginate: {
                    first:"<?php echo $LANG['first']; ?>", last:"<?php echo $LANG['last']; ?>",
                    next:"<?php echo $LANG['next']; ?>", previous:"<?php echo $LANG['previous']; ?>"
                },
                emptyTable:"<?php echo $LANG['no_data']; ?>", zeroRecords:"<?php echo $LANG['no_matching']; ?>"
            },
            dom:
                "<'row mb-3'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                "<'row'<'col-sm-12'tr>>" +
                "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
        };

        if (!isNaN(orderCol) && orderCol >= 0) {
            opts.order = [[orderCol, orderDir]];
        }

        // Kolom yang ditandai 'orderable' => false di ui_table_open().
        if (noOrder && noOrder.length) {
            opts.columnDefs = [{ orderable: false, targets: noOrder }];
        }

        if (serverSide && ajaxUrl) {
            opts.serverSide = true;
            opts.processing = true;
            // deferRender: baris hanya dibuat saat benar-benar ditampilkan,
            // jadi tabel bervolume besar tidak lagi merender seluruh baris
            // ke HTML lalu dipaginasi di browser (inti dari temuan T9).
            opts.deferRender = true;
            opts.ajax = { url: ajaxUrl, type: 'POST' };
            if (columns) {
                opts.columns = $.map(columns, function (c) {
                    return { data: c, defaultContent: '' };
                });
            }
        }

        $t.DataTable(opts);
    });
});
</script>

<!-- ═══════ Theme toggle (dark / light) ═════════════════════════════════
     Toggle is a navbar item with id="bltxThemeToggle". Theme persisted
     in cookie `__bltx_theme` (1 year). Initial value is set server-side
     via <html data-bs-theme> so there is no flash of wrong theme on load. -->
<script>
(function () {
    'use strict';
    var COOKIE = '__bltx_theme';
    var btn  = document.getElementById('bltxThemeToggle');
    var icon = document.getElementById('bltxThemeIcon');
    var html = document.documentElement;

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        var secure = (location.protocol === 'https:') ? ';Secure' : '';
        document.cookie = name + '=' + value + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax' + secure;
    }

    function applyTheme(theme, withTransition) {
        if (withTransition) html.classList.add('bltx-theme-ready');
        html.setAttribute('data-bs-theme', theme);
        if (icon) {
            icon.classList.toggle('bi-sun-fill',        theme === 'dark');
            icon.classList.toggle('bi-moon-stars-fill', theme === 'light');
        }
        // Update meta theme-color for mobile address-bar tint
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#1e2a38' : '#0d6efd');
    }

    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var current = html.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
            var next    = current === 'dark' ? 'light' : 'dark';
            applyTheme(next, true);
            setCookie(COOKIE, next, 365);
            btn.setAttribute('data-current-theme', next);
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     PRATINJAU GAMBAR BERSAMA (lightbox)

     Satu implementasi untuk SELURUH sistem. Acuannya openPhotoModal() di
     arw_pos/manual_dreport.php — zoom roda, seret untuk menggeser, cubit di
     layar sentuh.

     Sebelumnya ada LIMA salinan terpisah di empat berkas
     (manual_dreport, purchase_view x2, purchases_invoice, inventory_data),
     masing-masing dengan markup dan ~100 baris JS sendiri. Perbedaan kecil
     di antaranya menghasilkan perilaku yang tidak seragam.

     CARA PAKAI dari halaman mana pun:
         bltxPhotoLightbox('https://.../gambar.jpg');

     Tombol tutup sengaja di LUAR .modal-dialog: kalau di dalam, ia ikut
     bergeser saat gambar di-zoom, karena transform pada <img> mempengaruhi
     seluruh isi dialog.
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="bltxLightboxModal" tabindex="-1" aria-hidden="true">
    <!-- width:fit-content membuat dialog MEMELUK gambar: tanpa itu
         .modal-dialog memakai lebar baku Bootstrap (500px) dan menyisakan
         ruang kosong di sisi gambar yang lebih sempit dari itu. -->
    <div class="modal-dialog modal-dialog-centered"
         style="max-width:90vw;width:fit-content;margin-left:auto;margin-right:auto;">
        <div class="modal-content bg-transparent border-0 shadow-none" style="width:auto;">
            <div class="modal-body p-0" style="line-height:0;">
                <img id="bltxLightboxImg" src="" alt="<?php echo htmlspecialchars($LANG['image'] ?? 'Image', ENT_QUOTES, 'UTF-8'); ?>"
                     style="max-width:88vw;max-height:82vh;object-fit:contain;border-radius:0.5rem;display:block;margin:0 auto;cursor:grab;user-select:none;touch-action:none;">
            </div>
        </div>
    </div>
</div>
<button type="button" id="bltxLightboxClose" class="btn btn-sm btn-light px-4"
        style="display:none;position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);z-index:1090;">
    <i class="bi bi-x-circle me-1"></i><?php echo htmlspecialchars($LANG['close'] ?? 'Close', ENT_QUOTES, 'UTF-8'); ?>
</button>

<script>
(function () {
    var modalEl, imgEl, closeEl;
    var scale = 1, transX = 0, transY = 0, dragStart = null;
    var pinchStartDist = 0, pinchStartScale = 1;

    function clampScale(s) { return Math.min(Math.max(s, 1), 5); }

    function applyTransform() {
        imgEl.style.transform = 'scale(' + scale + ') translate(' + transX + 'px,' + transY + 'px)';
    }

    function reset() {
        scale = 1; transX = 0; transY = 0;
        imgEl.style.transform = '';
        imgEl.style.cursor = 'grab';
    }

    /* Handler dipasang SEKALI saat halaman siap, bukan tiap kali modal
       dibuka. Versi lama memasangnya ulang di setiap pemanggilan, sehingga
       listener window menumpuk dan tidak pernah dilepas. */
    document.addEventListener('DOMContentLoaded', function () {
        modalEl = document.getElementById('bltxLightboxModal');
        imgEl   = document.getElementById('bltxLightboxImg');
        closeEl = document.getElementById('bltxLightboxClose');
        if (!modalEl || !imgEl || !closeEl) { return; }

        modalEl.addEventListener('shown.bs.modal',  function () { closeEl.style.display = 'block'; });
        modalEl.addEventListener('hidden.bs.modal', function () { closeEl.style.display = 'none'; reset(); });

        closeEl.addEventListener('click', function () {
            var inst = bootstrap.Modal.getInstance(modalEl);
            if (inst) { inst.hide(); }
        });

        imgEl.addEventListener('wheel', function (e) {
            e.preventDefault();
            scale = clampScale(scale + (e.deltaY < 0 ? 0.15 : -0.15));
            if (scale === 1) { transX = 0; transY = 0; }
            applyTransform();
        }, { passive: false });

        imgEl.addEventListener('mousedown', function (e) {
            if (scale <= 1) { return; }
            e.preventDefault();
            dragStart = { x: e.clientX - transX, y: e.clientY - transY };
            imgEl.style.cursor = 'grabbing';
        });
        window.addEventListener('mousemove', function (e) {
            if (!dragStart) { return; }
            transX = e.clientX - dragStart.x;
            transY = e.clientY - dragStart.y;
            applyTransform();
        });
        window.addEventListener('mouseup', function () {
            if (dragStart) { dragStart = null; imgEl.style.cursor = 'grab'; }
        });

        imgEl.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                e.preventDefault();
                var dx = e.touches[0].clientX - e.touches[1].clientX;
                var dy = e.touches[0].clientY - e.touches[1].clientY;
                pinchStartDist  = Math.sqrt(dx * dx + dy * dy);
                pinchStartScale = scale;
            }
        }, { passive: false });
        imgEl.addEventListener('touchmove', function (e) {
            if (e.touches.length === 2) {
                e.preventDefault();
                var dx = e.touches[0].clientX - e.touches[1].clientX;
                var dy = e.touches[0].clientY - e.touches[1].clientY;
                scale = clampScale(pinchStartScale * (Math.sqrt(dx * dx + dy * dy) / pinchStartDist));
                if (scale === 1) { transX = 0; transY = 0; }
                applyTransform();
            }
        }, { passive: false });
    });

    window.bltxPhotoLightbox = function (url) {
        if (!modalEl || !imgEl) { return; }
        reset();
        imgEl.src = url;
        new bootstrap.Modal(modalEl).show();
    };
})();
</script>

<?php if (isset($extra_js)) echo $extra_js; ?>

</body>
</html>
<?php ob_end_flush(); ?>
