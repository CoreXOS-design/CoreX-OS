<?php

namespace Tests\Feature\Webinars;

use App\Models\Role;
use App\Models\SiteConnector;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The optional registration cut-off.
 *
 * Spec: .ai/specs/webinar-registration.md §3.1a, §4.5
 *
 * Two things here earn their keep:
 *
 * 1. The THREE-WAY UPDATE CONTRACT (set / clear with "" / leave alone by omitting the
 *    key). It works only because ConvertEmptyStringsToNull turns "" into null and
 *    validate() keeps present-but-null keys while dropping absent ones — implicit
 *    machinery that a refactor to $request->input() would silently break, turning
 *    "leave unchanged" into "wipe it" on every unrelated edit.
 *
 * 2. NULL still meaning "no cut-off". That is what makes the new column safe for every
 *    webinar that already existed, and it is the assertion that would catch someone
 *    later defaulting the column or making it NOT NULL.
 */
class WebinarRegistrationCutOffTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Webinar $webinar;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();

        $this->owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);

        [, $this->token] = SiteConnector::mint('CoreX Website', $this->owner->id);

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

    private function register(): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->postJson(
            '/api/v1/webinars/' . $this->webinar->slug . '/register',
            ['name' => 'Jane Smith', 'email' => 'jane@acme.co.za', 'company_name' => 'Acme Properties']
        );
    }

    /** The full payload the site API's PUT expects, so tests vary one field only. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'                  => $this->webinar->title,
            'starts_at'              => $this->webinar->starts_at->toIso8601String(),
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
        ], $overrides);
    }

    // ---- NULL means no cut-off (the compatibility guarantee) ----------------

    public function test_a_webinar_with_no_cut_off_behaves_exactly_as_before(): void
    {
        $this->assertNull($this->webinar->registration_closes_at);
        $this->assertTrue($this->webinar->isOpenForRegistration());
        $this->assertSame('Open for registration', $this->webinar->statusLabel());

        $this->register()->assertOk()->assertJsonPath('registered', true);
    }

    // ---- The verification case from the brief ------------------------------

    /** Starts next week, cut-off yesterday → closed, and the API says so. */
    public function test_a_passed_cut_off_closes_registration_even_though_the_webinar_is_still_a_week_away(): void
    {
        $this->webinar->update(['registration_closes_at' => Carbon::now()->subDay()]);

        $this->assertTrue($this->webinar->fresh()->starts_at->isFuture());
        $this->assertFalse($this->webinar->fresh()->isOpenForRegistration());

        $this->withToken($this->token)
             ->getJson('/api/v1/webinars/' . $this->webinar->slug)
             ->assertOk()
             ->assertJsonPath('webinar.registration_open', false);
    }

    /** …and the door is actually shut, with nothing created. */
    public function test_registering_after_the_cut_off_is_refused_with_the_same_404_as_everything_else(): void
    {
        $this->webinar->update(['registration_closes_at' => Carbon::now()->subDay()]);

        $this->register()
             ->assertStatus(404)
             // Byte-identical to archived / past / unknown — a distinct "closed"
             // message would let anyone map the sales calendar by probing slugs.
             ->assertJsonPath('message', 'That webinar is not open for registration.');

        $this->assertDatabaseCount('webinar_registrations', 0);
        $this->assertDatabaseCount('demo_access_grants', 0);
    }

    /** A cut-off still in the future leaves registration open. */
    public function test_a_future_cut_off_does_not_close_registration_yet(): void
    {
        $this->webinar->update(['registration_closes_at' => Carbon::now()->addDays(2)]);

        $this->assertTrue($this->webinar->fresh()->isOpenForRegistration());
        $this->register()->assertOk();
    }

    /** The exact moment it passes — a cut-off is inclusive of its own instant. */
    public function test_the_cut_off_bites_the_moment_it_is_reached(): void
    {
        $cutOff = Carbon::now()->addHour();
        $this->webinar->update(['registration_closes_at' => $cutOff]);

        $this->assertTrue($this->webinar->fresh()->isOpenForRegistration());

        $this->travelTo($cutOff);

        $this->assertFalse($this->webinar->fresh()->isOpenForRegistration());
    }

    // ---- The field is exposed on all four responses ------------------------

    public function test_the_public_read_returns_the_cut_off_so_the_page_can_explain_itself(): void
    {
        $cutOff = Carbon::now()->addDays(2)->startOfMinute();
        $this->webinar->update(['registration_closes_at' => $cutOff]);

        $this->withToken($this->token)
             ->getJson('/api/v1/webinars/' . $this->webinar->slug)
             ->assertOk()
             ->assertJsonPath('webinar.registration_closes_at', $cutOff->toIso8601String());
    }

    public function test_the_public_read_returns_null_when_there_is_no_cut_off(): void
    {
        $this->withToken($this->token)
             ->getJson('/api/v1/webinars/' . $this->webinar->slug)
             ->assertOk()
             ->assertJsonPath('webinar.registration_closes_at', null);
    }

    public function test_the_admin_list_carries_the_cut_off_on_every_row(): void
    {
        $cutOff = Carbon::now()->addDays(2)->startOfMinute();
        $this->webinar->update(['registration_closes_at' => $cutOff]);

        $this->withToken($this->token)
             ->getJson('/api/v1/webinars')
             ->assertOk()
             ->assertJsonPath('webinars.0.registration_closes_at', $cutOff->toIso8601String());
    }

    // ---- THE THREE-WAY UPDATE CONTRACT (§4.5) ------------------------------

    /** Row 1: a value sets it. */
    public function test_put_with_a_value_sets_the_cut_off(): void
    {
        $cutOff = Carbon::now()->addDays(2)->startOfMinute();

        $this->withToken($this->token)
             ->putJson('/api/v1/webinars/' . $this->webinar->slug,
                 $this->payload(['registration_closes_at' => $cutOff->toIso8601String()]))
             ->assertOk();

        $this->assertSame(
            $cutOff->toDateTimeString(),
            $this->webinar->fresh()->registration_closes_at->toDateTimeString()
        );
    }

    /** Row 2: an EMPTY STRING clears it. The website sends "" to reopen a webinar. */
    public function test_put_with_an_empty_string_clears_the_cut_off_and_reopens_registration(): void
    {
        $this->webinar->update(['registration_closes_at' => Carbon::now()->subDay()]);
        $this->assertFalse($this->webinar->fresh()->isOpenForRegistration());

        $this->withToken($this->token)
             ->putJson('/api/v1/webinars/' . $this->webinar->slug,
                 $this->payload(['registration_closes_at' => '']))
             ->assertOk();

        $this->assertNull($this->webinar->fresh()->registration_closes_at);
        $this->assertTrue($this->webinar->fresh()->isOpenForRegistration());

        // And the door is genuinely open again, not just reported open.
        $this->register()->assertOk()->assertJsonPath('registered', true);
    }

    /**
     * Row 3: an ABSENT key leaves it alone.
     *
     * This is the one a refactor breaks. If reading the field ever moves to
     * $request->input(), or defaults get merged into the validated array, this test
     * fails and the silent wipe is caught here instead of in production.
     */
    public function test_put_omitting_the_key_leaves_an_existing_cut_off_untouched(): void
    {
        $cutOff = Carbon::now()->addDays(2)->startOfMinute();
        $this->webinar->update(['registration_closes_at' => $cutOff]);

        $this->withToken($this->token)
             ->putJson('/api/v1/webinars/' . $this->webinar->slug,
                 $this->payload(['title' => 'A totally unrelated edit']))
             ->assertOk();

        $fresh = $this->webinar->fresh();

        $this->assertSame('A totally unrelated edit', $fresh->title);
        $this->assertNotNull($fresh->registration_closes_at, 'An unrelated edit must never wipe the cut-off.');
        $this->assertSame($cutOff->toDateTimeString(), $fresh->registration_closes_at->toDateTimeString());
    }

    // ---- Validation --------------------------------------------------------

    public function test_a_cut_off_after_the_start_is_rejected_field_keyed(): void
    {
        $this->withToken($this->token)
             ->putJson('/api/v1/webinars/' . $this->webinar->slug,
                 $this->payload([
                     'registration_closes_at' => $this->webinar->starts_at->copy()->addHour()->toIso8601String(),
                 ]))
             ->assertStatus(422)
             ->assertJsonPath('ok', false)
             ->assertJsonStructure(['errors' => ['registration_closes_at']]);

        $this->assertNull($this->webinar->fresh()->registration_closes_at);
    }

    public function test_a_cut_off_exactly_at_the_start_is_rejected(): void
    {
        $this->withToken($this->token)
             ->putJson('/api/v1/webinars/' . $this->webinar->slug,
                 $this->payload([
                     'registration_closes_at' => $this->webinar->starts_at->toIso8601String(),
                 ]))
             ->assertStatus(422);
    }

    public function test_creating_with_a_cut_off_works_through_the_site_api(): void
    {
        $starts = Carbon::now()->addDays(20)->setTime(10, 0);
        $cutOff = $starts->copy()->subDays(2)->startOfMinute();

        $this->withToken($this->token)
             ->postJson('/api/v1/webinars', [
                 'title'                  => 'Second walkthrough',
                 'starts_at'              => $starts->toIso8601String(),
                 'registration_closes_at' => $cutOff->toIso8601String(),
                 'access_ends_days_after' => 3,
                 'reminder_hours_before'  => 24,
             ])
             ->assertStatus(201)
             ->assertJsonPath('webinar.registration_closes_at', $cutOff->toIso8601String());
    }

    // ---- statusLabel (Johan's call, §3.1a) ---------------------------------

    /**
     * The label and the behaviour must agree on screen. Before this branch existed a
     * webinar past its cut-off still read "Open for registration" on the admin list
     * while the API refused every sign-up.
     */
    public function test_the_admin_label_says_registration_closed_once_the_cut_off_passes(): void
    {
        $this->webinar->update(['registration_closes_at' => Carbon::now()->subHour()]);

        $this->assertSame('Registration closed', $this->webinar->fresh()->statusLabel());
        $this->assertFalse($this->webinar->fresh()->isOpenForRegistration());
    }

    /** Archived still wins — it is the more final state. */
    public function test_archived_beats_a_passed_cut_off_in_the_label(): void
    {
        $this->webinar->update([
            'registration_closes_at' => Carbon::now()->subHour(),
            'archived_at'            => Carbon::now(),
        ]);

        $this->assertSame('Archived', $this->webinar->fresh()->statusLabel());
    }

    // ---- The CoreX-side admin screens (§7.2) -------------------------------

    public function test_the_owner_can_set_a_cut_off_from_the_corex_admin_screen(): void
    {
        $starts = Carbon::now()->addDays(30)->setTime(9, 0);
        $cutOff = $starts->copy()->subDays(3);

        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/webinars', [
                 'title'                  => 'Admin-created webinar',
                 'slug'                   => '',
                 'starts_at'              => $starts->format('Y-m-d\TH:i'),
                 'registration_closes_at' => $cutOff->format('Y-m-d\TH:i'),
                 'access_ends_days_after' => 3,
                 'reminder_hours_before'  => 24,
             ])
             ->assertRedirect();

        $created = Webinar::where('title', 'Admin-created webinar')->first();

        $this->assertNotNull($created->registration_closes_at);
        $this->assertSame($cutOff->format('Y-m-d H:i'), $created->registration_closes_at->format('Y-m-d H:i'));
    }

    public function test_the_corex_admin_screen_rejects_a_cut_off_after_the_start(): void
    {
        $starts = Carbon::now()->addDays(30)->setTime(9, 0);

        $this->actingAs($this->owner)
             ->post('/corex/admin/dev-settings/webinars', [
                 'title'                  => 'Bad cut-off',
                 'slug'                   => '',
                 'starts_at'              => $starts->format('Y-m-d\TH:i'),
                 'registration_closes_at' => $starts->copy()->addDay()->format('Y-m-d\TH:i'),
                 'access_ends_days_after' => 3,
                 'reminder_hours_before'  => 24,
             ])
             ->assertSessionHasErrors('registration_closes_at');
    }

    /** The edit screen renders the stored value back into the field. */
    public function test_the_edit_screen_renders_with_a_cut_off_set(): void
    {
        $this->webinar->update(['registration_closes_at' => Carbon::now()->addDays(2)]);

        $this->actingAs($this->owner)
             ->get('/corex/admin/dev-settings/webinars/' . $this->webinar->id . '/edit')
             ->assertOk()
             ->assertSee('Registration closes');
    }
}
