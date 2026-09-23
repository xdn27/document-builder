<?php

namespace Maqiis\DocumentBuilder\Tests\Variable;

use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use Maqiis\DocumentBuilder\Variable\CollectionVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use PHPUnit\Framework\TestCase;

class CollectionVariableResolverTest extends TestCase
{
    public function test_array_resolver_exposes_a_list_of_rows_as_a_collection(): void
    {
        $resolver = new ArrayVariableResolver([
            'recipients' => [['name' => 'Ahmad'], ['name' => 'Siti']],
        ]);

        $this->assertInstanceOf(CollectionVariableResolver::class, $resolver);

        $items = $resolver->collection('recipients');

        $this->assertCount(2, $items);
        $this->assertSame('Siti', $items[1]->resolve('name'));
    }

    public function test_array_resolver_reports_unknown_or_non_list_collections_as_null(): void
    {
        $resolver = new ArrayVariableResolver([
            'recipient' => ['name' => 'Ahmad'],
            'count' => '2',
        ]);

        $this->assertNull($resolver->collection('recipients'));
        $this->assertNull($resolver->collection('recipient'));
        $this->assertNull($resolver->collection('count'));
    }

    public function test_an_empty_list_is_an_empty_collection_not_an_unknown_one(): void
    {
        $this->assertSame([], (new ArrayVariableResolver(['recipients' => []]))->collection('recipients'));
    }

    public function test_registry_sample_resolver_serves_sample_collections(): void
    {
        $registry = (new VariableRegistry)
            ->define('recipient.name', 'Nama', 'Contoh', 'Penerima')
            ->defineCollection('recipients', [['name' => 'Satu'], ['name' => 'Dua']]);

        $resolver = $registry->sampleResolver();

        $this->assertInstanceOf(CollectionVariableResolver::class, $resolver);
        $this->assertSame('Contoh', $resolver->resolve('recipient.name'));
        $this->assertSame('Dua', $resolver->collection('recipients')[1]->resolve('name'));
        $this->assertNull($resolver->collection('unknown'));
    }
}
