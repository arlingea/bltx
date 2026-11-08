<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  DAFTAR BANK UMUM INDONESIA
 *  Dipakai oleh dropdown Bank Name di purchases/suppliers.php
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  CAKUPAN
 *  Berisi BANK UMUM saja — konvensional, syariah, Bank Pembangunan Daerah
 *  (BPD), bank digital, serta bank asing/campuran yang beroperasi di
 *  Indonesia. BPR dan BPRS SENGAJA TIDAK dimasukkan: jumlahnya sekitar 1.500
 *  dan berubah hampir tiap bulan, sementara supplier praktis tidak memakai
 *  rekening BPR untuk transaksi usaha.
 *
 *  ⚠ DAFTAR INI AKAN USANG SEIRING WAKTU
 *  Bank bisa merger, berganti nama, atau dicabut izinnya. Contoh nyata:
 *  Bank Maspion resmi berganti nama menjadi KBank Indonesia pada 14 Juli 2026.
 *  Karena itu daftarnya ditaruh di berkas TERSENDIRI — Anda bisa memperbarui
 *  isinya kapan saja TANPA menyentuh suppliers.php.
 *
 *  Rujukan resmi untuk memperbarui:
 *  OJK → Perbankan → Data dan Statistik →
 *  "Daftar Alamat Kantor Pusat Bank Umum dan Syariah"
 *
 *  CARA MENAMBAH / MENGUBAH
 *  Cukup tambah atau sunting satu baris pada array di bawah:
 *
 *      ['kode', 'Nama Bank yang tampil & tersimpan', 'SINGKATAN', '#warna'],
 *
 *    - kode      : dipakai untuk mencari berkas logo, huruf kecil tanpa spasi
 *    - nama      : teks INI yang tersimpan ke kolom suppliers.bank_name
 *    - singkatan : tampil di avatar teks bila logo belum ada (maks 4 huruf)
 *    - warna     : warna latar avatar teks, mendekati warna merek bank
 *
 *  LOGO BANK (opsional, boleh dikosongkan selamanya)
 *  Simpan berkas logo di:  public_html/bltx/assets/img/banks/<kode>.png
 *  Contoh:                 assets/img/banks/bca.png
 *
 *  Bank yang ADA berkas logonya   → dropdown menampilkan logo aslinya
 *  Bank yang BELUM ada logonya    → dropdown menampilkan avatar teks
 *
 *  Tidak perlu ubah kode apa pun saat menambah logo — folder dibaca otomatis
 *  (satu kali per pemuatan halaman, bukan per bank), jadi menaruh berkas baru
 *  langsung berpengaruh. Format lain (.jpg/.jpeg/.svg/.webp) juga dikenali.
 * ═══════════════════════════════════════════════════════════════════════════
 */

if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

if (!function_exists('bank_getList')) {
    /**
     * Daftar bank umum Indonesia.
     *
     * @return array<int,array{code:string,name:string,short:string,color:string}>
     */
    function bank_getList(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $raw = [
            // ── Bank BUMN (Himbara) ──────────────────────────────────────
            ['bri',            'Bank Rakyat Indonesia (BRI)',            'BRI',   '#00529C'],
            ['mandiri',        'Bank Mandiri',                           'MDR',   '#003D79'],
            ['bni',            'Bank Negara Indonesia (BNI)',            'BNI',   '#F15A22'],
            ['btn',            'Bank Tabungan Negara (BTN)',             'BTN',   '#00529C'],
            ['bsi',            'Bank Syariah Indonesia (BSI)',           'BSI',   '#00A39D'],

            // ── Bank Swasta Nasional ─────────────────────────────────────
            ['bca',            'Bank Central Asia (BCA)',                'BCA',   '#0060AF'],
            ['cimb',           'Bank CIMB Niaga',                        'CIMB',  '#7A1B2E'],
            ['danamon',        'Bank Danamon Indonesia',                 'DNMN',  '#F58220'],
            ['permata',        'Bank Permata',                           'PRMT',  '#005B94'],
            ['ocbc',           'Bank OCBC Indonesia',                    'OCBC',  '#E30613'],
            ['panin',          'Bank Panin',                             'PANN',  '#003C71'],
            ['maybank',        'Bank Maybank Indonesia',                 'MYBK',  '#FFB800'],
            ['mega',           'Bank Mega',                              'MEGA',  '#F58220'],
            ['sinarmas',       'Bank Sinarmas',                          'SNRM',  '#E30613'],
            ['btpn',           'Bank BTPN',                              'BTPN',  '#0C2340'],
            ['uob',            'Bank UOB Indonesia',                     'UOB',   '#005EB8'],
            ['hsbc',           'Bank HSBC Indonesia',                    'HSBC',  '#DB0011'],
            ['kbbank',         'KB Bank (Bank Bukopin)',                 'KB',    '#FFB600'],
            ['arthagraha',     'Bank Artha Graha Internasional',         'AGI',   '#003876'],
            ['qnb',            'Bank QNB Indonesia',                     'QNB',   '#73228A'],
            ['woori',          'Bank Woori Saudara Indonesia 1906',      'WOOR',  '#0067AC'],
            ['ccb',            'Bank China Construction Bank Indonesia', 'CCB',   '#005EA8'],
            ['ganesha',        'Bank Ganesha',                           'GNSH',  '#0072BC'],
            ['ina',            'Bank Ina Perdana',                       'INA',   '#E1251B'],
            ['victoria',       'Bank Victoria International',            'VCTR',  '#E30613'],
            ['mayapada',       'Bank Mayapada Internasional',            'MYPD',  '#00539B'],
            ['capital',        'Bank Capital Indonesia',                 'CPTL',  '#003399'],
            ['oke',            'Bank Oke Indonesia',                     'OKE',   '#E4002B'],
            ['ibk',            'Bank IBK Indonesia',                     'IBK',   '#0068B7'],
            ['shinhan',        'Bank Shinhan Indonesia',                 'SHIN',  '#0046FF'],
            ['kbank',          'KBank Indonesia (Bank Kasikorn)',        'KBNK',  '#00A950'],
            ['mnc',            'Bank MNC Internasional',                 'MNC',   '#E30613'],
            ['nobu',           'Bank Nationalnobu (Nobu Bank)',          'NOBU',  '#003A70'],
            ['mas',            'Bank Multiarta Sentosa (Bank MAS)',      'MAS',   '#00539B'],
            ['sampoerna',      'Bank Sahabat Sampoerna',                 'SMPN',  '#E30613'],
            ['index',          'Bank Index Selindo',                     'IDX',   '#005BAC'],
            ['jtrust',         'Bank J Trust Indonesia',                 'JTRS',  '#E60012'],
            ['sbi',            'Bank SBI Indonesia',                     'SBI',   '#003399'],
            ['hana',           'Bank KEB Hana Indonesia',                'HANA',  '#00A2E1'],

            // ── Bank Digital ─────────────────────────────────────────────
            ['jago',           'Bank Jago',                              'JAGO',  '#FF6B00'],
            ['allo',           'Allo Bank Indonesia',                    'ALLO',  '#3B0A64'],
            ['bnc',            'Bank Neo Commerce (BNC)',                'BNC',   '#FDB913'],
            ['raya',           'Bank Raya Indonesia',                    'RAYA',  '#F26522'],
            ['seabank',        'Bank SeaBank Indonesia',                 'SEA',   '#F5501E'],
            ['superbank',      'Superbank',                              'SPRB',  '#E8B900'],
            ['krom',           'Krom Bank Indonesia',                    'KROM',  '#FF4E00'],
            ['blu',            'BCA Digital (blu)',                      'BLU',   '#0060AF'],
            ['amar',           'Bank Amar Indonesia',                    'AMAR',  '#6A1B9A'],

            // ── Bank Umum Syariah ────────────────────────────────────────
            ['muamalat',       'Bank Muamalat Indonesia',                'MUAM',  '#00594F'],
            ['megasyariah',    'Bank Mega Syariah',                      'MGSY',  '#005BAA'],
            ['btpnsyariah',    'Bank BTPN Syariah',                      'BTPS',  '#E4002B'],
            ['paninsyariah',   'Bank Panin Dubai Syariah',               'PDS',   '#005BAA'],
            ['bjbsyariah',     'Bank BJB Syariah',                       'BJBS',  '#009A44'],
            ['kbsyariah',      'KB Bank Syariah',                        'KBS',   '#FFB600'],
            ['victoriasyariah','Bank Victoria Syariah',                  'VCTS',  '#E30613'],
            ['aladin',         'Bank Aladin Syariah',                    'ALDN',  '#2E3192'],
            ['acehsyariah',    'Bank Aceh Syariah',                      'ACEH',  '#007A33'],
            ['ntbsyariah',     'Bank NTB Syariah',                       'NTB',   '#007A33'],
            ['riaukepri',      'Bank Riau Kepri Syariah',                'RIAU',  '#009639'],

            // ── Bank Pembangunan Daerah (BPD) ────────────────────────────
            ['dki',            'Bank DKI',                               'DKI',   '#0068B3'],
            ['bjb',            'Bank BJB (Jabar Banten)',                'BJB',   '#005CA9'],
            ['jateng',         'Bank Jateng',                            'JTNG',  '#E30613'],
            ['jatim',          'Bank Jatim',                             'JTIM',  '#E4002B'],
            ['diy',            'Bank BPD DIY',                           'DIY',   '#007A53'],
            ['sumut',          'Bank Sumut',                             'SMUT',  '#005BAA'],
            ['nagari',         'Bank Nagari (Sumatera Barat)',           'NGRI',  '#00693E'],
            ['sumselbabel',    'Bank Sumsel Babel',                      'SSB',   '#005BAA'],
            ['lampung',        'Bank Lampung',                           'LMPG',  '#005BAA'],
            ['bengkulu',       'Bank Bengkulu',                          'BKL',   '#005BAA'],
            ['jambi',          'Bank Jambi',                             'JMBI',  '#005BAA'],
            ['kalbar',         'Bank Kalbar',                            'KLBR',  '#007A33'],
            ['kalsel',         'Bank Kalsel',                            'KLSL',  '#007A33'],
            ['kalteng',        'Bank Kalteng',                           'KLTG',  '#007A33'],
            ['kaltimtara',     'Bank Kaltimtara',                        'KTT',   '#005BAA'],
            ['sulselbar',      'Bank Sulselbar',                         'SSLB',  '#005BAA'],
            ['sulutgo',        'Bank SulutGo',                           'SLGO',  '#005BAA'],
            ['sulteng',        'Bank Sulteng',                           'SLTG',  '#005BAA'],
            ['sultra',         'Bank Sultra',                            'SLTR',  '#005BAA'],
            ['bpdbali',        'Bank BPD Bali',                          'BALI',  '#E4002B'],
            ['ntt',            'Bank NTT',                               'NTT',   '#005BAA'],
            ['malukumalut',    'Bank Maluku Malut',                      'MLK',   '#005BAA'],
            ['papua',          'Bank Papua',                             'PPUA',  '#005BAA'],
            ['banten',         'Bank Banten',                            'BTN2',  '#005BAA'],

            // ── Bank Asing & Campuran ────────────────────────────────────
            ['citibank',       'Citibank N.A. Indonesia',                'CITI',  '#004685'],
            ['dbs',            'Bank DBS Indonesia',                     'DBS',   '#FF3621'],
            ['standardchart',  'Standard Chartered Bank Indonesia',      'SCB',   '#0473EA'],
            ['deutsche',       'Deutsche Bank AG Indonesia',             'DB',    '#0018A8'],
            ['mufg',           'MUFG Bank Ltd. Indonesia',               'MUFG',  '#E60027'],
            ['smbc',           'Bank Sumitomo Mitsui Indonesia',         'SMBC',  '#009944'],
            ['mizuho',         'Bank Mizuho Indonesia',                  'MIZH',  '#003B70'],
            ['resona',         'Bank Resona Perdania',                   'RSNA',  '#005BAB'],
            ['bnpparibas',     'Bank BNP Paribas Indonesia',             'BNPP',  '#00915A'],
            ['anz',            'Bank ANZ Indonesia',                     'ANZ',   '#0072AC'],
            ['boa',            'Bank of America N.A. Indonesia',         'BOA',   '#012169'],
            ['jpmorgan',       'JPMorgan Chase Bank N.A. Indonesia',     'JPM',   '#1A3A6B'],
            ['icbc',           'Bank ICBC Indonesia',                    'ICBC',  '#C7000B'],
            ['bochk',          'Bank of China (Hong Kong) Indonesia',    'BOC',   '#C8102E'],
            ['ctbc',           'Bank CTBC Indonesia',                    'CTBC',  '#E4002B'],
            ['bangkokbank',    'Bangkok Bank Indonesia',                 'BBL',   '#003DA6'],
        ];

        $out = [];
        foreach ($raw as $r) {
            $out[] = [
                'code'  => $r[0],
                'name'  => $r[1],
                'short' => $r[2],
                'color' => $r[3],
            ];
        }
        return $cache = $out;
    }
}

if (!function_exists('bank_getLogoMap')) {
    /**
     * Peta kode bank → URL logo, HANYA untuk bank yang berkas logonya benar-benar ada.
     *
     * Foldernya dibaca SEKALI per pemuatan halaman memakai satu glob(), bukan
     * file_exists() per bank. Jadi walau daftarnya seratusan bank, biayanya
     * tetap satu operasi baca direktori.
     *
     * Folder boleh tidak ada sama sekali — hasilnya array kosong, dan seluruh
     * bank memakai avatar teks.
     *
     * @return array<string,string> kode bank => URL logo
     */
    function bank_getLogoMap(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $map = [];
        $dir = BASE_PATH . '/assets/img/banks';

        if (is_dir($dir)) {
            foreach ((array)glob($dir . '/*.{png,jpg,jpeg,svg,webp}', GLOB_BRACE) as $file) {
                $code = strtolower(pathinfo($file, PATHINFO_FILENAME));
                if ($code !== '' && !isset($map[$code])) {
                    $map[$code] = url('assets/img/banks/' . basename($file));
                }
            }
        }
        return $cache = $map;
    }
}
