<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

final class SpacerRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::Spacer;
    }

    public function render(Block $block, RenderContext $context): string
    {
        return sprintf(
            '<div class="db-spacer__box" style="height:%s"></div>',
            Mm::css((float) $block->prop('heightMm')),
        );
    }
}
