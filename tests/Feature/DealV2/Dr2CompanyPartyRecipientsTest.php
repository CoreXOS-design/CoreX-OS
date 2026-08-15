<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\Deal;
use App\Models\User;
use App\Services\DealV2\Dr2DistributionComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 company (entity) party — proxy-aware deal-email recipients (spec: dr2-company-selection).
 *
 * A COMPANY seller/buyer has no email of its own; the deal emails must reach its natural-person
 * representative(s), resolved through cc1's shared proxy-aware foundation
 * (Contact::emailRepresentatives()/representatives()) + the agent's stored routing mode on
 * deal_contacts.representative_email_mode. DR2 re-implements no capacity/proxy logic of its own.
 */
class Dr2CompanyPartyRecipientsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{deal:Deal, company:Contact, reps:array<int,Contact>} */
    private function makeCompanyDeal(string $role, ?string $mode, bool $proxyOnSecond): array
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $company = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Beaumont Prop CC', 'entity_reg_no' => '2015/123456/07',
            'first_name' => 'Beaumont Prop CC', 'last_name' => '', 'phone' => '', 'created_by_user_id' => $agent->id,
        ]));

        $reps = [];
        foreach ([['Piet', 'Direkteur'], ['Sannie', 'Bestuur']] as $i => [$fn, $ln]) {
            $reps[$i] = Contact::withoutEvents(fn () => Contact::create([
                'agency_id' => $agency->id, 'branch_id' => $branch->id,
                'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => $fn, 'last_name' => $ln,
                'email' => strtolower($fn) . Str::random(3) . '@ex.co.za', 'phone' => '082' . random_int(1000000, 9999999),
                'created_by_user_id' => $agent->id,
            ]));
            ContactRepresentative::create([
                'entity_contact_id' => $company->id, 'representative_contact_id' => $reps[$i]->id, 'capacity' => 'Director',
            ]);
        }
        if ($proxyOnSecond) {
            $company->representatives()->updateExistingPivot($reps[1]->id, ['signs_as_proxy' => true]);
        }

        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'created_by_id' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'deal_v2_id' => $twinId,
        ]));

        DB::table('deal_contacts')->insert([
            'deal_id' => $deal->id, 'contact_id' => $company->id, 'role' => $role,
            'representative_email_mode' => $mode, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['deal' => $deal, 'company' => $company, 'reps' => $reps];
    }

    private function emails(array $recipients): array
    {
        return collect($recipients)->pluck('email')->filter()->sort()->values()->all();
    }

    /** inherit + a proxy configured → ONLY the proxy rep is emailed (proxy signs for all). */
    public function test_inherit_with_proxy_emails_only_the_proxy(): void
    {
        $ctx = $this->makeCompanyDeal('seller', 'inherit', proxyOnSecond: true);
        $recipients = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'seller');

        $this->assertSame([$ctx['reps'][1]->email], $this->emails($recipients), 'only the proxy rep receives');
        $this->assertSame($ctx['company']->entity_name, $recipients[0]['on_behalf_of']);
    }

    /** all → EVERY representative is emailed, even when a proxy exists (agent override). */
    public function test_all_mode_emails_every_rep_overriding_proxy(): void
    {
        $ctx = $this->makeCompanyDeal('seller', 'all', proxyOnSecond: true);
        $recipients = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'seller');

        $this->assertSame(
            collect($ctx['reps'])->pluck('email')->sort()->values()->all(),
            $this->emails($recipients),
            'all reps receive when mode=all'
        );
    }

    /** inherit + NO proxy → ALL reps are emailed (default: everyone signs). */
    public function test_inherit_without_proxy_emails_all_reps(): void
    {
        $ctx = $this->makeCompanyDeal('seller', 'inherit', proxyOnSecond: false);
        $recipients = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'seller');

        $this->assertCount(2, $recipients);
        $this->assertSame(
            collect($ctx['reps'])->pluck('email')->sort()->values()->all(),
            $this->emails($recipients)
        );
    }

    /** The same resolution applies to a COMPANY buyer. */
    public function test_company_buyer_routes_to_proxy(): void
    {
        $ctx = $this->makeCompanyDeal('buyer', 'proxy', proxyOnSecond: true);
        $recipients = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'buyer');

        $this->assertSame([$ctx['reps'][1]->email], $this->emails($recipients));
    }

    /** The company itself (no email) is NEVER a recipient — it is not dropped silently, it is replaced. */
    public function test_company_itself_is_never_a_recipient(): void
    {
        $ctx = $this->makeCompanyDeal('seller', 'inherit', proxyOnSecond: false);
        $recipients = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'seller');

        $ids = collect($recipients)->pluck('id')->all();
        $this->assertNotContains($ctx['company']->id, $ids, 'the entity is replaced by its reps, never emailed directly');
    }

    /** @return array{user:User, company:Contact} a company with NO reps + an authorised user. */
    private function makeCompanyNoReps(): array
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'B']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);
        $company = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_kind' => Contact::TYPE_ENTITY,
            'entity_name' => 'NoReps CC', 'first_name' => 'NoReps CC', 'last_name' => '', 'phone' => '',
        ]));
        return ['user' => $user, 'company' => $company];
    }

    /** "No reps yet" inline-add: DR2 delegates to cc1's create-and-link — creates + links with capacity. */
    public function test_inline_add_representative_creates_and_links_with_capacity(): void
    {
        ['user' => $user, 'company' => $company] = $this->makeCompanyNoReps();
        $this->assertCount(0, $company->representatives()->get());

        $this->actingAs($user)
            ->postJson(route('deals-dr2.company.add-representative', ['contact' => $company->id]),
                ['first_name' => 'Nomsa', 'last_name' => 'Director', 'email' => 'nomsa@ex.co.za', 'capacity' => 'Director'])
            ->assertOk()
            ->assertJsonPath('reps.0.name', 'Nomsa Director')
            ->assertJsonPath('reps.0.capacity', 'Director')
            ->assertJsonPath('reps.0.has_email', true);

        $this->assertCount(1, $company->fresh()->representatives()->get());
    }

    /** Inline-add with proxy → the entity resolves to that single proxy (cc1's single-proxy rule). */
    public function test_inline_add_proxy_representative_narrows_recipients(): void
    {
        ['user' => $user, 'company' => $company] = $this->makeCompanyNoReps();

        $this->actingAs($user)->postJson(route('deals-dr2.company.add-representative', ['contact' => $company->id]),
            ['first_name' => 'Piet', 'last_name' => 'Proxy', 'email' => 'piet@ex.co.za', 'capacity' => 'Executor', 'signs_as_proxy' => 1])->assertOk();
        $this->actingAs($user)->postJson(route('deals-dr2.company.add-representative', ['contact' => $company->id]),
            ['first_name' => 'Ann', 'last_name' => 'Other', 'email' => 'ann@ex.co.za', 'capacity' => 'Director'])->assertOk();

        $company->refresh();
        $this->assertTrue($company->hasProxyRepresentative());
        $this->assertCount(2, $company->representatives()->get());
        $this->assertCount(1, $company->emailRepresentatives(), 'proxy set → only the proxy is emailed');
    }

    /** first_name is required — the delegated validation surfaces as JSON 422, never a silent create. */
    public function test_inline_add_requires_first_name(): void
    {
        ['user' => $user, 'company' => $company] = $this->makeCompanyNoReps();
        $this->actingAs($user)
            ->postJson(route('deals-dr2.company.add-representative', ['contact' => $company->id]), ['last_name' => 'NoFirst'])
            ->assertStatus(422)->assertJsonValidationErrors('first_name');
        $this->assertCount(0, $company->fresh()->representatives()->get());
    }
}
