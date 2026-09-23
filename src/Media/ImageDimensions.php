<?php

namespace Maqiis\DocumentBuilder\Media;

/**
 * Satu-satunya tempat dimensi gambar dibaca. Dipakai baik oleh renderer blok
 * yang perlu menghitung tinggi dari rasio gambar, maupun oleh HtmlRenderer
 * yang perlu menaksir cadangan tinggi kop untuk engine yang memaginasi
 * sendiri. Lihat ImageResolver untuk peran $resolver pada ratio().
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class ImageDimensions
{
    /**
     * Lebar dibagi tinggi, atau null bila gambar tidak dapat dibaca. $resolver
     * (bila diberikan) dicoba lebih dulu untuk sumber selain data URI — supaya
     * gambar yang disimpan sendiri oleh aplikasi (mis. lewat disk lokal) dibaca
     * langsung dari filesystem, bukan lewat fetch HTTP ke URL publiknya
     * sendiri. Fetch semacam itu bisa gagal, mis. saat proses PHP tidak bisa
     * menjangkau balik URL publik aplikasinya sendiri (umum terjadi di
     * lingkungan container).
     */
    public static function ratio(string $src, ?ImageResolver $resolver = null): ?float
    {
        $size = self::read($src, $resolver);

        if ($size === null || $size[1] <= 0) {
            return null;
        }

        return $size[0] / $size[1];
    }

    /** @return array{0:int,1:int}|null */
    private static function read(string $src, ?ImageResolver $resolver): ?array
    {
        $src = trim($src);

        if ($src === '') {
            return null;
        }

        $size = str_starts_with(strtolower($src), 'data:')
            ? self::fromDataUri($src)
            : @getimagesize($resolver?->resolve($src) ?? $src);

        if (! is_array($size) || ! isset($size[0], $size[1])) {
            return null;
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /** @return array{0:int,1:int}|false */
    private static function fromDataUri(string $src): array|false
    {
        $comma = strpos($src, ',');

        if ($comma === false) {
            return false;
        }

        $decoded = base64_decode(substr($src, $comma + 1), true);

        if ($decoded === false || $decoded === '') {
            return false;
        }

        $size = @getimagesizefromstring($decoded);

        // getimagesizefromstring tidak mengenali SVG. Dimensinya dibaca langsung
        // dari atribut tag <svg>, bukan lewat parser XML — sumber sudah lolos
        // whitelist data URI, tetapi tetap tidak dipercaya untuk memuat entitas.
        return is_array($size) ? $size : self::fromSvg($decoded);
    }

    /** @return array{0:int,1:int}|false */
    private static function fromSvg(string $xml): array|false
    {
        if (! preg_match('/<svg\b[^>]*>/i', $xml, $tag)) {
            return false;
        }

        $width = self::svgLength($tag[0], 'width');
        $height = self::svgLength($tag[0], 'height');

        if ($width !== null && $height !== null) {
            return [(int) round($width), (int) round($height)];
        }

        if (preg_match('/\bviewBox\s*=\s*"([^"]+)"/i', $tag[0], $box)) {
            $parts = preg_split('/[\s,]+/', trim($box[1]));

            if (count($parts) === 4 && (float) $parts[2] > 0 && (float) $parts[3] > 0) {
                return [(int) round((float) $parts[2]), (int) round((float) $parts[3])];
            }
        }

        return false;
    }

    private static function svgLength(string $svgTag, string $attribute): ?float
    {
        if (! preg_match('/\b'.$attribute.'\s*=\s*"([^"]+)"/i', $svgTag, $match)) {
            return null;
        }

        // Satuan seperti px/mm/pt dibuang; atribut root SVG lazimnya angka polos.
        $value = (float) preg_replace('/[a-z%]+$/i', '', trim($match[1]));

        return $value > 0 ? $value : null;
    }
}
