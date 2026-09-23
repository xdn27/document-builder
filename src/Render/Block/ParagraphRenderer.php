<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/** @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README. */
final class ParagraphRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::Paragraph;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $style = sprintf(
            'text-align:%s;text-indent:%s;margin-top:%s;margin-bottom:%s',
            $context->escape((string) $block->prop('align')),
            Mm::css((float) $block->prop('indentMm')),
            Mm::css((float) $block->prop('spaceBeforeMm')),
            Mm::css((float) $block->prop('spaceAfterMm')),
        );

        // Atribut dir, bukan cuma CSS direction: dir mengikutkan karakter netral
        // (spasi, tanda baca, angka) ke algoritma Unicode Bidi yang benar, yang
        // tidak didapat dari CSS direction saja.
        return sprintf(
            '<p dir="%s" style="%s"%s>%s</p>',
            $context->escape((string) $block->prop('direction')),
            $style,
            $context->editAttr('text', (string) $block->prop('text'), rich: true),
            $context->rich((string) $block->prop('text')),
        );
    }
}
