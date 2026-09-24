<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/**
 * Tanda tangan disusun per baris tabel — tanggal, jabatan, ruang, nama, NIP —
 * dengan satu sel per kolom di setiap baris. Dua alasan:
 *
 * - mpdf mengabaikan tinggi, padding, dan margin elemen blok di dalam sel tabel,
 *   sehingga ruang tanda tangan berupa div kosong hilang di PDF. Tinggi baris tabel
 *   dihormati mpdf maupun browser.
 * - Nama di semua kolom berada di baris yang sama, jadi jabatan yang membungkus ke
 *   dua baris di satu kolom tidak menggeser nama di kolom lain.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class SignatureRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::Signature;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $columns = $block->prop('columns');

        if ($columns === []) {
            return $context->marker('Blok tanda tangan belum memiliki kolom');
        }

        $align = (string) $block->prop('align');
        $textAlign = (string) $block->prop('textAlign');
        $cellAlign = $context->escape($textAlign !== '' ? $textAlign : ($align === 'space-between' ? 'center' : $align));
        $width = $this->number(100.0 / count($columns));

        $datelines = array_map(
            fn (array $column): string => $this->dateline((string) $column['place'], (string) $column['date'], $context),
            $columns,
        );

        $rows = [
            $this->textRow($datelines, 'db-signature__dateline', null, $columns, null, $context),
            $this->textRow($this->field($columns, 'position', $context), 'db-signature__position', null, $columns, 'position', $context),
            $this->spaceRow($block, $columns, Mm::css((float) $block->prop('spaceMm')), $cellAlign, $context),
            $this->textRow($this->field($columns, 'name', $context), 'db-signature__name', null, $columns, 'name', $context),
            $this->textRow($this->field($columns, 'nip', $context), 'db-signature__nip', 'NIP. ', $columns, 'nip', $context),
        ];

        $html = '';
        $first = true;

        foreach (array_filter($rows) as $cells) {
            $html .= '<tr>';

            foreach ($cells as $cell) {
                // Lebar kolom cukup ditetapkan di baris pertama.
                $html .= str_replace(
                    '{style}',
                    $cell['space'] ? '' : sprintf(' style="%stext-align:%s"', $first ? "width:{$width}%;" : '', $cellAlign),
                    $cell['html'],
                );
            }

            $html .= '</tr>';
            $first = false;
        }

        return sprintf(
            '<table class="db-signature__table" style="%s"><tbody>%s</tbody></table>',
            $this->tableStyle($align, (float) $block->prop('widthPercent'), (float) $block->prop('spaceBeforeMm')),
            $html,
        );
    }

    /**
     * Tabel selebar penuh (bawaan) mempertahankan perilaku lama persis. Di bawah
     * itu, tabel menyusut dan didorong ke sisi yang dipilih 'align' lewat margin —
     * bukan lewat lebar otomatis (auto/shrink-to-fit), yang di mpdf memecah tiap
     * kata jadi satu huruf per baris alih-alih menyusut mengikuti konten.
     * align:space-between selalu selebar penuh karena tujuannya memang menyebar
     * kolom, bukan memposisikan satu blok.
     */
    private function tableStyle(string $align, float $widthPercent, float $marginTopMm): string
    {
        $style = 'margin-top:'.Mm::css($marginTopMm);

        if ($widthPercent >= 100.0 || $align === 'space-between') {
            return $style;
        }

        $margin = match ($align) {
            'left' => 'margin-right:auto',
            'right' => 'margin-left:auto',
            default => 'margin-left:auto;margin-right:auto',
        };

        return sprintf('width:%s%%;%s;%s', $this->number($widthPercent), $margin, $style);
    }

    /**
     * @param  list<string>  $values  HTML aman per kolom
     * @param  list<array<string,mixed>>  $columns  nilai schema mentah, sumber atribut sunting inline
     * @return list<array{html:string,space:bool}>|null null bila tidak ada kolom yang memakainya
     */
    private function textRow(array $values, string $class, ?string $prefix, array $columns, ?string $editKey, RenderContext $context): ?array
    {
        if (array_filter($values, static fn (string $v): bool => $v !== '') === []) {
            return null;
        }

        $columns = array_values($columns);
        $cells = [];

        foreach (array_values($values) as $index => $value) {
            $edit = $editKey === null
                ? ''
                : $context->editAttr('columns', (string) ($columns[$index][$editKey] ?? ''), $index, key: $editKey, rich: true);

            // Awalan (mis. "NIP. ") bukan bagian nilai schema: region editable
            // dibungkus terpisah supaya awalan tidak ikut terbaca saat commit.
            if ($edit !== '' && $prefix !== null && $value !== '') {
                $value = sprintf('<span%s>%s</span>', $edit, $value);
                $edit = '';
            }

            $cells[] = [
                'space' => false,
                'html' => '<td class="db-signature__cell"{style}>'
                    .($value === '' ? '' : sprintf('<span class="%s"%s>%s%s</span>', $class, $edit, $prefix ?? '', $value))
                    .'</td>',
            ];
        }

        return $cells;
    }

    /**
     * Ruang tanda tangan boleh diisi gambar hasil unggah, ditumpuk di atas ruang
     * kosong yang sama — kalau kosong, tetap jadi ruang tulis tangan seperti biasa.
     * Tinggi diberikan ke <img> langsung (bukan div pembungkus di dalam sel tabel,
     * yang diabaikan mpdf — lihat catatan blok ini).
     *
     * @param  list<array<string,mixed>>  $columns
     * @return list<array{html:string,space:bool}>
     */
    private function spaceRow(Block $block, array $columns, string $height, string $cellAlign, RenderContext $context): array
    {
        $baseHeightMm = (float) $block->prop('spaceMm');
        $blockScale = (float) ($block->prop('imageScalePercent') ?? $block->prop('signatureScale') ?? 100.0);
        $blockOffsetY = (float) ($block->prop('imageOffsetYMm') ?? $block->prop('signatureOffsetYMm') ?? 0.0);
        $blockOffsetX = (float) ($block->prop('imageOffsetXMm') ?? $block->prop('signatureOffsetXMm') ?? 0.0);

        return array_map(
            fn (array $column): array => [
                'space' => true,
                'html' => sprintf(
                    '<td class="db-signature__space" style="height:%s;text-align:%s">%s</td>',
                    $height,
                    $cellAlign,
                    $this->signatureImage(
                        (string) ($column['signature'] ?? ''),
                        $baseHeightMm,
                        (float) ($column['imageScalePercent'] ?? $column['signatureScale'] ?? $blockScale),
                        (float) ($column['imageOffsetYMm'] ?? $column['signatureOffsetYMm'] ?? $blockOffsetY),
                        (float) ($column['imageOffsetXMm'] ?? $column['signatureOffsetXMm'] ?? $blockOffsetX),
                        $context,
                    ),
                ),
            ],
            $columns,
        );
    }

    private function signatureImage(
        string $src,
        float $baseHeightMm,
        float $scalePercent,
        float $offsetYMm,
        float $offsetXMm,
        RenderContext $context,
    ): string {
        $src = $context->source($src);

        if ($src === null) {
            return $context->marker('Variabel tanda tangan tidak dikenal');
        }

        if ($src === '') {
            return '';
        }

        if (! $context->images->isAllowed($src)) {
            return $context->marker('Sumber gambar ditolak');
        }

        $style = $this->signatureImageStyle($baseHeightMm, $scalePercent, $offsetYMm, $offsetXMm);

        return sprintf(
            '<img class="db-signature__image" src="%s" alt="" style="%s" />',
            $context->escape($src),
            $style,
        );
    }

    private function signatureImageStyle(
        float $baseHeightMm,
        float $scalePercent,
        float $offsetYMm,
        float $offsetXMm,
    ): string {
        $scale = $scalePercent > 0.0 ? ($scalePercent / 100.0) : 1.0;
        $isDefault = abs($scale - 1.0) < 0.001 && abs($offsetYMm) < 0.001 && abs($offsetXMm) < 0.001;

        if ($isDefault) {
            return sprintf('height:%s', Mm::css($baseHeightMm));
        }

        $renderHeightMm = $baseHeightMm * $scale;
        $extraHeightMm = $renderHeightMm - $baseHeightMm;
        $marginTopMm = -($extraHeightMm / 2.0) + $offsetYMm;
        $marginBottomMm = -($extraHeightMm / 2.0) - $offsetYMm;

        $styles = [
            sprintf('height:%s', Mm::css($renderHeightMm)),
        ];

        if (abs($marginTopMm) > 0.001) {
            $styles[] = sprintf('margin-top:%s', Mm::css($marginTopMm));
        }

        if (abs($marginBottomMm) > 0.001) {
            $styles[] = sprintf('margin-bottom:%s', Mm::css($marginBottomMm));
        }

        if (abs($offsetXMm) > 0.001) {
            $styles[] = sprintf('margin-left:%s', Mm::css($offsetXMm));
            $styles[] = sprintf('margin-right:%s', Mm::css(-$offsetXMm));
        }

        $styles[] = 'position:relative';
        $styles[] = 'max-width:none';

        return implode(';', $styles);
    }

    /** @return list<string> */
    private function field(array $columns, string $key, RenderContext $context): array
    {
        return array_map(
            static fn (array $column): string => trim((string) $column[$key]) === '' ? '' : $context->rich((string) $column[$key]),
            $columns,
        );
    }

    private function dateline(string $place, string $date, RenderContext $context): string
    {
        $place = trim($place);
        $date = trim($date);

        if ($place !== '' && $date !== '') {
            return $context->rich($place).', '.$context->rich($date);
        }

        return $place.$date === '' ? '' : $context->rich($place !== '' ? $place : $date);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
