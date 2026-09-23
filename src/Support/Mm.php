<?php

namespace Maqiis\DocumentBuilder\Support;

/**
 * Semua panjang di dalam package dinyatakan dalam milimeter. Kelas ini satu-satunya
 * tempat konversi satuan terjadi, sehingga paginator, kotak @page, dan engine PDF
 * selalu bicara dalam angka yang sama.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class Mm
{
    public const PER_INCH = 25.4;

    public const POINTS_PER_INCH = 72.0;

    public static function toPt(float $mm): float
    {
        return $mm / self::PER_INCH * self::POINTS_PER_INCH;
    }

    public static function fromPt(float $pt): float
    {
        return $pt / self::POINTS_PER_INCH * self::PER_INCH;
    }

    /**
     * Panjang CSS dalam mm. Angka bulat ditulis tanpa desimal supaya CSS yang
     * dihasilkan mudah dibaca saat proses debug.
     */
    public static function css(float $mm): string
    {
        $rounded = round($mm, 3);

        if ($rounded === floor($rounded)) {
            return sprintf('%dmm', (int) $rounded);
        }

        return rtrim(rtrim(number_format($rounded, 3, '.', ''), '0'), '.').'mm';
    }
}
