<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;
use Maqiis\DocumentBuilder\Variable\CollectionVariableResolver;
use Maqiis\DocumentBuilder\Variable\ScopedVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableResolver;

/**
 * Daftar penerima surat: pembuka ("Kepada Yth."), satu entri per butir koleksi
 * 'recipients', lalu penutup ("di Tempat"). Di dalam itemText, {{ recipient.* }}
 * diikat ke butir yang sedang dirender — di luar blok ini path yang sama tetap
 * berarti penerima pertama, jadi kosakata variabelnya tidak bertambah.
 *
 * - Resolver tanpa dukungan koleksi → dirender sekali dengan resolver dokumen
 *   (perilaku satu-penerima), bukan gagal.
 * - Koleksi kosong → blok tidak dirender sama sekali; "Kepada Yth." tanpa nama
 *   di bawahnya terbaca sebagai salah cetak.
 * - Baris yang kosong setelah variabel diisi dibuang, supaya penerima tanpa
 *   jabatan tidak menyisakan baris menggantung.
 * - Penomoran lewat tabel, bukan flexbox atau list-style: mpdf tidak mengenal
 *   flexbox, dan nomor harus sejajar baris pertama entri yang berbaris-baris.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class RecipientRenderer implements BlockRenderer
{
    public const COLLECTION = 'recipients';

    public const ALIAS = 'recipient';

    public function type(): BlockType
    {
        return BlockType::Recipient;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $items = $this->items($context->variables->resolver());

        if ($items === []) {
            return '';
        }

        $numbering = (string) $block->prop('numbering');
        $numbered = $numbering === 'always' || ($numbering === 'auto' && count($items) > 1);
        $align = $context->escape((string) $block->prop('align'));
        $itemSpace = Mm::css((float) $block->prop('itemSpaceMm'));

        $rows = '';

        foreach (array_values($items) as $index => $resolver) {
            $text = $this->withoutEmptyLines($context->withResolver($resolver)->rich((string) $block->prop('itemText')));
            $spacing = $index > 0 ? sprintf('padding-top:%s;', $itemSpace) : '';

            $rows .= '<tr class="db-recipient__item">'
                .($numbered ? sprintf('<td class="db-recipient__marker" style="%s">%d.</td>', $spacing, $index + 1) : '')
                .sprintf('<td class="db-recipient__text" style="%stext-align:%s">%s</td>', $spacing, $align, $text)
                .'</tr>';
        }

        return sprintf(
            '<div class="db-recipient__wrap" style="text-align:%s;margin-top:%s;margin-bottom:%s">%s'
            .'<table class="db-recipient__table"><tbody>%s</tbody></table>%s</div>',
            $align,
            Mm::css((float) $block->prop('spaceBeforeMm')),
            Mm::css((float) $block->prop('spaceAfterMm')),
            $this->line($block, 'heading', $context),
            $rows,
            $this->line($block, 'closing', $context),
        );
    }

    /** @return list<VariableResolver> */
    private function items(VariableResolver $document): array
    {
        $collection = $document instanceof CollectionVariableResolver
            ? $document->collection(self::COLLECTION)
            : null;

        if ($collection === null) {
            return [$document];
        }

        return array_map(
            static fn (VariableResolver $item): VariableResolver => new ScopedVariableResolver(self::ALIAS, $item, $document),
            $collection,
        );
    }

    private function line(Block $block, string $prop, RenderContext $context): string
    {
        $raw = (string) $block->prop($prop);

        if (trim($raw) === '') {
            return '';
        }

        return sprintf(
            '<div class="db-recipient__%s"%s>%s</div>',
            $prop,
            $context->editAttr($prop, $raw, rich: true),
            $context->rich($raw),
        );
    }

    private function withoutEmptyLines(string $html): string
    {
        $lines = preg_split('/<br\s*\/?>/i', $html) ?: [];

        return implode('<br>', array_filter(
            $lines,
            static fn (string $line): bool => trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '',
        ));
    }
}
