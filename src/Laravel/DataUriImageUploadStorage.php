<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Illuminate\Http\UploadedFile;

/**
 * Perilaku bawaan sebelum adapter ini ada: berkas diubah jadi data URI base64
 * dan ditempel langsung ke schema. Tidak butuh disk apa pun sehingga selalu
 * lolos ImageSourcePolicy tanpa konfigurasi tambahan — cocok sebagai default
 * yang aman, atau untuk instalasi yang sengaja tidak mau menyimpan berkas ke
 * disk (mis. tidak ada storage bersama antar server).
 */
final class DataUriImageUploadStorage implements ImageUploadStorage
{
    public function store(UploadedFile $file): string
    {
        return 'data:'.$file->getMimeType().';base64,'.base64_encode((string) file_get_contents($file->getRealPath()));
    }

    public function resolveLocalPath(string $src): ?string
    {
        // Data URI sudah termuat penuh di dalam HTML; tidak ada berkas terpisah
        // untuk di-resolve.
        return null;
    }
}
