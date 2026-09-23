<?php

namespace Maqiis\DocumentBuilder\Laravel\Doctor;

use Illuminate\Contracts\Foundation\Application;
use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Laravel\DocumentRenderer;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use Mpdf\Mpdf;
use Throwable;

/**
 * Memeriksa kegagalan yang terjadi diam-diam saat package dipasang di aplikasi
 * baru (spec §14.3). Setiap pemeriksaan mengembalikan DoctorCheck; tidak ada
 * yang melempar exception — satu pemeriksaan yang rusak tidak boleh
 * menyembunyikan hasil pemeriksaan lain.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class Doctor
{
    public function __construct(private readonly Application $app) {}

    /** @return list<DoctorCheck> */
    public function run(): array
    {
        return [
            $this->engine(),
            $this->fonts(),
            $this->images(),
            $this->upload(),
            $this->variables(),
            $this->render(),
        ];
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app['config']->get("document-builder.{$key}", $default);
    }

    private function engine(): DoctorCheck
    {
        $name = (string) $this->config('engine', 'mpdf');

        if ($name === 'mpdf') {
            if (! class_exists(Mpdf::class)) {
                return DoctorCheck::fail('engine', 'Engine mpdf dipilih tapi paketnya belum terpasang: composer require mpdf/mpdf');
            }

            $dir = (string) $this->config('engines.mpdf.temp_dir', sys_get_temp_dir());

            if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) {
                return DoctorCheck::fail('engine', "temp_dir mpdf {$dir} tidak ada dan tidak bisa dibuat.");
            }

            if (! is_writable($dir)) {
                return DoctorCheck::fail('engine', "temp_dir mpdf {$dir} tidak bisa ditulisi.");
            }

            return DoctorCheck::ok('engine', "mpdf, temp_dir {$dir}");
        }

        if ($name === 'gotenberg') {
            $base = rtrim((string) $this->config('engines.gotenberg.base_url', ''), '/');
            $status = $this->httpStatus($base.'/health');

            if ($status !== 200) {
                return DoctorCheck::fail('engine', sprintf(
                    'Gotenberg di %s tidak menjawab (%s). Tanpa layanan itu unduh PDF gagal — periksa GOTENBERG_URL dan container gotenberg.',
                    $base,
                    $status === 0 ? 'tak terjangkau' : "HTTP {$status}",
                ));
            }

            return DoctorCheck::ok('engine', "gotenberg di {$base}");
        }

        return DoctorCheck::fail('engine', "Engine PDF \"{$name}\" tidak dikenal (pilihan: mpdf, gotenberg).");
    }

    /** 0 berarti tidak terjangkau. Timeout pendek: doctor tidak boleh menggantung. */
    private function httpStatus(string $url): int
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $status;
    }

    private function fonts(): DoctorCheck
    {
        $broken = [];

        foreach (array_keys(FontRegistry::all()) as $key) {
            try {
                FontRegistry::fontFaceCss($key);
            } catch (Throwable) {
                $broken[] = $key;
            }
        }

        return $broken === []
            ? DoctorCheck::ok('fonts', count(FontRegistry::all()).' font terbaca')
            : DoctorCheck::fail('fonts', 'Berkas font tidak terbaca: '.implode(', ', $broken));
    }

    private function images(): DoctorCheck
    {
        $prefixes = (array) $this->config('images.allowed_prefixes', []);

        foreach ($prefixes as $prefix) {
            if (trim((string) $prefix) === '') {
                return DoctorCheck::fail('images', 'images.allowed_prefixes berisi awalan kosong — itu mengizinkan URL gambar apa pun, termasuk host internal yang lalu diambil engine PDF. Hapus entri kosong itu.');
            }
        }

        return DoctorCheck::ok('images', count($prefixes).' awalan tambahan dipercaya');
    }

    private function upload(): DoctorCheck
    {
        $strategy = (string) $this->config('images.upload_strategy', 'data-uri');

        if ($strategy === 'data-uri') {
            return DoctorCheck::warn('upload', 'upload_strategy data-uri: gambar ditanam ke schema dan ikut terkirim di setiap pratinjau. Disarankan filesystem.');
        }

        if ($strategy !== 'filesystem') {
            return DoctorCheck::fail('upload', "upload_strategy \"{$strategy}\" tidak dikenal (pilihan: filesystem, data-uri).");
        }

        $disk = (string) $this->config('images.upload_disk', 'public');

        if (! array_key_exists($disk, (array) $this->app['config']->get('filesystems.disks', []))) {
            return DoctorCheck::fail('upload', "Disk \"{$disk}\" tidak terdaftar di config/filesystems.php.");
        }

        // Disk lokal tanpa 'url' (mis. "local" bawaan Laravel, root storage/app)
        // menghasilkan src yang lolos ImageSourcePolicy tapi 404 di browser —
        // kegagalan paling sering, dan tidak kelihatan sampai gambar diunggah.
        $diskConfig = (array) $this->app['config']->get("filesystems.disks.{$disk}", []);

        if (($diskConfig['driver'] ?? null) === 'local' && empty($diskConfig['url'])) {
            return DoctorCheck::fail('upload', "Disk \"{$disk}\" tidak punya konfigurasi 'url' — gambar yang diunggah akan 404 di browser. Pakai disk yang bisa diakses publik, mis. public.");
        }

        if ($disk === 'public' && ! file_exists(public_path('storage'))) {
            return DoctorCheck::warn('upload', 'Disk public belum ditautkan: jalankan php artisan storage:link, kalau tidak gambar yang diunggah 404.');
        }

        return DoctorCheck::ok('upload', "filesystem, disk {$disk}");
    }

    private function variables(): DoctorCheck
    {
        $groups = $this->app->make(VariableRegistry::class)->groups();

        if ($groups === []) {
            return DoctorCheck::warn('variables', 'VariableRegistry kosong: panel variabel builder kosong dan token {{ … }} tidak tersubstitusi. Bind katalog aplikasi di service provider.');
        }

        return DoctorCheck::ok('variables', array_sum(array_map('count', $groups)).' variabel dalam '.count($groups).' grup');
    }

    private function render(): DoctorCheck
    {
        try {
            $html = $this->app->make(DocumentRenderer::class)->render(Template::blank())->flowHtml();
        } catch (Throwable $e) {
            return DoctorCheck::fail('render', 'Render template kosong gagal: '.$e->getMessage());
        }

        return str_contains($html, 'doc-root')
            ? DoctorCheck::ok('render', 'template kosong ter-render')
            : DoctorCheck::fail('render', 'Render template kosong tidak menghasilkan .doc-root.');
    }
}
