<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CATEGORIES — dual-mode (pola sama persis dengan goods_receipt.php)
//   • Standalone : /purchases/categories.php                    (halaman penuh)
//   • Partial    : di-include oleh pr_dahsboard_gate.php    (tab=categories)
//
//  Saat menjadi partial, header dan footer TIDAK di-include lagi karena hub
//  sudah mencetaknya; kalau tidak, halaman akan tercetak dua kali.
// ═══════════════════════════════════════════════════════════════════════════
$__categories_included_as_partial = defined('APP_NAME') && isset($LANG, $conn);

if (!$__categories_included_as_partial) {
    require_once '../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';

    // Tidak boleh diakses langsung tanpa login.
    if (!isLoggedIn()) redirect(url('index.php'));

    // GERBANG Purchase juga berlaku pada akses langsung, bukan cuma lewat hub.
    if (!canAccessPurchaseGate()) redirectNoAccess();

    $page_title = $LANG['categories'];
    include '../includes/header.php';
}

if (!checkPermission('categories', 'can_access')) {
    if ($__categories_included_as_partial) {
        echo '<div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> '
           . htmlspecialchars($LANG['no_permission']) . '</div>';
        return;
    }
    redirectNoAccess();
}

// Tujuan redirect setelah CRUD: saat partial kembali ke tab-nya di hub,
// saat standalone kembali ke halaman ini sendiri.
$categories_self_url = $__categories_included_as_partial
    ? url('purchases/pr_dahsboard_gate.php?tab=categories')
    : url('purchases/categories.php');

// Penanganan proses form (CRUD)
// ── CSRF ──────────────────────────────────────────────────────────────────
// Memakai pf_csrfToken() / pf_csrfValid() yang SUDAH ADA di
// includes/purchases_function.php — tidak membuat mekanisme token baru,
// sehingga tidak ada dua sistem CSRF yang berjalan berdampingan.
require_once __DIR__ . '/../includes/purchases_function.php';
$csrf_token = pf_csrfToken();

// Semua aksi POST di halaman ini wajib menyertakan token yang sah.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !pf_csrfValid($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = $LANG['invalid_csrf'];
    redirect($categories_self_url);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // --- PROSES TAMBAH (ADD) ---
        if ($_POST['action'] === 'add' && checkPermission('categories', 'can_create')) {
            $name = sanitize($_POST['name']);
            $desc = sanitize($_POST['description']);
            
            $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $desc);
            
            if ($stmt->execute()) {
                logActivity('add_category', "Added category: $name");
                $_SESSION['success'] = $LANG['save_success'];
            } else {
                $_SESSION['error'] = $LANG['save_error'] ?? 'Terjadi kesalahan pada database.';
            }
            redirect($categories_self_url);
        } 
        
        // --- PROSES UBAH (EDIT) ---
        elseif ($_POST['action'] === 'edit' && checkPermission('categories', 'can_update')) {
            $id = intval($_POST['id']);
            $name = sanitize($_POST['name']);
            $desc = sanitize($_POST['description']);
            
            $stmt = $conn->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
            $stmt->bind_param("ssi", $name, $desc, $id);
            
            if ($stmt->execute()) {
                logActivity('update_category', "Updated category ID: $id");
                $_SESSION['success'] = $LANG['update_success'];
            } else {
                $_SESSION['error'] = $LANG['update_error'] ?? 'Terjadi kesalahan saat memperbarui data.';
            }
            redirect($categories_self_url);
        }
        
        // --- PROSES HAPUS (DELETE) ---
        elseif ($_POST['action'] === 'delete' && checkPermission('categories', 'can_delete')) {
            $id = intval($_POST['id']);
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                logActivity('delete_category', "Deleted category ID: $id");
                $_SESSION['success'] = $LANG['delete_success'];
            } else {
                $_SESSION['error'] = $LANG['delete_error'] ?? 'Tidak dapat menghapus kategori ini.';
            }
            redirect($categories_self_url);
        }
    }
}

// Mengambil data kategori
$result = $conn->query("SELECT * FROM categories ORDER BY name");
?>

<!-- Container pembungkus utama seperti di activity.php -->
<div class="row align-items-start">

    <!-- Notifikasi Berhasil -->
    <?php
    /* ══════════════════════════════════════════════════════════════════════
       NOTIFIKASI MELAYANG (pola sama persis dengan purchases_invoice.php)
       position:fixed sehingga melayang di pojok kanan atas viewport dan
       TIDAK mendorong konten halaman ke bawah — inilah bedanya dengan blok
       alert lama yang menyisipkan baris baru di atas kartu.
       Murni HTML + CSS inline: kalau JavaScript mati sekalipun, notifikasi
       tetap terlihat dan tetap bisa ditutup manual lewat tombol silang.
       Skrip di bawahnya hanya untuk menghilangkan otomatis setelah 4 detik.
       Isi pesan di-escape dengan htmlspecialchars sehingga tanda baca
       seperti ' " & tampil apa adanya.
       ══════════════════════════════════════════════════════════════════ */
    ?>
    <?php
    /* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
       dua blok alert dan satu blok <script> yang isinya identik dengan
       berkas lain. Kunci sesi tidak diubah, hanya cara merendernya. */
    bltx_render_flash('categories');
    ?>

    <div class="col-12 mb-4">
        <div class="card">
            <!-- Menyesuaikan d-flex flexbox dan header styling dari activity.php -->
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-tags-fill me-1"></i> <?php echo $LANG['category_list']; ?>
                </div>
                
                <?php if (checkPermission('categories', 'can_create')): ?>
                <div class="card-tools">
                    <?php
                /* Tombol ekspor memakai modul permission YANG SUDAH ADA
                   ('categories' can_export) — tidak ada modul izin baru.
                   Endpoint export_purchases.php sudah diperluas untuk
                   menangani dataset ini. Gaya tombol mengikuti returns.php:
                   Excel = btn-success, PDF = btn-danger. */
                ?>
                <?php if (checkPermission('categories', 'can_export')): ?>
                <a href="<?= url('includes/export/export_purchases.php?type=excel&data=categories') ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel"></i> <?= htmlspecialchars($LANG['export_excel']) ?>
                </a>
                <?php endif; ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> <?php echo $LANG['add']; ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="card-body">
                <?php
                // Menyamakan konfigurasi UI tabel dengan activity.php (striped, hover, small, datatable)
                ui_table_open('dataTable', [
                    'columns' => [
                        ['label' => $LANG['category_name']],
                        ['label' => $LANG['description']],
                        ['label' => $LANG['actions'], 'width' => 130, 'align' => 'center'],
                    ],
                    'striped'   => true,
                    'hover'     => true,
                    'small'     => true,
                    'datatable' => true,
                ]);

                if ($result && $result->num_rows > 0):
                    while ($row = $result->fetch_assoc()):
                ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['description'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <!-- Tombol Edit -->
                                        <?php if (checkPermission('categories', 'can_update')): ?>
                                            <button class="btn btn-sm btn-warning"
                                                onclick='editCategory(<?php echo json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="<?= htmlspecialchars($LANG['edit']) ?>">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Tombol Hapus -->
                                        <?php if (checkPermission('categories', 'can_delete')): ?>
                                            <form method="POST" class="d-inline pur-confirm-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="<?= htmlspecialchars($LANG['delete']) ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                <?php
                    endwhile;
                else:
                    ui_table_empty(3, $LANG['no_data']);
                endif;

                ui_table_close();
                ?>
            </div>
        </div>
    </div>

</div>

<!-- MODAL TAMBAH (ADD) -->
<div class="modal fade" id="addModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="add">

                <div class="modal-header py-2">
                    <h5 class="modal-title mb-0 d-flex align-items-center">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>
                        <?php echo $LANG['add'].' '.$LANG['category']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['category_name']; ?> *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['description']; ?></label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php echo $LANG['cancel']; ?></button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i> <?php echo $LANG['save']; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL UBAH (EDIT) -->
<div class="modal fade" id="editModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header py-2">
                    <h5 class="modal-title mb-0 d-flex align-items-center">
                        <i class="bi bi-pencil-square me-2 text-warning"></i>
                        <?php echo $LANG['edit'].' '.$LANG['category']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['category_name']; ?> *</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['description']; ?></label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php echo $LANG['cancel']; ?></button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i> <?php echo $LANG['update']; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_description').value = data.description || '';
    
    var editModal = new bootstrap.Modal(document.getElementById('editModal'));
    editModal.show();
}
</script>

<script>
/* Konfirmasi hapus memakai SweetAlert2, menggantikan confirm() bawaan
   browser. Alasannya bukan sekadar tampilan: confirm() bawaan tampil
   berbeda di tiap peramban dan tidak mengikuti tema gelap/terang, sehingga
   menjadi satu-satunya dialog di seluruh modul Purchase yang terlihat
   asing. Pola ini sama persis dengan .gr-confirm-form pada
   goods_receipt_view.php.
   Form tetap POST — metode pengirimannya tidak diubah sama sekali. */
document.querySelectorAll('.pur-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        Swal.fire({
            title: '<?= htmlspecialchars($LANG['confirm'], ENT_QUOTES) ?>',
            text:  '<?= htmlspecialchars($LANG['confirm_delete'], ENT_QUOTES) ?>',
            icon:  'warning',
            showCancelButton:   true,
            confirmButtonColor: '#d33',
            cancelButtonColor:  '#3085d6',
            confirmButtonText:  '<?= htmlspecialchars($LANG['yes'], ENT_QUOTES) ?>',
            cancelButtonText:   '<?= htmlspecialchars($LANG['cancel'], ENT_QUOTES) ?>',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});
</script>

<?php if (!$__categories_included_as_partial) include '../includes/footer.php'; ?>