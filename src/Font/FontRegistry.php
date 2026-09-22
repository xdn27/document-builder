<?php

namespace Maqiis\DocumentBuilder\Font;

use LogicException;
use Maqiis\DocumentBuilder\Asset\AssetLoader;

/**
 * Tiga font pertama metriknya identik dengan Times New Roman, Arial, dan
 * Courier New — kesamaan metrik itu yang membuat perhitungan baris di browser
 * dan di mpdf tetap cocok tanpa perlu menyertakan berkas font ke repositori.
 * Untuk ketiganya, 'mpdf' berisi nama font bawaan mpdf yang dipakai langsung
 * sebagai default_font.
 *
 * Almarai berbeda: font Arab+Latin sungguhan yang berkasnya disisipkan ke
 * browser/Gotenberg lewat AssetLoader::fontFace() (lihat fontFaceCss(), yang
 * memakai data: URI base64). mpdf versi modern tidak memproses @font-face di
 * CSS sama sekali (hanya ada jalur itu di parser SVG), jadi mengandalkan
 * fontFaceCss() untuk mpdf membuatnya diam-diam substitusi ke font
 * bawaannya sendiri — itulah sumber "glyph salah/tidak konsisten" yang
 * sebelumnya membuat font ini diblokir total dari mpdf. Jalur yang benar
 * untuk mpdf adalah opsi konstruktor 'fontdata'/'fontDir' dengan path berkas
 * asli (lihat mpdfFontFiles() dan MpdfEngine) — sudah terverifikasi
 * menghasilkan PDF dengan Almarai tertanam benar.
 */
final class FontRegistry
{
    public const DEFAULT_KEY = 'tinos';

    /** @return array<string,array{label:string,css:string,mpdf:?string}> */
    public static function all(): array
    {
        return [
            'tinos' => [
                'label' => 'Tinos (metrik Times New Roman)',
                'css' => "'Tinos','Times New Roman','Liberation Serif',Times,serif",
                'mpdf' => 'times',
            ],
            'arimo' => [
                'label' => 'Arimo (metrik Arial)',
                'css' => "'Arimo',Arial,'Liberation Sans',Helvetica,sans-serif",
                'mpdf' => 'helvetica',
            ],
            'cousine' => [
                'label' => 'Cousine (metrik Courier New)',
                'css' => "'Cousine','Courier New','Liberation Mono',Courier,monospace",
                'mpdf' => 'courier',
            ],
            'almarai' => [
                'label' => 'Almarai (Arab & Latin)',
                'css' => "'Almarai','Traditional Arabic',Tahoma,Arial,sans-serif",
                'mpdf' => 'almarai',
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function cssStack(string $key): string
    {
        return (self::all()[$key] ?? self::all()[self::DEFAULT_KEY])['css'];
    }

    /** Kembalikan false untuk font yang wajib ditolak MpdfEngine — lihat docblock kelas ini. */
    public static function supportsMpdf(string $key): bool
    {
        return (self::all()[$key] ?? self::all()[self::DEFAULT_KEY])['mpdf'] !== null;
    }

    /** @throws LogicException bila dipanggil untuk font yang !supportsMpdf() */
    public static function mpdfFamily(string $key): string
    {
        $mpdf = (self::all()[$key] ?? self::all()[self::DEFAULT_KEY])['mpdf'];

        if ($mpdf === null) {
            throw new LogicException("Font \"{$key}\" tidak didukung mpdf — periksa supportsMpdf() lebih dulu.");
        }

        return $mpdf;
    }

    /**
     * Blok @font-face siap sisip untuk font yang berkasnya perlu disertakan
     * sendiri, atau null untuk font yang sudah tersedia sebagai font sistem
     * (tiga font metrik-kompatibel di atas). Hanya relevan untuk salinan CSS
     * yang dilihat browser/Chromium — mpdf tidak pernah menerimanya, lihat
     * RenderedDocument::resolvedCss() yang membangun CSS-nya sendiri lepas
     * dari PageLayoutCss.
     */
    public static function fontFaceCss(string $key): ?string
    {
        return match ($key) {
            'almarai' => AssetLoader::fontFace('Almarai', [
                'normal' => 'fonts/almarai/Almarai-Regular.ttf',
                'bold' => 'fonts/almarai/Almarai-Bold.ttf',
            ]),
            default => null,
        };
    }

    /**
     * Data registrasi berkas TTF untuk font yang perlu didaftarkan manual ke
     * mpdf lewat opsi konstruktor 'fontDir'/'fontdata' — null untuk font yang
     * sudah tersedia sebagai font bawaan mpdf (lihat docblock kelas ini).
     *
     * @return array{dir:string,files:array<string,string|int>}|null
     */
    public static function mpdfFontFiles(string $key): ?array
    {
        return match ($key) {
            'almarai' => [
                'dir' => dirname(AssetLoader::path('fonts/almarai/Almarai-Regular.ttf')),
                'files' => [
                    'R' => 'Almarai-Regular.ttf',
                    'B' => 'Almarai-Bold.ttf',
                    // Tanpa ini mpdf memetakan tiap huruf Arab ke bentuk lepasnya
                    // (isolated form) alih-alih bentuk sambung sesuai posisi kata
                    // (initial/medial/final) — hasilnya terlihat "putus per huruf"
                    // meski glyph yang dipakai tetap benar. Ini nilai yang sama
                    // dipakai mpdf sendiri untuk semua font Arab bawaannya
                    // (lihat vendor/mpdf/mpdf/src/Config/FontVariables.php).
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            default => null,
        };
    }
}
