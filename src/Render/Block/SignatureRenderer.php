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
            $this->textRow($datelines, 'db-signature__dateline', null, null, $context),
            $this->textRow($this->field($columns, 'position', $context), 'db-signature__position', null, 'position', $context),
            $this->spaceRow($columns, Mm::css((float) $block->prop('spaceMm')), $cellAlign, $context),
            $this->textRow($this->field($columns, 'name', $context), 'db-signature__name', null, 'name', $context),
            $this->textRow($this->field($columns, 'nip', $context), 'db-signature__nip', 'NIP. ', 'nip', $context),
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
     * @return list<array{html:string,space:bool}>|null null bila tidak ada kolom yang memakainya
     */
    private function textRow(array $values, string $class, ?string $prefix, ?string $editKey, RenderContext $context): ?array
    {
        if (array_filter($values, static fn (string $v): bool => $v !== '') === []) {
            return null;
        }

        $cells = [];

        foreach (array_values($values) as $index => $value) {
            $cells[] = [
                'space' => false,
                'html' => '<td class="db-signature__cell"{style}>'
                    .($value === '' ? '' : sprintf(
                        '<span class="%s"%s>%s%s</span>',
                        $class,
                        $editKey === null ? '' : $context->editAttr('columns', $index, key: $editKey, rich: true),
                        $prefix ?? '',
                        $value,
                    ))
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
     * @return list<array{html:string,space:bool}>
     */
    private function spaceRow(array $columns, string $height, string $cellAlign, RenderContext $context): array
    {
        return array_map(
            fn (array $column): array => [
                'space' => true,
                'html' => sprintf(
                    '<td class="db-signature__space" style="height:%s;text-align:%s">%s</td>',
                    $height,
                    $cellAlign,
                    $this->signatureImage((string) $column['signature'], $height, $context),
                ),
            ],
            $columns,
        );
    }

    private function signatureImage(string $src, string $height, RenderContext $context): string
    {
        $src = trim($src);

        if ($src === '') {
            return '';
        }

        if (! $context->images->isAllowed($src)) {
            return $context->marker('Sumber gambar ditolak');
        }

        return sprintf(
            '<img class="db-signature__image" src="%s" alt="" style="height:%s" />',
            $context->escape($src),
            $height,
        );
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
