<?php

namespace Maqiis\DocumentBuilder\Tests\Asset;

use Maqiis\DocumentBuilder\Asset\AssetLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AssetLoaderTest extends TestCase
{
    public function test_loads_the_document_stylesheet(): void
    {
        $css = AssetLoader::css();

        $this->assertStringContainsString('.doc-page', $css);
        $this->assertStringContainsString('.doc-flow', $css);
        $this->assertStringContainsString('.db-marker', $css);
    }

    public function test_stylesheet_contains_no_pixel_units(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\d\s*px/', AssetLoader::css());
    }

    public function test_stylesheet_never_targets_the_html_element(): void
    {
        $this->assertDoesNotMatchRegularExpression('/(^|[};,\s])html\s*[{,]/', AssetLoader::css());
    }

    public function test_stylesheet_styles_every_block_class(): void
    {
        $css = AssetLoader::css();

        foreach ([
            'db-letterhead', 'db-letter-meta', 'db-paragraph', 'db-signature',
            'db-table', 'db-list', 'db-image', 'db-qrcode', 'db-spacer', 'db-divider',
        ] as $class) {
            $this->assertStringContainsString($class, $css, "Kelas {$class} belum punya gaya.");
        }
    }

    public function test_throws_for_a_missing_asset(): void
    {
        $this->expectException(RuntimeException::class);

        AssetLoader::path('css/tidak-ada.css');
    }

    public function test_bundled_javascript_has_no_module_syntax(): void
    {
        // Bundel disisipkan sebagai skrip inline; baris import akan gagal di sana.
        $js = AssetLoader::bundledJs();

        $this->assertDoesNotMatchRegularExpression('/^\s*import\s/m', $js);
        $this->assertDoesNotMatchRegularExpression('/^export\s/m', $js);
    }

    public function test_paginator_javascript_has_no_module_syntax(): void
    {
        $js = AssetLoader::paginatorJs();

        $this->assertDoesNotMatchRegularExpression('/^\s*import\s/m', $js);
        $this->assertDoesNotMatchRegularExpression('/^export\s/m', $js);
    }
}
