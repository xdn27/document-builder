<?php

namespace Maqiis\DocumentBuilder\Schema;

final class Margin
{
    public const MIN_MM = 0.0;

    public const MAX_MM = 50.0;

    public const DEFAULT_MM = 20.0;

    public function __construct(
        public readonly float $top,
        public readonly float $right,
        public readonly float $bottom,
        public readonly float $left,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            self::clamp($raw['top'] ?? self::DEFAULT_MM),
            self::clamp($raw['right'] ?? self::DEFAULT_MM),
            self::clamp($raw['bottom'] ?? self::DEFAULT_MM),
            self::clamp($raw['left'] ?? self::DEFAULT_MM),
        );
    }

    public function toArray(): array
    {
        return [
            'top' => $this->top,
            'right' => $this->right,
            'bottom' => $this->bottom,
            'left' => $this->left,
        ];
    }

    private static function clamp(mixed $value): float
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_MM;
        }

        return max(self::MIN_MM, min(self::MAX_MM, (float) $value));
    }
}
