<?php

namespace Maqiis\DocumentBuilder\Variable;

final class VariableRegistry
{
    /** @var array<string,array{path:string,label:string,sample:string,group:string}> */
    private array $entries = [];

    public function define(string $path, string $label, string $sample, string $group = 'Umum'): self
    {
        $this->entries[$path] = compact('path', 'label', 'sample', 'group');

        return $this;
    }

    /** @return list<array{path:string,label:string,sample:string,group:string}> */
    public function all(): array
    {
        return array_values($this->entries);
    }

    /** @return array<string,list<array{path:string,label:string,sample:string,group:string}>> */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->entries as $entry) {
            $groups[$entry['group']][] = $entry;
        }

        return $groups;
    }

    public function has(string $path): bool
    {
        return isset($this->entries[$path]);
    }

    /** Resolver berisi nilai contoh — dipakai untuk preview di builder. */
    public function sampleResolver(): VariableResolver
    {
        $samples = [];

        foreach ($this->entries as $path => $entry) {
            $samples[$path] = $entry['sample'];
        }

        return new class($samples) implements VariableResolver
        {
            public function __construct(private readonly array $samples) {}

            public function resolve(string $path): ?string
            {
                return $this->samples[$path] ?? null;
            }
        };
    }
}
