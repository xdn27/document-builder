<?php

namespace Maqiis\DocumentBuilder\Qr;

/**
 * Bentuk HTML blok QR untuk engine PDF yang memaginasi sendiri (mpdf).
 *
 * Browser menerima SVG inline dan posisi tetap lewat `transform`: keduanya tidak
 * dibulatkan ke piksel bulat sehingga ukuran, ketebalan modul, dan posisi QR persis.
 * mpdf sebaliknya tidak memproses SVG inline dan mengabaikan `transform`, jadi di sini
 * SVG diubah menjadi <img> data URI dan posisi tetap ditulis sebagai top/left.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class QrEngineHtml
{
    public static function convert(string $html): string
    {
        if (! str_contains($html, 'db-qrcode__')) {
            return $html;
        }

        $html = preg_replace_callback(
            '#<svg class="db-qrcode__svg"[^>]*>.*?</svg>#s',
            static function (array $m): string {
                $svg = $m[0];
                $width = preg_match('/<svg\b[^>]*\swidth="([^"]+)"/i', $svg, $w) ? $w[1] : '';
                $height = preg_match('/<svg\b[^>]*\sheight="([^"]+)"/i', $svg, $h) ? $h[1] : '';

                return sprintf(
                    '<img class="db-qrcode__img" src="data:image/svg+xml;base64,%s" alt="Kode QR" style="width:%s;height:%s">',
                    base64_encode($svg),
                    $width,
                    $height,
                );
            },
            $html,
        ) ?? $html;

        return preg_replace(
            '#(<div class="db-qrcode__wrap db-qrcode__wrap--fixed") style="[^"]*" data-top="([^"]*)" data-left="([^"]*)">#',
            '$1 style="position:absolute;top:$2;left:$3">',
            $html,
        ) ?? $html;
    }
}
