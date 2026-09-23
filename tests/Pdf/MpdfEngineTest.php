<?php

namespace Maqiis\DocumentBuilder\Tests\Pdf;

use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Pdf\MpdfEngine;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\BlockType;
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

        return $method->invoke($engine, $html);
    }

    public function test_neutralize_top_bleed_honors_custom_data_margin_top(): void
    {
        $engine = new MpdfEngine;
        $method = new ReflectionMethod(MpdfEngine::class, 'neutralizeTopBleed');

        $htmlWithCustomMargin = '<div class="db-letterhead-image__bleed" style="margin-top:-15mm;margin-right:-10mm;margin-left:-10mm" data-margin-top="5mm"><img src="x" /></div>';
        $neutralized = $method->invoke($engine, $htmlWithCustomMargin);

        $this->assertStringContainsString('style="margin-top:0;padding-top:5mm;margin-right:-10mm;margin-left:-10mm"', $neutralized);

        $htmlDefault = '<div class="db-letterhead-image__bleed" style="margin-top:-20mm;margin-right:-20mm;margin-left:-25mm" data-margin-top="0mm"><img src="x" /></div>';
        $neutralizedDefault = $method->invoke($engine, $htmlDefault);

        $this->assertStringContainsString('style="margin-top:0;padding-top:0mm;margin-right:-20mm;margin-left:-25mm"', $neutralizedDefault);
    }

    public function test_render_positions_letterhead_image_at_margin_top_offset(): void
    {
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'style' => [],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => [
                    ['id' => 'kop', 'type' => 'letterhead-image', 'props' => [
                        'src' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                        'marginTopMm' => 15.0,
                        'marginRightMm' => 10.0,
                        'marginBottomMm' => 5.0,
                        'marginLeftMm' => 10.0,
                    ]],
                ]],
                'body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Isi']]]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $document = (new HtmlRenderer)->render($template, RenderContext::sample());
        $pdf = (new MpdfEngine)->render($document);

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $found = false;
        foreach ($streams[1] as $raw) {
            $decomp = @gzuncompress($raw);
            if ($decomp !== false && str_contains($decomp, 'Do')) {
                if (preg_match('/([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+cm/', $decomp, $m)) {
                    $yPt = (float) $m[6];
                    $hPt = (float) $m[4];
                    $topMm = (841.89 - ($yPt + $hPt)) / 72 * 25.4;
                    $this->assertEqualsWithDelta(15.0, $topMm, 0.1);
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'Perintah gambar tidak ditemukan di stream PDF.');
    }

    public function test_paragraph_space_before_is_honored_in_mpdf(): void
    {
        $createDoc = static function (float $spaceBefore): RenderedDocument {
            $template = SchemaValidator::validate([
                'version' => 1,
                'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
                'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
                'zones' => [
                    'body' => ['blocks' => [
                        ['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Isi Paragraf', 'spaceBeforeMm' => $spaceBefore]],
                    ]],
                    'footer' => ['blocks' => []],
                ],
            ]);

            return (new HtmlRenderer)->render($template, RenderContext::sample());
        };

        $getY = static function (string $pdf): float {
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
            foreach ($streams[1] as $raw) {
                $decomp = @gzuncompress($raw);
                if ($decomp !== false && preg_match('/([0-9.]+)\s+([0-9.]+)\s+Td\s*\(\x00I/s', $decomp, $m)) {
                    return (float) $m[2];
                }
            }

            return 0.0;
        };

        $engine = new MpdfEngine;
        $pdf0 = $engine->render($createDoc(0.0));
        $pdf15 = $engine->render($createDoc(15.0));

        $y0 = $getY($pdf0);
        $y15 = $getY($pdf15);

        $this->assertGreaterThan(0.0, $y0);
        $this->assertGreaterThan(0.0, $y15);

        // 15mm setara 42.52 pt (15 * 72 / 25.4); y0 - y15 harus mencerminkan pergeseran ke bawah sebesar 15mm
        $diffMm = ($y0 - $y15) * 25.4 / 72;
        $this->assertEqualsWithDelta(15.0, $diffMm, 0.1);
    }

    public function test_table_outer_margins_honored_in_mpdf(): void
    {
        $createDoc = static function (float $marginTop, float $marginLeft): RenderedDocument {
            $template = SchemaValidator::validate([
                'version' => 1,
                'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
                'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
                'zones' => [
                    'body' => ['blocks' => [
                        [
                            'id' => 'tbl',
                            'type' => 'table',
                            'props' => [
                                'columns' => [['label' => 'Header', 'widthPercent' => 100, 'align' => 'left']],
                                'rows' => [['Teks Tabel']],
                                'marginTopMm' => $marginTop,
                                'marginLeftMm' => $marginLeft,
                            ],
                        ],
                    ]],
                    'footer' => ['blocks' => []],
                ],
            ]);

            return (new HtmlRenderer)->render($template, RenderContext::sample());
        };

        $getCoords = static function (string $pdf): array {
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
            foreach ($streams[1] as $raw) {
                $decomp = @gzuncompress($raw);
                if ($decomp !== false && preg_match('/([0-9.]+)\s+([0-9.]+)\s+Td\s*\(\x00T\x00e\x00k\x00s/s', $decomp, $m)) {
                    return [(float) $m[1], (float) $m[2]];
                }
            }

            return [0.0, 0.0];
        };

        $engine = new MpdfEngine;
        $pdf0 = $engine->render($createDoc(0.0, 0.0));
        $pdfMargin = $engine->render($createDoc(15.0, 10.0));

        [$x0, $y0] = $getCoords($pdf0);
        [$x1, $y1] = $getCoords($pdfMargin);

        $this->assertGreaterThan(0.0, $x0);
        $this->assertGreaterThan(0.0, $y0);

        // Pergeseran vertikal sebesar 15mm
        $diffYMm = ($y0 - $y1) * 25.4 / 72;
        $this->assertEqualsWithDelta(15.0, $diffYMm, 0.1);

        // Pergeseran horizontal sebesar 10mm
        $diffXMm = ($x1 - $x0) * 25.4 / 72;
        $this->assertEqualsWithDelta(10.0, $diffXMm, 0.1);
    }

    public function test_signature_image_scales_and_offsets_in_mpdf_without_displacing_adjacent_text(): void
    {
        $createDoc = static function (float $scalePercent, float $offsetYMm, float $offsetXMm) {
            $im = imagecreatetruecolor(100, 50);
            $red = imagecolorallocate($im, 255, 0, 0);
            imagefilledrectangle($im, 0, 0, 99, 49, $red);
            ob_start();
            imagepng($im);
            $dataUri = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

            $template = SchemaValidator::validate([
                'version' => 1,
                'page' => [],
                'style' => [],
                'zones' => [
                    'header' => ['blocks' => []],
                    'body' => ['blocks' => [[
                        'id' => 'sig',
                        'type' => BlockType::Signature->value,
                        'props' => [
                            'columns' => [[
                                'place' => '', 'date' => '', 'position' => 'Kepala Sekolah',
                                'signature' => $dataUri, 'name' => 'Ahmad Fauzi', 'nip' => '',
                            ]],
                            'spaceMm' => 25.0,
                            'imageScalePercent' => $scalePercent,
                            'imageOffsetYMm' => $offsetYMm,
                            'imageOffsetXMm' => $offsetXMm,
                        ],
                    ]]],
                    'footer' => ['blocks' => []],
                ],
            ]);

            return (new HtmlRenderer)->render($template, RenderContext::sample());
        };

        $getImageAndTextCoords = static function (string $pdf): array {
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
            $imgCoords = [0.0, 0.0, 0.0, 0.0];
            $txtCoords = [];
            foreach ($streams[1] as $raw) {
                $decomp = @gzuncompress($raw);
                if ($decomp === false) {
                    continue;
                }
                if (preg_match('/q\s+([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+([0-9.-]+)\s+cm\s+\/[^ ]+\s+Do\s+Q/', $decomp, $im)) {
                    $imgCoords = [(float) $im[5], (float) $im[6], (float) $im[1], (float) $im[4]];
                }
                if (preg_match_all('/([0-9.]+)\s+([0-9.]+)\s+Td\s*\(\x00([^\)]+)\)/s', $decomp, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $txtCoords[] = [(float) $m[1], (float) $m[2]];
                    }
                }
            }

            return [$imgCoords, $txtCoords];
        };

        $engine = new MpdfEngine;
        $pdfBase = $engine->render($createDoc(100.0, 0.0, 0.0));
        $pdfShifted = $engine->render($createDoc(100.0, 5.0, 4.0));

        [$img0, $txt0] = $getImageAndTextCoords($pdfBase);
        [$img1, $txt1] = $getImageAndTextCoords($pdfShifted);

        // Posisi vertikal teks "Kepala Sekolah" dan "Ahmad Fauzi" tetap sama persis (tidak terdorong)
        $this->assertNotEmpty($txt0);
        $this->assertCount(count($txt0), $txt1);
        $this->assertEqualsWithDelta($txt0[0][1], $txt1[0][1], 0.01);

        // Gambar tanda tangan bergeser 5mm ke bawah (menumpuk/overlap ke arah nama)
        $diffYMm = ($img0[1] - $img1[1]) * 25.4 / 72;
        $this->assertEqualsWithDelta(5.0, $diffYMm, 0.1);

        // Gambar tanda tangan bergeser 4mm ke kanan
        $diffXMm = ($img1[0] - $img0[0]) * 25.4 / 72;
        $this->assertEqualsWithDelta(4.0, $diffXMm, 0.1);
    }
}
