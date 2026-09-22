<?php

namespace Maqiis\DocumentBuilder\Laravel\Concerns;

/**
 * Kontrak antara komponen penyusun dan penyimpanan template milik aplikasi.
 * Sengaja tidak menyebut Eloquent: implementasi apa pun sah selama memenuhi
 * keempat method ini.
 *
 * Otorisasi dipecah dua dengan sengaja, karena kode yang ada memang punya dua
 * pemeriksaan berbeda — scoping cabang saat membuka builder, dan ability saat
 * menyimpan. Menyatukannya akan menghilangkan salah satunya secara diam-diam.
 */
interface TemplateRecord
{
    public function getTemplateSchema(): array;

    public function setTemplateSchema(array $schema): void;

    /**
     * Nama yang ditampilkan di toolbar penyusun. String kosong sah; null tidak,
     * karena view merender apa pun yang dikembalikan tanpa memeriksa lagi.
     */
    public function getTemplateName(): string;

    /** Dipanggil saat builder dibuka — mis. scoping cabang. */
    public function authorizeTemplateView(): void;

    /** Dipanggil saat perubahan disimpan — ability menyimpan. */
    public function authorizeTemplateUpdate(): void;
}
