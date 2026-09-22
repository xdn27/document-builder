# maqiis/document-builder

Penyusun surat berbasis blok yang dicetak **persis** seperti tampilannya di layar.

Template disimpan sebagai schema JSON. Satu renderer PHP mengubahnya menjadi HTML, satu stylesheet
memberinya gaya, dan satu paginator memecahnya menjadi halaman. Kanvas builder, halaman cetak, dan
PDF semuanya berasal dari rantai yang sama.

## Prinsip: yang dicetak adalah DOM yang sudah dipaginasi

Paginator memecah dokumen menjadi kotak halaman di browser, dengan kop dan kaki disalin ke setiap
halaman. Halaman-halaman itulah yang tampil di builder **dan** yang dikirim ke `window.print()`.
Layar dan cetak tidak "dibuat mirip" — keduanya DOM yang sama.

```
schema.json
    │
    ├─► HtmlRenderer (PHP) ──► HTML mengalir + document.css
    │                                  │
    │                    ┌─────────────┴─────────────┐
    │                    ▼                           ▼
    │             paginate-dom.mjs              PdfEngine (PHP)
    │        (tempel → ukur luapan → pindah)    mpdf memaginasi sendiri
    │                    │
    │          ┌─────────┴─────────┐
    │          ▼                   ▼
    │    kanvas builder      cetak browser
```

Paginator **mengukur, tidak menaksir**. Setiap fragmen ditempel ke halaman nyata yang sudah
memiliki kop dan kakinya sendiri, lalu badan halaman diperiksa apakah meluap. Margin yang kolaps,
header tabel yang diulang, dan kop yang hanya muncul di halaman pertama dihitung oleh mesin tata
letak browser — mesin yang sama yang nanti mencetak.

## Tipe blok

| Tipe | Kegunaan | Dipecah antar halaman |
|---|---|---|
| `letterhead` | kop surat: logo, empat baris teks, garis bawah | tidak |
| `letterhead-image` | kop gambar penuh lebar, menembus tepi kiri/kanan/atas | tidak |
| `letter-meta` | Nomor / Lampiran / Hal, dengan teks kanan opsional (mis. tempat & tanggal) | tidak |
| `paragraph` | teks dengan `<b>`, `<i>`, `<u>`, `<br>` | tidak (pindah utuh) |
| `signature` | 1–3 kolom tanda tangan | tidak |
| `table` | tabel dengan header yang diulang di tiap halaman | per baris |
| `list` | daftar poin atau bernomor bertingkat `1.` / `a.` / `1)` | per butir |
| `image` | gambar dari sumber yang diizinkan | tidak |
| `qrcode` | QR dari teks atau variabel | tidak |
| `spacer` | jarak vertikal | tidak |
| `divider` | garis pemisah | tidak |

Kop dan kaki dapat tampil di `all`, `first-only`, atau `except-first`. Teks boleh memuat variabel
seperti `{{ student.name }}`; `{{ page }}` dan `{{ pages }}` diisi saat paginasi.

## Font (`FontRegistry`)

Font dipilih per-dokumen lewat `style.fontFamily`, bukan per-blok. Tiga yang pertama metriknya
identik dengan font inti mpdf, jadi berjalan di kedua engine tanpa berkas tambahan:

| Key | Tampilan | Dukungan mpdf |
|---|---|---|
| `tinos` (bawaan) | mirip Times New Roman | ya (`times`) |
| `arimo` | mirip Arial | ya (`helvetica`) |
| `cousine` | mirip Courier New | ya (`courier`) |
| `almarai` | Arab & Latin (Google Fonts, berkas disertakan di `resources/fonts/almarai/`) | **tidak** — lihat di bawah |

`FontRegistry::supportsMpdf()` menandai `almarai` sebagai tidak didukung mpdf: **terverifikasi**
lewat rendering PDF sungguhan bahwa parser TTF mpdf menghasilkan glyph salah/rusak untuknya (dan
untuk beberapa font Arab Google Fonts lain yang dicoba) — `MpdfEngine::render()` melempar
`PdfRenderingException` lebih dulu kalau font ini dipilih, mengarahkan ke engine `gotenberg` (lihat
bagian berikutnya), bukan diam-diam menghasilkan PDF yang rusak.

Untuk font yang butuh berkasnya sendiri (`almarai`), `FontRegistry::fontFaceCss()` menyisipkan
`@font-face` berbentuk data URI base64 lewat `AssetLoader::fontFace()` — hanya sampai ke
`RenderedDocument::css()` (dibaca browser/Chromium), tidak pernah ke `resolvedCss()` (dibaca mpdf),
jadi tidak perlu langkah "buang untuk mpdf" seperti krom halaman.

**RTL:** blok `paragraph` punya properti `direction` (`ltr`/bawaan, atau `rtl`), dirender sebagai
atribut HTML `dir` — bukan cuma CSS `direction`, supaya karakter netral (spasi, tanda baca, angka)
ikut algoritma Unicode Bidi yang benar. Belum ada di tipe blok lain; tambahkan pola yang sama di
renderer blok terkait kalau dibutuhkan.

## Memakai core tanpa Laravel

`src/` tidak bergantung pada Laravel sama sekali.

```php
use Maqiis\DocumentBuilder\Pdf\MpdfEngine;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;

$template = Template::fromArray(json_decode($json, true)); // melempar SchemaValidationException

$context = RenderContext::sample()->withResolver(new ArrayVariableResolver([
    'student' => ['name' => 'Fatimah Az-Zahra'],
]));

$document = (new HtmlRenderer)->render($template, $context);

$html = $document->fullHtml(autoPrint: true); // halaman cetak mandiri, tanpa permintaan jaringan
$pdf = (new MpdfEngine)->render($document);   // bytes PDF
```

`RenderContext::sample()` memakai kebijakan gambar yang longgar dan tanpa pembangkit QR — cukup
untuk percobaan. Untuk produksi, susun `RenderContext` dengan `ImageSourcePolicy` berdaftar-izin dan
`QrCodeGenerator` sungguhan.

## Di aplikasi ini

- Service provider didaftarkan manual di `config/app.php`, karena autoload package ditambahkan
  langsung di `composer.json` root sehingga auto-discovery tidak melihatnya.
- Semua render lewat `App\Services\DocumentBuilder\DocumentRenderer`; variabel yang tersedia
  didaftarkan di `App\Services\DocumentBuilder\VariableCatalog`.
- `DocumentBuilderMenuSeeder` membuat permission dan menu; `DocumentTemplateSampleSeeder` membuat
  tiga template contoh dari fixture test.

## Menambahkan engine PDF

Implementasikan `Maqiis\DocumentBuilder\Pdf\PdfEngine`, lalu tambahkan cabang `match` di
`DocumentBuilderServiceProvider` dan pilih lewat `DOCUMENT_BUILDER_PDF_ENGINE`.

`RenderedDocument` menyajikan dua rupa, dan engine mengambil yang cocok:

- engine yang memaginasi sendiri memakai `headerHtmlForEngine()`, `bodyHtml()`,
  `footerHtmlForEngine()`, dan `resolvedCss()` — CSS tanpa custom property dan tanpa krom halaman;
- engine berbasis browser (misalnya headless Chrome) cukup memakai `fullHtml()`, yang sudah memuat
  paginator dan memberi sinyal `document-builder:paginated` saat selesai. Engine seperti ini
  menghasilkan PDF yang identik dengan cetak browser.

## Engine PDF: Gotenberg (Chromium sungguhan)

`Maqiis\DocumentBuilder\Pdf\GotenbergEngine` merender lewat [Gotenberg](https://gotenberg.dev/) —
layanan HTTP terpisah yang membungkus Chromium headless, bukan library PHP. Pilih lewat
`DOCUMENT_BUILDER_PDF_ENGINE=gotenberg` dan `GOTENBERG_URL` (bawaan `http://gotenberg:3000`).

**Kenapa ini ada, bukan sekadar alternatif:** `GotenbergEngine` memakai `fullHtml()` yang sama persis
dipakai kanvas builder dan cetak browser, jadi kesetiaan layar=cetak di jalur ini didapat gratis dari
fakta keduanya sama-sama Chromium — bukan hasil penyesuaian tata letak manual seperti
`neutralizeTopBleed()` milik `MpdfEngine`. Untuk dokumen yang butuh kesetiaan tertinggi terhadap hasil
cetak browser tanpa bergantung pada layanan HTTP terpisah, `MpdfEngine` tetap jadi pilihan utama.

(Catatan sejarah: percobaan awal membuat `MpdfEngine` merender font berkas sendiri seperti Almarai
sempat tampak gagal total — glyph salah, tidak konsisten antar frasa. Penyebabnya bukan keterbatasan
mesin TTF mpdf, melainkan cara pendaftarannya: mpdf versi modern **tidak memproses `@font-face` di
CSS sama sekali** (hanya ada jalur itu di parser SVG), jadi mendaftarkan font lewat CSS — cara yang
benar untuk browser/Gotenberg — membuat mpdf diam-diam substitusi ke font bawaannya sendiri. Jalur
yang benar adalah opsi konstruktor `fontDir`/`fontdata`, lihat `FontRegistry::mpdfFontFiles()` dan
`MpdfEngine::mpdfConfig()` — dengan itu Almarai pun tertanam dan tershaping benar di mpdf.)

Jalankan Gotenberg sebagai container terpisah, mis. di `compose.yml`:

```yaml
gotenberg:
  image: gotenberg/gotenberg:8
  restart: unless-stopped
```

`GotenbergEngine` mengambil `fullHtml()` yang sama persis dipakai kanvas builder dan cetak browser —
kesetiaan layar=cetak di jalur ini didapat gratis dari fakta keduanya sama-sama Chromium, bukan hasil
penyesuaian kedua seperti `neutralizeTopBleed()` milik `MpdfEngine`. Satu-satunya penyesuaian:
`window.print()` (dipanggil `fullHtml(autoPrint:true)` untuk pengguna sungguhan) diganti penanda
global yang dipoll Gotenberg lewat `waitForExpression`, supaya PDF baru diambil setelah
`paginateDocument()` benar-benar selesai — Chromium headless tidak punya dialog cetak untuk dipicu.

Test terhadap instance Gotenberg sungguhan sengaja dilewati di suite default (lihat
`GotenbergEngineTest`) — jalankan manual:

```bash
docker run -d -p 3000:3000 gotenberg/gotenberg:8
DOCUMENT_BUILDER_TEST_GOTENBERG_URL=http://localhost:3000 ./vendor/bin/phpunit --filter Gotenberg
```

## Unggah gambar (`ImageUploadStorage`)

`DocumentBuilderServiceProvider` mengikat `Maqiis\DocumentBuilder\Laravel\ImageUploadStorage` —
dipakai host app saat pengguna mengunggah gambar di UI builder (mis. lewat properti Livewire
ber-tipe file) untuk mengubah berkas jadi `src` yang ditulis ke schema. Dipilih lewat
`document-builder.images.upload_strategy`:

- `data-uri` (bawaan): berkas ditempel langsung ke schema sebagai base64. Tidak butuh disk apa pun,
  selalu lolos `ImageSourcePolicy` tanpa konfigurasi tambahan.
- `filesystem`: disimpan ke `document-builder.images.upload_disk` (bawaan disk `public`) dan hanya
  URL-nya yang ditulis ke schema. URL disk itu otomatis ditambahkan ke `allowedPrefixes` milik
  `ImageSourcePolicy` saat strategi ini aktif. `upload_disk` SENGAJA bawaannya `public`, bukan
  `config('filesystems.default')`: disk default aplikasi seringkali disk `local` bawaan Laravel yang
  privat (root `storage/app`, tanpa symlink maupun config `url`) — memakainya menghasilkan `src` yang
  lolos `ImageSourcePolicy` tapi 404 di browser. `FilesystemImageUploadStorage::store()` melempar
  `RuntimeException` lebih dulu kalau disk yang dikonfigurasi berdriver `local` tanpa config `url`,
  supaya salah konfigurasi ketahuan saat mengunggah, bukan diam-diam menghasilkan `src` yang salah.

`MpdfEngine` juga bisa menerima `Media\ImageResolver` opsional lewat constructor, untuk mengganti src
tiap `<img>` khusus di salinan HTML yang diserahkan ke mpdf — supaya berkas di disk lokal dibaca
langsung dari filesystem saat merender PDF, bukan di-fetch lewat HTTP. Sama sekali tidak mempengaruhi
HTML yang dilihat browser. `DocumentBuilderServiceProvider` mengikat `Laravel\StorageImageResolver`
sebagai implementasinya, yang mendelegasikan ke `ImageUploadStorage` yang sedang aktif — otomatis
no-op selama strategi masih `data-uri`.

## Batasan yang diketahui

- **Paragraf tidak dipecah di tengah.** Paragraf yang tidak muat pindah utuh ke halaman berikutnya.
  Ini batasan kualitas tata letak, bukan kesetiaan: builder memperlihatkan perpindahan yang sama.
- **mpdf memaginasi sendiri.** Jumlah halaman mpdf cocok dengan cetak browser pada ketiga fixture,
  tetapi posisi pemenggalan baris di dalam halaman belum dibandingkan dan bisa berbeda tipis.
- **Zona bertinggi `auto` di mpdf** diberi ruang cadangan, karena mpdf tidak bisa mengukurnya lebih
  dulu. Menetapkan tinggi kop dan kaki secara numerik memberi kesetiaan PDF tertinggi.
- **CSS dokumen:** tanpa `px`, tanpa aturan untuk selector `html`, tanpa `currentColor`, tanpa
  flexbox pada gaya blok — masing-masing merusak keluaran mpdf atau dompdf.
- **`letterhead-image` wajib berada di zona bertinggi `auto`.** SchemaValidator memaksakannya diam-diam.
  Tingginya selalu dihitung dari rasio gambar dan lebar kertas, tidak bisa disunting manual. mpdf
  memaku kop pada `margin_header` dan mengabaikan margin atas negatif di dalamnya, jadi `MpdfEngine`
  menurunkan `margin_header` ke 0 dan menetralkan margin itu khusus untuk PDF — lihat
  `LetterheadImageRenderer` dan `MpdfEngine::neutralizeTopBleed()`.
- **Berkas yatim tidak dibersihkan.** `FilesystemImageUploadStorage` tidak menghapus berkas lama saat
  sebuah blok diunggah ulang atau blok dihapus.

## Test

```bash
# PHP (tanpa bootstrap Laravel)
./vendor/bin/phpunit --testsuite=DocumentBuilder

# algoritma paginasi
node --test "packages/document-builder/tests/js/*.test.mjs"

# layar = cetak, tanpa isi terpotong (butuh Chrome di host)
bash packages/document-builder/tools/verify-print.sh packages/document-builder/tests/fixtures/surat-tabel-panjang.json 3
```

`verify-print.sh` memeriksa tiga hal: jumlah halaman di layar sama dengan jumlah halaman tercetak,
tidak ada badan halaman yang meluap lalu terpotong, dan tabel yang pecah mengulang header-nya tanpa
kehilangan baris. Pemeriksaan kedua penting karena layar dan cetak bisa sama-sama memotong isi.
