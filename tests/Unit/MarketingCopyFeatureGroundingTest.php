<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MarketingCopyService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The grounding rule (spec marketing-ai-copy.md §4) lives or dies on what facts
 * reach the prompt. This guards `extractFeatures` — only genuine, present
 * features may pass; absent flags and non-feature config keys must be dropped so
 * Ellie never sees (and never writes about) something the property doesn't have.
 *
 * Pure logic, no DB — runs regardless of the local test-DB bootstrap.
 */
final class MarketingCopyFeatureGroundingTest extends TestCase
{
    private function extract(array $raw): array
    {
        $m = new ReflectionMethod(MarketingCopyService::class, 'extractFeatures');
        $m->setAccessible(true);
        return $m->invoke(new MarketingCopyService(), $raw);
    }

    public function test_false_flags_and_config_keys_are_dropped(): void
    {
        $out = $this->extract([
            'pool'               => false,
            'garden'             => false,
            'pets_allowed'       => false,
            'listing_visibility' => 'Public', // config, not a feature
        ]);

        $this->assertSame([], $out, 'no present features → nothing leaks (incl. listing_visibility)');
    }

    public function test_true_flags_and_positive_numerics_survive(): void
    {
        $out = $this->extract([
            'pool'               => true,
            'garden'             => false,
            'pet_friendly'       => true,
            'garage_spaces'      => 2,
            'extra_rooms'        => 0,        // zero → dropped
            'listing_visibility' => 'Public', // dropped
        ]);

        $this->assertSame(['Pool', 'Pet Friendly', 'Garage Spaces: 2'], $out);
    }

    public function test_string_list_shape_is_supported(): void
    {
        $this->assertSame(
            ['Pool', 'Sea View', 'Solar'],
            $this->extract(['Pool', ' Sea View ', 'Solar', '']),
        );
    }
}
