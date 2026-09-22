<?php

namespace Maqiis\DocumentBuilder\Qr;

interface QrCodeGenerator
{
    /**
     * Kembalikan SVG inline berukuran $sizeMm, atau string kosong bila QR tidak
     * dapat dibangkitkan. Renderer menampilkan penanda saat hasilnya kosong.
     */
    public function toSvg(string $payload, float $sizeMm): string;
}
