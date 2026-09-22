<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Maqiis\DocumentBuilder\Media\ImageResolver;

/**
 * Menjembatani kontrak ImageResolver (dipakai MpdfEngine di jalur render)
 * dengan ImageUploadStorage (dipakai jalur unggah host app). Keduanya sengaja
 * dipisah: satu soal "src apa yang ditulis ke schema", yang lain soal
 * "bisakah src itu dibaca dari filesystem tanpa jaringan" — tapi jawabannya
 * sama-sama bergantung pada ImageUploadStorage mana yang aktif.
 *
 * Otomatis jadi no-op saat strategi aktif adalah DataUriImageUploadStorage:
 * resolveLocalPath()-nya selalu null, jadi resolve() di sini selalu
 * mengembalikan $src apa adanya.
 */
final class StorageImageResolver implements ImageResolver
{
    public function __construct(private readonly ImageUploadStorage $storage) {}

    public function resolve(string $src): string
    {
        return $this->storage->resolveLocalPath($src) ?? $src;
    }
}
