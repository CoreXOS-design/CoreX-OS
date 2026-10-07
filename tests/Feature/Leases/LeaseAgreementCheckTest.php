<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Services\Rentals\LeaseAgreementCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.8 (Build L3c) — the comparison of what a lease agreement PRINTS with the lease record. Every
 * row of §15.8.3, one fixture per edit route (so a change is caught whichever way it was made — the whole point of
 * comparing end states, §15.8.1), a value that cannot be read, a different person, the fingerprint, and the fallbacks
 * (no document, no map, an agency whose lease carries only some fields).
 *
 * The agency is a Cape Town agency with its OWN field names — nothing here is HFC's (CLAUDE.md #9).
 */
final class LeaseAgreementCheckTest extends TestCase
{
    use BuildsLeaseAgreementFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgreementFixture();
    }

    private function verdict(Lease $lease, array $entered = []): array
    {
        return app(LeaseAgreementCheck::class)->verdict($lease->fresh(), $entered);
    }

    private function row(array $verdict, string $key): array
    {
        $row = collect($verdict['rows'])->firstWhere('key', $key);
        $this->assertNotNull($row, "no row for {$key}");

        return $row;
    }

    // ═══ nothing changed → nothing to see ═══

    public function test_an_agreement_that_prints_what_the_lease_holds_has_nothing_to_confirm(): void
    {
        [$lease] = $this->agreementOut();

        $v = $this->verdict($lease);

        $this->assertTrue($v['applicable']);
        $this->assertFalse($v['has_differences']);
        $this->assertFalse($v['needs_confirmation']);
        $this->assertFalse($v['blocked']);
        $this->assertSame([], $v['differences']);
        $this->assertSame([], $v['cannot_verify']);
        $this->assertNotNull($v['fingerprint']);
        $this->assertCount(9, $v['rows'], 'one row per mapped key');
        $this->assertSame(['agree'], collect($v['rows'])->pluck('state')->unique()->values()->all());
    }

    public function test_how_a_value_is_printed_does_not_matter_only_what_it_is(): void
    {
        [$lease, , $document] = $this->agreementOut();

        $this->print($document, [
            'rent_f' => 'R 8 500,00', 'start_f' => '1 November 2026', 'end_f' => '31/10/2027',
            'esc_f' => '7,5 %', 'escm_f' => 'november', 'adults_f' => ' 2 ', 'pets_f' => "  one   CAT ",
        ]);

        $v = $this->verdict($lease);

        $this->assertFalse($v['needs_confirmation'], json_encode($v['differences']));
    }

    // ═══ a changed value, by every route e-sign has ═══

    public function test_a_changed_rent_is_a_difference_the_agent_may_accept(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00']);

        $v = $this->verdict($lease);

        $this->assertTrue($v['has_differences']);
        $this->assertTrue($v['needs_confirmation']);
        $this->assertFalse($v['blocked']);
        $row = $this->row($v, 'rent');
        $this->assertSame('differs', $row['state']);
        $this->assertTrue($row['acceptable']);
        $this->assertSame('R8 500', $row['lease']);
        $this->assertSame('R6 940', $row['agreement']);
        $this->assertSame(6940.0, $row['agreement_value']);
        $this->assertSame(['type' => 'lease', 'column' => 'rental_amount'], $row['target']);
    }

    /**
     * Whichever store the changed value ended up in, the document PRINTS it, so the comparison finds it. One case per
     * route §15.1 #15 lists: the agent's field save (fields_json / overlay), a signer's web fields (field_values), the
     * completion merge (a flat key), a struck-and-reworded span.
     */
    #[DataProvider('editRoutes')]
    public function test_a_changed_value_is_found_whichever_route_it_was_edited_by(string $route): void
    {
        [$lease, , $document] = $this->agreementOut();

        // The span is on the page but unfilled — so the changed value lives only in the store the route writes to.
        $data = ['canonical_html' => '<p><span data-field="rent_f"></span></p>'];
        switch ($route) {
            case 'html span': // the printed truth itself
                $data = ['canonical_html' => '<p><span data-field="rent_f">6940.00</span></p>'];
                break;
            case 'fill-review overlay': // agent's saveAgentWebFields
                $data['_fill_review_overlay'] = ['rent_f' => '6940.00'];
                break;
            case 'signer field_values': // a signer's saveWebFields
                $data['field_values'] = ['rent_f' => '6940.00'];
                break;
            case 'flat key': // completeWeb's merge
                $data['rent_f'] = '6940.00';
                break;
            case 'struck and reworded':
                $data = ['canonical_html' => '<p><span data-field="rent_f"><del>8500.00</del><ins>6940.00</ins></span></p>'];
                break;
        }
        $document->forceFill(['web_template_data' => $data])->save();
        if ($route === 'fields_json list') { // the agent's saveAgentFields: a LIST of field objects
            $document->forceFill([
                'web_template_data' => ['canonical_html' => '<p>no spans</p>'],
                'fields_json' => [['field_name' => 'rent_f', 'value' => '6940.00']],
            ])->save();
        }

        $row = $this->row($this->verdict($lease), 'rent');

        $this->assertSame('differs', $row['state'], "route: {$route}");
        $this->assertSame(6940.0, $row['agreement_value'], "route: {$route}");
    }

    public static function editRoutes(): array
    {
        return array_map(fn ($r) => [$r], ['html span', 'fill-review overlay', 'signer field_values', 'flat key', 'fields_json list', 'struck and reworded']);
    }

    public function test_each_lease_and_agreement_field_is_compared_by_its_own_type(): void
    {
        [$lease, , $document] = $this->agreementOut();

        $this->print($document, [
            'start_f' => '2026-12-01', 'end_f' => '2028-01-31', 'adults_f' => '3', 'pets_f' => 'Two dogs',
            'esc_f' => '9', 'escm_f' => 'January',
        ]);

        $v = $this->verdict($lease);
        $byKey = collect($v['differences'])->keyBy('key');

        $this->assertSame(['adults', 'end_date', 'escalation_month', 'escalation_percent', 'pets', 'start_date'], $byKey->keys()->sort()->values()->all());
        $this->assertSame('1 December 2026', $byKey['start_date']['agreement']);
        $this->assertSame('1 November 2026', $byKey['start_date']['lease']);
        $this->assertSame(['type' => 'terms', 'column' => 'adults'], $byKey['adults']['target']);
        $this->assertSame('January', $byKey['escalation_month']['agreement']);
        $this->assertSame('November', $byKey['escalation_month']['lease']);
        $this->assertSame('9 %', $byKey['escalation_percent']['agreement']);
        $this->assertSame('7.5 %', $byKey['escalation_percent']['lease']);
    }

    public function test_an_agreement_detail_the_lease_never_held_is_a_difference_not_an_error(): void
    {
        // The agent typed "pets" into Fill & review for a lease that was captured without it (e.g. a wet-ink first lease).
        [$lease] = $this->agreementOut();
        $lease->agreementTerms()->first()->forceFill(['pets' => null])->save();

        $row = $this->row($this->verdict($lease), 'pets');

        $this->assertSame('differs', $row['state']);
        $this->assertNull($row['lease']);
        $this->assertSame('One cat', $row['agreement']);
    }

    public function test_an_agency_specific_extra_in_the_map_is_compared_as_text_and_kept_in_extra(): void
    {
        [$lease, , $document] = $this->agreementOut([], map: $this->map + ['garden_service' => 'garden_f']);
        $lease->agreementTerms()->first()->forceFill(['extra' => ['garden_service' => 'Landlord pays']])->save();
        $this->print($document, ['garden_f' => 'Tenant pays']);

        $row = $this->row($this->verdict($lease), 'garden_service');

        $this->assertSame('differs', $row['state']);
        $this->assertSame('Landlord pays', $row['lease']);
        $this->assertSame('Tenant pays', $row['agreement']);
        $this->assertSame(['type' => 'extra', 'column' => 'garden_service'], $row['target']);
    }

    // ═══ month-to-month, deposit ═══

    public function test_a_month_to_month_lease_with_no_end_date_printed_agrees_and_a_printed_date_differs(): void
    {
        [$lease, , $document] = $this->agreementOut(['is_month_to_month' => true, 'end_date' => null]);
        $this->print($document, ['end_f' => '']);
        $this->assertSame('agree', $this->row($this->verdict($lease), 'end_date')['state']);

        $this->print($document, ['end_f' => 'Month-to-month']);
        $this->assertSame('agree', $this->row($this->verdict($lease), 'end_date')['state'], 'the words are not a date, and not a difference');

        $this->print($document, ['end_f' => '2027-10-31']);
        $row = $this->row($this->verdict($lease), 'end_date');
        $this->assertSame('differs', $row['state']);
        $this->assertNull($row['lease'], 'the lease has no end date on record');
    }

    public function test_the_deposit_is_compared_only_when_the_agencys_agreement_carries_it(): void
    {
        [$lease, , $document] = $this->agreementOut(['deposit_amount' => 17000]);
        $this->print($document, ['deposit_f' => '15000.00']);

        // Not mapped: the agreement prints the deposit as a clause, not a field — never compared.
        $this->assertNull(collect($this->verdict($lease)['rows'])->firstWhere('key', 'deposit'));

        $mapped = $this->agreementOut(['deposit_amount' => 17000], map: $this->map + ['deposit' => 'deposit_f']);
        $this->print($mapped[2], ['deposit_f' => '15000.00']);
        $row = $this->row($this->verdict($mapped[0]), 'deposit');
        $this->assertSame('differs', $row['state']);
        $this->assertSame(15000.0, $row['agreement_value']);
    }

    // ═══ a value that cannot be read ═══

    public function test_a_blank_where_the_lease_has_a_value_cannot_be_verified_and_a_blank_on_both_sides_agrees(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $lease->agreementTerms()->first()->forceFill(['pets' => null])->save();
        $this->print($document, ['end_f' => '', 'pets_f' => '']);

        $v = $this->verdict($lease);

        $this->assertSame('cannot_verify', $this->row($v, 'end_date')['state']);
        $this->assertSame('agree', $this->row($v, 'pets')['state'], 'nothing on either side');
        $this->assertTrue($v['needs_confirmation']);
        $this->assertFalse($v['has_differences']);
        $this->assertSame(['end_date'], collect($v['cannot_verify'])->pluck('key')->all());
    }

    public function test_text_where_a_number_belongs_cannot_be_verified(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => 'seven thousand', 'start_f' => 'next month']);

        $v = $this->verdict($lease);

        $this->assertSame('cannot_verify', $this->row($v, 'rent')['state']);
        $this->assertSame('cannot_verify', $this->row($v, 'start_date')['state']);
        $this->assertSame('seven thousand', $this->row($v, 'rent')['agreement']);
    }

    public function test_what_the_agent_types_stands_in_for_the_unreadable_value_and_is_marked_as_typed(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => 'seven thousand', 'end_f' => '']);

        $v = $this->verdict($lease, ['rent' => '7000', 'end_date' => '2027-10-31']);

        $rent = $this->row($v, 'rent');
        $this->assertSame('differs', $rent['state']);
        $this->assertTrue($rent['entered']);
        $this->assertSame(7000.0, $rent['agreement_value']);
        $end = $this->row($v, 'end_date');
        $this->assertSame('agree', $end['state'], 'typed the same as the lease holds');
        $this->assertTrue($end['entered']);
        $this->assertSame([], $v['cannot_verify']);
        $this->assertNotNull($v['fingerprint']);
    }

    public function test_a_typed_value_that_is_still_unreadable_stays_cannot_verify(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '']);

        $v = $this->verdict($lease, ['rent' => 'lots']);

        $this->assertSame('cannot_verify', $this->row($v, 'rent')['state']);
    }

    // ═══ people — never acceptable ═══

    public function test_a_different_tenant_in_the_agreement_blocks_and_is_never_acceptable(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['tenant_f' => 'Sipho Dlamini']);

        $v = $this->verdict($lease);

        $row = $this->row($v, 'tenant_name');
        $this->assertSame('blocked', $row['state']);
        $this->assertFalse($row['acceptable']);
        $this->assertNull($row['target']);
        $this->assertTrue($v['blocked']);
        $this->assertTrue($v['has_differences']);
        $this->assertSame('Thandi Nkosi', $row['lease']);
        $this->assertSame('Sipho Dlamini', $row['agreement']);
    }

    #[DataProvider('sameTenantPrintedDifferently')]
    public function test_the_same_person_printed_another_way_is_not_a_different_person(string $printed): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['tenant_f' => $printed]);

        $this->assertSame('agree', $this->row($this->verdict($lease), 'tenant_name')['state'], $printed);
    }

    public static function sameTenantPrintedDifferently(): array
    {
        return [
            ['THANDI NKOSI'], ['Nkosi, Thandi'], ['Mrs Thandi  Nkosi'], ['Thandi Nkosi (ID 8002025009081)'],
            ['Thandi Nkosi — ID No. 8002025009081'],
        ];
    }

    public function test_an_extra_person_or_a_missing_person_is_a_different_person(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $second = $this->contact('Sipho', 'Dlamini', '9001015009087');

        $this->print($document, ['tenant_f' => 'Thandi Nkosi and Sipho Dlamini']);
        $this->assertSame('blocked', $this->row($this->verdict($lease), 'tenant_name')['state'], 'printed two, the lease has one');

        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $second->id, 'is_primary' => false]);
        $this->assertSame('agree', $this->row($this->verdict($lease), 'tenant_name')['state'], 'now the lease has both');

        $this->print($document, ['tenant_f' => 'Thandi Nkosi']);
        $this->assertSame('blocked', $this->row($this->verdict($lease), 'tenant_name')['state'], 'printed one, the lease has two');
    }

    public function test_the_landlord_is_compared_with_the_propertys_landlord(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['landlord_f' => 'Anna Smit']);

        $this->assertSame('blocked', $this->row($this->verdict($lease), 'landlord_name')['state']);
    }

    public function test_one_party_in_a_family_of_name_fields_is_matched_in_order(): void
    {
        $second = $this->contact('Sipho', 'Dlamini', '9001015009087');
        [$lease, , $document] = $this->agreementOut(map: ['rent' => 'rent_f', 'start_date' => 'start_f', 'tenant_name_1' => 't1_f', 'tenant_name_2' => 't2_f', 'landlord_name' => 'landlord_f']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $second->id, 'is_primary' => false]);
        $this->print($document, ['t1_f' => 'Thandi Nkosi', 't2_f' => 'Sipho Dlamini']);

        $v = $this->verdict($lease);
        $this->assertSame('agree', $this->row($v, 'tenant_name_1')['state']);
        $this->assertSame('agree', $this->row($v, 'tenant_name_2')['state']);

        $this->print($document, ['t1_f' => 'Sipho Dlamini', 't2_f' => 'Thandi Nkosi']);
        $this->assertSame('blocked', $this->row($this->verdict($lease), 'tenant_name_1')['state'], 'primary first');
    }

    public function test_contact_ids_and_addresses_are_checked_against_the_contact_record(): void
    {
        [$lease, , $document] = $this->agreementOut(map: $this->map + ['tenant_id' => 'tid_f', 'tenant_address' => 'taddr_f']);
        $this->print($document, ['tid_f' => '8002025009081', 'taddr_f' => '4 Beach Road, Umhlanga']);
        $this->assertFalse($this->verdict($lease)['needs_confirmation']);

        $this->print($document, ['tid_f' => '8002025009999', 'taddr_f' => '12 Other Road, Umhlanga']);
        $v = $this->verdict($lease);
        $this->assertSame('blocked', $this->row($v, 'tenant_id')['state']);
        $this->assertSame('blocked', $this->row($v, 'tenant_address')['state']);
    }

    public function test_a_company_may_be_followed_by_whoever_represents_it(): void
    {
        $company = new Contact;
        $company->forceFill([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_kind' => Contact::TYPE_ENTITY,
            'entity_name' => 'Bay Holdings (Pty) Ltd', 'email' => 'bay@example.test',
        ])->save();
        [$lease, , $document] = $this->agreementOut();
        $lease->tenants()->delete();
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $company->id, 'is_primary' => true]);

        $this->print($document, ['tenant_f' => 'Bay Holdings (Pty) Ltd, represented by Thandi Nkosi']);
        $this->assertSame('agree', $this->row($this->verdict($lease), 'tenant_name')['state']);

        $this->print($document, ['tenant_f' => 'Coast Holdings (Pty) Ltd']);
        $this->assertSame('blocked', $this->row($this->verdict($lease), 'tenant_name')['state']);
    }

    public function test_a_party_key_with_nothing_on_the_contact_side_is_not_compared(): void
    {
        [$lease, , $document] = $this->agreementOut(map: $this->map + ['tenant_id' => 'tid_f']);
        $this->tenant->forceFill(['id_number' => null, 'passport_number' => null])->save();
        $this->print($document, ['tid_f' => 'anything']);

        $this->assertNull(collect($this->verdict($lease)['rows'])->firstWhere('key', 'tenant_id'));
    }

    // ═══ calculated values — informational only ═══

    public function test_a_calculated_value_that_no_longer_matches_is_information_and_never_opens_the_screen(): void
    {
        [$lease, , $document] = $this->agreementOut(map: $this->map + ['rent_in_words' => 'words_f']);
        $this->print($document, ['words_f' => 'Seven thousand rand only']);

        $v = $this->verdict($lease);

        $row = $this->row($v, 'rent_in_words');
        $this->assertSame('informational', $row['state']);
        $this->assertFalse($v['needs_confirmation'], 'a calculated value alone never opens the confirm screen');
        $this->assertCount(1, $v['informational']);
    }

    public function test_the_words_are_judged_against_the_rent_being_accepted_not_the_old_rent(): void
    {
        [$lease, , $document] = $this->agreementOut(map: $this->map + ['rent_in_words' => 'words_f']);
        $this->print($document, ['rent_f' => '6940.00', 'words_f' => \App\Support\AmountInWords::rands(6940)]);

        $v = $this->verdict($lease);

        $this->assertSame('differs', $this->row($v, 'rent')['state']);
        $this->assertSame('agree', $this->row($v, 'rent_in_words')['state'], 'the words match the new rent');
    }

    // ═══ the fingerprint ═══

    public function test_the_fingerprint_follows_the_printed_values_and_nothing_else(): void
    {
        [$lease, , $document] = $this->agreementOut();

        $first = $this->verdict($lease)['fingerprint'];
        $this->assertSame($first, $this->verdict($lease)['fingerprint'], 'stable while nothing is edited');
        $this->assertSame($first, $this->verdict($lease, ['rent' => '1'])['fingerprint'], 'what the agent types is not the document');

        $this->print($document, ['pets_f' => 'Two cats']);
        $second = $this->verdict($lease)['fingerprint'];
        $this->assertNotSame($first, $second);

        $this->print($document, ['pets_f' => 'One cat']);
        $this->assertSame($first, $this->verdict($lease)['fingerprint'], 'edited back → the same document again');
    }

    // ═══ the wording changes ═══

    public function test_wording_changes_are_listed_read_only_and_a_reverted_one_is_not(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $data = (array) $document->web_template_data;
        $data['pending_body_changes'] = [
            ['change_id' => 'a', 'old' => 'no pets', 'new' => 'one cat allowed', 'actor_name' => 'Thandi Nkosi', 'at' => '2026-10-07T09:00:00+02:00'],
            ['change_id' => 'b', 'old' => 'x', 'new' => 'y', 'actor_name' => 'Pieter Botha', 'at' => '2026-10-07T10:00:00+02:00', 'reverted' => true],
        ];
        $document->update(['web_template_data' => $data]);

        $v = $this->verdict($lease);

        $this->assertCount(1, $v['text_changes']);
        $this->assertSame('no pets', $v['text_changes'][0]['old']);
        $this->assertSame('one cat allowed', $v['text_changes'][0]['new']);
        $this->assertSame('Thandi Nkosi', $v['text_changes'][0]['actor']);
        $this->assertFalse($v['needs_confirmation'], 'free text cannot be compared — it is shown, not gated');
    }

    // ═══ fallbacks — never a guess ═══

    public function test_a_lease_with_no_agreement_document_has_nothing_to_compare(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $v = app(LeaseAgreementCheck::class)->verdict($lease);

        $this->assertFalse($v['applicable']);
        $this->assertFalse($v['needs_confirmation']);
        $this->assertNull($v['fingerprint']);
        $this->assertSame([], $v['rows']);
    }

    public function test_a_document_made_from_a_template_nobody_mapped_is_never_guessed_at(): void
    {
        [$lease, , $document, $row] = $this->agreementOut();
        $row->forceFill(['field_map' => null])->save();
        $this->print($document, ['rent_f' => '1.00']);

        $v = $this->verdict($lease);

        $this->assertFalse($v['applicable']);
        $this->assertFalse($v['needs_confirmation']);
    }

    public function test_an_agency_whose_lease_carries_only_some_fields_is_only_asked_about_those(): void
    {
        [$lease, , $document] = $this->agreementOut(map: ['rent' => 'rent_f', 'start_date' => 'start_f', 'tenant_name' => 'tenant_f', 'landlord_name' => 'landlord_f', 'end_date' => 'end_f']);
        // The lease holds adults and pets, but THIS agreement has no such fields: whatever the document says elsewhere is irrelevant.
        $this->print($document, ['adults_f' => '9', 'pets_f' => 'Five lions']);

        $v = $this->verdict($lease);

        $this->assertFalse($v['needs_confirmation']);
        $this->assertSame(['rent', 'start_date', 'end_date', 'tenant_name', 'landlord_name'], collect($v['rows'])->pluck('key')->all());
    }

    public function test_the_check_writes_nothing(): void
    {
        [$lease, , $document] = $this->agreementOut();
        $this->print($document, ['rent_f' => '6940.00', 'tenant_f' => 'Someone Else']);
        $before = [$lease->fresh()->toArray(), $lease->agreementTerms()->first()->toArray(), \App\Models\LeaseEvent::count()];

        $this->verdict($lease);

        $this->assertSame($before, [$lease->fresh()->toArray(), $lease->agreementTerms()->first()->toArray(), \App\Models\LeaseEvent::count()]);
    }
}
