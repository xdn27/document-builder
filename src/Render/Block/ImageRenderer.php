<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/** @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README. */
final class ImageRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::Image;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $src = trim((string) $block->prop('src'));

        if ($src === '') {
            return $context->marker('Gambar belum dipilih');
        }

        if (! $context->images->isAllowed($src)) {
            return $context->marker('Sumber gambar ditolak');
        }

        return sprintf(
            '<div class="db-image__wrap" style="text-align:%s"><img class="db-image__img" src="%s" alt="%s" style="width:%s" /></div>',
            $context->escape((string) $block->prop('align')),
            $context->escape($src),
            $context->escape((string) $block->prop('alt')),
            Mm::css((float) $block->prop('widthMm')),
        );
    }
}
