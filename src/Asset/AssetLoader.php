<?php

namespace Maqiis\DocumentBuilder\Asset;

use RuntimeException;

/**
 * Aset disisipkan inline ke dalam halaman, bukan dipublikasikan ke public/.
 * Tidak ada langkah build, tidak ada langkah publish, dan tidak ada kemungkinan
 * aset basi yang membuat builder dan hasil cetak berbeda.
 */
final class AssetLoader
{
    public static function path(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/resources/'.ltrim($relative, '/');

        if (! is_file($path)) {
            throw new RuntimeException("Aset document-builder tidak ditemukan: {$relative}");
        }

        return $path;
    }

    public static function css(): string
    {
        return self::read('css/document.css');
    }

    /** Paginator dan seluruh dependensinya sebagai satu blok siap sisip. */
    public static function paginatorJs(): string
    {
        return self::bundle(['js/paginator.mjs', 'js/fragments.mjs', 'js/paginate-dom.mjs']);
    }

    /** Paginator ditambah kode builder — dipakai halaman penyusun. */
    public static function bundledJs(): string
    {
        return self::bundle([
            'js/paginator.mjs',
            'js/fragments.mjs',
            'js/paginate-dom.mjs',
            'js/builder.mjs',
        ]);
    }

    /**
     * Berkas font disisipkan sebagai data URI base64, bukan dipublikasikan ke
     * public/ — konsisten dengan CSS dan JS, dan supaya PDF via Gotenberg
     * (Chromium yang menerima berkas HTML lepas, tanpa akses ke domain
     * aplikasi) tetap bisa memuatnya tanpa permintaan jaringan sama sekali.
     *
     * @param  array<string,string>  $variants  font-weight CSS ('normal'|'bold') => path relatif ke resources/
     */
    public static function fontFace(string $family, array $variants): string
    {
        $css = '';

        foreach ($variants as $weight => $relative) {
            $data = base64_encode(self::read($relative));
            $css .= sprintf(
                "@font-face{font-family:'%s';font-weight:%s;font-style:normal;font-display:swap;"
                    ."src:url(data:font/ttf;base64,%s) format('truetype')}",
                $family,
                $weight,
                $data,
            );
        }

        return $css;
    }

    /** @param  list<string>  $relatives urutan penting: dependensi lebih dulu */
    private static function bundle(array $relatives): string
    {
        return implode("\n", array_map(
            static fn (string $relative): string => self::stripModuleSyntax(self::read($relative)),
            $relatives,
        ));
    }

    /**
     * Berkas ditulis sebagai modul ESM supaya bisa diuji node --test, tetapi
     * disisipkan inline sebagai satu blok. Baris import akan gagal di skrip
     * inline, dan export tidak ada artinya di sana.
     */
    private static function stripModuleSyntax(string $source): string
    {
        $source = (string) preg_replace('/^\s*import\s[^;]*;\s*$/m', '', $source);

        return (string) preg_replace('/^export\s+/m', '', $source);
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }
}
