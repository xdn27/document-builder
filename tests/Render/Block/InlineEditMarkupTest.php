<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\BlockType;
use PHPUnit\Framework\TestCase;

/**
 * Penanda data-edit-* untuk sunting inline di kanvas builder. Konteks bawaan
 * (cetak/PDF) tidak boleh memuatnya sama sekali.
 */
class InlineEditMarkupTest extends TestCase
{
    use RendersBlocks;

    private function editable(): RenderContext
    {
        return RenderContext::sample()->withEditable();
    }

    public function test_default_context_emits_no_edit_markers(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, ['text' => 'Dengan hormat.']);

        $this->assertStringNotContainsString('data-edit-', $html);
    }

    public function test_paragraph_marks_its_text_as_rich(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, ['text' => 'Dengan hormat.'], $this->editable());

        $this->assertStringContainsString('data-edit-prop="text" data-edit-rich="1">Dengan hormat.', $html);
    }

    public function test_regions_containing_variables_are_not_editable(): void
    {
        // Kanvas menampilkan nilai contoh; commit inline akan menimpa tokennya.
        $html = $this->renderBlock(BlockType::Paragraph, ['text' => 'Nama {{ student.name }}'], $this->editable());

        $this->assertStringNotContainsString('data-edit-', $html);
    }

    public function test_table_cells_carry_row_and_column_addresses(): void
    {
        $html = $this->renderBlock(BlockType::Table, [
            'columns' => [['label' => 'No', 'widthPercent' => 20, 'align' => 'left'], ['label' => 'Nama', 'widthPercent' => 80, 'align' => 'left']],
            'rows' => [['1', 'Ahmad']],
        ], $this->editable());

        $this->assertStringContainsString('data-edit-prop="columns" data-edit-row="1" data-edit-key="label" data-edit-rich="1">Nama', $html);
        $this->assertStringContainsString('data-edit-prop="rows" data-edit-row="0" data-edit-col="1" data-edit-rich="1">Ahmad', $html);
    }

    public function test_list_items_carry_their_index(): void
    {
        $html = $this->renderBlock(BlockType::ListBlock, ['items' => [['text' => 'Satu', 'level' => 0], ['text' => 'Dua', 'level' => 0]]], $this->editable());

        $this->assertStringContainsString('data-edit-prop="items" data-edit-row="1" data-edit-key="text" data-edit-rich="1">Dua', $html);
    }

    public function test_signature_prefix_stays_outside_the_editable_region(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['position' => 'Kepala', 'name' => 'Ahmad', 'nip' => '123']],
        ], $this->editable());

        // "NIP. " di dalam region akan ikut terbaca saat commit dan menjadi ganda.
        $this->assertStringContainsString('NIP. <span data-edit-prop="columns" data-edit-row="0" data-edit-key="nip" data-edit-rich="1">123</span>', $html);
        $this->assertStringContainsString('data-edit-prop="columns" data-edit-row="0" data-edit-key="name" data-edit-rich="1">Ahmad', $html);
    }

    public function test_signature_output_is_unchanged_when_not_editable(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['position' => 'Kepala', 'name' => 'Ahmad', 'nip' => '123']],
        ]);

        $this->assertStringContainsString('<span class="db-signature__nip">NIP. 123</span>', $html);
    }
}
