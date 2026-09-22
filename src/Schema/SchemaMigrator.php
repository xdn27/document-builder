<?php

namespace Maqiis\DocumentBuilder\Schema;

/**
 * Menaikkan schema tersimpan ke versi yang dimengerti package ini, satu langkah
 * per versi. Dipanggil Template::fromArray() SEBELUM validator, karena validator
 * memang menolak versi yang bukan versi berjalan — itu perilaku yang benar untuk
 * schema yang sudah dinormalkan, dan migrator inilah yang menormalkannya.
 *
 * Versi yang lebih baru dari package ditolak dengan pesannya sendiri: itu bukan
 * schema rusak, itu package yang kedaluwarsa, dan pesan galatnya harus
 * mengatakan begitu.
 */
final class SchemaMigrator
{
    /** @var array<int,callable(array):array> */
    private readonly array $upgrades;

    /** @param  array<int,callable(array):array>|null  $upgrades */
    public function __construct(
        ?array $upgrades = null,
        private readonly int $target = Template::CURRENT_VERSION,
    ) {
        $this->upgrades = $upgrades ?? self::defaultUpgrades();
    }

    /**
     * Kunci = versi asal, nilai = fungsi yang mengembalikan schema versi
     * berikutnya. Saat CURRENT_VERSION dinaikkan, langkahnya WAJIB ditambahkan
     * di sini — SchemaMigratorTest yang memastikan itu tidak terlupa.
     *
     * Sebuah METHOD, bukan konstanta: konstanta kelas di PHP tidak boleh memuat
     * closure ("Constant expression contains invalid operations"), jadi peta ini
     * akan langsung fatal begitu langkah upgrade pertama ditambahkan.
     *
     * @return array<int,callable(array):array>
     */
    private static function defaultUpgrades(): array
    {
        return [
            // 1 => fn (array $raw): array => ['version' => 2] + $raw,
        ];
    }

    /** @throws SchemaValidationException */
    public static function upgrade(array $raw): array
    {
        return (new self)->run($raw);
    }

    /** @throws SchemaValidationException */
    public function run(array $raw): array
    {
        $version = $raw['version'] ?? null;

        if (! is_int($version)) {
            throw new SchemaValidationException([
                'version' => sprintf('Versi schema harus berupa angka, diterima %s.', var_export($version, true)),
            ]);
        }

        if ($version > $this->target) {
            throw new SchemaValidationException([
                'version' => sprintf(
                    'Template ini memakai versi schema %d, lebih baru dari yang dimengerti paket terpasang (%d). Perbarui document-builder.',
                    $version,
                    $this->target,
                ),
            ]);
        }

        while ($version < $this->target) {
            $upgrade = $this->upgrades[$version] ?? null;

            if ($upgrade === null) {
                throw new SchemaValidationException([
                    'version' => sprintf(
                        'Tidak ada jalur upgrade schema dari versi %d ke %d. Ini bug paket, bukan data yang salah.',
                        $version,
                        $version + 1,
                    ),
                ]);
            }

            $raw = $upgrade($raw);
            $version = is_int($raw['version'] ?? null) ? $raw['version'] : $version + 1;
            $raw['version'] = $version;
        }

        return $raw;
    }
}
