# Tutorial: Instalasi & Penggunaan `document-builder`

Panduan praktis. Arsitektur dan alasan desain ada di `README.md` — tidak diulang di sini.

- **Pengembang** yang memasang package: pilih **1A** (Blade + Livewire) atau **1B** (Inertia + React),
  lalu baca **1C** (referensi bersama).
- **Admin/staf** yang menyusun surat: langsung ke **2. Cara Penggunaan**.

Kalau Anda harus membuka kode package atau bertanya untuk bisa memasangnya, itu bug dokumentasi —
laporkan.

---

## 1A. Quickstart: Blade + Livewire

Prasyarat: Laravel 10+, PHP 8.1+, Livewire 2.12 atau 3, Bootstrap 5 di layout Anda.

**1. Pasang paketnya.** Paket ini privat dan tidak ada di Packagist; daftarkan repo-nya di
`composer.json` aplikasi Anda:

```json
"repositories": [{ "type": "vcs", "url": "git@github.com:xdn27/document-builder.git" }]
```

```bash
composer require maqiis/document-builder:^1.0 mpdf/mpdf
```

Composer butuh akses baca ke repo tersebut: kunci SSH yang terdaftar di GitHub, atau token lewat
`composer config --global github-oauth.github.com <token>` (di CI, simpan sebagai `auth.json` atau
variabel `COMPOSER_AUTH`). Bila sumber paket ada di dalam repo aplikasi (monorepo), pakai repository
`path` — Composer men-symlink folder itu. Versinya dibaca dari branch yang sedang aktif
(`dev-main`, dialiaskan ke `1.x-dev`), jadi constraint-nya `^1.0@dev`:

```json
"repositories": [{ "type": "path", "url": "packages/document-builder" }]
```

```bash
composer require maqiis/document-builder:^1.0@dev mpdf/mpdf
```

Service provider terdaftar otomatis (auto-discovery).

**2. Jalankan installer.**

```bash
php artisan document-builder:install
php artisan migrate
```

Installer mem-publish `config/document-builder.php` dan migration `document_templates` (kolom `name`
dan `schema`; tambahkan kolom milik Anda sebelum `migrate`), mencetak empat langkah manual di bawah,
lalu menjalankan `document-builder:doctor`. Menjalankannya ulang aman: berkas yang sudah ada dilewati
kecuali dengan `--force`.

**3. Empat langkah yang Anda tulis sendiri** — salinan siap pakai ada di
`examples/livewire/`:

1. **Model** — `implements TemplateRecord` + `use IsTemplateRecord`, dan tulis
   `authorizeTemplateView()` sendiri (trait sengaja tidak menyediakannya: aturan akses lihat adalah
   keputusan aplikasi).
2. **Katalog variabel** — bind `Maqiis\DocumentBuilder\Variable\VariableRegistry` secara `scoped` di
   service provider.
3. **Route + view pembungkus** yang memasang
   `@livewire('document-builder::template-builder', ['template' => $template])`.
4. **Config** — isi `document-builder.livewire.index_route`, `print_route`, `print_ability`,
   `update_ability`; pastikan ability update ada di sistem permission Anda.

> **Layout wajib punya `@stack('script')` sesudah `@livewireScripts`.** View builder mendorong
> skrip kanvasnya ke stack bernama `script` (tunggal). Layout yang hanya punya `@stack('scripts')`
> membuat kanvas tidak pernah hidup — tanpa error apa pun.

**4. Sambungkan toast.** Package mengirim event netral; tentukan sendiri cara menampilkannya:

```html
<script>window.documentBuilderNotify = (detail) => Swal.fire({ toast: true, icon: detail.level, title: detail.message });</script>
```

**5. Periksa.** `php artisan document-builder:doctor` harus tanpa `FAIL`. Buka halaman builder,
tambah blok — blok harus langsung muncul di kanvas.

## 1B. Quickstart: Inertia + React

Langkah 1–2 sama dengan 1A (Livewire tidak dibutuhkan). Lalu, dari `examples/inertia-react/`:

1. **Model & katalog variabel** — sama dengan 1A langkah 3.1–3.2.
2. **Controller** — tiga method: `show` (props awal dari `ContractPayload::forRegistry()`),
   `preview` (render `{html, css}` dan kembalikan `revision` apa adanya), `save`
   (`Template::fromArray($schema, SchemaValidator::MAX_BYTES)` — batas ukuran hanya di jalur tulis).
3. **Alias Vite** — `@document-builder` → `vendor/maqiis/document-builder/resources/js`. Tidak ada
   paket npm; kode JS selalu versi yang sama dengan PHP yang terpasang.
4. **Halaman React** — bangun palet dan inspektor dari `contract.blockPropSchema` (label dan grup
   sudah ada di sana, jangan tulis ulang), lalu setelah menaruh `{html, css}` ke DOM panggil
   `paginateDocument({ document, root })`.

Tiga aturan yang tidak boleh dilewatkan:

- **Periksa `contract.contract`** dan gagal keras bila tidak cocok — jangan merender inspektor
  separuh jadi.
- **Pratinjau:** debounce 300–500 ms, batalkan request yang masih terbang (`AbortController`), dan
  buang respons yang `revision`-nya lebih kecil dari yang terakhir dilukis.
- **Tipe blok atau properti yang tidak dikenal** ditampilkan sebagai penanda dan dipertahankan saat
  menyimpan — jangan crash. Aturan inilah yang membuat penambahan tipe blok baru cukup rilis minor.

## 1C. Referensi bersama

### Konfigurasi

| Key | Env | Bawaan | Kegunaan |
|---|---|---|---|
| `engine` | `DOCUMENT_BUILDER_PDF_ENGINE` | `mpdf` | `mpdf` atau `gotenberg` |
| `engines.gotenberg.base_url` | `GOTENBERG_URL` | `http://gotenberg:3000` | Hanya dipakai saat `engine=gotenberg` |
| `images.allow_data_uri` | — | `true` | Izinkan gambar data URI |
| `images.allowed_prefixes` | — | `[]` | Awalan URL gambar tambahan yang dipercaya. **Jangan pernah berisi string kosong** — `doctor` menandainya GAGAL |
| `images.upload_strategy` | `DOCUMENT_BUILDER_UPLOAD_STRATEGY` | `filesystem` | `filesystem` (disarankan) atau `data-uri` |
| `images.upload_disk` | `DOCUMENT_BUILDER_UPLOAD_DISK` | `public` | Disk yang bisa diakses browser; jalankan `php artisan storage:link` |
| `images.upload_directory` | `DOCUMENT_BUILDER_UPLOAD_DIRECTORY` | `document-builder` | Sub-folder di disk tujuan |
| `livewire.block_palette` | — | lihat config | Isi awal tiap blok baru |
| `livewire.index_route`, `print_route` | — | `null` | Nama route aplikasi; `null` = tautan tidak dirender |
| `livewire.print_ability`, `update_ability` | — | `null`, `update-document-template` | Ability yang diperiksa view |

`mergeConfigFrom()` Laravel hanya menggabung di level teratas: kunci baru yang ditambahkan package
**di dalam** blok yang sudah Anda publish tidak muncul otomatis — salin manual saat naik versi (lihat
`UPGRADING.md`).

### Engine PDF Gotenberg

Tambahkan ke `compose.yml`:

```yaml
gotenberg:
  image: gotenberg/gotenberg:8
  restart: unless-stopped
```

lalu `DOCUMENT_BUILDER_PDF_ENGINE=gotenberg` dan `GOTENBERG_URL=http://gotenberg:3000`. Gotenberg
merender HTML kanvas yang sama persis lewat Chromium. `doctor` memeriksa layanannya terjangkau.

### Render, cetak, dan PDF

Semua lewat `Maqiis\DocumentBuilder\Laravel\DocumentRenderer` dari container — jangan menyusun HTML
surat lewat Blade sendiri:

```php
$document = app(DocumentRenderer::class)->render($model->template());   // atau Template::fromArray($array)

$document->fullHtml(autoPrint: true);        // halaman cetak
app(PdfEngine::class)->render($document);    // bytes PDF
$document->flowHtml();                       // HTML mengalir untuk kanvas
```

### Bahasa label

Label bawaan bahasa Indonesia. Untuk bahasa lain, buat
`lang/vendor/document-builder/{locale}/labels.php` dengan kunci `prop`, `value`, `group`, dan `block`,
misalnya `['prop' => ['align' => 'Alignment'], 'block' => ['paragraph' => 'Paragraph']]`. Kunci yang
tidak diterjemahkan tetap tampil dalam bahasa Indonesia.

### Tidak ada langkah build aset

CSS dokumen dan JS paginator disisipkan inline lewat `AssetLoader` — tidak ada yang dipublish ke
`public/`, jadi kanvas dan hasil cetak tidak pernah memakai versi aset yang berbeda.

### Verifikasi

```bash
php artisan document-builder:doctor
vendor/bin/document-builder-verify-print vendor/maqiis/document-builder/tests/fixtures/surat-tabel-panjang.json 3
```

`verify-print` butuh Chrome di host (`CHROME_BIN`). Bila PHP Anda di container, setel
`DOCUMENT_BUILDER_CONTAINER=<nama>` (preview di `/app/packages/document-builder`) atau
`DOCUMENT_BUILDER_PHP` + `DOCUMENT_BUILDER_PREVIEW`.

---

## 2. Cara Penggunaan

Bagian ini menjelaskan alur kerja dari sudut pandang pengguna panel admin.

### 2.1 Membuat template baru

Di halaman daftar (**Template Dokumen**), klik **Tambah**, isi:

- **Cabang** — hanya cabang yang boleh Anda kelola yang muncul di pilihan.
- **Nama** — judul template, mis. "Surat Keterangan Aktif".
- **Kategori** — bebas, dipakai untuk pengelompokan di daftar (opsional).
- **Keterangan** — catatan singkat (opsional).

Template baru dimulai kosong (belum ada blok apa pun). Klik nama template untuk membuka
**Builder**.

### 2.2 Menyusun isi surat di Builder

Builder terbagi tiga panel:

1. **Kiri** — daftar tipe blok yang bisa ditambahkan, dipisah per zona (kop / isi / kaki).
2. **Tengah** — kanvas: pratinjau halaman yang diperbarui otomatis setiap properti berubah,
   sudah terpaginasi persis seperti hasil cetak.
3. **Kanan** — inspektor: properti blok yang sedang dipilih.

Klik sebuah blok di kanvas (atau di daftar urutan) untuk menyeleksinya — propertinya muncul di
inspektor kanan. Urutan blok dalam satu zona bisa diseret-ubah (drag-and-drop) di daftar urutan.

#### Tipe blok yang tersedia

| Tipe | Kegunaan | Dipecah antar halaman? |
|---|---|---|
| Kop Surat (`letterhead`) | Logo + hingga empat baris teks + garis bawah | Tidak |
| Kop Gambar (`letterhead-image`) | Gambar penuh lebar, menembus tepi kiri/kanan/atas kertas | Tidak |
| Meta Surat (`letter-meta`) | Baris Nomor / Lampiran / Hal, dengan teks kanan opsional (tempat & tanggal) | Tidak |
| Paragraf (`paragraph`) | Teks biasa, boleh memuat `<b>`, `<i>`, `<u>`, `<br>` | Tidak — pindah utuh ke halaman berikutnya bila tak muat |
| Tanda Tangan (`signature`) | 1–3 kolom blok tanda tangan | Tidak |
| Tabel (`table`) | Tabel dengan header yang diulang di tiap halaman | Ya, per baris |
| Daftar (`list`) | Poin atau nomor bertingkat (`1.` / `a.` / `1)`) | Ya, per butir |
| Gambar (`image`) | Gambar tunggal dari sumber yang diizinkan | Tidak |
| QR Code (`qrcode`) | Kode QR dari teks atau variabel | Tidak |
| Spasi (`spacer`) | Jarak vertikal kosong | Tidak |
| Garis (`divider`) | Garis pemisah horizontal | Tidak |

Kop dan kaki bisa diatur tampil di **semua halaman**, **hanya halaman pertama**, atau **selain
halaman pertama** — pengaturan ini ada di properti zona, bukan per blok.

#### Font & teks Arab (RTL)

Font dipilih untuk seluruh dokumen di properti gaya dokumen (bukan per blok): **Tinos**, **Arimo**,
**Cousine** (metrik-kompatibel dengan Times New Roman/Arial/Courier New), atau **Almarai** — font
Arab & Latin sekaligus, cocok untuk surat yang memuat kutipan Arab (basmalah, salam, dsb.) selain
teks Indonesia.

Almarai didukung penuh di kedua engine PDF (mpdf maupun Gotenberg) — tidak perlu pengaturan
tambahan apa pun saat memilihnya.

Untuk paragraf berisi teks Arab, atur propertinya di inspektor:

- **Arah teks** → **Kanan ke kiri (Arab)**
- **Perataan** → **Kanan** (opsional, tapi lazim untuk teks Arab)

Kanvas builder langsung menampilkan hasilnya persis seperti PDF-nya nanti (Chromium yang sama-sama
dipakai keduanya) — tersambung dan rata kanan otomatis, tanpa langkah tambahan.

#### Memakai variabel

Ketik token seperti `{{ student.name }}` di dalam teks blok apa pun yang menerimanya (paragraf,
kop surat, meta surat, dll.) — panel **Variabel** di kanan bawah inspektor mencantumkan semua
token yang tersedia beserta label dan contoh nilainya, tinggal disalin. Contoh yang sudah
terdaftar di project ini: `institution.name`, `student.name`, `employee.position`,
`letter.number`, `today.long`. Di zona kaki, tersedia tambahan `{{ page }}` dan `{{ pages }}`
untuk nomor halaman — diisi otomatis saat paginasi, bukan saat disunting.

#### Mengunggah gambar

Setiap properti bergambar (kop gambar, blok gambar, gambar tanda tangan per kolom) punya tombol
**Unggah gambar** di inspektor — menerima PNG, JPG, atau WEBP, maksimal 2 MB. Ada juga kolom teks
di bawahnya untuk menempel data URI atau URL secara manual bila diperlukan.

Ke mana berkas itu tersimpan bergantung `document-builder.images.upload_strategy` (lihat §1C, "Konfigurasi"):
ditempel sebagai base64 langsung ke template (`data-uri`, bawaan), atau disimpan ke disk dan
hanya URL-nya yang dicatat (`filesystem`).

> **Kalau gambar tidak muncul setelah diunggah:** hampir selalu soal konfigurasi disk, bukan
> bug pemakaian. Marker **"Sumber gambar ditolak"** berarti URL-nya tidak ada di daftar
> `allowedPrefixes`; marker **"tidak dapat dibaca"** berarti dimensinya gagal dibaca; gambar
> yang sama sekali tidak tampil (tanpa marker apa pun, cuma kotak kosong / ikon rusak di
> browser) biasanya berarti disk yang dikonfigurasi bukan disk publik yang sesungguhnya bisa
> diakses browser. Pastikan `upload_disk` menunjuk disk dengan config `url` yang benar
> (bawaannya `public`) dan `php artisan storage:link` sudah pernah dijalankan.

### 2.3 Pratinjau, cetak, dan unduh PDF

- **Cetak** membuka `{template}/print` di tab baru — halaman HTML mandiri yang langsung memanggil
  `window.print()`. Ini bukan simulasi: DOM yang tercetak persis DOM yang terlihat di kanvas
  builder.
- **Unduh PDF** memanggil `{template}/pdf`, dirender lewat `mpdf` (atau engine lain yang
  dikonfigurasi).

Kedua jalur memakai renderer dan schema yang sama persis — tidak ada view Blade terpisah yang
bisa membuat keduanya berbeda.

### 2.4 Menduplikasi template

Tombol **Duplikat** di daftar template membuat salinan persis (nama, kategori, dan seluruh isi
blok) dengan akhiran "(salinan)" — berguna untuk membuat variasi dari template yang sudah ada
tanpa menyusun ulang dari nol.

---

## 3. Selanjutnya

- **Menambah tipe blok baru**, **mengganti engine PDF**, atau memahami **kenapa paginator
  bekerja seperti sekarang** — baca `README.md`.
- **Memakai core tanpa Laravel sama sekali** (mis. dari CLI atau job queue) — lihat bagian
  "Memakai core tanpa Laravel" di `README.md`.
