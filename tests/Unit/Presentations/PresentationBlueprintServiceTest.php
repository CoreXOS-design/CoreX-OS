<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\PresentationBlueprintService;
use PHPUnit\Framework\TestCase;

class PresentationBlueprintServiceTest extends TestCase
{
    private PresentationBlueprintService $service;

    protected function setUp(): void
    {
        $this->service = new PresentationBlueprintService();
    }

    // ── v1 blueprint structure ────────────────────────────────────────────────

    public function test_v1_returns_exactly_10_sections(): void
    {
        $sections = $this->service->getBlueprint('v1');
        $this->assertCount(10, $sections);
    }

    public function test_v1_sections_have_required_keys(): void
    {
        foreach ($this->service->getBlueprint('v1') as $section) {
            $this->assertArrayHasKey('key',   $section);
            $this->assertArrayHasKey('title', $section);
            $this->assertArrayHasKey('order', $section);
        }
    }

    public function test_v1_orders_are_1_to_10(): void
    {
        $orders = array_column($this->service->getBlueprint('v1'), 'order');
        $this->assertSame(range(1, 10), $orders);
    }

    public function test_v1_section_keys_are_unique(): void
    {
        $keys = array_column($this->service->getBlueprint('v1'), 'key');
        $this->assertSame($keys, array_unique($keys));
    }

    public function test_v1_contains_expected_section_keys(): void
    {
        $keys = array_column($this->service->getBlueprint('v1'), 'key');

        foreach (['cover', 'property_summary', 'sold_comps', 'active_stock',
                  'market_analytics', 'sale_probability', 'holding_cost',
                  'sensitivity', 'recommendation', 'appendix'] as $expected) {
            $this->assertContains($expected, $keys, "Missing section key: {$expected}");
        }
    }

    // ── Default version ───────────────────────────────────────────────────────

    public function test_default_version_equals_v1(): void
    {
        $this->assertSame(
            $this->service->getBlueprint('v1'),
            $this->service->getBlueprint(),
        );
    }

    // ── Unknown version ───────────────────────────────────────────────────────

    public function test_unknown_version_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getBlueprint('v99');
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_same_version_always_produces_same_blueprint(): void
    {
        $r1 = $this->service->getBlueprint('v1');
        $r2 = $this->service->getBlueprint('v1');
        $this->assertSame($r1, $r2);
    }
}
