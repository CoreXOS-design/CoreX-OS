<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\User;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New-agency walk, 14 Oct 2026 (cc3): the Proforma step and the Market Intelligence step threw a 500 on first
 * save for a brand-new agency — the wizard called every saver with the request alone, but those two savers
 * also type-hint a service. A new agency must be able to save EVERY wizard step.
 *
 * Two layers, so the class cannot come back:
 *  - every saver the wizard config names can be called the way the wizard calls it (container resolves the rest);
 *  - a brand-new agency, created the way the owner creates one and with the first admin's role, posts each step
 *    exactly as a browser would and none of them errors.
 */
final class AgencySetupEveryStepSavesTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;

    private function newAgencyWithAdmin(): array
    {
        // Colours as the owner's create form stamps them (AgencyController::store) — the branding step posts them back.
        $agency = Agency::create([
            'name' => 'Cape Rentals ' . uniqid(), 'slug' => 'cape-' . uniqid(), 'agency_type' => 'rentals',
            'sidebar_color' => '#0ea5e9', 'icon_color' => '#0ea5e9', 'default_color' => '#0b2a4a', 'button_color' => '#0ea5e9',
        ]);
        app(AgencyTypeFeatureSeeder::class)->seedNewAgency($agency);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);
        $agency->update(['default_branch_id' => $branch->id]);
        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        return [$agency, $admin];
    }

    private function switchOn(Agency $agency, string ...$keys): void
    {
        foreach ($keys as $key) {
            \App\Models\AgencyFeature::withoutGlobalScopes()->updateOrCreate(
                ['agency_id' => $agency->id, 'feature_key' => $key], ['enabled' => true]
            );
        }
        app(\App\Services\Features\AgencyFeatureService::class)->forget($agency->id);
    }

    public function test_every_wizard_saver_can_be_called_with_only_what_the_wizard_provides(): void
    {
        $bad = [];
        foreach (config('agency-onboarding-copy') as $step => $cfg) {
            foreach (($cfg['savers'] ?? []) as $saver) {
                $m = new \ReflectionMethod($saver['controller'], $saver['method']);
                foreach ($m->getParameters() as $p) {
                    $type = $p->getType();
                    $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                    $given = $name === \Illuminate\Http\Request::class || ($name === Agency::class && ! empty($saver['pass_agency']));
                    $resolvable = $name !== null && ! $type->isBuiltin() && class_exists($name);
                    if (! $given && ! $resolvable && ! $p->isOptional()) {
                        $bad[] = "{$step}: {$saver['controller']}@{$saver['method']} needs \${$p->getName()}";
                    }
                }
            }
        }
        $this->assertSame([], $bad, "These wizard savers need an argument the wizard cannot supply:\n" . implode("\n", $bad));
    }

    public function test_the_proforma_step_saves_for_a_brand_new_agency(): void
    {
        [$agency, $admin] = $this->newAgencyWithAdmin();
        $this->switchOn($agency, 'proforma-invoices');   // a rentals agency starts without it; the step only exists when it is on

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'proforma']), array_replace($this->browserFormFields($admin, 'proforma'), [
                'number_prefix' => 'CPT-', 'number_padding' => 5, 'due_date_rule' => 'days_after', 'due_days' => 14,
                'bank_details' => 'Cape Rentals, acc 123',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = \App\Models\Proforma\AgencyProformaSettings::forAgency($agency->id);
        $this->assertSame('CPT-', $row->number_prefix);
        $this->assertSame(14, (int) $row->due_days);
    }

    public function test_the_market_intelligence_step_saves_for_a_brand_new_agency(): void
    {
        [$agency, $admin] = $this->newAgencyWithAdmin();
        // Market Intelligence is a Prospecting step; a rentals agency has Prospecting off, so the step is skipped
        // (that is the point of the type). Switch it on, as an admin who wants it can.
        $this->switchOn($agency, 'prospecting');

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'market_intelligence']), array_replace($this->browserFormFields($admin, 'market_intelligence'), [
                'listing_off_market_days' => 45,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(45, (int) app(\App\Services\Prospecting\ProspectingConfigurationService::class)
            ->getSuggestedActionThresholds($agency->id)->listing_off_market_days);
    }

    public function test_no_step_errors_when_a_new_agency_walks_the_whole_wizard(): void
    {
        [$agency, $admin] = $this->newAgencyWithAdmin();
        // Every gated step reachable.
        $this->switchOn($agency, 'prospecting', 'road-to-sold', 'proforma-invoices', 'agency-tracker', 'commission-management', 'auctions', 'core-matches');
        // Values a brand-new agency has not got yet and a person must type (the form has no default for them).
        $typed = [
            // The colour pickers are Alpine-bound (:value), so they are not in the server HTML a test reads — a browser posts them.
            'branding' => ['sidebar_color' => '#0ea5e9', 'icon_color' => '#0ea5e9', 'default_color' => '#0b2a4a', 'button_color' => '#0ea5e9'],
            'commission' => [
                'commission_split_agent' => 70, 'annual_cap' => 1000000, 'post_cap_transaction_fee' => 500, 'post_cap_fee_cap' => 5000,
                'post_cap_reduced_fee' => 250, 'monthly_platform_fee' => 1000, 'risk_management_fee' => 300, 'risk_management_cap' => 3000,
                'mentor_program_enabled' => '0', 'mentor_extra_split' => 20, 'mentor_transactions' => 3, 'revenue_share_enabled' => '0',
                'revenue_share_pool_percent' => 10,
                'tier_1_percent' => 5, 'tier_2_percent' => 4, 'tier_3_percent' => 3, 'tier_4_percent' => 2, 'tier_5_percent' => 1,
                'tier_6_percent' => 1, 'tier_7_percent' => 1, 'tier_4_flqa_requirement' => 5, 'tier_5_flqa_requirement' => 10,
                'tier_6_flqa_requirement' => 15, 'tier_7_flqa_requirement' => 20,
            ],
        ];

        $failures = [];
        foreach (AgencyOnboardingSetup::STEPS as $step) {
            if (empty(config("agency-onboarding-copy.$step.savers"))) {
                continue;
            }
            $page = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => $step]));
            if ($page->getStatusCode() !== 200) {
                continue; // gated off for this agency (adaptive step-gating) — nothing to save
            }
            $fields = array_replace($this->browserFormFields($admin, $step), $this->alpineListRows($agency), $typed[$step] ?? []);
            $response = $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => $step]), $fields);

            $status = $response->getStatusCode();
            $errors = session('errors')?->all() ?? [];
            if ($status >= 500 || $errors !== []) {
                $failures[] = "{$step}: HTTP {$status} " . implode(' | ', $errors);
            }
        }

        $this->assertSame([], $failures, "Wizard steps that did not save for a new agency:\n" . implode("\n", $failures));
    }
}
