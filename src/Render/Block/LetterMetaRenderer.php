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
 *
 * Baris kedua opsional (prop `rightSubText`, mis. tanggal Hijriah) dirender di
 * sel yang sama dipisah `<br />` — bukan div di dalam td, supaya tetap
 * mpdf-safe. Keduanya rich text (`<b><i><u><br>`), jadi garis bawah tanggal
 * Masehi ditulis `<u>...</u>` langsung di `rightText`.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
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
        $rightSubText = trim((string) $block->prop('rightSubText'));
        $hasRight = $rightText !== '' || $rightSubText !== '';

        if ($rows === [] && ! $hasRight) {
            return '';
        }

        $labelWidth = Mm::css((float) $block->prop('labelWidthMm'));
        $separator = $context->plain((string) $block->prop('separator'));

        $rightCell = '';

        if ($hasRight) {
            if ($rightText !== '' && $rightSubText !== '') {
                // Dua baris terisi: tiap baris region suntingnya sendiri, karena
                // satu data-edit-prop hanya bisa menunjuk ke satu prop schema.
                $rightInner = sprintf(
                    '<span%s>%s</span><br /><span class="db-letter-meta__right-sub"%s>%s</span>',
                    $context->editAttr('rightText', $rightText, rich: true),
                    $context->rich($rightText),
                    $context->editAttr('rightSubText', $rightSubText, rich: true),
                    $context->rich($rightSubText),
                );
                $cellEditAttr = '';
            } else {
                // Satu baris saja: keluaran identik dengan sebelum rightSubText
                // ada (tanpa span tambahan), supaya template lama byte-identical.
                $singleProp = $rightText !== '' ? 'rightText' : 'rightSubText';
                $singleValue = $rightText !== '' ? $rightText : $rightSubText;
                $rightInner = $context->rich($singleValue);
                $cellEditAttr = $context->editAttr($singleProp, $singleValue, rich: true);
            }

            $rightCell = sprintf(
                '<td class="db-letter-meta__right" rowspan="%d" style="text-align:%s"%s>%s</td>',
                max(count($rows), 1),
                $context->escape((string) $block->prop('rightAlign')),
                $cellEditAttr,
                $rightInner,
            );
        }

        // Modifier terpisah, bukan mengubah .db-letter-meta__table langsung: tabel
        // hanya perlu melebar penuh saat kolom kanan ada, dan template lama tanpa
        // teks kanan harus tetap terlihat identik seperti sebelum fitur ini.
        $tableClass = $hasRight ? 'db-letter-meta__table db-letter-meta__table--with-right' : 'db-letter-meta__table';

        if ($rows === []) {
            return '<table class="'.$tableClass.'"><tbody><tr>'.$rightCell.'</tr></tbody></table>';
        }

        $html = '<table class="'.$tableClass.'"><tbody>';

        foreach ($rows as $index => $row) {
            $html .= sprintf(
                '<tr><td class="db-letter-meta__label" style="width:%s"%s>%s</td>'
                .'<td class="db-letter-meta__sep">%s</td>'
                .'<td class="db-letter-meta__value"%s>%s</td>%s</tr>',
                $labelWidth,
                $context->editAttr('rows', (string) $row['label'], $index, key: 'label', rich: true),
                $context->rich((string) $row['label']),
                $separator,
                $context->editAttr('rows', (string) $row['value'], $index, key: 'value', rich: true),
                $context->rich((string) $row['value']),
                $index === 0 ? $rightCell : '',
            );
        }

        return $html.'</tbody></table>';
    }
}
