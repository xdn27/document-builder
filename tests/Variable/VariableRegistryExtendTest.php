<?php

namespace Maqiis\DocumentBuilder\Tests\Variable;

use InvalidArgumentException;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use PHPUnit\Framework\TestCase;

final class VariableRegistryExtendTest extends TestCase
{
    private function base(): VariableRegistry
    {
        return (new VariableRegistry)
            ->define('branch.name', 'Nama Cabang', 'Cabang Pusat', 'Lembaga')
            ->defineCollection('recipients', [['name' => 'Bapak Ahmad']]);
    }

    public function test_extend_adds_entries_to_a_copy_and_leaves_the_original_untouched(): void
    {
        $base = $this->base();

        $extended = $base->extend([
            ['path' => 'employee.name', 'label' => 'Nama Pegawai', 'sample' => 'Ahmad Fauzi', 'group' => 'Pegawai'],
        ]);

        $this->assertTrue($extended->has('employee.name'));
        $this->assertTrue($extended->has('branch.name'));
        $this->assertFalse($base->has('employee.name'));
        $this->assertSame(['Lembaga', 'Pegawai'], array_keys($extended->groups()));
    }

    public function test_extend_defaults_the_group_to_umum(): void
    {
        $extended = (new VariableRegistry)->extend([['path' => 'a.b', 'label' => 'A', 'sample' => 'x']]);

        $this->assertSame(['Umum'], array_keys($extended->groups()));
    }

    public function test_extended_entries_override_the_same_path(): void
    {
        $extended = $this->base()->extend([['path' => 'branch.name', 'label' => 'Cabang', 'sample' => 'Baru']]);

        $this->assertSame('Baru', $extended->sampleResolver()->resolve('branch.name'));
    }

    public function test_extend_keeps_collections_and_sample_values(): void
    {
        $extended = $this->base()->extend([['path' => 'employee.name', 'label' => 'Nama', 'sample' => 'Ahmad']]);

        $this->assertTrue($extended->hasCollection('recipients'));
        $this->assertSame('Ahmad', $extended->sampleResolver()->resolve('employee.name'));
    }

    public function test_extend_with_no_entries_is_an_equal_copy(): void
    {
        $base = $this->base();

        $this->assertEquals($base, $base->extend([]));
        $this->assertNotSame($base, $base->extend([]));
    }

    public function test_extend_rejects_malformed_entries(): void
    {
        foreach ([['label' => 'A', 'sample' => 'x'], ['path' => 'a', 'sample' => 'x'], ['path' => 'a', 'label' => 'A'], ['path' => 'a', 'label' => 'A', 'sample' => 1]] as $entry) {
            try {
                (new VariableRegistry)->extend([$entry]);
                $this->fail('Entri salah bentuk harus ditolak: '.json_encode($entry));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
