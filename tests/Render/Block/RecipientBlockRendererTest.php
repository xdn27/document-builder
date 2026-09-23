<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use PHPUnit\Framework\TestCase;

class RecipientBlockRendererTest extends TestCase
{
    use RendersBlocks;

    private const TWO_RECIPIENTS = [
        'recipient' => ['name' => 'Bapak Ahmad', 'position' => 'Kepala Sekolah', 'location' => 'Surabaya'],
        'recipients' => [
            ['name' => 'Bapak Ahmad', 'position' => 'Kepala Sekolah', 'location' => 'Surabaya'],
            ['name' => 'Ibu Siti', 'position' => 'Bendahara', 'location' => 'Sidoarjo'],
        ],
    ];

    private function context(array $data): RenderContext
    {
        return RenderContext::sample()->withResolver(new ArrayVariableResolver($data));
    }

    public function test_renders_one_entry_per_recipient_in_the_collection(): void
    {
        $html = $this->renderBlock(BlockType::Recipient, [
            'itemText' => '{{ recipient.position }}<br>{{ recipient.name }}',
        ], $this->context(self::TWO_RECIPIENTS));

        $this->assertStringContainsString('class="doc-block db-recipient"', $html);
        $this->assertSame(2, substr_count($html, 'db-recipient__item'));
        $this->assertStringContainsString('Kepala Sekolah<br>Bapak Ahmad', $html);
        $this->assertStringContainsString('Bendahara<br>Ibu Siti', $html);
    }

    public function test_numbers_entries_automatically_only_when_there_is_more_than_one(): void
    {
        $many = $this->renderBlock(BlockType::Recipient, ['itemText' => '{{ recipient.name }}'], $this->context(self::TWO_RECIPIENTS));

        $this->assertStringContainsString('>1.</td>', $many);
        $this->assertStringContainsString('>2.</td>', $many);

        $single = self::TWO_RECIPIENTS;
        $single['recipients'] = [$single['recipients'][0]];
        $one = $this->renderBlock(BlockType::Recipient, ['itemText' => '{{ recipient.name }}'], $this->context($single));

        $this->assertStringNotContainsString('db-recipient__marker', $one);
    }

    public function test_numbering_can_be_forced_or_disabled(): void
    {
        $single = self::TWO_RECIPIENTS;
        $single['recipients'] = [$single['recipients'][0]];

        $always = $this->renderBlock(BlockType::Recipient, ['itemText' => '{{ recipient.name }}', 'numbering' => 'always'], $this->context($single));
        $never = $this->renderBlock(BlockType::Recipient, ['itemText' => '{{ recipient.name }}', 'numbering' => 'never'], $this->context(self::TWO_RECIPIENTS));

        $this->assertStringContainsString('>1.</td>', $always);
        $this->assertStringNotContainsString('db-recipient__marker', $never);
    }

    public function test_drops_lines_that_resolve_to_nothing(): void
    {
        $data = self::TWO_RECIPIENTS;
        $data['recipients'][0]['position'] = '';

        $html = $this->renderBlock(BlockType::Recipient, [
            'itemText' => '{{ recipient.position }}<br>{{ recipient.name }}<br>{{ recipient.location }}',
        ], $this->context($data));

        // Baris jabatan penerima pertama kosong: tidak boleh menyisakan <br> menggantung.
        $this->assertMatchesRegularExpression('/db-recipient__text"[^>]*>Bapak Ahmad<br>Surabaya</', $html);
    }

    public function test_renders_heading_and_closing_once_around_the_entries(): void
    {
        $html = $this->renderBlock(BlockType::Recipient, [
            'heading' => 'Kepada Yth.',
            'itemText' => '{{ recipient.name }}',
            'closing' => 'di Tempat',
        ], $this->context(self::TWO_RECIPIENTS));

        $this->assertSame(1, substr_count($html, 'Kepada Yth.'));
        $this->assertSame(1, substr_count($html, 'di Tempat'));
        $this->assertLessThan(strpos($html, 'Bapak Ahmad'), strpos($html, 'Kepada Yth.'));
        $this->assertGreaterThan(strpos($html, 'Ibu Siti'), strpos($html, 'di Tempat'));
    }

    public function test_variables_outside_the_recipient_alias_still_resolve_from_the_document(): void
    {
        $data = self::TWO_RECIPIENTS + ['letter' => ['subject' => 'Undangan']];

        $html = $this->renderBlock(BlockType::Recipient, [
            'itemText' => '{{ recipient.name }} ({{ letter.subject }})',
        ], $this->context($data));

        $this->assertStringContainsString('Ibu Siti (Undangan)', $html);
    }

    public function test_falls_back_to_the_single_recipient_when_no_collection_is_provided(): void
    {
        $html = $this->renderBlock(BlockType::Recipient, [
            'itemText' => '{{ recipient.name }}',
        ], $this->context(['recipient' => ['name' => 'Bapak Ahmad']]));

        $this->assertSame(1, substr_count($html, 'db-recipient__item'));
        $this->assertStringContainsString('Bapak Ahmad', $html);
    }

    public function test_an_empty_collection_renders_nothing_at_all(): void
    {
        $html = $this->renderBlock(BlockType::Recipient, [
            'heading' => 'Kepada Yth.',
            'itemText' => '{{ recipient.name }}',
        ], $this->context(['recipients' => []]));

        $this->assertStringNotContainsString('Kepada Yth.', $html);
        $this->assertStringNotContainsString('db-recipient__item', $html);
    }

    public function test_recipient_values_are_escaped(): void
    {
        $html = $this->renderBlock(BlockType::Recipient, [
            'itemText' => '{{ recipient.name }}',
        ], $this->context(['recipients' => [['name' => '<script>x</script>']]]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_builder_preview_repeats_the_registry_sample_collection(): void
    {
        $registry = (new VariableRegistry)
            ->define('recipient.name', 'Nama penerima', 'Contoh', 'Penerima')
            ->defineCollection('recipients', [['name' => 'Contoh Satu'], ['name' => 'Contoh Dua']]);

        $html = $this->renderBlock(
            BlockType::Recipient,
            ['itemText' => '{{ recipient.name }}'],
            RenderContext::sample()->withResolver($registry->sampleResolver()),
        );

        $this->assertStringContainsString('Contoh Satu', $html);
        $this->assertStringContainsString('Contoh Dua', $html);
    }
}
