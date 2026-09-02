<?php

namespace Tests\Feature\Webinars;

use App\Models\Role;
use App\Models\SiteConnector;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The owner-only admin surface, and the gate on it.
 *
 * Spec: .ai/specs/webinar-registration.md §7
 *
 * ══ WHY EVERY SCREEN IS ACTUALLY RENDERED HERE ══
 *
 * `view:cache` compiling a Blade file does NOT mean it renders: the compiler happily
 * emits PHP that only fails when executed. That exact gap shipped a broken directive
 * in this feature's confirmation email, and it was a render assertion that caught it.
 * So each screen is requested for real rather than asserted to exist.
 */
class WebinarAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();

        // owner_only checks the ROLE ROW's is_owner flag — not the user's role
        // string. is_owner is not fillable, so it is set explicitly and the role
        // cache cleared. Same pattern as DemoAccessAdminTest, the sibling
        // owner_only Dev Settings surface this screen sits beside.
        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();

        $this->owner = User::factory()->create([
            'role'      => 'super_admin',
            'name'      => 'Johan Reichel',
            'agency_id' => null,          // a platform identity, not a tenant member
        ]);

        // A NON-owner with the most privileged agency role available. If the gate
        // leaks, it leaks to someone like this.
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        Role::clearCache();

        $this->webinar = Webinar::create([
            'slug'                   => 'corex-walkthrough',
            'title'                  => 'CoreX OS — a walkthrough',
            'starts_at'              => Carbon::now()->addDays(7)->setTime(14, 0),
            'duration_minutes'       => 60,
            'join_url'               => 'https://zoom.us/j/123456',
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
            'created_by_user_id'     => $this->owner->id,
        ]);
    }

    // ---- The gate ----------------------------------------------------------

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/corex/admin/dev-settings/webinars')->assertRedirect();
    }

    /** The list is RR Technologies' sales data — an agency admin has no path to it. */
    public function test_an_agency_admin_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/corex/admin/dev-settings/webinars')->assertForbidden();
        $this->actingAs($admin)->get('/corex/admin/dev-settings/webinars/create')->assertForbidden();
        $this->actingAs($admin)->get('/corex/admin/dev-settings/webinars/' . $this->webinar->id)->assertForbidden();
        $this->actingAs($admin)->post('/corex/admin/dev-settings/webinars', [])->assertForbidden();
    }

    // ---- The screens -------------------------------------------------------

    public function test_every_screen_renders_for_an_owner(): void
    {
        $this->actingAs($this->owner)->get('/corex/admin/dev-settings/webinars')->assertOk();
        $this->actingAs($this->owner)->get('/corex/admin/dev-settings/webinars/create')->assertOk();
        $this->actingAs($this->owner)->get('/corex/admin/dev-settings/webinars/' . $this->webinar->id)->assertOk();
        $this->actingAs($this->owner)->get('/corex/admin/dev-settings/webinars/' . $this->webinar->id . '/edit')->assertOk();
    }

    /** The empty list has its own copy — that path renders too. */
    public function test_the_list_renders_when_there_are_no_webinars(): void
    {
        Webinar::query()->delete();

        $this->actingAs($this->owner)
             ->get('/corex/admin/dev-settings/webinars')
             ->assertOk()
             ->assertSee('No webinars yet');
    }

    /** The registration URL is the one thing this screen exists to hand over. */
    public function test_the_show_screen_displays_the_registration_endpoint(): void
    {
        $this->actingAs($this->owner)
             ->get('/corex/admin/dev-settings/webinars/' . $this->webinar->id)
             ->assertOk()
             ->assertSee('/api/v1/webinars/corex-walkthrough/register', false);
    }

    /** With registrants the table branch renders, including the status chips. */
    public function test_the_show_screen_renders_with_registrations(): void
    {
        WebinarRegistration::create([
            'webinar_id'   => $this->webinar->id,
            'name'         => 'Jane Smith',
            'email'        => 'jane@acme.co.za',
            'company_name' => 'Acme Properties',
            'phone'        => '+27 82 000 0000',
        ]);

        $this->actingAs($this->owner)
             ->get('/corex/admin/dev-settings/webinars/' . $this->webinar->id)
             ->assertOk()
             ->assertSee('Jane Smith')
             ->assertSee('Acme Properties')
             ->assertSee('No access issued');
    }

    // ---- Create / edit / archive -------------------------------------------

    public function test_an_owner_creates_a_webinar(): void
    {
        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/webinars', [
                 'title'                  => 'Second walkthrough',
                 'slug'                   => '',
                 'starts_at'              => Carbon::now()->addDays(14)->format('Y-m-d\TH:i'),
                 'duration_minutes'       => 45,
                 'join_url'               => 'https://zoom.us/j/222',
                 'access_ends_days_after' => 5,
                 'reminder_hours_before'  => 48,
             ])
             ->assertRedirect();

        $created = Webinar::where('title', 'Second walkthrough')->first();

        $this->assertNotNull($created);
        $this->assertSame('second-walkthrough', $created->slug, 'A blank slug is built from the title.');
        $this->assertSame(5, $created->access_ends_days_after);
        $this->assertSame($this->owner->id, $created->created_by_user_id);
    }

    /** Two webinars must never share a slug — the link would point at the wrong cohort. */
    public function test_a_duplicate_slug_is_suffixed_rather_than_collided(): void
    {
        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/webinars', [
                 'title'                  => 'CoreX OS — a walkthrough',
                 'slug'                   => 'corex-walkthrough',
                 'starts_at'              => Carbon::now()->addDays(20)->format('Y-m-d\TH:i'),
                 'access_ends_days_after' => 3,
                 'reminder_hours_before'  => 24,
             ])
             ->assertRedirect();

        $this->assertDatabaseHas('webinars', ['slug' => 'corex-walkthrough-2']);
    }

    public function test_a_bad_join_url_is_rejected_in_plain_english(): void
    {
        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/webinars', [
                 'title'                  => 'Broken link',
                 'starts_at'              => Carbon::now()->addDays(3)->format('Y-m-d\TH:i'),
                 'join_url'               => 'zoom dot us',
                 'access_ends_days_after' => 3,
                 'reminder_hours_before'  => 24,
             ])
             ->assertSessionHasErrors('join_url');
    }

    /**
     * The CoreX screen is a front door to the same record as the website's console, so
     * it has to be able to set all three joining details. Setting the link here but not
     * the Meeting ID and passcode would send a cohort a mail with a link and no fallback
     * way in — and send whoever was working in CoreX off to another system to finish.
     */
    public function test_an_owner_sets_the_meeting_id_and_passcode(): void
    {
        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/webinars', [
                 'title'                  => 'Joining details walkthrough',
                 'slug'                   => '',
                 'starts_at'              => Carbon::now()->addDays(14)->format('Y-m-d\TH:i'),
                 'join_url'               => 'https://zoom.us/j/82437708791',
                 'join_meeting_id'        => '824 3770 8791',
                 'join_passcode'          => '0ABcMc',
                 'access_ends_days_after' => 3,
                 'reminder_hours_before'  => 24,
             ])
             ->assertRedirect();

        $created = Webinar::where('title', 'Joining details walkthrough')->firstOrFail();

        // Spaces and case survive the round trip. A passcode that arrives upper-cased
        // is a passcode that does not work.
        $this->assertSame('824 3770 8791', $created->join_meeting_id);
        $this->assertSame('0ABcMc', $created->join_passcode);
    }

    /**
     * This screen posts the WHOLE form every time, so an emptied box means "clear it" —
     * deliberately unlike the site API, where an absent key means "leave unchanged".
     */
    public function test_emptying_the_passcode_box_clears_it(): void
    {
        $this->webinar->update([
            'join_meeting_id' => '824 3770 8791',
            'join_passcode'   => '0ABcMc',
        ]);

        $this->actingAs($this->owner)
             ->put('/corex/admin/dev-settings/webinars/' . $this->webinar->id, [
                 'title'                  => $this->webinar->title,
                 'slug'                   => $this->webinar->slug,
                 'starts_at'              => $this->webinar->starts_at->format('Y-m-d\TH:i'),
                 'join_url'               => $this->webinar->join_url,
                 'join_meeting_id'        => '824 3770 8791',
                 'join_passcode'          => '',
                 'access_ends_days_after' => 3,
                 'reminder_hours_before'  => 24,
             ])
             ->assertRedirect();

        $fresh = $this->webinar->fresh();

        $this->assertNull($fresh->join_passcode);
        $this->assertSame('824 3770 8791', $fresh->join_meeting_id);
    }

    /** Archiving closes registration and keeps the row (non-negotiable #1). */
    public function test_archiving_closes_registration_and_never_deletes(): void
    {
        $this->actingAs($this->owner)
             ->delete('/corex/admin/dev-settings/webinars/' . $this->webinar->id)
             ->assertRedirect();

        $this->assertDatabaseHas('webinars', ['id' => $this->webinar->id]);
        $this->assertNotNull($this->webinar->fresh()->archived_at);
        $this->assertFalse($this->webinar->fresh()->isOpenForRegistration());
    }

    public function test_the_csv_export_streams_the_registrants(): void
    {
        WebinarRegistration::create([
            'webinar_id'   => $this->webinar->id,
            'name'         => 'Jane Smith',
            'email'        => 'jane@acme.co.za',
            'company_name' => 'Acme Properties',
        ]);

        $response = $this->actingAs($this->owner)
                         ->get('/corex/admin/dev-settings/webinars/' . $this->webinar->id . '/export')
                         ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Jane Smith', $csv);
        $this->assertStringContainsString('Acme Properties', $csv);
    }

    // ---- The website connector card ----------------------------------------

    /** It lives on the Demo Access connection page, so that page must still render. */
    public function test_the_connection_page_renders_with_and_without_a_site_connector(): void
    {
        $this->actingAs($this->owner)
             ->get('/corex/admin/dev-settings/demo-access/connection')
             ->assertOk()
             ->assertSee('CoreX website connector');

        SiteConnector::mint('CoreX Website', $this->owner->id);

        $this->actingAs($this->owner)
             ->get('/corex/admin/dev-settings/demo-access/connection')
             ->assertOk()
             ->assertSee('cx_site_');
    }

    /** Minting shows the token once; rotation kills the previous one immediately. */
    public function test_minting_the_site_connector_rotates_and_reveals_once(): void
    {
        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/demo-access/site-connection', ['name' => 'CoreX Website'])
             ->assertRedirect()
             ->assertSessionHas('site_connector_token');

        $first = SiteConnector::current();

        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/demo-access/site-connection', ['name' => 'CoreX Website'])
             ->assertRedirect();

        $this->assertNotNull($first->fresh()->revoked_at, 'Rotation must not leave the old token working.');
        $this->assertNotSame($first->id, SiteConnector::current()->id);
    }

    public function test_a_non_owner_cannot_mint_the_site_connector(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
             ->post('/corex/admin/dev-settings/demo-access/site-connection', ['name' => 'Nope'])
             ->assertForbidden();

        $this->assertNull(SiteConnector::current());
    }
}
