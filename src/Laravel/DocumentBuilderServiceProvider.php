<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Maqiis\DocumentBuilder\Contract\ContractPayload;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Pdf\GotenbergEngine;
use Maqiis\DocumentBuilder\Pdf\MpdfEngine;
use Maqiis\DocumentBuilder\Pdf\PdfEngine;
use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Maqiis\DocumentBuilder\Schema\LabelTranslator;
use Maqiis\DocumentBuilder\Schema\PropCatalog;
use Maqiis\DocumentBuilder\Variable\BuiltinVariables;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;

class DocumentBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/document-builder.php', 'document-builder');

        $this->app->singleton(QrCodeGenerator::class, MilonQrCodeGenerator::class);

        $this->app->singleton(ImageUploadStorage::class, function ($app): ImageUploadStorage {
            $config = $app['config']->get('document-builder.images', []);

            if (($config['upload_strategy'] ?? 'data-uri') === 'filesystem') {
                return new FilesystemImageUploadStorage(
                    $config['upload_disk'] ?? 'public',
                    $config['upload_directory'] ?? 'document-builder',
                );
            }

            return new DataUriImageUploadStorage;
        });

        // Dipakai MpdfEngine untuk membaca gambar langsung dari filesystem saat
        // merender PDF, bukan fetch HTTP. Otomatis no-op selama ImageUploadStorage
        // di atas masih data URI — lihat StorageImageResolver.
        $this->app->singleton(
            ImageResolver::class,
            fn ($app) => new StorageImageResolver($app->make(ImageUploadStorage::class)),
        );

        $this->app->singleton(ImageSourcePolicy::class, function ($app): ImageSourcePolicy {
            $config = $app['config']->get('document-builder.images');

            $prefixes = [
                rtrim($app['url']->to('/storage'), '/').'/',
                rtrim(public_path('storage'), '/').'/',
                rtrim(storage_path('app/public'), '/').'/',
            ];

            // ImageUploadStorage berbasis disk hanya dipercaya saat memang dipakai —
            // supaya disk yang tidak pernah diisi gambar (mis. s3 yang cuma
            // dikonfigurasi untuk keperluan lain) tidak ikut jadi awalan terpercaya.
            if (($config['upload_strategy'] ?? 'data-uri') === 'filesystem') {
                $disk = $config['upload_disk'] ?? 'public';
                $prefixes[] = rtrim(Storage::disk($disk)->url(''), '/').'/';
            }

            return new ImageSourcePolicy(
                array_merge($prefixes, $config['allowed_prefixes'] ?? []),
                (bool) ($config['allow_data_uri'] ?? true),
            );
        });

        $this->app->singleton(PdfEngine::class, function ($app): PdfEngine {
            $name = $app['config']->get('document-builder.engine', 'mpdf');
            $options = $app['config']->get("document-builder.engines.{$name}", []);

            return match ($name) {
                'mpdf' => new MpdfEngine(
                    $options['temp_dir'] ?? null,
                    (float) ($options['auto_header_reserve_mm'] ?? 35.0),
                    (float) ($options['auto_footer_reserve_mm'] ?? 12.0),
                    $app->make(ImageResolver::class),
                ),
                'gotenberg' => new GotenbergEngine(
                    $options['base_url'] ?? 'http://gotenberg:3000',
                    (int) ($options['timeout'] ?? 30),
                ),
                default => throw new InvalidArgumentException("Engine PDF \"{$name}\" tidak dikenal."),
            };
        });

        /*
        | Registry bawaan sengaja KOSONG, bukan berisi contoh. Aplikasi yang
        | memakai builder mem-bind katalognya sendiri (lihat TUTORIAL.md);
        | yang belum mem-bind tetap bisa membuka builder dengan panel variabel
        | kosong. Scoped, bukan singleton: katalog aplikasi biasanya membaca
        | baris pengaturan dari basis data, dan nilai itu tidak boleh basi
        | melintasi request pada worker yang hidup lama.
        */
        $this->app->scoped(VariableRegistry::class, fn (): VariableRegistry => new VariableRegistry);

        /*
        | Variabel bawaan (tanggal hari ini, nomor halaman) lewat extend(), bukan
        | lewat binding di atas: extender tetap berlaku saat aplikasi mem-bind
        | ulang VariableRegistry dengan katalognya sendiri, dan register() hanya
        | mengisi path yang belum didefinisikan katalog itu. Scoped supaya
        | "hari ini" dihitung ulang per request/job, dengan zona waktu aplikasi.
        */
        $this->app->scoped(BuiltinVariables::class, fn (): BuiltinVariables => new BuiltinVariables(now()));

        $this->app->extend(VariableRegistry::class, fn (VariableRegistry $registry, $app): VariableRegistry => $this->builtinVariablesEnabled()
            ? $app->make(BuiltinVariables::class)->register($registry)
            : $registry);

        /*
        | Label dibaca lewat penerjemah Laravel (namespace "document-builder",
        | grup "labels"). Tanpa berkas terjemahan, TransLabelTranslator
        | mengembalikan teks Indonesia bawaan — tidak ada teks yang berubah.
        | Locale dibaca saat label diminta, jadi singleton aman.
        */
        $this->app->singleton(LabelTranslator::class, fn ($app): LabelTranslator => new TransLabelTranslator($app['translator']));
        $this->app->singleton(PropCatalog::class, fn ($app): PropCatalog => new PropCatalog($app->make(LabelTranslator::class)));
        $this->app->bind(ContractPayload::class, fn ($app): ContractPayload => new ContractPayload($app->make(LabelTranslator::class)));

        /*
        | Scoped, bukan singleton: renderer memegang VariableRegistry yang juga
        | scoped, dan singleton akan membekukan registry pada resolusi pertama —
        | membuat binding katalog aplikasi tidak pernah terbaca.
        */
        $this->app->scoped(DocumentRenderer::class, fn ($app): DocumentRenderer => new DocumentRenderer(
            $app->make(VariableRegistry::class),
            $app->make(ImageSourcePolicy::class),
            $app->make(QrCodeGenerator::class),
            $app->make(ImageResolver::class),
            $this->builtinVariablesEnabled() ? $app->make(BuiltinVariables::class) : null,
        ));
    }

    private function builtinVariablesEnabled(): bool
    {
        return (bool) $this->app['config']->get('document-builder.variables.builtin', true);
    }

    public function boot(): void
    {
        $package = dirname(__DIR__, 2);

        $this->loadViewsFrom($package.'/resources/views', 'document-builder');
        $this->loadTranslationsFrom($package.'/resources/lang', 'document-builder');

        if ($this->app->runningInConsole()) {
            // Satu prefix yang bisa ditebak, plus tag agregat "document-builder"
            // untuk yang ingin semuanya sekaligus (spec §16.3).
            $this->publishes([
                __DIR__.'/config/document-builder.php' => config_path('document-builder.php'),
            ], ['document-builder-config', 'document-builder']);

            $this->publishes([
                $package.'/resources/views' => resource_path('views/vendor/document-builder'),
            ], ['document-builder-views', 'document-builder']);

            /*
            | Sengaja TIDAK ada migration maupun model yang dipublish. Tabel dan
            | model template milik aplikasi — nama, kolom, scoping cabang/tenant
            | ditentukan konsumen; package hanya bicara lewat kontrak
            | Concerns\TemplateRecord.
            */

            $this->commands([
                Console\InstallCommand::class,
                Console\DoctorCommand::class,
            ]);
        }

        /*
        | Livewire adalah dependency OPSIONAL (require-dev + suggest): aplikasi
        | Inertia/React memasang package ini tanpa Livewire sama sekali, dan
        | autoload tidak boleh menyentuh kelas komponen di sana. Guard inilah
        | satu-satunya tempat kelas itu dirujuk (spec §5).
        */
        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('document-builder::template-builder', Livewire\TemplateBuilder::class);
        }
    }
}
