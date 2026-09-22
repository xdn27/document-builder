<?php

namespace Maqiis\DocumentBuilder\Schema;

/**
 * Daftar properti yang sah untuk setiap tipe blok. Validator memakainya sebagai
 * whitelist, dan renderer blok membaca nama properti yang sama — jadi berkas ini
 * adalah satu-satunya tempat nama properti didefinisikan.
 */
final class BlockPropSchema
{
    public const ALIGNMENTS = ['left', 'center', 'right'];

    public const TEXT_ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public const DIRECTIONS = ['ltr', 'rtl'];

    /** @return array<string,array<string,mixed>> */
    public static function for(BlockType $type): array
    {
        return match ($type) {
            BlockType::Letterhead => [
                'showLogo' => ['type' => 'bool', 'default' => true],
                'logo' => ['type' => 'string', 'default' => ''],
                'logoHeightMm' => ['type' => 'float', 'default' => 22.0, 'min' => 5.0, 'max' => 60.0],
                'line1' => ['type' => 'string', 'default' => ''],
                'line2' => ['type' => 'string', 'default' => ''],
                'line3' => ['type' => 'string', 'default' => ''],
                'line4' => ['type' => 'string', 'default' => ''],
                'align' => ['type' => 'enum', 'default' => 'center', 'values' => self::ALIGNMENTS],
                'rule' => ['type' => 'enum', 'default' => 'double', 'values' => ['double', 'single', 'none']],
            ],
            BlockType::LetterheadImage => [
                'src' => ['type' => 'image', 'default' => ''],
                'alt' => ['type' => 'string', 'default' => ''],
            ],
            BlockType::LetterMeta => [
                'rows' => ['type' => 'rows', 'default' => [], 'keys' => ['label' => '', 'value' => '']],
                'labelWidthMm' => ['type' => 'float', 'default' => 25.0, 'min' => 10.0, 'max' => 80.0],
                'separator' => ['type' => 'string', 'default' => ':'],
                'rightText' => ['type' => 'string', 'default' => ''],
                'rightAlign' => ['type' => 'enum', 'default' => 'right', 'values' => self::ALIGNMENTS],
            ],
            BlockType::Paragraph => [
                'text' => ['type' => 'string', 'default' => ''],
                'align' => ['type' => 'enum', 'default' => 'justify', 'values' => self::TEXT_ALIGNMENTS],
                'direction' => ['type' => 'enum', 'default' => 'ltr', 'values' => self::DIRECTIONS],
                'indentMm' => ['type' => 'float', 'default' => 0.0, 'min' => 0.0, 'max' => 50.0],
                'spaceBeforeMm' => ['type' => 'float', 'default' => 0.0, 'min' => 0.0, 'max' => 50.0],
                'spaceAfterMm' => ['type' => 'float', 'default' => 3.0, 'min' => 0.0, 'max' => 50.0],
            ],
            BlockType::Signature => [
                'columns' => ['type' => 'rows', 'default' => [], 'keys' => [
                    'place' => '', 'date' => '', 'position' => '', 'signature' => '', 'name' => '', 'nip' => '',
                ]],
                'align' => ['type' => 'enum', 'default' => 'right', 'values' => ['left', 'center', 'right', 'space-between']],
                // Kosong berarti ikut 'align' — persis perilaku lama, supaya template
                // yang sudah ada tidak berubah tampilannya. Diisi eksplisit hanya saat
                // perataan teks perlu berbeda dari posisi blok (lihat 'widthPercent').
                'textAlign' => ['type' => 'enum', 'default' => '', 'values' => ['', 'left', 'center', 'right']],
                // 100 (bawaan) = tabel selebar penuh seperti sebelumnya. Di bawah itu,
                // tabel menyusut dan didorong ke sisi yang dipilih 'align' lewat margin
                // — lihat SignatureRenderer::tableStyle(). Tidak berlaku untuk
                // align:space-between, yang memang harus selebar penuh.
                'widthPercent' => ['type' => 'float', 'default' => 100.0, 'min' => 20.0, 'max' => 100.0],
                'spaceMm' => ['type' => 'float', 'default' => 25.0, 'min' => 0.0, 'max' => 60.0],
                'spaceBeforeMm' => ['type' => 'float', 'default' => 8.0, 'min' => 0.0, 'max' => 50.0],
            ],
            BlockType::Table => [
                'columns' => ['type' => 'rows', 'default' => [], 'keys' => [
                    'label' => '', 'widthPercent' => 0, 'align' => 'left',
                ]],
                'rows' => ['type' => 'matrix', 'default' => []],
                'repeatHeader' => ['type' => 'bool', 'default' => true],
                'headerBold' => ['type' => 'bool', 'default' => true],
                'border' => ['type' => 'enum', 'default' => 'all', 'values' => ['all', 'horizontal', 'none']],
                'fontSizePt' => ['type' => 'float', 'default' => 0.0, 'min' => 0.0, 'max' => 18.0],
            ],
            BlockType::ListBlock => [
                'items' => ['type' => 'rows', 'default' => [], 'keys' => ['text' => '', 'level' => 0]],
                'style' => ['type' => 'enum', 'default' => 'bullet', 'values' => ['bullet', 'number']],
                'indentMm' => ['type' => 'float', 'default' => 8.0, 'min' => 0.0, 'max' => 30.0],
                'spaceAfterMm' => ['type' => 'float', 'default' => 3.0, 'min' => 0.0, 'max' => 50.0],
            ],
            BlockType::Image => [
                'src' => ['type' => 'image', 'default' => ''],
                'alt' => ['type' => 'string', 'default' => ''],
                'widthMm' => ['type' => 'float', 'default' => 50.0, 'min' => 5.0, 'max' => 200.0],
                'align' => ['type' => 'enum', 'default' => 'left', 'values' => self::ALIGNMENTS],
            ],
            BlockType::QrCode => [
                'payload' => ['type' => 'string', 'default' => ''],
                'sizeMm' => ['type' => 'float', 'default' => 30.0, 'min' => 10.0, 'max' => 80.0],
                'align' => ['type' => 'enum', 'default' => 'left', 'values' => self::ALIGNMENTS],
            ],
            BlockType::Spacer => [
                'heightMm' => ['type' => 'float', 'default' => 10.0, 'min' => 0.0, 'max' => 200.0],
            ],
            BlockType::Divider => [
                'thicknessMm' => ['type' => 'float', 'default' => 0.3, 'min' => 0.1, 'max' => 5.0],
                'style' => ['type' => 'enum', 'default' => 'solid', 'values' => ['solid', 'dashed', 'dotted', 'double']],
                'widthPercent' => ['type' => 'float', 'default' => 100.0, 'min' => 10.0, 'max' => 100.0],
                'align' => ['type' => 'enum', 'default' => 'left', 'values' => self::ALIGNMENTS],
            ],
        };
    }

    /** Nilai awal setiap properti untuk tipe blok tertentu. */
    public static function defaults(BlockType $type): array
    {
        $defaults = [];

        foreach (self::for($type) as $key => $definition) {
            $defaults[$key] = $definition['default'];
        }

        return $defaults;
    }

    /**
     * Metadata tampilan kini dimiliki PropCatalog; keempat method di bawah
     * dipertahankan sebagai delegasi supaya pemanggil yang sudah ada (Blade
     * inspector, test) tidak perlu berubah. Butuh penerjemah? Pakai PropCatalog
     * langsung.
     */
    private static function catalog(): PropCatalog
    {
        return new PropCatalog;
    }

    public static function label(string $key): string
    {
        return self::catalog()->label($key);
    }

    public static function valueLabel(string $value): string
    {
        return self::catalog()->valueLabel($value);
    }

    public static function group(string $key): string
    {
        return self::catalog()->group($key);
    }

    /**
     * @param  array<string,array<string,mixed>>  $definitions
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function grouped(array $definitions): array
    {
        return self::catalog()->grouped($definitions);
    }
}
