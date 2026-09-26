<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/** @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README. */
final class QrCodeRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::QrCode;
    }

    public function render(Block $block, RenderContext $context): string
    {
        // Payload masuk ke pembangkit sebagai teks mentah, bukan HTML — variabel
        // disubstitusi tetapi hasilnya tidak boleh ter-escape untuk HTML.
        $payload = trim($this->resolvePayload((string) $block->prop('payload'), $context));

        if ($payload === '') {
            return $context->marker('Isi QR belum ditentukan');
        }

        $size = (float) $block->prop('sizeMm');
        $svg = $context->qr->toSvg($payload, $size);

        if ($svg === '') {
            return $context->marker('Pembangkit QR tidak tersedia');
        }

        if ((string) $block->prop('positionMode') === 'fixed') {
            return sprintf(
                '<div class="db-qrcode__wrap db-qrcode__wrap--fixed" style="position:absolute;top:%s;left:%s"><span class="db-qrcode__svg" style="display:inline-block;width:%s">%s</span></div>',
                Mm::css((float) $block->prop('topMm')),
                Mm::css((float) $block->prop('leftMm')),
                Mm::css($size),
                $svg,
            );
        }

        return sprintf(
            '<div class="db-qrcode__wrap" style="text-align:%s"><span class="db-qrcode__svg" style="display:inline-block;width:%s">%s</span></div>',
            $context->escape((string) $block->prop('align')),
            Mm::css($size),
            $svg,
        );
    }

    private function resolvePayload(string $raw, RenderContext $context): string
    {
        return html_entity_decode(
            $context->variables->apply($raw),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
