<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;

class LetterTemplateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped, bukan singleton: katalog nyata biasanya membaca basis data
        // dan tanggal hari ini — nilainya tidak boleh basi antar request.
        $this->app->scoped(VariableRegistry::class, fn (): VariableRegistry => (new VariableRegistry)
            ->define('institution.name', 'Nama Lembaga', (string) config('app.name'), 'Lembaga')
            ->define('letter.number', 'Nomor Surat', '001/IX/2026', 'Surat')
            ->define('today.long', 'Tanggal Hari Ini', now()->translatedFormat('j F Y'), 'Tanggal'));
    }
}
