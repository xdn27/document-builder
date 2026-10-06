<?php

namespace Maqiis\DocumentBuilder\Tests\Views;

use PHPUnit\Framework\TestCase;

/**
 * Nilai prop berasal dari schema, yang bisa diisi lewat mode "kode HTML" atau impor JSON.
 * Mencetaknya mentah ke editor teks kaya menjalankan skrip tersimpan di browser penyunting
 * berikutnya (XSS tersimpan). Hanya keluaran HtmlSanitizer yang boleh dicetak tanpa escape.
 */
final class InspectorEscapingTest extends TestCase
{
    public function test_the_inspector_never_echoes_a_raw_prop_value(): void
    {
        $view = (string) file_get_contents(__DIR__.'/../../resources/views/partials/inspector.blade.php');

        preg_match_all('/\{!!(.*?)!!\}/s', $view, $raw);

        foreach ($raw[1] as $expression) {
            if (str_contains($expression, '$block') || str_contains($expression, 'props')) {
                $this->assertStringContainsString('HtmlSanitizer', $expression, 'Nilai prop dicetak mentah: '.trim($expression));
            }
        }

        $this->assertStringContainsString('HtmlSanitizer', $view);
    }

    public function test_schema_values_never_enter_a_wire_click_expression_unchecked(): void
    {
        // wire:click dievaluasi sebagai JavaScript; nilai dari schema atau dari properti publik
        // yang bisa ditulis klien harus dibatasi ke pola aman sebelum disisipkan.
        $view = (string) file_get_contents(__DIR__.'/../../resources/views/template-builder.blade.php');

        $this->assertStringNotContainsString("selectBlock('{{ \$block['id'] }}')", $view);
        $this->assertStringNotContainsString("'{{ \$selectedZone }}')", $view);
        $this->assertStringContainsString('BLOCK_ID_PATTERN', $view);
    }
}
