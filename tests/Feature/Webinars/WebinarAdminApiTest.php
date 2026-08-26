<?php

namespace Tests\Feature\Webinars;

use App\Models\DemoAccessGrant;
use App\Models\SiteConnector;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The admin API the CoreX marketing website's console runs on.
 *
 * Spec: .ai/specs/webinar-registration.md §4.3
 */
class WebinarAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webinar $webinar;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config(['integrations.corex_website_url' => 'https://corexweb.co.za']);

        $this->owner = User::factory()->create(['role' => 'super_admin']);

        [, $this->token] = SiteConnector::mint('CoreX Website', $this->owner->id);

        $this->webinar = Webinar::create([
            'slug'                   => 'corex-walkthrough',
            'title'                  => 'CoreX OS — a walkthrough',
            'description'            => 'Everything an agency principal needs to see.',
            'starts_at'              => Carbon::now()->addDays(7)->setTime(14, 0),
            'duration_minutes'       => 60,
            'join_url'               => 'https://zoom.us/j/123456',
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
            'created_by_user_id'     => $this->owner->id,
        ]);
    }

    private function api()
    {
        return $this->withToken($this->token);
    }

    private function registrant(string $name, string $email, string $company = 'Acme Properties'): WebinarRegistration
    {
        return WebinarRegistration::create([
            'webinar_id'   => $this->webinar->id,
            'name'         => $name,
            'email'        => $email,
            'company_name' => $company,
            'phone'        => '+27 82 000 0000',
            'source'       => 'website',
        ]);
    }

    // ---- Auth --------------------------------------------------------------

    public function test_every_admin_endpoint_refuses_a_request_with_no_token(): void
    {
        $slug = $this->webinar->slug;

        $this->getJson('/api/v1/webinars')->assertStatus(401);
        $this->postJson('/api/v1/webinars', [])->assertStatus(401);
        $this->putJson("/api/v1/webinars/{$slug}", [])->assertStatus(401);
        $this->deleteJson("/api/v1/webinars/{$slug}")->assertStatus(401);
        $this->getJson("/api/v1/webinars/{$slug}/registrations")->assertStatus(401);
        $this->getJson("/api/v1/webinars/{$slug}/registrations.csv")->assertStatus(401);
    }

    // ---- The list ----------------------------------------------------------

    public function test_the_list_returns_what_the_console_renders(): void
    {
        $this->registrant('Jane Smith', 'jane@acme.co.za');
        $this->registrant('Thabo Dlamini', 'thabo@ridge.co.za');

        $response = $this->api()->getJson('/api/v1/webinars')->assertOk();

        $response->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'webinars')
            ->assertJsonPath('webinars.0.slug', 'corex-walkthrough')
            ->assertJsonPath('webinars.0.status_label', 'Open for registration')
            ->assertJsonPath('webinars.0.registration_open', true)
            ->assertJsonPath('webinars.0.registration_count', 2)
            ->assertJsonPath('webinars.0.archived', false)
            // The edit form's three fields, which the public read withholds.
            ->assertJsonPath('webinars.0.join_url', 'https://zoom.us/j/123456')
            ->assertJsonPath('webinars.0.access_ends_days_after', 3)
            ->assertJsonPath('webinars.0.reminder_hours_before', 24);
    }

    /**
     * The one field only CoreX can get right: it must name the MARKETING site.
     * Built from app.url it would hand out a link to this API instead of to the
     * registration page, and the console's copy button is the whole point of it.
     */
    public function test_the_registration_url_points_at_the_marketing_website(): void
    {
        $this->api()->getJson('/api/v1/webinars')
            ->assertJsonPath('webinars.0.registration_url', 'https://corexweb.co.za/webinars/corex-walkthrough');
    }

    public function test_archived_webinars_are_hidden_unless_asked_for(): void
    {
        $this->webinar->update(['archived_at' => now()]);

        $this->api()->getJson('/api/v1/webinars')->assertJsonCount(0, 'webinars');

        $this->api()->getJson('/api/v1/webinars?include_archived=true')
            ->assertJsonCount(1, 'webinars')
            ->assertJsonPath('webinars.0.archived', true)
            ->assertJsonPath('webinars.0.status_label', 'Archived');
    }

    // ---- Create / edit -----------------------------------------------------

    public function test_a_webinar_can_be_created_and_its_slug_derived_from_the_title(): void
    {
        $this->api()->postJson('/api/v1/webinars', [
            'title'                  => 'CoreX OS — September principals session',
            'slug'                   => '',
            'description'            => 'Everything a principal needs to see, in 45 minutes.',
            'starts_at'              => Carbon::now()->addDays(14)->setTime(14, 0)->toIso8601String(),
            'duration_minutes'       => 60,
            'join_url'               => 'https://zoom.us/j/987654',
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
        ])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('webinar.slug', 'corex-os-september-principals-session');

        $this->assertDatabaseHas('webinars', ['slug' => 'corex-os-september-principals-session']);
    }

    /**
     * The real-world create, and the one that broke in production.
     *
     * `slug` is nullable, so validate() omits the KEY when the caller sends no
     * slug at all — which the website does not: its "Link name" box is blank by
     * design, ConvertEmptyStringsToNull turns that into null, and it drops nulls
     * before posting. Sending `slug => ''` (as the test above does) is a
     * different shape: the key arrives present-but-null and hides this entirely.
     */
    public function test_a_webinar_can_be_created_when_the_caller_omits_the_slug_key(): void
    {
        $this->api()->postJson('/api/v1/webinars', [
            'title'                  => 'CoreX OS — October principals session',
            // no 'slug' key at all
            'starts_at'              => Carbon::now()->addDays(14)->setTime(14, 0)->toIso8601String(),
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
        ])
            ->assertStatus(201)
            ->assertJsonPath('webinar.slug', 'corex-os-october-principals-session');
    }

    public function test_creating_a_webinar_returns_field_keyed_errors(): void
    {
        $this->api()->postJson('/api/v1/webinars', ['title' => '', 'join_url' => 'not-a-url'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['errors' => ['title', 'starts_at']])
            // The website renders these verbatim against its own inputs, so the
            // plain-English wording is part of the contract.
            ->assertJsonPath(
                'errors.title.0',
                'Give the webinar a title — registrants see it in their confirmation email.',
            );
    }

    public function test_a_webinar_can_be_edited(): void
    {
        $this->api()->putJson('/api/v1/webinars/corex-walkthrough', [
            'title'                  => 'CoreX OS — a revised walkthrough',
            'slug'                   => '',
            'starts_at'              => Carbon::now()->addDays(10)->setTime(9, 0)->toIso8601String(),
            'duration_minutes'       => 45,
            'access_ends_days_after' => 5,
            'reminder_hours_before'  => 48,
        ])
            ->assertOk()
            ->assertJsonPath('webinar.title', 'CoreX OS — a revised walkthrough')
            ->assertJsonPath('webinar.access_ends_days_after', 5);
    }

    /**
     * A blank slug on edit means "leave it alone". Rebuilding it from the new
     * title would silently break a link already printed in somebody's email.
     */
    public function test_editing_with_a_blank_slug_keeps_the_existing_link(): void
    {
        $this->api()->putJson('/api/v1/webinars/corex-walkthrough', [
            'title'                  => 'A completely different title',
            'slug'                   => '',
            'starts_at'              => $this->webinar->starts_at->toIso8601String(),
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
        ])->assertOk()->assertJsonPath('webinar.slug', 'corex-walkthrough');
    }

    /**
     * The behaviour the console warns about on screen, proven here: the deadline
     * is copied onto each grant at issue, so moving the webinar cannot retroactively
     * shorten access somebody was already promised in writing.
     */
    public function test_editing_the_access_window_does_not_move_an_existing_registrants_deadline(): void
    {
        $grant = DemoAccessGrant::create([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'contact_name'  => 'Jane Smith',
            'code_hash'     => bcrypt('irrelevant'),
            'expires_at'    => $this->webinar->demoAccessEndsAt(),
            'created_by'    => $this->owner->id,
        ]);

        $registration = $this->registrant('Jane Smith', 'jane@acme.co.za');
        $registration->update(['demo_access_grant_id' => $grant->id]);

        $promised = $grant->expires_at->copy();

        $this->api()->putJson('/api/v1/webinars/corex-walkthrough', [
            'title'                  => $this->webinar->title,
            'slug'                   => '',
            'starts_at'              => Carbon::now()->addDays(30)->setTime(14, 0)->toIso8601String(),
            'access_ends_days_after' => 0,
            'reminder_hours_before'  => 24,
        ])->assertOk();

        $this->assertTrue(
            $grant->fresh()->expires_at->equalTo($promised),
            'An existing grant must keep the end date its owner was emailed.',
        );
    }

    // ---- Archive -----------------------------------------------------------

    public function test_archiving_closes_registration_and_is_idempotent(): void
    {
        $this->api()->deleteJson('/api/v1/webinars/corex-walkthrough')
            ->assertOk()
            ->assertJsonPath('webinar.archived', true);

        $this->assertNotNull($this->webinar->fresh()->archived_at);

        $archivedAt = $this->webinar->fresh()->archived_at;

        // A double-click is not an error, and must not restamp the date.
        $this->api()->deleteJson('/api/v1/webinars/corex-walkthrough')->assertOk();

        $this->assertTrue($archivedAt->equalTo($this->webinar->fresh()->archived_at));
    }

    public function test_an_unknown_slug_is_a_404_everywhere(): void
    {
        $this->api()->putJson('/api/v1/webinars/nope', [])->assertStatus(404);
        $this->api()->deleteJson('/api/v1/webinars/nope')->assertStatus(404);
        $this->api()->getJson('/api/v1/webinars/nope/registrations')->assertStatus(404);
        $this->api()->getJson('/api/v1/webinars/nope/registrations.csv')->assertStatus(404);
    }

    // ---- Registrants -------------------------------------------------------

    public function test_the_registrant_list_is_newest_first_and_carries_the_contact_details(): void
    {
        $older = $this->registrant('Jane Smith', 'jane@acme.co.za');
        $older->update(['created_at' => Carbon::now()->subDay()]);

        $this->registrant('Thabo Dlamini', 'thabo@ridge.co.za', 'Ridge Realty');

        $response = $this->api()->getJson('/api/v1/webinars/corex-walkthrough/registrations')->assertOk();

        $response->assertJsonPath('webinar.slug', 'corex-walkthrough')
            ->assertJsonCount(2, 'registrations')
            ->assertJsonPath('registrations.0.email', 'thabo@ridge.co.za')
            ->assertJsonPath('registrations.1.email', 'jane@acme.co.za')
            ->assertJsonPath('registrations.0.company_name', 'Ridge Realty')
            ->assertJsonPath('registrations.0.phone', '+27 82 000 0000')
            ->assertJsonPath('registrations.0.demo_access_status', 'No access issued')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1);
    }

    /**
     * §3.2 stores one `name`. Splitting at the FIRST space keeps the surname
     * whole; splitting at the last would produce "Jan van der" + "Merwe", which
     * is not a name anyone has.
     */
    public function test_a_stored_full_name_is_split_without_mangling_the_surname(): void
    {
        $this->registrant('Jan van der Merwe', 'jan@acme.co.za');

        $this->api()->getJson('/api/v1/webinars/corex-walkthrough/registrations')
            ->assertJsonPath('registrations.0.first_name', 'Jan')
            ->assertJsonPath('registrations.0.last_name', 'van der Merwe')
            // The stored truth travels alongside the guess.
            ->assertJsonPath('registrations.0.name', 'Jan van der Merwe');
    }

    public function test_the_registrant_list_paginates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->registrant("Person {$i}", "person{$i}@acme.co.za");
        }

        $this->api()->getJson('/api/v1/webinars/corex-walkthrough/registrations?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'registrations')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    // ---- The downloads -----------------------------------------------------

    /**
     * Column-for-column what Zoom's bulk-registrant importer expects. This lives
     * here because CoreX generates the file; the website streams the bytes
     * through without touching them.
     */
    public function test_the_zoom_csv_has_zooms_column_order(): void
    {
        $this->registrant('Jan van der Merwe', 'jan@acme.co.za');

        $response = $this->api()->get('/api/v1/webinars/corex-walkthrough/registrations.csv?format=zoom')
            ->assertOk();

        $lines = preg_split('/\R/', trim($response->streamedContent()));

        $this->assertSame('Email Address,First Name,Last Name,Company', $lines[0]);
        $this->assertSame('jan@acme.co.za,Jan,"van der Merwe","Acme Properties"', $lines[1]);
    }

    public function test_the_full_csv_carries_the_sales_follow_up_columns(): void
    {
        $this->registrant('Jane Smith', 'jane@acme.co.za');

        $response = $this->api()->get('/api/v1/webinars/corex-walkthrough/registrations.csv?format=full')
            ->assertOk();

        $lines = preg_split('/\R/', trim($response->streamedContent()));

        $this->assertSame(
            'First Name,Last Name,Email,Company,Phone,"Registered at","Demo access","Access ends","Reminder sent"',
            $lines[0],
        );
        $this->assertStringContainsString('jane@acme.co.za', $lines[1]);
        $this->assertStringContainsString('No access issued', $lines[1]);
    }

    public function test_an_unknown_csv_format_falls_back_to_the_full_list(): void
    {
        $this->registrant('Jane Smith', 'jane@acme.co.za');

        $response = $this->api()->get('/api/v1/webinars/corex-walkthrough/registrations.csv?format=nonsense')
            ->assertOk();

        $this->assertStringStartsWith('First Name,Last Name,Email', trim($response->streamedContent()));
    }

    // ---- Regression guard on the public read -------------------------------

    /**
     * The admin list now carries join_url. The PUBLIC read must still not — it is
     * earned by registering, not by loading a page.
     */
    public function test_the_public_read_still_withholds_the_joining_link(): void
    {
        $this->api()->getJson('/api/v1/webinars/corex-walkthrough')
            ->assertOk()
            ->assertJsonMissingPath('webinar.join_url');
    }
}
