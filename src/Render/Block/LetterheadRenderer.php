<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/** @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README. */
final class LetterheadRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::Letterhead;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $html = '<div class="db-letterhead__inner" style="text-align:'
            .$context->escape((string) $block->prop('align')).'">';

        $html .= $this->logo($block, $context);
        $html .= '<div class="db-letterhead__text">';

        foreach (['line1', 'line2', 'line3', 'line4'] as $index => $key) {
            $raw = (string) $block->prop($key);

            if (trim($raw) === '') {
                continue;
            }

            $html .= sprintf(
                '<div class="db-letterhead__line db-letterhead__line--%d"%s>%s</div>',
                $index + 1,
                $context->editAttr($key, $raw, rich: true),
                $context->rich($raw),
            );
        }

        $html .= '</div>';

        $rule = (string) $block->prop('rule');

        if ($rule !== 'none') {
            $html .= sprintf('<div class="db-letterhead__rule db-letterhead__rule--%s"></div>', $context->escape($rule));
        }

        return $html.'</div>';
    }

    private function logo(Block $block, RenderContext $context): string
    {
        if ($block->prop('showLogo') !== true) {
            return '';
        }

        $src = (string) $block->prop('logo');

        if (trim($src) === '') {
            return '';
        }

        if (! $context->images->isAllowed($src)) {
            return $context->marker('Sumber gambar ditolak');
        }

        return sprintf(
            '<img class="db-letterhead__logo" src="%s" alt="" style="height:%s" />',
            $context->escape($src),
            Mm::css((float) $block->prop('logoHeightMm')),
        );
    }
}
