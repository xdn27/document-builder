<?php

namespace Maqiis\DocumentBuilder\Tests\Pdf;

use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Pdf\MpdfEngine;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MpdfEngineTest extends TestCase
{
    private function documentFromFixture(string $name, array $overrides = []): RenderedDocument
    {
        $raw = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/fixtures/'.$name),
            true,
        );

        return (new HtmlRenderer)->render(SchemaValidator::validate(array_replace_recursive($raw, $overrides)), RenderContext::sample());
    }

    public function test_renders_almarai_with_the_font_actually_embedded(): void
    {
        // Almarai tidak bisa didaftarkan ke mpdf lewat @font-face di CSS (mpdf
        // modern tidak memprosesnya sama sekali) — MpdfEngine mendaftarkannya
        // lewat opsi konstruktor 'fontDir'/'fontdata' (lihat FontRegistry::
        // mpdfFontFiles()). Memeriksa byte PDF memastikan font yang benar-benar
        // tertanam adalah Almarai, bukan substitusi diam-diam ke font bawaan
        // mpdf (mis. DejaVu Sans) yang tetap menghasilkan PDF valid tapi salah.
        $document = $this->documentFromFixture('surat-satu-halaman.json', ['style' => ['fontFamily' => 'almarai']]);

        $pdf = (new MpdfEngine)->render($document);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('Almarai-Regular', $pdf);
    }

    public function test_produces_pdf_bytes(): void
    {
        $pdf = (new MpdfEngine)->render($this->documentFromFixture('surat-satu-halaman.json'));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_reports_a_single_page_for_a_short_letter(): void
    {
        $engine = new MpdfEngine;
        $engine->render($this->documentFromFixture('surat-satu-halaman.json'));

        $this->assertSame(1, $engine->lastPageCount());
    }

    public function test_a_long_table_spans_several_pages(): void
    {
        $engine = new MpdfEngine;
        $engine->render($this->documentFromFixture('surat-tabel-panjang.json'));

        $this->assertGreaterThan(1, $engine->lastPageCount());
    }

    public function test_renders_a_full_bleed_letterhead_image_without_error(): void
    {
        // Geometri sesungguhnya (kop menembus tepi) diverifikasi manual lewat
        // pdftoppm — lihat catatan verifikasi; di sini cukup dipastikan mpdf
        // tidak gagal saat margin_header diturunkan ke 0 untuk kop full-bleed.
        $engine = new MpdfEngine;
        $pdf = $engine->render($this->documentFromFixture('surat-kop-gambar.json'));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $engine->lastPageCount());
    }

    public function test_engine_reports_its_name(): void
    {
        $this->assertSame('mpdf', (new MpdfEngine)->name());
    }

    public function test_resolved_css_contains_no_custom_properties(): void
    {
        $css = $this->documentFromFixture('surat-satu-halaman.json')->resolvedCss();

        $this->assertStringNotContainsString('var(--db-', $css);
        $this->assertStringNotContainsString('--db-page-w:', $css);
        $this->assertStringContainsString('165mm', $css);
    }

    public function test_resolved_css_contains_no_flexbox(): void
    {
        // mpdf tidak mengenal flexbox; kalau ada yang lolos, tata letaknya diam-diam berbeda.
        $this->assertStringNotContainsString(
            'display:flex',
            str_replace(' ', '', $this->documentFromFixture('surat-satu-halaman.json')->resolvedCss()),
        );
    }

    public function test_page_markers_become_mpdf_tokens(): void
    {
        $document = $this->documentFromFixture('surat-satu-halaman.json');

        $this->assertStringContainsString('{PAGENO}', (string) $document->footerHtmlForEngine());
        $this->assertStringContainsString('{nbpg}', (string) $document->footerHtmlForEngine());
        $this->assertStringNotContainsString('db-var-page', (string) $document->footerHtmlForEngine());
    }

    public function test_resolve_images_is_a_no_op_without_a_resolver(): void
    {
        $html = '<div><img src="https://cdn.test/kop.jpg" alt="x" /></div>';

        $this->assertSame($html, $this->invokeResolveImages(new MpdfEngine, $html));
    }

    public function test_resolve_images_delegates_every_img_src_to_the_injected_resolver(): void
    {
        $resolver = new class implements ImageResolver
        {
            /** @var list<string> */
            public array $seen = [];

            public function resolve(string $src): string
            {
                $this->seen[] = $src;

                return '/tmp/resolved.png';
            }
        };

        $engine = new MpdfEngine(images: $resolver);
        $html = '<div><img src="https://cdn.test/kop.jpg" alt="x" /><img src="https://cdn.test/ttd.png" /></div>';

        $result = $this->invokeResolveImages($engine, $html);

        $this->assertSame(['https://cdn.test/kop.jpg', 'https://cdn.test/ttd.png'], $resolver->seen);
        $this->assertSame(2, substr_count((string) $result, 'src="/tmp/resolved.png"'));
    }

    public function test_render_still_succeeds_when_a_resolver_is_injected(): void
    {
        // Kop di fixture ini berupa data URI, jadi resolver sengaja tidak
        // menemukan apa pun untuk diganti — yang diverifikasi di sini hanyalah
        // bahwa memasang resolver tidak merusak jalur render sama sekali.
        $resolver = new class implements ImageResolver
        {
            public function resolve(string $src): string
            {
                return $src;
            }
        };

        $engine = new MpdfEngine(images: $resolver);
        $pdf = $engine->render($this->documentFromFixture('surat-kop-gambar.json'));

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    private function invokeResolveImages(MpdfEngine $engine, ?string $html): ?string
    {
        $method = new ReflectionMethod(MpdfEngine::class, 'resolveImages');
        $method->setAccessible(true);

        return $method->invoke($engine, $html);
    }
}
