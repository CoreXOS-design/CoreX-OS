<?php

declare(strict_types=1);

namespace Tests\Feature\Presentations;

use App\Models\MarketReports\MarketReport;
use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\PresentationGeneratorService;
use App\Support\Presentations\SubjectReportResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * AT-78 — generator address fixes.
 *
 * FIX 1 — SubjectReportResolver requires a street match (suburb never borrows);
 *         the generator backfill only stamps complex/unit from THIS property's
 *         confirmed report, never a same-suburb / same-street sibling, and
 *         audits the write.
 * FIX 2 — the live property_address wins over a frozen extracted subject.address
 *         field, so a corrected address shows on regenerate.
 * FIX 3 — comps the valuation engine rejected as price outliers are hidden from
 *         the display comps table (agency-toggleable).
 */
class GeneratorAddressFixesTest extends TestCase
{
    use RefreshDatabase;

    private ?\App\Models\Agency $fixtureAgency = null;
    private ?\App\Models\Branch $fixtureBranch = null;
    private ?\App\Models\User $fixtureUser = null;

    private function agencyId(): int
    {
        $this->fixtureAgency ??= \App\Models\Agency::create([
            'name' => 'Test Agency',
            'slug' => 'test-agency-at78',
        ]);
        return (int) $this->fixtureAgency->id;
    }

    private function branchId(): int
    {
        $this->fixtureBranch ??= \App\Models\Branch::create([
            'agency_id' => $this->agencyId(),
            'name'      => 'Test Branch',
        ]);
        return (int) $this->fixtureBranch->id;
    }

    private function user(): \App\Models\User
    {
        return $this->fixtureUser ??= \App\Models\User::factory()->create([
            'agency_id' => $this->agencyId(),
            'branch_id' => $this->branchId(),
        ]);
    }

    private function makeReport(array $attrs): MarketReport
    {
        return MarketReport::create(array_merge([
            'agency_id'           => $this->agencyId(),
            'uploaded_by_user_id' => $this->user()->id,
            'parse_status'        => 'parsed',
            'file_path'           => 'reports/test.pdf',
            'file_name'           => 'test.pdf',
            'file_hash'           => bin2hex(random_bytes(8)),
            'report_date'         => '2025-01-01',
        ], $attrs));
    }

    private function makeProperty(array $attrs): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $this->agencyId(),
            'agent_id'      => $this->user()->id,
            'branch_id'     => $this->branchId(),
            'property_type' => 'House',
            'title'         => 'Test home',
            'status'        => 'active',
        ], $attrs));
    }

    // ── FIX 1a — resolver discipline ────────────────────────────────────────

    public function test_resolver_requires_street_match_and_never_borrows_a_suburb_sibling(): void
    {
        // The subject's own report (street matches).
        $own = $this->makeReport([
            'subject_address'        => '55 GARDEN AVENUE, UVONGO BEACH',
            'source_suburb'          => 'UVONGO BEACH',
        ]);
        // A DIFFERENT property in the SAME suburb (the AT-78 borrow case).
        $sibling = $this->makeReport([
            'subject_address'        => '75 MARINE DRIVE',
            'source_suburb'          => 'UVONGO BEACH',
            'subject_scheme_name'    => 'NAUTILUS',
            'subject_section_number' => '14',
        ]);

        $ids = SubjectReportResolver::resolveReportIds($this->agencyId(), '55 Garden Avenue, Uvongo Beach', 'Uvongo Beach');

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($sibling->id, $ids, 'suburb sibling must NOT be borrowed');
    }

    public function test_resolver_returns_nothing_for_empty_address(): void
    {
        $this->makeReport(['subject_address' => '75 MARINE DRIVE', 'source_suburb' => 'UVONGO BEACH']);
        $this->assertSame([], SubjectReportResolver::resolveReportIds($this->agencyId(), '', 'Uvongo Beach'));
    }

    // ── FIX 1b/c/d — backfill confidence + audit ────────────────────────────

    private function invokeBackfill(Property $p): bool
    {
        $svc = new PresentationGeneratorService();
        $m = new \ReflectionMethod($svc, 'backfillSubjectSectionalIdentity');
        $m->setAccessible(true);
        return (bool) $m->invoke($svc, $p);
    }

    public function test_backfill_does_NOT_stamp_a_suburb_siblings_identity(): void
    {
        // Sibling report (NAUTILUS / 75 Marine Drive) in the same suburb.
        $this->makeReport([
            'subject_address'        => '75 MARINE DRIVE',
            'source_suburb'          => 'UVONGO BEACH',
            'subject_scheme_name'    => 'NAUTILUS',
            'subject_section_number' => '14',
        ]);
        // The 557-style freehold: blank complex/unit, address in street_* (legacy
        // `address` column NULL).
        $prop = $this->makeProperty([
            'street_number' => '55',
            'street_name'   => 'Garden Avenue',
            'suburb'        => 'Uvongo Beach',
            'address'       => null,
            'complex_name'  => null,
            'unit_number'   => null,
            'title_type'    => 'full_title',
        ]);

        $wrote = $this->invokeBackfill($prop);

        $this->assertFalse($wrote, 'must not borrow a sibling report');
        $this->assertNull($prop->fresh()->complex_name);
        $this->assertNull($prop->fresh()->unit_number);
    }

    public function test_backfill_stamps_on_a_confident_full_street_match_and_audits(): void
    {
        // THIS property's own report — full street (number + name) matches.
        $report = $this->makeReport([
            'subject_address'        => '12 ESTATE ROAD, BALLITO',
            'source_suburb'          => 'BALLITO',
            'subject_scheme_name'    => 'PALM ESTATE',
            'subject_section_number' => '7',
        ]);
        // Estate freehold (full_title) — legitimately has complex/unit; blank here.
        $prop = $this->makeProperty([
            'street_number' => '12',
            'street_name'   => 'Estate Road',
            'suburb'        => 'Ballito',
            'complex_name'  => null,
            'unit_number'   => null,
            'title_type'    => 'full_title',
        ]);

        $wrote = $this->invokeBackfill($prop);

        $this->assertTrue($wrote);
        $this->assertSame('PALM ESTATE', $prop->fresh()->complex_name);
        $this->assertSame('7', $prop->fresh()->unit_number);

        // FIX 1d — the silent write is now audited.
        $this->assertDatabaseHas('property_audit_log', [
            'property_id' => $prop->id,
            'event_type'  => 'sectional_identity_backfilled',
        ]);
    }

    public function test_backfill_writes_nothing_when_property_has_no_street(): void
    {
        $this->makeReport([
            'subject_address'        => 'SOMETHING, BALLITO',
            'source_suburb'          => 'BALLITO',
            'subject_scheme_name'    => 'X',
            'subject_section_number' => '1',
        ]);
        $prop = $this->makeProperty([
            'street_number' => null,
            'street_name'   => null,
            'suburb'        => 'Ballito',
            'complex_name'  => null,
            'unit_number'   => null,
        ]);

        $this->assertFalse($this->invokeBackfill($prop));
        $this->assertNull($prop->fresh()->complex_name);
    }

    public function test_backfill_never_clobbers_an_agent_supplied_value(): void
    {
        $this->makeReport([
            'subject_address'        => '12 ESTATE ROAD, BALLITO',
            'source_suburb'          => 'BALLITO',
            'subject_scheme_name'    => 'PALM ESTATE',
            'subject_section_number' => '7',
        ]);
        $prop = $this->makeProperty([
            'street_number' => '12',
            'street_name'   => 'Estate Road',
            'suburb'        => 'Ballito',
            'complex_name'  => 'Agent Set Complex',
            'unit_number'   => '99',
        ]);

        $this->assertFalse($this->invokeBackfill($prop), 'both filled → no-op');
        $this->assertSame('Agent Set Complex', $prop->fresh()->complex_name);
        $this->assertSame('99', $prop->fresh()->unit_number);
    }

    // ── FIX 2 — live property_address wins over frozen extracted field ───────

    public function test_live_property_address_wins_over_extracted_subject_address_field(): void
    {
        $prop = $this->makeProperty([
            'street_number' => '99',
            'street_name'   => 'New Road',
            'suburb'        => 'Margate',
            'complex_name'  => null,
            'unit_number'   => null,
        ]);
        $pres = Presentation::create([
            'agency_id'          => $this->agencyId(),
            'branch_id'          => $this->branchId(),
            'created_by_user_id' => $this->user()->id,
            'property_id'        => $prop->id,
            'title'              => 'Test Presentation',
            'property_address'   => 'NEW LIVE ADDRESS, Margate',
            'suburb'             => 'Margate',
            'property_type'      => 'house',
            'status'             => 'draft',
        ]);
        $pres->setRelation('property', $prop);

        // A frozen extracted field carrying the OLD address.
        $fields = collect([
            'subject.address' => (object) ['final_value' => 'OLD STALE ADDRESS'],
        ]);

        $ads = new AnalysisDataService();
        $m = new \ReflectionMethod($ads, 'compileSubjectProperty');
        $m->setAccessible(true);
        $out = $m->invoke($ads, $pres, $fields, 2_500_000);

        $this->assertSame('NEW LIVE ADDRESS, Margate', $out['address']);
        $this->assertStringNotContainsString('STALE', (string) $out['display_address']);
    }

    // ── FIX 3 — outlier hidden from display comps table ─────────────────────

    private function soldComp(int $id, int $price, string $addr): PresentationSoldComp
    {
        $c = new PresentationSoldComp();
        $c->forceFill([
            'id'            => $id,
            'sold_price_inc' => $price,
            'size_m2'       => 100,
            'suburb'        => 'uvongo beach',
            'sold_date'     => '2025-06-01',
            'raw_row_json'  => ['address' => $addr, 'source' => 'mic_snapshot'],
        ]);
        return $c;
    }

    private function invokeComparable(Collection $comps, array $excluded, bool $hide): array
    {
        $ads = new AnalysisDataService();
        $m = new \ReflectionMethod($ads, 'compileComparableSales');
        $m->setAccessible(true);
        return $m->invoke($ads, $comps, null, true, null, $excluded, $hide);
    }

    public function test_valuation_outlier_is_hidden_from_display_when_toggle_on(): void
    {
        $comps = collect([
            $this->soldComp(1, 2_400_000, '40 Garden Avenue'),
            $this->soldComp(2, 2_650_000, '48 Colin Street'),
            $this->soldComp(99, 13_000_000, '71 Marine Drive'), // the outlier
        ]);

        $out = $this->invokeComparable($comps, [99], true);
        $addresses = collect($out)->flatMap(fn ($g) => array_column($g['rows'], 'address'))->all();

        $this->assertNotContains('71 Marine Drive', $addresses, 'outlier must be hidden');
        $this->assertContains('40 Garden Avenue', $addresses);
    }

    public function test_outlier_is_shown_when_toggle_off(): void
    {
        $comps = collect([
            $this->soldComp(1, 2_400_000, '40 Garden Avenue'),
            $this->soldComp(99, 13_000_000, '71 Marine Drive'),
        ]);

        $out = $this->invokeComparable($comps, [99], false);
        $addresses = collect($out)->flatMap(fn ($g) => array_column($g['rows'], 'address'))->all();

        $this->assertContains('71 Marine Drive', $addresses, 'toggle off → outlier shown');
    }
}
