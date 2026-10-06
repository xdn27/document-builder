# Changelog

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/); versi mengikuti semver
sebagaimana dijelaskan di README ("API publik & versi").

## [1.9.10] — 2026-10-06

### Keamanan
- `sanitizeRichHtml()` di `builder.mjs` kini menjadikan setiap `<` yang bukan awal tag yang
  diizinkan sebagai teks (`&lt;`), sehingga keluarannya terbukti hanya memuat `<b><i><u><br>`
  apa pun bentuk masukannya. Pengerasan atas sanitasi 1.9.8; tidak ada jalan pintas yang diketahui.

## [1.9.9] — 2026-10-06

### Keamanan
- **Penyisipan JavaScript lewat id blok.** Id blok dari schema dicetak ke ekspresi
  `wire:click="selectBlock('…')"` di panel struktur builder, dan validator menerima teks apa pun
  sebagai id. Schema hasil impor dengan id berisi tanda kutip menjalankan JavaScript saat butirnya
  diklik. `SchemaValidator` kini hanya menerima id berpola `[A-Za-z0-9_.:-]{1,100}`
  (`SchemaValidator::BLOCK_ID_PATTERN`), dan view memeriksa pola yang sama sebelum mencetaknya.
  Zona terpilih di `addBlock(…)` dibatasi ke `header`/`body`/`footer`.

### Diubah
- **Schema dengan id blok di luar pola itu kini ditolak validator.** UUID buatan builder dan id
  pendek seperti `p1` memenuhinya.

## [1.9.8] — 2026-10-06

### Keamanan
- **XSS tersimpan di inspector builder.** Editor teks kaya mencetak nilai prop schema tanpa
  sanitasi (`{!! !!}` di view, `innerHTML` di `builder.mjs`). Nilai itu bisa diisi lewat mode
  "kode HTML" atau impor JSON, sehingga skrip tersimpan berjalan di browser penyunting berikutnya.
  View kini mencetak keluaran `HtmlSanitizer`, dan `builder.mjs` melewatkan nilainya ke
  `sanitizeRichHtml()` (hanya `<b><i><u><br>` tanpa atribut) sebelum dipasang.
- **Path traversal pada sumber gambar.** `ImageSourcePolicy` hanya mencocokkan awalan string,
  sehingga `…/storage/../../x` lolos. Segmen `..` kini ditolak, termasuk bentuk ter-encode
  (`%2e%2e`, `%252e%252e`) dan dengan garis miring terbalik.
  `FilesystemImageUploadStorage::resolveLocalPath()` menolak jalur yang keluar dari root disk,
  termasuk lewat symlink. Sebelumnya bentuk `..` polos dihentikan Flysystem dengan exception
  (pratinjau gagal total); kini sumbernya ditolak dan blok menampilkan penanda.

### Diketahui
- Schema yang sudah tersimpan tidak dibersihkan: nilai mentahnya tetap ada di database, hanya
  tidak lagi dijalankan saat ditampilkan atau dirender.

## [1.9.7] — 2026-10-06

Lanjutan sapuan cetak browser vs PDF mpdf, kini pada 74 dokumen: jumlah halaman dan halaman tiap
kata sama di semuanya, selisih posisi kata paling banyak 0,3 mm vertikal maupun horizontal.
**PDF berubah** di semua butir; builder dan cetak browser hanya berubah di butir garis bawah.

### Diperbaiki
- **Blok utuh dipindahkan oleh engine sendiri.** `MpdfEngine` mengukur tiap blok yang tidak boleh
  terpotong (tanda tangan, paragraf, butir daftar, dst.) dan membuka halaman baru sebelum
  menulisnya bila tidak muat. Larangan potong milik mpdf tidak dipakai lagi: ia menulis blok dua
  kali, sehingga kop halaman baru ikut tertulis dua kali. Batasan 1.9.6 hilang: dokumen yang
  kopnya memuat QR berposisi tetap atau yang zonanya tidak tampil di semua halaman kembali
  menjaga paragraf, butir daftar, dan tanda tangan tetap utuh.
- **Penanda butir daftar** (`1.`, `•`) kini selebar 6 mm di mpdf seperti di browser; sebelumnya
  teks butir menempel ke penanda dan pemenggalan barisnya berbeda.
- **Kerning font** diaktifkan di mpdf. Tanpa itu lebar baris berselisih sampai 2 mm pada teks
  tebal huruf besar, sehingga teks rata tengah dan rata kanan bergeser ±1 mm.
- **Perataan kanan-kiri** di mpdf hanya lewat spasi antarkata, seperti browser. Bawaan mpdf
  membagi sebagian sisa ruang ke spasi antarhuruf (kata di tengah baris bergeser ±1 mm) dan ikut
  meregangkan baris terakhir paragraf.
- **Baris NIP** di blok tanda tangan tidak lagi 0,4–0,5 mm lebih tinggi di mpdf.
- **Garis bawah**: posisinya kini dari metrik font di browser (sama dengan mpdf), dan ketebalannya
  di mpdf mengikuti pembulatan browser. **Builder dan cetak browser berubah**: garis bawah naik
  ±0,3 mm.

### Diketahui
- Ketebalan garis bawah masih bisa berselisih hingga 0,08 mm pada ukuran huruf selain ukuran
  dokumen, dan posisinya hingga 0,25 mm pada teks tebal.
- `spaceAfterMm` pada blok `list` tidak dipakai `ListRenderer`: jarak sesudah daftar tidak muncul
  di browser maupun PDF.

## [1.9.6] — 2026-10-06

Hasil sapuan menyeluruh cetak browser vs PDF mpdf pada 60 dokumen (fixture, dokumen terbit, dan
dokumen uji tabel, pengulangan zona, serta batas halaman). **PDF berubah** di semua butir di bawah;
tampilan builder dan cetak browser hanya berubah di butir yang menyebutnya.

### Diperbaiki
- **Butir daftar** di batas halaman pindah utuh di PDF mpdf (sebelumnya terpecah per baris).
- **Lebar kolom tabel** ditulis dalam mm, bukan persen. Kolom yang lebih sempit dari isinya (mis.
  kolom titik dua 1%) dilebarkan browser mengikuti isi, sedangkan mpdf memakai persen apa adanya
  sehingga isi kolom berikutnya bergeser 2,6 mm dan bisa menimpa teks.
- **Kop/kaki bertinggi `auto`** kini diukur di mpdf, bukan memakai cadangan tetap 35/12 mm: isi
  mulai dan berakhir tepat di tepi zona seperti di browser (sebelumnya bisa berselisih 7,7 mm dan
  jumlah halaman berbeda).
- **Pengulangan kop/kaki** (`first-only`, `except-first`) kini dihormati mpdf; sebelumnya zona
  selalu tampil di semua halaman. Zona `auto` yang tidak tampil tidak memakan ruang.
- **Header tabel** hanya diulang di halaman berikutnya bila `repeatHeader` aktif; mpdf dulu
  selalu mengulangnya.
- **"Jarak sebelum" blok pertama kop/kaki** dan **"jarak sesudah" blok terakhir sebuah halaman**
  kini diperhitungkan mpdf seperti di browser.

### Diubah
- Bila semua kolom tabel diberi lebar dan jumlahnya memenuhi tabel, kolom terlebar menjadi
  pengambil sisa. **Builder dan cetak browser berubah** hanya bila ada kolom yang lebih sempit dari
  isinya: kolom itu melebar dan kolom terlebar yang mengalah (sebelumnya semua kolom diskala).
- Paginator browser dan mpdf memakai kelonggaran muat yang sama di dasar halaman (0,3 mm), dan
  paginator mengukur dari kotak pecahan, bukan `scrollHeight` yang dibulatkan. **Titik potong
  halaman di builder dan cetak browser bisa bergeser** untuk blok yang meluap kurang dari 0,3 mm.
  Dipindai pada 40 dokumen di sekitar batas halaman: titik potong sama di semuanya.

### Diketahui
- Dokumen yang kopnya memuat QR berposisi tetap, atau yang zona-nya tidak tampil di semua halaman,
  melepas larangan potong di mpdf: paragraf, butir daftar, dan tanda tangan di batas halaman bisa
  terpecah. mpdf menulis ulang kop saat memindahkan blok utuh, dan kotak berposisi tetap di dalam
  kop tercetak ganda.
- Penanda butir daftar (`1.`, `•`) tidak diberi lebar tetap oleh mpdf, sehingga teks butir mulai
  lebih kiri dan pemenggalan barisnya bisa berbeda dari browser.
- `spaceAfterMm` pada blok `list` tidak dipakai `ListRenderer`: jarak sesudah daftar tidak muncul
  di browser maupun PDF.

## [1.9.5] — 2026-10-06

### Diperbaiki
- Blok yang tidak boleh terpotong (tanda tangan, kop, gambar, QR, meta surat, penerima, dan
  lainnya) kini pindah utuh ke halaman berikutnya di PDF mpdf, sama seperti di cetak browser.
  Larangan itu ditulis dengan selektor atribut `[data-break-inside='avoid']`, yang tidak dikenal
  mpdf, sehingga tidak pernah berlaku: tanda tangan di batas halaman terpotong di antara jabatan
  dan nama. Pembungkus blok kini membawa kelas `doc-block--avoid`.
- Paragraf di batas halaman pindah utuh di PDF mpdf, seperti yang dilakukan paginator browser
  (sebelumnya mpdf memecahnya per baris). Paragraf yang lebih tinggi dari satu halaman tetap
  dipecah.
- "Jarak sebelum" blok tanda tangan tidak lagi hilang di PDF mpdf saat blok itu jatuh di puncak
  halaman.

### Diubah
- HTML blok tanda tangan: tabelnya dibungkus `<div class="db-signature__wrap">` yang membawa
  `margin-top`; `<table class="db-signature__table">` tidak lagi memilikinya.
- **PDF berubah** untuk dokumen multi-halaman yang paragraf atau tanda tangannya jatuh di batas
  halaman: titik potongnya kini sama dengan builder dan cetak browser.

## [1.9.4] — 2026-10-06

### Diperbaiki
- Zona kaki di PDF mpdf kini sama dengan cetak browser:
  - Kaki bertinggi tetap diisi dari atas kotaknya, bukan menempel ke tepi bawah (kaki 25 mm berisi
    dua baris sebelumnya 9,5 mm lebih rendah di PDF).
  - "Jarak sesudah" blok di dalam kaki dihormati. mpdf membuang `margin-bottom` selama menulis
    kaki; `MpdfEngine` kini menuliskannya sebagai `padding-bottom`.
- Teks kop atau kaki tidak lagi hilang di PDF mpdf bila zona yang sama memuat blok `qrcode`
  berposisi `fixed` (regresi sejak 1.9.1): blok berposisi tetap mengosongkan buffer kop/kaki
  mpdf. Isi mengalir zona itu kini ikut ditulis sebagai kotak berposisi tetap.
- Diukur terhadap cetak Chrome pada sembilan konfigurasi (tinggi tetap dan `auto`, dengan dan
  tanpa QR tetap, satu dan tiga halaman): posisi baris kop dan kaki berselisih paling banyak
  0,3 mm.

### Diubah
- **PDF berubah** untuk template yang kakinya bertinggi tetap atau memuat blok dengan "jarak
  sesudah": isi kaki pindah ke posisi yang sama dengan builder dan cetak browser.

### Diketahui
- Kop atau kaki bertinggi `auto` masih memakai ruang cadangan tetap di mpdf (35 mm dan 12 mm),
  sehingga jumlah baris isi per halaman bisa berbeda dari cetak browser. Tetapkan tinggi zona di
  schema untuk hasil yang sama.

## [1.9.3] — 2026-10-06

### Diperbaiki
- Tabel, tanda tangan, meta surat, dan penerima di PDF mpdf kini mengikuti `lineHeight` dokumen.
  mpdf memberi `<table>` line-height bawaan 1.2 dan tidak mewariskan nilai `.doc-root` ke dalamnya,
  sehingga tiap baris lebih rapat daripada di browser dan seluruh isi di bawahnya naik (11,7 mm di
  akhir surat satu halaman dengan dua tabel dan tanda tangan). **PDF dokumen yang memuat blok
  tersebut berubah**: isinya turun mengikuti tampilan builder dan cetak browser.
- Blok `qrcode` berukuran pecahan piksel tidak lagi terpotong di cetak browser. Chrome memotong isi
  `<svg>` pada kotak yang dibulatkan ke piksel CSS bulat: QR 25 mm tercetak 24,85 mm (kini
  24,98 mm; mpdf 25,02 mm).

### Diubah
- Jarak antarblok di browser (builder dan cetak) kini dijumlahkan seperti di mpdf: "jarak sesudah"
  blok atas ditambah "jarak sebelum" blok bawah, bukan diambil yang terbesar. **Tampilan builder dan
  cetak berubah** di tempat dua blok bersebelahan sama-sama memiliki jarak itu: celahnya bertambah
  sebesar nilai yang lebih kecil, dan template yang sangat padat bisa meluber ke halaman berikutnya
  (PDF-nya sudah demikian sejak awal). Diukur pada surat satu halaman dan dokumen tiga halaman
  dengan tabel terpotong: selisih posisi baris cetak browser vs mpdf paling banyak 0,6 mm, jumlah
  halaman dan titik potong tabel sama.

### Diketahui
- Zona kaki belum konsisten antara cetak browser dan mpdf, dan ini sudah demikian sebelum rilis
  ini: mpdf menempelkan isi kaki ke bawah kotaknya sedangkan browser ke atas, dan mpdf mengabaikan
  "jarak sesudah" blok di dalam kaki.

## [1.9.2] — 2026-10-05

### Diperbaiki
- Blok `qrcode`: ukuran, ketebalan modul, dan posisi QR di cetak browser kini sama dengan PDF
  mpdf. Chrome membulatkan kotak `<img>` dan posisi `left`/`top` ke piksel CSS bulat (QR 20 mm
  tercetak 20,11 mm dan bergeser sekitar 0,12 mm; modul tampak sedikit lebih tebal). Browser kini
  menerima SVG inline (ukurannya tepat) dan, pada mode `fixed`, posisi lewat
  `transform:translate(...)` yang tidak dibulatkan. Diukur pada render 600 dpi: kiri 30,01 mm,
  atas 239,99 mm, ukuran 19,98 × 20,02 mm di cetak browser maupun mpdf (sebelumnya 29,89 / 239,99 /
  20,11 × 20,11 mm di browser).

### Diubah
- `RenderedDocument::bodyHtmlForEngine()` baru, dan `headerHtmlForEngine()`/`footerHtmlForEngine()`
  kini juga mengubah blok QR menjadi bentuk yang dipahami mpdf: SVG inline menjadi `<img>` data URI
  dan posisi `fixed` ditulis sebagai `top`/`left`. `MpdfEngine` memakainya. HTML untuk browser
  (`bodyHtml()`, `flowHtml()`, `fullHtml()`) berisi `<svg class="db-qrcode__svg">`, bukan `<img>`
  seperti di 1.9.1.

### Diketahui
- Mode QR mengalir (`positionMode` `flow`) di cetak browser masih bisa bergeser hingga sekitar
  0,17 mm dari PDF pada `align` kiri: Chrome membulatkan posisi kotak yang ditentukan tata letak
  (bukan transform). Ukuran dan ketebalan sudah sama.

## [1.9.1] — 2026-10-05

### Diperbaiki
- Blok `qrcode`: prop `sizeMm` ("Ukuran (mm)") sekarang benar-benar mengubah ukuran QR. SVG dari
  `milon/barcode` hanya punya `width`/`height` tanpa `viewBox`, sehingga memperbesar elemennya
  tidak menskalakan isinya (QR selalu sekitar 15 mm di cetak browser maupun PDF).
- PDF (mpdf) tidak lagi mencetak `<?xml version="1.0" standalone="no"?>` sebagai teks di samping QR.
  Penyebabnya prolog XML dan DOCTYPE pada SVG yang disisipkan inline; mpdf tidak memproses SVG
  inline dengan benar.
- Hasil cetak browser dan PDF mpdf kini sama: QR berukuran tepat sesuai `sizeMm` dan rata
  (`align`) di posisi yang sama. Diukur pada render 20 mm dan 60 mm.
- Blok `qrcode` mode `fixed` kini dihormati mpdf. Sebelumnya mpdf mengabaikan `top`/`left` pada
  `position:absolute` yang bersarang di dalam wadah lain (hanya elemen tingkat atas yang dihormati),
  sehingga QR jatuh di posisi alur. `MpdfEngine` sekarang menggambar QR tetap sebagai elemen tingkat
  atas: di kop/kaki ia ikut berulang di setiap halaman, dan di isi dokumen ia digambar tepat setelah
  blok yang memuatnya sehingga jatuh di halaman yang sama dengan bloknya, seperti paginator browser.
  Isi ditulis per potongan blok hanya bila ada QR tetap; dokumen tanpanya ditulis sekali jalan seperti
  sebelumnya. Diukur terhadap cetak browser: posisi kop, isi, dan kaki sama hingga sekitar 0,1 mm.

### Diubah
- Blok `qrcode` kini dirender sebagai `<img class="db-qrcode__img" src="data:image/svg+xml;base64,…">`
  berukuran `sizeMm` × `sizeMm`, bukan `<svg>` inline. Kontrak `QrCodeGenerator::toSvg()` tidak
  berubah; SVG dari pembangkit apa pun dinormalkan lebih dulu (prolog dan DOCTYPE dibuang, `viewBox`
  diturunkan dari `width`/`height`, ukuran diset dalam mm). Aturan CSS `.db-qrcode__svg svg` diganti
  `.db-qrcode__img`. `MilonQrCodeGenerator` menghasilkan satu `<path>` yang jauh lebih ringkas.

## [1.9.0] — 2026-09-29

### Ditambahkan
- Baris kedua teks kanan pada blok `letter-meta` lewat prop baru `rightSubText` (mis. tanggal
  Hijriah di bawah tanggal Masehi). Dirender di sel yang sama dipisah `<br />` (mpdf-safe, tanpa
  tabel bersarang, `rowspan` tetap utuh); template lama yang hanya mengisi `rightText` menghasilkan
  HTML yang identik seperti sebelumnya. Contoh: `rightText` = `Jakarta, <u>1 Januari 2026</u>`,
  `rightSubText` = `1 Ramadhan 1447 H`.
- Kolom `rightText` dan `rightSubText` di inspektor builder memakai mini-RTE (toolbar B/I/U) seperti
  `text`/`itemText`, sehingga garis bawah tanggal bisa diterapkan lewat tombol tanpa mengetik `<u>`.

## [1.8.0] — 2026-09-28

### Ditambahkan
- Variabel tambahan per builder: `TemplateBuilder::mount($template, $extraVariables)` menerima daftar
  entri `{path, label, sample, group?}` di atas katalog bersama `VariableRegistry`. Panel variabel dan
  nilai contoh pratinjau kanvas memakainya. Berguna bila katalog bersama memuat variabel semua jenis
  dokumen tetapi builder tertentu hanya perlu sebagian. Disimpan di property publik
  `$extraVariables` (`#[Locked]`, ikut snapshot Livewire), jadi tetap berlaku di setiap update.
- `VariableRegistry::extend(array $entries): self` — salinan registry ditambah entri; yang asli
  tidak berubah, path yang sama menimpa. Entri salah bentuk melempar `InvalidArgumentException`.

### Diubah
- `TemplateBuilder` membangun katalog gabungan satu kali per request (bukan per pemanggilan
  `render()`/`preview()`). Tanpa `$extraVariables` perilakunya identik dengan 1.7.

## [1.7.0] — 2026-09-26

### Ditambahkan
- Properti posisi pada block QR code: `positionMode` (`flow` bawaan, atau `fixed`), `topMm`,
  `leftMm`. Mode `fixed` merender QR dengan `position:absolute` relatif ke `.doc-page`, pola yang
  sama dipakai watermark — bisa ditaruh di zona header, body, atau footer, dan otomatis terpotong
  pada batas halaman. Block ini tidak menyumbang tinggi ke alur dokumen, jadi tidak pernah memicu
  halaman baru sendiri; ia ikut ke halaman mana pun urutannya jatuh di paginasi.

## [1.6.0] — 2026-09-25

### Ditambahkan
- Tombol *Ekspor* dan *Impor* di toolbar builder Livewire (`TemplateBuilder::exportSchema()` dan
  `applySchemaImport()`). Ekspor mengunduh `$schema` saat ini sebagai berkas JSON lewat jalur BACA
  (`Template::fromArray()` tanpa `$maxBytes`, seperti `preview()`) — template lama yang sudah
  tersimpan di atas `SchemaValidator::MAX_BYTES` tetap bisa diekspor apa adanya. Impor membaca
  berkas JSON yang dipilih pengguna lewat jalur TULIS yang sama dengan `save()`
  (`Template::fromArray()` dengan `SchemaValidator::MAX_BYTES`), sehingga migrasi versi lama dan
  validasi struktur/ukuran tetap ditegakkan sebelum menimpa kanvas.

## [1.5.0] — 2026-09-25

### Ditambahkan
- Watermark teks diagonal di setiap halaman ("DRAF", "RAHASIA", …) lewat key schema opsional
  `watermark: {text, opacity}` dan kelas `Schema\Watermark`. Tampil di kanvas, cetak browser,
  Gotenberg, dan mpdf (`SetWatermarkText`). Ukuran huruf di browser diukur dengan aturan yang sama
  dengan mpdf. Watermark ditempel sesudah paginasi, jadi jumlah halaman tidak berubah.
- Isian *Watermark* dan *Opasitas* di panel Halaman builder, dengan pilihan cepat.
- `DocumentRenderer::render(..., watermark: ?string)` menimpa teks watermark saat render (`''`
  mematikannya), plus `Template::withWatermark()` dan `RenderedDocument::watermark()`.

### Diubah
- `Template::toArray()` kini selalu menyertakan key `watermark`. Konsumen schema non-Blade harus
  toleran pada key ini, sesuai aturan semver di README.

## [1.4.1] — 2026-09-24

### Diperbaiki
- PDF mpdf kini memakai font bermetrik sama dengan layar. Sebelumnya `tinos`/`arimo`/`cousine`
  dipetakan ke font inti `times`/`helvetica`/`courier`, yang tidak dipakai mpdf dalam mode utf-8,
  sehingga seluruh teks jatuh ke DejaVu (±16% lebih lebar): baris yang pas di browser terlipat di
  PDF, mis. nama di blok tanda tangan. Berkas Liberation Serif/Sans/Mono (SIL OFL, metrik identik
  Times/Arial/Courier) kini disertakan dan didaftarkan lewat `fontdata`, dan `resolvedCss()`
  menaruh keluarga mpdf paling depan karena mpdf hanya membaca nama pertama di `font-family`.

## [1.4.0] — 2026-09-24

### Ditambahkan
- Variabel pada sumber gambar: `src` blok `image` dan `letterhead-image`, `logo` blok `letterhead`,
  serta gambar tanda tangan per kolom kini boleh berisi token seperti `{{ school.letterhead }}`.
  Nilai diisi mentah lalu tetap melewati `ImageSourcePolicy`; token yang tidak dikenal
  menampilkan penanda. Cadangan tinggi kop untuk mpdf ikut membaca sumber yang sudah diisi.
- `VariableSyntax::applyRaw()` (nilai tanpa escape, null bila ada token tak terisi) dan
  `RenderContext::source()`.

## [1.3.0] — 2026-09-24

### Ditambahkan
- Variabel bawaan `Variable\BuiltinVariables`: `today.long`, `today.short`, `today.full`,
  `today.day`, `today.date`, `today.month`, `today.month_roman`, `today.year` (nama hari/bulan
  berbahasa Indonesia), plus `page`/`pages` di panel. Di Laravel otomatis masuk panel builder
  (lewat `extend()`, jadi tetap ada meski aplikasi mem-bind ulang `VariableRegistry`) dan diisi
  `DocumentRenderer` juga saat mencetak dengan resolver aplikasi. Definisi dan nilai aplikasi
  untuk path yang sama selalu menang. Dimatikan dengan config `variables.builtin = false`.
- `DocumentRenderer` menerima argumen konstruktor opsional `?BuiltinVariables` (kelima).

### Diubah
- `document-builder:doctor` menghitung variabel aplikasi terpisah dari variabel bawaan, dan tetap
  memperingatkan bila katalog aplikasi belum di-bind.
- Bila Anda mem-publish config, tambahkan blok `variables` dari config paket (opsional; tanpa
  itu variabel bawaan tetap aktif).

## [1.2.1] — 2026-09-24

### Diperbaiki
- Inspector dan panel pengaturan halaman kini merender ulang kanvas saat nilainya diubah di
  Livewire 3 dan 4. Sejak Livewire 3, `wire:model` tanpa `.live` bersifat deferred sehingga
  `updated()` (pemicu pratinjau) tidak pernah terpanggil sampai ada aksi lain. Semua binding
  `schema.*` kini memakai `wire:model.live` / `wire:model.live.debounce.500ms`; Livewire 2
  mengabaikan `.live`, jadi perilakunya di sana tidak berubah.
- Bila Anda mem-publish view builder, tambahkan `.live` yang sama di salinan Anda.

## [1.2.0] — 2026-09-24

### Dihapus
- Stub migration `create_document_templates_table` beserta tag publish `document-builder-migrations`;
  `document-builder:install` tidak lagi mempublish migration. Tabel dan model template sepenuhnya
  milik aplikasi — package hanya mengenal kontrak `TemplateRecord`. Migration yang sudah
  dipublish di aplikasi Anda tidak tersentuh.

## [1.1.1] — 2026-09-24

### Diperbaiki
- Paket kini bisa dipasang di Laravel 13: constraint `illuminate/*` (dan `laravel/framework` di
  require-dev) diperluas ke `^13.0`. Diuji dengan Laravel 13.33 + Livewire 3.8.

## [1.1.0] — 2026-09-24

### Ditambahkan
- Tipe blok `recipient` (Penerima): pembuka (`heading`), satu entri per butir koleksi `recipients`
  dengan format `itemText` tempat `{{ recipient.* }}` diikat ke butir yang sedang dirender, lalu
  penutup (`closing`). Penomoran `auto`/`always`/`never`, baris kosong dibuang, koleksi kosong tidak
  dirender, dan resolver tanpa koleksi jatuh ke perilaku satu penerima. Ditambahkan di akhir
  `BlockType` supaya urutan palet konsumen tidak bergeser.
- Interface publik `Variable\CollectionVariableResolver` (`collection(string $name): ?array`),
  diimplementasikan `ArrayVariableResolver` (list berisi array dibaca sebagai koleksi) dan resolver
  contoh `VariableRegistry`. `VariableRegistry::defineCollection()` / `hasCollection()` untuk contoh
  butir di kanvas builder; `VariableSyntax::resolver()` untuk membaca resolver dokumen.
- Inspektor Blade memakai Mini-RTE juga untuk `itemText`.

### Diperbaiki
- Gambar tanda tangan dan blok gambar kini mengikuti `textAlign`/`align` di kanvas builder yang
  tertanam di halaman aplikasi. Reset CSS host (mis. preflight Tailwind `img { display: block }`)
  membuat `text-align` sel diabaikan sehingga gambar menempel ke tepi kiri; `document.css` kini
  menegaskan `display: inline` untuk `.db-signature__image` dan `.db-image__img`. Hasil cetak dan PDF
  tidak berubah karena nilainya sama dengan bawaan peramban.

## [1.0.0] — 2026-09-23

Rilis pertama sebagai paket Composer privat, dipasang lewat repository `vcs` (atau `path` di dalam
monorepo).

### Ditambahkan
- `PropCatalog::describe()` — definisi properti beserta label, grup, dan label nilai enum dalam satu
  bentuk siap JSON; `LabelTranslator` sebagai seam penerjemah, tersambung ke `trans()` lewat
  `TransLabelTranslator`.
- `SchemaMigrator` — schema lama dinaikkan ke versi berjalan sebelum validasi.
- Batas ukuran schema `SchemaValidator::MAX_BYTES` (256 KB), ditegakkan hanya di jalur tulis.
- `ContractPayload` dan `resources/js/contract.d.ts` untuk UI non-Blade.
- Kontrak `TemplateRecord` + trait `IsTemplateRecord`, dan `TemplateRecordContractTests` untuk
  diuji di aplikasi konsumen.
- Komponen penyusun Livewire `document-builder::template-builder` beserta Blade-nya (publishable),
  mendukung Livewire 2 dan 3 lewat satu titik adaptasi.
- `Laravel\DocumentRenderer` dengan `VariableRegistry` dari container.
- `builder.mjs` mengembalikan handle `applyPreview()` dengan pembuangan `revision` kedaluwarsa.
- Sunting teks langsung di kanvas (inline edit).
- Perintah `document-builder:install` dan `document-builder:doctor`; stub migration; tag publish
  `document-builder-config`, `-views`, `-migrations`, dan agregat `document-builder`.
- `bin/document-builder-verify-print` yang portabel.
- Contoh berjalan di `examples/livewire` dan `examples/inertia-react`.
- `phpunit.xml.dist` — test paket berjalan mandiri lewat `vendor/bin/phpunit`.

### Diperbaiki
- Dependensi yang dipakai tetapi tidak dideklarasikan kini tercantum: `illuminate/console`,
  `illuminate/contracts`, `illuminate/http` (require) serta `ext-gd`, `laravel/framework`,
  `mpdf/mpdf` (require-dev).
- Template tersimpan di atas batas ukuran kini tetap bisa dibuka, disunting, dicetak, dan diunduh
  PDF-nya; batas hanya berlaku saat menyimpan.
- State kanvas tidak lagi bocor antar pemanggilan `initBuilder()` pada halaman yang sama.
