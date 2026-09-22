<?php

namespace Maqiis\DocumentBuilder\Tests\Render;

use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Render\PageLayoutCss;
use Maqiis\DocumentBuilder\Schema\DocumentStyle;
use Maqiis\DocumentBuilder\Schema\Margin;
use Maqiis\DocumentBuilder\Schema\Orientation;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Maqiis\DocumentBuilder\Schema\PageSize;
use PHPUnit\Framework\TestCase;

class PageLayoutCssTest extends TestCase
{
    private function css(): string
    {
        return PageLayoutCss::render(
            new PageSetup(PageSize::A4, Orientation::Portrait, Margin::fromArray([
                'top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 25,
            ])),
            DocumentStyle::fromArray([]),
        );
    }

    public function test_declares_the_page_box(): void
    {
        $this->assertStringContainsString('@page{size:210mm 297mm;margin:0}', $this->css());
    }

    public function test_exposes_the_content_box_as_custom_properties(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('--db-page-w:210mm', $css);
        $this->assertStringContainsString('--db-page-h:297mm', $css);
        $this->assertStringContainsString('--db-content-w:165mm', $css);
        $this->assertStringContainsString('--db-content-h:257mm', $css);
        $this->assertStringContainsString('--db-ml:25mm', $css);
    }

    public function test_font_size_is_expressed_in_points(): void
    {
        $this->assertStringContainsString('--db-font-size:12pt', $this->css());
    }

    public function test_never_emits_pixel_units(): void
    {
        $this->assertStringNotContainsString('px', $this->css());
    }

    public function test_never_targets_the_html_element(): void
    {
        // dompdf dan mpdf menerapkan gaya elemen html ke frame halaman; aturan seperti
        // "html{margin:0}" menghapus margin @page secara diam-diam.
        $this->assertDoesNotMatchRegularExpression('/(^|[};,\s])html\s*[{,]/', $this->css());
    }

    public function test_landscape_swaps_the_page_box(): void
    {
        $css = PageLayoutCss::render(
            new PageSetup(PageSize::A4, Orientation::Landscape, Margin::fromArray([])),
            DocumentStyle::fromArray([]),
        );

        $this->assertStringContainsString('@page{size:297mm 210mm;margin:0}', $css);
    }

    public function test_uses_the_font_stack_from_the_registry(): void
    {
        $this->assertStringContainsString(FontRegistry::cssStack('tinos'), $this->css());
    }

    public function test_unknown_font_key_falls_back_to_the_default_stack(): void
    {
        $this->assertSame(FontRegistry::cssStack('tinos'), FontRegistry::cssStack('font-yang-tidak-ada'));
    }

    public function test_maps_font_keys_to_mpdf_core_families(): void
    {
        $this->assertSame('times', FontRegistry::mpdfFamily('tinos'));
        $this->assertSame('helvetica', FontRegistry::mpdfFamily('arimo'));
        $this->assertSame('courier', FontRegistry::mpdfFamily('cousine'));
    }

    public function test_almarai_is_supported_by_mpdf_via_custom_font_registration(): void
    {
        // mpdf tidak memproses @font-face di CSS sama sekali, jadi Almarai
        // tidak bisa lewat fontFaceCss()/resolvedCss() seperti font sistem —
        // ia didaftarkan manual lewat FontRegistry::mpdfFontFiles(), yang
        // dipakai MpdfEngine untuk mengisi opsi konstruktor 'fontDir'/'fontdata'.
        $this->assertTrue(FontRegistry::supportsMpdf('almarai'));
        $this->assertSame('almarai', FontRegistry::mpdfFamily('almarai'));

        $files = FontRegistry::mpdfFontFiles('almarai');

        $this->assertNotNull($files);
        $this->assertStringEndsWith('fonts/almarai', $files['dir']);
        $this->assertSame('Almarai-Regular.ttf', $files['files']['R']);
        $this->assertSame('Almarai-Bold.ttf', $files['files']['B']);
        // Tanpa useOTL, mpdf memetakan tiap huruf Arab ke bentuk lepasnya
        // (isolated form) alih-alih bentuk sambung — lihat FontRegistry.
        $this->assertSame(0xFF, $files['files']['useOTL']);
    }

    public function test_mpdf_font_files_is_null_for_fonts_that_need_no_custom_registration(): void
    {
        $this->assertNull(FontRegistry::mpdfFontFiles('tinos'));
    }

    public function test_almarai_embeds_its_font_face_block_only_when_selected(): void
    {
        $css = PageLayoutCss::render(
            new PageSetup(PageSize::A4, Orientation::Portrait, Margin::fromArray([])),
            DocumentStyle::fromArray(['fontFamily' => 'almarai']),
        );

        $this->assertStringContainsString("@font-face{font-family:'Almarai';font-weight:normal", $css);
        $this->assertStringContainsString("@font-face{font-family:'Almarai';font-weight:bold", $css);
        $this->assertStringContainsString('data:font/ttf;base64,', $css);
    }

    public function test_other_fonts_do_not_embed_any_font_face_block(): void
    {
        $this->assertStringNotContainsString('@font-face', $this->css());
    }
}
