<?php

namespace Maqiis\DocumentBuilder\Laravel\Console;

use Illuminate\Console\Command;
use Maqiis\DocumentBuilder\Laravel\Doctor\Doctor;
use Maqiis\DocumentBuilder\Laravel\Doctor\DoctorCheck;

/**
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'document-builder:doctor';

    protected $description = 'Periksa konfigurasi document-builder: engine PDF, font, gambar, variabel, dan render';

    public function handle(Doctor $doctor): int
    {
        $checks = $doctor->run();

        $this->table(
            ['Pemeriksaan', 'Status', 'Keterangan'],
            array_map(fn (DoctorCheck $c): array => [$c->name, strtoupper($c->status), $c->message], $checks),
        );

        $failed = array_filter($checks, fn (DoctorCheck $c): bool => $c->status === DoctorCheck::FAIL);

        if ($failed !== []) {
            $this->error(count($failed).' pemeriksaan gagal.');

            return self::FAILURE;
        }

        $this->info('Semua pemeriksaan wajib lolos.');

        return self::SUCCESS;
    }
}
