<?php

namespace Maqiis\DocumentBuilder\Tests\Qr;

use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Maqiis\DocumentBuilder\Qr\QrEngineHtml;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use PHPUnit\Framework\TestCase;

class QrEngineHtmlTest extends TestCase
{
    private function document(array $zones): \Maqiis\DocumentBuilder\Render\RenderedDocument
    {
        $generator = new class implements QrCodeGenerator
        {
            public function toSvg(string $payload, float $sizeMm): string
            {
                return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="5" height="5"/></svg>';
            }
        };
        $schema = Template::blank()->toArray();

        foreach ($zones as $zone => $blocks) {
            $schema['zones'][$zone]['blocks'] = $blocks;
        }

        return (new HtmlRenderer)->render(SchemaValidator::validate($schema), RenderContext::sample()->withQr($generator));
    }

    private function qr(string $id, array $props): array
    {
        return ['id' => $id, 'type' => 'qrcode', 'props' => ['payload' => 'x'] + $props];
    }

    public function test_html_without_a_qr_is_returned_untouched(): void
    {
        $html = '<div class="doc-block"><p>Halo <svg width="1" height="1"></svg></p></div>';

        $this->assertSame($html, QrEngineHtml::convert($html));
    }

    public function test_an_inline_qr_becomes_an_image_of_the_same_size_for_mpdf(): void
    {
        $document = $this->document(['body' => [$this->qr('q', ['sizeMm' => 35, 'align' => 'center'])]]);

        $browser = $document->bodyHtml();
        $engine = $document->bodyHtmlForEngine();

        $this->assertStringContainsString('<svg class="db-qrcode__svg"', $browser);
        $this->assertStringNotContainsString('<svg', $engine);
        $this->assertStringContainsString('<img class="db-qrcode__img" src="data:image/svg+xml;base64,', $engine);
        $this->assertStringContainsString('style="width:35mm;height:35mm"', $engine);

        preg_match('/base64,([^"]+)"/', $engine, $m);
        $this->assertStringContainsString('width="35mm"', (string) base64_decode($m[1]));
    }

    public function test_a_fixed_qr_uses_a_transform_in_the_browser_and_top_left_for_mpdf(): void
    {
        $document = $this->document(['body' => [$this->qr('q', ['sizeMm' => 20, 'positionMode' => 'fixed', 'topMm' => 240, 'leftMm' => 30])]]);

        $this->assertStringContainsString('transform:translate(30mm,240mm)', $document->bodyHtml());

        $engine = $document->bodyHtmlForEngine();
        $this->assertStringContainsString('style="position:absolute;top:240mm;left:30mm"', $engine);
        $this->assertStringNotContainsString('transform', $engine);
        $this->assertStringNotContainsString('data-top', $engine);
    }

    public function test_header_and_footer_are_converted_for_the_engine_too(): void
    {
        $document = $this->document([
            'header' => [$this->qr('qh', ['sizeMm' => 12, 'positionMode' => 'fixed', 'topMm' => 15, 'leftMm' => 195])],
            'footer' => [$this->qr('qf', ['sizeMm' => 12, 'positionMode' => 'fixed', 'topMm' => 265, 'leftMm' => 195])],
        ]);

        $this->assertStringContainsString('top:15mm;left:195mm', (string) $document->headerHtmlForEngine());
        $this->assertStringContainsString('top:265mm;left:195mm', (string) $document->footerHtmlForEngine());
        $this->assertStringNotContainsString('<svg', (string) $document->headerHtmlForEngine());
        $this->assertStringContainsString('<svg', (string) $document->headerHtml());
    }
}
