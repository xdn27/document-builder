<?php

namespace Maqiis\DocumentBuilder\Tests\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sejak Livewire 3, `wire:model` tanpa `.live` bersifat deferred: nilai hanya
 * disimpan di klien sampai ada aksi lain, sehingga updated() — yang memicu
 * pratinjau kanvas — tidak pernah terpanggil saat input inspector diubah.
 * `.live` diabaikan Livewire 2 (yang live secara bawaan), jadi satu sintaks
 * berlaku di 2, 3, dan 4. Input berkas (`imageUpload.*`, `importFile`) punya
 * jalur unggah sendiri dan tidak terkena aturan ini.
 */
final class WireModelTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../resources/views';

    /** @return iterable<string,array{string,string}> */
    public static function schemaBindings(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            foreach (file($file->getPathname()) as $index => $line) {
                preg_match_all('/wire:model([.\w]*)="([^"]*)"/', $line, $matches, PREG_SET_ORDER);

                foreach ($matches as [, $modifiers, $target]) {
                    if (str_starts_with($target, 'imageUpload.') || $target === 'importFile') {
                        continue;
                    }

                    yield sprintf('%s:%d %s', $file->getFilename(), $index + 1, $target) => [$modifiers, $target];
                }
            }
        }
    }

    #[DataProvider('schemaBindings')]
    public function test_schema_bindings_reach_the_server_on_livewire_3_and_4(string $modifiers, string $target): void
    {
        $this->assertMatchesRegularExpression(
            '/^\.(live|lazy)\b/',
            $modifiers,
            "wire:model untuk {$target} tidak memicu request di Livewire 3/4 tanpa .live (atau .lazy) sebagai modifier pertama.",
        );
    }

    public function test_the_views_contain_schema_bindings(): void
    {
        $this->assertNotEmpty(iterator_to_array(self::schemaBindings()));
    }
}
