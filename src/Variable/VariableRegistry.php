<?php

namespace Maqiis\DocumentBuilder\Variable;

final class VariableRegistry
{
    /** @var array<string,array{path:string,label:string,sample:string,group:string}> */
    private array $entries = [];

    /** @var array<string,list<array<string,mixed>>> */
    private array $collections = [];

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

    /**
     * Contoh butir koleksi untuk kanvas builder (mis. dua penerima), supaya blok
     * berulang terlihat berulang saat mendesain. Isinya bentuk yang sama dengan
     * data koleksi saat mencetak: satu array per butir, path relatif butir.
     *
     * @param  list<array<string,mixed>>  $samples
     */
    public function defineCollection(string $name, array $samples): self
    {
        $this->collections[$name] = array_values($samples);

        return $this;
    }

    public function hasCollection(string $name): bool
    {
        return isset($this->collections[$name]);
    }

    public function has(string $path): bool
    {
        return isset($this->entries[$path]);
    }

    /** Resolver berisi nilai contoh — dipakai untuk preview di builder. */
    public function sampleResolver(): CollectionVariableResolver
    {
        $samples = [];

        foreach ($this->entries as $path => $entry) {
            $samples[$path] = $entry['sample'];
        }

        return new class($samples, $this->collections) implements CollectionVariableResolver
        {
            public function __construct(private readonly array $samples, private readonly array $collections) {}

            public function resolve(string $path): ?string
            {
                return $this->samples[$path] ?? null;
            }

            public function collection(string $name): ?array
            {
                return isset($this->collections[$name])
                    ? (new ArrayVariableResolver([$name => $this->collections[$name]]))->collection($name)
                    : null;
            }
        };
    }
}
