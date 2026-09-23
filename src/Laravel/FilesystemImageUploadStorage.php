<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Menyimpan berkas ke disk Laravel mana pun yang dikonfigurasi (lihat
 * config('document-builder.images.upload_disk'), bawaan "public") dan
 * mengembalikan URL publiknya, bukan data URI. Disk dan direktori tujuan
 * diatur lewat konfigurasi, bukan konstanta, supaya host app bebas memilih
 * disknya sendiri — SELAMA disk itu memang disk publik (punya config 'url'),
 * bukan disk privat seperti disk "local" bawaan Laravel; lihat store().
 *
 * Berkas lama tidak otomatis dihapus saat sebuah blok diunggah ulang —
 * pembersihan berkas yatim (orphan) belum ditangani di sini.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class FilesystemImageUploadStorage implements ImageUploadStorage
{
    public function __construct(
        private readonly string $disk,
        private readonly string $directory = 'document-builder',
    ) {}

    public function store(UploadedFile $file): string
    {
        $this->assertPubliclyServable();

        $path = $file->store($this->directory, $this->disk);

        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Disk berdriver "local" tanpa config 'url' (mis. disk "local" bawaan
     * Laravel, root storage/app tanpa symlink) membuat Storage::url() menebak
     * URL "/storage/..." yang tidak sesuai root sungguhan disk itu — src yang
     * dihasilkan lolos ImageSourcePolicy (awalannya konsisten dengan tebakan
     * yang sama) tapi 404 di browser karena filenya tidak pernah ada di jalur
     * yang ditebak. Gagal cepat di sini jauh lebih baik daripada src yang
     * diam-diam salah. Disk berdriver lain (mis. s3) tidak diperiksa: URL-nya
     * dihitung dari bucket/region, bukan dari config 'url' eksplisit.
     */
    private function assertPubliclyServable(): void
    {
        if (config("filesystems.disks.{$this->disk}.driver") !== 'local') {
            return;
        }

        if (empty(config("filesystems.disks.{$this->disk}.url"))) {
            throw new RuntimeException(sprintf(
                'Disk "%s" tidak punya konfigurasi "url" sehingga tidak bisa diakses browser (kemungkinan disk privat, mis. disk "local" bawaan Laravel). Set document-builder.images.upload_disk ke disk publik seperti "public", atau tambahkan config "url" pada disk "%s".',
                $this->disk,
                $this->disk,
            ));
        }
    }

    public function resolveLocalPath(string $src): ?string
    {
        if (config("filesystems.disks.{$this->disk}.driver") !== 'local') {
            return null;
        }

        $prefix = rtrim(Storage::disk($this->disk)->url(''), '/').'/';

        if (! str_starts_with($src, $prefix)) {
            return null;
        }

        return Storage::disk($this->disk)->path(substr($src, strlen($prefix)));
    }
}
