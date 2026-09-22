# Tutorial: Instalasi & Penggunaan `document-builder`

Dokumen ini adalah panduan praktis langkah-demi-langkah. Untuk arsitektur, alasan desain, dan
detail ekstensibilitas, baca `README.md` di folder yang sama — file ini sengaja tidak
mengulanginya.

Ditujukan untuk dua pembaca:

- **Pengembang** yang memasang package ini di sebuah project Laravel (bagian "Instalasi").
- **Admin/staf** yang menyusun surat lewat halaman builder (bagian "Cara Penggunaan").

---

## 1. Instalasi

Package ini **tidak dipasang lewat `composer require`** dari Packagist — sumbernya ditaruh
langsung di dalam repo (`packages/document-builder/`) dan dikenalkan ke Composer lewat
pemetaan PSR-4 manual. Contoh nyatanya sudah berjalan di project ini; ikuti langkah yang sama
untuk memasangnya di project Laravel lain.

### 1.1 Prasyarat

- Laravel 10, PHP 8.1+
- Livewire 2.x (panel builder adalah komponen Livewire)
- `mpdf/mpdf` bila ingin ekspor PDF (engine bawaan)
- Model Eloquent untuk menyimpan template — package **tidak** menyediakan migrasi atau model,
  hanya memvalidasi dan merender schema JSON-nya (lihat 1.4)

### 1.2 Salin sumber & daftarkan autoload

Taruh folder `document-builder/` di `packages/`, lalu tambahkan namespace-nya ke `composer.json`
root project (bukan `repositories` + `require`, karena package ini tidak punya `composer.json`
yang berdiri sendiri sebagai paket terinstal):

```json
{
    "autoload": {
        "psr-4": {
            "Maqiis\\DocumentBuilder\\": "packages/document-builder/src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Maqiis\\DocumentBuilder\\Tests\\": "packages/document-builder/tests/"
        }
    }
}
```

Jalankan `composer dump-autoload` setelahnya.

### 1.3 Daftarkan service provider secara manual

Karena package ini bukan paket Composer yang "terinstal" sungguhan, mekanisme
**auto-discovery Laravel tidak akan menemukannya** — provider harus didaftarkan tangan di
`config/app.php`:

```php
'providers' => [
    // ...
    Maqiis\DocumentBuilder\Laravel\DocumentBuilderServiceProvider::class,
],
```

`DocumentBuilderServiceProvider` inilah yang mengikat `PdfEngine`, `ImageSourcePolicy`,
`QrCodeGenerator`, dan `ImageUploadStorage`/`ImageResolver` ke container — tidak ada binding
lain yang perlu ditambahkan di project Anda sendiri.

### 1.4 Publish & sesuaikan konfigurasi

```bash
php artisan vendor:publish --tag=document-builder-config
```

Ini menyalin `config/document-builder.php` ke project Anda. Opsi pentingnya:

| Key | Env | Bawaan | Kegunaan |
|---|---|---|---|
| `engine` | `DOCUMENT_BUILDER_PDF_ENGINE` | `mpdf` | Engine PDF aktif — `mpdf` atau `gotenberg` |
| `engines.gotenberg.base_url` | `GOTENBERG_URL` | `http://gotenberg:3000` | URL layanan Gotenberg (lihat §1.4.1) — hanya dipakai saat `engine=gotenberg` |
| `images.allow_data_uri` | — | `true` | Izinkan gambar sebagai data URI base64 |
| `images.allowed_prefixes` | — | `[]` | Awalan URL gambar tambahan yang dipercaya (di luar disk `/storage` yang sudah otomatis) |
| `images.upload_strategy` | `DOCUMENT_BUILDER_UPLOAD_STRATEGY` | `data-uri` | `data-uri` (tempel base64 ke schema) atau `filesystem` (simpan ke disk, tulis URL-nya saja) |
| `images.upload_disk` | `DOCUMENT_BUILDER_UPLOAD_DISK` | `public` | Disk Laravel tujuan saat strategi `filesystem` — **harus** disk yang benar-benar bisa diakses browser (punya config `url`), jangan disk privat seperti `local` bawaan Laravel |
| `images.upload_directory` | `DOCUMENT_BUILDER_UPLOAD_DIRECTORY` | `document-builder` | Sub-folder di dalam disk tujuan |

Kalau memakai `upload_strategy=filesystem` dengan disk lokal, jalankan juga:

```bash
php artisan storage:link
```

#### 1.4.1 (Opsional) Engine PDF Gotenberg — kesetiaan Chromium tanpa penyesuaian manual

`GotenbergEngine` merender lewat layanan terpisah yang membungkus Chromium sungguhan, memakai HTML
kanvas yang sama persis dengan builder — kesetiaan layar=cetak didapat gratis tanpa penyesuaian tata
letak manual seperti yang dilakukan `MpdfEngine` (mis. `neutralizeTopBleed()`). Semua font, termasuk
Almarai, sudah didukung penuh di kedua engine; pertimbangkan Gotenberg terutama kalau Anda ingin
menghindari sepenuhnya jalur pemaginasian mpdf sendiri dan lebih memercayakan hasil cetak ke mesin
render browser yang sama dipakai kanvas.

Jalankan sebagai container terpisah (tambahkan ke `compose.yml` Anda):

```yaml
gotenberg:
  image: gotenberg/gotenberg:8
  restart: unless-stopped
```

Lalu set di `.env`:

```
DOCUMENT_BUILDER_PDF_ENGINE=gotenberg
GOTENBERG_URL=http://gotenberg:3000
```

Tidak perlu langkah lain — `GotenbergEngine` memakai HTML kanvas yang sama persis, jadi kesetiaan
layar=cetak justru lebih terjamin dibanding mpdf.

### 1.5 Model & migrasi Eloquent (tanggung jawab project Anda)

Package hanya tahu cara memvalidasi dan merender **array** schema — cara Anda menyimpannya
terserah Anda. Di project ini, modelnya (`App\Models\DocumentBuilder\DocumentTemplate`) sengaja
berada di app, bukan di package:

```php
class DocumentTemplate extends Model
{
    protected $casts = ['schema' => 'array', 'is_active' => 'boolean'];

    public function template(): \Maqiis\DocumentBuilder\Schema\Template
    {
        return \Maqiis\DocumentBuilder\Schema\Template::fromArray($this->schema ?? []);
    }
}
```

Kolom minimal yang dibutuhkan: `name` (string) dan `schema` (json). Kolom lain
(`branch_id`, `category`, `is_active`, dll.) murni kebutuhan aplikasi Anda — lihat
`database/migrations/2026_09_14_000001_create_document_templates_table.php` sebagai contoh.

### 1.6 Render, cetak, dan PDF lewat satu pintu

Jangan menyusun HTML surat sendiri lewat view Blade — itu akan membuat tampilan layar dan hasil
cetak berbeda. Buat satu service tipis yang membungkus `HtmlRenderer`, seperti
`App\Services\DocumentBuilder\DocumentRenderer` di project ini:

```php
class DocumentRenderer
{
    public function __construct(
        private readonly VariableCatalog $catalog,   // registry variabel Anda sendiri
        private readonly ImageSourcePolicy $images,   // dari container, sudah diikat provider
        private readonly QrCodeGenerator $qr,         // dari container
        private readonly ImageResolver $imageResolver, // dari container
    ) {}

    public function render(Template $template, ?VariableResolver $resolver = null): RenderedDocument
    {
        $context = new RenderContext(
            $template->style, $template->page, new HtmlSanitizer,
            new VariableSyntax($resolver ?? $this->catalog->registry()->sampleResolver()),
            $this->images, $this->qr, $this->imageResolver,
        );

        return (new HtmlRenderer)->render($template, $context);
    }
}
```

Tiga pemakaian `RenderedDocument` yang dihasilkan:

```php
$document = $renderer->render($template);

$document->fullHtml(autoPrint: true);   // halaman cetak siap window.print()
(new MpdfEngine)->render($document);    // bytes PDF (atau resolve PdfEngine dari container)
$document->flowHtml();                  // HTML mengalir untuk disuntik ke kanvas builder
```

### 1.7 Routes, controller, permission, dan menu

Tidak ada route bawaan dari package — buat sendiri sesuai kebutuhan navigasi aplikasi Anda.
Pola minimal yang dipakai di project ini (lihat `routes/admin/document-builder.php` dan
`app/Http/Controllers/Admin/DocumentBuilder/DocumentTemplateController.php`):

```php
Route::prefix('document-template')->name('document-template.')->group(function () {
    Route::get('/', [DocumentTemplateController::class, 'index'])->name('index');
    Route::get('{documentTemplate}/builder', [DocumentTemplateController::class, 'builder'])->name('builder');
    Route::get('{documentTemplate}/print', [DocumentTemplateController::class, 'print'])->name('print');
    Route::get('{documentTemplate}/pdf', [DocumentTemplateController::class, 'pdf'])->name('pdf');
});
```

Membuat, menyunting, dan menghapus baris template ditangani komponen Livewire di halaman daftar
(`ShowDocumentTemplate`) — tidak perlu route `store`/`update`/`destroy` terpisah. Menyusun isi
surat (blok-blok di dalamnya) ditangani komponen Livewire terpisah di halaman builder
(`TemplateBuilder`).

Permission yang lazim dipakai (sesuaikan dengan sistem otorisasi Anda sendiri):
`read-document-template`, `create-document-template`, `update-document-template`,
`delete-document-template`, `print-document-template`.

### 1.8 Tidak ada langkah build aset

JS paginator dan CSS dokumen **disisipkan inline** ke halaman lewat
`Maqiis\DocumentBuilder\Asset\AssetLoader` — tidak ada `npm install`, tidak ada langkah Vite,
tidak ada file untuk di-publish ke `public/`. Ini sengaja: aset yang basi (beda versi antara
yang dipakai kanvas dan yang dipakai cetak) adalah sumber bug paling berbahaya untuk package
ini, jadi keduanya selalu dibaca langsung dari `packages/document-builder/resources/` saat
request diproses.

```blade
<script>
    (function() {
        {!! \Maqiis\DocumentBuilder\Asset\AssetLoader::bundledJs() !!}
        document.addEventListener('livewire:load', () => initBuilder({ /* ... */ }));
    })();
</script>
```

### 1.9 Verifikasi instalasi

```bash
./vendor/bin/phpunit --testsuite=DocumentBuilder
node --test "packages/document-builder/tests/js/*.test.mjs"
```

Kalau keduanya lulus, buka halaman daftar template di browser, buat satu template baru, dan
pastikan builder terbuka tanpa galat di console.

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

Ke mana berkas itu tersimpan bergantung `document-builder.images.upload_strategy` (lihat §1.4):
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
