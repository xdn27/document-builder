<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/**
 * Penomoran dihitung di PHP, bukan diserahkan ke list-style CSS. Dukungan
 * list-style bertingkat berbeda-beda antar engine PDF, sedangkan surat resmi
 * Indonesia menuntut pola 1. / a. / 1) yang persis.
 */
final class ListRenderer implements BlockRenderer
{
    public const MAX_LEVEL = 2;

    private const BULLETS = ['•', '◦', '▪'];

    public function type(): BlockType
    {
        return BlockType::ListBlock;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $items = $block->prop('items');

        if ($items === []) {
            return '';
        }

        $numbered = $block->prop('style') === 'number';
        $indent = (float) $block->prop('indentMm');
        $counters = [0, 0, 0];
        $html = '';

        foreach ($items as $item) {
            $level = max(0, min(self::MAX_LEVEL, (int) $item['level']));

            if ($numbered) {
                $counters[$level]++;

                // Kembali ke level lebih dangkal berarti penomoran anak dimulai ulang.
                for ($deeper = $level + 1; $deeper <= self::MAX_LEVEL; $deeper++) {
                    $counters[$deeper] = 0;
                }

                $marker = $this->marker($level, $counters[$level]);
            } else {
                $marker = self::BULLETS[$level];
            }

            $html .= sprintf(
                '<div class="db-list__item" style="padding-left:%s">'
                .'<span class="db-list__marker">%s</span>'
                .'<span class="db-list__text">%s</span></div>',
                Mm::css($indent * ($level + 1)),
                $context->escape($marker),
                $context->rich((string) $item['text']),
            );
        }

        return $html;
    }

    private function marker(int $level, int $ordinal): string
    {
        return match ($level) {
            0 => $ordinal.'.',
            1 => $this->alpha($ordinal).'.',
            default => $ordinal.')',
        };
    }

    private function alpha(int $ordinal): string
    {
        $letters = '';
        $value = $ordinal;

        while ($value > 0) {
            $value--;
            $letters = chr(97 + ($value % 26)).$letters;
            $value = intdiv($value, 26);
        }

        return $letters === '' ? 'a' : $letters;
    }
}
