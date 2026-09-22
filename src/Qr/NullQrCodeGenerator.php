<?php

namespace Maqiis\DocumentBuilder\Qr;

final class NullQrCodeGenerator implements QrCodeGenerator
{
    public function toSvg(string $payload, float $sizeMm): string
    {
        return '';
    }
}
