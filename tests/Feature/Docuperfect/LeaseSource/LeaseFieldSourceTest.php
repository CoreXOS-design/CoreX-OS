<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\LeaseSource;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\NamedField;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\Property;
use App\Models\User;
use App\Services\Docuperfect\CdsBindingSuggester;
use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\FieldCatalogue;
use App\Services\Docuperfect\LeaseFieldSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * esign-lease-field-source.md - the "Lease" data source: its named fields exist, a lease field prints the lease's own value at
 * signing, an imported lease's blanks bind to it, the pickers show a curated list, and the AI button can only choose from it.
 */
final class LeaseFieldSourceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        LeaseFieldSource::ensureNamedFields();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'agent_id' => $this->admin->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental', 'commission_percent' => 10,
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 8500, 'deposit_amount' => 8500, 'lease_fee' => 1500, 'start_date' => '2027-03-01', 'end_date' => '2028-02-28',
            'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ]);
        LeaseAgreementTerms::forLease($this->lease)->fill([
            'adults' => 2, 'max_other_persons' => 3, 'pets' => 'One small dog', 'escalation_percent' => 8, 'escalation_month' => 3,
            'renewal_option_months' => 12, 'other_conditions' => 'No smoking inside.', 'earliest_termination_date' => '2028-02-28',
        ])->save();
    }

    public function test_the_lease_fields_exist_as_named_fields_and_the_sync_is_repeatable(): void
    {
        $keys = LeaseFieldSource::keys();
        $this->assertContains('rent', $keys);
        $this->assertContains('escalation_in_words', $keys);
        $rows = NamedField::whereNull('deleted_at')->where('source_type', 'lease')->pluck('source_column')->all();
        $this->assertEqualsCanonicalizing($keys, $rows);

        $this->assertSame(0, LeaseFieldSource::ensureNamedFields(), 'a second run writes nothing new');
        $this->assertSame(count($keys), NamedField::where('source_type', 'lease')->count());
    }

    public function test_a_lease_field_prints_the_leases_own_value(): void
    {
        $v = app(LeaseFieldSource::class)->valuesForStepData(['lease_id' => $this->lease->id]);

        $this->assertSame('R 8,500.00', $v['rent']);
        $this->assertSame('Eight thousand five hundred Rand', $v['rent_in_words']);
        $this->assertSame('Eight thousand five hundred Rand', $v['deposit_in_words']);
        $this->assertSame('1 March 2027', $v['start_date']);
        $this->assertSame('28 February 2028', $v['end_date']);
        $this->assertSame('2', $v['adults']);
        $this->assertSame('3', $v['max_other_persons']);
        $this->assertSame('One small dog', $v['pets']);
        $this->assertSame('8%', $v['escalation_percent']);
        $this->assertSame('eight', $v['escalation_in_words']);
        $this->assertSame('March', $v['escalation_month']);
        $this->assertSame('12', $v['renewal_option_months']);
        $this->assertSame('No smoking inside.', $v['other_conditions']);
        $this->assertSame('R 1,500.00', $v['lease_fee']);
        $this->assertSame('R 7,650.00', $v['net_to_owner'] ?? null, 'rent less the 10% service fee');
    }

    public function test_the_proposed_figures_in_the_flow_win_and_a_flow_without_a_lease_still_fills_the_basics(): void
    {
        $v = app(LeaseFieldSource::class)->valuesForStepData([
            'lease_id' => $this->lease->id,
            'details' => ['monthly_rental' => '9000', 'lease_start' => '2027-04-01', 'lease_end' => '2028-03-31', 'deposit' => '9000'],
        ]);
        $this->assertSame('R 9,000.00', $v['rent']);
        $this->assertSame('1 April 2027', $v['start_date']);
        $this->assertSame('Nine thousand Rand', $v['deposit_in_words']);

        $none = app(LeaseFieldSource::class)->valuesForStepData(['details' => ['monthly_rental' => '5500', 'lease_start' => '2027-01-01']]);
        $this->assertSame('R 5,500.00', $none['rent']);
        $this->assertSame('1 January 2027', $none['start_date']);
        $this->assertArrayNotHasKey('adults', $none);
    }

    public function test_a_percent_is_handed_over_bare_when_the_page_prints_the_sign(): void
    {
        $this->assertSame('8', $this->valueWithPrintedUnit());
    }

    private function valueWithPrintedUnit(): string
    {
        // The page-reading rule lives in LeaseSeedPresentation; here: the percent type is dressed "8%" by default and bare when asked.
        $docs = app(\App\Services\Rentals\LeaseAgreementDocumentValues::class);

        return (string) $docs->dress(8, 'percent', 'bare');
    }

    public function test_an_imported_leases_blanks_bind_to_the_lease_source(): void
    {
        $text = fn (string $v) => ['type' => 'text', 'value' => $v];
        $blank = ['type' => 'field_placeholder'];
        $cds = ['title' => 'LEASE AGREEMENT - RESIDENTIAL', 'sections' => [
            ['type' => 'paragraph', 'content' => [$text('LEASE AGREEMENT - RESIDENTIAL')]],
            ['type' => 'clause', 'content' => [$text("\tThe Rental shall be "), $blank, $text('('), $blank, $text(') per month')]],
            ['type' => 'clause', 'content' => [$text('The lease shall commence on '), $blank, $text(', and shall continue')]],
            ['type' => 'clause', 'content' => [$text("\tThe lease shall expire at midnight on "), $blank, $text(', (the expiry date).')]],
        ]];

        $out = app(CdsParserService::class)->reidentifyFields($cds);
        $names = [];
        foreach ($out['sections'] as $s) {
            foreach ($s['content'] as $i) {
                if (($i['type'] ?? '') === 'field_placeholder') {
                    $names[] = $i['field_name'] ?? '';
                }
            }
        }
        $this->assertSame(['lease.rent', 'lease.rent_in_words', 'lease.start_date', 'lease.end_date'], array_slice($names, 0, 4));

        $bindings = (new CdsBindingSuggester($this->agency->id))->suggest($out)['bindings'];
        $this->assertSame('sf:lease', $bindings[0]['typeKey']);
        $this->assertSame('lease', $bindings[0]['sourceType']);
        $this->assertSame(['agent'], $bindings[0]['editable_by']);
        $this->assertSame('Lease - Start date', $bindings[2]['label']);
    }

    public function test_a_mandate_keeps_its_own_start_date_rule(): void
    {
        $text = fn (string $v) => ['type' => 'text', 'value' => $v];
        $cds = ['title' => 'LETTING MANDATE', 'sections' => [
            ['type' => 'paragraph', 'content' => [$text('LETTING MANDATE')]],
            ['type' => 'clause', 'content' => [$text('This mandate shall commence on '), ['type' => 'field_placeholder'], $text(' and run')]],
        ]];
        $out = app(CdsParserService::class)->reidentifyFields($cds);

        $this->assertSame('deal.start_date', $out['sections'][1]['content'][1]['field_name']);
    }

    public function test_the_pickers_list_the_curated_fields_and_hide_the_database_columns(): void
    {
        $c = app(FieldCatalogue::class);
        $this->assertTrue($c->isCurated('contact', 'id_number'));
        $this->assertTrue($c->isCurated('lease', 'rent'));
        $this->assertTrue($c->isCurated('manual', 'anything'));
        $this->assertFalse($c->isCurated('contact', 'purged_at'));
        $this->assertFalse($c->isCurated('property', 'p24_image_signature'));
        $this->assertFalse($c->isCurated('contact', 'buyer_pipeline_notes'));
    }

    public function test_the_builder_page_offers_the_lease_source_the_switch_and_the_ai_button(): void
    {
        $draft = CdsDraft::create([
            'user_id' => $this->admin->id, 'agency_id' => $this->agency->id, 'template_name' => 'LEASE AGREEMENT - RESIDENTIAL', 'status' => 'draft',
            'cds_json' => ['title' => 'LEASE AGREEMENT - RESIDENTIAL', 'sections' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'x']]]]],
        ]);

        $html = $this->actingAs($this->admin)->get(route('docuperfect.cds.builder', $draft))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="sf:lease">Lease</option>', $html);
        $this->assertStringContainsString('data-ai-suggest', $html);
        $this->assertStringContainsString('data-show-all-fields', $html);
        $this->assertStringContainsString('"curated":true', $html);
    }

    public function test_the_ai_button_can_only_choose_from_the_curated_list(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $rent = NamedField::where('source_type', 'lease')->where('source_column', 'rent')->value('id');
        $junk = NamedField::create(['name' => 'Opt Out Sms', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'opt_out_sms', 'source_contact_type' => 'Lessor'])->id;
        Http::fake(['*/v1/messages' => Http::response([
            'model' => 'claude-sonnet-4-6', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            'content' => [['type' => 'text', 'text' => json_encode([['tag' => 'a', 'id' => $rent], ['tag' => 'b', 'id' => $junk], ['tag' => 'zzz', 'id' => $rent]])]],
        ])]);
        $draft = CdsDraft::create(['user_id' => $this->admin->id, 'agency_id' => $this->agency->id, 'template_name' => 'x', 'status' => 'draft', 'cds_json' => ['sections' => []]]);

        $res = $this->actingAs($this->admin)->postJson(route('docuperfect.cds.builder.aiSuggest', $draft), [
            'blanks' => [['tag' => 'a', 'before' => 'The Rental shall be', 'after' => ''], ['tag' => 'b', 'before' => 'sms', 'after' => '']], 'lease' => true,
        ])->assertOk();

        $this->assertSame([['tag' => 'a', 'named_field_id' => $rent]], $res->json('suggestions'));
    }

    public function test_another_users_draft_cannot_be_sent_to_the_ai(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin', 'is_active' => true]);
        $draft = CdsDraft::create(['user_id' => $other->id, 'agency_id' => $this->agency->id, 'template_name' => 'x', 'status' => 'draft', 'cds_json' => ['sections' => []]]);

        $this->actingAs($this->admin)->postJson(route('docuperfect.cds.builder.aiSuggest', $draft), ['blanks' => [['tag' => 'a']]])->assertForbidden();
    }
}
