<?php

namespace Maqiis\DocumentBuilder\Qr;

/**
 * Menyiapkan SVG dari pembangkit QR apa pun supaya tampil sama di browser dan di mpdf.
 *
 * Dua masalah pada keluaran mentah pembangkit umum (mis. milon/barcode):
 * - prolog `<?xml ...?>` dan `<!DOCTYPE ...>` ikut tercetak sebagai teks bila SVG
 *   disisipkan inline ke HTML yang diproses mpdf;
 * - elemen akar hanya punya `width`/`height` tanpa `viewBox`, sehingga mengubah ukuran
 *   elemen (CSS) tidak menskalakan isinya.
 *
 * Hasilnya dibungkus sebagai data URI untuk `<img>`: itu satu-satunya cara SVG masuk ke
 * mpdf dengan ukuran yang dihormati, dan di browser tampil identik dengan SVG inline.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class QrSvg
{
    /**
     * Data URI `image/svg+xml` berukuran $sizeMm persegi, atau string kosong bila
     * $svg bukan SVG yang dapat dipakai.
     */
    public static function toDataUri(string $svg, float $sizeMm): string
    {
        $normalized = self::normalize($svg, $sizeMm);

        return $normalized === '' ? '' : 'data:image/svg+xml;base64,'.base64_encode($normalized);
    }

    public static function normalize(string $svg, float $sizeMm): string
    {
        $svg = preg_replace(['/<\?xml.*?\?>/is', '/<!DOCTYPE[^>\[]*(?:\[.*?\])?\s*>/is', '/<!--.*?-->/s'], '', $svg) ?? '';
        $svg = trim($svg);

        if (! preg_match('/<svg\b([^>]*)>/i', $svg, $tag, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $attributes = $tag[1][0];
        // Tanpa dimensi yang bisa dibaca, SVG tetap dipakai apa adanya (tanpa viewBox):
        // ukurannya mengikuti width/height di bawah, isinya tidak ikut diskala.
        $viewBox = self::viewBox($attributes);

        $clean = preg_replace('/\s(?:width|height|viewBox|preserveAspectRatio|x|y)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $attributes) ?? '';

        if (! preg_match('/\sxmlns\s*=/i', $clean)) {
            $clean .= ' xmlns="http://www.w3.org/2000/svg"';
        }

        $size = rtrim(rtrim(number_format($sizeMm, 3, '.', ''), '0'), '.').'mm';
        $open = sprintf(
            '<svg%s%s width="%s" height="%s" preserveAspectRatio="xMidYMid meet">',
            $clean,
            $viewBox === null ? '' : ' viewBox="'.$viewBox.'"',
            $size,
            $size,
        );

        return substr($svg, 0, $tag[0][1]).$open.substr($svg, $tag[0][1] + strlen($tag[0][0]));
    }

    /** Kotak gambar dari viewBox yang ada, atau dari width/height bila tidak ada. */
    private static function viewBox(string $attributes): ?string
    {
        if (preg_match('/\sviewBox\s*=\s*"\s*([-\d.]+)[\s,]+([-\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*"/i', $attributes, $m)) {
            return (float) $m[3] > 0 && (float) $m[4] > 0 ? "{$m[1]} {$m[2]} {$m[3]} {$m[4]}" : null;
        }

        $width = self::length($attributes, 'width');
        $height = self::length($attributes, 'height');

        return $width > 0 && $height > 0 ? "0 0 {$width} {$height}" : null;
    }

    private static function length(string $attributes, string $name): float
    {
        return preg_match('/\s'.$name.'\s*=\s*"\s*([\d.]+)\s*(?:px)?\s*"/i', $attributes, $m) ? (float) $m[1] : 0.0;
    }
}
