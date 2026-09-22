<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\BlockPropSchema;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\LabelTranslator;
use Maqiis\DocumentBuilder\Schema\PropCatalog;
use PHPUnit\Framework\TestCase;

/**
 * PropCatalog adalah versi bisa-diinstansiasi dari metadata tampilan yang dulu
 * jadi konstanta privat BlockPropSchema. Dua hal yang dijaga test ini: tanpa
 * penerjemah, teksnya HARUS identik dengan sebelum pemindahan (nol perubahan
 * perilaku), dan dengan penerjemah, setiap label boleh diganti tanpa core
 * menyentuh Laravel.
 */
class PropCatalogTest extends TestCase
{
    public function test_without_translator_it_returns_the_indonesian_defaults(): void
    {
        $catalog = new PropCatalog;

        $this->assertSame('Perataan', $catalog->label('align'));
        $this->assertSame('Teks', $catalog->label('text'));
        $this->assertSame('Kiri', $catalog->valueLabel('left'));
        $this->assertSame('Ikut posisi blok', $catalog->valueLabel(''));
        $this->assertSame('Konten', $catalog->group('text'));
    }

    public function test_unmapped_keys_fall_back_to_the_key_itself_and_lainnya(): void
    {
        $catalog = new PropCatalog;

        $this->assertSame('propertiAsing', $catalog->label('propertiAsing'));
        $this->assertSame('nilaiAsing', $catalog->valueLabel('nilaiAsing'));
        $this->assertSame('Lainnya', $catalog->group('propertiAsing'));
    }

    public function test_static_delegates_return_exactly_the_same_strings(): void
    {
        $catalog = new PropCatalog;

        // Paritas eksplisit: kalau salah satu jalur berubah, ini yang gagal
        // lebih dulu sebelum ada panel yang menampilkan teks berbeda.
        foreach (['align', 'text', 'rows', 'widthPercent', 'propertiAsing'] as $key) {
            $this->assertSame(BlockPropSchema::label($key), $catalog->label($key), "label({$key})");
            $this->assertSame(BlockPropSchema::group($key), $catalog->group($key), "group({$key})");
        }

        foreach (['left', 'center', '', 'rtl', 'none', 'nilaiAsing'] as $value) {
            $this->assertSame(BlockPropSchema::valueLabel($value), $catalog->valueLabel($value), "valueLabel({$value})");
        }
    }

    public function test_a_translator_overrides_labels_through_stable_keys(): void
    {
        $catalog = new PropCatalog(new class implements LabelTranslator
        {
            public function translate(string $key, string $fallback): string
            {
                return match ($key) {
                    'prop.align' => 'Alignment',
                    'value.left' => 'Left',
                    'group.Konten' => 'Content',
                    default => $fallback,
                };
            }
        });

        $this->assertSame('Alignment', $catalog->label('align'));
        $this->assertSame('Left', $catalog->valueLabel('left'));
        $this->assertSame('Content', $catalog->group('text'));
        $this->assertSame('Teks', $catalog->label('text'), 'kunci tak dikenal harus jatuh ke teks Indonesia');
    }

    public function test_block_type_label_accepts_the_same_translator(): void
    {
        $translator = new class implements LabelTranslator
        {
            public function translate(string $key, string $fallback): string
            {
                return $key === 'block.paragraph' ? 'Paragraph' : $fallback;
            }
        };

        $this->assertSame('Paragraf', BlockType::Paragraph->label());
        $this->assertSame('Paragraph', BlockType::Paragraph->label($translator));
        $this->assertSame('Tabel', BlockType::Table->label($translator));
    }

    public function test_grouped_still_preserves_every_prop_key(): void
    {
        $catalog = new PropCatalog;

        foreach (BlockType::cases() as $type) {
            $definitions = BlockPropSchema::for($type);

            $flat = [];
            foreach ($catalog->grouped($definitions) as $keys) {
                $flat = [...$flat, ...array_keys($keys)];
            }

            $this->assertEqualsCanonicalizing(array_keys($definitions), $flat, $type->value);
        }
    }

    public function test_describe_carries_definition_and_display_metadata_together(): void
    {
        $described = (new PropCatalog)->describe(BlockType::Paragraph);

        $this->assertSame(['Konten', 'Tata Letak', 'Ukuran & Jarak'], array_keys($described));

        $text = $described['Konten']['text'];
        $this->assertSame('string', $text['type']);
        $this->assertSame('', $text['default']);
        $this->assertSame('Teks', $text['label']);

        $align = $described['Tata Letak']['align'];
        $this->assertSame('enum', $align['type']);
        $this->assertSame(['left', 'center', 'right', 'justify'], $align['values']);
        $this->assertSame(
            ['left' => 'Kiri', 'center' => 'Tengah', 'right' => 'Kanan', 'justify' => 'Rata kanan-kiri'],
            $align['valueLabels'],
            'React membangun <select> dari sini; tanpa valueLabels ia menulis ulang label Indonesia'
        );

        $indent = $described['Ukuran & Jarak']['indentMm'];
        $this->assertSame(0.0, $indent['min']);
        $this->assertSame(50.0, $indent['max']);
    }

    public function test_describe_includes_row_keys_for_repeatable_props(): void
    {
        $described = (new PropCatalog)->describe(BlockType::LetterMeta);

        $this->assertSame('rows', $described['Konten']['rows']['type']);
        $this->assertSame(['label' => '', 'value' => ''], $described['Konten']['rows']['keys']);
    }

    public function test_describe_omits_keys_a_prop_does_not_have(): void
    {
        $spacer = (new PropCatalog)->describe(BlockType::Spacer);
        $height = $spacer['Ukuran & Jarak']['heightMm'];

        $this->assertArrayNotHasKey('values', $height);
        $this->assertArrayNotHasKey('valueLabels', $height);
        $this->assertArrayNotHasKey('keys', $height);
    }

    public function test_describe_is_json_clean_for_every_block_type(): void
    {
        $catalog = new PropCatalog;

        foreach (BlockType::cases() as $type) {
            $described = $catalog->describe($type);
            $json = json_encode($described, JSON_THROW_ON_ERROR);

            // assertEquals, bukan assertSame: PHP json_encode() tidak membedakan
            // float bulat (22.0) dari int (22) — "22" di JSON dibaca balik sebagai
            // int. Itu bukan kehilangan data untuk konsumen JS/React (keduanya
            // sama saja di sana); yang diuji di sini adalah tidak ada NILAI yang
            // hilang atau berubah bentuk, bukan tipe PHP-nya persis.
            $this->assertEquals(
                $described,
                json_decode($json, true),
                "describe({$type->value}) berubah bentuk saat lewat JSON — ada nilai yang tidak serializable"
            );
        }
    }

    public function test_describe_labels_follow_the_translator(): void
    {
        $described = (new PropCatalog(new class implements LabelTranslator
        {
            public function translate(string $key, string $fallback): string
            {
                return $key === 'prop.text' ? 'Body' : $fallback;
            }
        }))->describe(BlockType::Paragraph);

        $this->assertSame('Body', $described['Konten']['text']['label']);
    }
}
