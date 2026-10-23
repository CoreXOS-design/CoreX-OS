<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Rentals\LeaseAgreementDocumentValues;
use App\Services\Rentals\LeaseAgreementValuesReader;
use App\Services\Rentals\LeaseSeedPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.4 step 4 / §15.21 — what the launcher writes into the agency's own lease agreement is
 * dressed by TYPE in one place (LeaseAgreementDocumentValues::present): a date the way CoreX documents print dates,
 * money in the document money format, a percentage with its "%". Found on QA1 (23 Oct 2026): a lease prepared for
 * signing printed "2026-11-01", "5500.00" and "10". The stored lease stays raw; what is printed reads back to the
 * same value (the change check parses it); and a template that already prints its own "R" / "%" — or is a native
 * date box — is never given the unit twice.
 */
final class LeaseSeedDisplayFormatsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental', 'commission_percent' => 10,
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 5500, 'deposit_amount' => 11000.5,
            'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'source' => 'manual', 'created_by_user_id' => $agent->id,
        ]);
        $terms = LeaseAgreementTerms::forLease($this->lease);
        $terms->fill(['adults' => 2, 'escalation_percent' => 10, 'escalation_month' => 3, 'earliest_termination_date' => '2027-08-31']);
        $terms->save();
    }

    private function agreement(array $map): RentalLeaseTemplate
    {
        return (new RentalLeaseTemplate)->forceFill(['field_map' => $map]);
    }

    private function fullMap(): array
    {
        return [
            'rent' => ['field' => 'monthly_rental'], 'deposit' => ['field' => 'deposit_amount'],
            'start_date' => ['field' => 'lease_start'], 'end_date' => ['field' => 'lease_end'],
            'escalation_percent' => ['field' => 'annual_escalation'], 'escalation_month' => ['field' => 'esc_month'],
            'adults' => ['field' => 'occupants'], 'earliest_termination_date' => ['field' => 'notice_date'],
            'escalation_in_words' => ['field' => 'esc_words'], 'rent_in_words' => ['field' => 'rent_words'],
        ];
    }

    public function test_every_key_is_dressed_by_its_type_and_the_stored_lease_stays_raw(): void
    {
        $values = app(LeaseAgreementDocumentValues::class)->forLease($this->lease->fresh(), $this->agreement($this->fullMap()));

        $this->assertSame('R 5,500.00', $values['rent']);
        $this->assertSame('R 11,000.50', $values['deposit']);
        $this->assertSame('1 November 2026', $values['start_date']);
        $this->assertSame('31 October 2027', $values['end_date']);
        $this->assertSame('31 August 2027', $values['earliest_termination_date']);
        $this->assertSame('10%', $values['escalation_percent']);
        $this->assertSame('March', $values['escalation_month']);
        $this->assertSame('2', $values['adults']);
        $this->assertSame('ten', $values['escalation_in_words'], 'the words are worked out from the number, not from "10%"');
        $this->assertSame('Five thousand five hundred Rand', $values['rent_in_words']);

        $stored = $this->lease->fresh();
        $this->assertEquals(5500, $stored->rental_amount);
        $this->assertSame('2026-11-01', $stored->start_date->toDateString());
        $this->assertEquals(10, LeaseAgreementTerms::forLease($stored)->escalation_percent);
    }

    public function test_a_fractional_percentage_keeps_its_fraction(): void
    {
        $values = app(LeaseAgreementDocumentValues::class)->forLease($this->lease->fresh(), $this->agreement($this->fullMap()), ['escalation_percent' => '7.5']);

        $this->assertSame('7.5%', $values['escalation_percent']);
        $this->assertSame('seven point five', $values['escalation_in_words']);
    }

    public function test_a_template_that_prints_its_own_unit_is_handed_the_bare_number(): void
    {
        $values = app(LeaseAgreementDocumentValues::class)->forLease(
            $this->lease->fresh(),
            $this->agreement($this->fullMap()),
            null,
            ['rent' => 'bare', 'deposit' => 'bare', 'escalation_percent' => 'bare', 'start_date' => 'iso', 'end_date' => 'iso'],
        );

        $this->assertSame('5,500.00', $values['rent'], 'its "R" is drawn by the template');
        $this->assertSame('11,000.50', $values['deposit']);
        $this->assertSame('10', $values['escalation_percent'], 'its "%" is written after the box');
        $this->assertSame('2026-11-01', $values['start_date'], 'a native date box shows nothing for any other shape');
        $this->assertSame('31 August 2027', $values['earliest_termination_date'], 'a key the template did not flag is still dressed in full');
    }

    public function test_whatever_is_printed_reads_back_to_the_value_on_the_lease(): void
    {
        $dressed = app(LeaseAgreementDocumentValues::class)->forLease($this->lease->fresh(), $this->agreement($this->fullMap()));
        $bare = app(LeaseAgreementDocumentValues::class)->forLease($this->lease->fresh(), $this->agreement($this->fullMap()), null, ['rent' => 'bare', 'escalation_percent' => 'bare']);

        foreach ([$dressed, $bare] as $printed) {
            $this->assertSame(5500.0, LeaseAgreementValuesReader::parseMoney($printed['rent']));
            $this->assertSame('2026-11-01', LeaseAgreementValuesReader::parseDate($printed['start_date']));
            $this->assertSame('2027-10-31', LeaseAgreementValuesReader::parseDate($printed['end_date']));
            $this->assertSame(10.0, LeaseAgreementValuesReader::parsePercent($printed['escalation_percent']));
        }
    }

    public function test_the_service_fee_schedule_reads_the_raw_deduction_not_the_dressed_one(): void
    {
        $terms = LeaseAgreementTerms::forLease($this->lease);
        $terms->extra = ['other_deduction' => '150'];
        $terms->save();
        $map = ['agent_service_fee' => ['field' => 'fee'], 'other_deduction' => ['field' => 'other'], 'net_to_owner' => ['field' => 'net']];

        $values = app(LeaseAgreementDocumentValues::class)->forLease($this->lease->fresh(), $this->agreement($map));

        $this->assertSame('R 550.00', $values['agent_service_fee']);
        $this->assertSame('R 150.00', $values['other_deduction']);
        $this->assertSame('R 4,800.00', $values['net_to_owner'], '5500 − 550 − 150: "R 150.00" must never be what the sum reads');
    }

    public function test_money_typed_as_words_is_kept_as_typed_not_invented_or_blanked(): void
    {
        $values = app(LeaseAgreementDocumentValues::class)->forLease($this->lease->fresh(), $this->agreement(['other_deduction' => ['field' => 'other']]), ['other_deduction' => 'to be agreed']);

        $this->assertSame('to be agreed', $values['other_deduction']);
    }

    // ═══ how the agency's own template prints (LeaseSeedPresentation) ═══

    /** A compiled web template in a temp folder the view finder knows. */
    private function webTemplate(string $markup): Template
    {
        $dir = sys_get_temp_dir() . '/lease-seed-views-' . uniqid();
        mkdir($dir);
        $name = 'seedtpl' . uniqid();
        file_put_contents($dir . '/' . $name . '.blade.php', $markup);
        View::addLocation($dir);

        $template = new Template(['name' => 'Lease ' . $name, 'render_type' => 'web', 'blade_view' => $name, 'is_esign' => true]);
        $template->agency_id = $this->agency->id;
        $template->save();

        return $template;
    }

    private function presentationMap(): array
    {
        return [
            'rent' => ['field' => 'monthly_rental'], 'deposit' => ['field' => 'deposit_amount'],
            'escalation_percent' => ['field' => 'annual_escalation'], 'start_date' => ['field' => 'lease_start'],
            'adults' => ['field' => 'occupants'],
        ];
    }

    public function test_a_plain_text_box_template_gets_everything_dressed_in_full(): void
    {
        $template = $this->webTemplate(
            '<p>Monthly Rental: <span class="corex-field-value" data-field="monthly_rental">{{ $monthly_rental ?? \'\' }}</span></p>'
            . '<p>Deposit: <span class="corex-field-value" data-field="deposit_amount">{{ $deposit_amount ?? \'\' }}</span></p>'
            . '<p>Annual Escalation: <span class="corex-field-value" data-field="annual_escalation">{{ $annual_escalation ?? \'\' }}</span></p>'
            . '<p>Lease Start: <span class="corex-field-value" data-field="lease_start">{{ $lease_start ?? \'\' }}</span></p>'
        );
        $fields = [['id' => 'f1', 'field_name' => 'lease_start', 'type' => 'text']];

        $this->assertSame([], app(LeaseSeedPresentation::class)->for($template, $this->presentationMap(), $fields));
    }

    public function test_a_template_that_draws_the_rand_sign_or_writes_the_percent_sign_is_recognised(): void
    {
        $template = $this->webTemplate(
            '<p>The Rental shall be <span class="field field-medium field-currency" data-field="monthly_rental">{{ $monthly_rental ?? \'\' }}</span> per month</p>'
            . '<p>Deposit R <span data-field="deposit_amount" class="corex-field-value">{{ $deposit_amount ?? \'\' }}</span></p>'
            . '<p>an escalation of <span class="corex-field-value" data-field="annual_escalation">{{ $annual_escalation ?? \'\' }}</span>% per annum</p>'
            . '<p>commence on <span class="corex-field-value" data-field="lease_start">{{ $lease_start ?? \'\' }}</span>, and</p>'
        );
        $fields = [['id' => 'f1', 'field_name' => 'lease_start', 'type' => 'date']];

        $this->assertSame(
            ['rent' => 'bare', 'deposit' => 'bare', 'escalation_percent' => 'bare', 'start_date' => 'iso'],
            app(LeaseSeedPresentation::class)->for($template, $this->presentationMap(), $fields),
        );
    }

    public function test_the_word_rent_or_a_stray_capital_r_is_not_mistaken_for_a_rand_sign(): void
    {
        $template = $this->webTemplate(
            '<p>Rent <span class="corex-field-value" data-field="monthly_rental">{{ $monthly_rental ?? \'\' }}</span></p>'
            . '<p>Deposit Rental <span class="corex-field-value" data-field="deposit_amount">{{ $deposit_amount ?? \'\' }}</span></p>'
        );

        $this->assertSame([], app(LeaseSeedPresentation::class)->for($template, $this->presentationMap(), []));
    }

    public function test_an_agreement_with_no_readable_source_is_dressed_in_full(): void
    {
        $pdf = new Template(['name' => 'Scanned lease', 'render_type' => 'pdf']);

        $this->assertSame([], app(LeaseSeedPresentation::class)->for($pdf, $this->presentationMap(), []));
        $this->assertSame([], app(LeaseSeedPresentation::class)->for(null, $this->presentationMap(), []));
    }
}
