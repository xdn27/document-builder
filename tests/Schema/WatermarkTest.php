<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Schema\Watermark;
use PHPUnit\Framework\TestCase;

class WatermarkTest extends TestCase
{
    public function test_missing_watermark_means_none(): void
    {
        $watermark = Watermark::fromArray([]);

        $this->assertTrue($watermark->isEmpty());
        $this->assertSame(Watermark::DEFAULT_OPACITY, $watermark->opacity);
    }

    public function test_text_is_trimmed_and_whitespace_collapsed(): void
    {
        $this->assertSame('SALINAN RESMI', Watermark::fromArray(['text' => "  SALINAN \n\t RESMI "])->text);
    }

    public function test_whitespace_only_text_means_none(): void
    {
        $this->assertTrue(Watermark::fromArray(['text' => "  \n "])->isEmpty());
    }

    public function test_text_is_cut_to_the_maximum_length_without_breaking_multibyte_characters(): void
    {
        $text = Watermark::fromArray(['text' => str_repeat('É', Watermark::MAX_LENGTH + 10)])->text;

        $this->assertSame(Watermark::MAX_LENGTH, mb_strlen($text));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function test_non_string_text_means_none(): void
    {
        $this->assertTrue(Watermark::fromArray(['text' => ['DRAF']])->isEmpty());
    }

    public function test_opacity_is_clamped(): void
    {
        $this->assertSame(Watermark::MIN_OPACITY, Watermark::fromArray(['text' => 'DRAF', 'opacity' => 0])->opacity);
        $this->assertSame(Watermark::MAX_OPACITY, Watermark::fromArray(['text' => 'DRAF', 'opacity' => 1])->opacity);
        $this->assertSame(Watermark::DEFAULT_OPACITY, Watermark::fromArray(['text' => 'DRAF', 'opacity' => 'x'])->opacity);
        $this->assertSame(0.3, Watermark::fromArray(['text' => 'DRAF', 'opacity' => '0.3'])->opacity);
    }

    public function test_with_text_replaces_the_text_and_keeps_the_opacity(): void
    {
        $watermark = Watermark::fromArray(['text' => 'DRAF', 'opacity' => 0.3])->withText('  RAHASIA ');

        $this->assertSame('RAHASIA', $watermark->text);
        $this->assertSame(0.3, $watermark->opacity);
        $this->assertTrue($watermark->withText('')->isEmpty());
    }

    public function test_validator_reads_the_watermark_and_round_trips_it(): void
    {
        $raw = Template::blank()->toArray();
        $raw['watermark'] = ['text' => 'DRAF', 'opacity' => 0.2];

        $template = SchemaValidator::validate($raw);

        $this->assertSame('DRAF', $template->watermark->text);
        $this->assertSame(['text' => 'DRAF', 'opacity' => 0.2], $template->toArray()['watermark']);
        $this->assertSame($template->toArray(), SchemaValidator::validate($template->toArray())->toArray());
    }

    public function test_schema_without_watermark_stays_valid(): void
    {
        $raw = Template::blank()->toArray();
        unset($raw['watermark']);

        $this->assertTrue(SchemaValidator::validate($raw)->watermark->isEmpty());
    }

    public function test_template_with_watermark_overrides_only_the_text(): void
    {
        $raw = Template::blank()->toArray();
        $raw['watermark'] = ['text' => 'DRAF', 'opacity' => 0.2];
        $template = SchemaValidator::validate($raw);

        $copy = $template->withWatermark('RAHASIA');

        $this->assertSame('RAHASIA', $copy->watermark->text);
        $this->assertSame(0.2, $copy->watermark->opacity);
        $this->assertSame('DRAF', $template->watermark->text, 'template asal tidak berubah');
        $this->assertSame($template->body, $copy->body);
    }
}
