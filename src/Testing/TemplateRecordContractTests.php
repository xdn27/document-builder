<?php

namespace Maqiis\DocumentBuilder\Testing;

use Maqiis\DocumentBuilder\Laravel\Concerns\TemplateRecord;
use ReflectionClass;

/**
 * Test yang dijalankan aplikasi konsumen atas implementasi TemplateRecord
 * miliknya sendiri. Dikirim bersama package (bukan tinggal di tests/) supaya
 * ikut autoload konsumen.
 *
 * Berbentuk trait, bukan kelas abstrak, karena tiap aplikasi punya base TestCase
 * sendiri — kelas abstrak akan merampas induknya.
 *
 * Pakai dengan mengisi kedua method abstrak:
 *
 *   class DocumentTemplateRecordContractTest extends TestCase
 *   {
 *       use TemplateRecordContractTests;
 *
 *       protected function makeTemplateRecord(array $schema): TemplateRecord { … }
 *       protected function actingWithUpdateAbility(bool $granted): void { … }
 *   }
 */
trait TemplateRecordContractTests
{
    /** Buat satu record tersimpan yang schema-nya persis $schema. */
    abstract protected function makeTemplateRecord(array $schema): TemplateRecord;

    /** Jadikan user aktif punya ($granted true) atau tidak punya ability menyimpan. */
    abstract protected function actingWithUpdateAbility(bool $granted): void;

    private function contractSchema(string $text = 'Isi surat.'): array
    {
        return [
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'style' => ['fontFamily' => 'tinos', 'fontSize' => 12, 'lineHeight' => 1.5],
            'zones' => [
                'header' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
                'body' => ['blocks' => [['id' => 'p1', 'type' => 'paragraph', 'props' => ['text' => $text]]]],
                'footer' => ['repeat' => 'all', 'height' => 'auto', 'blocks' => []],
            ],
        ];
    }

    public function test_contract_round_trips_a_schema_without_losing_anything(): void
    {
        $record = $this->makeTemplateRecord($this->contractSchema());

        $this->assertSame($this->contractSchema(), $record->getTemplateSchema());

        $record->setTemplateSchema($this->contractSchema('Diperbarui.'));

        $this->assertSame(
            'Diperbarui.',
            $record->getTemplateSchema()['zones']['body']['blocks'][0]['props']['text'],
            'setTemplateSchema() tidak tersimpan atau tidak terbaca kembali'
        );
    }

    public function test_contract_returns_an_empty_array_for_an_unset_schema(): void
    {
        $record = $this->makeTemplateRecord([]);

        $this->assertSame([], $record->getTemplateSchema());
    }

    public function test_contract_blocks_update_without_the_ability(): void
    {
        $record = $this->makeTemplateRecord($this->contractSchema());
        $this->actingWithUpdateAbility(false);

        $blocked = false;

        try {
            $record->authorizeTemplateUpdate();
        } catch (\Throwable $e) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'authorizeTemplateUpdate() meloloskan user tanpa ability menyimpan');
    }

    public function test_contract_allows_update_with_the_ability(): void
    {
        $record = $this->makeTemplateRecord($this->contractSchema());
        $this->actingWithUpdateAbility(true);

        $record->authorizeTemplateUpdate();

        $this->assertTrue(true, 'authorizeTemplateUpdate() tidak boleh melempar untuk user yang berhak');
    }

    public function test_contract_exposes_a_human_readable_template_name(): void
    {
        $record = $this->makeTemplateRecord($this->contractSchema());

        // Toolbar builder menampilkan ini. Boleh string kosong (aplikasi yang
        // memang tidak menamai templatenya), tapi tidak boleh melempar dan
        // tidak boleh null — view merender apa pun yang dikembalikan.
        $this->assertIsString($record->getTemplateName());
    }

    public function test_contract_requires_the_consumer_to_declare_its_own_view_authorization(): void
    {
        $record = $this->makeTemplateRecord($this->contractSchema());
        $declaring = (new ReflectionClass($record))
            ->getMethod('authorizeTemplateView')
            ->getDeclaringClass()
            ->getName();

        // Kalau method ini datang dari package, berarti aplikasi tidak pernah
        // memutuskan aturan aksesnya sendiri — dan itu justru kegagalan yang
        // paling mahal untuk ditemukan belakangan.
        $this->assertStringStartsNotWith(
            'Maqiis\\DocumentBuilder',
            $declaring,
            'authorizeTemplateView() harus dideklarasikan kelas aplikasi, bukan diwarisi dari package'
        );
    }
}
