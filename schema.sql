-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 08, 2026 at 01:31 PM
-- Server version: 11.4.13-MariaDB
-- PHP Version: 8.4.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bltxmyid_bltx_bsb`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT '' COMMENT 'Salinan users.full_name saat log dibuat. Sengaja disimpan di tabel ini agar riwayat tetap terbaca setelah akun dihapus.',
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appraisal_cycles`
--

CREATE TABLE `appraisal_cycles` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `kpi_period_id` int(11) DEFAULT NULL,
  `status` enum('draft','self_review','manager_review','calibration','closed') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `approval_flows`
--

CREATE TABLE `approval_flows` (
  `id` int(11) NOT NULL,
  `flow_key` varchar(50) NOT NULL COMMENT 'leave_request | cash_advance | payroll | overtime',
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot nama pembuat (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Alur persetujuan bertingkat';

-- --------------------------------------------------------

--
-- Table structure for table `approval_logs`
--

CREATE TABLE `approval_logs` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `step_order` int(3) NOT NULL DEFAULT 1,
  `action` enum('approved','rejected','cancelled','submitted') NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL COMMENT 'Tanpa FK — disengaja, agar jejak tidak hilang',
  `actor_user_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot nama pelaku (permanen)',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Jejak persetujuan, permanen';

-- --------------------------------------------------------

--
-- Table structure for table `approval_requests`
--

CREATE TABLE `approval_requests` (
  `id` int(11) NOT NULL,
  `flow_key` varchar(50) NOT NULL,
  `ref_table` varchar(64) NOT NULL COMMENT 'leave_requests | cash_advances',
  `ref_id` int(11) NOT NULL COMMENT 'id baris dokumen yang disetujui',
  `employee_id` int(11) DEFAULT NULL COMMENT 'Pemohon',
  `employee_name` varchar(150) DEFAULT NULL COMMENT 'Snapshot nama pemohon (permanen)',
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `current_step` int(3) NOT NULL DEFAULT 1,
  `total_steps` int(3) NOT NULL DEFAULT 1,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dokumen yang sedang menunggu persetujuan';

-- --------------------------------------------------------

--
-- Table structure for table `approval_steps`
--

CREATE TABLE `approval_steps` (
  `id` int(11) NOT NULL,
  `flow_id` int(11) NOT NULL,
  `step_order` int(3) NOT NULL DEFAULT 1,
  `step_name` varchar(150) DEFAULT NULL,
  `approver_type` enum('manager','position','user') NOT NULL DEFAULT 'manager',
  `position_id` int(11) DEFAULT NULL COMMENT 'Diisi bila approver_type=position',
  `user_id` int(11) DEFAULT NULL COMMENT 'Diisi bila approver_type=user. Tanpa FK — disengaja.',
  `user_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot nama penyetuju (permanen)',
  `min_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Langkah berlaku bila nominal >= nilai ini',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Langkah penyetuju dalam satu alur';

-- --------------------------------------------------------

--
-- Table structure for table `asset_categories`
--

CREATE TABLE `asset_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `check_in_photo` varchar(255) DEFAULT NULL COMMENT 'Foto saat check-in',
  `check_out_photo` varchar(255) DEFAULT NULL COMMENT 'Foto saat check-out',
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_in_distance_m` int(8) DEFAULT NULL COMMENT 'Jarak meter dari titik kantor saat check-in. NULL = tidak terukur.',
  `check_out_lat` decimal(10,7) DEFAULT NULL,
  `check_out_lng` decimal(10,7) DEFAULT NULL,
  `check_out_distance_m` int(8) DEFAULT NULL COMMENT 'Jarak meter dari titik kantor saat check-out. NULL = tidak terukur.',
  `status` enum('present','permission','sick','absent','holiday','leave') NOT NULL DEFAULT 'present' COMMENT 'hadir,izin,sakit,alpha,libur,cuti',
  `late_minutes` int(5) NOT NULL DEFAULT 0 COMMENT 'Menit keterlambatan',
  `overtime_hours` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Jam lembur',
  `notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA penyetuju absensi (permanen)',
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pencatat absensi (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Absensi Harian Karyawan';

-- --------------------------------------------------------

--
-- Table structure for table `attendance_imports`
--

CREATE TABLE `attendance_imports` (
  `id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `machine_type` varchar(60) DEFAULT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `imported_rows` int(11) NOT NULL DEFAULT 0,
  `failed_rows` int(11) NOT NULL DEFAULT 0,
  `status` enum('uploaded','parsed','applied','failed') NOT NULL DEFAULT 'uploaded',
  `imported_by` int(11) DEFAULT NULL,
  `imported_by_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_import_rows`
--

CREATE TABLE `attendance_import_rows` (
  `id` int(11) NOT NULL,
  `import_id` int(11) NOT NULL,
  `row_number` int(11) NOT NULL DEFAULT 0,
  `raw_employee_code` varchar(60) DEFAULT NULL,
  `raw_date` varchar(40) DEFAULT NULL,
  `raw_check_in` varchar(40) DEFAULT NULL,
  `raw_check_out` varchar(40) DEFAULT NULL,
  `matched_employee_id` int(11) DEFAULT NULL,
  `result` varchar(40) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_advances`
--

CREATE TABLE `cash_advances` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `repayment_months` int(3) NOT NULL DEFAULT 1 COMMENT 'Cicilan berapa bulan',
  `monthly_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','paid_off') NOT NULL DEFAULT 'pending',
  `approval_request_id` int(11) DEFAULT NULL COMMENT 'approval_requests.id bila memakai alur bertingkat',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA penyetuju kasbon (permanen)',
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Kasbon/Cash Advance';

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `pic_employee_id` int(11) DEFAULT NULL COMMENT 'Penanggung jawab departemen (employees.id). Tanpa FK — disengaja.',
  `pic_name` varchar(150) DEFAULT NULL COMMENT 'Snapshot NAMA penanggung jawab saat ditetapkan (permanen)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Master Department';

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'Akun login karyawan untuk portal self-service (Modul 10). Tanpa FK — disengaja.',
  `employee_code` varchar(30) NOT NULL COMMENT 'Kode/NIK karyawan internal',
  `nik` varchar(20) DEFAULT NULL COMMENT 'Nomor Induk Kependudukan',
  `npwp` varchar(25) DEFAULT NULL COMMENT 'NPWP',
  `ptkp_code` varchar(10) DEFAULT 'TK/0' COMMENT 'Status PTKP untuk PPh21 (Modul 4)',
  `bpjs_kesehatan` varchar(30) DEFAULT NULL COMMENT 'No BPJS Kesehatan',
  `bpjs_ketenagakerjaan` varchar(30) DEFAULT NULL COMMENT 'No BPJS Ketenagakerjaan',
  `full_name` varchar(150) NOT NULL,
  `nick_name` varchar(80) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_place` varchar(100) DEFAULT NULL,
  `gender` enum('male','female') NOT NULL DEFAULT 'male',
  `marital_status` enum('single','married','divorced','widowed') NOT NULL DEFAULT 'single',
  `blood_type` enum('A','B','AB','O','A+','A-','B+','B-','AB+','AB-','O+','O-') DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `religion_id` int(11) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(25) DEFAULT NULL,
  `emergency_phone` varchar(25) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `bank_account_name` varchar(150) DEFAULT NULL COMMENT 'Harus sama dengan full_name',
  `medical_history` text DEFAULT NULL COMMENT 'Riwayat penyakit',
  `reference` text DEFAULT NULL COMMENT 'Referensi',
  `work_experience` text DEFAULT NULL COMMENT 'Pengalaman kerja',
  `department` varchar(100) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `position_id` int(11) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL COMMENT 'Atasan langsung (employees.id). Tanpa FK — disengaja.',
  `manager_name` varchar(150) DEFAULT NULL COMMENT 'Snapshot NAMA atasan saat ditetapkan (permanen)',
  `join_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL COMMENT 'Tanggal keluar, null = masih aktif',
  `shift_id` int(11) DEFAULT NULL,
  `employment_type` enum('permanent','contract','part_time','probation') NOT NULL DEFAULT 'permanent',
  `salary_type` enum('monthly','daily') NOT NULL DEFAULT 'monthly' COMMENT 'monthly = basic_salary sebulan; daily = daily_rate per hari hadir',
  `employee_status` enum('active','inactive','resigned','terminated') NOT NULL DEFAULT 'active',
  `basic_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `daily_rate` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Upah per hari. Hanya dipakai bila salary_type = daily.',
  `photo` varchar(255) DEFAULT NULL COMMENT 'Path foto karyawan',
  `cv_file` varchar(255) DEFAULT NULL COMMENT 'Path CV (pdf/jpg/jpeg/png)',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat data karyawan (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Data Karyawan';

-- --------------------------------------------------------

--
-- Table structure for table `employee_allowances`
--

CREATE TABLE `employee_allowances` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `component_key` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_assets`
--

CREATE TABLE `employee_assets` (
  `id` int(11) NOT NULL,
  `asset_code` varchar(60) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `serial_number` varchar(120) DEFAULT NULL,
  `assigned_date` date DEFAULT NULL,
  `returned_date` date DEFAULT NULL,
  `purchase_value` decimal(15,2) DEFAULT NULL,
  `asset_condition` enum('new','good','fair','damaged','lost') NOT NULL DEFAULT 'good',
  `status` enum('available','assigned','returned','written_off') NOT NULL DEFAULT 'available',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_documents`
--

CREATE TABLE `employee_documents` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `doc_type` enum('ktp','npwp','cv','certificate','contract','other') NOT NULL DEFAULT 'other',
  `doc_name` varchar(200) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pengunggah dokumen (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dokumen Karyawan';

-- --------------------------------------------------------

--
-- Table structure for table `employee_face_descriptors`
--

CREATE TABLE `employee_face_descriptors` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'employees.id. Tanpa FK — disengaja, mengikuti konvensi manager_id.',
  `sample_no` tinyint(4) NOT NULL DEFAULT 1 COMMENT 'Nomor sampel 1..5 untuk karyawan yang sama',
  `descriptor` text NOT NULL COMMENT 'JSON larik 128 float dari face-api.js',
  `photo` varchar(255) DEFAULT NULL COMMENT 'Foto saat pendaftaran, untuk audit dan pendaftaran ulang',
  `enrolled_by` int(11) DEFAULT NULL COMMENT 'users.id yang mendaftarkan',
  `enrolled_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pendaftar (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sidik wajah karyawan untuk absensi berbasis pengenalan wajah';

-- --------------------------------------------------------

--
-- Table structure for table `employee_leave_balance`
--

CREATE TABLE `employee_leave_balance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `year` int(4) NOT NULL DEFAULT year(current_timestamp()),
  `annual_quota` int(3) NOT NULL DEFAULT 12 COMMENT 'Annual leave days quota',
  `carry_over` int(3) NOT NULL DEFAULT 0 COMMENT 'Days carried over from previous year',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Annual leave balance per employee per year';

-- --------------------------------------------------------

--
-- Table structure for table `employee_salary_components`
--

CREATE TABLE `employee_salary_components` (
  `id` int(11) NOT NULL,
  `component_type` enum('earning','deduction') NOT NULL COMMENT 'earning=tunjangan/penghasilan, deduction=potongan',
  `component_name` varchar(100) NOT NULL,
  `component_key` varchar(50) NOT NULL COMMENT 'Key unik untuk referensi',
  `is_taxable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Apakah kena pajak',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(5) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_shifts`
--

CREATE TABLE `employee_shifts` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL COMMENT 'Nama shift, contoh: Morning, Evening, Night',
  `code` varchar(20) NOT NULL COMMENT 'Kode singkat shift, contoh: morning, evening, night',
  `start_time` time DEFAULT NULL COMMENT 'Jam mulai shift',
  `end_time` time DEFAULT NULL COMMENT 'Jam selesai shift',
  `break_minutes` int(5) NOT NULL DEFAULT 0 COMMENT 'Durasi istirahat (menit)',
  `cross_midnight` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 jika shift melewati tengah malam',
  `max_late_minutes` int(5) NOT NULL DEFAULT 15 COMMENT 'Toleransi keterlambatan (menit)',
  `color` varchar(10) NOT NULL DEFAULT '#6c757d' COMMENT 'Warna badge shift (hex)',
  `description` text DEFAULT NULL COMMENT 'Keterangan shift',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabel master shift karyawan';

-- --------------------------------------------------------

--
-- Table structure for table `employee_shift_schedules`
--

CREATE TABLE `employee_shift_schedules` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL COMMENT 'NULL when is_off=1',
  `schedule_date` date NOT NULL COMMENT 'Tanggal jadwal shift (NULL = berlaku mingguan)',
  `day_of_week` tinyint(1) DEFAULT NULL COMMENT '0=Minggu,1=Senin,...,6=Sabtu (NULL=tanggal spesifik)',
  `is_off` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=hari libur/day-off',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat jadwal (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Jadwal Shift Karyawan (Multi-Shift per hari)';

-- --------------------------------------------------------

--
-- Table structure for table `employee_trainings`
--

CREATE TABLE `employee_trainings` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `training_name` varchar(200) NOT NULL,
  `organizer` varchar(150) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `certificate` varchar(255) DEFAULT NULL COMMENT 'Path sertifikat',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pencatat pelatihan (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Riwayat Training Karyawan';

-- --------------------------------------------------------

--
-- Table structure for table `employee_warnings`
--

CREATE TABLE `employee_warnings` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `warning_type` enum('verbal','sp1','sp2','sp3','termination') NOT NULL DEFAULT 'verbal',
  `warning_date` date NOT NULL,
  `reason` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `issued_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA penerbit SP (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Surat Peringatan / Disciplinary';

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipts`
--

CREATE TABLE `goods_receipts` (
  `id` int(11) NOT NULL,
  `gr_number` varchar(50) NOT NULL COMMENT 'Nomor GR unik, auto-generate',
  `po_id` int(11) DEFAULT NULL COMMENT 'Purchase Order sumber (boleh NULL)',
  `po_number` varchar(50) DEFAULT NULL COMMENT 'Snapshot nomor PO saat GR dibuat',
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot nama supplier saat GR dibuat',
  `receipt_date` date NOT NULL,
  `delivery_note_number` varchar(100) DEFAULT NULL COMMENT 'Nomor surat jalan supplier',
  `payment_method` varchar(100) DEFAULT NULL COMMENT 'ID metode pembayaran (padanan purchases.payment_method)',
  `payment_by` varchar(100) DEFAULT NULL COMMENT 'NAMA user pembayar (permanen, tidak hilang saat akun dihapus)',
  `payer_name` varchar(100) DEFAULT NULL COMMENT 'NAMA user payer (permanen, tidak hilang saat akun dihapus)',
  `payer_shift` int(11) DEFAULT NULL COMMENT 'ID shift (padanan purchases.payer_shift)',
  `purchase_staff_name` varchar(100) DEFAULT NULL COMMENT 'NAMA purchase staff (permanen, tidak hilang saat user dihapus)',
  `payment_status` enum('paid','unpaid') NOT NULL DEFAULT 'paid' COMMENT 'paid = bayar langsung saat terima, unpaid = pembayaran tertunda',
  `paid_at` timestamp NULL DEFAULT NULL COMMENT 'Waktu pembayaran dicatat (NULL selama masih unpaid)',
  `paid_by` int(11) DEFAULT NULL COMMENT 'ID user yang mencatat pembayaran (referensi, boleh hilang)',
  `paid_by_name` varchar(100) DEFAULT NULL COMMENT 'NAMA pencatat pembayaran (permanen, tidak hilang saat akun dihapus)',
  `status` enum('draft','received','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `variance_reason` text DEFAULT NULL COMMENT 'Alasan selisih PO vs aktual',
  `overall_discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `overall_price_rounding` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Cache total untuk listing',
  `document` varchar(255) DEFAULT NULL COMMENT 'Lampiran surat jalan (opsional)',
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(100) NOT NULL DEFAULT '' COMMENT 'Snapshot identitas historis',
  `updated_by` int(11) DEFAULT NULL,
  `updated_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot identitas historis',
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot identitas historis',
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL COMMENT 'Alasan pembatalan, wajib untuk GR berstatus received',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipt_items`
--

CREATE TABLE `goods_receipt_items` (
  `id` int(11) NOT NULL,
  `gr_id` int(11) NOT NULL,
  `po_item_id` int(11) DEFAULT NULL COMMENT 'NULL = item tambahan di luar PO',
  `inventory_id` int(11) DEFAULT NULL,
  `item_name` varchar(200) NOT NULL,
  `ordered_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot qty PO (reference)',
  `received_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Fisik datang',
  `accepted_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Diterima / valid',
  `rejected_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Ditolak saat penerimaan',
  `close_line` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = sisa qty baris PO ini dinyatakan tidak akan datang lagi',
  `unit_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `po_unit_price` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot harga PO (reference)',
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Harga aktual',
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `price_rounding` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `name` varchar(150) NOT NULL,
  `is_national` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Libur nasional',
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat hari libur (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabel Hari Libur';

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `product_code` varchar(50) NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `qty_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Qty per unit (e.g. 330 ml, 12 pcs, 500 g)',
  `qty_per_unit_unit_id` int(11) DEFAULT NULL COMMENT 'Unit of qty_per_unit (FK to units.id, e.g. ml, gr, pcs)',
  `description` text DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `item_image` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat item (permanen, tidak hilang saat akun dihapus)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `classification_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_in`
--

CREATE TABLE `inventory_in` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pencatat (permanen, tidak hilang saat akun dihapus)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_out`
--

CREATE TABLE `inventory_out` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `taken_by` int(11) DEFAULT NULL,
  `taken_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pengambil (permanen, tidak hilang saat akun dihapus)',
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pencatat (permanen, tidak hilang saat akun dihapus)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_vacancies`
--

CREATE TABLE `job_vacancies` (
  `id` int(11) NOT NULL,
  `vacancy_code` varchar(60) NOT NULL,
  `title` varchar(200) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position_id` int(11) DEFAULT NULL,
  `quota` int(11) NOT NULL DEFAULT 1,
  `open_date` date DEFAULT NULL,
  `close_date` date DEFAULT NULL,
  `status` enum('draft','open','closed','cancelled') NOT NULL DEFAULT 'draft',
  `description` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kpi_categories`
--

CREATE TABLE `kpi_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `weight` decimal(5,2) NOT NULL DEFAULT 25.00 COMMENT 'Default weight % for indicators in this category',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kpi_evaluations`
--

CREATE TABLE `kpi_evaluations` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL,
  `evaluator_id` int(11) DEFAULT NULL COMMENT 'User who evaluated; null = self-eval pending',
  `evaluator_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA evaluator KPI (permanen)',
  `total_score` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Computed weighted score 0-100',
  `grade` varchar(5) DEFAULT NULL COMMENT 'A/B/C/D/E or custom',
  `comments` text DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `evaluated_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kpi_indicators`
--

CREATE TABLE `kpi_indicators` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `weight` decimal(5,2) NOT NULL DEFAULT 10.00 COMMENT 'Weight % within this evaluation',
  `target_value` decimal(15,2) DEFAULT NULL COMMENT 'Numeric target if applicable',
  `target_unit` varchar(20) DEFAULT NULL COMMENT 'e.g. %, items, days',
  `scoring_type` enum('rating','percentage','numeric') NOT NULL DEFAULT 'rating' COMMENT 'rating=1-5, percentage=0-100, numeric=actual vs target',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kpi_periods`
--

CREATE TABLE `kpi_periods` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `period_type` enum('monthly','quarterly','semester','annual','custom') NOT NULL DEFAULT 'quarterly',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('draft','active','closed') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kpi_scores`
--

CREATE TABLE `kpi_scores` (
  `id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `indicator_id` int(11) NOT NULL,
  `score` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '0-100 scale internally',
  `actual_value` decimal(15,2) DEFAULT NULL COMMENT 'For numeric scoring type',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type` enum('annual','sick','personal','maternity','paternity','other') NOT NULL DEFAULT 'annual',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` int(5) NOT NULL DEFAULT 1,
  `reason` text DEFAULT NULL,
  `supporting_document` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approval_request_id` int(11) DEFAULT NULL COMMENT 'approval_requests.id bila memakai alur bertingkat',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA penyetuju/penolak cuti (permanen)',
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pengajuan Cuti';

-- --------------------------------------------------------

--
-- Table structure for table `manual_daily_reports`
--

CREATE TABLE `manual_daily_reports` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL COMMENT 'Tanggal laporan',
  `cashier_name` varchar(150) DEFAULT NULL COMMENT 'Nama kasir, disimpan langsung dari users.full_name',
  `shift_id` int(11) DEFAULT NULL COMMENT 'FK ke employee_shifts.id',
  `system_revenue` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Pendapatan dari sistem (auto dari purchases)',
  `total_manual` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total manual input kasir',
  `difference` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Selisih: total_manual - system_revenue',
  `notes` text DEFAULT NULL COMMENT 'Catatan tambahan',
  `yesterday_cash_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `yesterday_cash_usage` decimal(15,2) NOT NULL DEFAULT 0.00,
  `yesterday_cash_optional` decimal(15,2) NOT NULL DEFAULT 0.00,
  `yesterday_cash_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `yesterday_cash_image` varchar(255) NOT NULL DEFAULT '',
  `notes_cash` text DEFAULT NULL,
  `rpos_image` varchar(255) NOT NULL DEFAULT '',
  `image_file` varchar(255) DEFAULT NULL COMMENT 'Nama file foto/struk',
  `created_by_user` varchar(100) DEFAULT NULL COMMENT 'NAMA pembuat laporan (permanen, bukan FK; tidak hilang saat user dihapus)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Laporan Harian Kasir Manual';

-- --------------------------------------------------------

--
-- Table structure for table `manual_daily_report_items`
--

CREATE TABLE `manual_daily_report_items` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL COMMENT 'FK ke manual_daily_reports.id',
  `payment_method_id` int(11) DEFAULT NULL COMMENT 'FK ke payment_methods.id',
  `manual_payment_method_id` int(11) DEFAULT NULL COMMENT 'FK ke manual_payment_methods.id (diisi jika input manual, NULL jika dari dropdown)',
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Nominal untuk metode pembayaran ini'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detail Metode Pembayaran per Laporan Harian Kasir';

-- --------------------------------------------------------

--
-- Table structure for table `manual_dreport_optional_items`
--

CREATE TABLE `manual_dreport_optional_items` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL COMMENT 'FK ke manual_daily_reports.id',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Deskripsi item opsional',
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manual_payment_methods`
--

CREATE TABLE `manual_payment_methods` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Nama metode pembayaran yang diketik manual',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Metode pembayaran custom yang diinput manual kasir';

-- --------------------------------------------------------

--
-- Table structure for table `news`
--

CREATE TABLE `news` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `onboarding_tasks`
--

CREATE TABLE `onboarding_tasks` (
  `id` int(11) NOT NULL,
  `task_name` varchar(200) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `overtime_settings`
--

CREATE TABLE `overtime_settings` (
  `id` int(11) NOT NULL,
  `multiplier_hour_1` decimal(3,1) NOT NULL DEFAULT 1.5 COMMENT 'Multiplier jam lembur 1',
  `multiplier_hour_2plus` decimal(3,1) NOT NULL DEFAULT 2.0 COMMENT 'Multiplier jam lembur >1',
  `late_deduction_per_minute` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Potongan per menit terlambat',
  `bpjs_kesehatan_rate` decimal(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Rate BPJS Kesehatan %',
  `bpjs_ketenagakerjaan_rate` decimal(5,2) NOT NULL DEFAULT 2.00 COMMENT 'Rate BPJS TK %',
  `pph21_rate` decimal(5,2) NOT NULL DEFAULT 5.00 COMMENT 'Rate PPh21 %',
  `ptkp_monthly` decimal(15,2) NOT NULL DEFAULT 500000.00 COMMENT 'PTKP bulanan',
  `daily_work_hours` decimal(4,2) NOT NULL DEFAULT 8.00 COMMENT 'Jam kerja standar per hari. Dasar tarif per jam karyawan harian.',
  `diligence_max_late_count` int(3) NOT NULL DEFAULT 0 COMMENT 'Batas jumlah keterlambatan yang masih ditoleransi. Lebih dari ini, uang kerajinan hangus.',
  `diligence_max_absent_days` int(3) NOT NULL DEFAULT 0 COMMENT 'Batas jumlah hari absen yang masih ditoleransi. Lebih dari ini, uang kerajinan hangus.',
  `geofence_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = absen di luar radius ditolak. Default mati.',
  `office_lat` decimal(10,7) NOT NULL DEFAULT 0.0000000 COMMENT 'Lintang titik absensi',
  `office_lng` decimal(10,7) NOT NULL DEFAULT 0.0000000 COMMENT 'Bujur titik absensi',
  `geofence_radius_m` int(6) NOT NULL DEFAULT 100 COMMENT 'Radius dalam METER dari titik di atas',
  `updated_by` int(11) DEFAULT NULL,
  `updated_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pengubah setelan (permanen)',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pengaturan Lembur & Potongan';

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `code` varchar(20) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `salary_type` enum('monthly','daily') NOT NULL DEFAULT 'monthly' COMMENT 'Snapshot tipe gaji saat periode ini dihitung',
  `basic_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `daily_rate` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot upah harian saat dihitung',
  `meal_allowance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `transport_allowance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `overtime_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(15,2) NOT NULL DEFAULT 0.00,
  `diligence_allowance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Uang kerajinan yang benar-benar dibayar periode ini',
  `other_allowance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `gross_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `working_days` int(3) NOT NULL DEFAULT 0,
  `present_days` int(3) NOT NULL DEFAULT 0,
  `absent_days` int(3) NOT NULL DEFAULT 0,
  `late_count` int(3) NOT NULL DEFAULT 0,
  `late_minutes_total` int(5) NOT NULL DEFAULT 0,
  `absence_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `late_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bpjs_kesehatan_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bpjs_ketenagakerjaan_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `income_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `kasbon_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_deduction` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','approved','paid') NOT NULL DEFAULT 'draft',
  `payment_method` varchar(50) DEFAULT 'transfer',
  `payment_date` date DEFAULT NULL,
  `slip_sent` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Slip sudah dikirim via email',
  `slip_sent_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA penyetuju payroll (permanen)',
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat payroll (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payroll/Slip Gaji';

-- --------------------------------------------------------

--
-- Table structure for table `payroll_periods`
--

CREATE TABLE `payroll_periods` (
  `id` int(11) NOT NULL,
  `period_name` varchar(100) NOT NULL COMMENT 'Contoh: March 2026',
  `period_year` year(4) NOT NULL,
  `period_month` tinyint(2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `payment_date` date DEFAULT NULL,
  `status` enum('draft','calculated','paid','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat periode (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Periode Penggajian';

-- --------------------------------------------------------

--
-- Table structure for table `payslip_email_log`
--

CREATE TABLE `payslip_email_log` (
  `id` int(11) NOT NULL,
  `payroll_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `employee_name` varchar(150) DEFAULT NULL,
  `email_to` varchar(190) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `sent_by` int(11) DEFAULT NULL,
  `sent_by_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` enum('admin','manager','supervisor','hr','user','cashier') NOT NULL,
  `module` varchar(50) NOT NULL,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_read` tinyint(1) NOT NULL DEFAULT 1,
  `can_update` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_access` tinyint(1) NOT NULL DEFAULT 1,
  `can_export` tinyint(1) NOT NULL DEFAULT 0,
  `can_view_all` tinyint(1) DEFAULT NULL COMMENT 'Cakupan data dreport: NULL=ikut aturan lama, 1=Full Data (override), 0=Data Sendiri',
  `po_data_scope` enum('all','own') NOT NULL DEFAULT 'all',
  `po_own_display` enum('show','hidden') NOT NULL DEFAULT 'show',
  `perm_delegate` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Baris module=users: 1 = akun ini boleh mengelola permission akun lain (delegate). 0 = tidak (DEFAULT)',
  `perm_scope_groups` varchar(255) NOT NULL DEFAULT '' COMMENT 'Baris module=users: daftar grup modul yang boleh dikelola delegate, dipisah koma (main,purchasing,inventory,hr,cashier,settings). Kosong = semua grup (DEFAULT)',
  `perm_target_rank` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Baris module=users: 1 = delegate hanya boleh mengelola akun ber-role di bawah role-nya. 0 = semua akun non-superadmin (DEFAULT)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `department_id` int(11) DEFAULT NULL COMMENT 'Optional: link to department',
  `description` text DEFAULT NULL,
  `pic_employee_id` int(11) DEFAULT NULL COMMENT 'Penanggung jawab posisi (employees.id). Tanpa FK — disengaja.',
  `pic_name` varchar(150) DEFAULT NULL COMMENT 'Snapshot NAMA penanggung jawab saat ditetapkan (permanen)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Master Position/Jabatan';

-- --------------------------------------------------------

--
-- Table structure for table `ptkp_status`
--

CREATE TABLE `ptkp_status` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL COMMENT 'TK/0, K/1, dst',
  `name` varchar(100) NOT NULL,
  `annual_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(3) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Penghasilan Tidak Kena Pajak';

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` int(11) NOT NULL,
  `input_date` date NOT NULL,
  `invoice_date` date NOT NULL,
  `purchase_staff_id` varchar(100) DEFAULT NULL COMMENT 'Nama purchase staff (permanen, tidak hilang saat user dihapus)',
  `payment_by` varchar(100) DEFAULT NULL,
  `payer_name` varchar(100) DEFAULT NULL,
  `payer_shift` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL COMMENT 'Source Purchase Order (optional)',
  `gr_id` int(11) DEFAULT NULL COMMENT 'Goods Receipt sumber (opsional)',
  `payment_method` varchar(50) NOT NULL DEFAULT 'cash',
  `notes` text DEFAULT NULL,
  `overall_discount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Global discount applied to the entire purchase',
  `overall_price_rounding` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Overall price rounding applied to the entire purchase',
  `tax` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Tax amount applied to the entire purchase',
  `document` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL COMMENT 'Nama pembuat invoice (permanen, tidak hilang saat user dihapus)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `price_rounding` decimal(15,2) NOT NULL DEFAULT 0.00,
  `category_id` int(11) DEFAULT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `po_number` varchar(50) NOT NULL COMMENT 'Unique PO reference, auto-generated',
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL COMMENT 'Expected delivery date',
  `supplier_id` int(11) DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'cash',
  `payment_terms` varchar(100) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `company_contact` varchar(100) DEFAULT NULL COMMENT 'Snapshot contact perusahaan saat PO dibuat (permanen)',
  `status` enum('draft','sent','approved','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `overall_discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `overall_price_rounding` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Cached subtotal for listings',
  `document` varchar(255) DEFAULT NULL COMMENT 'Optional attached file (signed PO etc.)',
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat PO (permanen, tidak hilang saat akun dihapus)',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA penyetuju PO (permanen, tidak hilang saat akun dihapus)',
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `item_name` varchar(200) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `price_rounding` decimal(15,2) NOT NULL DEFAULT 0.00,
  `category_id` int(11) DEFAULT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `received_qty` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'How much has been received against this PO line',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns`
--

CREATE TABLE `purchase_returns` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) NOT NULL COMMENT 'Nomor Return unik, auto-generate',
  `po_id` int(11) DEFAULT NULL,
  `po_number` varchar(50) DEFAULT NULL COMMENT 'Snapshot nomor PO',
  `gr_id` int(11) DEFAULT NULL,
  `gr_number` varchar(50) DEFAULT NULL COMMENT 'Snapshot nomor GR',
  `purchase_id` int(11) DEFAULT NULL COMMENT 'Purchase Invoice terkait (opsional)',
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot nama supplier',
  `return_date` date NOT NULL,
  `status` enum('pending','approved','rejected','processed','cancelled') NOT NULL DEFAULT 'pending',
  `reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `document` varchar(255) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(100) NOT NULL DEFAULT '' COMMENT 'Snapshot identitas historis',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot identitas historis',
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot identitas historis',
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_items`
--

CREATE TABLE `purchase_return_items` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `gr_item_id` int(11) DEFAULT NULL COMMENT 'Baris Goods Receipt sumber',
  `purchase_item_id` int(11) DEFAULT NULL COMMENT 'Baris Purchase Invoice sumber',
  `po_item_id` int(11) DEFAULT NULL COMMENT 'Baris Purchase Order sumber',
  `inventory_id` int(11) DEFAULT NULL,
  `item_name` varchar(200) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_id` int(11) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `religions`
--

CREATE TABLE `religions` (
  `id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Master Agama/Religion';

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` int(11) NOT NULL,
  `purchase_item_id` int(11) NOT NULL,
  `return_date` date NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(100) DEFAULT NULL COMMENT 'Snapshot NAMA pembuat retur (permanen)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT 'text',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_opnames`
--

CREATE TABLE `stock_opnames` (
  `id` int(11) NOT NULL,
  `opname_number` varchar(50) NOT NULL,
  `opname_date` date NOT NULL,
  `location_id` int(11) NOT NULL,
  `status` enum('draft','counting','review','approved','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(100) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_by_name` varchar(100) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_by_name` varchar(100) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_opname_items`
--

CREATE TABLE `stock_opname_items` (
  `id` int(11) NOT NULL,
  `stock_opname_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `system_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `counted_qty` decimal(10,2) DEFAULT NULL,
  `difference_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_id` int(11) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `difference_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `counted_by` int(11) DEFAULT NULL,
  `counted_by_name` varchar(100) DEFAULT NULL,
  `counted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `credit_limit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `rating` tinyint(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tax_brackets`
--

CREATE TABLE `tax_brackets` (
  `id` int(11) NOT NULL,
  `year` int(4) NOT NULL,
  `min_income` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Batas bawah PKP setahun',
  `max_income` decimal(15,2) DEFAULT NULL COMMENT 'Batas atas; NULL = tak terbatas',
  `rate_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(3) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lapisan tarif PPh21 progresif';

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(25) DEFAULT NULL COMMENT 'User phone number (profile_user.php)',
  `role` enum('superadmin','admin','manager','supervisor','hr','user','cashier') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_activity` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `trg_users_archive_insert` AFTER INSERT ON `users` FOR EACH ROW BEGIN
    REPLACE INTO `user_name_archive` (`user_id`, `username`, `full_name`, `role`)
    VALUES (NEW.`id`, NEW.`username`, NEW.`full_name`, NEW.`role`);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_users_archive_update` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    REPLACE INTO `user_name_archive` (`user_id`, `username`, `full_name`, `role`)
    VALUES (NEW.`id`, NEW.`username`, NEW.`full_name`, NEW.`role`);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_name_archive`
--

CREATE TABLE `user_name_archive` (
  `user_id` int(11) NOT NULL COMMENT 'Salinan users.id. SENGAJA TANPA foreign key agar tidak ikut terhapus.',
  `username` varchar(50) NOT NULL DEFAULT '',
  `full_name` varchar(100) NOT NULL DEFAULT '',
  `role` varchar(30) NOT NULL DEFAULT '',
  `last_synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Arsip permanen nama user. Tidak pernah dihapus, agar riwayat transaksi tetap bernama setelah akun user dihapus.';

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_locations`
--

CREATE TABLE `warehouse_locations` (
  `id` int(11) NOT NULL,
  `location_code` varchar(50) NOT NULL,
  `location_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `appraisal_cycles`
--
ALTER TABLE `appraisal_cycles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_kpi` (`kpi_period_id`);

--
-- Indexes for table `approval_flows`
--
ALTER TABLE `approval_flows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_flow_key` (`flow_key`),
  ADD KEY `idx_flow_active` (`is_active`);

--
-- Indexes for table `approval_logs`
--
ALTER TABLE `approval_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_log_request` (`request_id`),
  ADD KEY `idx_log_actor` (`actor_user_id`);

--
-- Indexes for table `approval_requests`
--
ALTER TABLE `approval_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ref` (`ref_table`,`ref_id`),
  ADD KEY `idx_req_status` (`status`),
  ADD KEY `idx_req_emp` (`employee_id`),
  ADD KEY `idx_req_flow` (`flow_key`);

--
-- Indexes for table `approval_steps`
--
ALTER TABLE `approval_steps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_flow_step` (`flow_id`,`step_order`),
  ADD KEY `idx_step_flow` (`flow_id`),
  ADD KEY `idx_step_user` (`user_id`),
  ADD KEY `idx_step_pos` (`position_id`);

--
-- Indexes for table `asset_categories`
--
ALTER TABLE `asset_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `emp_date` (`employee_id`,`attendance_date`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `idx_att_created_by` (`created_by`),
  ADD KEY `idx_att_approved_by` (`approved_by`);

--
-- Indexes for table `attendance_imports`
--
ALTER TABLE `attendance_imports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `attendance_import_rows`
--
ALTER TABLE `attendance_import_rows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_import` (`import_id`),
  ADD KEY `idx_match` (`matched_employee_id`);

--
-- Indexes for table `cash_advances`
--
ALTER TABLE `cash_advances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ca_emp_fk` (`employee_id`),
  ADD KEY `idx_ca_approved_by` (`approved_by`),
  ADD KEY `idx_ca_appr` (`approval_request_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dept_name_unique` (`name`),
  ADD KEY `idx_dept_pic` (`pic_employee_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD KEY `shift_id` (`shift_id`),
  ADD KEY `emp_dept_fk` (`department_id`),
  ADD KEY `emp_pos_fk` (`position_id`),
  ADD KEY `emp_rel_fk` (`religion_id`),
  ADD KEY `idx_employees_created_by` (`created_by`),
  ADD KEY `idx_emp_manager` (`manager_id`),
  ADD KEY `idx_emp_user` (`user_id`),
  ADD KEY `idx_emp_ptkp` (`ptkp_code`);

--
-- Indexes for table `employee_allowances`
--
ALTER TABLE `employee_allowances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `emp_component` (`employee_id`,`component_key`);

--
-- Indexes for table `employee_assets`
--
ALTER TABLE `employee_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_asset_code` (`asset_code`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_doc` (`employee_id`),
  ADD KEY `idx_edoc_created_by` (`created_by`);

--
-- Indexes for table `employee_face_descriptors`
--
ALTER TABLE `employee_face_descriptors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_emp_sample` (`employee_id`,`sample_no`),
  ADD KEY `idx_emp` (`employee_id`);

--
-- Indexes for table `employee_leave_balance`
--
ALTER TABLE `employee_leave_balance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_emp_year` (`employee_id`,`year`),
  ADD KEY `idx_employee_id` (`employee_id`);

--
-- Indexes for table `employee_salary_components`
--
ALTER TABLE `employee_salary_components`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `component_key` (`component_key`),
  ADD KEY `idx_esc_type_sort` (`component_type`,`sort_order`),
  ADD KEY `idx_esc_active` (`is_active`);

--
-- Indexes for table `employee_shifts`
--
ALTER TABLE `employee_shifts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `employee_shift_schedules`
--
ALTER TABLE `employee_shift_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_emp_date` (`employee_id`,`schedule_date`),
  ADD KEY `idx_emp_shift` (`employee_id`,`shift_id`),
  ADD KEY `idx_schedule_date` (`schedule_date`),
  ADD KEY `fk_ess_shift` (`shift_id`),
  ADD KEY `idx_ess_created_by` (`created_by`);

--
-- Indexes for table `employee_trainings`
--
ALTER TABLE `employee_trainings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_training` (`employee_id`),
  ADD KEY `idx_etr_created_by` (`created_by`);

--
-- Indexes for table `employee_warnings`
--
ALTER TABLE `employee_warnings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp_warning` (`employee_id`),
  ADD KEY `idx_ew_issued_by` (`issued_by`);

--
-- Indexes for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gr_number` (`gr_number`),
  ADD KEY `idx_gr_po` (`po_id`),
  ADD KEY `idx_gr_supplier` (`supplier_id`),
  ADD KEY `idx_gr_receipt_date` (`receipt_date`),
  ADD KEY `idx_gr_status` (`status`),
  ADD KEY `idx_gr_created_by` (`created_by`),
  ADD KEY `idx_gr_payment_status` (`payment_status`),
  ADD KEY `idx_gr_status_payment_date` (`status`,`payment_status`,`receipt_date`);

--
-- Indexes for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gri_gr` (`gr_id`),
  ADD KEY `idx_gri_po_item` (`po_item_id`),
  ADD KEY `idx_gri_inv` (`inventory_id`),
  ADD KEY `idx_gri_close_line` (`po_item_id`,`close_line`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_holiday_date` (`holiday_date`),
  ADD KEY `idx_holidays_created_by` (`created_by`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_code` (`product_code`),
  ADD UNIQUE KEY `uq_product_code` (`product_code`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `fk_qty_per_unit_unit` (`qty_per_unit_unit_id`);

--
-- Indexes for table `inventory_in`
--
ALTER TABLE `inventory_in`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `inventory_out`
--
ALTER TABLE `inventory_out`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `taken_by` (`taken_by`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `job_vacancies`
--
ALTER TABLE `job_vacancies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vacancy_code` (`vacancy_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_dept` (`department_id`);

--
-- Indexes for table `kpi_categories`
--
ALTER TABLE `kpi_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active_order` (`is_active`,`display_order`);

--
-- Indexes for table `kpi_evaluations`
--
ALTER TABLE `kpi_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_emp_period` (`employee_id`,`period_id`),
  ADD KEY `idx_period_status` (`period_id`,`status`),
  ADD KEY `idx_evaluator` (`evaluator_id`),
  ADD KEY `idx_kpi_eval_evaluator` (`evaluator_id`);

--
-- Indexes for table `kpi_indicators`
--
ALTER TABLE `kpi_indicators`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `kpi_periods`
--
ALTER TABLE `kpi_periods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status_dates` (`status`,`start_date`,`end_date`);

--
-- Indexes for table `kpi_scores`
--
ALTER TABLE `kpi_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_eval_indicator` (`evaluation_id`,`indicator_id`),
  ADD KEY `idx_evaluation` (`evaluation_id`),
  ADD KEY `idx_indicator` (`indicator_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lr_emp_fk` (`employee_id`),
  ADD KEY `idx_lr_approved_by` (`approved_by`),
  ADD KEY `idx_lr_appr` (`approval_request_id`);

--
-- Indexes for table `manual_daily_reports`
--
ALTER TABLE `manual_daily_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report_date` (`report_date`),
  ADD KEY `idx_shift_id` (`shift_id`),
  ADD KEY `idx_created_by` (`created_by_user`),
  ADD KEY `idx_cashier_name` (`cashier_name`);

--
-- Indexes for table `manual_daily_report_items`
--
ALTER TABLE `manual_daily_report_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report_id` (`report_id`),
  ADD KEY `idx_payment_method_id` (`payment_method_id`),
  ADD KEY `idx_manual_pm_id` (`manual_payment_method_id`);

--
-- Indexes for table `manual_dreport_optional_items`
--
ALTER TABLE `manual_dreport_optional_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mdoi_report` (`report_id`);

--
-- Indexes for table `manual_payment_methods`
--
ALTER TABLE `manual_payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_name` (`name`);

--
-- Indexes for table `news`
--
ALTER TABLE `news`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `onboarding_tasks`
--
ALTER TABLE `onboarding_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sort` (`sort_order`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `overtime_settings`
--
ALTER TABLE `overtime_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `period_employee` (`period_id`,`employee_id`),
  ADD KEY `pr_emp_fk` (`employee_id`),
  ADD KEY `idx_pr_created_by` (`created_by`),
  ADD KEY `idx_pr_approved_by` (`approved_by`);

--
-- Indexes for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `period_ym` (`period_year`,`period_month`),
  ADD KEY `idx_pp_created_by` (`created_by`);

--
-- Indexes for table `payslip_email_log`
--
ALTER TABLE `payslip_email_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_payroll` (`payroll_id`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_module` (`user_id`,`module`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pos_dept_fk` (`department_id`),
  ADD KEY `idx_pos_pic` (`pic_employee_id`);

--
-- Indexes for table `ptkp_status`
--
ALTER TABLE `ptkp_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ptkp_code` (`code`),
  ADD KEY `idx_ptkp_active` (`is_active`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `purchases_ibfk_3` (`purchase_staff_id`),
  ADD KEY `purchases_payer_shift_fk` (`payer_shift`),
  ADD KEY `idx_purchases_po` (`po_id`),
  ADD KEY `idx_purchases_gr` (`gr_id`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_po_number` (`po_number`),
  ADD KEY `idx_po_status` (`status`),
  ADD KEY `idx_po_order_date` (`order_date`),
  ADD KEY `idx_po_supplier` (`supplier_id`),
  ADD KEY `idx_po_created_by` (`created_by`),
  ADD KEY `idx_po_approved_by` (`approved_by`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_poi_po` (`po_id`),
  ADD KEY `idx_poi_category` (`category_id`),
  ADD KEY `idx_poi_unit` (`unit_id`),
  ADD KEY `idx_poi_inventory` (`inventory_id`);

--
-- Indexes for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pr_number` (`return_number`),
  ADD KEY `idx_pr_po` (`po_id`),
  ADD KEY `idx_pr_gr` (`gr_id`),
  ADD KEY `idx_pr_purchase` (`purchase_id`),
  ADD KEY `idx_pr_supplier` (`supplier_id`),
  ADD KEY `idx_pr_return_date` (`return_date`),
  ADD KEY `idx_pr_status` (`status`),
  ADD KEY `idx_pr_created_by` (`created_by`);

--
-- Indexes for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pri_return` (`return_id`),
  ADD KEY `idx_pri_gr_item` (`gr_item_id`),
  ADD KEY `idx_pri_pi` (`purchase_item_id`),
  ADD KEY `idx_pri_po_item` (`po_item_id`);

--
-- Indexes for table `religions`
--
ALTER TABLE `religions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `religion_name_unique` (`name`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_item_id` (`purchase_item_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_returns_created_by` (`created_by`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `stock_opnames`
--
ALTER TABLE `stock_opnames`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stock_opname_number` (`opname_number`),
  ADD KEY `idx_stock_opname_location` (`location_id`),
  ADD KEY `idx_stock_opname_status` (`status`),
  ADD KEY `idx_stock_opname_date` (`opname_date`),
  ADD KEY `idx_stock_opname_created_by` (`created_by`),
  ADD KEY `idx_stock_opname_approved_by` (`approved_by`),
  ADD KEY `idx_stock_opname_posted_by` (`posted_by`),
  ADD KEY `idx_stock_opname_cancelled_by` (`cancelled_by`);

--
-- Indexes for table `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_opname_inventory` (`stock_opname_id`,`inventory_id`),
  ADD KEY `idx_stock_opname_items_opname` (`stock_opname_id`),
  ADD KEY `idx_stock_opname_items_inventory` (`inventory_id`),
  ADD KEY `idx_stock_opname_items_unit` (`unit_id`),
  ADD KEY `idx_stock_opname_items_counted_by` (`counted_by`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tax_brackets`
--
ALTER TABLE `tax_brackets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tb_year` (`year`,`sort_order`),
  ADD KEY `idx_tb_active` (`is_active`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_name_archive`
--
ALTER TABLE `user_name_archive`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_una_full_name` (`full_name`);

--
-- Indexes for table `warehouse_locations`
--
ALTER TABLE `warehouse_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `location_code` (`location_code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appraisal_cycles`
--
ALTER TABLE `appraisal_cycles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_flows`
--
ALTER TABLE `approval_flows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_logs`
--
ALTER TABLE `approval_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_requests`
--
ALTER TABLE `approval_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_steps`
--
ALTER TABLE `approval_steps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asset_categories`
--
ALTER TABLE `asset_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_imports`
--
ALTER TABLE `attendance_imports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_import_rows`
--
ALTER TABLE `attendance_import_rows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_advances`
--
ALTER TABLE `cash_advances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_allowances`
--
ALTER TABLE `employee_allowances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_assets`
--
ALTER TABLE `employee_assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_documents`
--
ALTER TABLE `employee_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_face_descriptors`
--
ALTER TABLE `employee_face_descriptors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_leave_balance`
--
ALTER TABLE `employee_leave_balance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_salary_components`
--
ALTER TABLE `employee_salary_components`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_shifts`
--
ALTER TABLE `employee_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_shift_schedules`
--
ALTER TABLE `employee_shift_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_trainings`
--
ALTER TABLE `employee_trainings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_warnings`
--
ALTER TABLE `employee_warnings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_in`
--
ALTER TABLE `inventory_in`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_out`
--
ALTER TABLE `inventory_out`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_vacancies`
--
ALTER TABLE `job_vacancies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kpi_categories`
--
ALTER TABLE `kpi_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kpi_evaluations`
--
ALTER TABLE `kpi_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kpi_indicators`
--
ALTER TABLE `kpi_indicators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kpi_periods`
--
ALTER TABLE `kpi_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kpi_scores`
--
ALTER TABLE `kpi_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manual_daily_reports`
--
ALTER TABLE `manual_daily_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manual_daily_report_items`
--
ALTER TABLE `manual_daily_report_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manual_dreport_optional_items`
--
ALTER TABLE `manual_dreport_optional_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manual_payment_methods`
--
ALTER TABLE `manual_payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `news`
--
ALTER TABLE `news`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `onboarding_tasks`
--
ALTER TABLE `onboarding_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `overtime_settings`
--
ALTER TABLE `overtime_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payslip_email_log`
--
ALTER TABLE `payslip_email_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ptkp_status`
--
ALTER TABLE `ptkp_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `religions`
--
ALTER TABLE `religions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_opnames`
--
ALTER TABLE `stock_opnames`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tax_brackets`
--
ALTER TABLE `tax_brackets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_locations`
--
ALTER TABLE `warehouse_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `att_emp_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `att_shift_fk` FOREIGN KEY (`shift_id`) REFERENCES `employee_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `cash_advances`
--
ALTER TABLE `cash_advances`
  ADD CONSTRAINT `ca_emp_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `emp_dept_fk` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `emp_pos_fk` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `emp_rel_fk` FOREIGN KEY (`religion_id`) REFERENCES `religions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `emp_shift_fk` FOREIGN KEY (`shift_id`) REFERENCES `employee_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `employee_allowances`
--
ALTER TABLE `employee_allowances`
  ADD CONSTRAINT `ea_emp_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD CONSTRAINT `fk_edoc_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `employee_leave_balance`
--
ALTER TABLE `employee_leave_balance`
  ADD CONSTRAINT `fk_elb_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_shift_schedules`
--
ALTER TABLE `employee_shift_schedules`
  ADD CONSTRAINT `fk_ess_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ess_shift` FOREIGN KEY (`shift_id`) REFERENCES `employee_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `employee_trainings`
--
ALTER TABLE `employee_trainings`
  ADD CONSTRAINT `fk_etr_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `employee_warnings`
--
ALTER TABLE `employee_warnings`
  ADD CONSTRAINT `fk_ew_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `fk_qty_per_unit_unit` FOREIGN KEY (`qty_per_unit_unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `warehouse_locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_ibfk_4` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_in`
--
ALTER TABLE `inventory_in`
  ADD CONSTRAINT `inventory_in_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_in_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_out`
--
ALTER TABLE `inventory_out`
  ADD CONSTRAINT `inventory_out_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_out_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `kpi_indicators`
--
ALTER TABLE `kpi_indicators`
  ADD CONSTRAINT `fk_kpi_ind_category` FOREIGN KEY (`category_id`) REFERENCES `kpi_categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kpi_scores`
--
ALTER TABLE `kpi_scores`
  ADD CONSTRAINT `fk_kpi_score_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `kpi_evaluations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_kpi_score_ind` FOREIGN KEY (`indicator_id`) REFERENCES `kpi_indicators` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `lr_emp_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `manual_daily_reports`
--
ALTER TABLE `manual_daily_reports`
  ADD CONSTRAINT `fk_mdr_shift` FOREIGN KEY (`shift_id`) REFERENCES `employee_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `manual_daily_report_items`
--
ALTER TABLE `manual_daily_report_items`
  ADD CONSTRAINT `fk_mdri_manual_pm` FOREIGN KEY (`manual_payment_method_id`) REFERENCES `manual_payment_methods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mdri_payment` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mdri_report` FOREIGN KEY (`report_id`) REFERENCES `manual_daily_reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `manual_dreport_optional_items`
--
ALTER TABLE `manual_dreport_optional_items`
  ADD CONSTRAINT `fk_mdoi_report` FOREIGN KEY (`report_id`) REFERENCES `manual_daily_reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `pr_emp_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `pr_period_fk` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `permissions`
--
ALTER TABLE `permissions`
  ADD CONSTRAINT `fk_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `positions`
--
ALTER TABLE `positions`
  ADD CONSTRAINT `pos_dept_fk` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchases_payer_shift_fk` FOREIGN KEY (`payer_shift`) REFERENCES `employee_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_items_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `returns`
--
ALTER TABLE `returns`
  ADD CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`purchase_item_id`) REFERENCES `purchase_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_opnames`
--
ALTER TABLE `stock_opnames`
  ADD CONSTRAINT `stock_opnames_location_fk` FOREIGN KEY (`location_id`) REFERENCES `warehouse_locations` (`id`);

--
-- Constraints for table `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  ADD CONSTRAINT `stock_opname_items_inventory_fk` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`),
  ADD CONSTRAINT `stock_opname_items_opname_fk` FOREIGN KEY (`stock_opname_id`) REFERENCES `stock_opnames` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stock_opname_items_unit_fk` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
