<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Events\Mandate\MandateExpired;
use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertyExpiryPopupView;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\Properties\MandateExpiryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-448 — Expired status, expiry warning pop-up, expiry lock + Extension document.
 *
 * Spec: .ai/specs/at448-property-expiry.md §9 (the verification plan this file IS).
 *
 * Input paths proven, per BUILD_STANDARD §5:
 *   status item   — migration per agency, idempotent; defaults for a new agency; slug = system value
 *   document type — migration idempotent; makes a real Drive folder on sale AND rental
 *   tiles/filters — expired (mixed case), expiring_soon (window, off-market, other agency)
 *   popup         — shown in window; hidden outside; hidden off-market; own-scope; announced ONCE;
 *                   announced again after the date moves; dismiss ignores out-of-scope ids; idempotent
 *   lock          — off ⇒ editable; on + draft ⇒ editable; on + live ⇒ 422; fresh extension ⇒ saves
 *                   and re-locks; stale extension ⇒ 422; cleared date ⇒ 422; same date ⇒ ok;
 *                   first date ⇒ ok; soft-deleted extension ⇒ 422; imported stock ⇒ ok
 *   manual Expired — fires MandateExpired once with the actor; console path does not double-fire
 *   settings saver — bounds (0 / 91 / abc), has() guards, no agency ⇒ 403
 */
final class PropertyExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const POPUP_HEADING = 'Mandates expiring soon';

    // ── The status item ─────────────────────────────────────────────────────

    public function test_the_migration_provisions_expired_per_agency_and_is_idempotent(): void
    {
        $agencyA = $this->makeAgency();
        $agencyB = $this->makeAgency();
        $agencyC = $this->makeAgency(); // never configured statuses — must be skipped

        $this->seedStatusItems($agencyA);
        $this->seedStatusItems($agencyB);

        $migration = require database_path('migrations/2026_10_13_100000_add_expired_property_status_item.php');
        $migration->up();

        foreach ([$agencyA, $agencyB] as $agencyId) {
            $item = DB::table('property_setting_items')
                ->where('agency_id', $agencyId)->where('group', 'property_status')->where('name', 'Expired')->first();
            $this->assertNotNull($item, "Agency #{$agencyId} must receive the status.");
            $this->assertEquals(1, $item->active);
            // The dropdown slugs the name this way and the slug IS the stored status.
            $this->assertSame('expired', strtolower(str_replace(' ', '_', $item->name)));
        }

        $this->assertDatabaseMissing('property_setting_items', ['agency_id' => $agencyC, 'name' => 'Expired']);

        $migration->up();
        $this->assertSame(2, DB::table('property_setting_items')->where('name', 'Expired')->count());

        // And it is accepted by the write-side vocabulary guard for that agency.
        Property::clearAllowedStatusCache();
        $this->assertTrue(Property::isAllowedStatus('expired', $agencyA));
    }

    public function test_a_new_agency_gets_expired_in_its_default_statuses(): void
    {
        $names = array_column(PropertySettingItem::DEFAULT_ROWS[PropertySettingItem::GROUP_STATUS], 'name');
        $this->assertContains('Expired', $names);
        // Next to Withdrawn, where an agent looks for an off-market end.
        $this->assertSame(array_search('Withdrawn', $names, true) - 1, array_search('Expired', $names, true));
    }

    // ── The Extension document type ─────────────────────────────────────────

    public function test_the_extension_document_type_migration_is_idempotent_and_makes_a_drive_folder(): void
    {
        DocumentType::withTrashed()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->forceDelete();

        $migration = require database_path('migrations/2026_10_13_100100_add_mandate_extension_document_type.php');
        $migration->up();
        $migration->up();

        $rows = DocumentType::withTrashed()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->get();
        $this->assertCount(1, $rows);
        $type = $rows->first();
        $this->assertSame('Extension', $type->label);
        $this->assertTrue($type->is_active);
        // Without listing_types the type is an upload option but NOT a Drive folder.
        $this->assertTrue($type->appliesToListingType('sale'));
        $this->assertTrue($type->appliesToListingType('rental'));
    }

    // ── Tiles + filters ─────────────────────────────────────────────────────

    public function test_expired_filter_matches_the_status_case_insensitively(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Expired-Lower', ['status' => 'expired']);
        $this->property($agencyId, $admin, 'ZZZ-Expired-Upper', ['status' => 'Expired']);
        $this->property($agencyId, $admin, 'ZZZ-Still-Active');

        $res = $this->get(route('corex.properties.index', ['status' => 'expired']))->assertOk();
        $res->assertSee('ZZZ-Expired-Lower')->assertSee('ZZZ-Expired-Upper')->assertDontSee('ZZZ-Still-Active');
        // The tile exists on the page and the filter option is selected.
        $res->assertSee('Expired');
        $res->assertSee('value="expired" selected', false);
    }

    public function test_expiring_soon_filter_honours_the_agency_window_scope_and_market_state(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        [$otherAgencyId, $otherAdmin] = $this->agencyWithUser('admin');
        $this->actingAs($admin);
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_WARN_DAYS, 10, $agencyId);

        $this->property($agencyId, $admin, 'ZZZ-In-Window',     ['expiry_date' => today()->addDays(5)]);
        $this->property($agencyId, $admin, 'ZZZ-Edge-Today',    ['expiry_date' => today()]);
        $this->property($agencyId, $admin, 'ZZZ-Edge-Last-Day', ['expiry_date' => today()->addDays(10)]);
        $this->property($agencyId, $admin, 'ZZZ-Too-Far',       ['expiry_date' => today()->addDays(11)]);
        $this->property($agencyId, $admin, 'ZZZ-Already-Past',  ['expiry_date' => today()->subDay()]);
        $this->property($agencyId, $admin, 'ZZZ-Withdrawn',     ['expiry_date' => today()->addDays(5), 'status' => 'withdrawn']);
        $this->property($agencyId, $admin, 'ZZZ-No-Date',       ['expiry_date' => null]);
        $this->property($otherAgencyId, $otherAdmin, 'ZZZ-Other-Agency', ['expiry_date' => today()->addDays(5)]);

        $res = $this->get(route('corex.properties.index', ['status' => 'expiring_soon']))->assertOk();
        $res->assertSee('ZZZ-In-Window')->assertSee('ZZZ-Edge-Today')->assertSee('ZZZ-Edge-Last-Day');
        foreach (['ZZZ-Too-Far', 'ZZZ-Already-Past', 'ZZZ-Withdrawn', 'ZZZ-No-Date', 'ZZZ-Other-Agency'] as $hidden) {
            $res->assertDontSee($hidden);
        }
    }

    // ── The popup ───────────────────────────────────────────────────────────

    public function test_popup_announces_a_listing_once_and_again_when_its_date_moves(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser('agent');
        $this->actingAs($agent);
        $p = $this->property($agencyId, $agent, 'ZZZ-Popup-House', ['expiry_date' => today()->addDays(3)]);

        // Default window (7 days) — in.
        $res = $this->get(route('corex.properties.index'))->assertOk();
        $res->assertSee(self::POPUP_HEADING)->assertSee('ZZZ-Popup-House')->assertSee('Expires in 3 days');
        $res->assertSee(route('api.v1.properties.expiry-popup.dismiss', [], false), false);

        // Closing records it — then it is never announced again for this date.
        $this->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [$p->id]])
            ->assertOk()->assertJson(['ok' => true, 'recorded' => 1]);
        $this->assertDatabaseHas('property_expiry_popup_views', [
            'user_id' => $agent->id, 'property_id' => $p->id, 'expiry_date' => today()->addDays(3)->toDateString(),
        ]);
        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee(self::POPUP_HEADING);

        // Idempotent.
        $this->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [$p->id]])->assertOk();
        $this->assertSame(1, PropertyExpiryPopupView::query()->count());

        // The date moves (an extension) but stays inside the window → a new cycle, announced again.
        $p->update(['expiry_date' => today()->addDays(6)]);
        $this->get(route('corex.properties.index'))->assertOk()->assertSee(self::POPUP_HEADING)->assertSee('Expires in 6 days');
    }

    public function test_popup_is_hidden_outside_the_window_and_for_off_market_stock(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser('agent');
        $this->actingAs($agent);

        $this->property($agencyId, $agent, 'ZZZ-Far-Away', ['expiry_date' => today()->addDays(20)]);
        $this->property($agencyId, $agent, 'ZZZ-Withdrawn-Soon', ['expiry_date' => today()->addDays(2), 'status' => 'withdrawn']);
        $this->property($agencyId, $agent, 'ZZZ-Expired-Already', ['expiry_date' => today()->subDays(2), 'status' => 'expired']);

        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee(self::POPUP_HEADING);

        // The agency widens its window → the far one is now announced.
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_WARN_DAYS, 30, $agencyId);
        $this->get(route('corex.properties.index'))->assertOk()->assertSee(self::POPUP_HEADING)->assertSee('ZZZ-Far-Away');
    }

    public function test_popup_and_dismiss_respect_the_users_own_scope(): void
    {
        [$agencyId, $agentA] = $this->agencyWithUser('agent');
        $agentB = $this->user($agencyId, 'agent');
        $this->actingAs($agentA);

        // The popup names listings by ADDRESS (Property::buildDisplayAddress — never the
        // title, CODEBASE_MAP rule 4), so each gets its own street to assert on.
        $mine   = $this->property($agencyId, $agentA, 'ZZZ-Mine',   ['expiry_date' => today()->addDays(2), 'street_number' => '7',  'street_name' => 'Mine Crescent']);
        $theirs = $this->property($agencyId, $agentB, 'ZZZ-Theirs', ['expiry_date' => today()->addDays(2), 'street_number' => '12', 'street_name' => 'Theirs Avenue']);

        $this->get(route('corex.properties.index'))->assertOk()
            ->assertSee(self::POPUP_HEADING)->assertSee('Mine Crescent')->assertDontSee('Theirs Avenue');

        // A crafted dismiss for the other agent's listing is ignored, not an error.
        $this->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [$theirs->id, $mine->id]])
            ->assertOk()->assertJson(['recorded' => 1]);
        $this->assertDatabaseMissing('property_expiry_popup_views', ['property_id' => $theirs->id]);
        $this->assertDatabaseHas('property_expiry_popup_views', ['property_id' => $mine->id, 'user_id' => $agentA->id]);

    }

    /**
     * Owner ruling 2026-10-07: the pop-up is a PERSONAL alert. An admin, branch manager or
     * owner is NOT shown other people's listings — only their own. (Reverses the earlier
     * "admin sees the whole agency" rule; the agency-wide view is the Expiring-soon filter.)
     */
    public function test_the_popup_is_never_widened_by_role(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser('agent');
        $agentsListing = $this->property($agencyId, $agent, 'ZZZ-Agents', ['expiry_date' => today()->addDays(2), 'street_number' => '12', 'street_name' => 'Agents Avenue']);

        foreach (['admin', 'branch_manager'] as $role) {
            $boss = $this->user($agencyId, $role);

            // Sees nothing: the only expiring listing belongs to somebody else.
            $this->actingAs($boss)->get(route('corex.properties.index'))->assertOk()
                ->assertDontSee(self::POPUP_HEADING)->assertDontSee('Agents Avenue');

            // A crafted dismiss for it is ignored too — a boss cannot mark another agent's listing as seen.
            $this->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [$agentsListing->id]])
                ->assertOk()->assertJson(['recorded' => 0]);

            // Their OWN expiring listing does announce — and ONLY that one.
            $own = $this->property($agencyId, $boss, "ZZZ-Own-{$role}", ['expiry_date' => today()->addDays(3), 'street_number' => '5', 'street_name' => ucfirst(str_replace('_', '', $role)) . ' Road']);
            $this->actingAs($boss)->get(route('corex.properties.index'))->assertOk()
                ->assertSee(self::POPUP_HEADING)->assertSee(ucfirst(str_replace('_', '', $role)) . ' Road')->assertDontSee('Agents Avenue');
            $own->delete();
        }

        // The standing, role-wide view is still there on demand.
        $admin = $this->user($agencyId, 'admin');
        $listed = $this->actingAs($admin)->get(route('corex.properties.index', ['status' => 'expiring_soon', 'agent_ids' => 'all']))->assertOk()
            ->viewData('properties')->getCollection()->pluck('id')->all();
        $this->assertContains($agentsListing->id, $listed);
    }

    public function test_the_second_agent_on_a_listing_gets_the_popup_too(): void
    {
        [$agencyId, $lead] = $this->agencyWithUser('agent');
        $second = $this->user($agencyId, 'agent');
        $other  = $this->user($agencyId, 'agent');
        $this->property($agencyId, $lead, 'ZZZ-Shared', [
            'expiry_date' => today()->addDays(2), 'street_number' => '9', 'street_name' => 'Shared Lane', 'pp_second_agent_id' => $second->id,
        ]);

        $this->actingAs($lead)->get(route('corex.properties.index'))->assertSee('Shared Lane');
        $this->actingAs($second)->get(route('corex.properties.index'))->assertSee('Shared Lane');
        $this->actingAs($other)->get(route('corex.properties.index'))->assertDontSee('Shared Lane');
    }

    public function test_dismiss_rejects_malformed_input(): void
    {
        [, $agent] = $this->agencyWithUser('agent');
        $this->actingAs($agent);

        $this->postJson(route('api.v1.properties.expiry-popup.dismiss'), [])->assertStatus(422);
        $this->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => ['abc']])->assertStatus(422);
    }

    /** ?scope= (and any scope posted) has no say: the pop-up is the user's own listings, full stop. */
    public function test_a_scope_in_the_address_or_the_request_changes_nothing(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser('agent');
        $other = $this->user($agencyId, 'agent');
        $this->actingAs($agent);
        $p = $this->property($agencyId, $agent, 'ZZZ-Scope-House', ['expiry_date' => today()->addDays(3)]);
        $this->property($agencyId, $other, 'ZZZ-Other', ['expiry_date' => today()->addDays(3), 'street_number' => '3', 'street_name' => 'Elsewhere Close']);

        foreach (['bogus', 'branch', 'all'] as $scope) {
            $this->get(route('corex.properties.index', ['scope' => $scope]))->assertOk()
                ->assertSee(self::POPUP_HEADING)
                // The pop-up's id list is exactly this user's one listing — the other agent's is
                // not in it (the LIST below may legitimately show it under the branch view).
                ->assertSee("coreXExpiryPopup(JSON.parse('[{$p->id}]'), '" . route('api.v1.properties.expiry-popup.dismiss', [], false) . "')", false);
        }

        // A scope posted to the dismiss call is ignored, not an error.
        $this->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [$p->id], 'scope' => 'everything'])
            ->assertOk()->assertJson(['recorded' => 1]);
    }

    // ── The lock ────────────────────────────────────────────────────────────

    public function test_the_lock_names_the_real_folder_and_go_to_drive_preselects_it(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));

        // The snapshot-bootstrapped test DB carries no reference rows — provision the
        // folder exactly as a deploy does, then give it an older label (an install
        // that already had a hand-made row keeps it: the migration never relabels).
        (require database_path('migrations/2026_10_13_100100_add_mandate_extension_document_type.php'))->up();
        DocumentType::query()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->update(['label' => 'Mandate Extension']);
        $typeId = (int) DocumentType::query()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->value('id');

        $this->actingAs($admin)->get(route('corex.properties.show', $p))->assertOk()
            ->assertSee('Mandate Extension', false)
            ->assertSee("driveTypePref = {$typeId}; activeTab='drive'", false);

        // The server's refusal uses the same name.
        $this->saveEdit($admin, $p, ['expiry_date' => today()->addDays(90)->toDateString()])
            ->assertSessionHasErrors(['expiry_date' => MandateExpiryPolicy::lockMessage()]);
        $this->assertStringContainsString('Mandate Extension folder', MandateExpiryPolicy::lockMessage());
    }

    public function test_lock_off_leaves_the_date_editable_on_a_live_listing(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));

        $this->saveEdit($admin, $p, ['expiry_date' => today()->addDays(90)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(today()->addDays(90)->toDateString(), $p->fresh()->expiry_date->toDateString());
        $this->assertNotNull($p->fresh()->expiry_date_changed_at);
    }

    public function test_lock_on_refuses_to_change_a_live_listings_date_without_a_fresh_extension(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));

        // Later, earlier, and cleared — all are changes.
        foreach ([today()->addDays(90)->toDateString(), today()->addDays(5)->toDateString(), ''] as $attempt) {
            $this->saveEdit($admin, $p, ['expiry_date' => $attempt])->assertSessionHasErrors(['expiry_date']);
            $this->assertSame(today()->addDays(30)->toDateString(), $p->fresh()->expiry_date->toDateString(), "'{$attempt}' must not save");
        }

        // The page says why and offers the way out; the real date input is gone.
        $this->actingAs($admin)->get(route('corex.properties.show', $p))->assertOk()
            ->assertSee('Expiry date locked', false)
            ->assertSee('Go to Drive', false)
            ->assertDontSee('name="expiry_date"', false);

        // The same date re-submitted is not a change.
        $this->saveEdit($admin, $p, ['expiry_date' => today()->addDays(30)->toDateString()])->assertSessionHasNoErrors();
    }

    public function test_lock_on_leaves_drafts_imported_stock_and_first_dates_alone(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);

        // A draft that never went live.
        $draft = $this->property($agencyId, $admin, 'ZZZ-Draft', ['status' => 'draft', 'expiry_date' => today()->addDays(30)]);
        $this->assertFalse(MandateExpiryPolicy::lockState($draft)['locked']);

        // A live listing with no date yet — setting the first date is allowed.
        $noDate = $this->liveListing($agencyId, $admin, null);
        $this->saveEdit($admin, $noDate, ['expiry_date' => today()->addDays(60)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(today()->addDays(60)->toDateString(), $noDate->fresh()->expiry_date->toDateString());
        // …and from now on it is locked.
        $this->assertTrue(MandateExpiryPolicy::lockState($noDate->fresh())['locked']);

        // Imported Stock (ever advertised, by definition — it has a P24 ref) is exempt:
        // typing an expiry date IS the AT-422 takeover.
        $imported = $this->property($agencyId, $admin, 'ZZZ-Imported', [
            'status' => 'Withdrawn', 'p24_imported_at' => now()->subDays(40), 'p24_ref' => '123456789',
            'beds' => 3, 'baths' => 2, 'garages' => 1,
        ]);
        DB::table('properties')->where('id', $imported->id)->update(['expiry_date' => '2025-09-30', 'listed_date' => null]);
        $this->linkContact($agencyId, $admin, $imported);
        $this->assertFalse(MandateExpiryPolicy::lockState($imported->fresh())['locked']);
    }

    public function test_a_fresh_extension_document_unlocks_one_change_and_the_date_locks_again(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));
        $this->assertTrue(MandateExpiryPolicy::lockState($p)['locked']);

        // The agent uploads the signed extension to Drive → Extension (a minute
        // later — the unlock is "uploaded strictly AFTER the last date change").
        $this->travel(1)->minutes();
        $doc = $this->extensionDocument($agencyId, $admin, $p);
        $state = MandateExpiryPolicy::lockState($p->fresh());
        $this->assertFalse($state['locked']);
        $this->assertNotNull($state['unlocked_by_extension_at']);
        $this->actingAs($admin)->get(route('corex.properties.show', $p))->assertOk()
            ->assertSee('Expiry date unlocked', false)
            ->assertSee('name="expiry_date"', false);

        // One change saves…
        $this->saveEdit($admin, $p, ['expiry_date' => today()->addDays(120)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(today()->addDays(120)->toDateString(), $p->fresh()->expiry_date->toDateString());

        // …and the same upload does NOT unlock a second one (it is older than the change).
        $this->travel(1)->minutes();
        $this->assertTrue(MandateExpiryPolicy::lockState($p->fresh())['locked']);
        $this->saveEdit($admin, $p, ['expiry_date' => today()->addDays(200)->toDateString()])->assertSessionHasErrors(['expiry_date']);

        // A soft-deleted extension never counts.
        $this->travel(1)->minutes();
        $second = $this->extensionDocument($agencyId, $admin, $p);
        $this->assertFalse(MandateExpiryPolicy::lockState($p->fresh())['locked']);
        $second->delete();
        $this->assertTrue(MandateExpiryPolicy::lockState($p->fresh())['locked']);
        $this->travelBack();
    }

    public function test_an_unrelated_save_does_not_stamp_the_expiry_change(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));
        $stamp = $p->fresh()->expiry_date_changed_at;
        $this->assertNotNull($stamp);

        $this->travel(5)->minutes();
        $this->saveEdit($admin, $p, ['title' => 'ZZZ-Renamed'])->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->expiry_date_changed_at->equalTo($stamp));
        $this->travelBack();
    }

    // ── Manual Expired ──────────────────────────────────────────────────────

    public function test_choosing_expired_by_hand_fires_mandate_expired_once_with_the_actor(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $p = $this->property($agencyId, $admin, 'ZZZ-Manual-Expire');

        Event::fake([MandateExpired::class]);
        $this->actingAs($admin);

        $p->update(['status' => 'expired']);
        Event::assertDispatchedTimes(MandateExpired::class, 1);
        Event::assertDispatched(MandateExpired::class, fn (MandateExpired $e) => $e->mandate->is($p)
            && $e->actorUserId() === $admin->id
            && $e->agencyId() === $agencyId);

        // Saving again while already expired is not a transition.
        $p->update(['title' => 'ZZZ-Manual-Expire-2']);
        Event::assertDispatchedTimes(MandateExpired::class, 1);

        // The console sweep path (no acting user) fires the event itself — the
        // observer must stay silent so it is never doubled.
        Auth::logout();
        $q = $this->property($agencyId, $admin, 'ZZZ-Sweep-Expire');
        $q->update(['status' => 'expired']);
        Event::assertDispatchedTimes(MandateExpired::class, 1);
    }

    // ── Settings saver ──────────────────────────────────────────────────────

    public function test_settings_saver_validates_bounds_and_writes_per_agency(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $this->actingAs($admin);

        foreach (['0', '91', 'abc', ''] as $bad) {
            $this->from(route('corex.settings', ['s' => 'feature-properties']))
                ->post(route('corex.settings.mandate-expiry'), ['mandate_expiry_warn_days' => $bad])
                ->assertSessionHasErrors(['mandate_expiry_warn_days']);
        }
        $this->assertSame(MandateExpiryPolicy::DEFAULT_WARN_DAYS, MandateExpiryPolicy::warnDaysFor($agencyId));

        $this->post(route('corex.settings.mandate-expiry'), ['mandate_expiry_warn_days' => ' 14 ', 'mandate_expiry_lock_enabled' => '1'])
            ->assertRedirect(route('corex.settings', ['s' => 'feature-properties']));
        $this->assertSame(14, MandateExpiryPolicy::warnDaysFor($agencyId));
        $this->assertTrue(MandateExpiryPolicy::lockEnabledFor($agencyId));
        $this->assertDatabaseHas('performance_settings', ['key' => 'mandate_expiry_warn_days', 'agency_id' => $agencyId, 'value' => '14']);

        // Another agency is untouched (and reads the defaults).
        [$otherAgencyId] = $this->agencyWithUser('admin');
        $this->assertSame(MandateExpiryPolicy::DEFAULT_WARN_DAYS, MandateExpiryPolicy::warnDaysFor($otherAgencyId));
        $this->assertFalse(MandateExpiryPolicy::lockEnabledFor($otherAgencyId));

        // The settings page renders the card with the saved values.
        $this->get(route('corex.settings', ['s' => 'feature-properties']))->assertOk()
            ->assertSee('Mandate Expiry')->assertSee('value="14"', false);
    }

    public function test_settings_saver_refuses_without_an_agency_context(): void
    {
        $nobody = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'admin']);

        $this->actingAs($nobody)
            ->post(route('corex.settings.mandate-expiry'), ['mandate_expiry_warn_days' => '5'])
            ->assertStatus(403);
        $this->assertDatabaseMissing('performance_settings', ['key' => 'mandate_expiry_warn_days']);
    }

    public function test_policy_reads_default_safely_without_an_agency(): void
    {
        $this->assertSame(MandateExpiryPolicy::DEFAULT_WARN_DAYS, MandateExpiryPolicy::warnDaysFor(0));
        $this->assertSame(MandateExpiryPolicy::DEFAULT_WARN_DAYS, MandateExpiryPolicy::warnDaysFor(null));
        $this->assertFalse(MandateExpiryPolicy::lockEnabledFor(0));
        $this->assertSame(0, DB::table('performance_settings')->count(), 'reads must never write');
    }


    // ── Audit fixes (2026-10-07) ────────────────────────────────────────────

    /** Genuinely imported, never-touched Imported Stock through the REAL controller: exempt + takeover, as AT-422. */
    public function test_untouched_imported_stock_saves_a_new_date_through_the_controller_despite_the_lock(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $this->provisionExtensionFolder();

        $imported = $this->importedListing($agencyId, $admin, 'Withdrawn');
        $this->assertTrue($imported->isImportedStock());
        $this->assertTrue($imported->isUntouchedImportedStock());
        $this->assertNull($imported->expiry_lock_engaged_at);
        $this->assertFalse(MandateExpiryPolicy::lockState($imported)['locked'], 'untouched imported stock is exempt');

        $newDate = today()->addDays(45)->toDateString();
        $this->saveEdit($admin, $imported, ['expiry_date' => $newDate])->assertSessionHasNoErrors();

        $fresh = $imported->fresh();
        $this->assertSame($newDate, $fresh->expiry_date->toDateString(), 'the typed date is the takeover date');
        $this->assertNotNull($fresh->imported_released_at, 'the AT-422 takeover happened');
        $this->assertFalse($fresh->isImportedStock());
        // From now on it is an ordinary live-lifecycle property: the lock is in force.
        $this->assertSame('withdrawn', strtolower($fresh->status));
    }

    /** The hole: a LIVE imported listing withdrawn by a user must not become exempt. */
    public function test_withdrawing_a_live_imported_listing_does_not_open_the_lock(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $this->provisionExtensionFolder();

        $live = $this->importedListing($agencyId, $admin, 'active', today()->addDays(30));
        $this->assertFalse($live->isImportedStock(), 'on the market it is not Imported Stock');
        $this->assertTrue(MandateExpiryPolicy::lockState($live)['locked']);

        // Save 1: the user withdraws it (no date change, so the lock does not fire).
        $this->saveEdit($admin, $live, ['status' => 'withdrawn'])->assertSessionHasNoErrors();
        $afterWithdraw = $live->fresh();
        $this->assertSame('withdrawn', strtolower($afterWithdraw->status));
        $this->assertNotNull($afterWithdraw->expiry_lock_engaged_at, 'it lived in CoreX: the lock is engaged');
        // Johan's ruling: it STAYS on the Imported Stock page - membership is untouched.
        $this->assertNull($afterWithdraw->imported_released_at);
        $this->assertTrue($afterWithdraw->isImportedStock());
        $this->assertTrue(Property::query()->importedOffMarket()->whereKey($live->id)->exists());
        $this->assertFalse($afterWithdraw->isUntouchedImportedStock());
        $this->assertSame(today()->addDays(30)->toDateString(), $afterWithdraw->expiry_date->toDateString());
        $this->assertTrue(MandateExpiryPolicy::lockState($afterWithdraw)['locked']);

        // A status-only save (even to another off-market status) never resets or clears the date.
        $this->saveEdit($admin, $afterWithdraw, ['status' => 'sold'])->assertSessionHasNoErrors();
        $this->assertSame(today()->addDays(30)->toDateString(), $live->fresh()->expiry_date->toDateString());
        $this->assertNull($live->fresh()->imported_released_at, 'no takeover for an engaged listing');

        // Save 2: a new date is still refused - and nothing was written.
        $this->saveEdit($admin, $live->fresh(), ['expiry_date' => today()->addDays(200)->toDateString()])
            ->assertSessionHasErrors(['expiry_date']);
        $this->assertSame(today()->addDays(30)->toDateString(), $live->fresh()->expiry_date->toDateString());

        // The property page shows the real locked date, not "Imported".
        $this->actingAs($admin)->get(route('corex.properties.show', $live))->assertOk()
            ->assertSee('Expiry date locked', false);
    }

    /** Spec D4: the midnight sweep must not turn a locked live listing into exempt Imported Stock. */
    public function test_a_swept_expired_imported_listing_stays_locked(): void
    {
        Queue::fake();
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $this->provisionExtensionFolder();

        $live = $this->importedListing($agencyId, $admin, 'active', today()->subDay());

        Artisan::call('mandates:expire');

        $swept = $live->fresh();
        $this->assertSame('expired', strtolower($swept->status));
        $this->assertNotNull($swept->expiry_lock_engaged_at);
        $this->assertNull($swept->imported_released_at, 'it stays on the Imported Stock page');
        $this->assertTrue($swept->isImportedStock());
        $this->assertTrue(MandateExpiryPolicy::lockState($swept)['locked']);
        $this->saveEdit($admin, $swept, ['expiry_date' => today()->addDays(90)->toDateString()])
            ->assertSessionHasErrors(['expiry_date']);
    }

    public function test_an_expired_status_alone_never_unlocks_a_live_listing(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->subDays(3));
        $p->update(['status' => 'expired']);

        $this->assertTrue(MandateExpiryPolicy::lockState($p->fresh())['locked']);
        $this->saveEdit($admin, $p->fresh(), ['expiry_date' => today()->addDays(60)->toDateString()])
            ->assertSessionHasErrors(['expiry_date']);
    }

    public function test_a_non_extension_document_does_not_unlock(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));

        $other = DocumentType::query()->create([
            'slug' => 'title_deed_zzz', 'label' => 'Title Deed', 'grouping' => 'property',
            'listing_types' => ['sale'], 'is_active' => true, 'sort_order' => 5,
        ]);
        $this->travel(1)->minutes();
        $doc = Document::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'original_name' => 'deed.pdf',
            'storage_path' => "properties/{$p->id}/files/deed.pdf", 'disk' => 'local', 'mime_type' => 'application/pdf',
            'size' => 100, 'document_type_id' => $other->id, 'source_type' => 'upload', 'uploaded_by' => $admin->id,
        ]);
        $doc->properties()->attach($p->id);
        // …and an untyped document too.
        $untyped = Document::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'original_name' => 'misc.pdf',
            'storage_path' => "properties/{$p->id}/files/misc.pdf", 'disk' => 'local', 'mime_type' => 'application/pdf',
            'size' => 100, 'document_type_id' => null, 'source_type' => 'upload', 'uploaded_by' => $admin->id,
        ]);
        $untyped->properties()->attach($p->id);

        $this->assertTrue(MandateExpiryPolicy::lockState($p->fresh())['locked']);
        $this->saveEdit($admin, $p->fresh(), ['expiry_date' => today()->addDays(90)->toDateString()])
            ->assertSessionHasErrors(['expiry_date']);
        $this->travelBack();
    }

    /** "Expires today" is inside the pop-up window AND is still a locked change (the sweep expires only < today). */
    public function test_a_mandate_expiring_today_shows_in_the_popup_and_is_locked(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser('agent');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $agent, today());

        $this->actingAs($agent)->get(route('corex.properties.index'))->assertOk()
            ->assertSee(self::POPUP_HEADING)->assertSee('Expires today');

        $state = MandateExpiryPolicy::lockState($p);
        $this->assertTrue($state['locked']);
        $this->assertSame(0, $state['days_left']);
        $this->assertFalse($state['expired'], 'it is still live today - the sweep takes it at midnight');
        $this->saveEdit($agent, $p, ['expiry_date' => today()->addDays(30)->toDateString()])
            ->assertSessionHasErrors(['expiry_date']);
    }

    /** IDOR: another AGENCY's listing id is ignored by the dismiss endpoint. */
    public function test_dismiss_ignores_another_agencys_listing(): void
    {
        [$agencyA, $agentA] = $this->agencyWithUser('agent');
        [$agencyB, $agentB] = $this->agencyWithUser('agent');
        $foreign = $this->property($agencyB, $agentB, 'ZZZ-Foreign', ['expiry_date' => today()->addDays(2)]);
        $mine    = $this->property($agencyA, $agentA, 'ZZZ-Mine-A', ['expiry_date' => today()->addDays(2)]);

        $this->actingAs($agentA)
            ->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [$foreign->id, $mine->id]])
            ->assertOk()->assertJson(['recorded' => 1]);

        $this->assertDatabaseMissing('property_expiry_popup_views', ['property_id' => $foreign->id]);
        $this->assertDatabaseHas('property_expiry_popup_views', ['property_id' => $mine->id, 'user_id' => $agentA->id]);
    }

    /** Only listings the pop-up could have shown (own, on market, in the window) can be marked as seen. */
    public function test_dismiss_records_only_own_on_market_listings_inside_the_window(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser('agent');
        $inWindow = $this->property($agencyId, $agent, 'ZZZ-In', ['expiry_date' => today()->addDays(3)]);
        $farAway  = $this->property($agencyId, $agent, 'ZZZ-Far', ['expiry_date' => today()->addDays(200)]);
        $withdrawn = $this->property($agencyId, $agent, 'ZZZ-Wd', ['expiry_date' => today()->addDays(3), 'status' => 'withdrawn']);

        $this->actingAs($agent)
            ->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [$inWindow->id, $farAway->id, $withdrawn->id]])
            ->assertOk()->assertJson(['recorded' => 1]);

        $this->assertSame([$inWindow->id], PropertyExpiryPopupView::query()->pluck('property_id')->all());

        // Once that far-away listing really enters the window, it still announces (it was never pre-suppressed).
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_WARN_DAYS, 90, $agencyId);
        $farAway->update(['expiry_date' => today()->addDays(60)]);
        $this->get(route('corex.properties.index'))->assertOk()
            ->assertSee(self::POPUP_HEADING)
            ->assertSee("JSON.parse('[{$farAway->id}]')", false);
    }

    public function test_the_dismiss_route_is_permission_and_agency_gated(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.properties.expiry-popup.dismiss');
        $this->assertNotNull($route);
        $this->assertContains('permission:access_properties', $route->gatherMiddleware());
        $this->assertContains('agency.required', $route->gatherMiddleware());

        // An owner with no agency in scope gets a 422 and nothing is written.
        $nobody = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'admin']);
        $status = $this->actingAs($nobody)->postJson(route('api.v1.properties.expiry-popup.dismiss'), ['ids' => [1]])->getStatusCode();
        $this->assertContains($status, [403, 422], 'refused before anything is written');
        $this->assertSame(0, PropertyExpiryPopupView::query()->count());
    }

    /** Manual Expired: the event fires once AND the real listener still runs and dispatches the de-listing job. */
    public function test_manual_expired_dispatches_the_desyndication_job(): void
    {
        Queue::fake();
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $p = $this->property($agencyId, $admin, 'ZZZ-Manual-Desyn');
        $this->actingAs($admin);

        $fired = 0;
        Event::listen(MandateExpired::class, function () use (&$fired) { $fired++; });   // observe; do NOT fake

        $p->update(['status' => 'expired']);

        $this->assertSame(1, $fired, 'MandateExpired fires once for a manual Expired');
        Queue::assertPushed(DesyndicatePropertyFromPortalsJob::class, fn ($job) => $job->property->is($p));
    }

    /** A rejected locked save leaves no orphan files behind (the lock runs before any storing). */
    public function test_a_rejected_locked_save_stores_no_files(): void
    {
        Storage::fake('public');
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));

        $this->saveEditWithFile($admin, $p, ['expiry_date' => today()->addDays(90)->toDateString()])
            ->assertSessionHasErrors(['expiry_date']);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a refused save must not leave uploads on disk');

        // Control: the same upload on a save that is allowed DOES store it (the harness is real).
        $this->saveEditWithFile($admin, $p, [])->assertSessionHasNoErrors();
        $this->assertNotEmpty(Storage::disk('public')->allFiles());
    }

    public function test_the_extension_folder_cannot_be_renamed_deactivated_or_cleared_from_agency_settings(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $type = $this->provisionExtensionFolder();
        $before = DocumentType::query()->find($type->id)->only(['label', 'sort_order', 'is_active', 'listing_types']);

        $this->actingAs($admin)->post(route('admin.settings.document-types.bulk-save'), [
            'types' => [[
                'id' => $type->id, 'label' => 'Hijacked', 'sort_order' => 3, 'is_active' => '0',
                'listing_types' => [],
            ]],
        ])->assertSessionHasNoErrors();

        $after = DocumentType::query()->find($type->id);
        $this->assertSame($before, $after->only(['label', 'sort_order', 'is_active', 'listing_types']));

        // Single-row update and archive are refused too.
        $this->actingAs($admin)->put(route('admin.splitter.doc-types.update', $type->id), [
            'label' => 'Hijacked', 'sort_order' => 1, 'is_active' => 0,
        ])->assertSessionHasErrors(['label']);
        $this->actingAs($admin)->delete(route('admin.splitter.doc-types.destroy', $type->id))->assertSessionHasErrors(['label']);
        $this->assertNull(DocumentType::withTrashed()->find($type->id)->deleted_at);
        $this->assertSame('Extension', DocumentType::query()->find($type->id)->label);

        // An ordinary type still saves normally.
        $ordinary = DocumentType::query()->create([
            'slug' => 'ordinary_zzz', 'label' => 'Ordinary', 'grouping' => 'property',
            'listing_types' => ['sale'], 'is_active' => true, 'sort_order' => 7,
        ]);
        $this->actingAs($admin)->post(route('admin.settings.document-types.bulk-save'), [
            'types' => [['id' => $ordinary->id, 'label' => 'Renamed', 'sort_order' => 7, 'is_active' => '1', 'listing_types' => ['sale']]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $ordinary->fresh()->label);
    }

    /** Never "locked forever": with no usable way out the lock cannot hold. */
    public function test_the_lock_does_not_hold_when_the_extension_folder_is_unusable(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));
        $type = $this->provisionExtensionFolder();
        $this->assertTrue(MandateExpiryPolicy::lockState($p)['locked']);

        $type->update(['is_active' => false]);
        $this->assertFalse(MandateExpiryPolicy::lockState($p->fresh())['locked'], 'inactive folder');
        $type->update(['is_active' => true, 'listing_types' => null]);
        $this->assertFalse(MandateExpiryPolicy::lockState($p->fresh())['locked'], 'folder with no listing types');
        $type->update(['listing_types' => ['sale', 'rental']]);
        $type->delete();
        $this->assertFalse(MandateExpiryPolicy::lockState($p->fresh())['locked'], 'archived folder');
        $type->restore();
        $this->assertTrue(MandateExpiryPolicy::lockState($p->fresh())['locked']);
    }

    public function test_warn_days_clamp_to_the_nearest_bound_and_garbage_falls_back_to_the_default(): void
    {
        [$agencyId] = $this->agencyWithUser('admin');

        foreach ([['500', 90], ['91', 90], ['0', 1], ['-4', 1], ['45', 45], ['abc', MandateExpiryPolicy::DEFAULT_WARN_DAYS]] as [$stored, $expected]) {
            PerformanceSetting::set(MandateExpiryPolicy::SETTING_WARN_DAYS, $stored, $agencyId);
            $this->assertSame($expected, MandateExpiryPolicy::warnDaysFor($agencyId), "stored '{$stored}'");
        }
    }

    /** A stray NULL-agency row (script / tinker) must never configure any agency. */
    public function test_a_global_null_agency_row_never_leaks_into_an_agency(): void
    {
        [$agencyId] = $this->agencyWithUser('admin');
        DB::table('performance_settings')->insert([
            ['agency_id' => null, 'key' => MandateExpiryPolicy::SETTING_LOCK, 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['agency_id' => null, 'key' => MandateExpiryPolicy::SETTING_WARN_DAYS, 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertFalse(MandateExpiryPolicy::lockEnabledFor($agencyId));
        $this->assertSame(MandateExpiryPolicy::DEFAULT_WARN_DAYS, MandateExpiryPolicy::warnDaysFor($agencyId));
        $this->assertNull(PerformanceSetting::get(MandateExpiryPolicy::SETTING_LOCK, null, $agencyId));
    }

    public function test_lock_state_does_no_advertising_lookup_while_the_lock_is_off(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));

        DB::enableQueryLog();
        $state = MandateExpiryPolicy::lockState($p);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertFalse($state['locked']);
        $this->assertStringNotContainsString('property_website_syndication', $queries);
    }

    public function test_has_fresh_extension_document_and_lock_state_share_one_rule(): void
    {
        [$agencyId, $admin] = $this->agencyWithUser('admin');
        PerformanceSetting::set(MandateExpiryPolicy::SETTING_LOCK, 1, $agencyId);
        $p = $this->liveListing($agencyId, $admin, today()->addDays(30));
        $this->assertFalse(MandateExpiryPolicy::hasFreshExtensionDocument($p));

        $this->travel(1)->minutes();
        $this->extensionDocument($agencyId, $admin, $p);
        $this->assertTrue(MandateExpiryPolicy::hasFreshExtensionDocument($p->fresh()));
        $this->assertFalse(MandateExpiryPolicy::lockState($p->fresh())['locked']);
        $this->travelBack();
    }

    // ── Migration safety ────────────────────────────────────────────────────

    public function test_extension_migration_repairs_an_archived_or_inactive_row_and_down_never_hard_deletes(): void
    {
        $type = DocumentType::withTrashed()->firstOrCreate(
            ['slug' => MandateExpiryPolicy::EXTENSION_SLUG],
            ['label' => 'Mandate Extension', 'grouping' => 'property', 'listing_types' => null, 'is_active' => false, 'sort_order' => 4]
        );
        // The schema snapshot now carries this row with the migration's own label ('Extension'), so
        // firstOrCreate() above no longer builds the install-that-already-had-it case: set the label
        // explicitly, or the "label an install already had is kept" assertion below proves nothing.
        $type->update(['label' => 'Mandate Extension', 'is_active' => false, 'listing_types' => null]);
        $type->delete();   // archived AND inactive AND no listing types - the worst case

        $migration = require database_path('migrations/2026_10_13_100100_add_mandate_extension_document_type.php');
        $migration->up();

        $fixed = DocumentType::query()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->first();
        $this->assertNotNull($fixed, 'restored from the archive');
        $this->assertTrue($fixed->is_active);
        $this->assertTrue($fixed->appliesToListingType('sale'));
        $this->assertTrue($fixed->appliesToListingType('rental'));
        $this->assertSame('Mandate Extension', $fixed->label, 'the label an install already had is kept');
        $this->assertSame(1, DocumentType::withTrashed()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->count());

        // down() archives - it never removes a row (documents keep their type).
        $migration->down();
        $this->assertSame(1, DocumentType::withTrashed()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->count());
        $this->assertNotNull(DocumentType::withTrashed()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->value('deleted_at'));

        // …and up() brings it straight back.
        $migration->up();
        $this->assertNotNull(DocumentType::query()->where('slug', MandateExpiryPolicy::EXTENSION_SLUG)->first());
    }

    public function test_status_item_migration_down_soft_deletes_defaults_and_leaves_customised_rows(): void
    {
        $agencyA = $this->makeAgency();
        $agencyB = $this->makeAgency();
        $this->seedStatusItems($agencyA);
        $this->seedStatusItems($agencyB);

        $migration = require database_path('migrations/2026_10_13_100000_add_expired_property_status_item.php');
        $migration->up();

        // Agency B deactivated its Expired item - a customisation that must survive a rollback.
        DB::table('property_setting_items')->where('agency_id', $agencyB)->where('name', 'Expired')
            ->update(['active' => 0, 'updated_at' => now()->addMinute()]);

        $migration->down();

        $a = DB::table('property_setting_items')->where('agency_id', $agencyA)->where('name', 'Expired')->first();
        $b = DB::table('property_setting_items')->where('agency_id', $agencyB)->where('name', 'Expired')->first();
        $this->assertNotNull($a, 'never hard-deleted');
        $this->assertNotNull($a->deleted_at, 'the untouched default is archived');
        $this->assertNotNull($b);
        $this->assertNull($b->deleted_at, 'the customised row is left alone');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithUser(string $role): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, $this->user($agencyId, $role)];
    }

    private function user(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'price'         => 1_950_000,
            'expiry_date'   => today()->addDays(60),
        ], $attrs));
    }

    /** A completed listing that has gone live (Go Live pressed), with a linked contact so it can be saved. */
    private function liveListing(int $agencyId, User $agent, ?Carbon $expiry): Property
    {
        // The lock only holds while its way out exists (a usable Extension folder), exactly as on a
        // deployed install where the data migration provisioned it.
        $this->provisionExtensionFolder();

        $p = $this->property($agencyId, $agent, 'ZZZ-Live-' . Str::random(4), [
            'expiry_date'            => $expiry,
            'compliance_snapshot_at' => now()->subDay(),
            'beds' => 3, 'baths' => 2, 'garages' => 1,
        ]);
        $this->linkContact($agencyId, $agent, $p);

        return $p->fresh();
    }

    private function linkContact(int $agencyId, User $agent, Property $p): void
    {
        $contact = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agent->id,
            'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0820000099',
        ]);
        DB::table('contact_property')->insert([
            'property_id' => $p->id, 'contact_id' => $contact->id, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** What the edit form posts for an untouched save of $p. */
    private function saveEdit(User $user, Property $p, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'title'       => $p->title,
            'price'       => 1_950_000,
            'suburb'      => 'Uvongo',
            'city'        => 'Margate',
            'province'    => 'KwaZulu-Natal',
            'beds'        => 3, 'baths' => 2, 'garages' => 1,
            'agent_id'    => $p->agent_id,
            'status'      => $p->status,
            'expiry_date' => $p->expiry_date?->toDateString(),
        ], $overrides);

        return $this->actingAs($user)->put(route('corex.properties.update', $p->id), $payload);
    }

    /** A P24-origin listing (p24_imported_at set, never taken over) in the given status. */
    private function importedListing(int $agencyId, User $agent, string $status, ?Carbon $expiry = null): Property
    {
        $p = $this->property($agencyId, $agent, 'ZZZ-Imp-' . Str::random(4), [
            'status' => $status, 'p24_imported_at' => now()->subDays(40), 'p24_ref' => '123456789',
            'beds' => 3, 'baths' => 2, 'garages' => 1,
        ]);
        DB::table('properties')->where('id', $p->id)->update([
            'expiry_date' => ($expiry ?? Carbon::parse('2025-09-30'))->toDateString(),
            'listed_date' => null,
        ]);
        $this->linkContact($agencyId, $agent, $p);

        return $p->fresh();
    }

    /** saveEdit() plus an agent-photo upload (stored before the save when the save is allowed). */
    private function saveEditWithFile(User $user, Property $p, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'title' => $p->title, 'price' => 1_950_000, 'suburb' => 'Uvongo', 'city' => 'Margate',
            'province' => 'KwaZulu-Natal', 'beds' => 3, 'baths' => 2, 'garages' => 1,
            'agent_id' => $p->agent_id, 'status' => $p->status, 'expiry_date' => $p->expiry_date?->toDateString(),
        ], $overrides);

        $this->actingAs($user);

        return $this->call('PUT', route('corex.properties.update', $p->id), $payload, [], [
            'pp_agent_image' => UploadedFile::fake()->image('agent.jpg', 40, 40),
        ]);
    }

    private function provisionExtensionFolder(): DocumentType
    {
        return DocumentType::withTrashed()->firstOrCreate(
            ['slug' => MandateExpiryPolicy::EXTENSION_SLUG],
            ['label' => 'Extension', 'grouping' => 'property', 'listing_types' => ['sale', 'rental'], 'is_active' => true, 'sort_order' => 99]
        );
    }

    private function extensionDocument(int $agencyId, User $uploader, Property $p): Document
    {
        $type = $this->provisionExtensionFolder();

        $doc = Document::create([
            'agency_id'        => $agencyId,
            'branch_id'        => $agencyId,
            'original_name'    => 'mandate-extension-signed.pdf',
            'storage_path'     => "properties/{$p->id}/files/ext.pdf",
            'disk'             => 'local',
            'mime_type'        => 'application/pdf',
            'size'             => 12345,
            'document_type_id' => $type->id,
            'source_type'      => 'upload',
            'uploaded_by'      => $uploader->id,
        ]);
        $doc->properties()->attach($p->id);

        return $doc;
    }

    /**
     * The statuses a real, configured agency carries (mirrors 2026_03_05_300003).
     */
    private function seedStatusItems(int $agencyId): void
    {
        foreach (['For Sale' => 1, 'Sold' => 6, 'Under Offer' => 7, 'Withdrawn' => 11] as $name => $sort) {
            DB::table('property_setting_items')->insert([
                'agency_id'  => $agencyId, 'group' => 'property_status', 'name' => $name,
                'sort_order' => $sort, 'is_default' => 1, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Home Finders ' . Str::random(6),
            'slug'       => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
