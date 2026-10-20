<?php

namespace Maqiis\DocumentBuilder\Tests\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Isian panel builder sengaja memakai `wire:model` deferred (tanpa `.live`):
 * mengetik tidak boleh mengirim request. Perubahan yang tertumpuk dikirim
 * initDeferredSync() di builder.mjs lewat refreshPreview() setelah pengguna
 * berhenti mengetik. Satu binding `.live` saja sudah cukup untuk membuat
 * builder kembali mengirim request per ketikan. Input berkas (`imageUpload.*`,
 * `importFile`) punya jalur unggah sendiri dan tidak terkena aturan ini.
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
    public function test_schema_bindings_are_deferred_so_typing_sends_no_request(string $modifiers, string $target): void
    {
        $this->assertSame(
            '',
            $modifiers,
            "wire:model{$modifiers} untuk {$target} mengirim request sendiri; pakai wire:model polos supaya ikut refreshPreview().",
        );
    }

    public function test_the_views_contain_schema_bindings(): void
    {
        $this->assertNotEmpty(iterator_to_array(self::schemaBindings()));
    }

    public function test_the_builder_sends_deferred_changes_through_refresh_preview(): void
    {
        $view = (string) file_get_contents(self::VIEWS.'/template-builder.blade.php');

        $this->assertStringContainsString('initDeferredSync({ onFlush: refreshPreview })', $view);
        $this->assertStringContainsString('component.refreshPreview()', $view);
    }

    public function test_the_loading_overlay_is_limited_to_heavy_actions_and_delayed(): void
    {
        $view = (string) file_get_contents(self::VIEWS.'/template-builder.blade.php');

        preg_match_all('/<[^>]*\swire:loading[\s.>][^>]*>/', $view, $tags);

        $this->assertNotEmpty($tags[0]);

        foreach ($tags[0] as $tag) {
            $this->assertMatchesRegularExpression('/wire:target="[^"]+"/', $tag, "wire:loading tanpa wire:target muncul di setiap request: {$tag}");
            $this->assertStringNotContainsString('refreshPreview', $tag, 'pembaruan pratinjau tidak boleh memunculkan overlay');
        }

        $this->assertMatchesRegularExpression('/wire:loading\.delay\.long\s+wire:target="save,/', $view);
    }
}
