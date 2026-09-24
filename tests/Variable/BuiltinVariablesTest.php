<?php

namespace Maqiis\DocumentBuilder\Tests\Variable;

use DateTimeImmutable;
use DateTimeZone;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use Maqiis\DocumentBuilder\Variable\BuiltinVariables;
use Maqiis\DocumentBuilder\Variable\CollectionVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use Maqiis\DocumentBuilder\Variable\VariableSyntax;
use PHPUnit\Framework\TestCase;

final class BuiltinVariablesTest extends TestCase
{
    private function builtins(string $date = '2026-09-24 09:30:00'): BuiltinVariables
    {
        return new BuiltinVariables(new DateTimeImmutable($date, new DateTimeZone('Asia/Jakarta')));
    }

    public function test_date_values_use_indonesian_names(): void
    {
        $this->assertSame([
            'today.long' => '24 September 2026',
            'today.short' => '24/09/2026',
            'today.full' => 'Kamis, 24 September 2026',
            'today.day' => 'Kamis',
            'today.date' => '24',
            'today.month' => 'September',
            'today.month_roman' => 'IX',
            'today.year' => '2026',
        ], $this->builtins()->values());
    }

    public function test_single_digit_days_are_not_padded_in_long_form(): void
    {
        $values = $this->builtins('2026-03-01 00:00:00')->values();

        $this->assertSame('1 Maret 2026', $values['today.long']);
        $this->assertSame('01/03/2026', $values['today.short']);
        $this->assertSame('Minggu, 1 Maret 2026', $values['today.full']);
        $this->assertSame('III', $values['today.month_roman']);
    }

    public function test_every_month_has_a_roman_numeral(): void
    {
        $romans = [];

        foreach (range(1, 12) as $month) {
            $romans[] = $this->builtins(sprintf('2026-%02d-15', $month))->values()['today.month_roman'];
        }

        $this->assertSame(['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'], $romans);
    }

    public function test_the_date_follows_the_given_timezone_not_utc(): void
    {
        // 23 September 20:00 UTC sudah 24 September di Jakarta.
        $now = (new DateTimeImmutable('2026-09-23 20:00:00', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Jakarta'));

        $this->assertSame('24 September 2026', (new BuiltinVariables($now))->values()['today.long']);
    }

    public function test_register_adds_dates_and_page_numbers_to_the_panel(): void
    {
        $registry = $this->builtins()->register(new VariableRegistry);

        $groups = $registry->groups();

        $this->assertSame(BuiltinVariables::paths(), array_column($registry->all(), 'path'));
        $this->assertSame(['today.long', 'today.short', 'today.full', 'today.day', 'today.date', 'today.month', 'today.month_roman', 'today.year'], array_column($groups[BuiltinVariables::DATE_GROUP], 'path'));
        $this->assertSame(['page', 'pages'], array_column($groups[BuiltinVariables::PAGE_GROUP], 'path'));
        $this->assertSame('24 September 2026', $registry->sampleResolver()->resolve('today.long'));
    }

    public function test_register_never_overrides_an_application_definition(): void
    {
        $registry = (new VariableRegistry)
            ->define('institution.name', 'Nama Lembaga', 'Pesantren', 'Lembaga')
            ->define('today.long', 'Tanggal versi aplikasi', 'Kamis Legi', 'Aplikasi');

        $this->builtins()->register($registry);

        $mine = array_values(array_filter($registry->all(), fn (array $entry): bool => $entry['path'] === 'today.long'));

        $this->assertSame([['path' => 'today.long', 'label' => 'Tanggal versi aplikasi', 'sample' => 'Kamis Legi', 'group' => 'Aplikasi']], $mine);
        $this->assertSame('institution.name', $registry->all()[0]['path'], 'variabel aplikasi harus tetap di urutan pertama');
        $this->assertTrue($registry->has('today.year'));
    }

    public function test_resolver_prefers_the_application_and_fills_only_the_gaps(): void
    {
        $resolver = $this->builtins()->resolver(new ArrayVariableResolver([
            'today' => ['long' => 'tanggal dari aplikasi'],
            'letter' => ['number' => '001/IX/2026'],
        ]));

        $this->assertSame('tanggal dari aplikasi', $resolver->resolve('today.long'));
        $this->assertSame('2026', $resolver->resolve('today.year'));
        $this->assertSame('001/IX/2026', $resolver->resolve('letter.number'));
        $this->assertNull($resolver->resolve('letter.subject'));
    }

    public function test_resolver_keeps_collections_of_the_wrapped_resolver(): void
    {
        $resolver = $this->builtins()->resolver(new ArrayVariableResolver([
            'recipients' => [['name' => 'Bapak A'], ['name' => 'Ibu B']],
        ]));

        $this->assertInstanceOf(CollectionVariableResolver::class, $resolver);
        $this->assertCount(2, $resolver->collection('recipients'));
        $this->assertNull($resolver->collection('missing'));
    }

    public function test_tokens_render_through_variable_syntax(): void
    {
        $syntax = new VariableSyntax($this->builtins()->resolver(new ArrayVariableResolver([])));

        $this->assertSame(
            'Nomor 001/SK/IX/2026, 24 September 2026',
            $syntax->apply('Nomor 001/SK/{{ today.month_roman }}/{{ today.year }}, {{ today.long }}'),
        );
    }
}
