<?php

namespace Maqiis\DocumentBuilder\Media;

/**
 * Titik ekstensi opsional untuk mengganti src gambar sebelum diserahkan ke
 * engine PDF yang memaginasi sendiri (lihat MpdfEngine) — dipakai supaya
 * berkas yang aslinya URL disk lokal bisa dibaca langsung dari filesystem,
 * bukan di-fetch lewat HTTP saat merender. Sama sekali tidak mempengaruhi
 * HTML yang dilihat browser: itu tetap menerima src aslinya apa adanya.
 *
 * Implementasi konkretnya menyentuh Storage Laravel sehingga hidup di
 * src/Laravel/, bukan di sini — interface ini sendiri tetap bebas Laravel
 * supaya MpdfEngine boleh menerimanya tanpa melanggar batas itu.
 */
interface ImageResolver
{
    /**
     * Kembalikan src pengganti untuk dipakai engine PDF, atau $src apa
     * adanya bila tidak ada penggantian yang perlu dilakukan (mis. sumbernya
     * data URI, atau berkas yang memang hanya bisa diakses lewat jaringan).
     */
    public function resolve(string $src): string;
}
