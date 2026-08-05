<?php

namespace Tests\Feature\Performance;

use App\Services\Performance\MetricProviderRegistry;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * AT-366-B — the registry exposes every category with a unique key and each
 * provider honours the empty-cohort contract. No DB — pure wiring guard.
 */
class MetricProviderRegistryTest extends TestCase
{
    public function test_registry_exposes_all_categories_with_unique_keys(): void
    {
        $providers = app(MetricProviderRegistry::class)->all();

        $this->assertCount(11, $providers);

        $keys = array_map(fn ($p) => $p->key(), $providers);
        $this->assertSame($keys, array_values(array_unique($keys)), 'Provider keys must be unique.');

        $stub = new Period(CarbonImmutable::now(), CarbonImmutable::now(), 'x', 'today');
        foreach ($providers as $p) {
            $this->assertNotEmpty($p->label(), $p->key() . ' must have a label.');
            $this->assertSame([], $p->forUsers([], $stub), $p->key() . ' must return [] for an empty cohort.');
        }
    }
}
