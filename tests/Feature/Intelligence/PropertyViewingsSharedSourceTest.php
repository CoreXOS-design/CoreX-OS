<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\CommandCenter\AgencyFeedbackOption;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Properties\PropertyViewings;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ONE source for "how many viewings" and "what did buyers say about THIS property"
 * (App\Services\Properties\PropertyViewings), read by the agent Intelligence tab, the
 * seller live link and the client mobile API.
 *
 * Incident 2026-10-08, property 1482: seller link said 1 viewing, Intelligence said 2; the
 * seller link claimed "1 of 1 mentioned Damp" and "No viewing feedback yet" at once; the agent
 * list showed another property's "Internal: ...layout..." note under 1482 and no concerns at all.
 */
final class PropertyViewingsSharedSourceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $buyer;
    private AgencyFeedbackOption $damp;
    private AgencyFeedbackOption $layout;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);
        $this->buyer  = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lettie', 'last_name' => 'Venter', 'phone' => '0831234567', 'email' => 'lettie@example.co.za',
        ]);
        $this->damp   = $this->option('concern', 'Damp / maintenance');
        $this->layout = $this->option('concern', 'Layout');
    }

    // ── the property 1482 incident, end to end ──────────────────────────

    public function test_property_1482_scenario_both_screens_agree_and_nothing_leaks(): void
    {
        $a = $this->property('Manaba Beach apartment');   // "1482"
        $b = $this->property('Manaba townhouse');          // "3206", shown in the same appointment

        // The real viewing: two properties in one appointment, completed.
        $real = $this->viewing('Viewing with Lettie Venter — 2 properties', [$a, $b], ['status' => 'completed']);
        $this->feedback($real, $a, ['concern_option_ids' => [$this->damp->id], 'internal_notes' => 'SECRET-A more interested in Butleigh']);
        $this->feedback($real, $b, ['concern_option_ids' => [$this->layout->id], 'internal_notes' => 'SECRET-B lay out not up to par']);

        // The test booking: deleted AND dismissed, still linked to A, no feedback.
        $test = $this->viewing('Viewing with Test p — 5 properties', [$a], ['status' => 'dismissed']);
        $test->delete();

        $intel = app(PropertyIntelligenceService::class);
        $this->assertSame(1, $intel->getFeedbackRollup($a->id)['total_viewings']);
        $this->assertSame(1, $intel->getFeedbackRollup($a->id, excludeInternalOnly: true)['total_viewings']);
        $this->assertCount(1, $intel->getRecentViewings($a->id));

        $link = $this->sellerLink($a);
        $seller = $this->get('/property/live/' . $link->token)->assertOk()->getContent();

        $this->assertStringContainsString('1 viewing recorded so far', $seller);
        $this->assertStringContainsString('1 of 1 viewer mentioned Damp / maintenance', $seller);
        $this->assertStringNotContainsString('No viewing feedback yet', $seller, 'the page must not contradict its own themes line');
        $this->assertStringNotContainsString('Layout', $seller, "another property's concern must not reach this seller");
        foreach (['SECRET-A', 'SECRET-B', 'Butleigh', 'lay out', 'Lettie', 'Venter'] as $leak) {
            $this->assertStringNotContainsString($leak, $seller, "'$leak' leaked onto the seller page");
        }

        $agentHtml = $this->actingAs($this->agent)->get(route('corex.properties.show', $a))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*1\s*<\/div>\s*<div[^>]*>\s*Total Viewings/u', $agentHtml);
        $this->assertStringContainsString('Damp / maintenance', $agentHtml, 'concerns must show on the Intelligence tab');
        $this->assertStringContainsString('SECRET-A', $agentHtml, "the agent sees this property's own internal note");
        $this->assertStringNotContainsString('SECRET-B', $agentHtml, "another property's internal note must not show under this property");
        $this->assertStringNotContainsString('Test p', $agentHtml, 'the deleted/dismissed test booking must not be listed');
    }

    public function test_the_other_property_in_the_same_appointment_gets_its_own_view(): void
    {
        $a = $this->property('A');
        $b = $this->property('B');
        $ev = $this->viewing('Viewing — 2 properties', [$a, $b], ['status' => 'completed']);
        $this->feedback($ev, $a, ['concern_option_ids' => [$this->damp->id]]);
        $this->feedback($ev, $b, ['concern_option_ids' => [$this->layout->id]]);

        $svc = app(PropertyViewings::class);
        $this->assertSame([['label' => 'Damp / maintenance', 'count' => 1, 'total' => 1]], $svc->themes($a->id, true));
        $this->assertSame([['label' => 'Layout', 'count' => 1, 'total' => 1]], $svc->themes($b->id, true));
        $this->assertSame(['Damp / maintenance' => 1], $svc->rollup($a->id)['top_concern_labels']);
        $this->assertSame(1, $svc->rollup($a->id)['total_feedback_rows']);
    }

    // ── what counts as a viewing ────────────────────────────────────────

    public function test_deleted_and_dismissed_viewings_never_count_anywhere(): void
    {
        $p = $this->property('P');
        $deleted = $this->viewing('deleted', [$p], ['status' => 'completed']);
        $this->feedback($deleted, $p, []);
        $deleted->delete();
        $dismissed = $this->viewing('dismissed', [$p], ['status' => 'dismissed']);
        $this->feedback($dismissed, $p, []);

        $svc = app(PropertyViewings::class);
        $this->assertSame(0, $svc->rollup($p->id)['total_viewings']);
        $this->assertSame(0, $svc->rollup($p->id, true)['total_viewings']);
        $this->assertSame(0, $svc->rollup($p->id)['total_feedback_rows']);
        $this->assertCount(0, $svc->recentForAgent($p->id));
    }

    public function test_a_booked_but_unconfirmed_viewing_is_not_claimed_as_held_but_is_still_listed_for_the_agent(): void
    {
        $p = $this->property('P');
        $this->viewing('upcoming', [$p], ['status' => 'pending', 'event_date' => now()->addDays(2)]);

        $svc = app(PropertyViewings::class);
        $this->assertSame(0, $svc->rollup($p->id)['total_viewings']);
        $this->assertCount(1, $svc->recentForAgent($p->id), 'the agent still needs it listed to book / capture feedback');
    }

    public function test_a_completed_viewing_counts_even_with_no_feedback_and_the_page_says_so_once(): void
    {
        $p = $this->property('P');
        $this->viewing('done', [$p], ['status' => 'completed']);

        $this->assertSame(1, app(PropertyViewings::class)->rollup($p->id, true)['total_viewings']);

        $seller = $this->get('/property/live/' . $this->sellerLink($p)->token)->assertOk()->getContent();
        $this->assertStringContainsString('1 viewing recorded so far', $seller);
        $this->assertSame(1, substr_count($seller, 'No viewing feedback yet'));
        $this->assertStringNotContainsString('mentioned', $seller);
    }

    public function test_events_that_are_not_viewings_do_not_count(): void
    {
        $p = $this->property('P');
        $lp = $this->viewing('Listing', [$p], ['status' => 'completed', 'category' => 'listing_presentation']);
        $this->feedback($lp, $p, [], ['feedback_kind' => 'viewing']);   // real Staging rows carry this mis-stamp

        $this->assertSame(0, app(PropertyViewings::class)->rollup($p->id)['total_viewings']);
    }

    public function test_two_buyers_on_one_viewing_count_once_and_a_repeated_concern_counts_once(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'completed']);
        $other = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Co', 'last_name' => 'Buyer', 'phone' => '0829999999', 'email' => 'co@example.co.za']);
        $this->feedback($ev, $p, ['concern_option_ids' => [$this->damp->id]]);
        $this->feedback($ev, $p, ['concern_option_ids' => [$this->damp->id]], ['contact_id' => $other->id]);

        $svc = app(PropertyViewings::class);
        $this->assertSame(1, $svc->rollup($p->id)['total_viewings']);
        $this->assertSame([['label' => 'Damp / maintenance', 'count' => 1, 'total' => 1]], $svc->themes($p->id, true));
    }

    // ── per-property attribution of property-less feedback ──────────────

    public function test_feedback_without_a_property_belongs_to_the_event_linked_property_only(): void
    {
        $linked = $this->property('Linked');
        $unrelated = $this->property('Unrelated');
        $ev = $this->viewing('single', [$linked], ['status' => 'completed']);
        $this->feedback($ev, null, ['concern_option_ids' => [$this->layout->id]]);

        $svc = app(PropertyViewings::class);
        $this->assertSame(1, $svc->rollup($linked->id)['total_feedback_rows']);
        $this->assertSame(0, $svc->rollup($unrelated->id)['total_feedback_rows']);
        $this->assertSame(0, $svc->rollup($unrelated->id)['total_viewings']);
    }

    // ── internal vs seller-visible ──────────────────────────────────────

    public function test_internal_only_rows_and_internal_notes_never_reach_a_seller_structure(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'completed']);
        $this->feedback($ev, $p, ['seller_visible_notes' => 'Loved the sea view', 'internal_notes' => 'INTERNAL-1', 'next_action_notes' => 'NEXT-1']);
        $ev2 = $this->viewing('v2', [$p], ['status' => 'completed']);
        $this->feedback($ev2, $p, ['seller_visible_notes' => 'HIDDEN-ROW', 'internal_notes' => 'INTERNAL-2', 'concern_option_ids' => [$this->layout->id]], ['visibility' => 'internal_only']);

        $svc = app(PropertyViewings::class);
        $notes = $svc->sellerNotes($p->id);
        $this->assertCount(1, $notes);
        $this->assertSame('Loved the sea view', $notes[0]['notes']);
        $this->assertSame(['outcome_label', 'notes', 'date'], array_keys($notes[0]));
        $this->assertStringNotContainsString('INTERNAL', json_encode($notes));
        $this->assertStringNotContainsString('NEXT-1', json_encode($notes));
        $this->assertSame([], $svc->themes($p->id, true), 'an internal_only row must not feed the seller themes');
        $this->assertSame(2, $svc->rollup($p->id, false)['total_viewings'], 'the COUNT is the same for every audience');
        $this->assertSame(2, $svc->rollup($p->id, true)['total_viewings']);

        $seller = $this->get('/property/live/' . $this->sellerLink($p)->token)->assertOk()->getContent();
        $this->assertStringContainsString('Loved the sea view', $seller);
        foreach (['INTERNAL', 'NEXT-1', 'HIDDEN-ROW'] as $leak) {
            $this->assertStringNotContainsString($leak, $seller);
        }
    }

    public function test_themes_with_notes_show_both_and_never_the_empty_message(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'completed']);
        $this->feedback($ev, $p, ['concern_option_ids' => [$this->damp->id], 'seller_visible_notes' => 'Needs some paint']);

        $seller = $this->get('/property/live/' . $this->sellerLink($p)->token)->assertOk()->getContent();
        $this->assertStringContainsString('1 of 1 viewer mentioned Damp / maintenance', $seller);
        $this->assertStringContainsString('Needs some paint', $seller);
        $this->assertStringNotContainsString('No viewing feedback yet', $seller);
    }

    public function test_soft_deleted_feedback_is_ignored(): void
    {
        $p = $this->property('P');
        $ev = $this->viewing('v', [$p], ['status' => 'completed']);
        $fb = $this->feedback($ev, $p, ['concern_option_ids' => [$this->damp->id]]);
        $fb->delete();

        $svc = app(PropertyViewings::class);
        $this->assertSame(0, $svc->rollup($p->id)['total_feedback_rows']);
        $this->assertSame([], $svc->themes($p->id));
        $this->assertSame(1, $svc->rollup($p->id)['total_viewings'], 'completed viewing still counts; its removed feedback does not');
    }

    public function test_no_viewings_at_all_is_clean(): void
    {
        $p = $this->property('P');
        $svc = app(PropertyViewings::class);
        $this->assertSame(0, $svc->rollup($p->id)['total_viewings']);
        $this->assertSame([], $svc->themes($p->id));
        $this->assertSame([], $svc->sellerNotes($p->id));
        $this->assertCount(0, $svc->recentForAgent($p->id));
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function option(string $category, string $label): AgencyFeedbackOption
    {
        return AgencyFeedbackOption::withoutGlobalScopes()->create([
            'agency_id' => null, 'category' => $category, 'label' => $label, 'is_active' => true, 'sort_order' => 1, 'is_system_default' => true,
        ]);
    }

    private function property(string $title): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => $title . ' ' . Str::random(4), 'suburb' => 'Margate',
            'property_type' => 'apartment', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1_000_000,
        ]);
    }

    /** @param Property[] $properties */
    private function viewing(string $title, array $properties, array $extra = []): CalendarEvent
    {
        $ev = CalendarEvent::withoutGlobalScopes()->create(array_merge([
            'user_id' => $this->agent->id, 'created_by_id' => $this->agent->id, 'event_type' => 'manual', 'category' => 'viewing',
            'title' => $title, 'event_date' => now()->subDays(3), 'status' => 'pending',
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ], $extra));
        foreach ($properties as $p) {
            CalendarEventLink::withoutGlobalScopes()->create([
                'agency_id' => $this->agency->id, 'calendar_event_id' => $ev->id,
                'linkable_type' => Property::class, 'linkable_id' => $p->id, 'role' => 'subject_property',
            ]);
        }
        CalendarEventLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'calendar_event_id' => $ev->id,
            'linkable_type' => Contact::class, 'linkable_id' => $this->buyer->id, 'role' => 'buyer_contact',
        ]);

        return $ev;
    }

    private function feedback(CalendarEvent $ev, ?Property $p, array $fields, array $extra = []): CalendarEventFeedback
    {
        return CalendarEventFeedback::withoutGlobalScopes()->create(array_merge([
            'calendar_event_id' => $ev->id, 'contact_id' => $this->buyer->id, 'property_id' => $p?->id,
            'feedback_kind' => 'viewing', 'visibility' => 'public_to_seller', 'concern_option_ids' => [],
            'captured_by_user_id' => $this->agent->id, 'captured_at' => now()->subDays(2),
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ], $fields, $extra));
    }

    private function sellerLink(Property $p): PropertySellerLink
    {
        $seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Tanya', 'last_name' => 'Seller' . Str::random(3),
            'phone' => '083' . random_int(1000000, 9999999), 'email' => 'tanya-' . Str::random(5) . '@example.co.za',
        ]);

        return PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'contact_id' => $seller->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->agent->id, 'generated_at' => now(),
        ]);
    }
}
