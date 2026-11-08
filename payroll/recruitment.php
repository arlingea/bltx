<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "recruitment" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('recruitment');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('recruitment');

$deptOptions = hrisRefOptions('departments');
$posOptions  = hrisRefOptions('positions');

$cfg = [
    'module'   => 'recruitment',
    'table'    => 'job_vacancies',
    'tab'      => 'recruitment',
    'title'    => $LANG['recruitment'],
    'icon'     => 'bi-person-plus-fill',
    'order'    => 'id DESC',
    'intro'    => $LANG['intro_recruitment'],
    'snapshot' => ['created_by' => 'created_by_name'],
    'fields'   => [
        'vacancy_code' => [ 'label' => $LANG['code'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 4 ],
        'title' => [ 'label' => $LANG['title'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 8 ],
        'department_id' => ['label' => $LANG['department'], 'type' => 'select', 'list' => true, 'col' => 6, 'options' => $deptOptions],
        'position_id' => ['label' => $LANG['position'], 'type' => 'select', 'list' => true, 'col' => 6, 'options' => $posOptions],
        'quota' => [ 'label' => $LANG['quota'], 'type' => 'number', 'list' => true, 'col' => 4 ],
        'open_date' => [ 'label' => $LANG['open_date'], 'type' => 'date', 'list' => true, 'col' => 4 ],
        'close_date' => [ 'label' => $LANG['close_date'], 'type' => 'date', 'list' => true, 'col' => 4 ],
        'status' => ['label' => $LANG['status'], 'type' => 'select', 'list' => true, 'col' => 6,
                              'options' => ['draft' => $LANG['draft'], 'open' => $LANG['vacancy_open'],
                                            'closed' => $LANG['closed'], 'cancelled' => $LANG['cancelled']]],
        'description' => [ 'label' => $LANG['description'], 'type' => 'textarea', 'col' => 12 ],
        'requirements' => [ 'label' => $LANG['requirements'], 'type' => 'textarea', 'col' => 12 ],
    ],
];

hrisCrudHandle($cfg);

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('recruitment'); }

hrisCrudRender($cfg);
