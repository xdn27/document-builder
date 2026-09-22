<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use PHPUnit\Framework\TestCase;

class LetterBlockRendererTest extends TestCase
{
    use RendersBlocks;

    public function test_letterhead_renders_four_text_lines(): void
    {
        $html = $this->renderBlock(BlockType::Letterhead, [
            'showLogo' => false,
            'line1' => 'YAYASAN MAQIIS',
            'line2' => 'Pondok Pesantren Nurul Ilmi',
            'line3' => 'Jl. Merdeka No. 10, Bandung',
            'line4' => 'Telp. 022-000000',
        ]);

        $this->assertStringContainsString('YAYASAN MAQIIS', $html);
        $this->assertStringContainsString('Pondok Pesantren Nurul Ilmi', $html);
        $this->assertStringContainsString('Jl. Merdeka No. 10, Bandung', $html);
        $this->assertStringContainsString('Telp. 022-000000', $html);
    }

    public function test_letterhead_omits_empty_lines(): void
    {
        $html = $this->renderBlock(BlockType::Letterhead, ['showLogo' => false, 'line1' => 'A']);

        // Dihitung lewat kelas bernomor: kelas dasar muncul dua kali per baris.
        $this->assertSame(1, substr_count($html, 'db-letterhead__line--'));
    }

    public function test_letterhead_renders_a_double_rule_by_default(): void
    {
        $html = $this->renderBlock(BlockType::Letterhead, ['showLogo' => false, 'line1' => 'A']);

        $this->assertStringContainsString('db-letterhead__rule--double', $html);
    }

    public function test_letterhead_omits_the_rule_when_set_to_none(): void
    {
        $html = $this->renderBlock(BlockType::Letterhead, ['showLogo' => false, 'line1' => 'A', 'rule' => 'none']);

        $this->assertStringNotContainsString('db-letterhead__rule', $html);
    }

    public function test_letterhead_renders_an_allowed_logo(): void
    {
        $html = $this->renderBlock(BlockType::Letterhead, [
            'showLogo' => true,
            'logo' => 'https://cdn.sekolah.id/logo.png',
            'logoHeightMm' => 25,
        ], RenderContext::sample()->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/'])));

        $this->assertStringContainsString('src="https://cdn.sekolah.id/logo.png"', $html);
        $this->assertStringContainsString('height:25mm', $html);
    }

    public function test_letterhead_replaces_a_rejected_logo_with_a_marker(): void
    {
        $html = $this->renderBlock(BlockType::Letterhead, [
            'showLogo' => true,
            'logo' => 'http://169.254.169.254/latest/meta-data/',
        ], RenderContext::sample()->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/'])));

        $this->assertStringNotContainsString('169.254.169.254', $html);
        $this->assertStringContainsString('db-marker', $html);
        $this->assertStringContainsString('ditolak', $html);
    }

    public function test_letter_meta_renders_label_value_rows(): void
    {
        $html = $this->renderBlock(BlockType::LetterMeta, [
            'rows' => [
                ['label' => 'Nomor', 'value' => '001/SK/IX/2026'],
                ['label' => 'Hal', 'value' => 'Undangan'],
            ],
            'labelWidthMm' => 30,
        ]);

        $this->assertStringContainsString('Nomor', $html);
        $this->assertStringContainsString('001/SK/IX/2026', $html);
        $this->assertStringContainsString('Undangan', $html);
        $this->assertStringContainsString('width:30mm', $html);
        $this->assertSame(2, substr_count($html, '<tr>'));
    }

    public function test_letter_meta_uses_the_configured_separator(): void
    {
        $html = $this->renderBlock(BlockType::LetterMeta, [
            'rows' => [['label' => 'Nomor', 'value' => '1']],
            'separator' => '=',
        ]);

        $this->assertStringContainsString('>=<', $html);
    }

    public function test_letter_meta_substitutes_variables_in_values(): void
    {
        $context = RenderContext::sample()->withResolver(
            new ArrayVariableResolver(['letter' => ['number' => 'A-9']]),
        );

        $html = $this->renderBlock(BlockType::LetterMeta, [
            'rows' => [['label' => 'Nomor', 'value' => '{{ letter.number }}']],
        ], $context);

        $this->assertStringContainsString('A-9', $html);
    }

    public function test_letter_meta_renders_nothing_visible_when_there_are_no_rows(): void
    {
        $html = $this->renderBlock(BlockType::LetterMeta, ['rows' => []]);

        $this->assertStringNotContainsString('<tr>', $html);
    }

    public function test_letter_meta_right_text_spans_all_rows_via_rowspan_not_a_nested_table(): void
    {
        $html = $this->renderBlock(BlockType::LetterMeta, [
            'rows' => [
                ['label' => 'Nomor', 'value' => '001/SK/IX/2026'],
                ['label' => 'Hal', 'value' => 'Undangan'],
            ],
            'rightText' => 'Bandung, 14 September 2026',
            'rightAlign' => 'right',
        ]);

        $this->assertSame(2, substr_count($html, '<tr>'), 'Kolom kanan seharusnya tidak menambah baris');
        $this->assertSame(1, substr_count($html, '<table'), 'Kolom kanan seharusnya tidak jadi tabel bersarang');
        $this->assertSame(1, substr_count($html, 'db-letter-meta__right'));
        $this->assertMatchesRegularExpression('#rowspan="2"#', $html);
        $this->assertStringContainsString('text-align:right', $html);
        $this->assertStringContainsString('Bandung, 14 September 2026', $html);
        $this->assertStringContainsString('db-letter-meta__table--with-right', $html);
    }

    public function test_letter_meta_right_text_alone_still_renders_without_rows(): void
    {
        $html = $this->renderBlock(BlockType::LetterMeta, [
            'rows' => [],
            'rightText' => '14 September 2026',
        ]);

        $this->assertSame(1, substr_count($html, '<tr>'));
        $this->assertStringContainsString('14 September 2026', $html);
        $this->assertStringNotContainsString('db-letter-meta__label', $html);
    }

    public function test_letter_meta_without_right_text_keeps_the_plain_table_class(): void
    {
        $html = $this->renderBlock(BlockType::LetterMeta, [
            'rows' => [['label' => 'Nomor', 'value' => '1']],
        ]);

        $this->assertStringNotContainsString('db-letter-meta__table--with-right', $html);
        $this->assertStringNotContainsString('db-letter-meta__right', $html);
    }
}
