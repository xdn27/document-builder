<?php

namespace Maqiis\DocumentBuilder\Schema;

final class DocumentStyle
{
    public const MIN_FONT_PT = 8.0;

    public const MAX_FONT_PT = 24.0;

    public const MIN_LINE_HEIGHT = 1.0;

    public const MAX_LINE_HEIGHT = 3.0;

    public function __construct(
        public readonly string $fontFamily,
        public readonly float $fontSize,
        public readonly float $lineHeight,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            is_string($raw['fontFamily'] ?? null) && $raw['fontFamily'] !== '' ? $raw['fontFamily'] : 'tinos',
            self::clamp($raw['fontSize'] ?? 12.0, self::MIN_FONT_PT, self::MAX_FONT_PT, 12.0),
            self::clamp($raw['lineHeight'] ?? 1.5, self::MIN_LINE_HEIGHT, self::MAX_LINE_HEIGHT, 1.5),
        );
    }

    public function toArray(): array
    {
        return [
            'fontFamily' => $this->fontFamily,
            'fontSize' => $this->fontSize,
            'lineHeight' => $this->lineHeight,
        ];
    }

    private static function clamp(mixed $value, float $min, float $max, float $fallback): float
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (float) $value));
    }
}
