<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Illuminate\Http\UploadedFile;

/**
 * Titik masuk tunggal untuk mengubah berkas yang diunggah pengguna di UI
 * builder (mis. lewat properti Livewire ber-tipe file) menjadi "src" yang
 * ditulis ke schema template. Implementasinya menentukan bentuk keluarannya —
 * data URI, URL disk lokal, atau URL S3 — tanpa host app maupun block
 * renderer perlu tahu bedanya; keduanya hanya melihat string src biasa.
 */
interface ImageUploadStorage
{
    /**
     * Simpan berkas lalu kembalikan src yang akan ditulis ke schema. Hasilnya
     * harus lolos ImageSourcePolicy — tanggung jawab itu ada pada pemasangan
     * (lihat DocumentBuilderServiceProvider), bukan pada pemanggil ini.
     */
    public function store(UploadedFile $file): string;

    /**
     * Bila $src berasal dari implementasi ini dan bisa dibaca langsung dari
     * filesystem tanpa jaringan, kembalikan path absolutnya — dipakai
     * StorageImageResolver supaya mpdf tidak perlu fetch HTTP saat membuat
     * dokumen. Kembalikan null bila $src bukan buatan implementasi ini, atau
     * memang hanya bisa diakses lewat jaringan (mis. disk S3).
     */
    public function resolveLocalPath(string $src): ?string;
}
