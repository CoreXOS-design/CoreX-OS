<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PlatformEsign\WordingVersion;
use App\Services\PlatformEsign\Agreement\AgreementLayout;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-10-06 (E7): a REV bump in AgreementLayout must not repaginate a PUBLISHED wording version — agencies have
 * already initialled it page by page. ensure() returns the stored layout untouched (no compute, no DB write).
 */
final class AgreementLayoutPublishedStableTest extends TestCase
{
    public function test_published_version_keeps_its_stored_layout_after_a_rev_bump(): void
    {
        $stored = ['rev' => AgreementLayout::REV - 1, 'budget' => 700.0, 'parts' => ['B' => [3, 4], 'C' => [2]], 'total' => 3];
        $v = new WordingVersion();
        $v->is_published = true;
        $v->layout_json  = $stored;

        // compute() would hit the renderer/PDF engine; reaching it here would fatal on the missing constructor deps.
        $layout = (new \ReflectionClass(AgreementLayout::class))->newInstanceWithoutConstructor();

        $this->assertEquals($stored, $layout->ensure($v));
    }
}
