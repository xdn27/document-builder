<?php

namespace Maqiis\DocumentBuilder\Variable;

final class ArrayVariableResolver implements VariableResolver
{
    public function __construct(private readonly array $data) {}

    public function resolve(string $path): ?string
    {
        $current = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : null;
    }
}
