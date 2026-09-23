<?php

namespace Maqiis\DocumentBuilder\Render;

use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Schema\DocumentStyle;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Maqiis\DocumentBuilder\Support\Mm;

/**
 * CSS yang bergantung pada template tertentu. Gaya yang tidak bergantung template
 * ada di resources/css/document.css dan membaca custom property yang dihasilkan di sini.
 *
 * Tidak ada aturan yang menyasar selector html: dompdf dan mpdf menerapkan gaya
 * elemen html ke frame halaman, sehingga "html{margin:0}" menghapus margin @page.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class PageLayoutCss
{
    /** @return array<string,string> nama custom property => nilai literal */
    public static function variables(PageSetup $page, DocumentStyle $style): array
    {
        return [
            '--db-page-w' => Mm::css($page->widthMm()),
            '--db-page-h' => Mm::css($page->heightMm()),
            '--db-mt' => Mm::css($page->margin->top),
            '--db-mr' => Mm::css($page->margin->right),
            '--db-mb' => Mm::css($page->margin->bottom),
            '--db-ml' => Mm::css($page->margin->left),
            '--db-content-w' => Mm::css($page->contentWidthMm()),
            '--db-content-h' => Mm::css($page->contentHeightMm()),
            '--db-font-family' => FontRegistry::cssStack($style->fontFamily),
            '--db-font-size' => rtrim(rtrim(number_format($style->fontSize, 2, '.', ''), '0'), '.').'pt',
            '--db-line-height' => (string) $style->lineHeight,
        ];
    }

    public static function render(PageSetup $page, DocumentStyle $style): string
    {
        $body = '';

        foreach (self::variables($page, $style) as $property => $value) {
            $body .= "{$property}:{$value};";
        }

        // Hanya sampai ke browser/Chromium: mpdf membangun CSS-nya sendiri lewat
        // RenderedDocument::resolvedCss(), yang tidak pernah membaca $layoutCss ini.
        $fontFace = FontRegistry::fontFaceCss($style->fontFamily) ?? '';

        return $fontFace.sprintf(
            '@page{size:%s %s;margin:0}.doc-root{%s}',
            Mm::css($page->widthMm()),
            Mm::css($page->heightMm()),
            rtrim($body, ';'),
        );
    }
}
