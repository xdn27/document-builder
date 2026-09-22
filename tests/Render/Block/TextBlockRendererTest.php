<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Render\Block\BlockRendererRegistry;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use PHPUnit\Framework\TestCase;

class TextBlockRendererTest extends TestCase
{
    use RendersBlocks;

    public function test_paragraph_wraps_content_in_a_block_envelope(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, ['text' => 'Dengan hormat.']);

        $this->assertStringContainsString('class="doc-block db-paragraph"', $html);
        $this->assertStringContainsString('data-block-id="blk"', $html);
        $this->assertStringContainsString('Dengan hormat.', $html);
    }

    public function test_paragraph_keeps_allowed_inline_markup(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, ['text' => 'Kepada <b>Bapak</b>']);

        $this->assertStringContainsString('Kepada <b>Bapak</b>', $html);
    }

    public function test_paragraph_drops_disallowed_markup(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, ['text' => '<img src=x onerror=alert(1)>halo']);

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('halo', $html);
    }

    public function test_paragraph_substitutes_variables(): void
    {
        $context = RenderContext::sample()->withResolver(
            new ArrayVariableResolver(['student' => ['name' => 'Fatimah']]),
        );

        $html = $this->renderBlock(BlockType::Paragraph, ['text' => 'Nama {{ student.name }}'], $context);

        $this->assertStringContainsString('Nama Fatimah', $html);
    }

    public function test_paragraph_emits_alignment_and_spacing_in_millimetres(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, [
            'text' => 'x',
            'align' => 'center',
            'indentMm' => 10,
            'spaceBeforeMm' => 4,
            'spaceAfterMm' => 6,
        ]);

        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('text-indent:10mm', $html);
        $this->assertStringContainsString('margin-top:4mm', $html);
        $this->assertStringContainsString('margin-bottom:6mm', $html);
        $this->assertStringNotContainsString('px', $html);
    }

    public function test_paragraph_direction_defaults_to_ltr(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, ['text' => 'Dengan hormat.']);

        $this->assertStringContainsString('dir="ltr"', $html);
    }

    public function test_paragraph_direction_can_be_set_to_rtl_for_arabic_text(): void
    {
        $html = $this->renderBlock(BlockType::Paragraph, [
            'text' => 'بسم الله الرحمن الرحيم',
            'direction' => 'rtl',
        ]);

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('بسم الله الرحمن الرحيم', $html);
    }

    public function test_paragraph_is_splittable_across_pages(): void
    {
        $this->assertStringContainsString(
            'data-break-inside="auto"',
            $this->renderBlock(BlockType::Paragraph, ['text' => 'x']),
        );
    }

    public function test_spacer_renders_a_fixed_height_box(): void
    {
        $html = $this->renderBlock(BlockType::Spacer, ['heightMm' => 15]);

        $this->assertStringContainsString('db-spacer', $html);
        $this->assertStringContainsString('height:15mm', $html);
        $this->assertStringContainsString('data-break-inside="avoid"', $html);
    }

    public function test_divider_renders_a_rule_with_style_and_width(): void
    {
        $html = $this->renderBlock(BlockType::Divider, [
            'thicknessMm' => 0.5,
            'style' => 'dashed',
            'widthPercent' => 60,
            'align' => 'center',
        ]);

        $this->assertStringContainsString('db-divider', $html);
        $this->assertStringContainsString('border-top:0.5mm dashed', $html);
        $this->assertStringContainsString('width:60%', $html);
        $this->assertStringContainsString('margin-left:auto', $html);
        $this->assertStringContainsString('margin-right:auto', $html);
    }

    public function test_registry_renders_a_marker_for_an_unregistered_type(): void
    {
        $registry = new BlockRendererRegistry;

        $html = $registry->render(new Block('blk', BlockType::Paragraph, []), RenderContext::sample());

        $this->assertStringContainsString('db-marker', $html);
        $this->assertStringContainsString('paragraph', $html);
    }

    public function test_marker_escapes_its_message(): void
    {
        $this->assertStringNotContainsString(
            '<script>',
            RenderContext::sample()->marker('<script>alert(1)</script>'),
        );
    }
}
