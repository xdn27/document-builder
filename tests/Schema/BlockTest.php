<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\DocumentStyle;
use PHPUnit\Framework\TestCase;

class BlockTest extends TestCase
{
    public function test_block_exposes_props_with_default(): void
    {
        $block = new Block('abc123', BlockType::Paragraph, ['align' => 'justify']);

        $this->assertSame('justify', $block->prop('align'));
        $this->assertSame('kiri', $block->prop('missing', 'kiri'));
    }

    public function test_list_block_type_is_spelled_list(): void
    {
        $this->assertSame('list', BlockType::ListBlock->value);
        $this->assertSame('letter-meta', BlockType::LetterMeta->value);
        $this->assertSame('qrcode', BlockType::QrCode->value);
    }

    public function test_there_are_exactly_eleven_block_types(): void
    {
        $this->assertCount(11, BlockType::cases());
    }

    public function test_document_style_clamps_font_size(): void
    {
        $this->assertEqualsWithDelta(24.0, DocumentStyle::fromArray(['fontSize' => 99])->fontSize, 0.001);
        $this->assertEqualsWithDelta(8.0, DocumentStyle::fromArray(['fontSize' => 1])->fontSize, 0.001);
    }

    public function test_document_style_clamps_line_height(): void
    {
        $this->assertEqualsWithDelta(3.0, DocumentStyle::fromArray(['lineHeight' => 9])->lineHeight, 0.001);
        $this->assertEqualsWithDelta(1.0, DocumentStyle::fromArray(['lineHeight' => 0.1])->lineHeight, 0.001);
    }

    public function test_document_style_defaults_to_tinos_twelve_point(): void
    {
        $style = DocumentStyle::fromArray([]);

        $this->assertSame('tinos', $style->fontFamily);
        $this->assertEqualsWithDelta(12.0, $style->fontSize, 0.001);
        $this->assertEqualsWithDelta(1.5, $style->lineHeight, 0.001);
    }
}
