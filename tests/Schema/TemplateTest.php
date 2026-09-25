<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\SchemaValidationException;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use PHPUnit\Framework\TestCase;

/**
 * Template::fromArray() adalah jalur MEMBACA schema — dipakai mount(), preview(),
 * dan DocumentTemplate::template() (yang menopang cetak & PDF). Jalur ini tidak
 * boleh menegakkan batas ukuran (spec §13's SchemaValidator::MAX_BYTES): sekali
 * sebuah template tersimpan melebihi batas — lewat versi package sebelumnya,
 * atau sekadar akumulasi gambar dari waktu ke waktu — jalur baca yang menolaknya
 * berarti template itu tidak bisa dibuka, dicetak, atau diunduh PDF-nya lagi
 * SAMA SEKALI, dan tidak ada cara memperbaikinya dari UI karena UI-nya sendiri
 * yang menolak terbuka.
 *
 * Batas ukuran hanya masuk akal ditegakkan saat MENERIMA tulisan baru — itulah
 * kenapa TemplateBuilder::save() memanggil SchemaValidator::validate() dengan
 * maxBytes eksplisit, bukan lewat fromArray().
 */
class TemplateTest extends TestCase
{
    private function rawWithLargeImage(): array
    {
        return [
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
                'body' => ['blocks' => [
                    // ~300KB, di atas SchemaValidator::MAX_BYTES (256KB) — meniru
                    // dua template produksi sungguhan yang memicu bug ini.
                    ['id' => 'kop', 'type' => 'image', 'props' => [
                        'src' => 'data:image/png;base64,'.str_repeat('A', 300_000),
                        'alt' => '', 'widthMm' => 50.0, 'align' => 'left',
                    ]],
                ]],
                'footer' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
            ],
        ];
    }

    public function test_from_array_reads_a_schema_larger_than_the_size_guard(): void
    {
        // Prasyarat: fixture ini benar-benar di atas MAX_BYTES.
        $this->assertGreaterThan(SchemaValidator::MAX_BYTES, strlen((string) json_encode($this->rawWithLargeImage())));

        $template = Template::fromArray($this->rawWithLargeImage());

        $this->assertSame(1, $template->version);
        $this->assertCount(1, $template->body->blocks);
    }

    public function test_from_array_still_rejects_structurally_invalid_schemas(): void
    {
        // Melonggarkan batas ukuran tidak boleh ikut melonggarkan validasi
        // struktural — id blok kosong tetap harus ditolak.
        $raw = $this->rawWithLargeImage();
        $raw['zones']['body']['blocks'][0]['id'] = '';

        $this->expectException(SchemaValidationException::class);

        Template::fromArray($raw);
    }

    public function test_an_explicit_max_bytes_still_enforces_the_cap_through_from_array(): void
    {
        // Titik yang dipanggil TemplateBuilder::save() — write path TETAP
        // menegakkan batas, lewat parameter eksplisit.
        try {
            Template::fromArray($this->rawWithLargeImage(), SchemaValidator::MAX_BYTES);
            $this->fail('save() dengan schema di atas batas seharusnya ditolak');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('size', $e->errors());
        }
    }

    /**
     * TemplateBuilder::exportSchema()/applySchemaImport() lewat pasangan
     * toArray()/fromArray() yang sama persis dengan test ini. Round-trip
     * harus stabil: JSON hasil ekspor, saat diimpor lagi, menghasilkan
     * schema yang identik — bukan cuma "valid".
     */
    public function test_exported_json_round_trips_back_into_an_identical_template(): void
    {
        $original = Template::fromArray($this->validSchema());

        $exported = json_decode((string) json_encode($original->toArray()), true);
        $reimported = Template::fromArray($exported, SchemaValidator::MAX_BYTES);

        $this->assertSame($original->toArray(), $reimported->toArray());
    }

    /**
     * Ekspor adalah jalur BACA (spt. preview()): template lama yang sudah
     * tersimpan di atas MAX_BYTES tetap harus bisa diekspor apa adanya —
     * itulah skenario paling umum orang menekan tombol Ekspor (mis. untuk
     * memangkas gambar secara manual di luar builder). Batas ukuran baru
     * ditegakkan lagi saat hasilnya diimpor balik (jalur TULIS).
     */
    public function test_an_oversized_saved_template_can_still_be_exported_but_not_reimported(): void
    {
        $exportable = Template::fromArray($this->rawWithLargeImage());

        $this->assertCount(1, $exportable->body->blocks);

        $exported = json_decode((string) json_encode($exportable->toArray()), true);

        $this->expectException(SchemaValidationException::class);

        Template::fromArray($exported, SchemaValidator::MAX_BYTES);
    }

    private function validSchema(): array
    {
        return [
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
                'body' => ['blocks' => [
                    ['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => 'Halo dunia']],
                ]],
                'footer' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
            ],
        ];
    }
}
