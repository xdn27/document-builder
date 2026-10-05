<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Milon\Barcode\DNS2D;
use Throwable;

/**
 * Memakai milon/barcode yang sudah terpasang di aplikasi, sehingga tidak ada
 * dependensi Composer baru. Keluarannya SVG inline agar tajam di layar maupun
 * cetak dan tidak memerlukan berkas sementara.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
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

        if (! is_string($svg) || ! str_contains($svg, '<svg')) {
            return '';
        }

        return self::compact($svg) ?? $svg;
    }

    /**
     * milon/barcode menggambar tiap modul sebagai `<rect>` tersendiri (belasan KB per
     * QR) dan menaruh prolog XML di depan. Satu `<path>` jauh lebih ringkas dan tidak
     * meninggalkan garis tipis antarmodul di penampil PDF. Null bila bentuk keluarannya
     * tidak dikenali, supaya pemanggil memakai SVG aslinya.
     */
    private static function compact(string $svg): ?string
    {
        if (! preg_match('/<svg\b[^>]*\swidth="([\d.]+)"[^>]*\sheight="([\d.]+)"/i', $svg, $box)) {
            return null;
        }

        $count = preg_match_all('/<rect\s+x="([\d.]+)"\s+y="([\d.]+)"\s+width="([\d.]+)"\s+height="([\d.]+)"\s*\/>/i', $svg, $cells, PREG_SET_ORDER);

        if (! $count) {
            return null;
        }

        $path = '';

        foreach ($cells as [, $x, $y, $w, $h]) {
            $path .= "M{$x} {$y}h{$w}v{$h}h-{$w}z";
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" shape-rendering="crispEdges"><path fill="black" d="%s"/></svg>',
            $box[1],
            $box[2],
            $path,
        );
    }
}
