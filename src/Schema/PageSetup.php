<?php

namespace Maqiis\DocumentBuilder\Schema;

final class PageSetup
{
    public function __construct(
        public readonly PageSize $size,
        public readonly Orientation $orientation,
        public readonly Margin $margin,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            PageSize::tryFrom((string) ($raw['size'] ?? 'A4')) ?? PageSize::A4,
            Orientation::tryFrom((string) ($raw['orientation'] ?? 'portrait')) ?? Orientation::Portrait,
            Margin::fromArray(is_array($raw['margin'] ?? null) ? $raw['margin'] : []),
        );
    }

    public function widthMm(): float
    {
        return $this->orientation === Orientation::Landscape
            ? $this->size->heightMm()
            : $this->size->widthMm();
    }

    public function heightMm(): float
    {
        return $this->orientation === Orientation::Landscape
            ? $this->size->widthMm()
            : $this->size->heightMm();
    }

    public function contentWidthMm(): float
    {
        return $this->widthMm() - $this->margin->left - $this->margin->right;
    }

    public function contentHeightMm(): float
    {
        return $this->heightMm() - $this->margin->top - $this->margin->bottom;
    }

    public function toArray(): array
    {
        return [
            'size' => $this->size->value,
            'orientation' => $this->orientation->value,
            'margin' => array_map(
                static fn (float $mm): int|float => $mm === floor($mm) ? (int) $mm : $mm,
                $this->margin->toArray(),
            ),
        ];
    }
}
