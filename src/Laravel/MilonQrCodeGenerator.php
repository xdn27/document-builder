<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Milon\Barcode\DNS2D;
use Throwable;

/**
 * Memakai milon/barcode yang sudah terpasang di aplikasi, sehingga tidak ada
 * dependensi Composer baru. Keluarannya SVG inline agar tajam di layar maupun
 * cetak dan tidak memerlukan berkas sementara.
 */
final class MilonQrCodeGenerator implements QrCodeGenerator
{
    public function toSvg(string $payload, float $sizeMm): string
    {
        try {
            // Argumen ketiga dan keempat adalah lebar dan tinggi modul; nilai 2
            // menghasilkan SVG yang cukup rapat, dan ukuran akhir diatur CSS.
            $svg = (new DNS2D)->getBarcodeSVG($payload, 'QRCODE', 2, 2, 'black', false);
        } catch (Throwable) {
            return '';
        }

        return is_string($svg) && str_contains($svg, '<svg') ? $svg : '';
    }
}
