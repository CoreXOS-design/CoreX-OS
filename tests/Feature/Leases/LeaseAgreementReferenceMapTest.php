<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Services\Rentals\LeaseAgreementTemplateGuard;
use App\Services\Rentals\LeaseAgreementValuesReader;
use Tests\TestCase;

/**
 * leases.md §15.12.5 (Build L0) — the worked example of a field map: a real residential lease's 24
 * fill-in places, in document order, and where each value comes from. It is one agency's map, so it
 * lives here as DATA (a fixture), never in code. What this pins, in the one place L0/L2/L3a/L3c share:
 *   - every place's registry key is known to the registry, and classifies the way §15.12.5 says
 *     (4 from the lease record, 6 from a contact, 1 + 4 worked out, 9 typed on the capture screen);
 *   - the map carries what the process needs, so the guard's map check passes;
 *   - the reader reads every one of the 24 places from a printed agreement, `monthly_rental` printed
 *     twice reading once;
 *   - an agency whose own map has no schedule carries none of the schedule values.
 *
 * NOT here: computing the calculated values (words, service fee with/without VAT, net to owner). No
 * code computes them until the launcher seeds the document (Build L3a); they cannot be tested before
 * there is something to test.
 */
final class LeaseAgreementReferenceMapTest extends TestCase
{
    /**
     * place # => [registry key, the template's own field name, where the value comes from]
     * (field names are those of the residential CDS snapshot in the repo).
     */
    private const PLACES = [
        1 => ['landlord_name', 'lessor_full', 'contact'],
        2 => ['landlord_address', 'lessor_address', 'contact'],
        3 => ['landlord_id', 'lessor_id_number', 'contact'],
        4 => ['tenant_name', 'lessee_name_id', 'contact'],
        5 => ['tenant_address', 'lessee_address', 'contact'],
        6 => ['tenant_id', 'lessee_id_number', 'contact'],
        7 => ['property_description', 'property_full', 'calculated'],
        8 => ['adults', 'occupants', 'terms'],
        9 => ['max_other_persons', 'kids', 'terms'],
        10 => ['rent', 'monthly_rental', 'lease'],
        11 => ['rent_in_words', 'price_in_words', 'calculated'],
        12 => ['escalation_percent', 'escalation', 'terms'],
        13 => ['escalation_in_words', 'escalation_alpha', 'calculated'],
        14 => ['escalation_month', 'escalation_month', 'terms'],
        15 => ['start_date', 'property_lease_start_date', 'lease'],
        16 => ['earliest_termination_date', 'notice_date', 'terms'],
        17 => ['end_date', 'property_lease_end_date', 'lease'],
        18 => ['renewal_option_months', 'renewal_period', 'terms'],
        19 => ['pets', 'what_pets_are_allowed', 'terms'],
        20 => ['other_conditions', 'other_conditions', 'terms'],
        21 => ['rent', 'monthly_rental', 'lease'],
        22 => ['agent_service_fee', 'agent_fee_in_rands', 'calculated'],
        23 => ['other_deduction', 'lets_assists_fee', 'terms'],
        24 => ['net_to_owner', 'owner_nett', 'calculated'],
    ];

    public function test_there_are_24_places_and_every_key_is_known_to_the_registry(): void
    {
        $registry = config('lease-agreement-fields.fields');

        $this->assertCount(24, self::PLACES);
        foreach (self::PLACES as $n => [$key]) {
            $this->assertArrayHasKey($key, $registry, "place #$n uses an unknown key");
        }
    }

    public function test_the_places_classify_the_way_the_spec_says(): void
    {
        $registry = config('lease-agreement-fields.fields');
        $bySide = [];
        foreach (self::PLACES as $n => [$key, , $expectedSide]) {
            $side = $registry[$key]['side'];
            $this->assertSame($expectedSide, $side, "place #$n ($key)");
            $bySide[$side] = ($bySide[$side] ?? 0) + 1;
        }

        // §15.12.5 totals: 4 from the lease record (rent twice, start, end), 6 from a contact,
        // 5 worked out (one from the property + four more), 9 typed on the capture screen.
        $this->assertSame(['contact' => 6, 'calculated' => 5, 'terms' => 9, 'lease' => 4], $bySide);
    }

    public function test_the_reference_map_is_linkable_and_carries_no_hardcoded_label(): void
    {
        $guard = app(LeaseAgreementTemplateGuard::class);

        $this->assertSame([], $guard->mapProblems($this->referenceMap()));
        foreach ($this->referenceMap() as $key => $entry) {
            $this->assertArrayNotHasKey('label', $entry, 'labels come from an agency\'s own map, never from a shared fixture');
        }
        // The registry itself names no fee scheme: the schedule label is blank until the agency's map gives one.
        $registry = config('lease-agreement-fields.fields');
        $this->assertSame('Other deduction', $registry['other_deduction']['label']);
    }

    public function test_every_one_of_the_24_places_is_read_back_from_a_printed_agreement(): void
    {
        $values = [
            'lessor_full' => 'Owner Person', 'lessor_address' => '1 Beach Rd', 'lessor_id_number' => '7001015009087',
            'lessee_name_id' => 'Thandi Nkosi', 'lessee_address' => '2 Dune Ave', 'lessee_id_number' => '8002025009081',
            'property_full' => 'Unit 4, Sea Complex, 12 Marine Dr, Shelly Beach', 'occupants' => '2', 'kids' => '1',
            'monthly_rental' => 'R6 940', 'price_in_words' => 'Six thousand nine hundred and forty Rand', 'escalation' => '7.5',
            'escalation_alpha' => 'seven point five', 'escalation_month' => 'March', 'property_lease_start_date' => '1 March 2027',
            'notice_date' => '31 January 2028', 'property_lease_end_date' => '28 February 2028', 'renewal_period' => '12',
            'what_pets_are_allowed' => 'One small dog', 'other_conditions' => 'No smoking indoors.', 'agent_fee_in_rands' => 'R 798.10',
            'lets_assists_fee' => 'R 150.00', 'owner_nett' => 'R 5 991.90',
        ];
        $html = '';
        foreach ($values as $field => $value) {
            $html .= '<p><span data-field="' . $field . '">' . $value . '</span></p>';
        }
        // The rent is printed a second time in the schedule, as the lease prints it.
        $html .= '<p>Total <span data-field="monthly_rental">R6 940</span></p>';

        $out = app(LeaseAgreementValuesReader::class)->readFromParts($html, [], [], $this->referenceMap());

        foreach (self::PLACES as $n => [$key]) {
            $this->assertNotNull($out[$key]['printed'], "place #$n ($key) was not read");
            $this->assertSame('html', $out[$key]['source']);
        }
        $this->assertSame(6940.0, $out['rent']['parsed']);
        $this->assertSame('2027-03-01', $out['start_date']['parsed']);
        $this->assertSame('2028-02-28', $out['end_date']['parsed']);
        $this->assertSame('2028-01-31', $out['earliest_termination_date']['parsed']);
        $this->assertSame(7.5, $out['escalation_percent']['parsed']);
        $this->assertSame(3, $out['escalation_month']['parsed']);
        $this->assertSame(2, $out['adults']['parsed']);
        $this->assertSame(150.0, $out['other_deduction']['parsed']);
        $this->assertSame(5991.9, $out['net_to_owner']['parsed']);
    }

    public function test_an_agency_whose_map_has_no_schedule_carries_none_of_it(): void
    {
        $map = $this->referenceMap();
        foreach (['agent_service_fee', 'other_deduction', 'net_to_owner'] as $scheduleKey) {
            unset($map[$scheduleKey]);
        }
        $html = '<span data-field="monthly_rental">R6 940</span><span data-field="lets_assists_fee">R 150.00</span><span data-field="owner_nett">R 5 000</span>';

        $out = app(LeaseAgreementValuesReader::class)->readFromParts($html, [], [], $map);

        $this->assertArrayNotHasKey('other_deduction', $out);
        $this->assertArrayNotHasKey('agent_service_fee', $out);
        $this->assertArrayNotHasKey('net_to_owner', $out);
        $this->assertArrayHasKey('rent', $out);
        $this->assertSame([], app(LeaseAgreementTemplateGuard::class)->mapProblems($map), 'a lease with no schedule is still linkable');
    }

    /** @return array<string, array{field: string}> */
    private function referenceMap(): array
    {
        $map = [];
        foreach (self::PLACES as [$key, $field]) {
            $map[$key] = ['field' => $field];
        }

        return $map;
    }
}
