<?php

namespace Maqiis\DocumentBuilder\Tests\Support;

use Maqiis\DocumentBuilder\Support\Mm;
use PHPUnit\Framework\TestCase;

class MmTest extends TestCase
{
    public function test_converts_millimetres_to_points(): void
    {
        // 25,4 mm = 1 inci = 72 pt
        $this->assertEqualsWithDelta(72.0, Mm::toPt(25.4), 0.0001);
    }

    public function test_converts_points_to_millimetres(): void
    {
        $this->assertEqualsWithDelta(25.4, Mm::fromPt(72.0), 0.0001);
    }

    public function test_formats_whole_numbers_without_decimals(): void
    {
        $this->assertSame('20mm', Mm::css(20.0));
    }

    public function test_formats_fractions_with_at_most_three_decimals(): void
    {
        $this->assertSame('215.9mm', Mm::css(215.9));
        $this->assertSame('12.346mm', Mm::css(12.34567));
    }

    public function test_never_emits_scientific_notation(): void
    {
        $this->assertStringNotContainsString('E', Mm::css(0.0000001));
    }
}
