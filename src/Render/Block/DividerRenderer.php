<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/** @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README. */
final class DividerRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::Divider;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $align = (string) $block->prop('align');

        $margins = match ($align) {
            'center' => 'margin-left:auto;margin-right:auto',
            'right' => 'margin-left:auto;margin-right:0',
            default => 'margin-left:0;margin-right:auto',
        };

        $style = sprintf(
            'border-top:%s %s #000;width:%s%%;%s',
            Mm::css((float) $block->prop('thicknessMm')),
            $context->escape((string) $block->prop('style')),
            rtrim(rtrim(number_format((float) $block->prop('widthPercent'), 2, '.', ''), '0'), '.'),
            $margins,
        );

        return sprintf('<div class="db-divider__rule" style="%s"></div>', $style);
    }
}
