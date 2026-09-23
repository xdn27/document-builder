<?php

namespace Maqiis\DocumentBuilder\Render;

use Maqiis\DocumentBuilder\Render\Block\BlockRendererRegistry;
use Maqiis\DocumentBuilder\Render\Block\LetterheadImageRenderer;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Schema\Zone;

final class HtmlRenderer
{
    private readonly BlockRendererRegistry $registry;

    public function __construct(?BlockRendererRegistry $registry = null)
    {
        $this->registry = $registry ?? BlockRendererRegistry::default();
    }

    public function render(Template $template, RenderContext $context): RenderedDocument
    {
        return new RenderedDocument(
            $template->page,
            $template->style,
            PageLayoutCss::render($template->page, $template->style),
            $template->header->isEmpty() ? null : $this->zone($template->header, $context),
            $this->zone($template->body, $context),
            $template->footer->isEmpty() ? null : $this->zone($template->footer, $context),
            $template->header->repeat,
            $template->footer->repeat,
            $template->header->height,
            $template->footer->height,
            $this->autoHeightHint($template->header, $context),
            $this->autoHeightHint($template->footer, $context),
        );
    }

    /**
     * Engine yang memaginasi sendiri tidak bisa mengukur zona "auto" sebelum
     * merender, jadi diberi cadangan generik (lihat MpdfEngine). Kop gambar
     * penuh lebar adalah pengecualian: tingginya sudah diketahui pasti dari
     * rasio gambar, jadi cadangannya dihitung, bukan ditaksir.
     *
     * Margin negatif blok ini "memakan" ruang alirnya sendiri sebesar margin
     * atas halaman — geometri yang sama persis dirender LetterheadImageRenderer.
     * Cadangan mengikuti angka itu, bukan tinggi gambar mentah.
     *
     * Sumber diperiksa ulang lewat kebijakan gambar yang sama seperti renderer:
     * sumber yang ditolak tidak boleh memicu pembacaan berkas/jaringan di sini.
     */
    private function autoHeightHint(Zone $zone, RenderContext $context): ?float
    {
        if ($zone->height !== 'auto') {
            return null;
        }

        foreach ($zone->blocks as $block) {
            if ($block->type !== BlockType::LetterheadImage) {
                continue;
            }

            $src = (string) $block->prop('src');

            if (! $context->images->isAllowed($src)) {
                continue;
            }

            $marginLeftMm = (float) $block->prop('marginLeftMm');
            $marginRightMm = (float) $block->prop('marginRightMm');
            $marginTopMm = (float) $block->prop('marginTopMm');
            $marginBottomMm = (float) $block->prop('marginBottomMm');

            $effectiveWidth = max(0.0, $context->page->widthMm() - $marginLeftMm - $marginRightMm);
            $height = LetterheadImageRenderer::bleedHeightMm($effectiveWidth, $src, $context->imageResolver);

            if ($height !== null) {
                return max(0.0, $height + $marginTopMm + $marginBottomMm - $context->page->margin->top);
            }
        }

        return null;
    }

    private function zone(Zone $zone, RenderContext $context): string
    {
        $html = '';

        foreach ($zone->blocks as $block) {
            $html .= $this->registry->render($block, $context);
        }

        return $html;
    }
}
