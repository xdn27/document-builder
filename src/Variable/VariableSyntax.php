<?php

namespace Maqiis\DocumentBuilder\Variable;

final class VariableSyntax
{
    public const PATTERN = '/\{\{\s*([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)\s*\}\}/u';

    public const PAGE_MARKER_CLASS = 'db-var-page';

    public const PAGES_MARKER_CLASS = 'db-var-pages';

    /** Jalur yang tidak pernah ditanyakan ke resolver; diisi saat paginasi. */
    public const RESERVED = ['page', 'pages'];

    public function __construct(private readonly VariableResolver $resolver) {}

    /** Resolver dokumen — dipakai blok berulang untuk membaca koleksi dan membuat scope per butir. */
    public function resolver(): VariableResolver
    {
        return $this->resolver;
    }

    /**
     * Teks masukan sudah melewati HtmlSanitizer, sehingga tag inline yang tersisa aman.
     * Nilai variabel selalu di-escape sebelum disisipkan.
     */
    public function apply(string $text): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            function (array $matches): string {
                $path = $matches[1];

                if ($path === 'page') {
                    return '<span class="'.self::PAGE_MARKER_CLASS.'"></span>';
                }

                if ($path === 'pages') {
                    return '<span class="'.self::PAGES_MARKER_CLASS.'"></span>';
                }

                $value = $this->resolver->resolve($path);

                if ($value === null) {
                    return '⟦'.htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'?⟧';
                }

                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $text,
        );
    }

    /**
     * Isi variabel dengan nilai MENTAH — untuk nilai yang bukan HTML, seperti
     * sumber gambar. Pemanggil wajib meng-escape hasilnya sendiri saat
     * menyisipkannya ke atribut.
     *
     * Null bila satu saja token tidak bisa diisi: jalur tidak dikenal resolver,
     * atau `page`/`pages` yang baru ada nilainya saat paginasi. Mengembalikan
     * penanda seperti apply() di sini akan menghasilkan URL rusak yang sulit
     * dibedakan dari URL yang memang ditolak.
     */
    public function applyRaw(string $text): ?string
    {
        $unresolved = false;

        $result = (string) preg_replace_callback(
            self::PATTERN,
            function (array $matches) use (&$unresolved): string {
                $value = in_array($matches[1], self::RESERVED, true)
                    ? null
                    : $this->resolver->resolve($matches[1]);

                if ($value === null) {
                    $unresolved = true;

                    return '';
                }

                return $value;
            },
            $text,
        );

        return $unresolved ? null : $result;
    }

    /** @return list<string> jalur unik yang dipakai teks, tanpa jalur cadangan */
    public static function paths(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        $paths = array_values(array_unique($matches[1] ?? []));

        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => ! in_array($path, self::RESERVED, true),
        ));
    }
}
