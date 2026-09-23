<?php

namespace Maqiis\DocumentBuilder\Variable;

/**
 * Mengikat satu butir koleksi ke sebuah alias: di dalam blok berulang,
 * `{{ recipient.name }}` dibaca dari butir yang sedang dirender, sedangkan
 * path lain (`{{ letter.subject }}`) tetap diteruskan ke resolver dokumen.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class ScopedVariableResolver implements CollectionVariableResolver
{
    public function __construct(
        private readonly string $alias,
        private readonly VariableResolver $item,
        private readonly VariableResolver $parent,
    ) {}

    public function resolve(string $path): ?string
    {
        $prefix = $this->alias.'.';

        return str_starts_with($path, $prefix)
            ? $this->item->resolve(substr($path, strlen($prefix)))
            : $this->parent->resolve($path);
    }

    public function collection(string $name): ?array
    {
        return $this->parent instanceof CollectionVariableResolver
            ? $this->parent->collection($name)
            : null;
    }
}
