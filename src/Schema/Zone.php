<?php

namespace Maqiis\DocumentBuilder\Schema;

final class Zone
{
    /** @param Block[] $blocks */
    public function __construct(
        public readonly string $name,
        public readonly ZoneRepeat $repeat,
        public readonly float|string $height,
        public readonly array $blocks,
    ) {}

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    public function toArray(): array
    {
        $array = ['blocks' => array_map(static fn (Block $b): array => $b->toArray(), $this->blocks)];

        if ($this->name !== 'body') {
            $array = [
                'repeat' => $this->repeat->value,
                'height' => $this->height,
            ] + $array;
        }

        return $array;
    }
}
