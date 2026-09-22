<?php

namespace Maqiis\DocumentBuilder\Schema;

/**
 * Seam penerjemah label. Core tidak boleh memanggil trans() atau apa pun milik
 * Laravel (lihat CLAUDE.md), jadi yang ada di sini hanya interface-nya; adapter
 * yang menyambungkannya ke berkas lang hidup di src/Laravel/.
 *
 * $fallback selalu teks bahasa Indonesia yang berlaku hari ini, sehingga
 * implementasi yang tidak mengenali sebuah kunci cukup mengembalikannya.
 */
interface LabelTranslator
{
    public function translate(string $key, string $fallback): string;
}
