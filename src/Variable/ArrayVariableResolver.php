<?php

namespace Maqiis\DocumentBuilder\Variable;

final class ArrayVariableResolver implements CollectionVariableResolver
{
    public function __construct(private readonly array $data) {}

    public function resolve(string $path): ?string
    {
        $current = $this->walk($path);

        return is_scalar($current) ? (string) $current : null;
    }

    /** List berisi array (mis. 'recipients' => [[...], [...]]) dibaca sebagai koleksi. */
    public function collection(string $name): ?array
    {
        $current = $this->walk($name);

        if (! is_array($current) || ! array_is_list($current)) {
            return null;
        }

        foreach ($current as $item) {
            if (! is_array($item)) {
                return null;
            }
        }

        return array_map(static fn (array $item): VariableResolver => new self($item), $current);
    }

    private function walk(string $path): mixed
    {
        $current = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
