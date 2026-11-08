<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "appraisal" — bagian dari payroll/payroll_gate.php

   Berkas ini TIPIS dengan sengaja: seluruh CRUD, CSRF, toast, DataTables,
   modal, dan gerbang izin per aksi ditangani mesin generik di
   includes/hris_functions.php. Yang ditulis di sini hanya KONFIGURASI
   kolomnya. Menambah modul serupa di masa depan cukup menyalin pola ini.

   URL lama tetap hidup: bila dibuka langsung (PAYROLL_GATE belum
   terdefinisi), GET dialihkan ke hub sedangkan POST tetap dilayani.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('appraisal');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('appraisal');

$kpiPeriodOptions = hrisRefOptions('kpi_periods');

$cfg = [
    'module'   => 'appraisal',
    'table'    => 'appraisal_cycles',
    'tab'      => 'appraisal',
    'title'    => $LANG['appraisal'],
    'icon'     => 'bi-clipboard-data-fill',
    'order'    => 'id DESC',
    'intro'    => $LANG['intro_appraisal'],
    'snapshot' => ['created_by' => 'created_by_name'],
    'fields'   => [
        'name' => [ 'label' => $LANG['name'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 6 ],
        'period_start' => [ 'label' => $LANG['period_start'], 'type' => 'date', 'required' => true, 'list' => true, 'col' => 3 ],
        'period_end' => [ 'label' => $LANG['period_end'], 'type' => 'date', 'required' => true, 'list' => true, 'col' => 3 ],
        'kpi_period_id' => ['label' => $LANG['kpi_period'], 'type' => 'select', 'col' => 6, 'options' => $kpiPeriodOptions],
        'status' => ['label' => $LANG['status'], 'type' => 'select', 'list' => true, 'col' => 6,
                              'options' => ['draft' => $LANG['draft'], 'self_review' => $LANG['stage_self_review'],
                                            'manager_review' => $LANG['stage_manager_review'],
                                            'calibration' => $LANG['stage_calibration'], 'closed' => $LANG['closed']]],
        'notes' => [ 'label' => $LANG['notes'], 'type' => 'textarea', 'col' => 12 ],
    ],
];

hrisCrudHandle($cfg);

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('appraisal'); }

hrisCrudRender($cfg);
