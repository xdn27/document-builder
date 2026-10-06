<?php

namespace Maqiis\DocumentBuilder\Tests\Qr;

use Maqiis\DocumentBuilder\Qr\QrSvg;
use PHPUnit\Framework\TestCase;

class QrSvgTest extends TestCase
{
    private const MILON_STYLE = '<?xml version="1.0" standalone="no"?>'."\n"
        .'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'."\n"
        .'<svg width="50" height="50" version="1.1" xmlns="http://www.w3.org/2000/svg" shape-rendering="crispEdges">'
        .'<g fill="black"><rect x="0" y="0" width="2" height="2" /></g></svg>';

    public function test_xml_prolog_and_doctype_are_removed(): void
    {
        $svg = QrSvg::normalize(self::MILON_STYLE, 30);

        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertStringNotContainsString('<!DOCTYPE', $svg);
        $this->assertStringStartsWith('<svg', $svg);
    }

    public function test_a_viewbox_is_derived_from_width_and_height_so_the_content_scales(): void
    {
        $svg = QrSvg::normalize(self::MILON_STYLE, 30);

        $this->assertStringContainsString('viewBox="0 0 50 50"', $svg);
        $this->assertStringContainsString('width="30mm"', $svg);
        $this->assertStringContainsString('height="30mm"', $svg);

        // Hanya elemen akar yang dapat ukuran mm; atribut width/height lama tidak tersisa di sana.
        preg_match('/<svg\b[^>]*>/', $svg, $root);
        $this->assertSame(1, substr_count($root[0], ' width="'));
        $this->assertSame(1, substr_count($root[0], ' height="'));
        $this->assertStringNotContainsString('width="50"', $root[0]);
    }

    public function test_an_existing_viewbox_is_kept(): void
    {
        $svg = QrSvg::normalize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 21 21" width="999" height="999"><path d="M0 0h1v1z"/></svg>', 25.5);

        $this->assertStringContainsString('viewBox="0 0 21 21"', $svg);
        $this->assertStringContainsString('width="25.5mm"', $svg);
        $this->assertStringNotContainsString('999', $svg);
    }

    public function test_the_svg_namespace_is_added_when_missing(): void
    {
        $svg = QrSvg::normalize('<svg width="10" height="10"><rect width="1" height="1"/></svg>', 20);

        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
    }

    public function test_an_svg_without_dimensions_is_still_usable_without_a_viewbox(): void
    {
        $svg = QrSvg::normalize('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>', 20);

        $this->assertStringNotContainsString('viewBox', $svg);
        $this->assertStringContainsString('width="20mm"', $svg);
    }

    public function test_something_that_is_not_an_svg_yields_an_empty_result(): void
    {
        $this->assertSame('', QrSvg::normalize('', 30));
        $this->assertSame('', QrSvg::normalize('<div>bukan svg</div>', 30));
        $this->assertSame('', QrSvg::toDataUri('<div>bukan svg</div>', 30));
    }

    public function test_the_data_uri_decodes_back_to_the_normalized_svg(): void
    {
        $uri = QrSvg::toDataUri(self::MILON_STYLE, 30);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertSame(QrSvg::normalize(self::MILON_STYLE, 30), base64_decode(substr($uri, strlen('data:image/svg+xml;base64,'))));
    }

    public function test_the_inline_form_carries_the_marker_class_and_replaces_existing_class_and_style(): void
    {
        $svg = QrSvg::inline('<svg class="lama" style="color:red" width="10" height="10"><rect width="1" height="1"/></svg>', 30);

        preg_match('/<svg\b[^>]*>/', $svg, $root);
        $this->assertSame(1, substr_count($root[0], 'class='));
        $this->assertSame(1, substr_count($root[0], 'style='));
        $this->assertStringContainsString('class="db-qrcode__svg"', $root[0]);
        $this->assertStringContainsString('display:inline-block', $root[0]);
        $this->assertStringNotContainsString('lama', $svg);
        $this->assertStringNotContainsString('color:red', $svg);
        $this->assertSame('', QrSvg::inline('<div>bukan svg</div>', 30));
    }

    public function test_the_inline_form_is_not_clipped_to_whole_pixels(): void
    {
        // Chrome memotong isi <svg> pada kotak yang dibulatkan ke piksel CSS bulat: QR 25 mm
        // (94,49 px) tercetak 24,85 mm. overflow:visible meniadakan pemotongan itu.
        $svg = QrSvg::inline('<svg width="10" height="10"><rect width="1" height="1"/></svg>', 25);

        preg_match('/<svg\b[^>]*>/', $svg, $root);
        $this->assertStringContainsString('overflow:visible', $root[0]);
    }
}
