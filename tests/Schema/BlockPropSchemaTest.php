<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\BlockPropSchema;
use Maqiis\DocumentBuilder\Schema\BlockType;
use PHPUnit\Framework\TestCase;

/**
 * label()/group()/valueLabel()/grouped() adalah metadata tampilan (dipakai
 * panel penyunting untuk label field, tab grup, dan opsi <select>)
 * yang sengaja dibuat di package ini, bukan di aplikasi konsumen — supaya
 * aplikasi lain yang memakai package ini tidak perlu mendefinisikan ulang,
 * cukup menyediakan tempat merender field-nya.
 */
class BlockPropSchemaTest extends TestCase
{
    public function test_label_returns_the_key_itself_when_unmapped(): void
    {
        $this->assertSame('Perataan', BlockPropSchema::label('align'));
        $this->assertSame('propertiAsing', BlockPropSchema::label('propertiAsing'));
    }

    public function test_group_falls_back_to_lainnya_when_unmapped(): void
    {
        $this->assertSame('Konten', BlockPropSchema::group('text'));
        $this->assertSame('Tata Letak', BlockPropSchema::group('align'));
        $this->assertSame('Lainnya', BlockPropSchema::group('propertiAsing'));
    }

    public function test_value_label_returns_the_value_itself_when_unmapped(): void
    {
        $this->assertSame('Kiri', BlockPropSchema::valueLabel('left'));
        $this->assertSame('Ikut posisi blok', BlockPropSchema::valueLabel(''));
        $this->assertSame('nilaiAsing', BlockPropSchema::valueLabel('nilaiAsing'));
    }

    public function test_grouped_preserves_every_prop_key_of_every_block_type(): void
    {
        foreach (BlockType::cases() as $type) {
            $definitions = BlockPropSchema::for($type);
            $grouped = BlockPropSchema::grouped($definitions);

            $flatKeys = [];
            foreach ($grouped as $groupKeys) {
                $flatKeys = [...$flatKeys, ...array_keys($groupKeys)];
            }

            // Pengelompokan sengaja mengubah urutan (properti disusun ulang per
            // grup context), jadi yang dibandingkan himpunan kuncinya, bukan urutan.
            $this->assertEqualsCanonicalizing(
                array_keys($definitions),
                $flatKeys,
                "Properti tipe blok '{$type->value}' hilang atau bertambah saat dikelompokkan"
            );
        }
    }

    public function test_grouped_orders_sections_by_the_fixed_group_order(): void
    {
        $grouped = BlockPropSchema::grouped(BlockPropSchema::for(BlockType::Letterhead));

        $this->assertSame(['Konten', 'Tata Letak', 'Ukuran & Jarak', 'Tampilan'], array_keys($grouped));
    }

    public function test_grouped_omits_sections_with_no_matching_props(): void
    {
        // Spacer cuma punya satu properti ('heightMm'), jadi cuma satu sektor
        // ("Ukuran & Jarak") yang seharusnya muncul, bukan keempatnya.
        $grouped = BlockPropSchema::grouped(BlockPropSchema::for(BlockType::Spacer));

        $this->assertSame(['Ukuran & Jarak'], array_keys($grouped));
    }
}
