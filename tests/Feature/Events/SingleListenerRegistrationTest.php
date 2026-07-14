<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Notifications\NewPortalLeadAgentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-261 — ONE EVENT, ONE EXECUTION.
 *
 * Laravel's EventServiceProvider defaults `$shouldDiscoverEvents = true`, so it auto-registered
 * every listener in app/Listeners as `Listener@handle` ON TOP OF our explicit Event::listen()
 * catalogue. Two registrations, one dispatch, two executions — silently. On live that meant 25
 * events and 31 listener classes firing twice: Retha received TWO emails for one P24 lead, the
 * DR2 Wave 2 cascade double-wrote its audit rows, and the domain-events ledger double-wrote
 * every entry.
 *
 * This asserts the CONTROL directly rather than the symptom: the registration itself. Counting
 * emails would have passed just as happily on a listener that fired twice and deduplicated;
 * what must be true is that a listener is registered ONCE.
 */
final class SingleListenerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The defect, asserted at its source: no listener may be registered against the same event
     * more than once — in ANY form. Discovery registered `Foo@handle` while the catalogue
     * registered `Foo`; those are different strings and the same listener, which is precisely
     * why the duplication was invisible.
     */
    public function test_no_listener_is_registered_twice_for_the_same_event(): void
    {
        $dispatcher = Event::getFacadeRoot();
        $ref = new \ReflectionClass($dispatcher);
        $prop = $ref->getProperty('listeners');
        $prop->setAccessible(true);

        $offenders = [];

        foreach ($prop->getValue($dispatcher) as $event => $listeners) {
            $normalised = [];
            foreach ($listeners as $listener) {
                $name = is_string($listener)
                    ? $listener
                    : (is_array($listener) && isset($listener[0]) && is_string($listener[0]) ? $listener[0] : null);

                if ($name === null) {
                    continue;   // closures are not the defect
                }

                // `Foo` and `Foo@handle` are the SAME listener wearing two hats.
                $normalised[] = preg_replace('/@handle$/', '', $name);
            }

            foreach (array_count_values($normalised) as $listener => $times) {
                if ($times > 1) {
                    $offenders[] = class_basename($event) . ' → ' . class_basename($listener) . " ×{$times}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "These listeners are registered more than once and will fire more than once:\n  "
            . implode("\n  ", $offenders));
    }

    /** Event auto-discovery must stay OFF: it is what silently duplicated the catalogue. */
    public function test_event_auto_discovery_is_disabled(): void
    {
        $provider = new \Illuminate\Foundation\Support\Providers\EventServiceProvider($this->app);

        $this->assertFalse($provider->shouldDiscoverEvents(),
            'Auto-discovery is on again — every catalogued listener will fire twice.');
    }

    /**
     * The listeners that ONLY discovery was registering must still be wired, or turning
     * discovery off silently kills them — mandate de-syndication, stage-document distribution,
     * the agency setup portal, and the whole domain-event logging family.
     */
    public function test_the_formerly_discovery_only_listeners_are_still_registered(): void
    {
        $mustFire = [
            \App\Events\Contact\ContactLinkedToProperty::class => \App\Listeners\Contact\PromoteOwnerToSellerOnPropertyLink::class,
            \App\Events\Mandate\MandateExpired::class          => \App\Listeners\Mandate\DesyndicateExpiredMandate::class,
            \App\Events\AgencyCreated::class                   => \App\Listeners\Onboarding\CreateAgencySetupPortal::class,
            \App\Events\DealV2\DealStepCompleted::class        => \App\Listeners\DealV2\AutoDistributeStageDocuments::class,
            \App\Events\Demo\DemoAccessGranted::class          => \App\Listeners\Demo\SendDemoAccessGrantEmail::class,
            \App\Events\AbstractDomainEvent::class             => \App\Listeners\Deal\LogDealEvent::class,
        ];

        foreach ($mustFire as $event => $listener) {
            $registered = collect(Event::getListeners($event));

            $this->assertTrue($registered->isNotEmpty(),
                class_basename($event) . ' has NO listeners — discovery was its only home.');
        }

        // ...and the domain-event logging family is registered exactly once each.
        $this->assertNotEmpty(Event::getListeners(\App\Events\AbstractDomainEvent::class));
    }

    // ── the symptom, end to end ──────────────────────────────────────────

    /**
     * ONE portal lead → the handler runs ONCE.
     *
     * Asserted exactly the way the defect was DIAGNOSED on live: LogPortalLeadReceived writes
     * one line per execution, and live's log carried TWO for the same lead id 234 —
     *
     *   [21:35:06] Portal lead received {"id":234, ...}
     *   [21:35:10] Portal lead received {"id":234, ...}
     *
     * — which is what proved a single lead was being handled twice. One dispatch must produce
     * exactly one execution. (Counting delivered emails would be the WRONG control: on these
     * benches the send already runs through the AT-235 gateway, which dedups by lead, so a
     * double-fire could be masked by the dedup and still be there.)
     */
    public function test_one_dispatch_runs_each_listener_exactly_once(): void
    {
        [$agent, $lead] = $this->portalLead();

        $executions = 0;
        Log::listen(function ($e) use (&$executions, $lead) {
            if (str_contains((string) $e->message, 'Portal lead received')
                && (int) ($e->context['id'] ?? 0) === (int) $lead->id) {
                $executions++;
            }
        });

        event(new \App\Events\Leads\NewPortalLeadReceived($lead));

        $this->assertSame(1, $executions,
            "One lead was handled {$executions}× — 2 is the AT-261 double-fire that sent Retha two emails.");
    }

    /** ...and the email opens the CONTACT, not the list of leads. */
    public function test_the_email_links_to_the_contact_not_the_portal_leads_list(): void
    {
        [$agent, $lead] = $this->portalLead();

        $mail = (new NewPortalLeadAgentNotification($lead))->toMail($agent);
        $array = (new NewPortalLeadAgentNotification($lead))->toArray($agent);

        $this->assertStringContainsString('/corex/contacts/' . $lead->contact_id, $mail->actionUrl);
        $this->assertStringNotContainsString('portal-leads', $mail->actionUrl);

        // The in-app bell must agree with the inbox.
        $this->assertSame($mail->actionUrl, $array['action_url']);
    }

    /** A lead with no resolvable person still has somewhere honest to go. */
    public function test_a_lead_with_no_contact_falls_back_to_the_portal_leads_list(): void
    {
        [$agent, $lead] = $this->portalLead();
        $lead->contact_id = null;

        $mail = (new NewPortalLeadAgentNotification($lead))->toMail($agent);

        $this->assertStringContainsString('portal-leads', $mail->actionUrl);
    }

    // ── helper ───────────────────────────────────────────────────────────

    /** @return array{0:User,1:PortalLead} a real P24 lead on a real listing, with its contact */
    private function portalLead(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Shelly Beach',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        $property = Property::create([
            'agency_id' => $agencyId, 'agent_id' => $agent->id, 'branch_id' => $agencyId,
            'title' => '1 Alamien Avenue, Uvongo', 'address' => '1 Alamien Avenue',
            'suburb' => 'Uvongo', 'status' => 'active', 'property_type' => 'House',
            'price' => 2_150_000,
        ]);

        $contact = Contact::create([
            'agency_id'  => $agencyId,
            'first_name' => 'Dalene', 'last_name' => 'de Sousa',
            'email'      => 'dalene@example.co.za', 'phone' => '082 796 2095',
            'is_buyer'   => true,
        ]);

        $lead = PortalLead::create([
            'agency_id'          => $agencyId,
            'portal'             => PortalLead::PORTAL_P24,
            'lead_type'          => 'Email',
            'listing_id'         => $property->id,
            'listing_portal_ref' => '117282481',
            'contact_id'         => $contact->id,
            'contact_exists'     => false,
            'name'               => 'Dalene de Sousa',
            'email'              => 'dalene@example.co.za',
            'phone'              => '082 796 2095',
            'message'            => 'What is the pet policy?',
            'lead_source_raw'    => ['name' => 'Dalene de Sousa', 'leadId' => 'p24-117282481'],
            'received_at'        => now(),
        ]);

        return [$agent, $lead];
    }
}
