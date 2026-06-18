<?php

declare(strict_types=1);

namespace Tests\Unit\Presentations;

use App\Support\Presentations\CompLabel;
use PHPUnit\Framework\TestCase;

/**
 * Comp display-label fallback chain — pin the 5-step priority and
 * the never-blank invariant. Bug repro: sectional CMA imports landed
 * scheme+section with no street address → review/PDF/map all rendered
 * "—" → seller couldn't identify the comp.
 */
final class CompLabelTest extends TestCase
{
    // ── Step 1: sectional identity (Unit + scheme) wins over street ─────

    public function test_sectional_unit_and_scheme_wins_over_street_address(): void
    {
        // The street on a sectional row is the scheme's shared street
        // ("18 Duke Road" for every Suntide Cabanas unit). Unit+scheme
        // identifies the comp; the street does not. Unit-first to match
        // the subject identity (Property::buildDisplayAddress()).
        $label = CompLabel::build(
            ['address' => '18 Duke Road', 'scheme_name' => 'Pumula', 'section_number' => '7'],
            suburb: 'Margate',
        );
        $this->assertSame('Unit 7, Pumula', $label);
    }

    public function test_address_takes_priority_over_scheme_when_no_section(): void
    {
        // Scheme present but no section → can't form "Unit N, Scheme",
        // so the street remains the better identifier. Nothing regresses.
        $label = CompLabel::build(
            ['address' => '12 King Street', 'scheme_name' => 'Seeskulp'],
            suburb: 'Margate',
        );
        $this->assertSame('12 King Street', $label);
    }

    public function test_blank_address_uses_unit_and_scheme(): void
    {
        $label = CompLabel::build(
            ['address' => '   ', 'scheme_name' => 'Seeskulp', 'section_number' => '8'],
            suburb: 'Uvongo',
        );
        $this->assertSame('Unit 8, Seeskulp', $label);
    }

    public function test_freehold_uses_street_address_unchanged(): void
    {
        // No scheme, no section → freehold. Street address is the label.
        $label = CompLabel::build(
            ['address' => '4 Tucker Avenue'],
            suburb: 'Uvongo',
        );
        $this->assertSame('4 Tucker Avenue', $label);
    }

    // ── Step 2: scheme + section → Unit-first identity ──────────────────

    public function test_uses_unit_and_scheme_when_no_address(): void
    {
        $label = CompLabel::build(
            ['scheme_name' => 'Seeskulp', 'section_number' => '8'],
            suburb: 'Uvongo',
        );
        $this->assertSame('Unit 8, Seeskulp', $label);
    }

    public function test_uses_scheme_alone_when_no_section(): void
    {
        $label = CompLabel::build(
            ['scheme_name' => 'Madeira Gardens'],
            suburb: 'Uvongo',
        );
        $this->assertSame('Madeira Gardens', $label);
    }

    public function test_section_no_alias_supported_for_doc_extract_rows(): void
    {
        // doc_extract_v1 parsers emit section_no instead of section_number.
        $label = CompLabel::build(
            ['scheme_name' => 'Seeskulp', 'section_no' => '12'],
            suburb: 'Uvongo',
        );
        $this->assertSame('Unit 12, Seeskulp', $label);
    }

    // ── Step 3: bare section ────────────────────────────────────────────

    public function test_section_with_suburb_when_no_scheme(): void
    {
        $label = CompLabel::build(
            ['section_number' => '10'],
            suburb: 'Uvongo',
        );
        $this->assertSame('Section 10, Uvongo', $label);
    }

    public function test_section_alone_when_no_suburb_or_scheme(): void
    {
        $label = CompLabel::build(
            ['section_number' => '10'],
            suburb: null,
        );
        $this->assertSame('Section 10', $label);
    }

    // ── Step 4: suburb fallback ─────────────────────────────────────────

    public function test_suburb_used_when_no_address_or_scheme_or_section(): void
    {
        $label = CompLabel::build(
            [],
            suburb: 'Margate',
        );
        $this->assertSame('Margate', $label);
    }

    // ── Step 5: absolute floor ──────────────────────────────────────────

    public function test_comp_id_floor_when_everything_else_missing(): void
    {
        $label = CompLabel::build(
            [],
            suburb: null,
            id: 47,
        );
        $this->assertSame('Comp #47', $label);
    }

    public function test_unidentified_floor_when_no_id_either(): void
    {
        $label = CompLabel::build([], suburb: null, id: null);
        $this->assertSame('Unidentified comp', $label);
    }

    // ── never-blank invariant ──────────────────────────────────────────

    public function test_never_returns_blank_for_null_inputs(): void
    {
        $label = CompLabel::build(null, suburb: null, id: null);
        $this->assertNotSame('', $label);
        $this->assertNotSame('—', $label);
    }

    public function test_never_returns_blank_for_empty_array(): void
    {
        $label = CompLabel::build([], suburb: '', id: 0);
        // id=0 is non-null → "Comp #0" wins; never blank.
        $this->assertSame('Comp #0', $label);
    }
}
