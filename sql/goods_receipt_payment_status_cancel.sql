-- ═══════════════════════════════════════════════════════════════════════════
--  MIGRASI: goods_receipts.payment_status  →  menambah nilai 'cancel'
--
--  MENGAPA DIBUAT
--  Dropdown "All Payment Status" di purchases/goods_receipt.php mendapat
--  pilihan baru: Cancel. Sebelumnya kolom payment_status hanya berisi
--  'paid' / 'unpaid', sehingga GR yang dibatalkan tetap membawa nilai lama
--  ('unpaid' bila pembatalan terjadi sebelum pembayaran dicatat). Akibatnya
--  filter Unpaid menampilkan campuran unpaid + dokumen cancel.
--  Dengan nilai 'cancel' yang ditulis oleh gr_cancel(), setiap pilihan pada
--  dropdown menjadi saling eksklusif:
--      Paid   → hanya payment_status = 'paid'   (dokumen hidup)
--      Unpaid → hanya payment_status = 'unpaid' (dokumen hidup)
--      Cancel → hanya payment_status = 'cancel' (GR dibatalkan)
--
--  Cara menjalankan (dari folder proyek):
--      mysql -u <user> -p <nama_db> < sql/goods_receipt_payment_status_cancel.sql
--
--  Aman dijalankan berulang (idempotent): MODIFY enum di bawah bersifat
--  netral, dan UPDATE hanya menyentuh baris yang belum bernilai 'cancel'.
--  Perhatikan: gr_hasPaymentStatusColumn() membaca tipe kolom, jadi selama
--  file ini belum dijalankan, kolom deteksinya dianggap belum mendukung
--  'cancel' — halaman tetap berfungsi penuh seperti sebelumnya.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE `goods_receipts`
  MODIFY COLUMN `payment_status`
    ENUM('paid','unpaid','cancel') NOT NULL DEFAULT 'paid'
    COMMENT 'paid = bayar langsung saat terima, unpaid = pembayaran tertunda, cancel = pembayarannya dibatalkan (GR cancelled)';

-- Backfill: seluruh dokumen yang sudah dibatalkan ikut ditandai 'cancel'.
UPDATE `goods_receipts`
   SET `payment_status` = 'cancel'
 WHERE `status` = 'cancelled'
   AND `payment_status` <> 'cancel';

-- Index idx_gr_payment_status / idx_gr_status_payment_date tetap terpakai:
-- MODIFY enum di atas tidak menghapus index yang sudah ada.
