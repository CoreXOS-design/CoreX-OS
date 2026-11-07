<?php

namespace Tests\Feature\SoloAgent;

use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\Billing\SubscriptionPricingService;
use App\Services\SoloAgent\SoloAgentProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Solo Agent Accounts, build step 3 - the flat R450 price and the 5-a-month AI template allowance:
 * .ai/specs/solo-agent-accounts.md section 5a.
 */
class SoloBillingAndAiAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    /** @return array{agency: Agency, user: User} */
    private function solo(string $email = 'sam@lonestar.test'): array
    {
        return app(SoloAgentProvisioner::class)->provision([
            'agency_name' => 'Lone Star Realty',
            'name'        => 'Sam Solo',
            'email'       => $email,
        ]);
    }

    private function activate(User $user): void
    {
        $user->update(['is_active' => true]);
    }

    private function quote(Agency $agency): \App\Services\Billing\BillingQuote
    {
        return app(SubscriptionPricingService::class)->quoteFor($agency->fresh());
    }

    public function test_an_active_solo_agent_is_billed_the_flat_fee_as_one_single_agent_line(): void
    {
        ['agency' => $agency, 'user' => $user] = $this->solo();
        $this->activate($user);

        $q = $this->quote($agency);

        $this->assertTrue($q->isSolo);
        $this->assertSame('Single agent', $q->planLabel);
        $this->assertSame(450.0, $q->payableZar);
        $this->assertCount(1, $q->lines);
        $this->assertSame('Single agent', $q->lines[0]['label']);
        $this->assertSame(450.0, $q->lines[0]['amount']);
    }

    public function test_the_agents_billing_page_shows_single_agent_and_the_fee(): void
    {
        ['agency' => $agency, 'user' => $user] = $this->solo();
        $this->activate($user);

        $this->actingAs($user->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Single agent')
            ->assertSee('450')
            ->assertSee('Your account')
            ->assertSee('single-agent account')
            ->assertDontSee('move into another band');
    }

    public function test_the_fee_comes_from_config_and_not_from_the_band_list(): void
    {
        ['agency' => $agency, 'user' => $user] = $this->solo();
        $this->activate($user);

        config(['solo-agent.monthly_fee_zar' => 520]);

        $this->assertSame(520.0, $this->quote($agency)->payableZar);
    }

    public function test_an_account_still_awaiting_its_signed_agreement_owes_nothing(): void
    {
        ['agency' => $agency] = $this->solo();   // login is inactive until the agreement is signed

        $q = $this->quote($agency);

        $this->assertSame(0.0, $q->payableZar);
        $this->assertSame([], $q->lines);
    }

    public function test_the_owners_custom_amount_and_discount_controls_still_work_on_a_solo_account(): void
    {
        ['agency' => $agency, 'user' => $user] = $this->solo();
        $this->activate($user);

        AgencySubscription::forAgency((int) $agency->id)->update(['custom_amount_zar' => 300]);
        $custom = $this->quote($agency);
        $this->assertSame(300.0, $custom->payableZar);
        $this->assertSame(450.0, $custom->computedZar, 'the list price stays visible next to the agreed amount');
    }

    public function test_a_normal_agency_is_still_priced_by_its_band(): void
    {
        $normal = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);
        User::factory()->count(3)->create(['agency_id' => $normal->id, 'is_active' => 1]);

        $q = $this->quote($normal);

        $this->assertFalse($q->isSolo);
        $this->assertSame(1495.0, $q->payableZar, '3 agents sit in the 3-to-5 band');
        $this->assertNotSame('Single agent', $q->planLabel);
    }

    public function test_provisioning_sets_the_ai_template_allowance_to_five(): void
    {
        ['agency' => $agency] = $this->solo();

        $this->assertSame(5, app(AiTemplateBuilderService::class)->limitFor($agency->id));
    }

    public function test_a_normal_agency_keeps_the_default_allowance(): void
    {
        $normal = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);

        $this->assertSame(AiTemplateBuilderService::DEFAULT_LIMIT, app(AiTemplateBuilderService::class)->limitFor($normal->id));
    }

    public function test_the_allowance_comes_from_config(): void
    {
        config(['solo-agent.ai_template_monthly_limit' => 7]);
        ['agency' => $agency] = $this->solo('other@lonestar.test');

        $this->assertSame(7, app(AiTemplateBuilderService::class)->limitFor($agency->id));
    }
}
