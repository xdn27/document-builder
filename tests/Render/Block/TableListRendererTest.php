<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Schema\BlockType;
use PHPUnit\Framework\TestCase;

class TableListRendererTest extends TestCase
{
    use RendersBlocks;

    private function table(array $override = []): string
    {
        return $this->renderBlock(BlockType::Table, array_replace([
            'columns' => [
                ['label' => 'No', 'widthPercent' => 10, 'align' => 'center'],
                ['label' => 'Nama', 'widthPercent' => 60, 'align' => 'left'],
                ['label' => 'Kelas', 'widthPercent' => 30, 'align' => 'center'],
            ],
            'rows' => [
                ['1', 'Fatimah', 'VII A'],
                ['2', 'Ahmad', 'VII B'],
            ],
        ], $override));
    }

    public function test_table_renders_header_and_body(): void
    {
        $html = $this->table();

        $this->assertStringContainsString('<thead', $html);
        $this->assertStringContainsString('Nama', $html);
        $this->assertSame(2, substr_count($html, '<tr class="db-table__row">'));
        $this->assertStringContainsString('Fatimah', $html);
    }

    public function test_table_emits_column_widths_as_percentages(): void
    {
        $this->assertStringContainsString('width:60%', $this->table());
    }

    public function test_table_marks_the_header_as_repeatable_by_default(): void
    {
        $this->assertStringContainsString('data-repeat-header="1"', $this->table());
    }

    public function test_table_can_disable_header_repetition(): void
    {
        $this->assertStringContainsString('data-repeat-header="0"', $this->table(['repeatHeader' => false]));
    }

    public function test_table_pads_rows_that_have_too_few_cells(): void
    {
        $html = $this->table(['rows' => [['1']]]);

        $this->assertSame(3, substr_count($html, '<td'));
    }

    public function test_table_truncates_rows_that_have_too_many_cells(): void
    {
        $html = $this->table(['rows' => [['1', '2', '3', 'kelebihan']]]);

        $this->assertStringNotContainsString('kelebihan', $html);
    }

    public function test_table_applies_a_font_size_override_in_points(): void
    {
        $this->assertStringContainsString('font-size:10pt', $this->table(['fontSizePt' => 10]));
    }

    public function test_table_inherits_the_document_font_size_when_override_is_zero(): void
    {
        $this->assertStringNotContainsString('font-size:', $this->table(['fontSizePt' => 0]));
    }

    public function test_table_border_variant_is_exposed_as_a_class(): void
    {
        $this->assertStringContainsString('db-table__table--horizontal', $this->table(['border' => 'horizontal']));
    }

    public function test_table_escapes_cell_content(): void
    {
        $html = $this->table(['rows' => [['<script>alert(1)</script>', 'a', 'b']]]);

        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_numbered_list_uses_indonesian_multi_level_markers(): void
    {
        $html = $this->renderBlock(BlockType::ListBlock, [
            'style' => 'number',
            'items' => [
                ['text' => 'Menimbang', 'level' => 0],
                ['text' => 'Bahwa', 'level' => 1],
                ['text' => 'Bahwa lagi', 'level' => 1],
                ['text' => 'Mengingat', 'level' => 0],
            ],
        ]);

        $this->assertStringContainsString('>1.<', $html);
        $this->assertStringContainsString('>a.<', $html);
        $this->assertStringContainsString('>b.<', $html);
        $this->assertStringContainsString('>2.<', $html);
    }

    public function test_deeper_counters_reset_when_returning_to_a_shallower_level(): void
    {
        $html = $this->renderBlock(BlockType::ListBlock, [
            'style' => 'number',
            'items' => [
                ['text' => 'satu', 'level' => 0],
                ['text' => 'anak', 'level' => 1],
                ['text' => 'dua', 'level' => 0],
                ['text' => 'anak lagi', 'level' => 1],
            ],
        ]);

        // Butir "anak lagi" harus kembali menjadi a., bukan lanjut ke b.
        $this->assertSame(2, substr_count($html, '>a.<'));
    }

    public function test_bullet_list_uses_level_specific_markers(): void
    {
        $html = $this->renderBlock(BlockType::ListBlock, [
            'style' => 'bullet',
            'items' => [
                ['text' => 'satu', 'level' => 0],
                ['text' => 'dua', 'level' => 1],
            ],
        ]);

        $this->assertStringContainsString('•', $html);
        $this->assertStringContainsString('◦', $html);
    }

    public function test_list_indents_by_level(): void
    {
        $html = $this->renderBlock(BlockType::ListBlock, [
            'items' => [['text' => 'x', 'level' => 2]],
            'indentMm' => 10,
        ]);

        $this->assertStringContainsString('padding-left:30mm', $html);
    }

    public function test_list_clamps_level_to_the_supported_depth(): void
    {
        $html = $this->renderBlock(BlockType::ListBlock, [
            'items' => [['text' => 'x', 'level' => 99]],
            'indentMm' => 10,
        ]);

        $this->assertStringContainsString('padding-left:30mm', $html);
    }

    public function test_each_list_item_is_an_independent_fragment(): void
    {
        $html = $this->renderBlock(BlockType::ListBlock, [
            'items' => [['text' => 'a', 'level' => 0], ['text' => 'b', 'level' => 0]],
        ]);

        $this->assertSame(2, substr_count($html, 'db-list__item'));
    }
}
