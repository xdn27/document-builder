<?php

namespace Maqiis\DocumentBuilder\Tests\Render;

use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Render\Block\BlockRendererRegistry;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\ZoneRepeat;
use PHPUnit\Framework\TestCase;

class HtmlRendererTest extends TestCase
{
    private function document(): RenderedDocument
    {
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'style' => [],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => [
                    ['id' => 'kop', 'type' => 'letterhead', 'props' => ['showLogo' => false, 'line1' => 'YAYASAN']],
                ]],
                'body' => ['blocks' => [
                    ['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Isi surat.']],
                ]],
                'footer' => ['repeat' => 'except-first', 'height' => 12, 'blocks' => [
                    ['id' => 'f1', 'type' => 'paragraph', 'props' => ['text' => 'Halaman {{ page }} dari {{ pages }}']],
                ]],
            ],
        ]);

        return (new HtmlRenderer)->render($template, RenderContext::sample());
    }

    public function test_separates_the_three_zones(): void
    {
        $document = $this->document();

        $this->assertStringContainsString('YAYASAN', (string) $document->headerHtml());
        $this->assertStringContainsString('Isi surat.', $document->bodyHtml());
        $this->assertStringContainsString('db-var-page', (string) $document->footerHtml());
    }

    public function test_returns_null_for_an_empty_zone(): void
    {
        $template = SchemaValidator::validate([
            'version' => 1, 'page' => [], 'style' => [],
            'zones' => ['header' => ['blocks' => []], 'body' => ['blocks' => []], 'footer' => ['blocks' => []]],
        ]);

        $document = (new HtmlRenderer)->render($template, RenderContext::sample());

        $this->assertNull($document->headerHtml());
        $this->assertNull($document->footerHtml());
    }

    public function test_exposes_zone_repetition_and_height(): void
    {
        $document = $this->document();

        $this->assertSame(ZoneRepeat::All, $document->headerRepeat());
        $this->assertSame(ZoneRepeat::ExceptFirst, $document->footerRepeat());
        $this->assertSame('auto', $document->headerHeight());
        $this->assertEqualsWithDelta(12.0, $document->footerHeight(), 0.001);
    }

    public function test_css_combines_page_layout_and_the_stylesheet(): void
    {
        $css = $this->document()->css();

        $this->assertStringContainsString('@page{size:210mm 297mm', $css);
        $this->assertStringContainsString('.doc-page', $css);
    }

    public function test_flow_html_carries_the_zone_containers(): void
    {
        $flow = $this->document()->flowHtml();

        $this->assertStringContainsString('class="doc-root"', $flow);
        $this->assertStringContainsString('class="doc-flow"', $flow);
        $this->assertStringContainsString('data-zone="header"', $flow);
        $this->assertStringContainsString('data-zone="body"', $flow);
        $this->assertStringContainsString('data-zone="footer"', $flow);
    }

    public function test_flow_html_carries_zone_settings_as_data_attributes(): void
    {
        $flow = $this->document()->flowHtml();

        $this->assertStringContainsString('data-repeat="all"', $flow);
        $this->assertStringContainsString('data-repeat="except-first"', $flow);
        $this->assertStringContainsString('data-height="auto"', $flow);
        $this->assertStringContainsString('data-height="12"', $flow);
    }

    public function test_full_html_is_a_self_contained_document(): void
    {
        $html = $this->document()->fullHtml();

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('@page{size:210mm 297mm', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<script src=', $html);
    }

    public function test_full_html_can_request_automatic_printing(): void
    {
        $this->assertStringContainsString('window.print()', $this->document()->fullHtml(autoPrint: true));
        $this->assertStringNotContainsString('window.print()', $this->document()->fullHtml());
    }

    public function test_header_height_is_a_computed_hint_when_it_holds_a_letterhead_image(): void
    {
        // mpdf tidak bisa mengukur zona "auto" sebelum merender. Tinggi kop gambar
        // sudah pasti dari rasio, jadi cadangannya dihitung, bukan ditaksir 35mm.
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'style' => [],
            'zones' => [
                'header' => ['height' => 'auto', 'blocks' => [
                    ['id' => 'kop', 'type' => 'letterhead-image', 'props' => ['src' => $this->pngDataUri(400, 80)]],
                ]],
                'body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Isi']]]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $document = (new HtmlRenderer)->render($template, RenderContext::sample());

        // Rasio 5:1, halaman A4 (210mm) bermargin atas 20mm: bleed 42mm,
        // dikurangi margin atas yang "dimakan" margin negatifnya sendiri = 22mm.
        $this->assertEqualsWithDelta(22.0, $document->headerHeight(), 0.001);
    }

    public function test_header_height_hint_accounts_for_letterhead_image_custom_margins(): void
    {
        // 400x80 -> rasio 5:1. Halaman A4 (210mm), margin atas 20mm.
        // Margin kop: marginTopMm = 5, marginRightMm = 10, marginLeftMm = 10, marginBottomMm = 8.
        // Lebar efektif = 210 - 10 - 10 = 190mm.
        // Tinggi = 190 / 5 = 38mm.
        // Hint = height + marginTopMm + marginBottomMm - pageMarginTop
        // = 38 + 5 + 8 - 20 = 31mm.
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'style' => [],
            'zones' => [
                'header' => ['height' => 'auto', 'blocks' => [
                    ['id' => 'kop', 'type' => 'letterhead-image', 'props' => [
                        'src' => $this->pngDataUri(400, 80),
                        'marginTopMm' => 5,
                        'marginRightMm' => 10,
                        'marginLeftMm' => 10,
                        'marginBottomMm' => 8,
                    ]],
                ]],
                'body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Isi']]]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $document = (new HtmlRenderer)->render($template, RenderContext::sample());

        $this->assertEqualsWithDelta(31.0, $document->headerHeight(), 0.001);
    }

    public function test_header_height_stays_auto_when_the_letterhead_image_cannot_be_read(): void
    {
        $template = SchemaValidator::validate([
            'version' => 1, 'page' => [], 'style' => [],
            'zones' => [
                'header' => ['height' => 'auto', 'blocks' => [
                    ['id' => 'kop', 'type' => 'letterhead-image', 'props' => ['src' => '']],
                ]],
                'body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Isi']]]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $document = (new HtmlRenderer)->render($template, RenderContext::sample());

        $this->assertSame('auto', $document->headerHeight());
    }

    public function test_header_height_stays_auto_when_the_letterhead_image_source_is_rejected(): void
    {
        // Cadangan tidak boleh membaca sumber yang ditolak kebijakan gambar —
        // renderer sendiri juga akan menampilkan penanda, bukan gambarnya.
        $template = SchemaValidator::validate([
            'version' => 1, 'page' => [], 'style' => [],
            'zones' => [
                'header' => ['height' => 'auto', 'blocks' => [
                    ['id' => 'kop', 'type' => 'letterhead-image', 'props' => ['src' => 'http://169.254.169.254/kop.png']],
                ]],
                'body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Isi']]]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $context = RenderContext::sample()->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/']));
        $document = (new HtmlRenderer)->render($template, $context);

        $this->assertSame('auto', $document->headerHeight());
    }

    private function pngDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function test_unknown_block_type_does_not_break_the_document(): void
    {
        // Blok tak dikenal tidak bisa lolos validator, jadi diuji lewat registry kosong.
        $template = SchemaValidator::validate([
            'version' => 1, 'page' => [], 'style' => [],
            'zones' => [
                'header' => ['blocks' => []],
                'body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'tetap ada']]]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $renderer = new HtmlRenderer(new BlockRendererRegistry);
        $body = $renderer->render($template, RenderContext::sample())->bodyHtml();

        $this->assertStringContainsString('db-marker', $body);
        $this->assertStringContainsString('Blok tidak dikenal', $body);
    }
}
