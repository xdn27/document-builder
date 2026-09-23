<?php

namespace Maqiis\DocumentBuilder\Tests\Pdf;

use Maqiis\DocumentBuilder\Pdf\GotenbergEngine;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class GotenbergEngineTest extends TestCase
{
    private function documentFromFixture(string $name): RenderedDocument
    {
        $raw = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/fixtures/'.$name),
            true,
        );

        return (new HtmlRenderer)->render(SchemaValidator::validate($raw), RenderContext::sample());
    }

    public function test_engine_reports_its_name(): void
    {
        $this->assertSame('gotenberg', (new GotenbergEngine('http://gotenberg:3000'))->name());
    }

    public function test_ready_html_replaces_window_print_with_a_polled_readiness_flag(): void
    {
        // fullHtml(autoPrint:true) memanggil window.print() — tidak berarti apa
        // apa di Chromium headless Gotenberg (tidak ada dialog untuk dipicu) dan
        // tidak dipakai di sini; readyHtml() harus memakai bentuk autoPrint:false
        // lalu menyisipkan penanda yang dipoll lewat waitForExpression.
        $document = $this->documentFromFixture('surat-satu-halaman.json');

        $method = new ReflectionMethod(GotenbergEngine::class, 'readyHtml');
        $html = $method->invoke(new GotenbergEngine('http://gotenberg:3000'), $document);

        $this->assertStringNotContainsString('window.print()', $html);
        $this->assertStringContainsString('await paginateDocument();window.__documentBuilderReady = true;', $html);
    }

    /**
     * Butuh instance Gotenberg sungguhan — tidak dijalankan di suite default.
     * Jalankan manual: docker run -d -p 3000:3000 gotenberg/gotenberg:8, lalu
     * DOCUMENT_BUILDER_TEST_GOTENBERG_URL=http://localhost:3000 ./vendor/bin/phpunit --filter Gotenberg
     */
    public function test_renders_real_pdf_bytes_via_a_live_gotenberg_instance(): void
    {
        $baseUrl = getenv('DOCUMENT_BUILDER_TEST_GOTENBERG_URL');

        if ($baseUrl === false || $baseUrl === '') {
            $this->markTestSkipped('DOCUMENT_BUILDER_TEST_GOTENBERG_URL tidak diset; lewati test terhadap Gotenberg sungguhan.');
        }

        $engine = new GotenbergEngine($baseUrl);
        $pdf = $engine->render($this->documentFromFixture('surat-satu-halaman.json'));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
