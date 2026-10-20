<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalJobCard;
use App\Models\User;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsRentalPortalFixtures;
use Tests\TestCase;

/**
 * AT-464 - .ai/specs/rental-work-orders.md §17.40: after a posted action that comes back to the fault report, work order or job card
 * screen, the redirect carries `land_on_next` and the layout puts the page on the screen's Next bar (instead of restoring the old scroll
 * position, which left Johan at the bottom of the fault report looking at the form he had just used). One middleware, no per-button code.
 */
final class LandOnNextBarTest extends TestCase
{
    use BuildsRentalPortalFixtures;
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $landlord;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // nothing here may reach a real inbox
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void
            {
            }
        });
        $this->agency = $this->makeAgency('Land On Next Realty');
        $this->agent = $this->makeAgent($this->agency);
        $this->property = $this->makeProperty($this->agency, $this->agent);
        $this->lease = $this->makeLease($this->agency, $this->property);
        $this->landlord = $this->makeLandlord($this->agency, $this->property);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the stopcock']);
    }

    private function fault(): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'agent', 'reported_channel' => 'app',
            'title' => 'Tap drips', 'description' => 'The kitchen tap drips all night', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $fault->requestApproval($this->agent);

        return $fault->fresh();
    }

    private function recordDecision(RentalFaultReport $fault, array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-decision.store', $fault), array_merge([
            'owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'phone_call',
            'told_at' => now()->subHour()->format('Y-m-d\TH:i'), 'note' => 'Lenny phoned: yes, go ahead.',
        ], $over));
    }

    // ── the fault report (Johan's case) ────────────────────────────────────────────────

    public function test_recording_the_owners_decision_redirects_with_the_land_on_next_flag_and_the_screen_renders_the_anchor(): void
    {
        $fault = $this->fault();

        $this->recordDecision($fault)->assertRedirect(route('corex.rental-fault-reports.show', $fault))->assertSessionHasNoErrors()->assertSessionHas('land_on_next', true);
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $fault->fresh()->status);

        $page = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk();
        $page->assertSee('var LAND_ON_NEXT = true;', false);
        $page->assertSee('data-land-on-next', false);
        $page->assertSee('Owner approved', false);
        $this->assertGreaterThanOrEqual(2, substr_count($page->getContent(), 'data-land-on-next'), 'the header and the appoint-the-contractor block are both marked');

        // consumed: the next visit is an ordinary one that restores the scroll position
        $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->assertSee('var LAND_ON_NEXT = false;', false);
    }

    public function test_a_refused_action_does_not_ask_to_land_on_the_next_bar(): void
    {
        $fault = $this->fault();

        // no decision at all: a validation failure goes back to the form with its errors, where the person must read them
        $this->recordDecision($fault, ['decision' => ''])->assertSessionHasErrors('decision')->assertSessionMissing('land_on_next');

        $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->assertSee('var LAND_ON_NEXT = false;', false);
    }

    public function test_an_ordinary_page_view_never_carries_the_flag(): void
    {
        $fault = $this->fault();

        $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->assertSee('var LAND_ON_NEXT = false;', false)->assertDontSee('var LAND_ON_NEXT = true;', false);
    }

    // ── the work order ──────────────────────────────────────────────────────────────────

    public function test_an_action_on_a_work_order_lands_on_its_next_bar(): void
    {
        $wo = $this->makeWorkOrder($this->agency, $this->property, $this->lease);

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.access-contact.update', $wo), ['access_contact_name' => 'Sam Caretaker', 'access_contact_phone' => '0821234567'])
            ->assertRedirect(route('corex.rental-work-orders.show', $wo))->assertSessionHas('land_on_next', true);

        $page = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk();
        $page->assertSee('var LAND_ON_NEXT = true;', false);
        $page->assertSee('data-next-step data-land-on-next', false);
    }

    // ── the job card ────────────────────────────────────────────────────────────────────

    public function test_an_action_on_a_job_card_lands_on_its_next_bar(): void
    {
        $card = $this->makeJobCard($this->agency, $this->property, $this->lease, ['status' => RentalJobCard::STATUS_DRAFT]);

        $this->actingAs($this->agent)->put(route('corex.rental-job-cards.update', $card), ['title' => 'Replace geyser element - revised'])
            ->assertRedirect(route('corex.rental-job-cards.show', $card))->assertSessionHas('land_on_next', true);

        $page = $this->actingAs($this->agent)->get(route('corex.rental-job-cards.show', $card))->assertOk();
        $page->assertSee('var LAND_ON_NEXT = true;', false);
        $page->assertSee('data-next-step data-land-on-next', false);
    }

    public function test_a_job_card_action_that_names_its_own_focus_keeps_it(): void
    {
        $card = $this->makeJobCard($this->agency, $this->property, $this->lease, ['status' => RentalJobCard::STATUS_DRAFT]);

        // adding a task flashes jc_focus_task (the new task is brought into view): that explicit anchor stays
        $this->actingAs($this->agent)->post(route('corex.rental-job-cards.tasks.store', $card), ['description' => 'Replace the element'])
            ->assertRedirect(route('corex.rental-job-cards.show', $card))->assertSessionHas('jc_focus_task')->assertSessionMissing('land_on_next');
    }

    // ── the rules of the middleware itself ──────────────────────────────────────────────

    public function test_a_redirect_with_an_explicit_fragment_or_the_raise_form_request_keeps_its_own_anchor(): void
    {
        $mw = new \App\Http\Middleware\LandOnNextBar();
        $fault = $this->fault();
        $base = route('corex.rental-fault-reports.show', $fault);

        $run = function (string $target) use ($mw) {
            $request = \Illuminate\Http\Request::create('/corex/anything', 'POST');
            $request->setLaravelSession($session = new \Illuminate\Session\Store('t', new \Illuminate\Session\ArraySessionHandler(120)));
            $mw->handle($request, fn () => redirect($target));

            return $session->get('land_on_next');
        };

        $this->assertTrue($run($base));
        $this->assertNull($run($base . '#appoint-contractor'), 'an explicit #fragment wins');
        $this->assertNull($run($base . '?create_work_order=1'), 'the raise-work-order form request wins');
        $this->assertNull($run(url('/corex/rental-fault-reports')), 'a list page is not one of the three screens');
        $this->assertNull($run(url('/corex/properties/1')), 'nor is any other screen');
    }

    public function test_a_read_only_request_never_sets_the_flag(): void
    {
        $mw = new \App\Http\Middleware\LandOnNextBar();
        $request = \Illuminate\Http\Request::create('/corex/rental-fault-reports/5', 'GET');
        $request->setLaravelSession($session = new \Illuminate\Session\Store('t', new \Illuminate\Session\ArraySessionHandler(120)));
        $mw->handle($request, fn () => redirect(url('/corex/rental-fault-reports/5')));

        $this->assertNull($session->get('land_on_next'));
    }
}
