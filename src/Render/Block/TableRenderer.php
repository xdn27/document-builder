<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;

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

        $html .= '<thead class="db-table__head"><tr>';

        foreach ($columns as $column) {
            $width = (float) $column['widthPercent'];

            $html .= sprintf(
                '<th class="db-table__th%s" style="%stext-align:%s">%s</th>',
                $block->prop('headerBold') === true ? '' : ' db-table__th--regular',
                $width > 0.0 ? sprintf('width:%s%%;', $this->number($width)) : '',
                $context->escape($this->align($column['align'])),
                $context->rich((string) $column['label']),
            );
        }

        $html .= '</tr></thead><tbody class="db-table__body">';

        foreach ($block->prop('rows') as $row) {
            $html .= '<tr class="db-table__row">';

            foreach ($columns as $index => $column) {
                $html .= sprintf(
                    '<td class="db-table__td" style="text-align:%s">%s</td>',
                    $context->escape($this->align($column['align'])),
                    $context->rich((string) ($row[$index] ?? '')),
                );
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
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
