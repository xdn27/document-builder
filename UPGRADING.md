# Upgrading

## Dari pemakaian di dalam repo (pra-1.0) ke 1.0

Langkah 2–7 sudah ditempuh akademik-maqiis (Gate 3); langkah 1 menyusul saat paket diekstrak
menjadi repo sendiri (Tahap A) — sampai itu repo tersebut masih memetakan PSR-4 dan mendaftarkan
provider secara manual. Ulangi semuanya di aplikasi lain yang masih memakai salinan lama.

1. **Pasang lewat Composer** (repository `path` atau VCS), hapus pemetaan PSR-4 manual dan
   pendaftaran provider manual di `config/app.php` — auto-discovery mengambil alih.
2. **Model** — `implements TemplateRecord` + `use IsTemplateRecord`; pindahkan pemeriksaan akses yang
   dulu ada di `mount()` komponen Anda ke `authorizeTemplateView()`.
3. **Renderer** — hapus service render milik aplikasi; pakai `Maqiis\DocumentBuilder\Laravel\DocumentRenderer`
   dari container. Pemanggil `preview($model)` menjadi `render($model->template())`.
4. **Katalog variabel** — bind `VariableRegistry` secara `scoped` di service provider aplikasi.
5. **Komponen** — hapus komponen penyusun milik aplikasi; arahkan view pembungkus ke
   `@livewire('document-builder::template-builder', …)` dan isi `document-builder.livewire.*`.
6. **Toast** — ganti panggilan toaster di komponen lama dengan `window.documentBuilderNotify`.
7. **Verifikasi** — rekam keluaran render seluruh fixture sebelum langkah 1 dan bandingkan
   byte-per-byte sesudahnya (golden master). Pemindahan yang benar tidak mengubah satu byte pun.

## Menaikkan versi paket

- Setelah `composer update`, bandingkan `config/document-builder.php` Anda dengan
  `vendor/maqiis/document-builder/src/Laravel/config/document-builder.php`: kunci baru **di dalam**
  blok yang sudah Anda publish tidak ditambahkan otomatis.
- Bila Anda mem-publish view builder (`--views`), bandingkan juga dengan view paket — salinan Anda
  tidak ikut diperbarui.
- Jalankan `php artisan document-builder:doctor`.
