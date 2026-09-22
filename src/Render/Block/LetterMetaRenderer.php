<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/**
 * Tabel dipakai di sini karena penyelarasan kolom label dan nilai berperilaku sama
 * di browser maupun mpdf, sedangkan flexbox tidak.
 *
 * Teks kanan (mis. "Bandung, 14 September 2026") memakai satu sel dengan rowspan
 * di baris pertama, bukan tabel bersarang: rowspan adalah fitur tabel dasar yang
 * didukung penuh mpdf, sedangkan tabel di dalam sel tabel adalah sumber quirk
 * yang lebih besar (lihat catatan blok signature untuk contoh serupa).
 */
final class LetterMetaRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::LetterMeta;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $rows = $block->prop('rows');
        $rightText = trim((string) $block->prop('rightText'));

        if ($rows === [] && $rightText === '') {
            return '';
        }

        $labelWidth = Mm::css((float) $block->prop('labelWidthMm'));
        $separator = $context->plain((string) $block->prop('separator'));

        $rightCell = $rightText === '' ? '' : sprintf(
            '<td class="db-letter-meta__right" rowspan="%d" style="text-align:%s">%s</td>',
            max(count($rows), 1),
            $context->escape((string) $block->prop('rightAlign')),
            $context->rich($rightText),
        );

        // Modifier terpisah, bukan mengubah .db-letter-meta__table langsung: tabel
        // hanya perlu melebar penuh saat kolom kanan ada, dan template lama tanpa
        // teks kanan harus tetap terlihat identik seperti sebelum fitur ini.
        $tableClass = $rightText === '' ? 'db-letter-meta__table' : 'db-letter-meta__table db-letter-meta__table--with-right';

        if ($rows === []) {
            return '<table class="'.$tableClass.'"><tbody><tr>'.$rightCell.'</tr></tbody></table>';
        }

        $html = '<table class="'.$tableClass.'"><tbody>';

        foreach ($rows as $index => $row) {
            $html .= sprintf(
                '<tr><td class="db-letter-meta__label" style="width:%s">%s</td>'
                .'<td class="db-letter-meta__sep">%s</td>'
                .'<td class="db-letter-meta__value">%s</td>%s</tr>',
                $labelWidth,
                $context->rich((string) $row['label']),
                $separator,
                $context->rich((string) $row['value']),
                $index === 0 ? $rightCell : '',
            );
        }

        return $html.'</tbody></table>';
    }
}
