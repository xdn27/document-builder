<?php

return [
    /*
    | Engine PDF aktif. Interface PdfEngine membuat penggantian engine tidak
    | menyentuh kode lain — cukup ubah nilai ini dan tambahkan binding-nya.
    */
    'engine' => env('DOCUMENT_BUILDER_PDF_ENGINE', 'mpdf'),

    'engines' => [
        'mpdf' => [
            'temp_dir' => storage_path('app/mpdf'),
            // Dipakai hanya saat tinggi zona bernilai "auto". Menetapkan tinggi
            // zona secara numerik di template memberi kesetiaan lebih tinggi.
            'auto_header_reserve_mm' => 35.0,
            'auto_footer_reserve_mm' => 12.0,
        ],

        /*
        | Merender lewat Chromium sungguhan (layanan Gotenberg terpisah, bukan
        | library PHP) alih-alih mpdf. Dipakai saat sebuah font tidak terbaca
        | benar oleh parser TTF mpdf — lihat README bagian "Font Arab & batasan
        | mpdf". base_url menunjuk ke instance Gotenberg (mis. docker run -p
        | 3000:3000 gotenberg/gotenberg:8, atau service "gotenberg" di
        | compose.yml pada jaringan yang sama).
        */
        'gotenberg' => [
            'base_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
            'timeout' => (int) env('GOTENBERG_TIMEOUT', 30),
        ],
    ],

    'images' => [
        /*
        | Awalan sumber gambar yang diizinkan, di luar disk publik aplikasi yang
        | sudah ditambahkan otomatis. Jangan pernah mengizinkan awalan kosong:
        | engine PDF akan mengambil URL apa pun, termasuk host internal.
        */
        'allowed_prefixes' => [],
        'allow_data_uri' => true,

        /*
        | Strategi ImageUploadStorage untuk berkas yang diunggah dari panel
        | builder. "data-uri" (bawaan) menempel
        | berkas langsung ke schema sebagai base64 — tidak butuh disk, tapi
        | boros ruang schema untuk gambar besar atau yang dipakai berulang.
        | "filesystem" menyimpannya ke disk Laravel (upload_disk) dan hanya
        | menulis URL-nya ke schema; awalan URL disk itu otomatis dipercaya
        | ImageSourcePolicy saat strategi ini aktif.
        */
        'upload_strategy' => env('DOCUMENT_BUILDER_UPLOAD_STRATEGY', 'filesystem'),

        // Kosong berarti disk "public" bawaan Laravel — disk yang memang
        // disiapkan untuk berkas yang harus bisa diakses browser (symlink
        // /storage, config 'url' terisi). SENGAJA BUKAN config('filesystems.default'):
        // disk default aplikasi seringkali disk "local" yang privat (root
        // storage/app, tanpa symlink maupun 'url'), dan memakainya di sini
        // menghasilkan src yang lolos ImageSourcePolicy tapi 404 di browser
        // karena filenya tidak pernah berada di jalur yang disangka. Hanya
        // dipakai saat upload_strategy di atas bernilai "filesystem".
        'upload_disk' => env('DOCUMENT_BUILDER_UPLOAD_DISK', 'public'),
        'upload_directory' => env('DOCUMENT_BUILDER_UPLOAD_DIRECTORY', 'document-builder'),
    ],

    /*
    | Tiga dari empat titik override komponen penyusun (yang keempat adalah
    | Blade-nya sendiri, lewat `vendor:publish --tag=document-builder-views`).
    | Lihat spec §8.3.
    */
    'livewire' => [
        /*
        | Isi awal setiap blok baru, supaya blok yang baru ditambahkan langsung
        | terlihat alih-alih muncul sebagai penanda kosong. Kuncinya nilai
        | BlockType; properti yang tidak disebut memakai default BlockPropSchema.
        */
        'block_palette' => [
            'paragraph' => ['text' => 'Tulis isi paragraf di sini.'],
            'letterhead' => [
                'showLogo' => false,
                'line1' => '{{ institution.name }}',
                'line3' => '{{ institution.address }}',
            ],
            'letter-meta' => ['rows' => [
                ['label' => 'Nomor', 'value' => '{{ letter.number }}'],
                ['label' => 'Lampiran', 'value' => '-'],
                ['label' => 'Hal', 'value' => '{{ letter.subject }}'],
            ]],
            'signature' => ['columns' => [[
                'place' => '{{ letter.city }}',
                'date' => '{{ today.long }}',
                'position' => '{{ employee.position }}',
                'name' => '{{ employee.name }}',
                'nip' => '{{ employee.nip }}',
            ]]],
            'table' => [
                'columns' => [
                    ['label' => 'No', 'widthPercent' => 10, 'align' => 'center'],
                    ['label' => 'Uraian', 'widthPercent' => 90, 'align' => 'left'],
                ],
                'rows' => [['1', '']],
            ],
            'list' => ['items' => [['text' => 'Butir pertama', 'level' => 0]]],
            'qrcode' => ['payload' => '{{ letter.number }}'],
        ],

        /*
        | Nama route milik APLIKASI yang dirujuk toolbar builder. Package tidak
        | menebaknya: null berarti tautan/tombolnya tidak dirender sama sekali,
        | sehingga aplikasi yang belum punya halaman cetak tidak perlu membuat
        | route palsu supaya view tidak melempar RouteNotFoundException.
        */
        'index_route' => null,
        'print_route' => null,

        /*
        | Ability yang diperiksa view sebelum merender tombolnya. null pada
        | print_ability berarti tombol cetak tidak diperiksa (hanya bergantung
        | print_route). update_ability harus SAMA dengan
        | IsTemplateRecord::$templateUpdateAbility milik model — view yang
        | menyembunyikan tombol Simpan tapi model yang tetap menolak (atau
        | sebaliknya) adalah kebingungan yang mahal ditelusuri.
        */
        'print_ability' => null,
        'update_ability' => 'update-document-template',
    ],
];
