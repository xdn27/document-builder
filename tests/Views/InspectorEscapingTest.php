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
}
