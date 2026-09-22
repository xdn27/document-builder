<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;

final class BlockRendererRegistry
{
    /** Tipe yang tidak boleh dipecah antar halaman. */
    private const AVOID_BREAK = [
        BlockType::Signature,
        BlockType::Letterhead,
        BlockType::LetterheadImage,
        BlockType::Image,
        BlockType::QrCode,
        BlockType::Spacer,
        BlockType::Divider,
        BlockType::LetterMeta,
    ];

    /** @var array<string,BlockRenderer> */
    private array $renderers = [];

    public static function default(): self
    {
        return (new self)
            ->register(new LetterheadRenderer)
            ->register(new LetterheadImageRenderer)
            ->register(new LetterMetaRenderer)
            ->register(new ParagraphRenderer)
            ->register(new SpacerRenderer)
            ->register(new DividerRenderer)
            ->register(new TableRenderer)
            ->register(new ListRenderer)
            ->register(new ImageRenderer)
            ->register(new QrCodeRenderer)
            ->register(new SignatureRenderer);
    }

    public function register(BlockRenderer $renderer): self
    {
        $this->renderers[$renderer->type()->value] = $renderer;

        return $this;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $renderer = $this->renderers[$block->type->value] ?? null;

        $inner = $renderer === null
            ? $context->marker(sprintf('Blok tidak dikenal: %s', $block->type->value))
            : $renderer->render($block, $context);

        return sprintf(
            '<div class="doc-block db-%s" data-block-id="%s" data-break-inside="%s">%s</div>',
            $context->escape($block->type->value),
            $context->escape($block->id),
            in_array($block->type, self::AVOID_BREAK, true) ? 'avoid' : 'auto',
            $inner,
        );
    }
}
