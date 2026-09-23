# Changelog

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/); versi mengikuti semver
sebagaimana dijelaskan di README ("API publik & versi").

## [Unreleased] — 1.0.0

Akan ditag `1.0.0` saat paket diekstrak menjadi repo sendiri (Tahap A). Sampai saat itu paket
dipasang lewat repository `path`.

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

### Diperbaiki
- Template tersimpan di atas batas ukuran kini tetap bisa dibuka, disunting, dicetak, dan diunduh
  PDF-nya; batas hanya berlaku saat menyimpan.
- State kanvas tidak lagi bocor antar pemanggilan `initBuilder()` pada halaman yang sama.
