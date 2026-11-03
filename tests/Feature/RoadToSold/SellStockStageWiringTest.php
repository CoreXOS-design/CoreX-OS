<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Contact\ContactContactedByAgent;
use App\Events\Contact\ContactMatchCreated;
use App\Events\Docuperfect\OtpSigned;
use App\Events\Leads\NewPortalLeadReceived;
use App\Events\Marketing\ViewingPackCreated;
use App\Events\Property\PropertyWentLiveOnPortal;
use App\Events\Viewing\ViewingHeld;
use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\RecipeCatalogue;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3, R7 (road-to-sold.md 22.8) - the Sell Stock events that had no stage proof: listing live, lead arrived, lead contacted, Core
 * Match set up, viewing pack, viewing held, OTP signed. Each one is raised through the real event, moves the AGENT'S prospect to the stage
 * 22.2 names, and an event that arrives twice or out of order never moves it backwards.
 *
 * Plus the structural guard: every Sell Stock event on the allowed list exists, is raised somewhere outside its own listeners, and is either
 * handled by the stage listener or named here as credit-only - so a new event cannot be added to the list and quietly do nothing.
 */
final class SellStockStageWiringTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private ProspectService $prospects;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        SettingsService::forget();
        $this->setUpAgency();
        $this->prospects = app(ProspectService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:int,1:RtsProspect} */
    private function prospectOnProperty(User $agent): array
    {
        $propertyId = $this->property($agent->id);
        $tp = $this->tp(['promoted_to_property_id' => $propertyId]);

        return [$propertyId, $this->prospects->place($agent, $tp->id, 'sales', ['property_id' => $propertyId])];
    }

    public function test_a_listing_going_live_on_a_portal_moves_the_property_agents_prospect(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);

        event(new PropertyWentLiveOnPortal(Property::withoutGlobalScopes()->findOrFail($propertyId), 'p24', $this->agent->id));

        $this->assertSame(S::LISTING_LIVE, $p->refresh()->stage);
    }

    public function test_a_lead_a_first_contact_and_a_core_match_walk_the_prospect_through_the_buyer_stages(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);
        $buyer = $this->buyer($this->agent->id);
        $lead = PortalLead::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'portal' => 'p24', 'lead_type' => 'email', 'listing_id' => $propertyId,
            'contact_id' => $buyer->id, 'name' => 'Buyer Lead', 'lead_source_raw' => [], 'received_at' => now(),
        ]);

        // the lead arrives on the agent's property
        event(new NewPortalLeadReceived($lead));
        $this->assertSame(S::LEAD, $p->refresh()->stage);

        // the agent contacts that buyer
        event(new ContactContactedByAgent($buyer->fresh(), 'call', now(), $this->agent->id));
        $this->assertSame(S::LEAD_CONTACTED, $p->refresh()->stage);

        // and sets up a Core Match for them
        event(new ContactMatchCreated(matchId: 1, contactId: $buyer->id, agencyId: $this->agency->id, branchId: $this->branch->id, userId: $this->agent->id, actorUserId: $this->agent->id));
        $this->assertSame(S::CORE_MATCH, $p->refresh()->stage);

        // the same events again change nothing, and an older stage is never re-entered
        event(new NewPortalLeadReceived($lead));
        event(new ContactContactedByAgent($buyer->fresh(), 'call', now(), $this->agent->id));
        $this->assertSame(S::CORE_MATCH, $p->refresh()->stage, 'forward only');
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('to_stage', S::LEAD)->count());
    }

    public function test_a_contact_with_no_lead_on_a_prospect_moves_nothing(): void
    {
        [, $p] = $this->prospectOnProperty($this->agent);
        $stranger = $this->buyer($this->agent->id);

        event(new ContactContactedByAgent($stranger, 'call', now(), $this->agent->id));
        event(new ContactMatchCreated(matchId: 2, contactId: $stranger->id, agencyId: $this->agency->id, branchId: $this->branch->id, userId: $this->agent->id));

        $this->assertSame(S::FOUND, $p->refresh()->stage, 'the lead is the only link between a buyer and a property');
    }

    public function test_a_viewing_pack_and_a_held_viewing_move_the_prospect_and_an_otp_makes_it_an_offer(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);
        $this->actingAs($this->agent);
        $viewing = app(CalendarEventCreator::class)->create([
            'title' => 'Viewing', 'category' => 'viewing', 'event_date' => '2026-10-20 10:00:00', 'property_id' => $propertyId,
        ], $this->agent);

        event(new ViewingPackCreated(packId: 5, contactId: null, calendarEventId: $viewing->id, agencyId: $this->agency->id, branchId: $this->branch->id, userId: $this->agent->id));
        $this->assertSame(S::VIEWING_PACK, $p->refresh()->stage);

        event(new ViewingHeld(feedbackId: 7, calendarEventId: $viewing->id, propertyId: $propertyId, contactId: null, agencyId: $this->agency->id, branchId: $this->branch->id, userId: $this->agent->id, heldAt: '2026-10-20 11:00:00'));
        $this->assertSame(S::VIEWING_FEEDBACK, $p->refresh()->stage);

        event(new OtpSigned(signatureTemplateId: 9, documentId: 3, propertyId: $propertyId, dealId: null, agencyId: $this->agency->id, branchId: $this->branch->id, userId: $this->agent->id));
        $this->assertSame(S::OFFER, $p->refresh()->stage);
    }

    public function test_an_otp_credits_the_property_agent_when_the_signer_is_not_one(): void
    {
        [$propertyId, $p] = $this->prospectOnProperty($this->agent);

        event(new OtpSigned(signatureTemplateId: 10, documentId: 4, propertyId: $propertyId, dealId: null, agencyId: $this->agency->id, branchId: $this->branch->id, userId: null));

        $this->assertSame(S::OFFER, $p->refresh()->stage);
        $this->assertNull(RtsProspect::withoutGlobalScopes()->where('user_id', $this->mate->id)->first(), 'the other agent has no prospect made for them');
    }

    // ── structural guard ─────────────────────────────────────────────────

    /** Events that credit a recipe task but are not a prospect stage (22.2 has no stage for them). */
    private const CREDIT_ONLY = [
        \App\Events\Deal\DealCommissionFinalised::class,   // "commission paid": credits the agents on the deal, ends no stage
        \App\Events\Prospecting\OwnerLinkedToTrackedProperty::class,
        \App\Events\Prospecting\ClaimCreated::class,
        \App\Events\Prospecting\ClaimFeedbackRecorded::class,
        \App\Events\SellerOutreach\PitchSent::class,
        \App\Events\SellerOutreach\OutreachOutcomeUpdated::class,
        \App\Events\RoadToSold\RoadToSoldOutcomeRecorded::class,   // the outcome service moves the prospect itself
    ];

    public function test_every_sell_stock_event_on_the_allowed_list_is_raised_and_either_moves_a_stage_or_is_named_credit_only(): void
    {
        $handled = (new \ReflectionClass(\App\Listeners\RoadToSold\AdvanceProspectsFromDomainEvent::class))->getReflectionConstant('HANDLED')->getValue();
        $root = base_path('app');
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/Listeners/RoadToSold/') && ! str_contains($file->getPathname(), '/Events/')) {
                $source .= file_get_contents($file->getPathname());
            }
        }

        $problems = [];
        foreach (array_keys(RecipeCatalogue::EVENTS) as $class) {
            $short = substr(strrchr($class, '\\'), 1);
            if (! class_exists($class)) {
                $problems[] = "$short: class missing";
                continue;
            }
            $raised = preg_match('/new\s+\\\\?(?:[A-Za-z\\\\]+\\\\)?' . $short . '\s*\(|SafeRaise::dispatch\(\s*\'' . $short . '\'/', $source) === 1;
            if (! $raised && $short !== 'OtpSigned') {
                $problems[] = "$short: never raised";
            }
            if (! in_array($class, $handled, true) && ! in_array($class, self::CREDIT_ONLY, true)) {
                $problems[] = "$short: neither moves a stage nor is named credit-only";
            }
        }
        // OtpSigned is raised by RaiseOtpSignedFromEnvelope (in the listeners folder this scan skips), registered on the envelope-finalised event
        $this->assertStringContainsString('RaiseOtpSignedFromEnvelope', file_get_contents(base_path('app/Providers/AppServiceProvider.php')));
        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
