<?php

namespace Tests\Feature\Platform;

use App\Events\Platform\AgencyContractSigned;
use App\Events\Platform\AgencySetupWizardCompleted;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineDefaultItem;
use App\Models\Platform\AgencyTimelineItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-447 — Agency Timeline. Spec: .ai/specs/agency-timeline-and-platform-esign.md
 */
class AgencyTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    private function agency(string $name = 'Caprivi Realty'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]);
    }

    private function svc(): AgencyTimelineService
    {
        return app(AgencyTimelineService::class);
    }

    private function seedMini(): void
    {
        AgencyTimelineDefaultItem::query()->forceDelete();
        AgencyTimelineDefaultItem::create(['kind' => 'block', 'title' => 'Where we are', 'body' => 'Setting up {{agency_name}}.', 'sort_order' => 10]);
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Questionnaire', 'offset_days' => 3, 'sort_order' => 10]);
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Sign agreement', 'offset_days' => 10, 'sort_order' => 20, 'auto_complete_trigger' => 'contract_signed']);
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Wizard', 'offset_days' => 20, 'sort_order' => 30, 'auto_complete_trigger' => 'setup_wizard_completed']);
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => '{{agency_name}} live', 'offset_days' => 30, 'sort_order' => 40, 'is_go_live' => true]);
    }

    public function test_defaults_are_seeded_by_the_migration_with_one_go_live(): void
    {
        $this->assertGreaterThan(0, AgencyTimelineDefaultItem::where('kind', 'milestone')->count());
        $this->assertSame(1, AgencyTimelineDefaultItem::where('is_go_live', true)->count());
    }

    public function test_start_snapshots_defaults_with_dates_from_start_date(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $tl = $this->svc()->start($agency, Carbon::parse('2026-10-01'), null);

        $dates = AgencyTimelineItem::where('timeline_id', $tl->id)->where('kind', 'milestone')->orderBy('offset_days')->pluck('due_date', 'offset_days');
        $this->assertSame('2026-10-04', $dates[3]->toDateString());
        $this->assertSame('2026-10-31', $dates[30]->toDateString());
        $this->assertSame(48, strlen($tl->token));
        $this->assertDatabaseHas('agency_timeline_events', ['timeline_id' => $tl->id, 'event' => 'started']);
    }

    public function test_second_start_for_same_agency_is_refused(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $this->svc()->start($agency, now(), null);
        $this->expectException(\DomainException::class);
        $this->svc()->start($agency, now(), null);
    }

    public function test_editing_defaults_later_does_not_change_a_running_timeline(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);
        AgencyTimelineDefaultItem::where('title', 'Questionnaire')->update(['title' => 'CHANGED', 'offset_days' => 99]);

        $item = AgencyTimelineItem::where('timeline_id', $tl->id)->where('title', 'Questionnaire')->first();
        $this->assertNotNull($item);
        $this->assertSame('2026-10-04', $item->due_date->toDateString());
    }

    public function test_overdue_open_step_pushes_expected_go_live_out(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);

        // Day +13 after start: the +3 and +10 steps are 10 and 3 days late; +10 is the worst only if still open.
        $today = Carbon::parse('2026-10-14');
        $gl = $this->svc()->goLive($tl, $today);
        $this->assertSame('2026-10-31', $gl['planned']->toDateString());
        $this->assertSame(10, $gl['slip_days']); // questionnaire (due 10-04) is 10 days late
        $this->assertSame('2026-11-10', $gl['expected']->toDateString());

        // Completing the late questionnaire leaves the contract (due 10-11, 3 days late) as the worst.
        $q = AgencyTimelineItem::where('timeline_id', $tl->id)->where('title', 'Questionnaire')->first();
        $this->svc()->setStatus($q, 'done', null);
        $this->assertSame(3, $this->svc()->goLive($tl, $today)['slip_days']);
    }

    public function test_contract_signed_and_wizard_events_tick_only_their_own_items_once(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $tl = $this->svc()->start($agency, now(), null);

        event(new AgencyContractSigned($agency->id, 1, null));
        $this->assertSame('done', AgencyTimelineItem::where('timeline_id', $tl->id)->where('title', 'Sign agreement')->value('status'));
        $this->assertSame('pending', AgencyTimelineItem::where('timeline_id', $tl->id)->where('title', 'Wizard')->value('status'));

        event(new AgencySetupWizardCompleted($agency->id, null));
        $this->assertSame('done', AgencyTimelineItem::where('timeline_id', $tl->id)->where('title', 'Wizard')->value('status'));

        // A replay is a no-op (idempotent): one completion event each.
        event(new AgencyContractSigned($agency->id, 1, null));
        $this->assertSame(1, \App\Models\Platform\AgencyTimelineEvent::where('timeline_id', $tl->id)->where('event', 'status_done')->where('summary', 'like', '%Sign agreement%')->count());
    }

    public function test_other_agency_is_not_ticked(): void
    {
        $this->seedMini();
        $a = $this->agency('A One'); $b = $this->agency('B Two');
        $this->svc()->start($a, now(), null);
        $tlB = $this->svc()->start($b, now(), null);
        event(new AgencyContractSigned($a->id, 1, null));
        $this->assertSame('pending', AgencyTimelineItem::where('timeline_id', $tlB->id)->where('title', 'Sign agreement')->value('status'));
    }

    public function test_changing_start_date_shifts_open_steps_only_and_is_logged(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);
        $done = AgencyTimelineItem::where('timeline_id', $tl->id)->where('title', 'Questionnaire')->first();
        $this->svc()->setStatus($done, 'done', null);

        $this->svc()->changeStartDate($tl, Carbon::parse('2026-10-06'), true, null);

        $this->assertSame('2026-10-04', $done->fresh()->due_date->toDateString()); // done step stays
        $this->assertSame('2026-11-05', AgencyTimelineItem::where('timeline_id', $tl->id)->where('is_go_live', true)->first()->due_date->toDateString());
        $this->assertDatabaseHas('agency_timeline_events', ['timeline_id' => $tl->id, 'event' => 'start_date_changed']);
    }

    public function test_custom_item_belongs_to_one_timeline_only_and_moves_are_logged(): void
    {
        $this->seedMini();
        $a = $this->svc()->start($this->agency('A One'), now(), null);
        $b = $this->svc()->start($this->agency('B Two'), now(), null);
        $item = $this->svc()->addCustomItem($a, ['kind' => 'milestone', 'title' => 'Website review', 'due_date' => '2026-10-20'], null);

        $this->assertTrue($item->is_custom);
        $this->assertSame(0, AgencyTimelineItem::where('timeline_id', $b->id)->where('title', 'Website review')->count());

        $this->svc()->updateItem($item, ['title' => 'Website review', 'due_date' => '2026-10-25'], null);
        $this->assertDatabaseHas('agency_timeline_events', ['timeline_id' => $a->id, 'item_id' => $item->id, 'event' => 'date_moved']);
    }

    // ── HTTP ────────────────────────────────────────────────────────────

    public function test_non_owner_gets_403_on_every_owner_route(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $tl = $this->svc()->start($agency, now(), null);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id]);
        $this->actingAs($admin);

        foreach ([
            route('admin.agency-timelines.index'),
            route('admin.agency-timelines.show', $tl),
            route('admin.agency-timelines.start-form', $agency),
            route('admin.timeline-defaults.index'),
            route('admin.platform-esign.enter'),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('admin.agency-timelines.start', $agency), ['start_date' => now()->toDateString()])->assertForbidden();
        $this->post(route('admin.agency-timelines.link', $tl), ['action' => 'regenerate'])->assertForbidden();
    }

    public function test_owner_can_start_and_view_timeline_pages(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $this->actingAs($this->owner());

        $this->get(route('admin.agency-timelines.index'))->assertOk()->assertSee('Start timeline');
        $this->get(route('admin.agency-timelines.start-form', $agency))->assertOk()->assertSee('Questionnaire');
        $this->post(route('admin.agency-timelines.start', $agency), ['start_date' => now()->toDateString()])->assertRedirect();

        $tl = AgencyTimeline::where('agency_id', $agency->id)->firstOrFail();
        $this->get(route('admin.agency-timelines.show', $tl))->assertOk()->assertSee('Mark agency live')->assertSee('Public link');
        $this->get(route('admin.agency-timelines.show', ['timeline' => $tl, 'tab' => 'history']))->assertOk()->assertSee('Timeline started');
        $this->get(route('admin.timeline-defaults.index'))->assertOk()->assertSee('Default steps');
    }

    public function test_start_date_cannot_be_in_the_past_and_form_clamps_to_today(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $this->actingAs($this->owner());

        $this->post(route('admin.agency-timelines.start', $agency), ['start_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('start_date');
        $this->assertNull(AgencyTimeline::where('agency_id', $agency->id)->first());

        // An old date in the URL (e.g. the agency's creation date) never pre-fills a past start.
        $this->get(route('admin.agency-timelines.start-form', [$agency, 'start_date' => '2026-03-02']))
            ->assertOk()->assertDontSee('2026-03-02')->assertSee(now()->toDateString());
    }

    public function test_step_dates_can_be_customised_on_start_and_cannot_precede_the_start_date(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $this->actingAs($this->owner());
        $q = AgencyTimelineDefaultItem::where('title', 'Questionnaire')->first();
        $custom = now()->addDays(20)->toDateString();

        $this->post(route('admin.agency-timelines.start', $agency), [
            'start_date' => now()->addDay()->toDateString(), 'dates' => [$q->id => now()->toDateString()],
        ])->assertSessionHasErrors('dates.' . $q->id);

        $this->post(route('admin.agency-timelines.start', $agency), [
            'start_date' => now()->addDay()->toDateString(), 'dates' => [$q->id => $custom],
        ])->assertRedirect();

        $tl = AgencyTimeline::where('agency_id', $agency->id)->firstOrFail();
        $this->assertSame($custom, AgencyTimelineItem::where('timeline_id', $tl->id)->where('source_default_id', $q->id)->first()->due_date->toDateString());
    }

    // ── Platform e-sign (CoreX's own contracts run in the real e-sign, in a platform agency) ──

    private function platformDocument(?int $agencyId, int $ownerId, string $status): \App\Models\Docuperfect\SignatureTemplate
    {
        $docId = \Illuminate\Support\Facades\DB::table('docuperfect_documents')->insertGetId([
            'name' => 'Subscription Agreement', 'agency_id' => $agencyId, 'owner_id' => $ownerId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return \App\Models\Docuperfect\SignatureTemplate::withoutGlobalScopes()->create([
            'document_id' => $docId, 'agency_id' => $agencyId, 'status' => $status,
        ]);
    }

    public function test_signed_platform_document_ticks_the_agreement_step_once_linked(): void
    {
        $this->seedMini();
        $owner = $this->owner();
        $this->actingAs($owner);
        $agency = $this->agency();
        $tl = $this->svc()->start($agency, now(), $owner->id);
        $doc = $this->platformDocument(null, $owner->id, 'signing');
        $step = AgencyTimelineItem::where('timeline_id', $tl->id)->where('auto_complete_trigger', 'contract_signed')->firstOrFail();

        $this->post(route('admin.agency-timelines.agreement', $tl), ['template_id' => $doc->id])->assertRedirect();
        $this->assertSame('pending', $step->fresh()->status, 'not signed yet');

        $doc->update(['status' => 'completed', 'completed_at' => now()]);
        $this->get(route('admin.agency-timelines.show', $tl))->assertOk()->assertSee('Subscription Agreement');
        $this->assertSame('done', $step->fresh()->status);
    }

    public function test_only_an_agency_less_platform_document_can_be_linked_as_the_agreement(): void
    {
        $this->seedMini();
        $owner = $this->owner();
        $this->actingAs($owner);
        $agency = $this->agency();
        $other = $this->agency('Some Other Agency');
        $tl = $this->svc()->start($agency, now(), $owner->id);
        $foreign = $this->platformDocument($other->id, $owner->id, 'completed');

        $this->post(route('admin.agency-timelines.agreement', $tl), ['template_id' => $foreign->id])->assertStatus(422);
        $this->assertNull($tl->fresh()->agreement_template_id);
    }

    public function test_owner_edits_defaults_with_single_go_live_enforced(): void
    {
        $this->seedMini();
        $this->actingAs($this->owner());
        $q = AgencyTimelineDefaultItem::where('title', 'Questionnaire')->first();

        $this->put(route('admin.timeline-defaults.update', $q->id), ['title' => 'Questionnaire', 'offset_days' => 5, 'is_go_live' => 1, 'is_public' => 1])->assertRedirect();
        $this->assertSame(5, $q->fresh()->offset_days);
        $this->assertSame(1, AgencyTimelineDefaultItem::where('is_go_live', true)->count());
        $this->assertTrue($q->fresh()->is_go_live);

        $this->put(route('admin.timeline-defaults.update', $q->id), ['title' => 'Questionnaire', 'is_public' => 1])->assertSessionHasErrors('offset_days');

        $this->delete(route('admin.timeline-defaults.destroy', $q->id))->assertRedirect();
        $this->assertSoftDeleted('agency_timeline_default_items', ['id' => $q->id]);
        $this->post(route('admin.timeline-defaults.restore', $q->id))->assertRedirect();
        $this->assertNull($q->fresh()->deleted_at);
    }

    // ── Public page ─────────────────────────────────────────────────────

    public function test_public_page_shows_states_hides_private_and_leaks_nothing(): void
    {
        $this->seedMini();
        $agency = $this->agency('Caprivi Realty');
        $tl = $this->svc()->start($agency, Carbon::parse('2026-09-20'), null);
        $this->svc()->addCustomItem($tl, ['kind' => 'milestone', 'title' => 'SECRET INTERNAL STEP', 'due_date' => '2026-10-02', 'is_public' => false], null);
        $q = AgencyTimelineItem::where('timeline_id', $tl->id)->where('title', 'Questionnaire')->first();
        $this->svc()->setStatus($q, 'done', null);
        Carbon::setTestNow('2026-10-05 09:00:00');

        $res = $this->get('/agency-timeline/' . $tl->token);
        $res->assertOk()->assertSee('Caprivi Realty live')->assertSee('Overdue')->assertSee('Done')
            ->assertDontSee('SECRET INTERNAL STEP')->assertSee('Setting up Caprivi Realty.')
            ->assertSee('Moved from');
        $this->assertStringContainsString('noindex', $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_disabled_regenerated_and_unknown_tokens_get_the_same_neutral_page(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), now(), null);
        $old = $tl->token;

        $this->svc()->setLinkEnabled($tl, false, null);
        $disabled = $this->get('/agency-timeline/' . $old);
        $this->svc()->setLinkEnabled($tl, true, null);
        $this->svc()->regenerateToken($tl, null);
        $regen = $this->get('/agency-timeline/' . $old);
        $unknown = $this->get('/agency-timeline/' . str_repeat('x', 48));
        $short = $this->get('/agency-timeline/abc');

        foreach ([$disabled, $regen, $unknown, $short] as $r) {
            $r->assertNotFound()->assertSee('This link is no longer active');
        }
        $this->get('/agency-timeline/' . $tl->fresh()->token)->assertOk();
    }
}
