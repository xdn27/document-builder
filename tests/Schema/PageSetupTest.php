<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\Margin;
use Maqiis\DocumentBuilder\Schema\Orientation;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Maqiis\DocumentBuilder\Schema\PageSize;
use PHPUnit\Framework\TestCase;

class PageSetupTest extends TestCase
{
    public function test_a4_portrait_has_standard_dimensions(): void
    {
        $setup = new PageSetup(PageSize::A4, Orientation::Portrait, Margin::fromArray([]));

        $this->assertEqualsWithDelta(210.0, $setup->widthMm(), 0.001);
        $this->assertEqualsWithDelta(297.0, $setup->heightMm(), 0.001);
    }

    public function test_landscape_swaps_width_and_height(): void
    {
        $setup = new PageSetup(PageSize::A4, Orientation::Landscape, Margin::fromArray([]));

        $this->assertEqualsWithDelta(297.0, $setup->widthMm(), 0.001);
        $this->assertEqualsWithDelta(210.0, $setup->heightMm(), 0.001);
    }

    public function test_content_box_subtracts_margins(): void
    {
        $setup = new PageSetup(
            PageSize::A4,
            Orientation::Portrait,
            Margin::fromArray(['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 25]),
        );

        $this->assertEqualsWithDelta(165.0, $setup->contentWidthMm(), 0.001);
        $this->assertEqualsWithDelta(257.0, $setup->contentHeightMm(), 0.001);
    }

    public function test_margins_are_clamped_to_fifty_millimetres(): void
    {
        $margin = Margin::fromArray(['top' => 999, 'right' => -5, 'bottom' => 20, 'left' => 25]);

        $this->assertEqualsWithDelta(50.0, $margin->top, 0.001);
        $this->assertEqualsWithDelta(0.0, $margin->right, 0.001);
    }

    public function test_missing_margin_sides_fall_back_to_twenty(): void
    {
        $margin = Margin::fromArray([]);

        $this->assertEqualsWithDelta(20.0, $margin->top, 0.001);
        $this->assertEqualsWithDelta(20.0, $margin->left, 0.001);
    }

    public function test_f4_uses_indonesian_folio_dimensions(): void
    {
        $this->assertEqualsWithDelta(215.0, PageSize::F4->widthMm(), 0.001);
        $this->assertEqualsWithDelta(330.0, PageSize::F4->heightMm(), 0.001);
    }

    public function test_round_trips_through_array(): void
    {
        $raw = [
            'size' => 'F4',
            'orientation' => 'landscape',
            'margin' => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 30],
        ];

        $this->assertSame($raw, PageSetup::fromArray($raw)->toArray());
    }
}
