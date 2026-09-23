<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/** @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README. */
final class TableRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::Table;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $columns = $block->prop('columns');

        if ($columns === []) {
            return $context->marker('Tabel belum memiliki kolom');
        }

        $fontSize = (float) $block->prop('fontSizePt');
        $style = $fontSize > 0.0
            ? sprintf(' style="font-size:%spt"', $this->number($fontSize))
            : '';

        $html = sprintf(
            '<table class="db-table__table db-table__table--%s"%s data-repeat-header="%s">',
            $context->escape((string) $block->prop('border')),
            $style,
            $block->prop('repeatHeader') === true ? '1' : '0',
        );

        $showHeader = $block->prop('showHeader') !== false;

        if ($showHeader) {
            $html .= '<thead class="db-table__head"><tr>';

            foreach ($columns as $columnIndex => $column) {
                $width = (float) $column['widthPercent'];

                $html .= sprintf(
                    '<th class="db-table__th%s" style="%stext-align:%s"%s>%s</th>',
                    $block->prop('headerBold') === true ? '' : ' db-table__th--regular',
                    $width > 0.0 ? sprintf('width:%s%%;', $this->number($width)) : '',
                    $context->escape($this->align($column['align'])),
                    $context->editAttr('columns', (string) $column['label'], $columnIndex, key: 'label', rich: true),
                    $context->rich((string) $column['label']),
                );
            }

            $html .= '</tr></thead>';
        }

        $html .= '<tbody class="db-table__body">';

        foreach ($block->prop('rows') as $rowIndex => $row) {
            $html .= '<tr class="db-table__row">';

            foreach ($columns as $index => $column) {
                $width = (float) $column['widthPercent'];
                $widthStyle = (! $showHeader && $width > 0.0) ? sprintf('width:%s%%;', $this->number($width)) : '';

                $html .= sprintf(
                    '<td class="db-table__td" style="%stext-align:%s"%s>%s</td>',
                    $widthStyle,
                    $context->escape($this->align($column['align'])),
                    $context->editAttr('rows', (string) ($row[$index] ?? ''), $rowIndex, $index, rich: true),
                    $context->rich((string) ($row[$index] ?? '')),
                );
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        $marginTopMm = (float) ($block->prop('marginTopMm') ?: $block->prop('spaceBeforeMm'));
        $marginBottomMm = (float) ($block->prop('marginBottomMm') ?: $block->prop('spaceAfterMm'));
        $marginRightMm = (float) $block->prop('marginRightMm');
        $marginLeftMm = (float) $block->prop('marginLeftMm');

        $wrapStyle = sprintf(
            'margin-top:%s;margin-right:%s;margin-bottom:%s;margin-left:%s',
            Mm::css($marginTopMm),
            Mm::css($marginRightMm),
            Mm::css($marginBottomMm),
            Mm::css($marginLeftMm),
        );

        return sprintf('<div class="db-table__wrap" style="%s">%s</div>', $wrapStyle, $html);
    }

    private function align(mixed $value): string
    {
        return in_array($value, ['left', 'center', 'right'], true) ? $value : 'left';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
