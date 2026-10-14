<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use Tests\TestCase;

/**
 * FeatureNavGuardCoverageTest only proves a screen MENTIONS the feature it links to. These three links (14 Oct 2026) had no
 * wrapper at all; here the link must sit INSIDE the feature's own block so switching the feature off hides it.
 */
final class CrossLinkFeatureWrapTest extends TestCase
{
    private function source(string $rel): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/' . $rel)));
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function blocks(): array
    {
        return [
            'who-pays summary -> work order' => ['corex/rental-fault-reports/_who-pays-summary.blade.php', 'rental-work-orders', "route('corex.rental-work-orders.show'"],
            'paused-by-owner banner -> fault' => ['partials/paused-by-owner-banner.blade.php', 'rental-faults', "route('corex.rental-fault-reports.show'"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blocks')]
    public function test_the_link_sits_inside_its_feature_block(string $rel, string $key, string $route): void
    {
        $src = $this->source($rel);
        $this->assertSame(1, substr_count($src, $route), 'exactly one such link');

        $open = strpos($src, "@feature('{$key}')");
        $close = strpos($src, '@endfeature', (int) $open);
        $at = strpos($src, $route);
        $this->assertNotFalse($open, "@feature('{$key}') is missing");
        $this->assertNotFalse($close, '@endfeature is missing');
        $this->assertTrue($open < $at && $at < $close, "the link must be between @feature('{$key}') and @endfeature");
    }

    public function test_the_road_to_sold_see_all_buyers_link_is_only_built_when_the_buyer_pipeline_is_on(): void
    {
        $src = $this->source('road-to-sold/index.blade.php');

        $this->assertStringContainsString("feature('buyer-pipeline') ? route('command-center.buyers.pipeline') : null", $src);
        $this->assertSame(1, substr_count($src, "route('command-center.buyers.pipeline')"), 'no other, unguarded copy of the link');
    }
}
