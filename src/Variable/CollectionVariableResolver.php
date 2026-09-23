<?php

namespace Maqiis\DocumentBuilder\Variable;

/**
 * Resolver yang juga menyediakan KOLEKSI — daftar butir berulang seperti
 * penerima surat. Interface terpisah dari VariableResolver supaya resolver
 * milik aplikasi yang sudah ada tidak wajib mengimplementasikannya: blok yang
 * membutuhkan koleksi jatuh ke perilaku satu-butir bila resolvernya tidak
 * mendukung.
 *
 * Nilai tiap butir tetap teks polos yang di-escape, sama seperti variabel biasa.
 */
interface CollectionVariableResolver extends VariableResolver
{
    /**
     * Satu resolver per butir, dengan path relatif terhadap butir (mis. 'name',
     * bukan 'recipients.0.name').
     *
     * @return list<VariableResolver>|null null bila koleksi tidak dikenal —
     *                                     berbeda dari [] (koleksi dikenal tapi kosong)
     */
    public function collection(string $name): ?array;
}
