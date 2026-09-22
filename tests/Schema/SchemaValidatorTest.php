<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\SchemaValidationException;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Schema\ZoneRepeat;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    private function validRaw(array $override = []): array
    {
        $base = [
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
                'body' => ['blocks' => [
                    ['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Halo']],
                ]],
                'footer' => ['repeat' => 'except-first', 'height' => 12, 'blocks' => []],
            ],
        ];

        // Daftar blok harus diganti utuh, bukan digabung per-indeks. Tanpa ini
        // array_replace_recursive mewariskan id dan props dari base ke override,
        // sehingga kasus "blok tanpa id" tidak pernah benar-benar teruji.
        foreach (['header', 'body', 'footer'] as $zone) {
            if (isset($override['zones'][$zone]['blocks'])) {
                $base['zones'][$zone]['blocks'] = [];
            }
        }

        return array_replace_recursive($base, $override);
    }

    public function test_accepts_a_minimal_valid_template(): void
    {
        $template = SchemaValidator::validate($this->validRaw());

        $this->assertSame(1, $template->version);
        $this->assertCount(1, $template->body->blocks);
        $this->assertSame(BlockType::Paragraph, $template->body->blocks[0]->type);
        $this->assertSame(ZoneRepeat::ExceptFirst, $template->footer->repeat);
        $this->assertSame('auto', $template->header->height);
        $this->assertEqualsWithDelta(12.0, $template->footer->height, 0.001);
    }

    public function test_it_rejects_a_schema_larger_than_the_byte_limit(): void
    {
        $raw = $this->validRaw([
            'zones' => ['body' => ['blocks' => [
                ['id' => 'kop', 'type' => 'image', 'props' => ['src' => 'data:image/png;base64,'.str_repeat('A', 2048)]],
            ]]],
        ]);

        try {
            SchemaValidator::validate($raw, maxBytes: 512);
            $this->fail('Schema di atas batas seharusnya ditolak');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('size', $e->errors());
            $this->assertStringContainsString('gambar', $e->errors()['size'], 'pesan harus menunjuk penyebab tersering');
        }
    }

    public function test_the_byte_limit_does_not_trip_on_an_ordinary_template(): void
    {
        // Batas bawaan 256KB; template tanpa gambar tertanam jauh di bawahnya,
        // jadi penjaga ini tidak boleh mengganggu pemakaian normal.
        $template = SchemaValidator::validate($this->validRaw());

        $this->assertSame(1, $template->version);
    }

    public function test_passing_null_max_bytes_skips_the_size_check_entirely(): void
    {
        // Ditemukan lewat bug nyata: dua template produksi (263KB dan 266KB)
        // sudah tersimpan sebelum batas ini ada, dan sama sekali tidak bisa
        // dibuka lagi begitu SchemaValidator::validate() SELALU menegakkan
        // MAX_BYTES tanpa cara mematikannya. Membaca schema yang SUDAH
        // tersimpan harus selalu berhasil, berapa pun ukurannya — batas ukuran
        // hanya masuk akal ditegakkan saat MENERIMA tulisan baru (lihat
        // Template::fromArray()).
        //
        // Fixture-nya SENGAJA melebihi MAX_BYTES (256KB) — bukan cuma batas
        // 512 byte di test lain. Fixture kecil pernah membuat test ini lolos
        // tanpa benar-benar membuktikan apa-apa: 2KB tidak pernah menyentuh
        // batas bawaan 256KB entah dilewati atau tidak.
        $raw = $this->validRaw([
            'zones' => ['body' => ['blocks' => [
                ['id' => 'kop', 'type' => 'image', 'props' => ['src' => 'data:image/png;base64,'.str_repeat('A', 300_000)]],
            ]]],
        ]);

        // Membuktikan schema ini SUNGGUHAN di atas batas bawaan — kalau baris
        // ini tidak melempar, test di bawahnya tidak menguji apa-apa.
        try {
            SchemaValidator::validate($raw);
            $this->fail('Fixture ini harus melebihi MAX_BYTES, kalau tidak test di bawah palsu');
        } catch (SchemaValidationException) {
            // sengaja diabaikan — ini pembuktian prasyarat, bukan yang diuji
        }

        $template = SchemaValidator::validate($raw, maxBytes: null);

        $this->assertSame(1, $template->version, 'maxBytes: null seharusnya melewati penjaga ukuran sama sekali');
    }

    public function test_rejects_unknown_block_type(): void
    {
        try {
            SchemaValidator::validate($this->validRaw([
                'zones' => ['body' => ['blocks' => [['id' => 'x', 'type' => 'carousel', 'props' => []]]]],
            ]));
            $this->fail('Seharusnya melempar SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('zones.body.blocks.0.type', $e->errors());
        }
    }

    public function test_rejects_block_without_id(): void
    {
        try {
            SchemaValidator::validate($this->validRaw([
                'zones' => ['body' => ['blocks' => [['type' => 'paragraph', 'props' => []]]]],
            ]));
            $this->fail('Seharusnya melempar SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('zones.body.blocks.0.id', $e->errors());
        }
    }

    public function test_rejects_unsupported_version(): void
    {
        try {
            SchemaValidator::validate($this->validRaw(['version' => 99]));
            $this->fail('Seharusnya melempar SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }
    }

    public function test_rejects_table_whose_column_widths_exceed_one_hundred(): void
    {
        try {
            SchemaValidator::validate($this->validRaw([
                'zones' => ['body' => ['blocks' => [[
                    'id' => 't1',
                    'type' => 'table',
                    'props' => ['columns' => [
                        ['label' => 'A', 'widthPercent' => 70],
                        ['label' => 'B', 'widthPercent' => 70],
                    ]],
                ]]]],
            ]));
            $this->fail('Seharusnya melempar SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('zones.body.blocks.0.props.columns', $e->errors());
        }
    }

    public function test_drops_properties_outside_the_whitelist(): void
    {
        $template = SchemaValidator::validate($this->validRaw([
            'zones' => ['body' => ['blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'props' => ['text' => 'Halo', 'onclick' => 'alert(1)'],
            ]]]],
        ]));

        $this->assertArrayNotHasKey('onclick', $template->body->blocks[0]->props);
        $this->assertSame('Halo', $template->body->blocks[0]->prop('text'));
    }

    public function test_clamps_numeric_properties_into_range(): void
    {
        $template = SchemaValidator::validate($this->validRaw([
            'zones' => ['body' => ['blocks' => [[
                'id' => 's1',
                'type' => 'spacer',
                'props' => ['heightMm' => 9999],
            ]]]],
        ]));

        $this->assertEqualsWithDelta(200.0, $template->body->blocks[0]->prop('heightMm'), 0.001);
    }

    public function test_unknown_enum_value_falls_back_to_default(): void
    {
        $template = SchemaValidator::validate($this->validRaw([
            'zones' => ['body' => ['blocks' => [[
                'id' => 'p1',
                'type' => 'paragraph',
                'props' => ['text' => 'Halo', 'align' => 'diagonal'],
            ]]]],
        ]));

        $this->assertSame('justify', $template->body->blocks[0]->prop('align'));
    }

    public function test_missing_properties_receive_defaults(): void
    {
        $template = SchemaValidator::validate($this->validRaw([
            'zones' => ['body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => []]]]],
        ]));

        $this->assertSame('', $template->body->blocks[0]->prop('text'));
        $this->assertSame('justify', $template->body->blocks[0]->prop('align'));
        $this->assertEqualsWithDelta(3.0, $template->body->blocks[0]->prop('spaceAfterMm'), 0.001);
    }

    public function test_blank_template_is_valid_and_round_trips(): void
    {
        $blank = Template::blank();

        $this->assertSame($blank->toArray(), SchemaValidator::validate($blank->toArray())->toArray());
    }

    public function test_template_from_array_delegates_to_the_validator(): void
    {
        $this->expectException(SchemaValidationException::class);

        Template::fromArray($this->validRaw(['version' => 99]));
    }

    public function test_zone_height_is_forced_to_auto_when_it_holds_a_letterhead_image(): void
    {
        // Margin negatif blok ini menembus zona bertinggi tetap, yang selalu
        // dipasangi overflow:hidden oleh paginator — jadi zona seperti itu
        // diperbaiki diam-diam ke "auto", bukan ditolak.
        $template = SchemaValidator::validate($this->validRaw([
            'zones' => ['header' => [
                'height' => 35,
                'blocks' => [['id' => 'kop', 'type' => 'letterhead-image', 'props' => ['src' => '']]],
            ]],
        ]));

        $this->assertSame('auto', $template->header->height);
    }

    public function test_duplicate_block_ids_are_rejected(): void
    {
        try {
            SchemaValidator::validate($this->validRaw([
                'zones' => ['body' => ['blocks' => [
                    ['id' => 'dup', 'type' => 'paragraph', 'props' => []],
                    ['id' => 'dup', 'type' => 'paragraph', 'props' => []],
                ]]],
            ]));
            $this->fail('Seharusnya melempar SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('zones.body.blocks.1.id', $e->errors());
        }
    }
}
