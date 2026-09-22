<?php

namespace Maqiis\DocumentBuilder\Schema;

final class Block
{
    public function __construct(
        public readonly string $id,
        public readonly BlockType $type,
        public readonly array $props,
    ) {}

    public function prop(string $key, mixed $default = null): mixed
    {
        return $this->props[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'props' => $this->props,
        ];
    }
}
