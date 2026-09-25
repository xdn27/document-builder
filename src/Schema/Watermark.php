<?php

namespace Maqiis\DocumentBuilder\Schema;

/**
 * Teks diagonal samar di setiap halaman ("DRAF", "RAHASIA", …). Teks kosong
 * berarti tanpa watermark, jadi schema lama yang belum mengenal key ini tetap sah.
 *
 * Teks polos saja — tanpa markup dan tanpa variabel: watermark menandai status
 * dokumen, bukan isinya. Warna sengaja tidak bisa diatur; hitam dengan opasitas
 * rendah adalah satu-satunya bentuk yang identik di browser dan mpdf.
 */
final class Watermark
{
    public const MAX_LENGTH = 40;

    public const MIN_OPACITY = 0.05;

    public const MAX_OPACITY = 0.5;

    public const DEFAULT_OPACITY = 0.12;

    public readonly string $text;

    public readonly float $opacity;

    public function __construct(string $text = '', float $opacity = self::DEFAULT_OPACITY)
    {
        $this->text = self::normalize($text);
        $this->opacity = max(self::MIN_OPACITY, min(self::MAX_OPACITY, $opacity));
    }

    public static function fromArray(array $raw): self
    {
        $opacity = $raw['opacity'] ?? null;

        return new self(
            is_string($raw['text'] ?? null) ? $raw['text'] : '',
            is_numeric($opacity) ? (float) $opacity : self::DEFAULT_OPACITY,
        );
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /** Salinan dengan teks lain; opasitas tetap. String kosong mematikan watermark. */
    public function withText(string $text): self
    {
        return new self($text, $this->opacity);
    }

    public function toArray(): array
    {
        return ['text' => $this->text, 'opacity' => $this->opacity];
    }

    private static function normalize(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8');
    }
}
