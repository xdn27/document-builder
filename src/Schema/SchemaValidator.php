<?php

namespace Maqiis\DocumentBuilder\Schema;

/**
 * Validasi bersifat whitelist. Kesalahan struktural — tipe blok tak dikenal, id kosong,
 * id ganda, lebar kolom berlebih — ditolak. Kesalahan nilai — properti asing, angka di
 * luar rentang, nilai enum tak dikenal — diperbaiki diam-diam supaya template tetap
 * bisa dibuka dan disunting.
 */
final class SchemaValidator
{
    /**
     * Schema dikirim utuh di setiap round-trip preview (Livewire mengirim
     * seluruh state, konsumen non-Blade mem-POST seluruh schema), jadi ukurannya
     * berpengaruh langsung ke latensi tiap ketukan — bukan cuma ke baris DB.
     * Penyebab pembengkakan yang praktis selalu terjadi adalah gambar tertanam
     * sebagai data URI; karena itu pesan galatnya menyebut gambar.
     *
     * Batas ini hanya masuk akal ditegakkan saat MENERIMA tulisan baru — bukan
     * saat membaca yang sudah tersimpan. Lihat $maxBytes di validate(): default
     * di sini berlaku untuk pemanggil langsung (mis. kode konsumen yang
     * memvalidasi schema baru langsung lewat validate()); Template::fromArray() — jalur baca
     * yang dipakai mount()/preview()/cetak/PDF — sengaja TIDAK ikut default ini.
     */
    public const MAX_BYTES = 262144;

    /** @var array<string,string> */
    private array $errors = [];

    /**
     * $maxBytes null berarti TIDAK ADA batas ukuran yang ditegakkan — dipakai
     * jalur baca. Tanpa parameter (bawaan self::MAX_BYTES), batas tetap
     * ditegakkan seperti sebelumnya untuk pemanggil yang tidak menyebutkan apa
     * pun secara eksplisit.
     */
    public static function validate(array $raw, ?int $maxBytes = self::MAX_BYTES): Template
    {
        return (new self)->run($raw, $maxBytes);
    }

    private function run(array $raw, ?int $maxBytes): Template
    {
        if ($maxBytes !== null) {
            $encoded = json_encode($raw);

            if ($encoded === false) {
                throw new SchemaValidationException([
                    'size' => 'Schema tidak bisa dibaca sebagai JSON.',
                ]);
            }

            if (strlen($encoded) > $maxBytes) {
                throw new SchemaValidationException([
                    'size' => sprintf(
                        'Ukuran schema %d KB melewati batas %d KB. Penyebab tersering: gambar yang ditanam langsung ke template — unggah gambarnya sebagai berkas alih-alih menempelkannya.',
                        (int) round(strlen($encoded) / 1024),
                        (int) round($maxBytes / 1024),
                    ),
                ]);
            }
        }

        $version = $raw['version'] ?? null;

        if (! is_int($version) || $version !== Template::CURRENT_VERSION) {
            $this->errors['version'] = sprintf(
                'Versi schema harus %d, diterima %s.',
                Template::CURRENT_VERSION,
                var_export($version, true),
            );
        }

        $zones = is_array($raw['zones'] ?? null) ? $raw['zones'] : [];

        $header = $this->zone('header', $zones['header'] ?? [], ZoneRepeat::All);
        $body = $this->zone('body', $zones['body'] ?? [], ZoneRepeat::All);
        $footer = $this->zone('footer', $zones['footer'] ?? [], ZoneRepeat::All);

        if ($this->errors !== []) {
            throw new SchemaValidationException($this->errors);
        }

        return new Template(
            Template::CURRENT_VERSION,
            PageSetup::fromArray(is_array($raw['page'] ?? null) ? $raw['page'] : []),
            DocumentStyle::fromArray(is_array($raw['style'] ?? null) ? $raw['style'] : []),
            $header,
            $body,
            $footer,
        );
    }

    private function zone(string $name, mixed $raw, ZoneRepeat $fallback): Zone
    {
        $raw = is_array($raw) ? $raw : [];
        $path = "zones.{$name}";

        $repeat = $name === 'body'
            ? ZoneRepeat::All
            : (ZoneRepeat::tryFrom((string) ($raw['repeat'] ?? '')) ?? $fallback);

        $height = 'auto';
        if ($name !== 'body' && isset($raw['height']) && is_numeric($raw['height'])) {
            $height = max(0.0, min(150.0, (float) $raw['height']));
        }

        $blocks = [];
        $seenIds = [];
        $rawBlocks = is_array($raw['blocks'] ?? null) ? array_values($raw['blocks']) : [];

        foreach ($rawBlocks as $index => $rawBlock) {
            $block = $this->block("{$path}.blocks.{$index}", $rawBlock, $seenIds);

            if ($block !== null) {
                $seenIds[$block->id] = true;
                $blocks[] = $block;
            }
        }

        // Kop gambar menembus margin atas dengan margin negatif. Zona bertinggi
        // tetap dipasangi overflow:hidden oleh paginator dan akan memotongnya,
        // jadi zona yang memuatnya dipaksa "auto" — diperbaiki diam-diam seperti
        // nilai salah lain, bukan ditolak.
        if ($name !== 'body' && $this->hasLetterheadImage($blocks)) {
            $height = 'auto';
        }

        return new Zone($name, $repeat, $height, $blocks);
    }

    /** @param  Block[]  $blocks */
    private function hasLetterheadImage(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block->type === BlockType::LetterheadImage) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string,bool>  $seenIds */
    private function block(string $path, mixed $raw, array $seenIds): ?Block
    {
        if (! is_array($raw)) {
            $this->errors[$path] = 'Blok harus berupa objek.';

            return null;
        }

        $id = $raw['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            $this->errors["{$path}.id"] = 'Blok wajib memiliki id berupa teks tidak kosong.';

            return null;
        }

        if (isset($seenIds[$id])) {
            $this->errors["{$path}.id"] = sprintf('Id blok "%s" dipakai lebih dari sekali.', $id);

            return null;
        }

        $type = BlockType::tryFrom((string) ($raw['type'] ?? ''));

        if ($type === null) {
            $this->errors["{$path}.type"] = sprintf(
                'Tipe blok "%s" tidak dikenal.',
                is_scalar($raw['type'] ?? null) ? (string) $raw['type'] : '?',
            );

            return null;
        }

        $props = $this->props($path, $type, is_array($raw['props'] ?? null) ? $raw['props'] : []);

        return new Block($id, $type, $props);
    }

    private function props(string $path, BlockType $type, array $raw): array
    {
        if ($type === BlockType::Table) {
            if (isset($raw['hideHeader']) && ! isset($raw['showHeader'])) {
                $raw['showHeader'] = ! (bool) $raw['hideHeader'];
            }
            if (isset($raw['spaceBeforeMm']) && ! isset($raw['marginTopMm'])) {
                $raw['marginTopMm'] = $raw['spaceBeforeMm'];
            }
            if (isset($raw['spaceAfterMm']) && ! isset($raw['marginBottomMm'])) {
                $raw['marginBottomMm'] = $raw['spaceAfterMm'];
            }
        }

        $definitions = BlockPropSchema::for($type);
        $props = [];

        foreach ($definitions as $key => $definition) {
            $props[$key] = $this->coerce($raw[$key] ?? null, $definition);
        }

        if ($type === BlockType::Table) {
            $total = array_sum(array_map(
                static fn (array $column): float => (float) $column['widthPercent'],
                $props['columns'],
            ));

            if ($total > 100.5) {
                $this->errors["{$path}.props.columns"] = sprintf(
                    'Total lebar kolom %.1f%% melebihi 100%%.',
                    $total,
                );
            }
        }

        return $props;
    }

    private function coerce(mixed $value, array $definition): mixed
    {
        return match ($definition['type']) {
            'bool' => is_bool($value) ? $value : (bool) ($definition['default']),
            'string', 'image' => is_string($value) ? $value : (is_scalar($value) ? (string) $value : $definition['default']),
            'float' => is_numeric($value)
                ? max((float) $definition['min'], min((float) $definition['max'], (float) $value))
                : (float) $definition['default'],
            'enum' => in_array($value, $definition['values'], true) ? $value : $definition['default'],
            'rows' => $this->rows($value, $definition['keys']),
            'matrix' => $this->matrix($value),
            default => $definition['default'],
        };
    }

    /** @param  array<string,mixed>  $keys */
    private function rows(mixed $value, array $keys): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach (array_values($value) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];

            foreach ($keys as $key => $default) {
                $raw = $row[$key] ?? $default;
                $clean[$key] = is_scalar($raw) ? $raw : $default;
            }

            $rows[] = $clean;
        }

        return $rows;
    }

    private function matrix(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $matrix = [];

        foreach (array_values($value) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $matrix[] = array_map(
                static fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '',
                array_values($row),
            );
        }

        return $matrix;
    }
}
