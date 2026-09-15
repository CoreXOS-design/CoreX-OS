<?php

declare(strict_types=1);

namespace Tests\Feature\BuyerPipeline;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\BuyerStateTransition;
use App\Models\Contact;
use App\Models\User;
use App\Services\BuyerStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan (2026-09-15): "the buyer going lost after nothing for 60 days
 * should be something an agency turns on and sets the days stale before
 * moving. I dont like it happening silently."
 *
 * Also fixes a real pre-existing bug found while building this: buyer_lost_
 * days was a saved, validated, on-screen setting that resolveState() never
 * read — anything past buyer_cold_days became 'lost' immediately regardless
 * of its value. Buyers already Lost purely because of that old 30-day
 * fallthrough are NOT un-Lost by this change — nothing here rewrites an
 * existing buyer_state, only how a FUTURE recompute resolves one.
 */
final class AutoLostSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Auto Lost Test Agency', 'slug' => 'auto-lost-test-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    private function staleBuyer(int $daysSinceActivity): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Stale', 'last_name' => 'Buyer', 'is_buyer' => true,
            'buyer_state' => 'cold',
            'last_activity_at' => now()->subDays($daysSinceActivity),
        ]);
    }

    public function test_auto_lost_is_off_by_default_and_a_stale_buyer_stays_cold(): void
    {
        $settings = AgencyContactSettings::forAgency($this->agency->id);
        $this->assertFalse($settings->buyerAutoLostEnabled());

        $contact = $this->staleBuyer(90); // well past the default 60-day buyer_lost_days
        $resolved = app(BuyerStateService::class)->resolveState($contact);

        $this->assertSame('cold', $resolved, 'a stale buyer must never auto-transition to lost while the setting is off');
    }

    public function test_auto_lost_on_transitions_a_stale_buyer_to_lost_past_the_threshold(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update([
            'buyer_auto_lost_enabled' => true,
            'buyer_lost_days' => 60,
        ]);

        $contact = $this->staleBuyer(61);
        $resolved = app(BuyerStateService::class)->resolveState($contact);

        $this->assertSame('lost', $resolved);
    }

    public function test_the_3060_bug_is_fixed_a_buyer_at_45_days_stays_cold_not_lost(): void
    {
        // Before this fix, anything past buyer_cold_days (default 30) became
        // 'lost' immediately. 45 days is past cold (30) but short of the
        // real 60-day lost threshold, and short of the toggle even mattering
        // here since it's not past buyer_lost_days at all.
        AgencyContactSettings::forAgency($this->agency->id)->update(['buyer_auto_lost_enabled' => true]);

        $contact = $this->staleBuyer(45);
        $resolved = app(BuyerStateService::class)->resolveState($contact);

        $this->assertSame('cold', $resolved, 'buyer_lost_days (60) must be the real threshold, not buyer_cold_days (30)');
    }

    public function test_gate_before_write_no_auto_recompute_transition_row_is_ever_created_while_off(): void
    {
        // cc2's link-expiry dependency: an auto_recompute-to-lost transition
        // row must not exist at all while the setting is off, not merely be
        // ignored by some other layer after the fact.
        $contact = $this->staleBuyer(90);

        $service = app(BuyerStateService::class);
        $newState = $service->resolveState($contact);
        if ($newState !== $contact->buyer_state) {
            $service->transitionTo($contact, $newState, 'auto_recompute');
        }

        $this->assertSame(0, BuyerStateTransition::where('contact_id', $contact->id)
            ->where('reason', 'auto_recompute')->where('to_state', 'lost')->count());
    }

    public function test_days_until_auto_lost_is_null_when_the_agency_setting_is_off(): void
    {
        $contact = $this->staleBuyer(55); // 5 days short of the default 60-day threshold
        $this->assertNull(app(BuyerStateService::class)->daysUntilAutoLost($contact));
    }

    public function test_days_until_auto_lost_returns_remaining_days_inside_the_warning_window(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update([
            'buyer_auto_lost_enabled' => true,
            'buyer_lost_days' => 60,
            'buyer_lost_warning_days' => 7,
        ]);

        $contact = $this->staleBuyer(55); // 5 days remaining, inside the 7-day warning window
        $this->assertSame(5, app(BuyerStateService::class)->daysUntilAutoLost($contact));
    }

    public function test_days_until_auto_lost_is_null_outside_the_warning_window(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update([
            'buyer_auto_lost_enabled' => true,
            'buyer_lost_days' => 60,
            'buyer_lost_warning_days' => 7,
        ]);

        $contact = $this->staleBuyer(40); // 20 days remaining -- not yet at risk
        $this->assertNull(app(BuyerStateService::class)->daysUntilAutoLost($contact));
    }

    public function test_days_until_auto_lost_is_singular_at_exactly_one_day(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update([
            'buyer_auto_lost_enabled' => true,
            'buyer_lost_days' => 60,
            'buyer_lost_warning_days' => 7,
        ]);

        $contact = $this->staleBuyer(59);
        $this->assertSame(1, app(BuyerStateService::class)->daysUntilAutoLost($contact));
    }

    public function test_days_until_auto_lost_is_null_for_a_buyer_already_lost(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update(['buyer_auto_lost_enabled' => true]);
        $contact = $this->staleBuyer(55);
        $contact->buyer_state = 'lost';
        $contact->save();

        $this->assertNull(app(BuyerStateService::class)->daysUntilAutoLost($contact));
    }

    public function test_days_until_auto_lost_is_null_for_a_buyer_already_won(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update(['buyer_auto_lost_enabled' => true]);
        $contact = $this->staleBuyer(55);
        $contact->buyer_state = BuyerStateService::WON;
        $contact->save();

        $this->assertNull(app(BuyerStateService::class)->daysUntilAutoLost($contact));
    }

    public function test_warning_window_is_clamped_to_never_exceed_the_lost_threshold(): void
    {
        // Defence in depth behind the save-time validation refusal -- a row
        // that predates the clamp, or a buyer_lost_days lowered afterward,
        // must never produce a nonsense reading (warning window bigger than
        // the thing it's warning about).
        $settings = AgencyContactSettings::forAgency($this->agency->id);
        $settings->forceFill(['buyer_lost_days' => 7, 'buyer_lost_warning_days' => 14])->save();

        $this->assertSame(7, $settings->fresh()->buyerLostWarningDays());
    }

    public function test_saving_a_warning_window_larger_than_the_lost_threshold_is_refused(): void
    {
        $this->actingAs($this->agent);

        $response = $this->put(route('command-center.settings.contact-governance.update'), $this->validPayload([
            'buyer_lost_days' => 7,
            'buyer_lost_warning_days' => 14,
        ]));

        $response->assertSessionHasErrors('buyer_lost_warning_days');
    }

    public function test_an_agencys_existing_custom_lost_threshold_survives_a_settings_save(): void
    {
        // Proves the value MOVED WITH the relocated input, not reset to
        // default: an agency that already has 45 must still have 45 after
        // this build, not silently fall back to the new default location.
        AgencyContactSettings::forAgency($this->agency->id)->update(['buyer_lost_days' => 45]);

        $this->actingAs($this->agent);
        $this->put(route('command-center.settings.contact-governance.update'), $this->validPayload([
            'buyer_lost_days' => 45,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(45, AgencyContactSettings::forAgency($this->agency->id)->fresh()->buyer_lost_days);
    }

    public function test_the_auto_lost_toggle_and_warning_days_actually_save(): void
    {
        $this->actingAs($this->agent);

        $this->put(route('command-center.settings.contact-governance.update'), $this->validPayload([
            'buyer_auto_lost_enabled' => '1',
            'buyer_lost_warning_days' => 10,
            'buyer_lost_days' => 60,
        ]))->assertSessionHasNoErrors();

        $settings = AgencyContactSettings::forAgency($this->agency->id)->fresh();
        $this->assertTrue($settings->buyer_auto_lost_enabled);
        $this->assertSame(10, $settings->buyer_lost_warning_days);
    }

    /** Every field the real form posts, so a validation test only fails on the field it's actually testing. */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'buyer_pipeline_default_scope' => 'own',
            'buyer_kanban_column_limit' => 50,
            'duplicate_mode' => 'soft_warn',
            'duplicate_match_fields' => ['phone', 'email'],
            'address_match_mode' => 'standard',
            'buyer_warm_days' => 14,
            'buyer_cold_days' => 30,
            'buyer_lost_days' => 60,
            'buyer_lost_warning_days' => 7,
            'core_matches_working_window_days' => 7,
            'outreach_no_response_days' => 7,
            'contact_retention_years' => 5,
            'consent_retention_years' => 5,
            'access_log_retention_years' => 5,
        ], $overrides);
    }
}
