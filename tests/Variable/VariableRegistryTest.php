<?php

namespace Maqiis\DocumentBuilder\Tests\Variable;

use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use PHPUnit\Framework\TestCase;

class VariableRegistryTest extends TestCase
{
    public function test_registers_and_reports_known_paths(): void
    {
        $registry = (new VariableRegistry)->define('student.name', 'Nama Santri', 'Fatimah', 'Santri');

        $this->assertTrue($registry->has('student.name'));
        $this->assertFalse($registry->has('student.age'));
    }

    public function test_sample_resolver_returns_sample_values(): void
    {
        $registry = (new VariableRegistry)->define('student.name', 'Nama Santri', 'Fatimah', 'Santri');

        $this->assertSame('Fatimah', $registry->sampleResolver()->resolve('student.name'));
        $this->assertNull($registry->sampleResolver()->resolve('student.age'));
    }

    public function test_groups_entries_for_the_picker(): void
    {
        $registry = (new VariableRegistry)
            ->define('student.name', 'Nama Santri', 'Fatimah', 'Santri')
            ->define('institution.name', 'Nama Lembaga', 'Pesantren Maqiis', 'Lembaga');

        $groups = $registry->groups();

        $this->assertSame(['Santri', 'Lembaga'], array_keys($groups));
        $this->assertSame('student.name', $groups['Santri'][0]['path']);
    }

    public function test_defining_the_same_path_twice_overwrites(): void
    {
        $registry = (new VariableRegistry)
            ->define('a.b', 'Lama', 'satu')
            ->define('a.b', 'Baru', 'dua');

        $this->assertCount(1, $registry->all());
        $this->assertSame('dua', $registry->sampleResolver()->resolve('a.b'));
    }
}
