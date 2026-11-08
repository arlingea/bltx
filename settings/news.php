<?php
/* =============================================================================
   settings/news.php  —  FRAGMEN TAB dari settings/settings_gate.php

   RINGKASAN PERUBAHAN TAHAP 2
     1. Guard direct-access memakai stanza baku Tahap 0.3. Sebelumnya berkas
        ini satu-satunya di sistem yang memakai defined('DB_HOST').
     2. Token CSRF pada seluruh handler — sebelumnya nol.
     3. PENGHAPUSAN TIDAK LAGI LEWAT GET. Dulu ?delete_news=ID menghapus baris
        hanya dengan membuka URL, sehingga satu <img src="...&delete_news=5">
        di halaman mana pun cukup untuk menghapus berita. Sekarang POST +
        token.
     4. Sakelar status tidak lagi memakai $.ajax + endpoint JSON. Ia mengirim
        POST biasa lalu halaman dimuat ulang — sejalan dengan B2 poin 4, dan
        menghapus satu-satunya endpoint JSON di berkas ini.
     5. Notifikasi lewat toast bltx_render_flash(); pesan GAGAL kini juga
        tampil (dulu hanya sukses yang punya notifikasi).
     6. Tabel memakai helper ui_table_*.
     7. PEMUATAN ULANG ASET DIHAPUS. Berkas ini memuat lagi jQuery,
        DataTables, dan SweetAlert2 (CSS+JS) lewat tag script src, padahal
        includes/footer.php sudah memuatnya. jQuery yang dimuat dua kali
        menghapus plugin yang sudah terdaftar pada instans pertama.
     8. Init DataTables kedua dihapus — footer.php sudah punya satu-satunya.

   TIDAK diubah: pemanggilan sanitize() dibiarkan apa adanya. Itu wilayah
   Tahap 1, yang atas kesepakatan dipindah ke urutan terakhir.
   ========================================================================== */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment();

$__news_self = url('settings/settings_gate.php?tab=news');

$__news_can_create = isSuperAdmin() || checkPermission('settings_news', 'can_create');
$__news_can_update = isSuperAdmin() || checkPermission('settings_news', 'can_update');
$__news_can_delete = isSuperAdmin() || checkPermission('settings_news', 'can_delete');

/* ===============================
   SIMPAN / UBAH / HAPUS / SAKELAR STATUS
   Semuanya POST -> proses -> redirect.
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    bltx_csrf_require($__news_self);

    $action = (string)$_POST['action'];

    // TAMBAH
    if ($action === 'add') {
        if (!$__news_can_create) {
            bltx_flash_error($LANG['no_permission']);
            redirect($__news_self);
        }
        $title     = sanitize($_POST['title']);
        $content   = sanitize($_POST['content']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (trim($title) === '' || trim($content) === '') {
            bltx_flash_error($LANG['fill_required_fields'] ?? $LANG['error_occurred']);
            redirect($__news_self);
        }

        $stmt = $conn->prepare("INSERT INTO news (title, content, is_active, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("ssi", $title, $content, $is_active);

        if ($stmt->execute()) {
            logActivity('ADD_NEWS', "Added news: $title");
            bltx_flash_success($LANG['save_success']);
        } else {
            error_log('[bltx] add news failed: ' . $stmt->error);
            bltx_flash_error($LANG['error_occurred']);
        }
        $stmt->close();
        redirect($__news_self);
    }

    // UBAH
    if ($action === 'edit') {
        if (!$__news_can_update) {
            bltx_flash_error($LANG['no_permission']);
            redirect($__news_self);
        }
        $id        = (int)($_POST['id'] ?? 0);
        $title     = sanitize($_POST['title']);
        $content   = sanitize($_POST['content']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || trim($title) === '' || trim($content) === '') {
            bltx_flash_error($LANG['fill_required_fields'] ?? $LANG['error_occurred']);
            redirect($__news_self);
        }

        $stmt = $conn->prepare("UPDATE news SET title=?, content=?, is_active=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("ssii", $title, $content, $is_active, $id);

        if ($stmt->execute()) {
            logActivity('UPDATE_NEWS', "Updated news ID: $id");
            bltx_flash_success($LANG['update_success']);
        } else {
            error_log('[bltx] update news failed: ' . $stmt->error);
            bltx_flash_error($LANG['error_occurred']);
        }
        $stmt->close();
        redirect($__news_self);
    }

    // SAKELAR STATUS — dulu $.ajax ke endpoint JSON
    if ($action === 'toggle') {
        if (!$__news_can_update) {
            bltx_flash_error($LANG['no_permission']);
            redirect($__news_self);
        }
        $id        = (int)($_POST['id'] ?? 0);
        $is_active = ((int)($_POST['is_active'] ?? 0) === 1) ? 1 : 0;

        if ($id <= 0) {
            bltx_flash_error($LANG['error_occurred']);
            redirect($__news_self);
        }

        $stmt = $conn->prepare("UPDATE news SET is_active = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $is_active, $id);
            if ($stmt->execute()) {
                logActivity($_SESSION['user_id'], 'toggle_news_status',
                    "Changed news ID $id status to " . ($is_active ? 'active' : 'inactive'));
                bltx_flash_success($LANG['update_success']);
            } else {
                error_log('[bltx] toggle news failed: ' . $stmt->error);
                bltx_flash_error($LANG['error_occurred']);
            }
            $stmt->close();
        }
        redirect($__news_self);
    }

    // HAPUS — dulu lewat GET ?delete_news=ID
    if ($action === 'delete') {
        if (!$__news_can_delete) {
            bltx_flash_error($LANG['no_permission']);
            redirect($__news_self);
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            bltx_flash_error($LANG['error_occurred']);
            redirect($__news_self);
        }

        $stmt = $conn->prepare("DELETE FROM news WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            logActivity('DELETE_NEWS', "Deleted news ID: $id");
            bltx_flash_success($LANG['delete_success']);
        } else {
            error_log('[bltx] delete news failed: ' . $stmt->error);
            bltx_flash_error($LANG['error_occurred']);
        }
        $stmt->close();
        redirect($__news_self);
    }
}

/* Jalur GET lama sengaja TIDAK dipertahankan. Bila ada tautan lama yang
   masih memanggilnya, ia kini tidak melakukan apa-apa selain memuat tab. */

$result = $conn->query("SELECT * FROM news ORDER BY created_at DESC");
?>

<?php bltx_render_flash('news'); ?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-newspaper"></i> <?php echo bltx_e($LANG['news']); ?>
                </div>
                <?php if ($__news_can_create): ?>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> <?php echo bltx_e($LANG['add']); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php
                ui_table_open('newsTable', [
                    'small'   => true,
                    'striped' => false,
                    'order'   => [0, 'asc'],
                    'columns' => [
                        ['label' => $LANG['no']],
                        ['label' => $LANG['title']],
                        ['label' => $LANG['content']],
                        ['label' => $LANG['status'],     'align' => 'center'],
                        ['label' => $LANG['created_at']],
                        ['label' => $LANG['actions'],    'align' => 'center', 'width' => 120],
                    ],
                ]);

                $no = 1;
                if ($result && $result->num_rows > 0):
                    while ($row = $result->fetch_assoc()):
                ?>
                <tr>
                    <td><?php echo $no++; ?></td>
                    <td><strong><?php echo bltx_e($row['title']); ?></strong></td>
                    <td class="text-muted small"><?php echo bltx_e_multiline($row['content']); ?></td>
                    <td class="text-center">
                        <div class="form-check form-switch d-inline-block">
                            <input type="checkbox"
                                   class="form-check-input status-toggle"
                                   data-id="<?php echo (int)$row['id']; ?>"
                                   <?php echo $__news_can_update ? '' : 'disabled'; ?>
                                   <?php echo $row['is_active'] ? 'checked' : ''; ?>>
                        </div>
                    </td>
                    <td><small class="text-muted"><?php echo bltx_e($row['created_at']); ?></small></td>
                    <td class="text-center">
                        <?php if ($__news_can_update || $__news_can_delete): ?>
                        <div class="btn btn-sm-group btn-group-sm" role="group">
                            <?php if ($__news_can_update): ?>
                            <button type="button" class="btn btn-warning btn-sm edit-news"
                                    data-id="<?php echo (int)$row['id']; ?>"
                                    data-title="<?php echo bltx_e($row['title']); ?>"
                                    data-content="<?php echo bltx_e($row['content']); ?>"
                                    data-active="<?php echo (int)$row['is_active']; ?>"
                                    title="<?php echo bltx_e($LANG['edit']); ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($__news_can_delete): ?>
                            <button type="button" class="btn btn-danger btn-sm delete-news"
                                    data-id="<?php echo (int)$row['id']; ?>"
                                    data-title="<?php echo bltx_e($row['title']); ?>"
                                    title="<?php echo bltx_e($LANG['delete']); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    endwhile;
                else:
                    ui_table_empty(6, $LANG['no_data']);
                endif;
                ui_table_close();
                ?>
            </div>
        </div>
    </div>
</div>

<!-- Formulir tersembunyi: hapus & sakelar status, keduanya POST + token. -->
<form id="newsActionForm" method="POST" action="<?php echo bltx_e($__news_self); ?>" class="d-none">
    <?php bltx_csrf_field(); ?>
    <input type="hidden" name="action"    id="news_action">
    <input type="hidden" name="id"        id="news_action_id">
    <input type="hidden" name="is_active" id="news_action_active">
</form>

<!-- MODAL TAMBAH -->
<div class="modal fade" id="addModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <form method="POST" action="<?php echo bltx_e($__news_self); ?>">
                <?php bltx_csrf_field(); ?>
                <input type="hidden" name="action" value="add">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>
                        <?php echo bltx_e($LANG['add']); ?> <?php echo bltx_e($LANG['news']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?php echo bltx_e($LANG['title']); ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" name="title" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?php echo bltx_e($LANG['content']); ?> <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" name="content" rows="5" required></textarea>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="add_is_active" checked>
                            <label class="form-check-label" for="add_is_active"><?php echo bltx_e($LANG['active']); ?></label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i><?php echo bltx_e($LANG['cancel']); ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save me-1"></i><?php echo bltx_e($LANG['save']); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL UBAH -->
<div class="modal fade" id="editModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <form method="POST" action="<?php echo bltx_e($__news_self); ?>">
                <?php bltx_csrf_field(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-pencil-square me-2 text-warning"></i>
                        <?php echo bltx_e($LANG['edit']); ?> <?php echo bltx_e($LANG['news']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?php echo bltx_e($LANG['title']); ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" name="title" id="edit_title" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?php echo bltx_e($LANG['content']); ?> <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" name="content" id="edit_content" rows="5" required></textarea>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                            <label class="form-check-label" for="edit_is_active"><?php echo bltx_e($LANG['active']); ?></label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i><?php echo bltx_e($LANG['cancel']); ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save me-1"></i><?php echo bltx_e($LANG['update']); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* Tidak ada tag script src di berkas ini. jQuery, DataTables, dan SweetAlert2
   sudah dimuat includes/footer.php; memuatnya lagi menghapus plugin yang
   terdaftar pada instans pertama. */
document.addEventListener('DOMContentLoaded', function () {

    var actionForm = document.getElementById('newsActionForm');

    function submitNewsAction(action, id, isActive) {
        document.getElementById('news_action').value        = action;
        document.getElementById('news_action_id').value     = id;
        document.getElementById('news_action_active').value = (isActive === undefined ? '' : isActive);
        actionForm.submit();
    }

    // SAKELAR STATUS — POST biasa, bukan AJAX.
    document.addEventListener('change', function (e) {
        var toggle = e.target.closest('.status-toggle');
        if (!toggle) return;
        submitNewsAction('toggle', toggle.getAttribute('data-id'), toggle.checked ? '1' : '0');
    });

    document.addEventListener('click', function (e) {

        // TOMBOL UBAH — isi modal dari atribut data-*, bukan dari JSON satu baris
        // penuh. Sebelumnya seluruh isi baris database ikut tertulis ke HTML.
        var editBtn = e.target.closest('.edit-news');
        if (editBtn) {
            e.preventDefault();
            document.getElementById('edit_id').value          = editBtn.getAttribute('data-id');
            document.getElementById('edit_title').value       = editBtn.getAttribute('data-title');
            document.getElementById('edit_content').value     = editBtn.getAttribute('data-content');
            document.getElementById('edit_is_active').checked = (editBtn.getAttribute('data-active') === '1');

            var el  = document.getElementById('editModal');
            var obj = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
            obj.show();
            return;
        }

        // TOMBOL HAPUS — SweetAlert2 sebagai dialog konfirmasi, hasilnya toast.
        var delBtn = e.target.closest('.delete-news');
        if (delBtn) {
            e.preventDefault();
            var id    = delBtn.getAttribute('data-id');
            var title = delBtn.getAttribute('data-title') || '';

            Swal.fire({
                title:              <?php echo bltx_js($LANG['are_you_sure']); ?>,
                text:               <?php echo bltx_js($LANG['confirm_delete']); ?> + (title ? ' (' + title + ')' : ''),
                icon:               'warning',
                showCancelButton:   true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor:  '#6c757d',
                confirmButtonText:  <?php echo bltx_js($LANG['delete']); ?>,
                cancelButtonText:   <?php echo bltx_js($LANG['cancel']); ?>,
                reverseButtons:     true
            }).then(function (result) {
                if (result.isConfirmed) {
                    submitNewsAction('delete', id);
                }
            });
            return;
        }
    });
});
</script>
