# Changelog

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/); versi mengikuti semver
sebagaimana dijelaskan di README ("API publik & versi").

## [Unreleased]

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
