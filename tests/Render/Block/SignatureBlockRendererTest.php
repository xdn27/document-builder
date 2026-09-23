<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Render\Block\SignatureRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use PHPUnit\Framework\TestCase;

class SignatureBlockRendererTest extends TestCase
{
    use RendersBlocks;

    private function oneColumn(array $overrides = []): array
    {
        return array_replace([
            'columns' => [[
                'place' => 'Jakarta', 'date' => '21 Januari 2026', 'position' => 'Kepala Sekolah',
                'signature' => '', 'name' => 'Nama Panjang', 'nip' => '123456',
            ]],
        ], $overrides);
    }

    public function test_text_align_follows_align_by_default_for_full_backward_compatibility(): void
    {
        $html = $this->renderBlock(BlockType::Signature, $this->oneColumn(['align' => 'right']));

        $this->assertStringContainsString('text-align:right', $html);
        $this->assertStringNotContainsString('text-align:center', $html);
    }

    public function test_full_width_table_has_no_explicit_width_or_margin_by_default(): void
    {
        // widthPercent bawaan 100 — perilaku lama persis, tanpa style width/margin
        // tambahan pada elemen <table>.
        $html = $this->renderBlock(BlockType::Signature, $this->oneColumn(['align' => 'right']));

        $this->assertMatchesRegularExpression('/<table class="db-signature__table" style="margin-top:[^"]*">/', $html);
    }

    public function test_text_align_can_be_set_independently_from_block_align(): void
    {
        $html = $this->renderBlock(BlockType::Signature, $this->oneColumn([
            'align' => 'right',
            'textAlign' => 'center',
        ]));

        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringNotContainsString('text-align:right', $html);
    }

    public function test_a_narrower_block_aligned_right_gets_pushed_right_via_margin(): void
    {
        $html = $this->renderBlock(BlockType::Signature, $this->oneColumn([
            'align' => 'right',
            'widthPercent' => 50.0,
        ]));

        $this->assertStringContainsString('width:50%', $html);
        $this->assertStringContainsString('margin-left:auto', $html);
        $this->assertStringNotContainsString('margin-right:auto', $html);
    }

    public function test_a_narrower_block_aligned_left_gets_pushed_left_via_margin(): void
    {
        $html = $this->renderBlock(BlockType::Signature, $this->oneColumn([
            'align' => 'left',
            'widthPercent' => 50.0,
        ]));

        $this->assertStringContainsString('margin-right:auto', $html);
        $this->assertStringNotContainsString('margin-left:auto', $html);
    }

    public function test_a_narrower_block_centered_gets_margin_auto_on_both_sides(): void
    {
        $html = $this->renderBlock(BlockType::Signature, $this->oneColumn([
            'align' => 'center',
            'widthPercent' => 50.0,
        ]));

        $this->assertStringContainsString('margin-left:auto;margin-right:auto', $html);
    }

    public function test_space_between_always_stays_full_width_even_with_a_narrower_width_percent(): void
    {
        // space-between menyebar kolom, jadi tidak masuk akal disusutkan — lihat
        // SignatureRenderer::tableStyle().
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [
                ['place' => '', 'date' => '', 'position' => 'A', 'signature' => '', 'name' => 'Satu', 'nip' => ''],
                ['place' => '', 'date' => '', 'position' => 'B', 'signature' => '', 'name' => 'Dua', 'nip' => ''],
            ],
            'align' => 'space-between',
            'widthPercent' => 50.0,
        ]);

        $this->assertMatchesRegularExpression('/<table class="db-signature__table" style="margin-top:[^"]*">/', $html);
        $this->assertStringNotContainsString('margin-left:auto', $html);
    }

    public function test_a_block_full_width_regardless_of_align_when_width_percent_is_100(): void
    {
        $html = $this->renderBlock(BlockType::Signature, $this->oneColumn([
            'align' => 'right',
            'widthPercent' => 100.0,
        ]));

        $this->assertStringNotContainsString('margin-left:auto', $html);
    }

    public function test_signature_image_uses_default_scale_and_zero_offsets(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [[
                'place' => '', 'date' => '', 'position' => 'Kepala',
                'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '',
            ]],
            'spaceMm' => 25.0,
        ]);

        $this->assertStringContainsString('style="height:25mm"', $html);
        $this->assertStringNotContainsString('margin-bottom:', $html);
        $this->assertStringNotContainsString('margin-left:', $html);
        $this->assertStringNotContainsString('max-width:none', $html);
    }

    public function test_signature_image_scale_adjusts_height_and_centers_extra_height_via_negative_margins(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [[
                'place' => '', 'date' => '', 'position' => 'Kepala',
                'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '',
            ]],
            'spaceMm' => 25.0,
            'imageScalePercent' => 120.0,
        ]);

        // 25mm * 1.2 = 30mm, extra = 5mm, -5/2 = -2.5mm on top and bottom
        $this->assertStringContainsString('height:30mm', $html);
        $this->assertStringContainsString('margin-top:-2.5mm', $html);
        $this->assertStringContainsString('margin-bottom:-2.5mm', $html);
        $this->assertStringContainsString('position:relative', $html);
        $this->assertStringContainsString('max-width:none', $html);
    }

    public function test_signature_image_vertical_offset_shifts_margins(): void
    {
        // Shift downwards towards name below (imageOffsetYMm = 4.0)
        $htmlDown = $this->renderBlock(BlockType::Signature, [
            'columns' => [[
                'place' => '', 'date' => '', 'position' => 'Kepala',
                'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '',
            ]],
            'spaceMm' => 25.0,
            'imageOffsetYMm' => 4.0,
        ]);

        $this->assertStringContainsString('height:25mm', $htmlDown);
        $this->assertStringContainsString('margin-top:4mm', $htmlDown);
        $this->assertStringContainsString('margin-bottom:-4mm', $htmlDown);

        // Shift upwards towards position above (imageOffsetYMm = -4.0)
        $htmlUp = $this->renderBlock(BlockType::Signature, [
            'columns' => [[
                'place' => '', 'date' => '', 'position' => 'Kepala',
                'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '',
            ]],
            'spaceMm' => 25.0,
            'imageOffsetYMm' => -4.0,
        ]);

        $this->assertStringContainsString('height:25mm', $htmlUp);
        $this->assertStringContainsString('margin-top:-4mm', $htmlUp);
        $this->assertStringContainsString('margin-bottom:4mm', $htmlUp);
    }

    public function test_signature_image_horizontal_offset_shifts_margin_left_and_right(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [[
                'place' => '', 'date' => '', 'position' => 'Kepala',
                'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '',
            ]],
            'spaceMm' => 25.0,
            'imageOffsetXMm' => 6.0,
        ]);

        $this->assertStringContainsString('height:25mm', $html);
        $this->assertStringContainsString('margin-left:6mm', $html);
        $this->assertStringContainsString('margin-right:-6mm', $html);
    }

    public function test_signature_image_column_override_takes_precedence_over_block_prop(): void
    {
        $renderer = new SignatureRenderer;
        $context = RenderContext::sample();

        $block = new Block('s1', BlockType::Signature, [
            'columns' => [[
                'place' => '', 'date' => '', 'position' => 'Kepala',
                'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '',
                'imageScalePercent' => 140.0,
                'imageOffsetYMm' => -5.0,
                'imageOffsetXMm' => 2.0,
            ]],
            'spaceMm' => 25.0,
            'imageScalePercent' => 100.0,
            'imageOffsetYMm' => 0.0,
            'imageOffsetXMm' => 0.0,
        ]);

        $html = $renderer->render($block, $context);

        // Column override: 25 * 1.4 = 35mm, extra = 10mm, base top margin = -5mm.
        // With offsetY = -5mm -> marginTop = -5 + (-5) = -10mm, marginBottom = -5 - (-5) = 0mm.
        $this->assertStringContainsString('height:35mm', $html);
        $this->assertStringContainsString('margin-top:-10mm', $html);
        $this->assertStringContainsString('margin-left:2mm', $html);
    }

    public function test_signature_image_supports_aliases(): void
    {
        $renderer = new SignatureRenderer;
        $context = RenderContext::sample();

        $block = new Block('s1', BlockType::Signature, [
            'columns' => [[
                'place' => '', 'date' => '', 'position' => 'Kepala',
                'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '',
            ]],
            'spaceMm' => 25.0,
            'signatureScale' => 120.0,
            'signatureOffsetYMm' => 2.0,
            'signatureOffsetXMm' => 3.0,
        ]);

        $html = $renderer->render($block, $context);

        $this->assertStringContainsString('height:30mm', $html);
        $this->assertStringContainsString('margin-left:3mm', $html);
    }
}
