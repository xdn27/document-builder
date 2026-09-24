<?php

namespace Maqiis\DocumentBuilder\Tests\Variable;

use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableSyntax;
use PHPUnit\Framework\TestCase;

class VariableSyntaxTest extends TestCase
{
    private function syntax(array $data = []): VariableSyntax
    {
        return new VariableSyntax(new ArrayVariableResolver($data));
    }

    public function test_substitutes_a_known_variable(): void
    {
        $syntax = $this->syntax(['student' => ['name' => 'Fatimah']]);

        $this->assertSame('Nama: Fatimah', $syntax->apply('Nama: {{ student.name }}'));
    }

    public function test_tolerates_missing_whitespace_inside_braces(): void
    {
        $syntax = $this->syntax(['a' => 'b']);

        $this->assertSame('b', $syntax->apply('{{a}}'));
    }

    public function test_escapes_the_resolved_value(): void
    {
        $syntax = $this->syntax(['x' => '<script>alert(1)</script>']);

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $syntax->apply('{{ x }}'));
    }

    public function test_marks_unknown_variables_without_throwing(): void
    {
        $this->assertSame('⟦student.nickname?⟧', $this->syntax()->apply('{{ student.nickname }}'));
    }

    public function test_leaves_surrounding_inline_markup_intact(): void
    {
        $syntax = $this->syntax(['n' => 'Ahmad']);

        $this->assertSame('<b>Ahmad</b>', $syntax->apply('<b>{{ n }}</b>'));
    }

    public function test_page_becomes_a_marker_element(): void
    {
        $this->assertSame(
            'Halaman <span class="db-var-page"></span> dari <span class="db-var-pages"></span>',
            $this->syntax()->apply('Halaman {{ page }} dari {{ pages }}'),
        );
    }

    public function test_collects_the_paths_used_in_a_text(): void
    {
        $this->assertSame(
            ['student.name', 'letter.number'],
            VariableSyntax::paths('{{ student.name }} — {{ letter.number }} — {{ student.name }}'),
        );
    }

    public function test_reserved_paths_are_excluded_from_collected_paths(): void
    {
        $this->assertSame([], VariableSyntax::paths('{{ page }} / {{ pages }}'));
    }

    public function test_array_resolver_returns_null_for_missing_path(): void
    {
        $this->assertNull((new ArrayVariableResolver(['a' => ['b' => 'c']]))->resolve('a.z'));
    }

    public function test_array_resolver_returns_null_when_value_is_not_scalar(): void
    {
        $this->assertNull((new ArrayVariableResolver(['a' => ['b' => 'c']]))->resolve('a'));
    }

    public function test_apply_raw_substitutes_values_without_escaping(): void
    {
        $syntax = $this->syntax(['school' => ['letterhead' => 'https://cdn.sekolah.id/kop.png?v=1&size=a4']]);

        $this->assertSame(
            'https://cdn.sekolah.id/kop.png?v=1&size=a4',
            $syntax->applyRaw('{{ school.letterhead }}'),
        );
    }

    public function test_apply_raw_returns_text_without_tokens_unchanged(): void
    {
        $this->assertSame('https://cdn.sekolah.id/kop.png', $this->syntax()->applyRaw('https://cdn.sekolah.id/kop.png'));
    }

    public function test_apply_raw_is_null_when_a_variable_is_unknown(): void
    {
        $syntax = $this->syntax(['cdn' => 'https://cdn.sekolah.id']);

        $this->assertNull($syntax->applyRaw('{{ cdn }}/{{ school.letterhead }}'));
    }

    public function test_apply_raw_is_null_for_reserved_page_variables(): void
    {
        $this->assertNull($this->syntax()->applyRaw('kop-{{ page }}.png'));
    }
}
