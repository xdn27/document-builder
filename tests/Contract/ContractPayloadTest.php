<?php

namespace Maqiis\DocumentBuilder\Tests\Contract;

use Maqiis\DocumentBuilder\Contract\ContractPayload;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Payload ini satu-satunya yang perlu dikirim sebuah controller supaya UI non-Blade
 * bisa membangun palette dan inspector penuh. Kalau test ini perlu ditambah
 * kunci baru di masa depan, itu perubahan MAJOR: konsumen React menyandarkan
 * bentuknya pada 'contract' (spec §16.5, §17.3).
 */
class ContractPayloadTest extends TestCase
{
    private function registry(): VariableRegistry
    {
        return (new VariableRegistry)
            ->define('institution.name', 'Nama Lembaga', 'Pesantren Contoh', 'Lembaga')
            ->define('letter.number', 'Nomor Surat', '001/IX/2026', 'Surat');
    }

    public function test_it_exposes_exactly_the_five_documented_top_level_keys(): void
    {
        $payload = (new ContractPayload)->forRegistry($this->registry());

        $this->assertSame(
            ['contract', 'blockTypes', 'blockPropSchema', 'fonts', 'variables'],
            array_keys($payload)
        );
        $this->assertSame(ContractPayload::VERSION, $payload['contract']);
    }

    public function test_block_types_carry_value_and_label_for_the_palette(): void
    {
        $payload = (new ContractPayload)->forRegistry($this->registry());

        $this->assertCount(count(BlockType::cases()), $payload['blockTypes']);
        $this->assertSame(['value' => 'paragraph', 'label' => 'Paragraf'], $payload['blockTypes'][3]);
    }

    public function test_prop_schema_is_keyed_by_block_type_value(): void
    {
        $payload = (new ContractPayload)->forRegistry($this->registry());

        foreach (BlockType::cases() as $type) {
            $this->assertArrayHasKey($type->value, $payload['blockPropSchema']);
        }

        $this->assertSame('Teks', $payload['blockPropSchema']['paragraph']['Konten']['text']['label']);
    }

    public function test_it_carries_fonts_and_grouped_variables(): void
    {
        $payload = (new ContractPayload)->forRegistry($this->registry());

        $this->assertArrayHasKey('tinos', $payload['fonts']);
        $this->assertSame('Tinos (metrik Times New Roman)', $payload['fonts']['tinos']['label']);
        $this->assertSame(['Lembaga', 'Surat'], array_keys($payload['variables']));
        $this->assertSame('institution.name', $payload['variables']['Lembaga'][0]['path']);
    }

    public function test_the_whole_payload_survives_a_json_round_trip_unchanged(): void
    {
        $payload = (new ContractPayload)->forRegistry($this->registry());
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        // assertEquals, bukan assertSame: PHP json_encode() tidak membedakan
        // float bulat (22.0) dari int (22), dan itu bukan kehilangan data untuk
        // konsumen JS/React — keduanya sama saja di sana. Yang diuji di sini
        // adalah tidak ada NILAI yang hilang, bukan tipe PHP-nya persis.
        $this->assertEquals(
            $payload,
            json_decode($json, true),
            'Ada nilai non-serializable menyelinap ke kontrak — enum, objek, atau closure'
        );
    }
}
