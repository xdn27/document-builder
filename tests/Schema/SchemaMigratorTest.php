<?php

namespace Maqiis\DocumentBuilder\Tests\Schema;

use Maqiis\DocumentBuilder\Schema\SchemaMigrator;
use Maqiis\DocumentBuilder\Schema\SchemaValidationException;
use Maqiis\DocumentBuilder\Schema\Template;
use PHPUnit\Framework\TestCase;

/**
 * Validator menolak versi schema yang bukan versi berjalan. Tanpa migrator,
 * hari CURRENT_VERSION naik berarti seluruh template tersimpan gagal dibuka di
 * setiap repo yang belum ikut naik versi package (spec §12). Test ini memakai
 * peta upgrade palsu supaya mekanismenya terbukti TANPA harus menaikkan versi
 * sungguhan lebih dulu.
 */
class SchemaMigratorTest extends TestCase
{
    private function v1(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/schema-v1-minimal.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    public function test_it_leaves_a_current_version_schema_untouched(): void
    {
        $raw = $this->v1();

        $this->assertSame($raw, SchemaMigrator::upgrade($raw));
    }

    public function test_it_walks_a_schema_up_through_every_intervening_version(): void
    {
        $migrator = new SchemaMigrator(
            upgrades: [
                1 => fn (array $raw): array => ['version' => 2, 'satu' => true] + $raw,
                2 => fn (array $raw): array => ['version' => 3, 'dua' => true] + $raw,
            ],
            target: 3,
        );

        $upgraded = $migrator->run($this->v1());

        $this->assertSame(3, $upgraded['version']);
        $this->assertTrue($upgraded['satu'], 'langkah 1→2 terlewat');
        $this->assertTrue($upgraded['dua'], 'langkah 2→3 terlewat');
        $this->assertSame('Isi surat.', $upgraded['zones']['body']['blocks'][0]['props']['text']);
    }

    public function test_it_rejects_a_schema_newer_than_the_package(): void
    {
        $raw = ['version' => 99] + $this->v1();

        try {
            SchemaMigrator::upgrade($raw);
            $this->fail('Schema dari versi lebih baru seharusnya ditolak');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
            $this->assertStringContainsString('lebih baru', $e->errors()['version']);
        }
    }

    public function test_it_fails_loudly_when_an_upgrade_step_is_missing(): void
    {
        $migrator = new SchemaMigrator(upgrades: [], target: 2);

        try {
            $migrator->run($this->v1());
            $this->fail('Langkah upgrade yang belum ditulis seharusnya tidak lolos diam-diam');
        } catch (SchemaValidationException $e) {
            $this->assertStringContainsString('1', $e->errors()['version']);
        }
    }

    public function test_a_stored_v1_schema_still_opens_through_template_from_array(): void
    {
        $template = Template::fromArray($this->v1());

        $this->assertSame(Template::CURRENT_VERSION, $template->version);
        $this->assertCount(1, $template->body->blocks);
    }

    public function test_a_missing_or_non_integer_version_is_rejected_not_guessed(): void
    {
        $withoutVersion = $this->v1();
        unset($withoutVersion['version']);

        // Catatan: jangan menulis kasus "tanpa versi" sebagai [] + v1() — union
        // array tidak menghapus apa pun, jadi kasusnya malah lolos dan testnya
        // tidak menguji apa-apa.
        $cases = [
            'tanpa versi' => $withoutVersion,
            'versi null' => ['version' => null] + $this->v1(),
            'versi berupa string' => ['version' => '1'] + $this->v1(),
        ];

        foreach ($cases as $name => $raw) {
            try {
                SchemaMigrator::upgrade($raw);
                $this->fail("Versi yang tidak berupa integer seharusnya ditolak: {$name}");
            } catch (SchemaValidationException $e) {
                $this->assertArrayHasKey('version', $e->errors(), $name);
            }
        }
    }
}
