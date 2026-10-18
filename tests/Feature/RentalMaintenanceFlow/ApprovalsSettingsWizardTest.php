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
 * .ai/specs/rental-work-orders.md §17.14 / CLAUDE.md #10a — Build 2's settings (the fee on an outside contractor's quote: type + value; the
 * variation tolerance % and the auto-approved-extra email were REMOVED on 13 Oct 2026, rentals-faults-work-orders.md §22.6: the authorised amount is a
 * hard lock) are on the Rental Work Orders settings page AND in the Setup Wizard with their explain/affects copy, saved by ONE narrow has()-guarded saver that can never wipe a setting a wizard step
 * did not render (agency-onboarding-setup.md §6.1), with an explicit currentValues() arm each so a reopened step shows the saved value.
 */
final class ApprovalsSettingsWizardTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Settings Agency', 'slug' => 'settings-' . uniqid()]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function setting(Agency $agency, array $attrs): void
    {
        RentalWorkOrderSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $agency->id], $attrs);
    }

    private const KEYS = ['external_quote_markup_type', 'external_quote_markup_value'];

    // ── the Setup Wizard ─────────────────────────────────────────────

    public function test_the_wizard_step_shows_the_fee_controls_and_no_tolerance_controls(): void
    {
        $agency = $this->agency();

        $this->actingAs($this->admin($agency))->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()
            ->assertSee('how it is worked out')
            ->assertSee('0 = no fee')
            ->assertSee('What this changes:')
            ->assertDontSee('Extra work the owner is not asked about')
            ->assertDontSee('agreed tolerance')
            ->assertDontSee('name="variation_tolerance_percent"', false)
            ->assertDontSee('name="notify_landlord_on_auto_variation"', false);
    }

    public function test_the_step_saves_the_fee_settings_and_reopening_shows_them(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $fields = array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency), [
            'external_quote_markup_type' => 'amount',
            'external_quote_markup_value' => '150',
        ]);
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('amount', RentalWorkOrderSetting::externalQuoteMarkupTypeFor($agency->id));
        $this->assertSame(150.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($agency->id));

        // currentValues() resolves each key explicitly — never the hardcoded default
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()
            ->assertSee('value="150"', false)->assertSee('<option value="amount" selected', false);
    }

    public function test_a_post_that_never_rendered_the_controls_wipes_nothing(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $this->setting($agency, ['variation_tolerance_percent' => 8, 'notify_landlord_on_auto_variation' => false, 'external_quote_markup_type' => 'amount', 'external_quote_markup_value' => 99]);   // 8 / false: the removed settings' stored data

        $fields = array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency));
        foreach (self::KEYS as $key) {
            unset($fields[$key]);
        }
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields)->assertRedirect()->assertSessionHasNoErrors();

        $row = RentalWorkOrderSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->first();
        $this->assertSame(8.0, (float) $row->variation_tolerance_percent, 'stored data of the removed setting is kept, untouched');
        $this->assertSame('amount', RentalWorkOrderSetting::externalQuoteMarkupTypeFor($agency->id));
        $this->assertSame(99.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($agency->id));
    }

    // ── the settings page and its saver ──────────────────────────────

    public function test_the_settings_page_renders_the_section_with_the_neutral_defaults(): void
    {
        $agency = $this->agency();

        $this->actingAs($this->admin($agency))->get(route('corex.settings.rental-work-orders.edit'))->assertOk()
            ->assertSee('Owner approvals: extra work and contractor fee')
            ->assertDontSee('name="variation_tolerance_percent"', false)
            ->assertDontSee('name="notify_landlord_on_auto_variation"', false)
            ->assertSee('name="external_quote_markup_value"', false)
            ->assertSee('hard lock');
    }

    public function test_the_saver_saves_each_field_and_a_partial_post_leaves_the_others_alone(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $this->setting($agency, ['variation_tolerance_percent' => 8, 'external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 5]);

        // the type alone changes only itself
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.approvals'), ['external_quote_markup_type' => 'amount'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('amount', RentalWorkOrderSetting::externalQuoteMarkupTypeFor($agency->id));
        $this->assertSame(5.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($agency->id));

        // an empty post changes nothing at all
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.approvals'), [])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('amount', RentalWorkOrderSetting::externalQuoteMarkupTypeFor($agency->id));

        // a posted tolerance is ignored: nothing writes it any more, and the stored value is untouched
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.approvals'), ['variation_tolerance_percent' => '30', 'notify_landlord_on_auto_variation' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $row = RentalWorkOrderSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->first();
        $this->assertSame(8.0, (float) $row->variation_tolerance_percent);

        // zero is a real value
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.approvals'), ['external_quote_markup_value' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($agency->id));
    }

    public function test_bad_values_are_refused_in_plain_words_and_save_nothing(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        foreach ([
            ['external_quote_markup_type' => 'bogus'],
            ['external_quote_markup_value' => '-5'],
            ['external_quote_markup_value' => 'much'],
        ] as $bad) {
            $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.approvals'), $bad)->assertSessionHasErrors(array_key_first($bad));
        }
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.approvals'), ['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => '2000'])
            ->assertSessionHasErrors('external_quote_markup_value');
        $this->assertSame(0.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($agency->id));
    }

    public function test_only_staff_who_manage_work_order_settings_can_save_them_and_agencies_are_independent(): void
    {
        $agency = $this->agency();
        $other = $this->agency();
        $admin = $this->admin($agency);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Second']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        \App\Models\RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'rental_work_orders.manage_settings', 'agency_id' => $agency->id], ['scope' => 'all']);
        \App\Services\PermissionService::clearCache();

        $this->actingAs($agent)->post(route('corex.settings.rental-work-orders.approvals'), ['external_quote_markup_value' => '50'])->assertForbidden();
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.approvals'), ['external_quote_markup_value' => '20'])->assertSessionHasNoErrors();

        $this->assertSame(20.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($agency->id));
        $this->assertSame(0.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($other->id), 'a second agency keeps the neutral default');
    }
}
