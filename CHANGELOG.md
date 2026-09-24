# Changelog

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/); versi mengikuti semver
sebagaimana dijelaskan di README ("API publik & versi").

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
