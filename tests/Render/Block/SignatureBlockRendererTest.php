<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

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
}
