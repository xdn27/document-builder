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

        // Lebar kolom ditulis dalam mm, bukan persen. Untuk kolom yang lebih sempit dari isinya
        // (mis. kolom titik dua 1%), browser melebarkannya mengikuti isi; mpdf hanya berbuat
        // sama pada lebar absolut dan memakai persen apa adanya, sehingga isi kolom berikutnya
        // bergeser dan bisa bertumpuk.
        $tableWidthMm = max(
            0.0,
            $context->page->contentWidthMm() - (float) $block->prop('marginLeftMm') - (float) $block->prop('marginRightMm'),
        );

        $widths = $this->columnWidthsMm($columns, $tableWidthMm);

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
                $width = $widths[$columnIndex];

                $html .= sprintf(
                    '<th class="db-table__th%s" style="%stext-align:%s"%s>%s</th>',
                    $block->prop('headerBold') === true ? '' : ' db-table__th--regular',
                    $width > 0.0 ? sprintf('width:%s;', Mm::css($width)) : '',
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
                $width = $widths[$index];
                $widthStyle = (! $showHeader && $width > 0.0) ? sprintf('width:%s;', Mm::css($width)) : '';

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

    /**
     * Lebar tiap kolom dalam mm; 0.0 berarti kolom itu mengambil sisa lebar tabel.
     *
     * Bila semua kolom diberi lebar dan jumlahnya memenuhi tabel, kolom terlebar dijadikan
     * pengambil sisa. Hasilnya sama selama tiap kolom muat isinya; bedanya baru tampak saat
     * ada kolom yang lebih sempit dari isinya: kolom itu melebar dan kolom terlebar yang
     * mengalah. Tanpa ini mpdf menganggap setiap lebar sebagai batas minimum dan mengecilkan
     * seisi tabel (termasuk hurufnya) supaya muat.
     *
     * @param  list<array<string,mixed>>  $columns
     * @return list<float>
     */
    private function columnWidthsMm(array $columns, float $tableWidthMm): array
    {
        $percents = array_map(static fn (array $column): float => max(0.0, (float) $column['widthPercent']), array_values($columns));
        $total = array_sum($percents);

        if (! in_array(0.0, $percents, true) && $total >= 99.5) {
            $percents = array_map(static fn (float $percent): float => $percent / $total * 100, $percents);
            $percents[array_search(max($percents), $percents, true)] = 0.0;
        }

        return array_map(static fn (float $percent): float => $tableWidthMm * $percent / 100, $percents);
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
