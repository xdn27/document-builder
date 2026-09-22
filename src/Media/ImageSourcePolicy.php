<?php

namespace Maqiis\DocumentBuilder\Media;

/**
 * Engine PDF akan dengan senang hati mengambil URL apa pun yang diberikan kepadanya,
 * termasuk host internal dan endpoint metadata cloud. Kebijakan ini adalah pintu masuk
 * tunggal: sumber yang tidak lolos tidak pernah sampai ke engine.
 */
final class ImageSourcePolicy
{
    private const DATA_URI_PATTERN = '#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=\s]+$#i';

    /** @param  list<string>  $allowedPrefixes awalan absolut; diperlakukan sebagai batas jalur */
    public function __construct(
        private readonly array $allowedPrefixes = [],
        private readonly bool $allowDataUri = true,
        private readonly bool $allowAnything = false,
    ) {}

    /** Hanya untuk test renderer — jangan dipakai di jalur produksi. */
    public static function permissive(): self
    {
        return new self([], true, true);
    }

    public function isAllowed(string $src): bool
    {
        $src = trim($src);

        if ($src === '') {
            return false;
        }

        if (str_starts_with(strtolower($src), 'data:')) {
            return $this->allowDataUri && preg_match(self::DATA_URI_PATTERN, $src) === 1;
        }

        if ($this->allowAnything) {
            return str_starts_with($src, 'http://') || str_starts_with($src, 'https://');
        }

        foreach ($this->allowedPrefixes as $prefix) {
            if ($this->matchesPrefix($src, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pencocokan awalan harus berhenti di batas jalur. Tanpa ini,
     * "https://cdn.sekolah.id.penyerang.com/" lolos terhadap awalan
     * "https://cdn.sekolah.id" hanya karena kecocokan string mentah.
     */
    private function matchesPrefix(string $src, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }

        $prefix = str_ends_with($prefix, '/') ? $prefix : $prefix.'/';

        return str_starts_with($src, $prefix);
    }
}
