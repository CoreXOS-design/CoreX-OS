<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.14 / non-negotiable #10a — Build 1's three settings (default parts %, default labour %, the
 * estimate wording) reach the Setup Wizard with their own has()-guarded savers and explicit currentValues() arms. The wizard
 * step posts a SUBSET of what the settings page posts (agency-onboarding-setup.md §6.1) — the savers must never wipe what the
 * step did not render.
 */
final class PricingSettingsWizardTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Wizard Realty', 'slug' => 'wizard-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function control(string $key): ?array
    {
        return collect(config('agency-onboarding-copy.leases.controls'))->firstWhere('key', $key);
    }

    public function test_every_pricing_control_is_declared_with_an_explain_and_a_concrete_affects(): void
    {
        foreach (['default_parts_markup_percent', 'default_labour_markup_percent', 'quote_estimate_term', 'catalogue_default_markup_type', 'catalogue_default_markup_value'] as $key) {
            $c = $this->control($key);
            $this->assertNotNull($c, "{$key} must be a wizard control (non-negotiable #10a)");
            $this->assertSame('rental_work_orders', $c['source']);
            $this->assertGreaterThan(60, strlen($c['explain']));
            $this->assertGreaterThan(60, strlen($c['affects']), 'a concrete, observable consequence — never a tautology');
        }
        $savers = collect(config('agency-onboarding-copy.leases.savers'))->where('controller', \App\Http\Controllers\CoreX\RentalWorkOrderPricingSettingsController::class)->pluck('method')->all();
        $this->assertEqualsCanonicalizing(['updateDefaultMarkups', 'updateQuoteEstimateTerm', 'updateCatalogueMarkup'], $savers);
    }

    public function test_the_wizard_page_shows_the_saved_values_not_a_stale_default(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'default_parts_markup_percent' => 17.5, 'default_labour_markup_percent' => 42, 'quote_estimate_term' => 'Our own estimate words.']);

        $fields = $this->browserFormFields($this->admin, 'leases');

        $this->assertEquals(17.5, (float) $fields['default_parts_markup_percent']);
        $this->assertEquals(42.0, (float) $fields['default_labour_markup_percent']);
        $this->assertSame('Our own estimate words.', $fields['quote_estimate_term']);
    }

    public function test_a_fresh_agency_sees_the_neutral_defaults(): void
    {
        $fields = $this->browserFormFields($this->admin, 'leases');

        $this->assertEquals(0.0, (float) $fields['default_parts_markup_percent']);
        $this->assertEquals(0.0, (float) $fields['default_labour_markup_percent']);
        $this->assertSame(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM, $fields['quote_estimate_term']);
    }

    public function test_saving_the_real_step_form_saves_the_markups_and_leaves_an_untouched_wording_following_the_default(): void
    {
        $fields = $this->browserFormFields($this->admin, 'leases') + $this->alpineListRows($this->agency);
        $fields['default_parts_markup_percent'] = '20';
        $fields['default_labour_markup_percent'] = '';

        $this->actingAs($this->admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields)->assertRedirect()->assertSessionHasNoErrors();

        $row = RentalWorkOrderSetting::where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertEquals(20.0, (float) $row->default_parts_markup_percent);
        $this->assertNull($row->default_labour_markup_percent, 'blank = back to the neutral default');
        $this->assertNull($row->quote_estimate_term, 'the wording was posted back unchanged, so it is not frozen as a copy of today\'s default');
    }

    public function test_the_wizard_saves_a_rewritten_estimate_term(): void
    {
        $fields = $this->browserFormFields($this->admin, 'leases') + $this->alpineListRows($this->agency);
        $fields['quote_estimate_term'] = 'Wizard-written estimate wording.';

        $this->actingAs($this->admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Wizard-written estimate wording.', RentalWorkOrderSetting::quoteEstimateTermFor($this->agency->id));
    }

    public function test_the_catalogue_default_markup_reaches_the_wizard_shows_its_saved_value_and_saves_from_the_real_step_form(): void
    {
        // AT-458 / §17.38 — a fresh agency sees the neutral default (percent, 0)...
        $fields = $this->browserFormFields($this->admin, 'leases');
        $this->assertSame('percent', $fields['catalogue_default_markup_type']);
        $this->assertEquals(0.0, (float) $fields['catalogue_default_markup_value']);

        // ...saving the untouched step leaves the markup alone, and a changed one is saved
        $fields = $fields + $this->alpineListRows($this->agency);
        $fields['catalogue_default_markup_type'] = 'rand';
        $fields['catalogue_default_markup_value'] = '25';
        $this->actingAs($this->admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('rand', RentalWorkOrderSetting::catalogueMarkupTypeFor($this->agency->id));
        $this->assertEquals(25.0, RentalWorkOrderSetting::catalogueMarkupValueFor($this->agency->id));

        // and the page now shows what was saved, not the stale default
        $again = $this->browserFormFields($this->admin, 'leases');
        $this->assertSame('rand', $again['catalogue_default_markup_type']);
        $this->assertEquals(25.0, (float) $again['catalogue_default_markup_value']);
    }

    public function test_a_saver_given_only_one_key_never_touches_the_others(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'default_parts_markup_percent' => 11, 'default_labour_markup_percent' => 22, 'quote_estimate_term' => 'Keep me.']);

        // the settings-page route, posting ONLY the parts box (a subset, as the wizard may)
        $this->actingAs($this->admin)->post(route('corex.settings.rental-work-orders.default-markups'), ['default_parts_markup_percent' => 30])->assertRedirect();

        $row = RentalWorkOrderSetting::where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertEquals(30.0, (float) $row->default_parts_markup_percent);
        $this->assertEquals(22.0, (float) $row->default_labour_markup_percent, 'an absent key is never reset');
        $this->assertSame('Keep me.', $row->quote_estimate_term);

        // and the estimate saver ignores the markups entirely
        $this->actingAs($this->admin)->post(route('corex.settings.rental-work-orders.quote-estimate-term'), ['quote_estimate_term' => 'New words.'])->assertRedirect();
        $row->refresh();
        $this->assertEquals(30.0, (float) $row->default_parts_markup_percent);
        $this->assertEquals(22.0, (float) $row->default_labour_markup_percent);
    }

    public function test_a_request_with_none_of_the_keys_is_an_error_not_a_silent_save(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.rental-work-orders.default-markups'), [])->assertSessionHasErrors('default_parts_markup_percent');
        $this->assertNull(RentalWorkOrderSetting::where('agency_id', $this->agency->id)->first());
    }

    public function test_markups_outside_0_to_1000_are_refused(): void
    {
        foreach (['-1', '1001', 'lots'] as $bad) {
            $this->actingAs($this->admin)->post(route('corex.settings.rental-work-orders.default-markups'), ['default_parts_markup_percent' => $bad])->assertSessionHasErrors('default_parts_markup_percent');
        }
        $this->assertNull(RentalWorkOrderSetting::where('agency_id', $this->agency->id)->value('default_parts_markup_percent'));
    }

    public function test_a_user_without_the_permission_is_refused_by_the_saver_itself_which_the_wizard_bypasses_middleware_for(): void
    {
        $nobody = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->admin->branch_id, 'role' => 'agent']);
        \App\Models\RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'rental_work_orders.manage_settings', 'agency_id' => $this->agency->id], ['scope' => 'all']);
        \App\Services\PermissionService::clearCache();

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['default_parts_markup_percent' => 5]);
        $request->setUserResolver(fn () => $nobody);

        try {
            app(\App\Http\Controllers\CoreX\RentalWorkOrderPricingSettingsController::class)->updateDefaultMarkups($request);
            $this->fail('expected a 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull(RentalWorkOrderSetting::where('agency_id', $this->agency->id)->first());
    }
}
