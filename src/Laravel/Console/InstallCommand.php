<?php

namespace Maqiis\DocumentBuilder\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use Maqiis\DocumentBuilder\Laravel\DocumentBuilderServiceProvider;

/**
 * Mengerjakan yang bisa diotomatiskan, mencetak yang tidak bisa, lalu
 * menjalankan doctor (spec §16.1).
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class InstallCommand extends Command
{
    protected $signature = 'document-builder:install
        {--views : Salin juga Blade builder ke resources/views/vendor supaya bisa diubah}
        {--force : Timpa berkas yang sudah ada}';

    protected $description = 'Pasang document-builder: publish config, tampilkan langkah manual, lalu jalankan doctor';

    /** Langkah yang bergantung konvensi aplikasi, jadi harus ditulis manusia. */
    private const CHECKLIST = [
        'Penyimpanan template: tabel/model milik Anda sendiri (package tidak mempublish migration maupun model). Cukup `implements TemplateRecord` — untuk Eloquent tambahkan `use IsTemplateRecord` — lalu tulis authorizeTemplateView() sendiri; trait sengaja tidak menyediakannya.',
        'Katalog variabel: bind Maqiis\DocumentBuilder\Variable\VariableRegistry (scoped) di service provider aplikasi.',
        "Route + view pembungkus yang memasang @livewire('document-builder::template-builder', ['template' => \$template]). Layout-nya WAJIB punya @stack('script') setelah @livewireScripts — tanpa itu kanvas tidak pernah hidup.",
        'Isi document-builder.livewire.* di config (index_route, print_route, print_ability, update_ability) dan pastikan ability update ada di sistem permission Anda.',
    ];

    public function handle(): int
    {
        $this->info('Memasang document-builder');

        $this->publish('document-builder-config');

        if ($this->option('views')) {
            $this->publish('document-builder-views');
        }

        $this->newLine();
        $this->line('Langkah yang harus Anda tulis sendiri (lihat TUTORIAL.md paket):');

        foreach (self::CHECKLIST as $index => $step) {
            $this->line(sprintf('  %d. %s', $index + 1, $step));
        }

        $this->newLine();

        return $this->call('document-builder:doctor');
    }

    private function publish(string $tag): void
    {
        $force = (bool) $this->option('force');
        $targets = array_values(ServiceProvider::pathsToPublish(DocumentBuilderServiceProvider::class, $tag));
        $existed = array_map('file_exists', $targets);

        $this->callSilently('vendor:publish', array_filter(['--tag' => $tag, '--force' => $force ?: null]));

        foreach ($targets as $index => $target) {
            $state = match (true) {
                $existed[$index] && ! $force => 'sudah ada, dilewati',
                $existed[$index] => 'ditimpa',
                default => 'dibuat',
            };

            $this->line(sprintf('  %s — %s', ltrim(str_replace(base_path(), '', $target), '/'), $state));
        }
    }
}
