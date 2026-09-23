<?php

namespace Maqiis\DocumentBuilder\Tests;

use FilesystemIterator;
use ParseError;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Contoh yang tidak berjalan lebih buruk daripada tidak ada contoh: integrator
 * menyalinnya apa adanya. Test ini menjaga contoh PHP tetap ter-parse dan setiap
 * `use Maqiis\DocumentBuilder\…` menunjuk tipe yang benar-benar ada, sehingga
 * rename di package tidak diam-diam mematikan contoh.
 */
class ExamplesTest extends TestCase
{
    private const EXAMPLES = __DIR__.'/../examples';

    /** @return list<string> */
    private function files(string $suffix): array
    {
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::EXAMPLES, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getPathname(), $suffix)) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /** @return list<string> berkas .php yang bukan Blade */
    private function phpFiles(): array
    {
        return array_values(array_filter($this->files('.php'), fn (string $f): bool => ! str_ends_with($f, '.blade.php')));
    }

    public function test_both_paths_have_examples(): void
    {
        $this->assertDirectoryExists(self::EXAMPLES.'/livewire');
        $this->assertDirectoryExists(self::EXAMPLES.'/inertia-react');
        $this->assertNotEmpty($this->phpFiles());
    }

    public function test_every_php_example_parses(): void
    {
        foreach ($this->phpFiles() as $file) {
            try {
                token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            } catch (ParseError $e) {
                $this->fail("{$file}: {$e->getMessage()}");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_every_package_import_in_the_examples_exists(): void
    {
        $missing = [];

        foreach ($this->phpFiles() as $file) {
            preg_match_all('/^use (Maqiis\\\\DocumentBuilder\\\\[\w\\\\]+);/m', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $type) {
                if (! class_exists($type) && ! interface_exists($type) && ! trait_exists($type) && ! enum_exists($type)) {
                    $missing[] = basename($file).': '.$type;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_the_livewire_example_mounts_the_alias_the_provider_registers(): void
    {
        $provider = (string) file_get_contents(__DIR__.'/../src/Laravel/DocumentBuilderServiceProvider.php');
        preg_match("/Livewire::component\\('([^']+)'/", $provider, $alias);

        $view = (string) file_get_contents(self::EXAMPLES.'/livewire/resources/views/letter-templates/builder.blade.php');

        $this->assertStringContainsString("@livewire('{$alias[1]}'", $view);
    }

    public function test_the_livewire_example_layout_has_the_script_stack_after_livewire_scripts(): void
    {
        // Review Focus #2: view builder mendorong skripnya ke stack 'script'.
        // Tanpa @stack('script') kanvas mati tanpa error — contoh resmi harus benar.
        // Komentar Blade dibuang dulu: direktif yang dikomentari tidak dieksekusi,
        // dan komentar penjelas di contoh memang menyebut kedua direktif.
        $view = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(self::EXAMPLES.'/livewire/resources/views/letter-templates/builder.blade.php'));

        $scripts = strpos($view, '@livewireScripts');
        $stack = strpos($view, "@stack('script')");

        $this->assertNotFalse($scripts);
        $this->assertNotFalse($stack);
        $this->assertGreaterThan($scripts, $stack, "@stack('script') harus sesudah @livewireScripts");
    }
}
