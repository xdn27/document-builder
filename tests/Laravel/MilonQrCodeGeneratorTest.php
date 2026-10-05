<?php

namespace Maqiis\DocumentBuilder\Tests\Laravel;

use Maqiis\DocumentBuilder\Laravel\MilonQrCodeGenerator;
use Maqiis\DocumentBuilder\Qr\QrSvg;
use Milon\Barcode\DNS2D;
use PHPUnit\Framework\TestCase;

class MilonQrCodeGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(DNS2D::class)) {
            $this->markTestSkipped('milon/barcode tidak terpasang.');
        }
    }

    public function test_the_qr_is_one_compact_path_without_an_xml_prolog(): void
    {
        $svg = (new MilonQrCodeGenerator)->toSvg('https://sekolah.id/verif/abc123', 30);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
        $this->assertSame(1, substr_count($svg, '<path'));
    }

    public function test_the_compact_svg_normalizes_to_a_scalable_square(): void
    {
        $svg = QrSvg::normalize((new MilonQrCodeGenerator)->toSvg('https://sekolah.id/verif/abc123', 30), 40);

        $this->assertMatchesRegularExpression('/viewBox="0 0 (\d+) \1"/', $svg);
        $this->assertStringContainsString('width="40mm"', $svg);
        $this->assertStringContainsString('height="40mm"', $svg);
    }

    public function test_an_empty_result_is_returned_when_the_payload_cannot_be_encoded(): void
    {
        $this->assertSame('', (new MilonQrCodeGenerator)->toSvg(str_repeat('x', 100000), 30));
    }
}
