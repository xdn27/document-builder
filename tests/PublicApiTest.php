<?php

namespace Maqiis\DocumentBuilder\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Begitu tiga repo memasang package ini, "apa yang boleh dipegang konsumen"
 * harus dinyatakan — kalau tidak, seseorang akan memakai kelas internal dan
 * rilis minor berikutnya memecahkan repo yang tidak ikut rapat (spec §17).
 *
 * Daftar di bawah dijaga semver. Setiap kelas lain wajib bertanda @internal —
 * tanda sungguhan yang dibaca IDE dan static analyser konsumen, bukan konvensi
 * lisan. Kelas baru yang lupa ditandai membuat test ini merah.
 */
class PublicApiTest extends TestCase
{
    private const PUBLIC = [
        'Maqiis\DocumentBuilder\Asset\AssetLoader',
        'Maqiis\DocumentBuilder\Contract\ContractPayload',
        'Maqiis\DocumentBuilder\Font\FontRegistry',
        'Maqiis\DocumentBuilder\Laravel\Concerns\IsTemplateRecord',
        'Maqiis\DocumentBuilder\Laravel\Concerns\TemplateRecord',
        'Maqiis\DocumentBuilder\Laravel\DocumentBuilderServiceProvider',
        'Maqiis\DocumentBuilder\Laravel\DocumentRenderer',
        'Maqiis\DocumentBuilder\Laravel\ImageUploadStorage',
        'Maqiis\DocumentBuilder\Laravel\Livewire\TemplateBuilder',
        'Maqiis\DocumentBuilder\Laravel\TransLabelTranslator',
        'Maqiis\DocumentBuilder\Media\ImageResolver',
        'Maqiis\DocumentBuilder\Media\ImageSourcePolicy',
        'Maqiis\DocumentBuilder\Pdf\GotenbergEngine',
        'Maqiis\DocumentBuilder\Pdf\MpdfEngine',
        'Maqiis\DocumentBuilder\Pdf\PdfEngine',
        'Maqiis\DocumentBuilder\Pdf\PdfRenderingException',
        'Maqiis\DocumentBuilder\Qr\NullQrCodeGenerator',
        'Maqiis\DocumentBuilder\Qr\QrCodeGenerator',
        'Maqiis\DocumentBuilder\Render\HtmlRenderer',
        'Maqiis\DocumentBuilder\Render\RenderContext',
        'Maqiis\DocumentBuilder\Render\RenderedDocument',
        'Maqiis\DocumentBuilder\Sanitize\HtmlSanitizer',
        'Maqiis\DocumentBuilder\Schema\Block',
        'Maqiis\DocumentBuilder\Schema\BlockPropSchema',
        'Maqiis\DocumentBuilder\Schema\BlockType',
        'Maqiis\DocumentBuilder\Schema\DocumentStyle',
        'Maqiis\DocumentBuilder\Schema\LabelTranslator',
        'Maqiis\DocumentBuilder\Schema\Margin',
        'Maqiis\DocumentBuilder\Schema\Orientation',
        'Maqiis\DocumentBuilder\Schema\PageSetup',
        'Maqiis\DocumentBuilder\Schema\PageSize',
        'Maqiis\DocumentBuilder\Schema\PropCatalog',
        'Maqiis\DocumentBuilder\Schema\SchemaMigrator',
        'Maqiis\DocumentBuilder\Schema\SchemaValidationException',
        'Maqiis\DocumentBuilder\Schema\SchemaValidator',
        'Maqiis\DocumentBuilder\Schema\Template',
        'Maqiis\DocumentBuilder\Schema\Zone',
        'Maqiis\DocumentBuilder\Schema\ZoneRepeat',
        'Maqiis\DocumentBuilder\Testing\TemplateRecordContractTests',
        'Maqiis\DocumentBuilder\Variable\ArrayVariableResolver',
        'Maqiis\DocumentBuilder\Variable\VariableRegistry',
        'Maqiis\DocumentBuilder\Variable\VariableResolver',
        'Maqiis\DocumentBuilder\Variable\VariableSyntax',
    ];

    /** @return list<string> FQCN setiap kelas/interface/trait/enum di src/ */
    private function allClasses(): array
    {
        $src = dirname(__DIR__).'/src';
        $classes = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            // config/ berisi array PHP, bukan kelas.
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/config/')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($src) + 1, -4);
            $classes[] = 'Maqiis\\DocumentBuilder\\'.str_replace('/', '\\', $relative);
        }

        sort($classes);

        return $classes;
    }

    private function isInternal(string $class): bool
    {
        return str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal');
    }

    public function test_every_class_outside_the_public_list_is_marked_internal(): void
    {
        $unmarked = array_values(array_filter(
            array_diff($this->allClasses(), self::PUBLIC),
            fn (string $class): bool => ! $this->isInternal($class),
        ));

        $this->assertSame([], $unmarked, "Kelas ini bukan API publik tapi belum bertanda @internal:\n".implode("\n", $unmarked));
    }

    public function test_public_classes_are_not_marked_internal(): void
    {
        $marked = array_values(array_filter(self::PUBLIC, fn (string $class): bool => $this->isInternal($class)));

        $this->assertSame([], $marked, "API publik tidak boleh bertanda @internal:\n".implode("\n", $marked));
    }

    public function test_the_public_list_has_no_stale_entries(): void
    {
        $missing = array_values(array_diff(self::PUBLIC, $this->allClasses()));

        $this->assertSame([], $missing, "Daftar API publik menyebut kelas yang tidak ada:\n".implode("\n", $missing));
    }
}
