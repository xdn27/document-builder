<?php

namespace Maqiis\DocumentBuilder\Tests\Pdf;

use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Pdf\MpdfEngine;
use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Dalam mode utf-8 mpdf tidak memakai font inti PDF: "times" diterjemahkan ke
     * "timesnewroman" yang tidak disertakan mpdf, lalu jatuh diam-diam ke DejaVu —
     * 16% lebih lebar dari Times, sehingga baris yang pas di browser terlipat di PDF.
     *
     * @return array<string, array{string, string}>
     */
    public static function metricCompatibleFonts(): array
    {
        return [
            'tinos' => ['tinos', 'LiberationSerif'],
            'arimo' => ['arimo', 'LiberationSans'],
            'cousine' => ['cousine', 'LiberationMono'],
        ];
    }

    #[DataProvider('metricCompatibleFonts')]
    public function test_embeds_a_metric_compatible_font_instead_of_dejavu(string $key, string $embedded): void
    {
        $document = $this->documentFromFixture('surat-satu-halaman.json', ['style' => ['fontFamily' => $key]]);

        $pdf = (new MpdfEngine)->render($document);

        $this->assertStringContainsString($embedded, $pdf);
        $this->assertStringNotContainsString('DejaVu', $pdf);
    }

    public function test_bold_text_uses_the_bold_face_of_the_same_family(): void
    {
        // Nama di blok tanda tangan (tebal) yang memicu laporan ini: lipatannya
        // berasal dari DejaVuSerifCondensed-Bold.
        $pdf = (new MpdfEngine)->render($this->documentFromFixture('surat-satu-halaman.json'));

        $this->assertStringContainsString('LiberationSerif-Bold', $pdf);
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

    public function test_draws_the_watermark_on_every_page(): void
    {
        $engine = new MpdfEngine;
        $pdf = $engine->render($this->documentFromFixture('surat-tabel-panjang.json', [
            'watermark' => ['text' => 'DRAF', 'opacity' => 0.17],
        ]));

        // Watermark teks mpdf memasang ExtGState transparansi dengan alpha yang diminta.
        $this->assertMatchesRegularExpression('#/ca 0\.17\b#', $pdf);
        $this->assertGreaterThan(1, $engine->lastPageCount());
    }

    public function test_watermark_does_not_change_the_page_count(): void
    {
        $plain = new MpdfEngine;
        $plain->render($this->documentFromFixture('surat-tabel-panjang.json'));

        $marked = new MpdfEngine;
        $marked->render($this->documentFromFixture('surat-tabel-panjang.json', ['watermark' => ['text' => 'RAHASIA']]));

        $this->assertSame($plain->lastPageCount(), $marked->lastPageCount());
    }

    public function test_no_transparency_without_a_watermark(): void
    {
        $pdf = (new MpdfEngine)->render($this->documentFromFixture('surat-satu-halaman.json'));

        $this->assertDoesNotMatchRegularExpression('#/ca 0\.#', $pdf);
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
            $decomp = self::plainText(@gzuncompress($raw));
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
                $decomp = self::plainText(@gzuncompress($raw));
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
                $decomp = self::plainText(@gzuncompress($raw));
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

    public function test_a_fixed_height_footer_is_filled_from_the_top_in_mpdf(): void
    {
        // mpdf menempelkan isi kaki ke bawah kotaknya; browser mengisinya dari atas. Dengan
        // kotak 15 mm lebih tinggi, baris pertama harus naik tepat 15 mm.
        $blocks = [$this->spacedParagraph('f1', 'Kaki1', 0, 2), $this->spacedParagraph('f2', 'Kaki2', 3, 0)];
        $low = (new MpdfEngine)->render($this->footerDocument($blocks, 25));
        $high = (new MpdfEngine)->render($this->footerDocument($blocks, 40));

        $this->assertGreaterThan(0.0, $this->textY($low, 'Kaki1'));
        $this->assertEqualsWithDelta(15.0, $this->textY($high, 'Kaki1') - $this->textY($low, 'Kaki1'), 0.1);
        // Jarak sesudah (2) dan jarak sebelum (3) dijumlahkan seperti di isi dokumen.
        $this->assertEqualsWithDelta(self::LINE_MM + 5.0, $this->textY($low, 'Kaki1') - $this->textY($low, 'Kaki2'), 0.1);
    }

    public function test_an_auto_height_footer_honours_space_after_in_mpdf(): void
    {
        // Di kaki, mpdf membuang margin-bottom setiap blok kecuali kotaknya diberi tinggi.
        $between = (new MpdfEngine)->render($this->footerDocument(
            [$this->spacedParagraph('f1', 'Kaki1', 0, 2), $this->spacedParagraph('f2', 'Kaki2', 3, 0)],
            'auto',
        ));
        $this->assertEqualsWithDelta(self::LINE_MM + 5.0, $this->textY($between, 'Kaki1') - $this->textY($between, 'Kaki2'), 0.1);

        $flush = (new MpdfEngine)->render($this->footerDocument([$this->spacedParagraph('f1', 'Kaki1', 0, 0)], 'auto'));
        $lifted = (new MpdfEngine)->render($this->footerDocument([$this->spacedParagraph('f1', 'Kaki1', 0, 5)], 'auto'));
        // Jarak sesudah blok terakhir mengangkat isi kaki dari tepi bawahnya.
        $this->assertEqualsWithDelta(5.0, $this->textY($lifted, 'Kaki1') - $this->textY($flush, 'Kaki1'), 0.1);
    }

    public function test_a_signature_at_the_page_edge_moves_whole_to_the_next_page_in_mpdf(): void
    {
        // 26 paragraf menyisakan ruang lebih kecil dari blok tanda tangan: paginator browser
        // memindahkannya utuh; mpdf dulu memotongnya di antara jabatan dan nama.
        $pdf = (new MpdfEngine)->render($this->flowDocument([
            ...$this->fillerParagraphs(26),
            ['id' => 'sig', 'type' => 'signature', 'props' => [
                'columns' => [['place' => 'Kota', 'date' => 'hari ini', 'position' => 'Jabatan', 'name' => 'Penanda', 'nip' => '1']],
                'spaceMm' => 25,
                'spaceBeforeMm' => 4,
            ]],
        ]));

        // Dari tepi bawah kertas: baris yang lebih bawah di halaman yang sama bernilai lebih kecil.
        $this->assertGreaterThan($this->textY($pdf, 'Jabatan'), $this->textY($pdf, 'Kota'));
        $this->assertGreaterThan($this->textY($pdf, 'Penanda'), $this->textY($pdf, 'Jabatan'));
        // Jarak sebelum (4 mm) tetap ada di puncak halaman, seperti di browser.
        $this->assertEqualsWithDelta($this->textY($pdf, 'Isi1') - 4.0, $this->textY($pdf, 'Kota'), 0.3);
    }

    public function test_a_paragraph_at_the_page_edge_moves_whole_to_the_next_page_in_mpdf(): void
    {
        $pdf = (new MpdfEngine)->render($this->flowDocument([
            ...$this->fillerParagraphs(26),
            $this->spacedParagraph('long', 'Awalan<br>'.str_repeat('lorem ipsum dolor sit amet consectetur ', 40).'<br>Akhiran', 6, 2),
        ]));

        $this->assertGreaterThan($this->textY($pdf, 'Akhiran'), $this->textY($pdf, 'Awalan'));
        $this->assertEqualsWithDelta($this->textY($pdf, 'Isi1') - 6.0, $this->textY($pdf, 'Awalan'), 0.3);
    }

    public function test_a_list_item_at_the_page_edge_moves_whole_to_the_next_page_in_mpdf(): void
    {
        // Paginator browser memecah daftar per butir, tidak pernah di tengah butir; mpdf dulu
        // memecahnya per baris. Diuji pada beberapa posisi supaya salah satunya pasti di tepi.
        $long = str_repeat('lorem ipsum dolor sit amet ', 14);

        foreach ([20, 24, 25, 26, 28] as $fillers) {
            $pdf = (new MpdfEngine)->render($this->flowDocument([
                ...$this->fillerParagraphs($fillers),
                ['id' => 'list', 'type' => 'list', 'props' => ['items' => [
                    ['text' => "satu<br>MulaiA {$long}<br>UjungA", 'level' => 0],
                    ['text' => "dua<br>MulaiB {$long}<br>UjungB", 'level' => 0],
                    ['text' => "tiga<br>MulaiC {$long}<br>UjungC", 'level' => 1],
                ]]],
            ]));

            foreach (['A', 'B', 'C'] as $item) {
                $this->assertGreaterThan(0.0, $this->textY($pdf, 'Ujung'.$item));
                $this->assertGreaterThan(
                    $this->textY($pdf, 'Ujung'.$item),
                    $this->textY($pdf, 'Mulai'.$item),
                    "butir {$item} terpotong dengan {$fillers} paragraf di depannya",
                );
            }
        }
    }

    public function test_an_auto_height_header_reserves_exactly_its_own_height_in_mpdf(): void
    {
        // Dulu zona "auto" diberi cadangan tetap 35 mm, sehingga isi mulai jauh di bawah kop
        // (atau menimpanya bila kop lebih tinggi). Di browser isi mulai tepat di bawah kop.
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => [
                    $this->spacedParagraph('h1', 'Kopsatu', 0, 0),
                    $this->spacedParagraph('h2', 'Kopdua', 3, 4),
                ]],
                'body' => ['blocks' => [$this->spacedParagraph('b1', 'Isi', 0, 0)]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $pdf = (new MpdfEngine)->render((new HtmlRenderer)->render($template, RenderContext::sample()));

        // Kop: baris, jarak 3, baris, jarak 4 → isi mulai 2 baris + 7 mm di bawah baris pertama kop.
        $this->assertEqualsWithDelta(2 * self::LINE_MM + 7.0, $this->textY($pdf, 'Kopsatu') - $this->textY($pdf, 'Isi'), 0.2);
    }

    public function test_an_auto_height_footer_reserves_exactly_its_own_height_in_mpdf(): void
    {
        // Isi setinggi 248,5 mm dan kaki satu baris (6,35 mm) muat di area 257 mm; dengan
        // cadangan tetap 12 mm yang lama, baris terakhir terdorong ke halaman kedua.
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'body' => ['blocks' => [...$this->fillerParagraphs(29), $this->spacedParagraph('last', 'Terakhir', 0, 0)]],
                'footer' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => [$this->spacedParagraph('f1', 'Kaki', 0, 0)]],
            ],
        ]);
        $engine = new MpdfEngine;

        $engine->render((new HtmlRenderer)->render($template, RenderContext::sample()));

        $this->assertSame(1, $engine->lastPageCount());
    }

    public function test_zone_repeat_modes_are_honoured_in_mpdf(): void
    {
        // Dulu kop/kaki selalu tampil di semua halaman mpdf, apa pun pengaturan pengulangannya.
        $render = function (string $headerRepeat, string $footerRepeat): array {
            $template = SchemaValidator::validate([
                'version' => 1,
                'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
                'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
                'zones' => [
                    'header' => ['repeat' => $headerRepeat, 'height' => 'auto', 'blocks' => [$this->spacedParagraph('h', 'Kopsurat', 0, 4)]],
                    'body' => ['blocks' => $this->fillerParagraphs(70)],
                    'footer' => ['repeat' => $footerRepeat, 'height' => 'auto', 'blocks' => [$this->spacedParagraph('f', 'Kakisurat', 0, 0)]],
                ],
            ]);
            $engine = new MpdfEngine;
            $pdf = $engine->render((new HtmlRenderer)->render($template, RenderContext::sample()));

            return [$this->textCount($pdf, 'Kopsurat'), $this->textCount($pdf, 'Kakisurat'), (int) $engine->lastPageCount()];
        };

        [$headers, $footers, $pages] = $render('all', 'all');
        $this->assertGreaterThan(2, $pages);
        $this->assertSame([$pages, $pages], [$headers, $footers]);

        [$headers, $footers, $pages] = $render('first-only', 'except-first');
        $this->assertSame([1, $pages - 1], [$headers, $footers]);

        [$headers, $footers, $pages] = $render('except-first', 'first-only');
        $this->assertSame([$pages - 1, 1], [$headers, $footers]);
    }

    public function test_a_first_only_auto_header_frees_its_space_on_later_pages_in_mpdf(): void
    {
        // Kop "auto" yang hanya di halaman pertama tidak memakan ruang di halaman berikutnya
        // (di browser zona itu kosong di sana): dokumen yang sama dengan kop di semua halaman
        // butuh lebih banyak halaman.
        $pages = function (string $repeat): int {
            $template = SchemaValidator::validate([
                'version' => 1,
                'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
                'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
                'zones' => [
                    'header' => ['repeat' => $repeat, 'height' => 'auto', 'blocks' => [
                        ['id' => 'sp', 'type' => 'spacer', 'props' => ['heightMm' => 120]],
                    ]],
                    'body' => ['blocks' => $this->fillerParagraphs(45)],
                    'footer' => ['blocks' => []],
                ],
            ]);
            $engine = new MpdfEngine;
            $engine->render((new HtmlRenderer)->render($template, RenderContext::sample()));

            return (int) $engine->lastPageCount();
        };

        // 45 paragraf × 8,35 mm = 376 mm. Kop 120 mm di semua halaman: 137 mm isi per halaman → 3
        // halaman. Hanya di halaman pertama: 137 + 257 → 2 halaman.
        $this->assertSame(3, $pages('all'));
        $this->assertSame(2, $pages('first-only'));
    }

    public function test_a_repeating_table_header_repeats_on_every_page_in_mpdf(): void
    {
        $rows = array_map(static fn (int $i): array => ["Baris{$i}"], range(1, 70));
        $render = function (bool $repeat) use ($rows): array {
            $engine = new MpdfEngine;
            $pdf = $engine->render($this->flowDocument([[
                'id' => 't',
                'type' => 'table',
                'props' => ['columns' => [['label' => 'Judulkolom', 'widthPercent' => 0, 'align' => 'left']], 'rows' => $rows, 'showHeader' => true, 'repeatHeader' => $repeat],
            ]]));

            return [$this->textCount($pdf, 'Judulkolom'), (int) $engine->lastPageCount()];
        };

        [$count, $pages] = $render(true);
        $this->assertGreaterThan(1, $pages);
        $this->assertSame($pages, $count);

        [$count] = $render(false);
        $this->assertSame(1, $count);
    }

    public function test_a_pinned_header_is_not_drawn_twice_when_a_block_moves_to_the_next_page(): void
    {
        // mpdf menulis ulang kop halaman baru saat memindahkan blok utuh; kotak berposisi tetap di
        // dalam kop (QR tetap, zona per halaman) lalu tercetak ganda.
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'first-only', 'height' => 'auto', 'blocks' => [$this->spacedParagraph('h', 'Kopsurat', 0, 4)]],
                'body' => ['blocks' => [
                    ...$this->fillerParagraphs(28),
                    $this->spacedParagraph('long', 'Awalan<br>'.str_repeat('lorem ipsum dolor sit amet consectetur ', 40).'<br>Akhiran', 0, 2),
                ]],
                'footer' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => [$this->spacedParagraph('f', 'Kakisurat', 0, 0)]],
            ],
        ]);

        $pdf = (new MpdfEngine)->render((new HtmlRenderer)->render($template, RenderContext::sample()));
        $needle = implode('', array_map(static fn (string $c): string => "\x00".$c, str_split('Kakisurat')));
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $raw) {
            $decomp = self::plainText(@gzuncompress($raw));

            if ($decomp !== false) {
                $this->assertLessThanOrEqual(1, substr_count($decomp, '('.$needle));
            }
        }

        $this->assertSame(2, $this->textCount($pdf, 'Kakisurat'));
    }

    public function test_mpdf_applies_font_kerning_like_the_browser(): void
    {
        $document = $this->flowDocument([$this->spacedParagraph('p', 'Isi', 0, 0)]);
        $config = (new \ReflectionMethod(MpdfEngine::class, 'mpdfConfig'))->invoke(new MpdfEngine, $document, $document->pageSetup(), 0.0, 0.0);

        $this->assertTrue($config['useKerning']);
    }

    public function test_mpdf_justifies_with_word_spacing_only_like_the_browser(): void
    {
        $document = $this->flowDocument([$this->spacedParagraph('p', 'Isi', 0, 0)]);
        $config = (new \ReflectionMethod(MpdfEngine::class, 'mpdfConfig'))->invoke(new MpdfEngine, $document, $document->pageSetup(), 0.0, 0.0);

        $this->assertSame([1.0, 0, 0, 0], [$config['jSWord'], $config['jSmaxChar'], $config['jSmaxCharLast'], $config['jSmaxWordLast']]);
    }

    public function test_list_markers_get_a_fixed_width_in_mpdf(): void
    {
        // Di browser penanda butir adalah inline-block 6 mm; mpdf tidak mengenalnya, jadi engine
        // menambahkan pengisi supaya teks butir mulai di tempat yang sama.
        $engine = new MpdfEngine;
        $pdf = $engine->render($this->flowDocument([['id' => 'l', 'type' => 'list', 'props' => ['style' => 'number', 'items' => [
            ['text' => 'satu', 'level' => 0],
        ]]]]));

        $html = (new \ReflectionMethod(MpdfEngine::class, 'listMarkersForEngine'))->invoke(
            $engine,
            '<span class="db-list__marker">1.</span><span class="db-list__text">satu</span>',
            new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir().'/mpdf']),
            $this->flowDocument([$this->spacedParagraph('p', 'Isi', 0, 0)]),
        );

        $this->assertNotSame('', $pdf);
        $this->assertSame(1, preg_match('#</span><img src="data:image/gif;base64,[^"]+" style="width:([0-9.]+)mm;height:0.1mm" alt="" />#', $html, $m));
        // "1." pada 12pt selebar ±3,6 mm → pengisi ±2,4 mm.
        $this->assertEqualsWithDelta(2.4, (float) $m[1], 0.3);
        $this->assertStringContainsString('width: 6mm', \Maqiis\DocumentBuilder\Asset\AssetLoader::css());
    }

    public function test_the_header_is_drawn_once_on_a_page_opened_by_a_moved_block(): void
    {
        // Larangan potong milik mpdf menulis blok dua kali, dan kop halaman baru ikut tertulis
        // dua kali. Engine kini memindahkan blok sendiri, jadi kop hanya tertulis sekali.
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => [$this->spacedParagraph('h', 'Kopsurat', 0, 4)]],
                'body' => ['blocks' => [
                    ...$this->fillerParagraphs(26),
                    $this->spacedParagraph('long', 'Awalan<br>'.str_repeat('lorem ipsum dolor sit amet consectetur ', 40).'<br>Akhiran', 0, 2),
                ]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $pdf = (new MpdfEngine)->render((new HtmlRenderer)->render($template, RenderContext::sample()));
        $needle = '('.implode('', array_map(static fn (string $c): string => "\x00".$c, str_split('Kopsurat')));
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $perPage = [];

        foreach ($streams[1] as $raw) {
            $decomp = self::plainText(@gzuncompress($raw));

            if ($decomp !== false && str_contains($decomp, $needle)) {
                $perPage[] = substr_count($decomp, $needle);
            }
        }

        $this->assertSame([1, 1], $perPage);
        // Paragraf panjang pindah utuh ke halaman kedua.
        $this->assertGreaterThan($this->textY($pdf, 'Akhiran'), $this->textY($pdf, 'Awalan'));
    }

    public function test_blocks_stay_whole_even_when_the_header_holds_a_fixed_qr_code(): void
    {
        $document = $this->qrDocument([
            'header' => [$this->paragraph('kop', 'Kop surat'), $this->fixedQr('qh', 12, 15, 195)],
            'body' => [
                ...$this->lines(24),
                $this->spacedParagraph('long', 'Awalan<br>'.str_repeat('lorem ipsum dolor sit amet consectetur ', 40).'<br>Akhiran', 0, 2),
            ],
        ]);
        $engine = new MpdfEngine;
        $pdf = $engine->render($document);

        $this->assertSame(2, $engine->lastPageCount());
        $this->assertGreaterThan($this->textY($pdf, 'Akhiran'), $this->textY($pdf, 'Awalan'));
        $this->assertCount(2, $this->qrPlacements($pdf, $document->pageSetup()->heightMm()));
    }

    public function test_the_mpdf_stylesheet_carries_no_flow_root(): void
    {
        $this->assertStringNotContainsString('flow-root', $this->documentFromFixture('surat-satu-halaman.json')->resolvedCss());
    }

    public function test_tables_follow_the_document_line_height_in_mpdf(): void
    {
        // mpdf memberi <table> line-height bawaan 1.2 dan tidak mewariskan nilai .doc-root,
        // sehingga tiap baris tabel dan tanda tangan lebih rapat daripada di browser dan
        // seluruh isi di bawahnya naik (11,7 mm pada surat satu halaman).
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
                            'columns' => [['label' => '', 'widthPercent' => 100, 'align' => 'left']],
                            'rows' => [['Satu'], ['Dua']],
                            'showHeader' => false,
                            'border' => 'none',
                        ],
                    ],
                    [
                        'id' => 'sig',
                        'type' => 'signature',
                        'props' => ['columns' => [['place' => 'Kota', 'date' => 'hari ini', 'position' => 'Jabatan', 'name' => 'Nama', 'nip' => '1']]],
                    ],
                ]],
                'footer' => ['blocks' => []],
            ],
        ]);

        $pdf = (new MpdfEngine)->render((new HtmlRenderer)->render($template, RenderContext::sample()));

        $y = static function (string $text) use ($pdf): float {
            $needle = preg_quote(implode('', array_map(static fn (string $c): string => "\x00".$c, str_split($text))), '/');
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

            foreach ($streams[1] as $raw) {
                $decomp = self::plainText(@gzuncompress($raw));

                if ($decomp !== false && preg_match('/([0-9.]+)\s+([0-9.]+)\s+Td\s*\('.$needle.'/s', $decomp, $m)) {
                    return (float) $m[2] * 25.4 / 72;
                }
            }

            return 0.0;
        };

        $line = 12 * 1.5 * 25.4 / 72; // 6,35 mm

        // Baris tabel: satu baris teks + padding sel 1 mm atas dan bawah.
        $this->assertGreaterThan(0.0, $y('Satu'));
        $this->assertEqualsWithDelta($line + 2.0, $y('Satu') - $y('Dua'), 0.1);
        // Tanda tangan: sel tanpa padding, jarak tempat/tanggal ke jabatan satu baris teks.
        $this->assertEqualsWithDelta($line, $y('Kota') - $y('Jabatan'), 0.1);
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
                $decomp = self::plainText(@gzuncompress($raw));
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

    // --- QR berposisi tetap -------------------------------------------------------------
    // mpdf hanya menghormati top/left pada `position:absolute` tingkat atas, jadi MpdfEngine
    // menggambar QR tetap di luar pembungkus alur (lihat MpdfEngine::writeBody()/zone()).

    /** Tinggi satu baris teks 12pt dengan line-height 1.5. */
    private const LINE_MM = 12 * 1.5 * 25.4 / 72;

    /** @return array<string, mixed> */
    private function spacedParagraph(string $id, string $text, float $beforeMm, float $afterMm): array
    {
        return ['id' => $id, 'type' => 'paragraph', 'props' => ['text' => $text, 'spaceBeforeMm' => $beforeMm, 'spaceAfterMm' => $afterMm]];
    }

    /** @return list<array<string, mixed>> */
    private function fillerParagraphs(int $count): array
    {
        return array_map(fn (int $i): array => $this->spacedParagraph("b{$i}", "Isi{$i} paragraf", 0, 2), range(1, $count));
    }

    /** @param  list<array<string, mixed>>  $bodyBlocks */
    private function flowDocument(array $bodyBlocks): RenderedDocument
    {
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => ['body' => ['blocks' => $bodyBlocks], 'footer' => ['blocks' => []]],
        ]);

        return (new HtmlRenderer)->render($template, RenderContext::sample());
    }

    /** @param  list<array<string, mixed>>  $footerBlocks */
    private function footerDocument(array $footerBlocks, float|string $footerHeight): RenderedDocument
    {
        $template = SchemaValidator::validate([
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait', 'margin' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'body' => ['blocks' => [$this->spacedParagraph('b1', 'Isi', 0, 0)]],
                'footer' => ['repeat' => 'all', 'height' => $footerHeight, 'blocks' => $footerBlocks],
            ],
        ]);

        return (new HtmlRenderer)->render($template, RenderContext::sample());
    }

    /**
     * Posisi garis dasar teks dari tepi bawah kertas (mm), atau 0.0 bila tidak ditemukan.
     * mpdf menulis kop/kaki di dalam `q … cm … Q` yang menggeser isinya, jadi translasi yang
     * masih terbuka di depan teks ikut dijumlahkan.
     */
    private function textY(string $pdf, string $text): float
    {
        $needle = preg_quote(implode('', array_map(static fn (string $c): string => "\x00".$c, str_split($text))), '/');
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $raw) {
            $decomp = self::plainText(@gzuncompress($raw));

            if ($decomp === false || ! preg_match('/([0-9.]+)\s+([0-9.]+)\s+Td\s*\('.$needle.'/s', $decomp, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $shifts = [0.0];

            foreach (preg_split('/\r?\n/', substr($decomp, 0, $m[0][1])) ?: [] as $line) {
                if ($line === 'q') {
                    $shifts[] = 0.0;
                } elseif (preg_match('/^Q\b/', $line) && count($shifts) > 1) {
                    array_pop($shifts);
                } elseif (preg_match('/^1\.0+ 0\.0+ 0\.0+ 1\.0+ [-0-9.]+ ([-0-9.]+) cm$/', $line, $cm)) {
                    $shifts[count($shifts) - 1] += (float) $cm[1];
                }
            }

            return ((float) $m[2][0] + array_sum($shifts)) * 25.4 / 72;
        }

        return 0.0;
    }

    /** Di berapa halaman teks ini muncul (mpdf menulis satu content stream per halaman). */
    private function textCount(string $pdf, string $text): int
    {
        $needle = implode('', array_map(static fn (string $c): string => "\x00".$c, str_split($text)));
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $count = 0;

        foreach ($streams[1] as $raw) {
            $decomp = self::plainText(@gzuncompress($raw));
            $count += $decomp !== false && str_contains($decomp, '('.$needle) ? 1 : 0;
        }

        return $count;
    }

    /**
     * Dengan kerning aktif mpdf menulis teks sebagai larik `[(po) -20 (tongan)] TJ`. Larik itu
     * digabung kembali menjadi satu `(potongan) Tj` supaya teks bisa dicari utuh.
     */
    private static function plainText(string|false $stream): string|false
    {
        if ($stream === false) {
            return false;
        }

        // `Td 0 Tc 0 Tw [` → `Td [`: pengaturan spasi di antara posisi dan teksnya dibuang.
        $stream = (string) preg_replace('/Td\s+[-0-9.]+ Tc [-0-9.]+ Tw\s*\[/', 'Td [', $stream);

        return (string) preg_replace_callback(
            '/\[((?:\((?:\\\\.|[^\\\\)])*\)|[-0-9.\s])*)\]\s*TJ/s',
            static function (array $m): string {
                preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $m[1], $parts);

                return '('.implode('', $parts[1]).') Tj';
            },
            $stream,
        );
    }

    private function qrGenerator(): QrCodeGenerator
    {
        return new class implements QrCodeGenerator
        {
            public function toSvg(string $payload, float $sizeMm): string
            {
                return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="5" height="5"/></svg>';
            }
        };
    }

    /** @return array<string, mixed> */
    private function paragraph(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'paragraph', 'props' => ['text' => $text]];
    }

    /** @return array<string, mixed> */
    private function fixedQr(string $id, float $sizeMm, float $topMm, float $leftMm): array
    {
        return ['id' => $id, 'type' => 'qrcode', 'props' => ['payload' => 'x', 'sizeMm' => $sizeMm, 'positionMode' => 'fixed', 'topMm' => $topMm, 'leftMm' => $leftMm]];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $zones  blok per zona
     */
    private function qrDocument(array $zones): RenderedDocument
    {
        $schema = Template::blank()->toArray();

        foreach ($zones as $zone => $blocks) {
            $schema['zones'][$zone]['blocks'] = $blocks;
        }

        $schema['zones']['header']['height'] = 30;
        $schema['zones']['footer']['height'] = 20;

        return (new HtmlRenderer)->render(SchemaValidator::validate($schema), RenderContext::sample()->withQr($this->qrGenerator()));
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $count, int $from = 0): array
    {
        $blocks = [];

        for ($i = $from; $i < $from + $count; $i++) {
            $blocks[] = $this->paragraph("line{$i}", "Baris isi nomor {$i}");
        }

        return $blocks;
    }

    /**
     * Posisi tiap gambar yang ditempatkan di PDF, dibaca dari aliran isi halaman:
     * `1 0 0 1 X Y cm /FO1 Do` dengan X dan Y dalam poin dari pojok kiri bawah.
     * Halaman diurutkan menurut aliran isi yang memuat teks.
     *
     * @return list<array{page: int, left: float, top: float}> dalam mm dari pojok kiri atas halaman
     */
    private function qrPlacements(string $pdf, float $pageHeightMm): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        $page = 0;
        $placements = [];

        foreach ($streams[1] as $raw) {
            $content = self::plainText(@gzuncompress($raw));

            if ($content === false || ! str_contains($content, 'BT')) {
                continue;
            }

            $page++;

            if (preg_match_all('/1\.000 0 0 1\.000 ([\d.]+) ([\d.]+) cm\s*\/FO\d+ Do/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $placements[] = [
                        'page' => $page,
                        'left' => round((float) $m[1] / 72 * 25.4, 1),
                        'top' => round($pageHeightMm - (float) $m[2] / 72 * 25.4, 1),
                    ];
                }
            }
        }

        return $placements;
    }

    public function test_a_fixed_qr_in_the_body_is_drawn_at_page_coordinates_on_the_page_of_its_block(): void
    {
        $document = $this->qrDocument(['body' => [
            $this->paragraph('awal', 'Halaman pertama'),
            $this->fixedQr('q1', 25, 100, 150),
            ...$this->lines(120),
            $this->fixedQr('q2', 25, 50, 20),
        ]]);
        $engine = new MpdfEngine;

        $placements = $this->qrPlacements($engine->render($document), $document->pageSetup()->heightMm());

        $this->assertGreaterThan(1, $engine->lastPageCount());
        $this->assertCount(2, $placements);
        $this->assertEquals(['page' => 1, 'left' => 150.0, 'top' => 100.0], $placements[0]);
        $this->assertEquals(['page' => $engine->lastPageCount(), 'left' => 20.0, 'top' => 50.0], $placements[1]);
    }

    public function test_fixed_qr_codes_in_the_header_and_footer_repeat_on_every_page(): void
    {
        $document = $this->qrDocument([
            'header' => [$this->paragraph('kop', 'Kop surat'), $this->fixedQr('qh', 12, 15, 195)],
            'body' => $this->lines(120),
            'footer' => [$this->paragraph('kaki', 'Kaki surat'), $this->fixedQr('qf', 12, 265, 195)],
        ]);
        $engine = new MpdfEngine;

        $placements = $this->qrPlacements($engine->render($document), $document->pageSetup()->heightMm());
        $pages = (int) $engine->lastPageCount();

        $this->assertGreaterThan(1, $pages);
        $this->assertCount($pages * 2, $placements);

        for ($page = 1; $page <= $pages; $page++) {
            $onPage = array_values(array_filter($placements, fn (array $p): bool => $p['page'] === $page));
            $tops = array_column($onPage, 'top');
            sort($tops);

            $this->assertEqualsWithDelta(15.0, $tops[0], 0.3, "kop halaman {$page}");
            $this->assertEqualsWithDelta(265.0, $tops[1], 0.3, "kaki halaman {$page}");
            $this->assertEquals([195.0, 195.0], array_column($onPage, 'left'));
        }
    }

    public function test_header_and_footer_text_survives_next_to_a_fixed_qr_code(): void
    {
        // Blok berposisi tetap mengosongkan buffer kop/kaki mpdf: teks zona yang sama hilang.
        $document = $this->qrDocument([
            'header' => [$this->paragraph('kop', 'Kop surat'), $this->fixedQr('qh', 12, 15, 195)],
            'body' => $this->lines(3),
            'footer' => [$this->paragraph('kaki', 'Kaki surat'), $this->fixedQr('qf', 12, 265, 195)],
        ]);
        $plain = $this->qrDocument([
            'header' => [$this->paragraph('kop', 'Kop surat')],
            'body' => $this->lines(3),
            'footer' => [$this->paragraph('kaki', 'Kaki surat')],
        ]);

        $withQr = (new MpdfEngine)->render($document);
        $without = (new MpdfEngine)->render($plain);

        foreach (['Kop surat', 'Kaki surat'] as $text) {
            $this->assertGreaterThan(0.0, $this->textY($without, $text));
            $this->assertEqualsWithDelta($this->textY($without, $text), $this->textY($withQr, $text), 0.3, $text);
        }
    }

    public function test_a_fixed_qr_does_not_change_how_the_body_flows(): void
    {
        $withQr = $this->qrDocument(['body' => [...$this->lines(60), $this->fixedQr('q', 25, 100, 150), ...$this->lines(60, 60)]]);
        $without = $this->qrDocument(['body' => $this->lines(120)]);
        $a = new MpdfEngine;
        $b = new MpdfEngine;

        $a->render($withQr);
        $b->render($without);

        $this->assertSame($b->lastPageCount(), $a->lastPageCount());
    }

    public function test_a_document_without_a_fixed_qr_still_renders_in_one_pass(): void
    {
        $document = $this->qrDocument(['body' => $this->lines(10)]);

        $pdf = (new MpdfEngine)->render($document);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame([], $this->qrPlacements($pdf, $document->pageSetup()->heightMm()));
    }
}
