<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 13 Oct 2026 - cc5's walk, second batch. F: the New Property flow (key-press error, the "save first?" question on a property that
 * does not exist yet, the tour counter, the 401 on the page's own geocode call). H: the application review screens (stated income,
 * "unattributed", the Approve modal's start values, one FICA story, the chip that overflowed, button heights).
 */
final class SherryWalkRound2Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Bus::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function application(array $extra = [], string $status = 'under_assessment'): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi-' . uniqid() . '@example.test', 'agent_id' => $this->agent->id, 'created_by_user_id' => $this->agent->id,
        ]);

        return RentalApplication::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status, 'full_name' => 'Thandi Nkosi',
            'email' => 'applicant-' . uniqid() . '@example.test', 'token' => bin2hex(random_bytes(16)),
            'token_expires_at' => now()->addDays(7), 'submitted_at' => now()->subDay(),
        ]);
    }

    // ═══ F. the New Property flow ═════════════════════════════════════════════════════════════════════════════

    public function test_the_key_press_listener_survives_a_page_with_no_gallery(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.properties.create'))->assertOk()->getContent();
        $this->assertStringContainsString("if (!lb || lb.classList.contains('hidden')) return;", $html);
        $this->assertStringNotContainsString("if (lb.classList.contains('hidden')) return;", $html);
    }

    public function test_a_new_property_does_not_ask_to_save_first_when_you_switch_tabs_but_an_existing_one_still_does(): void
    {
        $new = $this->actingAs($this->agent)->get(route('corex.properties.create'))->assertOk()->getContent();
        $this->assertStringContainsString('var isNewProperty = true;', $new);
        $this->assertStringContainsString('if (isNewProperty) return;', $new);

        $property = Property::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'title' => 'Flat', 'status' => 'draft', 'listing_type' => 'sale', 'price' => 1000000]);
        $existing = $this->actingAs($this->agent)->get(route('corex.properties.show', $property))->assertOk()->getContent();
        $this->assertStringContainsString('var isNewProperty = false;', $existing);
    }

    public function test_the_tour_counts_only_the_steps_that_are_on_the_screen(): void
    {
        $js = (string) file_get_contents(resource_path('views/layouts/partials/tour-engine.blade.php'));
        $this->assertStringContainsString('_passed: new Set()', $js);
        $this->assertStringContainsString('this._passed.add(i);', $js);
        $this->assertStringContainsString('(this._steps.length - this._passed.size)', $js, 'the total is the steps that can be shown, not the full list');
        $this->assertStringNotContainsString("'Step ' + (this._i + 1) + ' of ' + this._steps.length", $js);
    }

    public function test_the_property_pages_own_geocode_call_uses_a_session_route_and_the_mobile_route_stays_bearer_only(): void
    {
        $web = Route::getRoutes()->getByName('corex.properties.geocode');
        $this->assertNotNull($web);
        $this->assertSame('corex/properties/{property}/geocode', $web->uri());
        $this->assertContains('web', $web->gatherMiddleware());
        $this->assertContains('permission:access_properties', $web->gatherMiddleware());

        $api = Route::getRoutes()->getByName('v1.properties.geocode');
        $this->assertNotNull($api);
        $this->assertContains('auth:sanctum', $api->gatherMiddleware(), 'the mobile route is untouched');
        $this->assertNotContains('web', $api->gatherMiddleware());

        $property = Property::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'title' => 'Flat', 'status' => 'draft', 'listing_type' => 'sale', 'price' => 1000000]);
        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $property))->assertOk()->getContent();
        $this->assertStringContainsString("'/corex/properties/' + this.propertyId + '/geocode'", $html);
        $this->assertStringNotContainsString("'/api/v1/properties/' + this.propertyId + '/geocode'", $html);

        // a signed-out caller is not let in
        auth()->logout();
        $this->postJson(route('corex.properties.geocode', $property))->assertStatus(401);
    }

    // ═══ H. the application review screens ════════════════════════════════════════════════════════════════════

    public function test_the_income_the_applicant_stated_is_offered_as_one_click_and_none_captured_only_shows_when_nothing_was_stated(): void
    {
        $with = $this->application(['monthly_salary' => 42000]);
        $res = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $with))->assertOk();
        $res->assertSee('data-qa="stated-income"', false)->assertSee('Stated on the application:')->assertSee('R42 000')->assertSee('Use this');
        $html = $res->getContent();
        $this->assertStringContainsString('incomeEntries().length === 0 && true', $html, 'the stated-income row shows when nothing is captured yet');
        $this->assertStringContainsString('incomeEntries().length === 0 && false', $html, 'and "None captured" stays hidden');

        $without = $this->application(['monthly_salary' => null]);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $without))->assertOk()->getContent();
        $this->assertStringContainsString('incomeEntries().length === 0 && false', $html);
        $this->assertStringContainsString('incomeEntries().length === 0 && true"><p class="text-[11px]" style="color: var(--text-muted);">None captured.', $html);
    }

    public function test_system_lines_in_the_audit_trail_read_system_not_unattributed(): void
    {
        $app = $this->application();
        RentalApplicationAuditLog::forceCreate([
            'agency_id' => $this->agency->id, 'rental_application_id' => $app->id, 'event_category' => 'system', 'event_type' => 'status_changed',
            'actor_type' => 'unknown', 'actor_label' => 'unattributed', 'human_summary' => 'Something happened by itself', 'created_at' => now(),
        ]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();
        $this->assertStringContainsString('Something happened by itself', $html);
        $this->assertStringNotContainsString('<strong>unattributed</strong>', $html);
        $this->assertMatchesRegularExpression('/<strong>System<\\/strong>\\s*&mdash; Something happened by itself/', $html);
    }

    public function test_the_approve_modal_starts_with_the_properties_rent_and_deposit(): void
    {
        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'title' => 'Flat', 'status' => 'to_let',
            'listing_type' => 'rental', 'rental_amount' => 14500, 'deposit_amount' => 14500,
        ]);
        $this->agency->update(['rental_application_co_user_ids' => [$this->agent->id]]);
        \App\Models\RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['approval_mode' => 'one_step']);
        $app = $this->application(['property_id' => $property->id]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();
        $this->assertStringContainsString("if (!approveAmount) approveAmount = '14500.00';", $html);
        $this->assertStringContainsString("if (!approveDepositAmount) approveDepositAmount = '14500.00';", $html);
    }

    public function test_one_fica_story_the_modal_and_the_header_agree(): void
    {
        $this->agency->update(['rental_application_co_user_ids' => [$this->agent->id]]);
        \App\Models\RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['approval_mode' => 'one_step']);
        $app = $this->application();
        // a FICA submission that is with compliance but not yet verified
        \Illuminate\Support\Facades\DB::table('fica_submissions')->insert([
            'contact_id' => $app->contact_id, 'agency_id' => $this->agency->id, 'requested_by' => $this->agent->id, 'token' => 'tok-' . uniqid(),
            'token_expires_at' => now()->addDays(7), 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();
        $this->assertStringContainsString('FICA Submitted', $html);
        $this->assertStringContainsString('FICA has been submitted and is with compliance for verification', $html);
        $this->assertStringNotContainsString('FICA is still outstanding for this applicant', $html, 'the modal must not say outstanding while the header says submitted');
    }

    public function test_the_filled_and_the_outline_action_buttons_share_one_height(): void
    {
        $this->agency->update(['rental_application_co_user_ids' => [$this->agent->id]]);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $this->application()))->assertOk()->getContent();
        $this->assertStringContainsString('rr-equal-btns', $html);
        $this->assertStringContainsString('min-height: 30px', $html);

        $approved = $this->application(['approved_rental_amount' => 9000], 'approved');
        $ro = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $approved));
        if ($ro->status() === 200) {
            $this->assertStringContainsString('rr-equal-btns', $ro->getContent());
        }
    }

    public function test_the_header_row_wraps_so_the_chips_cannot_run_off_the_edge(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $this->application()))->assertOk()->getContent();
        // The header was redesigned on Staging (Andre): the rows are still flex-wrap, so the chips wrap instead of running off the edge.
        $this->assertStringContainsString('flex flex-wrap items-center gap-x-2 gap-y-1', $html);
    }
}
