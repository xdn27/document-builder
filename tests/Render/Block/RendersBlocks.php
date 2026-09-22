<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Render\Block\BlockRendererRegistry;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;

trait RendersBlocks
{
    /** Lewatkan props mentah melalui validator supaya default dan penjepitan ikut teruji. */
    protected function renderBlock(BlockType $type, array $props, ?RenderContext $context = null): string
    {
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => [],
            'style' => [],
            'zones' => [
                'header' => ['blocks' => []],
                'body' => ['blocks' => [['id' => 'blk', 'type' => $type->value, 'props' => $props]]],
                'footer' => ['blocks' => []],
            ],
        ]);

        return BlockRendererRegistry::default()->render(
            $template->body->blocks[0],
            $context ?? RenderContext::sample(),
        );
    }
}
