<?php

namespace Maqiis\DocumentBuilder\Tests\Laravel;

use Maqiis\DocumentBuilder\Laravel\FilesystemImageUploadStorage;
use PHPUnit\Framework\TestCase;

class FilesystemImageUploadStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/db-storage-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/public/kop', 0777, true);
        mkdir($this->root.'/private', 0777, true);
        file_put_contents($this->root.'/public/kop/logo.png', 'x');
        file_put_contents($this->root.'/private/rahasia.png', 'x');
    }

    protected function tearDown(): void
    {
        foreach (['/public/kop/logo.png', '/private/rahasia.png'] as $file) {
            @unlink($this->root.$file);
        }

        foreach (['/public/kop', '/public', '/private', ''] as $dir) {
            @rmdir($this->root.$dir);
        }
    }

    public function test_a_file_inside_the_disk_root_resolves_to_its_real_path(): void
    {
        $this->assertSame(
            realpath($this->root.'/public/kop/logo.png'),
            FilesystemImageUploadStorage::containedPath($this->root.'/public', 'kop/logo.png'),
        );
    }

    public function test_a_path_that_climbs_out_of_the_disk_root_is_refused(): void
    {
        // src "…/storage/../private/rahasia.png" lolos pemotongan awalan URL; tanpa pemeriksaan
        // ini mpdf membaca berkas privat langsung dari filesystem.
        $this->assertNull(FilesystemImageUploadStorage::containedPath($this->root.'/public', '../private/rahasia.png'));
        $this->assertNull(FilesystemImageUploadStorage::containedPath($this->root.'/public', 'kop/../../private/rahasia.png'));
    }

    public function test_a_missing_file_inside_the_root_keeps_its_path(): void
    {
        // Berkas yang belum ada tetap di-resolve ke jalurnya: engine gagal memuat gambar itu,
        // bukan beralih mengambilnya lewat HTTP.
        $this->assertSame(
            $this->root.'/public/kop/tidak-ada.png',
            FilesystemImageUploadStorage::containedPath($this->root.'/public', 'kop/tidak-ada.png'),
        );
        $this->assertNull(FilesystemImageUploadStorage::containedPath($this->root.'/public', 'kop/../../tidak-ada.png'));
    }

    public function test_a_symlink_pointing_out_of_the_root_is_refused(): void
    {
        if (! @symlink($this->root.'/private/rahasia.png', $this->root.'/public/kop/tautan.png')) {
            $this->markTestSkipped('Symlink tidak bisa dibuat di sistem berkas ini.');
        }

        try {
            $this->assertNull(FilesystemImageUploadStorage::containedPath($this->root.'/public', 'kop/tautan.png'));
        } finally {
            @unlink($this->root.'/public/kop/tautan.png');
        }
    }

    public function test_a_sibling_directory_sharing_the_root_prefix_is_refused(): void
    {
        mkdir($this->root.'/public-lain');
        file_put_contents($this->root.'/public-lain/a.png', 'x');

        try {
            $this->assertNull(FilesystemImageUploadStorage::containedPath($this->root.'/public', '../public-lain/a.png'));
        } finally {
            @unlink($this->root.'/public-lain/a.png');
            @rmdir($this->root.'/public-lain');
        }
    }
}
