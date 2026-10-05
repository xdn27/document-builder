<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Qr\QrSvg;
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
        $svg = QrSvg::inline($context->qr->toSvg($payload, $size), $size);

        if ($svg === '') {
            return $context->marker('Pembangkit QR tidak tersedia');
        }

        // SVG inline, bukan <img>: Chrome membulatkan kotak <img> ke piksel CSS bulat
        // (QR 20 mm tercetak 20,11 mm dan bergeser), sedangkan SVG inline berukuran tepat.
        // Posisi tetap memakai transform karena left/top pun dibulatkan. Untuk mpdf,
        // bentuk ini diubah kembali oleh QrEngineHtml (RenderedDocument::*ForEngine()).
        if ((string) $block->prop('positionMode') === 'fixed') {
            $top = Mm::css((float) $block->prop('topMm'));
            $left = Mm::css((float) $block->prop('leftMm'));

            return sprintf(
                '<div class="db-qrcode__wrap db-qrcode__wrap--fixed" style="position:absolute;top:0;left:0;transform:translate(%s,%s)" data-top="%s" data-left="%s">%s</div>',
                $left,
                $top,
                $top,
                $left,
                $svg,
            );
        }

        return sprintf(
            '<div class="db-qrcode__wrap" style="text-align:%s">%s</div>',
            $context->escape((string) $block->prop('align')),
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
