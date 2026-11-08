<?php
/**
 * global_permission.php
 * File: public_html/bltx/includes/global_permission.php
 *
 * Centralized permission helper — used across all pages.
 * Provides consistent permission functions usable globally.
 *
 * Compatible with permission system in config.php:
 *  - checkPermission($module, $action)   → from session cache
 *  - _canAccess($module, $action)        → shorthand alias
 *  - loadPermissionsToSession()          → load permissions to session cache
 *
 * Functions in this file:
 *  - getPermissions($role, $module)
 *  - invCanExport($module)
 *  - getInventoryPermissions()
 *  - getModulePermissions($module)
 *  - requirePermission($module, $action)
 *  - requireLogin()
 *  - canShowExportSection($module)
 *  - isSuper()
 *  -- Purchases module --
 *  - requirePurchaseCreate/Update
 *  - canCreatePurchase/Update/Delete/Export/Read
 *  - canOverallPriceRounding
 *  -- DReport module --
 *  - requireYesterdayCashViewAccess
 *  -- Payroll module --
 *  - requirePayrollDashboard/EmployeesAccess/AttendanceAccess/ShiftsAccess
 *  - requireShiftSchedulesAccess (NEW — multi-shift)
 *  - requireHolidaysAccess (NEW)
 *  - requirePayrollPeriodsAccess/ListAccess/LeaveRequestsAccess
 *  - requireCashAdvancesAccess/DepartmentsAccess/PositionsAccess/ReligionsAccess
 *  - requirePayrollSettingsAccess
 *  - canCreateEmployee/Update/Delete/Export
 *  - canCreateAttendance/Update/Delete/Export
 *  - canCreateShift/Update/Delete
 *  - canManageShiftSchedules (NEW)
 *  - canManageHolidays (NEW)
 *  - canCreatePayrollPeriod/Update/Delete
 *  - canApprovePayroll/Export
 *  - canCreateLeaveRequest/Approve/Delete/Export
 *  - canCreateCashAdvance/Approve/Delete/Export
 *  - canCreateDepartment/Update/Delete
 *  - canCreatePosition/Update/Delete
 *  - canCreateReligion/Update/Delete
 *  - canUpdatePayrollSettings
 *  - isPayrollAccess / requirePayrollAccess (legacy)
 *  -- Inventory parent gate + tabs (terpusat) --
 *  - canAccessInventoryGate
 *  - inventoryModuleAllowed / inventoryModuleCan / requireInventoryModule
 *  - inventoryTabModules
 *  - inventoryTabIcons / inventoryTabLangKeys
 *  -- Take Items module --
 *  - canAccessTakeItems / requireTakeItemsAccess
 *  - canReadTakeItems/Create/Update/Delete/Export
 *  -- Added Items (in_items) module --
 *  - canAccessInItems / requireInItemsAccess
 *  - canReadInItems/Create/Update/Delete/Export
 *  -- Goods Receipt module (NEW) --
 *  - requireGoodsReceiptAccess
 *  - canAccessGoodsReceipt/Read/Create/Update/Delete/Export/Cancel
 *  -- Purchase Return module (NEW flow, reuses existing 'returns' permission) --
 *  - requirePurchaseReturnAccess
 *  - canAccessPurchaseReturn/Read/Create/Update/Delete/Export/Approve/Cancel
 *  -- Settings > Purchase module --
 *  - canAccessSettingsPurchase/Read/Update
 */

/* ============================================================
   GUARD — config.php must be loaded first
   ============================================================ */
if (!function_exists('checkPermission')) {
    return;
}

/* ============================================================
   LEGACY COMPAT — getPermissions()
   ============================================================ */
if (!function_exists('getPermissions')) {
    function getPermissions(string $role, string $module): array {
        if ($role === 'superadmin') {
            return ['can_access'=>1,'can_read'=>1,'can_create'=>1,'can_update'=>1,'can_delete'=>1,'can_export'=>1];
        }
        if (!$role || !$module) {
            return ['can_access'=>0,'can_read'=>0,'can_create'=>0,'can_update'=>0,'can_delete'=>0,'can_export'=>0];
        }
        return [
            'can_access' => (int) checkPermission($module, 'can_access'),
            'can_read'   => (int) checkPermission($module, 'can_read'),
            'can_create' => (int) checkPermission($module, 'can_create'),
            'can_update' => (int) checkPermission($module, 'can_update'),
            'can_delete' => (int) checkPermission($module, 'can_delete'),
            'can_export' => (int) checkPermission($module, 'can_export'),
        ];
    }
}

/* ============================================================
   GLOBAL UTILITY FUNCTIONS
   ============================================================ */

if (!function_exists('invCanExport')) {
    function invCanExport(string $module): bool {
        return checkPermission($module, 'can_export');
    }
}

if (!function_exists('getModulePermissions')) {
    function getModulePermissions(string $module): array {
        return [
            'can_access' => checkPermission($module, 'can_access'),
            'can_read'   => checkPermission($module, 'can_read'),
            'can_create' => checkPermission($module, 'can_create'),
            'can_update' => checkPermission($module, 'can_update'),
            'can_delete' => checkPermission($module, 'can_delete'),
            'can_export' => checkPermission($module, 'can_export'),
        ];
    }
}

if (!function_exists('getInventoryPermissions')) {
    function getInventoryPermissions(): array {
        $perms = [];
        foreach (['inventory_data','inventory_in','inventory_out','warehouse_location'] as $m) {
            $perms[$m] = getModulePermissions($m);
        }
        return $perms;
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void {
        if (!isLoggedIn()) redirect(url('index.php'));
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission(string $module, string $action = 'can_access'): void {
        if (!checkPermission($module, $action)) redirectNoAccess();
    }
}

if (!function_exists('isSuper')) {
    function isSuper(): bool {
        return isSuperAdmin();
    }
}

if (!function_exists('canShowExportSection')) {
    function canShowExportSection(string $module): bool {
        return checkPermission($module, 'can_export');
    }
}

/* ============================================================
   PURCHASES MODULE
   ============================================================ */

if (!function_exists('requirePurchaseCreate')) {
    function requirePurchaseCreate(): void { requireLogin(); requirePermission('purchases', 'can_create'); }
}
if (!function_exists('requirePurchaseUpdate')) {
    function requirePurchaseUpdate(): void { requireLogin(); requirePermission('purchases', 'can_update'); }
}
if (!function_exists('canCreatePurchase')) {
    function canCreatePurchase(): bool { return _dbCan('purchases', 'can_create'); }
}
if (!function_exists('canUpdatePurchase')) {
    function canUpdatePurchase(): bool { return _dbCan('purchases', 'can_update'); }
}
if (!function_exists('canDeletePurchase')) {
    function canDeletePurchase(): bool { return _dbCan('purchases', 'can_delete'); }
}
if (!function_exists('canExportPurchase')) {
    function canExportPurchase(): bool { return _dbCan('purchases', 'can_export'); }
}
if (!function_exists('canReadPurchase')) {
    function canReadPurchase(): bool { return _dbCan('purchases', 'can_read'); }
}
if (!function_exists('canOverallPriceRounding')) {
    function canOverallPriceRounding(): bool { return checkPermission('purchases', 'can_update'); }
}

/* ============================================================
   DREPORT MODULE
   ============================================================ */

/* requireYesterdayCashViewAccess() DIHAPUS DARI SINI (perbaikan 500).

   Fungsi ini juga didefinisikan di includes/manual_dreport_functions.php
   baris ~1096, dan versi DI SANA tidak berpagar function_exists. Selama
   global_permission.php tidak pernah dimuat di jalur arw_pos, itu tidak
   bermasalah. Setelah ronde prasyarat menambahkannya, keduanya bertemu dan
   PHP berhenti dengan 'Cannot redeclare' -> HTTP 500 di Cashier Report.

   Perbaikan pertama saya hanya mengatur URUTAN muat, dan itu rapuh: berkas
   baru mana pun yang memuat global_permission.php lebih dulu akan memicu
   kembali masalah yang sama. Menghapus salinan di sini menyelesaikannya
   secara permanen — tinggal satu definisi, tidak ada lagi ketergantungan
   urutan.

   Aman: satu-satunya pemanggil adalah arw_pos/manual_yesterdaycash_view.php
   baris 45, dan berkas itu memuat manual_dreport_functions.php. Versi yang
   dipakainya juga tidak berubah — versi modul itulah yang selama ini selalu
   menang, karena ia yang dimuat lebih dulu. */

/* ============================================================
   PAYROLL MODULE — PERMISSION HELPERS
   All payroll sub-module guards and boolean helpers.
   Superadmin always has full control.
   ============================================================ */

/* ---------- PAYROLL DASHBOARD ---------- */
if (!function_exists('requirePayrollDashboard')) {
    function requirePayrollDashboard(): void {
        requireLogin();
        if (!checkPermission('payroll_dashboard', 'can_access')) redirectNoAccess();
    }
}

/* ---------- EMPLOYEES ---------- */
if (!function_exists('requireEmployeesAccess')) {
    function requireEmployeesAccess(): void {
        requireLogin();
        if (!checkPermission('employees', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateEmployee')) {
    function canCreateEmployee(): bool { return checkPermission('employees', 'can_create'); }
}
if (!function_exists('canUpdateEmployee')) {
    function canUpdateEmployee(): bool { return checkPermission('employees', 'can_update'); }
}
if (!function_exists('canDeleteEmployee')) {
    function canDeleteEmployee(): bool { return checkPermission('employees', 'can_delete'); }
}
if (!function_exists('canExportEmployee')) {
    function canExportEmployee(): bool { return checkPermission('employees', 'can_export'); }
}

/* ---------- ATTENDANCE ---------- */
if (!function_exists('requireAttendanceAccess')) {
    function requireAttendanceAccess(): void {
        requireLogin();
        if (!checkPermission('attendance', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateAttendance')) {
    function canCreateAttendance(): bool { return checkPermission('attendance', 'can_create'); }
}
if (!function_exists('canUpdateAttendance')) {
    function canUpdateAttendance(): bool { return checkPermission('attendance', 'can_update'); }
}
if (!function_exists('canDeleteAttendance')) {
    function canDeleteAttendance(): bool { return checkPermission('attendance', 'can_delete'); }
}
if (!function_exists('canExportAttendance')) {
    function canExportAttendance(): bool { return checkPermission('attendance', 'can_export'); }
}

/* ---------- SHIFTS ---------- */
if (!function_exists('requireShiftsAccess')) {
    function requireShiftsAccess(): void {
        requireLogin();
        if (!checkPermission('shifts', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateShift')) {
    function canCreateShift(): bool { return checkPermission('shifts', 'can_create'); }
}
if (!function_exists('canUpdateShift')) {
    function canUpdateShift(): bool { return checkPermission('shifts', 'can_update'); }
}
if (!function_exists('canDeleteShift')) {
    function canDeleteShift(): bool { return checkPermission('shifts', 'can_delete'); }
}

/* ---------- SHIFT SCHEDULES (Multi-Shift) — NEW ---------- */
if (!function_exists('requireShiftSchedulesAccess')) {
    function requireShiftSchedulesAccess(): void {
        requireLogin();
        if (!checkPermission('shift_schedules', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canManageShiftSchedules')) {
    function canManageShiftSchedules(): bool {
        return checkPermission('shift_schedules', 'can_create') || checkPermission('shift_schedules', 'can_update');
    }
}
if (!function_exists('canReadShiftSchedules')) {
    function canReadShiftSchedules(): bool { return checkPermission('shift_schedules', 'can_read'); }
}
if (!function_exists('canDeleteShiftSchedule')) {
    function canDeleteShiftSchedule(): bool { return checkPermission('shift_schedules', 'can_delete'); }
}

/* ---------- HOLIDAYS — NEW ---------- */
if (!function_exists('requireHolidaysAccess')) {
    function requireHolidaysAccess(): void {
        requireLogin();
        if (!checkPermission('holidays', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateHoliday')) {
    function canCreateHoliday(): bool { return checkPermission('holidays', 'can_create'); }
}
if (!function_exists('canUpdateHoliday')) {
    function canUpdateHoliday(): bool { return checkPermission('holidays', 'can_update'); }
}
if (!function_exists('canDeleteHoliday')) {
    function canDeleteHoliday(): bool { return checkPermission('holidays', 'can_delete'); }
}
if (!function_exists('canManageHolidays')) {
    function canManageHolidays(): bool {
        return checkPermission('holidays', 'can_create') || checkPermission('holidays', 'can_update');
    }
}

/* ---------- PAYROLL PERIODS ---------- */
if (!function_exists('requirePayrollPeriodsAccess')) {
    function requirePayrollPeriodsAccess(): void {
        requireLogin();
        if (!checkPermission('payroll_periods', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreatePayrollPeriod')) {
    function canCreatePayrollPeriod(): bool { return checkPermission('payroll_periods', 'can_create'); }
}
if (!function_exists('canUpdatePayrollPeriod')) {
    function canUpdatePayrollPeriod(): bool { return checkPermission('payroll_periods', 'can_update'); }
}
if (!function_exists('canDeletePayrollPeriod')) {
    function canDeletePayrollPeriod(): bool { return checkPermission('payroll_periods', 'can_delete'); }
}

/* ---------- PAYROLL LIST ---------- */
if (!function_exists('requirePayrollListAccess')) {
    function requirePayrollListAccess(): void {
        requireLogin();
        if (!checkPermission('payroll_list', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canApprovePayroll')) {
    function canApprovePayroll(): bool { return checkPermission('payroll_list', 'can_update'); }
}
if (!function_exists('canExportPayroll')) {
    function canExportPayroll(): bool { return checkPermission('payroll_list', 'can_export'); }
}

/* ---------- LEAVE REQUESTS ---------- */
if (!function_exists('requireLeaveRequestsAccess')) {
    function requireLeaveRequestsAccess(): void {
        requireLogin();
        if (!checkPermission('leave_requests', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateLeaveRequest')) {
    function canCreateLeaveRequest(): bool { return checkPermission('leave_requests', 'can_create'); }
}
if (!function_exists('canApproveLeaveRequest')) {
    function canApproveLeaveRequest(): bool { return checkPermission('leave_requests', 'can_update'); }
}
if (!function_exists('canDeleteLeaveRequest')) {
    function canDeleteLeaveRequest(): bool { return checkPermission('leave_requests', 'can_delete'); }
}
if (!function_exists('canExportLeaveRequest')) {
    function canExportLeaveRequest(): bool { return checkPermission('leave_requests', 'can_export'); }
}

/* ---------- CASH ADVANCES ---------- */
if (!function_exists('requireCashAdvancesAccess')) {
    function requireCashAdvancesAccess(): void {
        requireLogin();
        if (!checkPermission('cash_advances', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateCashAdvance')) {
    function canCreateCashAdvance(): bool { return checkPermission('cash_advances', 'can_create'); }
}
if (!function_exists('canApproveCashAdvance')) {
    function canApproveCashAdvance(): bool { return checkPermission('cash_advances', 'can_update'); }
}
if (!function_exists('canDeleteCashAdvance')) {
    function canDeleteCashAdvance(): bool { return checkPermission('cash_advances', 'can_delete'); }
}
if (!function_exists('canExportCashAdvance')) {
    function canExportCashAdvance(): bool { return checkPermission('cash_advances', 'can_export'); }
}

/* ---------- DEPARTMENTS ---------- */
if (!function_exists('requireDepartmentsAccess')) {
    function requireDepartmentsAccess(): void {
        requireLogin();
        if (!checkPermission('departments', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateDepartment')) {
    function canCreateDepartment(): bool { return checkPermission('departments', 'can_create'); }
}
if (!function_exists('canUpdateDepartment')) {
    function canUpdateDepartment(): bool { return checkPermission('departments', 'can_update'); }
}
if (!function_exists('canDeleteDepartment')) {
    function canDeleteDepartment(): bool { return checkPermission('departments', 'can_delete'); }
}

/* ---------- POSITIONS ---------- */
if (!function_exists('requirePositionsAccess')) {
    function requirePositionsAccess(): void {
        requireLogin();
        if (!checkPermission('positions', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreatePosition')) {
    function canCreatePosition(): bool { return checkPermission('positions', 'can_create'); }
}
if (!function_exists('canUpdatePosition')) {
    function canUpdatePosition(): bool { return checkPermission('positions', 'can_update'); }
}
if (!function_exists('canDeletePosition')) {
    function canDeletePosition(): bool { return checkPermission('positions', 'can_delete'); }
}

/* ---------- RELIGIONS ---------- */
if (!function_exists('requireReligionsAccess')) {
    function requireReligionsAccess(): void {
        requireLogin();
        if (!checkPermission('religions', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canCreateReligion')) {
    function canCreateReligion(): bool { return checkPermission('religions', 'can_create'); }
}
if (!function_exists('canUpdateReligion')) {
    function canUpdateReligion(): bool { return checkPermission('religions', 'can_update'); }
}
if (!function_exists('canDeleteReligion')) {
    function canDeleteReligion(): bool { return checkPermission('religions', 'can_delete'); }
}

/* ---------- PAYROLL SETTINGS ---------- */
if (!function_exists('requirePayrollSettingsAccess')) {
    function requirePayrollSettingsAccess(): void {
        requireLogin();
        if (!checkPermission('payroll_settings', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canUpdatePayrollSettings')) {
    function canUpdatePayrollSettings(): bool { return checkPermission('payroll_settings', 'can_update'); }
}

/* ---------- LEGACY COMPAT: isPayrollAccess / requirePayrollAccess ---------- */
if (!function_exists('isPayrollAccess')) {
    function isPayrollAccess(): bool { return checkPermission('payroll', 'can_access'); }
}
if (!function_exists('requirePayrollAccess')) {
    function requirePayrollAccess(): void {
        requireLogin();
        if (!checkPermission('payroll', 'can_access')) redirectNoAccess();
    }
}

/* ============================================================
   INVENTORY PARENT GATE + IZIN MODUL INVENTORY (TERPUSAT)

   Satu-satunya tempat yang memutuskan boleh/tidaknya sebuah halaman
   atau tab Inventory dibuka. Seluruh halaman Inventory memanggil
   helper di bawah ini, sehingga aturannya tidak tersebar dan tidak
   ada dua tempat yang bisa berbeda.

   TIDAK ADA MODUL PERMISSION BARU. Seluruh modul yang dipakai di sini
   SUDAH ADA di tabel `permissions` dan sudah terdaftar di
   permGroupCatalog() grup 'inventory':

       inventory            (GERBANG INDUK, sudah ada)
       inventory_data
       inventory_in
       inventory_out
       warehouse_location
       stock_opname
       take_items
       in_items

   Karena itu tidak ada double permission: gerbang memakai modul
   'inventory' yang memang sudah berperan sebagai gerbang menu di
   sidebar, sub-modulnya memakai barisnya masing-masing.

   Dua lapis pemeriksaan, sama persis dengan pola Purchase:
     1. GERBANG : modul 'inventory' harus can_access. Ini yang membuat
                  URL langsung ikut tertutup ketika gerbang dimatikan.
     2. MODUL   : can_access modul yang bersangkutan.

   Keduanya dibaca lewat _dbCan() — LANGSUNG DARI DATABASE, bukan
   cache $_SESSION['_perms']. Cache itu hanya diisi sekali saat login
   dan tidak pernah dibatalkan oleh settings_permissions.php, sehingga
   pencabutan izin lewat jalur cache baru berlaku setelah user logout
   lalu login lagi. _dbPermissionRow() sendiri sudah punya cache statis
   per-request, jadi satu modul tetap hanya ditanyakan sekali per
   halaman — tidak ada tambahan beban query yang berarti.

   Superadmin SELALU full control: _dbCan() mengembalikan true di baris
   pertamanya tanpa menyentuh database sama sekali.
   ============================================================ */

/** Gerbang induk Inventory (modul 'inventory'). */
if (!function_exists('canAccessInventoryGate')) {
    function canAccessInventoryGate(): bool { return _dbCan('inventory', 'can_access'); }
}

/** Gerbang induk + izin satu sub-modul Inventory. */
if (!function_exists('inventoryModuleAllowed')) {
    function inventoryModuleAllowed(string $module): bool {
        if (isSuperAdmin()) return true;
        if (!isLoggedIn())  return false;
        if (!_dbCan('inventory', 'can_access')) return false;  // gerbang induk
        return _dbCan($module, 'can_access');                  // izin modul
    }
}

/** Aksi (can_read/can_create/...) sebuah sub-modul, tetap lewat gerbang. */
if (!function_exists('inventoryModuleCan')) {
    function inventoryModuleCan(string $module, string $action): bool {
        if (isSuperAdmin()) return true;
        if (!isLoggedIn())  return false;
        if (!_dbCan('inventory', 'can_access')) return false;  // gerbang induk
        return _dbCan($module, $action);
    }
}

/** Versi tegas: hentikan halaman bila tidak berhak. */
if (!function_exists('requireInventoryModule')) {
    function requireInventoryModule(string $module): void {
        requireLogin();
        if (!inventoryModuleAllowed($module)) redirectNoAccess();
    }
}

/**
 * Daftar tab hub Inventory beserta modul izinnya, DALAM URUTAN TAMPIL.
 * Dipakai inventory/inventory_gate.php untuk membangun tab DAN untuk
 * menentukan tab pengganti bila tab yang diminta tidak diizinkan,
 * sehingga daftar tab hanya ditulis di satu tempat.
 *
 * Urutan ini sengaja dibuat identik dengan urutan submenu di sidebar:
 *   Inventory Data → Items In → Items Out → Stock Opname → Location
 */
if (!function_exists('inventoryTabModules')) {
    function inventoryTabModules(): array {
        return [
            'inventory_data'     => 'inventory_data',
            'inventory_in'       => 'inventory_in',
            'inventory_out'      => 'inventory_out',
            'stock_opname'       => 'stock_opname',
            'warehouse_location' => 'warehouse_location',
        ];
    }
}

/** Ikon Bootstrap tiap tab — satu sumber kebenaran untuk hub & sidebar. */
if (!function_exists('inventoryTabIcons')) {
    function inventoryTabIcons(): array {
        return [
            'inventory_data'     => 'bi-box-seam',
            'inventory_in'       => 'bi-box-arrow-in-down',
            'inventory_out'      => 'bi-box-arrow-up',
            'stock_opname'       => 'bi-clipboard-check',
            'warehouse_location' => 'bi-geo-alt-fill',
        ];
    }
}

/** Kunci bahasa judul tiap tab — tidak ada string yang di-hardcode. */
if (!function_exists('inventoryTabLangKeys')) {
    function inventoryTabLangKeys(): array {
        return [
            'inventory_data'     => 'inventory_data',
            'inventory_in'       => 'inventory_in',
            'inventory_out'      => 'inventory_out',
            'stock_opname'       => 'stock_opname',
            'warehouse_location' => 'warehouse_location',
        ];
    }
}


/* ============================================================
   TAKE ITEMS MODULE

   Dipindahkan ke pembaca DB-langsung yang gate-aware
   (inventoryModuleCan) supaya SATU aturan berlaku untuk menu
   sidebar, halaman, dan seluruh endpoint AJAX-nya. Modul
   permission-nya TETAP 'take_items' yang sudah ada — tidak ada
   modul atau kolom baru, jadi tidak ada double permission.
   ============================================================ */

if (!function_exists('canAccessTakeItems')) {
    function canAccessTakeItems(): bool { return inventoryModuleAllowed('take_items'); }
}
if (!function_exists('requireTakeItemsAccess')) {
    function requireTakeItemsAccess(): void {
        requireLogin();
        if (!inventoryModuleAllowed('take_items')) redirectNoAccess();
    }
}
if (!function_exists('canReadTakeItems')) {
    function canReadTakeItems(): bool { return inventoryModuleCan('take_items', 'can_read'); }
}
if (!function_exists('canCreateTakeItems')) {
    function canCreateTakeItems(): bool { return inventoryModuleCan('take_items', 'can_create'); }
}
if (!function_exists('canUpdateTakeItems')) {
    function canUpdateTakeItems(): bool { return inventoryModuleCan('take_items', 'can_update'); }
}
if (!function_exists('canDeleteTakeItems')) {
    function canDeleteTakeItems(): bool { return inventoryModuleCan('take_items', 'can_delete'); }
}
if (!function_exists('canExportTakeItems')) {
    function canExportTakeItems(): bool { return inventoryModuleCan('take_items', 'can_export'); }
}


/* ============================================================
   ADDED ITEMS / ITEMS IN MODULE (halaman inventory/in_items.php)

   Modul permission-nya TETAP 'in_items' yang sudah ada di tabel
   `permissions` dan sudah terdaftar di permGroupCatalog() — tidak
   ada modul baru dan tidak ada permission ganda. Sebelumnya
   in_items.php membaca izinnya sendiri lewat _canAccess() (cache
   session); sekarang seluruh pemeriksaannya melewati helper di
   bawah ini, sama seperti Take Items.
   ============================================================ */

if (!function_exists('canAccessInItems')) {
    function canAccessInItems(): bool { return inventoryModuleAllowed('in_items'); }
}
if (!function_exists('requireInItemsAccess')) {
    function requireInItemsAccess(): void {
        requireLogin();
        if (!inventoryModuleAllowed('in_items')) redirectNoAccess();
    }
}
if (!function_exists('canReadInItems')) {
    function canReadInItems(): bool { return inventoryModuleCan('in_items', 'can_read'); }
}
if (!function_exists('canCreateInItems')) {
    function canCreateInItems(): bool { return inventoryModuleCan('in_items', 'can_create'); }
}
if (!function_exists('canUpdateInItems')) {
    function canUpdateInItems(): bool { return inventoryModuleCan('in_items', 'can_update'); }
}
if (!function_exists('canDeleteInItems')) {
    function canDeleteInItems(): bool { return inventoryModuleCan('in_items', 'can_delete'); }
}
if (!function_exists('canExportInItems')) {
    function canExportInItems(): bool { return inventoryModuleCan('in_items', 'can_export'); }
}


/* ============================================================
   INVENTORY DATA — BARCODE PRINT PERMISSION HELPER
   Reuses existing can_export permission — no separate column needed.
   Superadmin always has full control.
   ============================================================ */

/**
 * Boolean: apakah user boleh mencetak barcode dari inventory data?
 * Menggunakan can_export sebagai gate (tidak memerlukan kolom permission terpisah).
 * Superadmin selalu true.
 *
 * @return bool
 */
if (!function_exists('canPrintInventoryBarcode')) {
    function canPrintInventoryBarcode(): bool {
        return checkPermission('inventory_data', 'can_export');
    }
}

/* ============================================================
   STOCK OPNAME MODULE
   Menggunakan 6 kolom permission standar (can_access/read/create/
   update/delete/export) — tidak ada kolom baru. "Approve" dan "Post"
   memakai can_update, mengikuti persis pola yang sudah dipakai untuk
   canApproveLeaveRequest() / canApproveCashAdvance() / canApprovePayroll()
   di file ini (transisi status workflow = update dokumen yang sudah
   ada). "Cancel" memakai can_delete, selaras dengan "Delete" karena
   keduanya adalah aksi yang membatalkan/menghapus dokumen. Superadmin
   selalu full control lewat checkPermission() itu sendiri.
   ============================================================ */

/**
 * Ambil baris permission modul 'stock_opname' LANGSUNG dari database.
 *
 * ALASAN tidak memakai checkPermission():
 * checkPermission() membaca $_SESSION['_perms'], dan cache itu hanya diisi
 * SEKALI per sesi login (lihat loadPermissionsToSession() di config.php).
 * settings_permissions.php tidak pernah membersihkan cache tersebut, sehingga
 * permission yang baru disimpan admin TIDAK berlaku sampai user logout lalu
 * login lagi. Dengan membaca langsung ke database di sini, pengaturan di
 * Settings > Permissions langsung berlaku pada refresh halaman berikutnya.
 *
 * Prioritas sama persis dengan loadPermissionsToSession():
 *   1) default milik role  (user_id IS NULL AND role = ?)
 *   2) override milik user (user_id = ?)  -> menimpa nilai role
 *
 * Query hanya dijalankan SEKALI per request (disimpan di static), jadi
 * seluruh tombol pada satu halaman tetap hemat: 2 query, bukan per tombol.
 *
 * Hanya menyentuh modul 'stock_opname'. Modul lain tidak terpengaruh dan
 * tetap memakai mekanisme cache session yang sudah ada.
 */
if (!function_exists('_soPermissionRow')) {
    function _soPermissionRow(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        global $conn;

        $perm = [
            'can_access' => 0,
            'can_read'   => 0,
            'can_create' => 0,
            'can_update' => 0,
            'can_delete' => 0,
            'can_export' => 0,
        ];

        if (!isLoggedIn()) {
            $cache = $perm;
            return $cache;
        }

        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        $apply = function ($row) use (&$perm) {
            if (!$row) return;
            $perm['can_access'] = (int)$row['can_access'];
            $perm['can_read']   = (int)$row['can_read'];
            $perm['can_create'] = (int)$row['can_create'];
            $perm['can_update'] = (int)$row['can_update'];
            $perm['can_delete'] = (int)$row['can_delete'];
            $perm['can_export'] = (int)$row['can_export'];
        };

        // 1) Default milik role
        if ($role !== '') {
            $stmt = $conn->prepare(
                "SELECT can_access, can_read, can_create, can_update, can_delete,
                        COALESCE(can_export, 0) AS can_export
                 FROM permissions
                 WHERE user_id IS NULL AND role = ? AND module = 'stock_opname' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $apply($stmt->get_result()->fetch_assoc());
                $stmt->close();
            }
        }

        // 2) Override milik user (prioritas tertinggi)
        if ($uid > 0) {
            $stmt = $conn->prepare(
                "SELECT can_access, can_read, can_create, can_update, can_delete,
                        COALESCE(can_export, 0) AS can_export
                 FROM permissions
                 WHERE user_id = ? AND module = 'stock_opname' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $apply($stmt->get_result()->fetch_assoc());
                $stmt->close();
            }
        }

        $cache = $perm;
        return $cache;
    }
}

/**
 * Gerbang tunggal permission Stock Opname.
 * Superadmin SELALU true tanpa menyentuh database (full control).
 */
if (!function_exists('_soCan')) {
    function _soCan(string $action): bool {
        if (isSuperAdmin()) return true;
        if (!isLoggedIn())  return false;
        $perm = _soPermissionRow();
        return !empty($perm[$action]);
    }
}

if (!function_exists('requireStockOpnameAccess')) {
    function requireStockOpnameAccess(): void {
        requireLogin();
        if (!_soCan('can_access')) redirectNoAccess();
    }
}
if (!function_exists('canReadStockOpname')) {
    function canReadStockOpname(): bool { return _soCan('can_read'); }
}
if (!function_exists('canCreateStockOpname')) {
    function canCreateStockOpname(): bool { return _soCan('can_create'); }
}
if (!function_exists('canUpdateStockOpname')) {
    function canUpdateStockOpname(): bool { return _soCan('can_update'); }
}
if (!function_exists('canDeleteStockOpname')) {
    function canDeleteStockOpname(): bool { return _soCan('can_delete'); }
}
if (!function_exists('canExportStockOpname')) {
    function canExportStockOpname(): bool { return _soCan('can_export'); }
}
if (!function_exists('canApproveStockOpname')) {
    function canApproveStockOpname(): bool { return _soCan('can_update'); }
}
if (!function_exists('canPostStockOpname')) {
    function canPostStockOpname(): bool { return _soCan('can_update'); }
}
if (!function_exists('canCancelStockOpname')) {
    function canCancelStockOpname(): bool { return _soCan('can_delete'); }
}

/**
 * Cakupan data Stock Opname (Report Data Scope).
 *
 * true  = boleh melihat SELURUH dokumen Stock Opname (All Data / Full Data)
 * false = hanya boleh melihat dokumen yang dibuat sendiri (Own Data Only)
 *
 * Dibaca LANGSUNG dari kolom permissions.can_view_all, bukan lewat
 * checkPermission(), karena checkPermission() hanya menangani enam kolom
 * aksi standar (can_access/read/create/update/delete/export) dan cache
 * session-nya memang tidak memuat can_view_all.
 *
 * Prioritas persis seperti permission lain: baris milik user menimpa baris
 * milik role. Nilai NULL berarti "belum diatur" dan diperlakukan sebagai
 * All Data, sehingga perilaku user yang sudah ada sekarang tidak berubah
 * sampai admin benar-benar memilih Own Data Only di Settings > Permissions.
 *
 * Superadmin SELALU true tanpa melihat database.
 */
if (!function_exists('canViewAllStockOpname')) {
    function canViewAllStockOpname(): bool {
        global $conn;

        if (isSuperAdmin()) return true;
        if (!isLoggedIn())  return false;

        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        // 1) Override milik user (prioritas tertinggi)
        if ($uid > 0) {
            $stmt = $conn->prepare(
                "SELECT can_view_all FROM permissions
                 WHERE user_id = ? AND module = 'stock_opname' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && $row['can_view_all'] !== null) {
                    return ((int)$row['can_view_all'] === 1);
                }
            }
        }

        // 2) Default milik role
        if ($role !== '') {
            $stmt = $conn->prepare(
                "SELECT can_view_all FROM permissions
                 WHERE user_id IS NULL AND role = ? AND module = 'stock_opname' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && $row['can_view_all'] !== null) {
                    return ((int)$row['can_view_all'] === 1);
                }
            }
        }

        // 3) Belum diatur → All Data
        return true;
    }
}

/* ============================================================
   GOODS RECEIPT MODULE
   Modul BARU: 'goods_receipt'.

   Memakai 6 kolom permission standar yang sudah ada
   (can_access / can_read / can_create / can_update / can_delete /
   can_export) — TIDAK ada kolom permission baru dan TIDAK ada
   permission system kedua.

   Pemetaan aksi ke permission existing:
     - Cancel Goods Receipt  -> can_delete
       (membatalkan dokumen = aksi yang setara dengan menghapus,
        persis seperti canCancelStockOpname() di bawah)
     - Semua aksi lain       -> kolom dengan nama yang sama

   ALASAN membaca LANGSUNG dari database (bukan checkPermission()):
   checkPermission() membaca $_SESSION['_perms'], dan cache itu hanya
   diisi SEKALI saat login oleh loadPermissionsToSession() di
   config.php berdasarkan daftar _allModules(). Modul 'goods_receipt'
   adalah modul baru, sehingga:
     1) sesi user yang sedang berjalan sama sekali tidak memuatnya, dan
     2) permission yang baru disimpan admin di Settings > Permissions
        tidak akan berlaku sampai user logout lalu login lagi.
   Dengan membaca langsung ke database di sini — persis pendekatan yang
   sudah dipakai modul 'stock_opname' pada file yang sama — pengaturan
   permission langsung berlaku pada request berikutnya, tanpa perlu
   mengubah config.php sama sekali.

   Query hanya dijalankan SEKALI per request (disimpan di static), jadi
   seluruh tombol pada satu halaman tetap hemat: maksimal 2 query.

   Hanya menyentuh modul 'goods_receipt'. Modul lain tidak terpengaruh
   dan tetap memakai mekanisme cache session yang sudah ada.
   ============================================================ */

/**
 * Ambil baris permission modul 'goods_receipt' LANGSUNG dari database.
 *
 * Prioritas sama persis dengan loadPermissionsToSession():
 *   1) default milik role  (user_id IS NULL AND role = ?)
 *   2) override milik user (user_id = ?)  -> menimpa nilai role
 */
if (!function_exists('_grPermissionRow')) {
    function _grPermissionRow(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        global $conn;

        $perm = [
            'can_access' => 0,
            'can_read'   => 0,
            'can_create' => 0,
            'can_update' => 0,
            'can_delete' => 0,
            'can_export' => 0,
        ];

        if (!isLoggedIn()) {
            $cache = $perm;
            return $cache;
        }

        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        $apply = function ($row) use (&$perm) {
            if (!$row) return;
            $perm['can_access'] = (int)$row['can_access'];
            $perm['can_read']   = (int)$row['can_read'];
            $perm['can_create'] = (int)$row['can_create'];
            $perm['can_update'] = (int)$row['can_update'];
            $perm['can_delete'] = (int)$row['can_delete'];
            $perm['can_export'] = (int)$row['can_export'];
        };

        // 1) Default milik role
        if ($role !== '') {
            $stmt = $conn->prepare(
                "SELECT can_access, can_read, can_create, can_update, can_delete,
                        COALESCE(can_export, 0) AS can_export
                 FROM permissions
                 WHERE user_id IS NULL AND role = ? AND module = 'goods_receipt' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $apply($stmt->get_result()->fetch_assoc());
                $stmt->close();
            }
        }

        // 2) Override milik user (prioritas tertinggi)
        if ($uid > 0) {
            $stmt = $conn->prepare(
                "SELECT can_access, can_read, can_create, can_update, can_delete,
                        COALESCE(can_export, 0) AS can_export
                 FROM permissions
                 WHERE user_id = ? AND module = 'goods_receipt' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $apply($stmt->get_result()->fetch_assoc());
                $stmt->close();
            }
        }

        $cache = $perm;
        return $cache;
    }
}

/**
 * Gerbang tunggal permission Goods Receipt.
 * Superadmin SELALU true tanpa menyentuh database (full control).
 */
if (!function_exists('_grCan')) {
    function _grCan(string $action): bool {
        // Diarahkan ke pembaca generik _dbCan() agar Goods Receipt memakai
        // sumber logika yang sama dengan Purchase Order & Purchase Invoice.
        // Perilakunya identik dengan _grPermissionRow() sebelumnya.
        return _dbCan('goods_receipt', $action);
    }
}

if (!function_exists('requireGoodsReceiptAccess')) {
    function requireGoodsReceiptAccess(): void {
        requireLogin();
        if (!_grCan('can_access')) redirectNoAccess();
    }
}
if (!function_exists('canAccessGoodsReceipt')) {
    function canAccessGoodsReceipt(): bool { return _grCan('can_access'); }
}
if (!function_exists('canReadGoodsReceipt')) {
    function canReadGoodsReceipt(): bool { return _grCan('can_read'); }
}
if (!function_exists('canCreateGoodsReceipt')) {
    function canCreateGoodsReceipt(): bool { return _grCan('can_create'); }
}
if (!function_exists('canUpdateGoodsReceipt')) {
    function canUpdateGoodsReceipt(): bool { return _grCan('can_update'); }
}
if (!function_exists('canDeleteGoodsReceipt')) {
    function canDeleteGoodsReceipt(): bool { return _grCan('can_delete'); }
}
if (!function_exists('canExportGoodsReceipt')) {
    function canExportGoodsReceipt(): bool { return _grCan('can_export'); }
}
/** Membatalkan Goods Receipt = aksi setara menghapus dokumen. */
if (!function_exists('canCancelGoodsReceipt')) {
    function canCancelGoodsReceipt(): bool { return _grCan('can_delete'); }
}

/* ============================================================
   PURCHASE RETURN MODULE (FLOW BARU)

   TIDAK membuat modul permission baru.
   Purchase Return memakai modul permission EXISTING 'returns' yang
   sudah terdaftar di config.php (_allModules()), sudah ada barisnya di
   tabel permissions, dan sudah bisa diatur dari Settings > Permissions.

   Pemetaan aksi:
     - Approve / Reject / Process Return -> can_update
       (transisi status = mengubah dokumen yang sudah ada, mengikuti
        pola canApproveLeaveRequest() / canApproveCashAdvance() /
        canApprovePayroll() di file ini)
     - Cancel Return                     -> can_delete
       (selaras dengan canCancelStockOpname())
   Superadmin selalu full control lewat checkPermission() itu sendiri.
   ============================================================ */

if (!function_exists('requirePurchaseReturnAccess')) {
    function requirePurchaseReturnAccess(): void {
        requireLogin();
        if (!checkPermission('returns', 'can_access')) redirectNoAccess();
    }
}
if (!function_exists('canAccessPurchaseReturn')) {
    function canAccessPurchaseReturn(): bool { return checkPermission('returns', 'can_access'); }
}
if (!function_exists('canReadPurchaseReturn')) {
    function canReadPurchaseReturn(): bool { return checkPermission('returns', 'can_read'); }
}
if (!function_exists('canCreatePurchaseReturn')) {
    function canCreatePurchaseReturn(): bool { return checkPermission('returns', 'can_create'); }
}
if (!function_exists('canUpdatePurchaseReturn')) {
    function canUpdatePurchaseReturn(): bool { return checkPermission('returns', 'can_update'); }
}
if (!function_exists('canDeletePurchaseReturn')) {
    function canDeletePurchaseReturn(): bool { return checkPermission('returns', 'can_delete'); }
}
if (!function_exists('canExportPurchaseReturn')) {
    function canExportPurchaseReturn(): bool { return checkPermission('returns', 'can_export'); }
}
if (!function_exists('canApprovePurchaseReturn')) {
    function canApprovePurchaseReturn(): bool { return checkPermission('returns', 'can_update'); }
}
if (!function_exists('canCancelPurchaseReturn')) {
    function canCancelPurchaseReturn(): bool { return checkPermission('returns', 'can_delete'); }
}


/* ============================================================
   REPORT DATA SCOPE — Purchase Order / Goods Receipt / Purchase Return

   Memakai kolom EXISTING `permissions.can_view_all` (3-state), persis
   mekanisme yang sudah dipakai modul 'dreport' dan 'stock_opname':

       NULL = belum diatur  -> All Data  (perilaku lama TIDAK berubah)
       1    = All Data (Full Data)
       0    = Own Data Only (hanya dokumen yang dibuat sendiri)

   Tidak ada kolom baru, tidak ada tabel baru, tidak ada permission
   system kedua. Superadmin SELALU All Data tanpa menyentuh database.

   Prioritas sama dengan permission lain:
       baris milik user  ->  menimpa  ->  baris milik role  ->  default All Data
   ============================================================ */

/**
 * Pembaca generik kolom can_view_all untuk satu modul.
 *
 * Dibaca LANGSUNG dari database karena checkPermission() hanya menangani
 * enam kolom aksi standar dan cache session-nya memang tidak memuat
 * can_view_all. Hasilnya di-cache per-request per-modul sehingga satu
 * halaman maksimal 2 query per modul, berapa pun tombol yang dirender.
 */
if (!function_exists('_permCanViewAll')) {
    function _permCanViewAll(string $module): bool {
        static $cache = [];
        if (array_key_exists($module, $cache)) return $cache[$module];

        global $conn;

        if (isSuperAdmin()) return $cache[$module] = true;
        if (!isLoggedIn())  return $cache[$module] = false;

        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        // 1) Override milik user (prioritas tertinggi)
        if ($uid > 0) {
            $stmt = $conn->prepare(
                "SELECT can_view_all FROM permissions
                 WHERE user_id = ? AND module = ? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('is', $uid, $module);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && $row['can_view_all'] !== null) {
                    return $cache[$module] = ((int)$row['can_view_all'] === 1);
                }
            }
        }

        // 2) Default milik role
        if ($role !== '') {
            $stmt = $conn->prepare(
                "SELECT can_view_all FROM permissions
                 WHERE user_id IS NULL AND role = ? AND module = ? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ss', $role, $module);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && $row['can_view_all'] !== null) {
                    return $cache[$module] = ((int)$row['can_view_all'] === 1);
                }
            }
        }

        // 3) Belum diatur → All Data (backward compatible)
        return $cache[$module] = true;
    }
}

if (!function_exists('canViewAllPurchaseOrder')) {
    function canViewAllPurchaseOrder(): bool { return _permCanViewAll('purchase_orders'); }
}
if (!function_exists('canViewAllGoodsReceipt')) {
    function canViewAllGoodsReceipt(): bool { return _permCanViewAll('goods_receipt'); }
}
if (!function_exists('canViewAllPurchaseReturn')) {
    function canViewAllPurchaseReturn(): bool { return _permCanViewAll('returns'); }
}

/**
 * ID pemilik yang harus dipakai sebagai filter query.
 *
 *   null    = All Data  -> jangan tambahkan filter apa pun
 *   int > 0 = Own Data Only -> tambahkan WHERE created_by = <id>
 *
 * Dipakai oleh po_getList(), gr_getList(), pret_getList(), dan seluruh
 * pengecekan record-level, supaya aturan cakupan data hanya ditulis di
 * SATU tempat dan tidak pernah berbeda antara daftar, halaman detail,
 * dan export.
 */
if (!function_exists('permScopeOwnerId')) {
    function permScopeOwnerId(bool $canViewAll): ?int {
        if ($canViewAll) return null;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        return $uid > 0 ? $uid : -1;   // -1 = tidak akan pernah cocok (fail-closed)
    }
}

/** Boleh melihat satu dokumen tertentu berdasarkan pemiliknya. */
if (!function_exists('permScopeAllowsRecord')) {
    function permScopeAllowsRecord(bool $canViewAll, $recordOwnerId): bool {
        if ($canViewAll) return true;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        return $uid > 0 && (int)$recordOwnerId === $uid;
    }
}

/* ============================================================
   PEMBACA PERMISSION — SEKARANG HANYA PENERUS

   RIWAYAT
   Blok ini dulu berisi implementasi kedua: ia membaca tabel
   `permissions` langsung untuk menghindari cache $_SESSION['_perms']
   yang tidak pernah di-invalidate. Itu memang menyelesaikan masalah
   izin basi, tetapi melahirkan dua jalur pemeriksaan yang berjalan
   berdampingan — checkPermission() (cache sesi) dan _dbCan() (DB
   langsung) — sehingga satu modul bisa dijawab berbeda tergantung
   fungsi mana yang kebetulan dipakai halaman itu.

   TAHAP 0.1
   Cache sesi DIHAPUS. Sumber kebenaran tunggal sekarang ada di
   includes/config.php:

       _bltx_perm_map()   memuat SELURUH modul milik user dalam
                          2 query borongan, sekali per request
       _bltx_perm_row()   mengambil satu baris dari peta itu

   checkPermission(), _canAccess(), dan _dbCan() semuanya bermuara
   ke sana. Nama-nama lama dipertahankan supaya folder payroll/
   (94 + 17 + 1 pemanggil) tidak perlu diedit sama sekali.

   Superadmin tetap SELALU true tanpa menyentuh database.

   BIAYA QUERY TURUN: versi lama menembak 2 query PER MODUL, jadi
   halaman yang memeriksa 8 modul memakai ~16 query. Sekarang tetap
   2 query untuk seluruh halaman.
   ============================================================ */

if (!function_exists('_dbPermissionRow')) {
    /**
     * TAHAP 0.1 — kini hanya MENERUSKAN ke mesin tunggal di config.php.
     *
     * Implementasi lama (2 query per modul, cache static per modul) sudah
     * dipindahkan ke _bltx_perm_map() / _bltx_perm_row() di config.php, yang
     * memuat SELURUH modul milik user dalam 2 query borongan sekali per
     * request. Nama _dbPermissionRow() dipertahankan supaya seluruh pemanggil
     * yang sudah ada — termasuk 1 pemanggil di folder payroll/ yang tidak
     * boleh diedit — tetap berfungsi tanpa perubahan.
     *
     * Bentuk kembaliannya sama persis: array enam kunci can_*.
     */
    function _dbPermissionRow(string $module): array {
        if (function_exists('_bltx_perm_row')) {
            return _bltx_perm_row($module);
        }
        // Jaring pengaman: config.php belum termuat (tidak terjadi pada alur
        // normal — setiap halaman memuat config.php lebih dulu). Menolak akses
        // lebih aman daripada menebak.
        return [
            'can_access' => 0, 'can_read'   => 0, 'can_create' => 0,
            'can_update' => 0, 'can_delete' => 0, 'can_export' => 0,
        ];
    }
}

/** Gerbang tunggal. Superadmin SELALU true tanpa menyentuh database. */
if (!function_exists('_dbCan')) {
    function _dbCan(string $module, string $action): bool {
        if (isSuperAdmin()) return true;
        if (!isLoggedIn())  return false;
        $perm = _dbPermissionRow($module);
        return !empty($perm[$action]);
    }
}

/* ── Purchase Invoice (modul 'purchases') : versi database-langsung ──────
 * Dipakai oleh purchases.php, purchases_invoice.php, dan header.php
 * menggantikan _canAccess('purchases', ...) yang membaca cache session. */
if (!function_exists('canAccessPurchase')) {
    function canAccessPurchase(): bool { return _dbCan('purchases', 'can_access'); }
}

/* ── Purchase Order (modul 'purchase_orders') : versi database-langsung ──
 * Wrapper canXxxPurchaseOrder() ada di includes/purchase_orders_function.php
 * dan sudah diarahkan ke _dbCan(). Fungsi ini melengkapinya untuk header.php
 * yang dimuat sebelum purchase_orders_function.php. */
if (!function_exists('canAccessPurchaseOrderDb')) {
    function canAccessPurchaseOrderDb(): bool { return _dbCan('purchase_orders', 'can_access'); }
}


/* ── PURCHASE PARENT GATE (modul 'purchase', TUNGGAL) ────────────────────
 * Gerbang induk untuk SELURUH modul Purchase, sejajar dengan modul
 * 'inventory', 'payroll', dan 'settings'.
 *
 * PENTING — kenapa 'purchase' dan bukan 'purchases':
 *   Modul 'purchases' (JAMAK) SUDAH DIPAKAI halaman Purchase Invoice
 *   (canAccessPurchase() tepat di atas). Memakainya lagi sebagai gate akan
 *   membuat satu baris permission mengendalikan dua hal berbeda — persis
 *   double permission yang harus dihindari. Karena itu gate memakai nama
 *   'purchase' yang belum terpakai. Kolom permissions.module bertipe
 *   varchar(50), jadi TIDAK diperlukan perubahan struktur database.
 *
 * Gate OFF => seluruh menu dan halaman Purchase tertutup, apa pun isi
 * permission sub-modulnya. Superadmin selalu lolos karena _dbCan()
 * mengembalikan true di baris pertamanya.
 *
 * CATATAN PERILAKU AWAL: selama baris 'purchase' belum pernah disimpan di
 * tabel permissions, _dbPermissionRow() mengembalikan can_access = 0.
 * Artinya gate berstatus TERTUTUP sampai admin menyalakannya di
 * Settings > Permissions. */
if (!function_exists('canAccessPurchaseGate')) {
    function canAccessPurchaseGate(): bool { return _dbCan('purchase', 'can_access'); }
}


/* ── PENEGAK GERBANG + IZIN MODUL PURCHASE (terpusat) ────────────────────
 * Satu-satunya tempat yang memutuskan boleh/tidaknya sebuah halaman atau tab
 * Purchase dibuka. Seluruh halaman Purchase memanggil helper ini, sehingga
 * aturannya tidak tersebar dan tidak ada dua tempat yang bisa berbeda.
 *
 * TIDAK membuat izin baru — hanya membungkus modul yang SUDAH ADA
 * ('purchases', 'purchase_orders', 'goods_receipt', 'returns', 'suppliers',
 * 'categories', 'units') di belakang gerbang 'purchase'.
 *
 * Dua lapis pemeriksaan:
 *   1. GERBANG  : modul 'purchase' harus can_access. Ini yang membuat URL
 *                 langsung ikut tertutup saat gerbang dimatikan — sebelumnya
 *                 gerbang hanya diperiksa di hub, sehingga mengetik URL
 *                 halaman detail tetap tembus.
 *   2. MODUL    : can_access modul yang bersangkutan.
 *
 * Keduanya dibaca lewat _dbCan() — LANGSUNG DARI DATABASE, bukan cache
 * $_SESSION['_perms']. Cache itu hanya diisi sekali saat login dan tidak
 * pernah dibatalkan oleh settings_permissions.php, sehingga pencabutan izin
 * lewat jalur cache baru berlaku setelah user logout lalu login lagi.
 * _dbPermissionRow() sendiri sudah punya cache statis per-request, jadi satu
 * modul tetap hanya ditanyakan sekali per halaman.
 *
 * Superadmin selalu lolos: _dbCan() mengembalikan true di baris pertamanya.
 */
if (!function_exists('purchaseModuleAllowed')) {
    function purchaseModuleAllowed(string $module): bool {
        if (isSuperAdmin()) return true;
        if (!isLoggedIn())  return false;
        if (!_dbCan('purchase', 'can_access')) return false;   // gerbang induk
        return _dbCan($module, 'can_access');                  // izin modul
    }
}

/** Versi tegas: hentikan halaman bila tidak berhak. */
if (!function_exists('requirePurchaseModule')) {
    function requirePurchaseModule(string $module): void {
        requireLogin();
        if (!purchaseModuleAllowed($module)) redirectNoAccess();
    }
}

/**
 * Daftar tab hub beserta modul izinnya, dalam urutan tampil.
 * Dipakai pr_dahsboard_gate.php untuk membangun tab DAN untuk menentukan
 * tab pengganti bila tab yang diminta tidak diizinkan.
 */
if (!function_exists('purchaseTabModules')) {
    function purchaseTabModules(): array {
        return [
            'order'         => 'purchase_orders',
            'goods_receipt' => 'goods_receipt',
            'invoice'       => 'purchases',
            'returns'       => 'returns',
            'suppliers'     => 'suppliers',
            'categories'    => 'categories',
            'units'         => 'units',
        ];
    }
}


/* ============================================================
   SETTINGS > PURCHASE  (halaman settings/purchase_settings.php)

   Modul: 'settings_purchase'.

   Memakai kolom permission standar yang sudah ada (can_access /
   can_read / can_update) — TIDAK ada kolom permission baru dan TIDAK
   ada permission system kedua. Aksi yang dipakai halaman ini hanya
   can_access (membuka tab & submenu) dan can_update (menyimpan).

   Dibaca lewat _dbCan() di atas, yaitu LANGSUNG dari tabel
   `permissions`, bukan dari cache $_SESSION['_perms']. Dengan begitu
   permission yang baru diatur admin langsung berlaku pada request
   berikutnya tanpa user harus logout lalu login lagi.

   Superadmin SELALU full control tanpa menyentuh database.
   ============================================================ */

if (!function_exists('canAccessSettingsPurchase')) {
    function canAccessSettingsPurchase(): bool { return _dbCan('settings_purchase', 'can_access'); }
}
if (!function_exists('canReadSettingsPurchase')) {
    function canReadSettingsPurchase(): bool { return _dbCan('settings_purchase', 'can_read'); }
}
if (!function_exists('canUpdateSettingsPurchase')) {
    function canUpdateSettingsPurchase(): bool { return _dbCan('settings_purchase', 'can_update'); }
}

/* ============================================================
   MODUL 'settings_backup' — Settings > Backup Database

   Sama seperti 'settings_purchase' di atas: dibaca LANGSUNG dari
   tabel `permissions` lewat _dbCan(), bukan dari cache session,
   supaya perubahan izin oleh superadmin langsung berlaku tanpa
   user harus logout/login ulang. Superadmin selalu full control.
   ============================================================ */

if (!function_exists('canAccessSettingsBackup')) {
    function canAccessSettingsBackup(): bool { return _dbCan('settings_backup', 'can_access'); }
}

/* ============================================================
   PERMISSION DELEGATION — "PERMISSION KHUSUS"

   TUJUAN
   Memberi wewenang mengelola permission (halaman Settings >
   Users > Permissions) kepada akun NON-superadmin, tetapi
   dengan tiga lapis pagar pembatas sekaligus:

     1. NON-ESKALASI  — delegate hanya boleh menyalakan/mematikan
        aksi yang delegate itu SENDIRI miliki. Aksi yang tidak
        dia punya terkunci pada nilai lamanya.
     2. BATAS GRUP    — delegate hanya boleh menyentuh grup modul
        yang diizinkan superadmin (mis. hanya Purchasing &
        Inventory). Grup lain terkunci.
     3. BATAS TARGET  — delegate hanya boleh mengelola akun yang
        role-nya DI BAWAH role delegate (opsional), tidak pernah
        superadmin, dan tidak pernah dirinya sendiri.

   Di samping ketiganya berlaku dua invariant keras yang TIDAK
   bisa dimatikan lewat pengaturan apa pun:
     - delegate TIDAK PERNAH boleh menyentuh baris module='users'
       milik siapa pun (mencegah dia mengangkat delegate baru
       atau menaikkan wewenangnya sendiri);
     - superadmin SELALU full control tanpa menyentuh database.

   PENYIMPANAN
   Tiga kolom baru pada tabel `permissions`, HANYA dibaca pada
   baris module='users':
     perm_delegate     TINYINT(1)   0 = bukan delegate (DEFAULT)
     perm_scope_groups VARCHAR(255) '' = semua grup (DEFAULT)
     perm_target_rank  TINYINT(1)   0 = semua non-superadmin (DEFAULT)

   Seluruh DEFAULT-nya berarti "perilaku persis seperti sebelum
   fitur ini ada", sehingga 1.486 baris permission yang sudah ada
   TIDAK berubah perilakunya sama sekali setelah migrasi.

   Prioritas baca sama persis dengan seluruh permission lain:
       baris role (user_id IS NULL)  ->  ditimpa  ->  baris user
   ============================================================ */

/** Enam kolom aksi standar — satu-satunya sumber kebenaran. */
if (!function_exists('permActionKeys')) {
    function permActionKeys(): array {
        return ['can_access', 'can_read', 'can_create', 'can_update', 'can_delete', 'can_export'];
    }
}

/**
 * Peringkat role. Angka KECIL = wewenang LEBIH TINGGI.
 *
 * Hierarki ini DITENTUKAN OLEH PEMILIK SISTEM, bukan diturunkan dari
 * urutan enum di tabel `users`. Beberapa role sengaja berbagi peringkat
 * yang sama karena dianggap setara:
 *
 *     0  superadmin
 *     1  manager
 *     2  supervisor
 *     3  hr, admin          <- setara
 *     4  user, cashier      <- setara
 *
 * Dipakai HANYA oleh permCanManageTargetUser() saat switch
 * "Hanya akun dengan role di bawahnya" (perm_target_rank) aktif.
 * Aturannya "DI BAWAH", bukan "setara atau di bawah": delegate tidak
 * boleh mengelola akun yang peringkatnya sama dengan dirinya. Jadi
 * delegate ber-role 'user' tidak bisa mengelola 'cashier' (sama-sama 4),
 * dan delegate ber-role 'hr' tidak bisa mengelola 'admin' (sama-sama 3).
 * Kalau perilaku setara-boleh yang diinginkan, cukup ubah operator '<='
 * menjadi '<' di permCanManageTargetUser().
 */
if (!function_exists('permRoleRank')) {
    function permRoleRank(string $role): int {
        $rank = [
            'superadmin' => 0,
            'manager'    => 1,
            'supervisor' => 2,
            'hr'         => 3,
            'admin'      => 3,
            'user'       => 4,
            'cashier'    => 4,
        ];
        return $rank[$role] ?? 99;
    }
}

/**
 * Katalog grup modul — menggantikan empat array modul yang dulu
 * di-hardcode terpisah di settings_permissions.php. Urutan grup
 * dan urutan modul di dalamnya SENGAJA dibuat identik dengan
 * tampilan lama supaya susunan pohon permission tidak berubah.
 *
 * 'scope' = modul yang punya baris "Report Data Scope"
 * (kolom can_view_all 3-state). Isinya persis lima modul yang
 * sudah memakainya sekarang — tidak ada penambahan.
 */
if (!function_exists('permGroupCatalog')) {
    function permGroupCatalog(): array {
        return [
            'main' => [
                'icon' => 'bi-speedometer2',
                'lang' => 'perm_group_main',
                'modules' => [
                    'dashboard' => 'perm_mod_dashboard',
                ],
            ],
            'purchasing' => [
                'icon' => 'bi-cart3',
                'lang' => 'perm_group_purchasing',
                'modules' => [
                    'purchase'        => 'perm_mod_purchase',
                    'purchase_orders' => 'perm_mod_purchase_orders',
                    'goods_receipt'   => 'perm_mod_goods_receipt',
                    'purchases'       => 'perm_mod_purchases',
                    'returns'         => 'perm_mod_returns',
                    'suppliers'       => 'perm_mod_suppliers',
                    'categories'      => 'perm_mod_categories',
                    'units'           => 'perm_mod_units',
                ],
            ],
            'inventory' => [
                'icon' => 'bi-box-seam',
                'lang' => 'perm_group_inventory',
                'modules' => [
                    'inventory'          => 'perm_mod_inventory',
                    'inventory_data'     => 'perm_mod_inventory_data',
                    'inventory_in'       => 'perm_mod_inventory_in',
                    'inventory_out'      => 'perm_mod_inventory_out',
                    'warehouse_location' => 'perm_mod_warehouse_location',
                    'take_items'         => 'perm_mod_take_items',
                    'in_items'           => 'perm_mod_in_items',
                    'stock_opname'       => 'perm_mod_stock_opname',
                ],
            ],
            'hr' => [
                'icon' => 'bi-people',
                'lang' => 'perm_group_hr',
                'modules' => [
                    'payroll'           => 'perm_mod_payroll',
                    'payroll_dashboard' => 'perm_mod_payroll_dashboard',
                    'employees'         => 'perm_mod_employees',
                    'attendance'        => 'perm_mod_attendance',
                    'shifts'            => 'perm_mod_shifts',
                    'shift_schedules'   => 'perm_mod_shift_schedules',
                    'holidays'          => 'perm_mod_holidays',
                    'payroll_periods'   => 'perm_mod_payroll_periods',
                    'payroll_list'      => 'perm_mod_payroll_list',
                    'leave_requests'    => 'perm_mod_leave_requests',
                    'cash_advances'     => 'perm_mod_cash_advances',
                    'kpi'               => 'perm_mod_kpi',
                    'departments'       => 'perm_mod_departments',
                    'positions'         => 'perm_mod_positions',
                    'religions'         => 'perm_mod_religions',
                    'payroll_settings'  => 'perm_mod_payroll_settings',
                ],
            ],
            'cashier' => [
                'icon' => 'bi-receipt',
                'lang' => 'perm_group_cashier',
                'modules' => [
                    'dreport' => 'perm_mod_dreport',
                ],
            ],
            'settings' => [
                'icon' => 'bi-gear',
                'lang' => 'perm_group_settings',
                'modules' => [
                    'settings'          => 'perm_mod_settings',
                    'settings_general'  => 'perm_mod_settings_general',
                    'settings_purchase' => 'perm_mod_settings_purchase',
                    'settings_currency' => 'perm_mod_settings_currency',
                    'settings_news'     => 'perm_mod_settings_news',
                    'settings_activity' => 'perm_mod_settings_activity',
                    'users'             => 'perm_mod_users',
                    'settings_backup'   => 'perm_mod_settings_backup',
                ],
            ],
        ];
    }
}

/** Modul yang menampilkan baris cakupan data (can_view_all). */
if (!function_exists('permScopeModules')) {
    function permScopeModules(): array {
        return ['purchase_orders', 'goods_receipt', 'returns', 'stock_opname', 'dreport'];
    }
}

/** Peta datar module => group key, dihitung sekali per request. */
if (!function_exists('permModuleGroupMap')) {
    function permModuleGroupMap(): array {
        static $map = null;
        if ($map !== null) return $map;
        $map = [];
        foreach (permGroupCatalog() as $gkey => $g) {
            foreach ($g['modules'] as $mod => $langKey) {
                $map[$mod] = $gkey;
            }
        }
        return $map;
    }
}

/** Grup sebuah modul; modul asing (belum dikatalogkan) => 'other'. */
if (!function_exists('permGroupOfModule')) {
    function permGroupOfModule(string $module): string {
        $map = permModuleGroupMap();
        return $map[$module] ?? 'other';
    }
}

/**
 * Daftar modul APA ADANYA DARI DATABASE.
 *
 * Halaman permission tidak lagi memakai daftar modul hardcode.
 * Modul diambil dari `SELECT DISTINCT module FROM permissions`
 * lalu digabung dengan katalog, sehingga:
 *   - modul yang ada di katalog tapi belum punya baris DB tetap
 *     tampil (bisa diatur untuk pertama kali);
 *   - modul yang ada di DB tapi belum dikatalogkan tetap tampil
 *     di grup "Other" dan tidak pernah hilang diam-diam.
 */
if (!function_exists('permModulesFromDatabase')) {
    function permModulesFromDatabase(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        global $conn;
        $found = [];

        $res = $conn->query("SELECT DISTINCT module FROM permissions ORDER BY module ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $m = (string)$r['module'];
                if ($m !== '') $found[$m] = true;
            }
            $res->free();
        }

        $ordered = [];
        foreach (permGroupCatalog() as $g) {
            foreach ($g['modules'] as $mod => $langKey) {
                $ordered[] = $mod;
                unset($found[$mod]);
            }
        }
        foreach (array_keys($found) as $extra) {
            $ordered[] = $extra;
        }

        return $cache = $ordered;
    }
}

/**
 * Pohon grup + modul siap render, label sudah diterjemahkan.
 * $LANG dikirim sebagai argumen supaya fungsi ini tetap murni
 * dan tidak bergantung pada variabel global halaman.
 */
if (!function_exists('permBuildTree')) {
    function permBuildTree(array $LANG): array {
        $catalog = permGroupCatalog();
        $scope   = array_flip(permScopeModules());
        $all     = permModulesFromDatabase();
        $known   = permModuleGroupMap();

        $tree = [];
        foreach ($catalog as $gkey => $g) {
            $tree[$gkey] = [
                'key'     => $gkey,
                'icon'    => $g['icon'],
                'label'   => (string)($LANG[$g['lang']] ?? $gkey),
                'modules' => [],
            ];
        }

        foreach ($all as $mod) {
            $gkey = $known[$mod] ?? 'other';

            if (!isset($tree[$gkey])) {
                $tree[$gkey] = [
                    'key'     => $gkey,
                    'icon'    => 'bi-puzzle',
                    'label'   => (string)($LANG['perm_group_other'] ?? 'Other'),
                    'modules' => [],
                ];
            }

            $langKey = $catalog[$gkey]['modules'][$mod] ?? null;
            $label   = ($langKey !== null && isset($LANG[$langKey])) ? (string)$LANG[$langKey] : $mod;

            $tree[$gkey]['modules'][] = [
                'key'   => $mod,
                'label' => $label,
                'scope' => isset($scope[$mod]),
            ];
        }

        $out = [];
        foreach ($tree as $g) {
            if (!empty($g['modules'])) $out[] = $g;
        }
        return $out;
    }
}

/**
 * Apakah kolom delegasi sudah ada di tabel `permissions`?
 *
 * Pengaman agar sistem tetap hidup normal (superadmin-only,
 * persis seperti sebelumnya) apabila file PHP sudah diupload
 * tetapi migrasi SQL belum dijalankan. Dicek sekali per request.
 */
if (!function_exists('permDelegateColumnsExist')) {
    function permDelegateColumnsExist(): bool {
        static $exists = null;
        if ($exists !== null) return $exists;

        global $conn;
        $exists = false;

        $res = $conn->query("SHOW COLUMNS FROM `permissions` LIKE 'perm_delegate'");
        if ($res) {
            $exists = ($res->num_rows > 0);
            $res->free();
        }
        return $exists;
    }
}

/**
 * Konfigurasi delegasi milik USER YANG SEDANG LOGIN.
 *
 * Dibaca LANGSUNG dari database (bukan cache $_SESSION['_perms'],
 * yang hanya diisi sekali saat login dan tidak memuat kolom ini),
 * dengan prioritas role -> ditimpa user, sama seperti permission
 * lain. Hasilnya di-cache per request.
 */
if (!function_exists('permDelegateConfig')) {
    function permDelegateConfig(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        global $conn;

        $cfg = [
            'is_super'      => false,
            'enabled'       => false,
            'groups'        => [],
            'restrict_rank' => false,
            'rank'          => 99,
        ];

        if (!isLoggedIn()) return $cache = $cfg;

        $cfg['rank'] = permRoleRank((string)($_SESSION['role'] ?? ''));

        if (isSuperAdmin()) {
            $cfg['is_super'] = true;
            $cfg['enabled']  = true;
            return $cache = $cfg;
        }

        if (!permDelegateColumnsExist()) return $cache = $cfg;

        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        $apply = function ($row) use (&$cfg) {
            if (!$row) return;
            $cfg['enabled']       = ((int)$row['perm_delegate'] === 1);
            $cfg['restrict_rank'] = ((int)$row['perm_target_rank'] === 1);
            $raw                  = trim((string)$row['perm_scope_groups']);
            $cfg['groups']        = ($raw === '') ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
        };

        if ($role !== '') {
            $stmt = $conn->prepare(
                "SELECT perm_delegate, perm_scope_groups, perm_target_rank
                   FROM permissions
                  WHERE user_id IS NULL AND role = ? AND module = 'users' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $apply($stmt->get_result()->fetch_assoc());
                $stmt->close();
            }
        }

        if ($uid > 0) {
            $stmt = $conn->prepare(
                "SELECT perm_delegate, perm_scope_groups, perm_target_rank
                   FROM permissions
                  WHERE user_id = ? AND module = 'users' LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $apply($stmt->get_result()->fetch_assoc());
                $stmt->close();
            }
        }

        return $cache = $cfg;
    }
}

/** Boleh membuka & menyimpan halaman permission sama sekali? */
if (!function_exists('permCanManagePermissions')) {
    function permCanManagePermissions(): bool {
        if (!isLoggedIn()) return false;
        if (isSuperAdmin()) return true;
        $cfg = permDelegateConfig();
        return !empty($cfg['enabled']);
    }
}

/**
 * BATAS 3 — boleh mengelola akun target tertentu?
 * Superadmin selalu boleh, KECUALI target berrole superadmin
 * (kolom permissions.role bertipe enum tanpa nilai 'superadmin',
 * jadi barisnya memang tidak pernah bisa ditulis).
 */
if (!function_exists('permCanManageTargetUser')) {
    function permCanManageTargetUser(int $targetId, string $targetRole): bool {
        if (!isLoggedIn())               return false;
        if ($targetId <= 0)              return false;
        if ($targetRole === 'superadmin') return false;
        if (isSuperAdmin())              return true;

        $cfg = permDelegateConfig();
        if (empty($cfg['enabled'])) return false;

        // Tidak pernah boleh mengelola dirinya sendiri.
        if ($targetId === (int)($_SESSION['user_id'] ?? 0)) return false;

        if (!empty($cfg['restrict_rank'])) {
            if (permRoleRank($targetRole) <= (int)$cfg['rank']) return false;
        }
        return true;
    }
}

/** BATAS 2 — grup modul boleh disentuh delegate? */
if (!function_exists('permGroupAllowed')) {
    function permGroupAllowed(string $group): bool {
        if (isSuperAdmin()) return true;
        $cfg = permDelegateConfig();
        if (empty($cfg['enabled'])) return false;
        if (empty($cfg['groups'])) return true;      // kosong = semua grup
        return in_array($group, $cfg['groups'], true);
    }
}

/**
 * Modul boleh disentuh delegate?
 * INVARIANT KERAS: modul 'users' tertutup rapat untuk non-superadmin,
 * supaya delegate tidak bisa mengangkat delegate baru.
 */
if (!function_exists('permModuleAllowed')) {
    function permModuleAllowed(string $module): bool {
        if (isSuperAdmin()) return true;
        if ($module === 'users') return false;
        return permGroupAllowed(permGroupOfModule($module));
    }
}

/**
 * BATAS 1 — NON-ESKALASI.
 * Delegate hanya boleh mengubah aksi yang dia sendiri miliki,
 * dibaca lewat _dbCan() (langsung dari database, dengan cache
 * per-modul per-request).
 */
if (!function_exists('permActionAllowed')) {
    function permActionAllowed(string $module, string $action): bool {
        if (isSuperAdmin()) return true;
        if (!in_array($action, permActionKeys(), true)) return false;
        if (!permModuleAllowed($module)) return false;
        return _dbCan($module, $action);
    }
}

/** Cakupan data (can_view_all) boleh diubah delegate? */
if (!function_exists('permScopeAllowed')) {
    function permScopeAllowed(string $module): bool {
        if (isSuperAdmin()) return true;
        if (!in_array($module, permScopeModules(), true)) return false;
        if (!permModuleAllowed($module)) return false;
        return _dbCan($module, 'can_read');
    }
}

/**
 * Matriks izin siap kirim ke browser. Dipakai untuk men-disable
 * checkbox di UI — TETAPI keputusan sebenarnya tetap dihitung
 * ulang di server saat menyimpan. HTML disabled bukan pengaman.
 */
if (!function_exists('permBoundsMatrix')) {
    function permBoundsMatrix(array $modules): array {
        $bounds  = ['actions' => [], 'scope' => [], 'groups' => []];
        $actions = permActionKeys();

        foreach (permGroupCatalog() as $gkey => $g) {
            $bounds['groups'][$gkey] = permGroupAllowed($gkey);
        }
        $bounds['groups']['other'] = permGroupAllowed('other');

        foreach ($modules as $mod) {
            foreach ($actions as $act) {
                $bounds['actions'][$mod][$act] = permActionAllowed($mod, $act);
            }
            $bounds['scope'][$mod] = permScopeAllowed($mod);
        }
        return $bounds;
    }
}

/* ============================================================
   PENGHAPUSAN AKUN USERS — GERBANG, SEGEL NAMA, DAN ZERO-FILL

   Tiga fungsi di bawah adalah SATU-SATUNYA sumber kebenaran untuk
   fitur hapus akun di settings/users.php. Ditaruh di sini (bukan di
   users.php) supaya tidak ada logic permission ganda, persis seperti
   permCanManageTargetUser() yang sudah dipakai bersama oleh toggle
   status, tombol Edit, dan tombol Permissions.
   ============================================================ */

/**
 * BOLEH MENGHAPUS AKUN TARGET?
 *
 * Memakai permission yang SUDAH TERSEDIA di database — kolom
 * can_delete pada baris module='users'. Kolom itu sebelumnya belum
 * pernah dipakai kode mana pun, jadi TIDAK ADA modul baru, TIDAK ADA
 * kolom baru, dan TIDAK ADA permission ganda.
 *
 * Lapisan gerbangnya, berurutan:
 *   1. harus login
 *   2. target valid
 *   3. target BUKAN superadmin  (permCanManageTargetUser sudah menolak)
 *   4. target BUKAN diri sendiri
 *   5. superadmin -> selalu boleh
 *   6. selain itu: can_delete modul 'users' DARI DATABASE (_dbCan,
 *      bukan cache session yang tidak pernah di-refresh) DAN target
 *      berada di bawah wewenang permrolerank akun yang login
 *
 * Batas "superadmin terakhir" TIDAK dicek di sini karena butuh query
 * hitung; itu diperiksa di users.php di dalam transaction.
 */
if (!function_exists('permCanDeleteUser')) {
    function permCanDeleteUser(int $targetId, string $targetRole): bool {
        if (!isLoggedIn())                return false;
        if ($targetId <= 0)               return false;
        if ($targetRole === 'superadmin') return false;

        // Tidak pernah boleh menghapus akun sendiri.
        if ($targetId === (int)($_SESSION['user_id'] ?? 0)) return false;

        if (isSuperAdmin()) return true;

        return _dbCan('users', 'can_delete')
            && permCanManageTargetUser($targetId, $targetRole);
    }
}

/**
 * PETA KOLOM SNAPSHOT NAMA.
 *
 * tabel => [ kolom_id_pelaku => kolom_nama_permanen, ... ]
 *
 * Satu-satunya daftar acuan; dipakai oleh permSealUserNames() dan bisa
 * dipakai ulang kapan pun ada tabel baru yang menyimpan users.id.
 * Menambah tabel baru cukup menambah satu baris di sini.
 *
 * `activity_logs` TIDAK ada di daftar karena logActivity() di
 * config.php sudah menyalin nama pelakunya ke kolom user_name pada
 * saat log dibuat.
 * `purchases` dan `manual_daily_reports` juga tidak ada karena kolom
 * pelakunya memang sudah bertipe varchar berisi NAMA, bukan ID.
 */
if (!function_exists('permUserSnapshotMap')) {
    function permUserSnapshotMap(): array {
        return [
            'inventory_out'            => ['taken_by'     => 'taken_by_name',
                                           'created_by'   => 'created_by_name'],
            'inventory_in'             => ['created_by'   => 'created_by_name'],
            'inventory'                => ['created_by'   => 'created_by_name'],
            'purchase_orders'          => ['created_by'   => 'created_by_name',
                                           'approved_by'  => 'approved_by_name'],
            'employees'                => ['created_by'   => 'created_by_name'],
            'employee_shift_schedules' => ['created_by'   => 'created_by_name'],
            'holidays'                 => ['created_by'   => 'created_by_name'],
            'overtime_settings'        => ['updated_by'   => 'updated_by_name'],
            'attendance'               => ['approved_by'  => 'approved_by_name',
                                           'created_by'   => 'created_by_name'],
            'cash_advances'            => ['approved_by'  => 'approved_by_name'],
            'leave_requests'           => ['approved_by'  => 'approved_by_name'],
            'payroll'                  => ['approved_by'  => 'approved_by_name',
                                           'created_by'   => 'created_by_name'],
            'payroll_periods'          => ['created_by'   => 'created_by_name'],
            'employee_warnings'        => ['issued_by'    => 'issued_by_name'],
            'employee_documents'       => ['created_by'   => 'created_by_name'],
            'employee_trainings'       => ['created_by'   => 'created_by_name'],
            'returns'                  => ['created_by'   => 'created_by_name'],
            'kpi_evaluations'          => ['evaluator_id' => 'evaluator_name'],
            'stock_opnames'            => ['created_by'   => 'created_by_name',
                                           'approved_by'  => 'approved_by_name',
                                           'posted_by'    => 'posted_by_name',
                                           'cancelled_by' => 'cancelled_by_name'],
            'stock_opname_items'       => ['counted_by'   => 'counted_by_name'],
            'goods_receipts'           => ['created_by'   => 'created_by_name',
                                           'updated_by'   => 'updated_by_name',
                                           'cancelled_by' => 'cancelled_by_name',
                                           'paid_by'      => 'paid_by_name'],
            'purchase_returns'         => ['created_by'   => 'created_by_name',
                                           'approved_by'  => 'approved_by_name',
                                           'cancelled_by' => 'cancelled_by_name'],
        ];
    }
}

/**
 * SEGEL NAMA — dipanggil TEPAT SEBELUM baris users dihapus.
 *
 * Untuk setiap tabel di permUserSnapshotMap(), kolom nama permanen
 * diisi dengan full_name akun yang akan dihapus, TAPI HANYA pada baris
 * yang kolom namanya memang masih kosong. Baris yang sudah punya nama
 * TIDAK PERNAH ditimpa.
 *
 * Ini jaring pengaman terakhir: walaupun ada satu jalur tulis yang
 * terlewat mengisi snapshot, namanya tetap terselamatkan pada detik
 * sebelum akunnya hilang.
 *
 * Kolom yang belum ada di database (migrasi belum dijalankan) dilewati
 * diam-diam lewat pemeriksaan SHOW COLUMNS, sehingga file PHP ini aman
 * diupload sebelum maupun sesudah migrasi SQL.
 *
 * @return int jumlah baris yang berhasil disegel
 */
if (!function_exists('permSealUserNames')) {
    function permSealUserNames(int $userId, string $fullName): int {
        global $conn;
        if ($userId <= 0) return 0;

        $sealed = 0;

        foreach (permUserSnapshotMap() as $table => $cols) {
            // Tabelnya ada?
            $chkT = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
            if (!$chkT || $chkT->num_rows === 0) { if ($chkT) $chkT->free(); continue; }
            $chkT->free();

            foreach ($cols as $idCol => $nameCol) {
                // Kolom snapshot-nya sudah dibuat?
                $chkC = @$conn->query(
                    "SHOW COLUMNS FROM `" . $table . "` LIKE '" . $conn->real_escape_string($nameCol) . "'"
                );
                if (!$chkC || $chkC->num_rows === 0) { if ($chkC) $chkC->free(); continue; }
                $chkC->free();

                $stmt = $conn->prepare(
                    "UPDATE `{$table}`
                        SET `{$nameCol}` = ?
                      WHERE `{$idCol}` = ?
                        AND (`{$nameCol}` IS NULL OR `{$nameCol}` = '')"
                );
                if (!$stmt) continue;
                $stmt->bind_param('si', $fullName, $userId);
                if ($stmt->execute()) $sealed += $stmt->affected_rows;
                $stmt->close();
            }
        }

        return $sealed;
    }
}

/**
 * ZERO-FILL — dipanggil TEPAT SESUDAH baris users baru dibuat.
 *
 * Menulis satu baris permission bernilai NOL untuk SETIAP modul yang
 * dikenal sistem. Karena urutan baca permission adalah
 *     baris role (user_id IS NULL)  ->  DITIMPA  ->  baris user
 * maka baris nol milik user ini menimpa seluruh default role, sehingga
 * akun baru benar-benar TIDAK punya akses apa pun sampai admin
 * mengaturnya di Settings > Users > Permissions.
 *
 * 210 baris default role (user_id IS NULL) TIDAK DISENTUH SAMA SEKALI,
 * jadi 37 akun lama tidak terpengaruh sedikit pun.
 *
 * Role 'superadmin' dilewati: kolom permissions.role bertipe enum tanpa
 * nilai 'superadmin', dan superadmin memang selalu full control lewat
 * baris pertama _dbCan().
 *
 * Daftar modulnya diambil dari permModulesFromDatabase() — fungsi yang
 * SAMA dengan yang dipakai tombol Save di halaman Permissions, supaya
 * tidak ada daftar modul ganda yang bisa berbeda isi.
 *
 * @return bool true bila seluruh baris berhasil ditulis
 */
if (!function_exists('permZeroFillNewUser')) {
    function permZeroFillNewUser(int $userId, string $role): bool {
        global $conn;

        if ($userId <= 0)           return false;
        if ($role === 'superadmin') return true;   // sengaja dilewati

        $modules = permModulesFromDatabase();
        if (empty($modules)) return true;

        $hasDelegateCols = permDelegateColumnsExist();

        if ($hasDelegateCols) {
            $sql = "INSERT INTO permissions
                        (user_id, role, module,
                         can_create, can_read, can_update, can_delete, can_access, can_export,
                         can_view_all, perm_delegate, perm_scope_groups, perm_target_rank)
                    VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, NULL, 0, '', 0)
                    ON DUPLICATE KEY UPDATE
                         can_create = 0, can_read   = 0, can_update = 0,
                         can_delete = 0, can_access = 0, can_export = 0,
                         can_view_all = NULL,
                         perm_delegate = 0, perm_scope_groups = '', perm_target_rank = 0";
        } else {
            $sql = "INSERT INTO permissions
                        (user_id, role, module,
                         can_create, can_read, can_update, can_delete, can_access, can_export,
                         can_view_all)
                    VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, NULL)
                    ON DUPLICATE KEY UPDATE
                         can_create = 0, can_read   = 0, can_update = 0,
                         can_delete = 0, can_access = 0, can_export = 0,
                         can_view_all = NULL";
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;

        $b_module = '';
        if (!$stmt->bind_param('iss', $userId, $role, $b_module)) {
            $stmt->close();
            return false;
        }

        $ok = true;
        foreach ($modules as $mod) {
            $b_module = $mod;
            if (!$stmt->execute()) { $ok = false; break; }
        }
        $stmt->close();

        return $ok;
    }
}


/* ============================================================
   HR & PAYROLL — GERBANG INDUK + IZIN MODUL (terpusat)

   Pola SAMA PERSIS dengan gerbang Purchase di atas, supaya tidak ada
   dua sistem izin yang bisa berbeda perilaku.

   TIDAK MEMBUAT IZIN BARU. Seluruh 15 modul di bawah SUDAH ADA di tabel
   `permissions`: payroll_dashboard, employees, attendance, shifts,
   shift_schedules, holidays, payroll_periods, payroll_list,
   leave_requests, cash_advances, kpi, departments, positions, religions,
   payroll_settings — dengan 'payroll' sebagai gerbang induknya.
   ============================================================ */

/** Gerbang induk HR & Payroll. Modul 'payroll'. */
if (!function_exists('canAccessPayrollGate')) {
    function canAccessPayrollGate(): bool { return _dbCan('payroll', 'can_access'); }
}

/**
 * Dua lapis pemeriksaan, sama seperti purchaseModuleAllowed():
 *   1. GERBANG : modul 'payroll' harus can_access — mematikan gerbang
 *                menutup seluruh halaman HR & Payroll termasuk URL langsung.
 *   2. MODUL   : can_access modul yang bersangkutan.
 *
 * Dibaca lewat _dbCan() (LANGSUNG dari database, bukan cache session yang
 * hanya diisi saat login), sehingga pencabutan izin langsung berlaku.
 * Superadmin selalu lolos di baris pertama _dbCan().
 */
if (!function_exists('payrollModuleAllowed')) {
    function payrollModuleAllowed(string $module): bool {
        if (isSuperAdmin()) return true;
        if (!isLoggedIn())  return false;
        if (!_dbCan('payroll', 'can_access')) return false;   // gerbang induk
        return _dbCan($module, 'can_access');                 // izin modul
    }
}

/** Versi tegas: hentikan halaman bila tidak berhak. */
if (!function_exists('requirePayrollModule')) {
    function requirePayrollModule(string $module): void {
        requireLogin();
        if (!payrollModuleAllowed($module)) redirectNoAccess();
    }
}

/**
 * Daftar tab hub HR & Payroll beserta modul izinnya.
 * URUTAN DI SINI = URUTAN TAB = URUTAN SUBMENU DI SIDEBAR.
 * Dipakai payroll_gate.php untuk membangun tab DAN untuk menentukan tab
 * pengganti bila tab yang diminta tidak diizinkan.
 */
if (!function_exists('payrollTabModules')) {
    function payrollTabModules(): array {
        return [
            'dashboard'        => 'payroll_dashboard',
            'employees'        => 'employees',
            'attendance'       => 'attendance',
            'shifts'           => 'shifts',
            'shift_schedules'  => 'shift_schedules',
            'holidays'         => 'holidays',
            'payroll_periods'  => 'payroll_periods',
            'payroll_list'     => 'payroll_list',
            'leave_requests'   => 'leave_requests',
            'cash_advances'    => 'cash_advances',
            'kpi'              => 'kpi',
            'departments'      => 'departments',
            'positions'        => 'positions',
            'religions'        => 'religions',
            'payroll_settings' => 'payroll_settings',
            // ── Modul enterprise (Sept 2026) ──
            'salary_components' => 'salary_components',
            'org_chart'         => 'org_chart',
            'approvals'         => 'approvals',
            'tax_settings'      => 'tax_settings',
            'assets'            => 'assets',
            'recruitment'       => 'recruitment',
            'onboarding'        => 'recruitment',
            'appraisal'         => 'appraisal',
            'attendance_import' => 'attendance_import',
            'payslip_email'     => 'payslip_email',
            'employee_portal'   => 'employee_portal',
        ];
    }
}

/**
 * Berkas fragmen tiap tab, sejajar dengan payrollTabModules().
 * Dipisah dari daftar modul supaya penamaan berkas tidak mengunci nama tab.
 */
if (!function_exists('payrollTabFiles')) {
    function payrollTabFiles(): array {
        return [
            'dashboard'        => 'payroll_index.php',
            'employees'        => 'employees.php',
            'attendance'       => 'attendance.php',
            'shifts'           => 'shifts.php',
            'shift_schedules'  => 'shift_schedules.php',
            'holidays'         => 'holidays.php',
            'payroll_periods'  => 'payroll_periods.php',
            'payroll_list'     => 'payroll_list.php',
            'leave_requests'   => 'leave_requests.php',
            'cash_advances'    => 'cash_advances.php',
            'kpi'              => 'kpi_employees.php',
            'departments'      => 'departments.php',
            'positions'        => 'positions.php',
            'religions'        => 'religions.php',
            'payroll_settings' => 'payroll_settings.php',
            'salary_components' => 'salary_components.php',
            'org_chart'         => 'org_chart.php',
            'approvals'         => 'approvals.php',
            'tax_settings'      => 'tax_settings.php',
            'assets'            => 'assets.php',
            'recruitment'       => 'recruitment.php',
            'onboarding'        => 'onboarding.php',
            'appraisal'         => 'appraisal.php',
            'attendance_import' => 'attendance_import.php',
            'payslip_email'     => 'payslip_email.php',
            'employee_portal'   => 'employee_portal.php',
        ];
    }
}

/** Ikon Bootstrap tiap tab — disamakan dengan ikon submenu di sidebar. */
if (!function_exists('payrollTabIcons')) {
    function payrollTabIcons(): array {
        return [
            'dashboard'        => 'bi-speedometer2',
            'employees'        => 'bi-person-badge-fill',
            'attendance'       => 'bi-calendar-check-fill',
            'shifts'           => 'bi-clock-fill',
            'shift_schedules'  => 'bi-calendar3',
            'holidays'         => 'bi-calendar-event-fill',
            'payroll_periods'  => 'bi-calendar-month-fill',
            'payroll_list'     => 'bi-receipt',
            'leave_requests'   => 'bi-file-earmark-check-fill',
            'cash_advances'    => 'bi-cash-coin',
            'kpi'              => 'bi-graph-up-arrow',
            'departments'      => 'bi-diagram-3-fill',
            'positions'        => 'bi-briefcase-fill',
            'religions'        => 'bi-journal-bookmark-fill',
            'payroll_settings' => 'bi-gear-wide-connected',
            'salary_components' => 'bi-ui-checks-grid',
            'org_chart'         => 'bi-diagram-3',
            'approvals'         => 'bi-signpost-split',
            'tax_settings'      => 'bi-percent',
            'assets'            => 'bi-box-seam',
            'recruitment'       => 'bi-person-plus-fill',
            'onboarding'        => 'bi-list-check',
            'appraisal'         => 'bi-clipboard-data-fill',
            'attendance_import' => 'bi-upload',
            'payslip_email'     => 'bi-envelope-paper-fill',
            'employee_portal'   => 'bi-person-workspace',
        ];
    }
}

/** Key bahasa judul tiap tab — memakai key yang SUDAH dipakai sidebar. */
if (!function_exists('payrollTabLangKeys')) {
    function payrollTabLangKeys(): array {
        return [
            'dashboard'        => 'payroll_dashboard',
            'employees'        => 'employees',
            'attendance'       => 'attendance',
            'shifts'           => 'shifts',
            'shift_schedules'  => 'shift_schedules',
            'holidays'         => 'holidays',
            'payroll_periods'  => 'payroll_periods',
            'payroll_list'     => 'payroll_list',
            'leave_requests'   => 'leave_requests',
            'cash_advances'    => 'cash_advances',
            'kpi'              => 'kpi_employees',
            'departments'      => 'departments',
            'positions'        => 'positions',
            'religions'        => 'religions',
            'payroll_settings' => 'payroll_settings',
            'salary_components' => 'salary_components',
            'org_chart'         => 'org_chart',
            'approvals'         => 'approvals',
            'tax_settings'      => 'tax_brackets',
            'assets'            => 'assets',
            'recruitment'       => 'recruitment',
            'onboarding'        => 'onboarding_tasks',
            'appraisal'         => 'appraisal',
            'attendance_import' => 'attendance_import',
            'payslip_email'     => 'payslip_email',
            'employee_portal'   => 'employee_portal',
        ];
    }
}

/**
 * Tab yang benar-benar boleh dibuka user ini, dalam urutan tampil.
 * Dipakai gate untuk membangun nav DAN untuk memilih tab pengganti.
 */
if (!function_exists('payrollAllowedTabs')) {
    function payrollAllowedTabs(): array {
        $out = [];
        foreach (payrollTabModules() as $tab => $mod) {
            if (payrollModuleAllowed($mod)) $out[] = $tab;
        }
        return $out;
    }
}

/**
 * URL tab hub. Satu-satunya tempat nama berkas hub ditulis, sehingga bila
 * suatu saat berganti nama cukup diubah di sini.
 */
if (!function_exists('payrollGateUrl')) {
    function payrollGateUrl(string $tab = ''): string {
        return url('payroll/payroll_gate.php' . ($tab !== '' ? '?tab=' . rawurlencode($tab) : ''));
    }
}

/**
 * Pengalihan berkas tab lama yang diakses langsung.
 * URL lama seperti payroll/employees.php tetap hidup: ia mengalihkan ke
 * tab yang sesuai di hub, sehingga bookmark dan tautan lama tidak mati.
 */
if (!function_exists('payrollRedirectLegacy')) {
    function payrollRedirectLegacy(string $tab): void {
        redirect(payrollGateUrl($tab));
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   TAHAP 0.3 — GUARD AKSES TERPUSAT

   MENGGANTIKAN empat pola yang sekarang tersebar:
     defined('APP_NAME')   26 berkas
     defined('BASE_PATH')   5 berkas
     defined('DB_HOST')     1 berkas
     !isset($_SESSION['user_id'])
   dan menutup satu lubang: inventory/inventory_data.php tidak punya guard
   sama sekali (hanya komentar). Ia "aman" hari ini semata-mata karena
   memanggil checkPermission() yang belum terdefinisi sehingga PHP fatal
   error — itu 500, bukan penolakan yang disengaja.

   DI TAHAP 0 HANYA HELPER-NYA YANG DIBUAT. Pemasangan per halaman
   dikerjakan di Tahap 2–5.

   ── CARA MEMASANG (salin persis, dua baris, di paling atas berkas) ──────

   Untuk FRAGMEN TAB (berkas yang di-include oleh gate, mis. currency.php,
   inventory_data.php, purchases_invoice.php):

       <?php
       if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
       bltx_guard_fragment();

   Untuk HALAMAN MANDIRI (punya header/footer sendiri):

       <?php
       require_once __DIR__ . '/../includes/config.php';
       require_once __DIR__ . '/../includes/global_permission.php';
       bltx_guard_page('nama_modul');            // atau bltx_guard_page() bila cukup cek login

   KENAPA BARIS function_exists ITU PERLU DAN BUKAN KEMUBAZIRAN:
   kalau fragmen dibuka langsung lewat URL, config.php dan berkas ini belum
   pernah termuat, sehingga bltx_guard_fragment() BELUM ADA. Memanggilnya
   begitu saja menghasilkan fatal error 500 — bukan 403 yang rapi. Baris
   pertama itulah yang tetap bekerja tanpa bergantung pada apa pun.
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Tolak permintaan dengan status HTTP dan pesan singkat.
 * Sengaja polos: tidak membocorkan nama berkas, modul, maupun alasan detail.
 */
if (!function_exists('bltx_deny')) {
    function bltx_deny(int $status = 403, string $message = 'Direct access not permitted.'): void {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }
        exit($message);
    }
}

/**
 * Guard untuk FRAGMEN TAB.
 *
 * Tiga syarat, semuanya harus terpenuhi:
 *   1. aplikasi sudah ter-bootstrap (config.php termuat → APP_NAME ada);
 *   2. bila $gate_constant diisi, konstanta itu harus terdefinisi —
 *      dipakai gate yang memang sudah memakai penanda seperti
 *      INV_GATE_EMBED dan PAYROLL_GATE, sehingga fragmen benar-benar
 *      hanya jalan bila di-include oleh gate-nya, bukan oleh gate lain;
 *   3. pengunjung sudah login.
 *
 * @param string $gate_constant opsional, mis. 'INV_GATE_EMBED'
 */
if (!function_exists('bltx_guard_fragment')) {
    function bltx_guard_fragment(string $gate_constant = ''): void {
        if (!defined('APP_NAME')) {
            bltx_deny();
        }
        if ($gate_constant !== '' && !defined($gate_constant)) {
            bltx_deny();
        }
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            bltx_deny();
        }
    }
}

/**
 * Guard untuk HALAMAN MANDIRI.
 *
 * Belum login  → dialihkan ke halaman login (perilaku require_login() yang
 *                sudah ada, lengkap dengan parameter ?next=).
 * Sudah login tetapi izinnya kurang → redirectNoAccess(), yang mengantar ke
 *                halaman pertama yang boleh ia buka, atau menampilkan
 *                layar "No Permission" bila tidak ada satu pun.
 *
 * Superadmin lolos otomatis karena checkPermission() mengembalikan true.
 *
 * @param string $module modul yang diperiksa; kosong = cukup cek login
 * @param string $action can_access | can_read | can_create | can_update |
 *                       can_delete | can_export
 */
if (!function_exists('bltx_guard_page')) {
    function bltx_guard_page(string $module = '', string $action = 'can_access'): void {
        requireLogin();
        if ($module !== '' && !checkPermission($module, $action)) {
            redirectNoAccess();
        }
    }
}

/**
 * Guard untuk ENDPOINT AJAX / JSON.
 *
 * Berbeda dari bltx_guard_page(): endpoint tidak boleh di-redirect, karena
 * yang menerima balasannya adalah JavaScript, bukan browser. Ia menjawab
 * dengan JSON berstatus 401/403 supaya sisi klien bisa menanganinya.
 */
if (!function_exists('bltx_guard_ajax')) {
    function bltx_guard_ajax(string $module = '', string $action = 'can_access'): void {
        $fail = function (int $status, string $msg) {
            http_response_code($status);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        };
        if (!isLoggedIn()) {
            $fail(401, 'Not authenticated.');
        }
        if ($module !== '' && !checkPermission($module, $action)) {
            $fail(403, 'Not permitted.');
        }
    }
}


/* ═══════════════════════════════════════════════════════════════════════════
   TAHAP 0.4 — TOAST MELAYANG + ESCAPING OUTPUT (TERPUSAT)

   Markup yang dihasilkan bltx_render_flash() SAMA PERSIS dengan pola
   referensi A4.1 (purchases/suppliers.php baris 280–316):
     • position:fixed  → tidak mendorong konten turun
     • murni HTML+CSS  → tetap tampil walau JavaScript mati
     • hilang sendiri setelah 4 detik, dan bisa ditutup manual

   Versi AJAX tetap memakai showFloatingAlert() yang SUDAH ADA di
   includes/footer.php. TIDAK ADA fungsi JS baru yang dibuat di sini.

   Pemakaian di Tahap 2–5:

       // setelah proses tulis berhasil / gagal
       bltx_flash_success($LANG['saved_successfully']);
       bltx_flash_error($LANG['save_failed']);
       redirect(url('settings/settings_gate.php?tab=currency'));   // POST → proses → redirect

       // di dalam berkas tampilan, satu baris, sebelum konten
       bltx_render_flash('currency');
   ═══════════════════════════════════════════════════════════════════════ */

/** Titipkan pesan sukses untuk ditampilkan setelah redirect. */
if (!function_exists('bltx_flash_success')) {
    function bltx_flash_success(string $message): void {
        $_SESSION['success'] = $message;
    }
}

/** Titipkan pesan gagal untuk ditampilkan setelah redirect. */
if (!function_exists('bltx_flash_error')) {
    function bltx_flash_error(string $message): void {
        $_SESSION['error'] = $message;
    }
}

/**
 * Cetak toast sukses dan/atau gagal yang sedang menunggu, lalu hapus dari
 * sesi supaya tidak muncul dua kali.
 *
 * Menggantikan ±30 baris HTML yang selama ini disalin ulang per halaman.
 *
 * @param string $prefix awalan id elemen, biasanya nama halaman
 *                       (mis. 'currency' → currencyFlashSuccess)
 * @param array  $keys   KUNCI SESI yang dibaca. Default ['success','error'].
 *
 *   Tambahan Tahap 3. Sistem ini memakai empat belas kunci flash yang berbeda
 *   — 'success', 'error', 'po_success', 'po_error', 'gr_success', 'gr_error',
 *   'pr_success', 'pr_error', 'dreport_*', 'swal_*', 'ps_flash', '_wh_flash'.
 *   Menyatukan kuncinya berarti mengubah setter dan pembaca secara bersamaan
 *   di puluhan berkas sekaligus, dan itu ditunda ke ronde tersendiri.
 *
 *   Parameter ini memisahkan dua persoalan: TAMPILAN toast bisa diseragamkan
 *   sekarang tanpa satu pun setter disentuh. Berkas yang memakai kunci sendiri
 *   cukup menyebutkannya:
 *
 *       bltx_render_flash('gr', ['success' => 'gr_success',
 *                                'error'   => 'gr_error']);
 */
if (!function_exists('bltx_render_flash')) {
    function bltx_render_flash(string $prefix = 'page', array $keys = []): void {
        $k_success = $keys['success'] ?? 'success';
        $k_error   = $keys['error']   ?? 'error';

        /* Kunci kosong ('') MEMATIKAN saluran itu. Dipakai halaman yang hanya
           pernah menampilkan pesan gagal — mis. po_add.php dan
           purchase_return_add.php. Tanpa ini, saluran sukses akan jatuh ke
           default 'success' dan halaman tersebut bisa MENGONSUMSI pesan
           sukses yang sebenarnya ditujukan untuk halaman daftar setelah
           redirect, sehingga pesannya hilang tanpa pernah terlihat. */
        $has_success = $k_success !== '' && isset($_SESSION[$k_success]) && $_SESSION[$k_success] !== '';
        $has_error   = $k_error   !== '' && isset($_SESSION[$k_error])   && $_SESSION[$k_error]   !== '';
        if (!$has_success && !$has_error) {
            return;
        }

        // Awalan hanya boleh huruf/angka/garis bawah agar id HTML selalu sah.
        $p = preg_replace('/[^A-Za-z0-9_]/', '', $prefix);
        if ($p === '') { $p = 'page'; }

        $close = bltx_e($GLOBALS['LANG']['close'] ?? 'Close');
        $ids   = [];

        if ($has_success) {
            $id    = $p . 'FlashSuccess';
            $ids[] = $id;
            echo '<div id="' . $id . '"'
               . ' style="position:fixed; top:1rem; right:1rem; z-index:1090;'
               . ' width:auto; min-width:280px; max-width:min(420px, 90vw);'
               . ' transition:opacity .35s ease;">'
               . '<div class="alert alert-success alert-dismissible shadow-lg d-flex align-items-center gap-2 mb-0">'
               . '<i class="bi bi-check-circle-fill"></i>'
               . '<span>' . bltx_e($_SESSION[$k_success]) . '</span>'
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . $close . '"></button>'
               . '</div></div>';
            unset($_SESSION[$k_success]);
        }

        if ($has_error) {
            $id    = $p . 'FlashError';
            $ids[] = $id;
            echo '<div id="' . $id . '"'
               . ' style="position:fixed; top:1rem; right:1rem; z-index:1091;'
               . ' width:auto; min-width:280px; max-width:min(420px, 90vw);'
               . ' transition:opacity .35s ease;">'
               . '<div class="alert alert-danger alert-dismissible shadow-lg d-flex align-items-center gap-2 mb-0">'
               . '<i class="bi bi-exclamation-triangle-fill"></i>'
               . '<span>' . bltx_e($_SESSION[$k_error]) . '</span>'
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . $close . '"></button>'
               . '</div></div>';
            unset($_SESSION[$k_error]);
        }

        echo '<script>(function(){'
           . json_encode($ids)
           . '.forEach(function(id){var el=document.getElementById(id);if(!el)return;'
           . 'setTimeout(function(){el.style.opacity="0";'
           . 'setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el);},400);},4000);});'
           . '})();</script>';
    }
}

/**
 * ESCAPING OUTPUT — SATU-SATUNYA fungsi yang dipakai untuk mencetak nilai
 * dari database ke HTML.
 *
 * Aturannya: SIMPAN MENTAH, ESCAPE SAAT KELUAR, tepat satu kali.
 * Dengan begitu tanda baca ' " & ! * dan spasi tampil apa adanya.
 *
 * CATATAN PENTING TENTANG `&amp;` YANG MASIH TERLIHAT HARI INI:
 * fungsi ini SENGAJA tidak melakukan html_entity_decode. Data lama di
 * database memang sudah rusak ter-escape ganda (mis. 'Arm &amp;amp; Hammer'),
 * dan itu masalah DATA, bukan masalah tampilan. Membereskannya di sisi
 * output hanya akan menutupi kerusakan sekaligus merusak nilai yang memang
 * berisi teks "&amp;" secara harfiah. Perbaikannya ada di Tahap 1
 * (menghentikan escape saat input + skrip normalisasi sekali jalan).
 */
if (!function_exists('bltx_e')) {
    function bltx_e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Sama seperti bltx_e(), tetapi ENTER ditampilkan sebagai pindah baris.
 *
 * Akhiran baris CRLF/CR diseragamkan ke LF lebih dulu, supaya tidak muncul
 * baris kosong ganda pada teks yang tersimpan dari textarea Windows.
 *
 * Ini TIDAK memperbaiki 7 nilai di manual_daily_reports yang menyimpan
 * "\r\n" sebagai dua karakter harfiah (garis miring + huruf) — itu
 * kerusakan data dan ditangani skrip normalisasi Tahap 1.3.
 */
if (!function_exists('bltx_e_multiline')) {
    function bltx_e_multiline($value): string {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$value);
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}

/**
 * Escape untuk nilai yang disisipkan ke dalam blok <script> sebagai string JS.
 * Dipakai agar string bahasa dan nilai database aman dipakai di JavaScript
 * tanpa menutup tag script lebih awal.
 */
if (!function_exists('bltx_js')) {
    function bltx_js($value): string {
        return json_encode(
            (string)$value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   TAHAP 2 — CSRF TERPUSAT

   BUKAN MEKANISME BARU. Ini membungkus token yang SUDAH dipakai:
   $_SESSION['csrf_token'] — 20 rujukan di settings/users.php,
   settings/activity.php, dan settings/settings_permissions.php, serta
   dibaca oleh includes/hris_functions.php baris 57 untuk payroll.
   Kunci sesi, cara pembentukan, dan cara pembandingannya sama persis;
   yang berubah hanya: tidak lagi ditulis ulang di tiap berkas.

   CATATAN PENTING — DI SISTEM INI ADA DELAPAN TOKEN CSRF TERPISAH:
     $_SESSION['csrf_token']            settings/ + payroll (hris_functions)
     $_SESSION['purchases_csrf']        purchases_function.php  (pf_csrfToken)
     $_SESSION['po_csrf_token']         purchase_orders_function.php
     $_SESSION['pret_csrf_token']       purchase_returns_function.php
     $_SESSION['gr_csrf_token']         goods_receipt_function.php
     $_SESSION['_inv_csrf']             inventory_function.php
     $_SESSION['kpi_csrf']              kpi_functions.php
     $_SESSION['settings_purchase_csrf'] settings/purchase_settings.php

   Dokumen migrasi hanya menyebut pf_csrfToken(). Helper di bawah SENGAJA
   TIDAK menyatukan kedelapannya — penyatuan itu akan menyentuh payroll
   (terlarang) dan seluruh modul purchases/inventory sekaligus, jauh di
   luar lingkup Tahap 2. Yang dilakukan di sini hanya menghentikan
   penulisan ulang token settings/ di tiap berkas.
   ═══════════════════════════════════════════════════════════════════════ */

/** Token CSRF milik sesi ini. Dibuat sekali, lalu dipakai ulang. */
if (!function_exists('bltx_csrf_token')) {
    function bltx_csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/** Cetak <input type="hidden"> berisi token. Satu baris di tiap <form>. */
if (!function_exists('bltx_csrf_field')) {
    function bltx_csrf_field(): void {
        echo '<input type="hidden" name="csrf_token" value="'
           . htmlspecialchars(bltx_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

/**
 * Benar/salahnya sebuah token. Memakai hash_equals supaya tidak bocor
 * lewat perbedaan waktu pembandingan.
 */
if (!function_exists('bltx_csrf_valid')) {
    function bltx_csrf_valid($token): bool {
        if (empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}

/**
 * Penjaga di baris pertama setiap handler POST.
 *
 * Token salah → tidak memproses apa pun. Untuk permintaan biasa: titipkan
 * pesan gagal lalu alihkan (POST → proses → redirect, sehingga refresh
 * tidak mengulang kiriman). Untuk permintaan AJAX: balas JSON 403.
 *
 * @param string $redirect_to tujuan pengalihan bila token salah
 */
if (!function_exists('bltx_csrf_require')) {
    function bltx_csrf_require(string $redirect_to = ''): void {
        if (bltx_csrf_valid($_POST['csrf_token'] ?? '')) {
            return;
        }
        $msg = $GLOBALS['LANG']['invalid_csrf'] ?? 'Invalid or expired security token. Please reload the page.';

        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($isAjax) {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        if (function_exists('bltx_flash_error')) {
            bltx_flash_error($msg);
        }
        if ($redirect_to !== '') {
            redirect($redirect_to);
        }
        bltx_deny(403, $msg);
    }
}
