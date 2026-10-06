<?php

namespace Tests\Feature\Platform;

use App\Events\Platform\AgencyContractSigned;
use App\Events\Platform\AgencySetupWizardCompleted;
use App\Events\Platform\AgencyTimelineMilestoneCompleted;
use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineDefaultItem;
use App\Models\Platform\AgencyTimelineEvent;
use App\Models\Platform\AgencyTimelineItem;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Template;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Audit fixes for the Agency Timeline (audit B, 2026-10-06): read paths never write, idempotent triggers, wizard catch-up,
 * one timeline per agency under a double click, validation instead of 500s, archived items frozen, public page counts public steps only.
 */
class AgencyTimelineAuditFixesTest extends TestCase
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
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Questionnaire', 'offset_days' => 3, 'sort_order' => 10, 'agency_can_complete' => true]);
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Sign agreement', 'offset_days' => 10, 'sort_order' => 20, 'auto_complete_trigger' => 'contract_signed']);
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Wizard', 'offset_days' => 20, 'sort_order' => 30, 'auto_complete_trigger' => 'setup_wizard_completed']);
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Go live', 'offset_days' => 30, 'sort_order' => 40, 'is_go_live' => true]);
    }

    private function step(AgencyTimeline $tl, string $title): AgencyTimelineItem
    {
        return AgencyTimelineItem::withTrashed()->where('timeline_id', $tl->id)->where('title', $title)->firstOrFail();
    }

    private function agreementDoc(?int $agencyId, string $status, string $kind = 'subscription_agreement'): Document
    {
        $tpl = Template::create(['name' => 'T ' . $kind . uniqid(), 'kind' => $kind, 'source' => 'web', 'body' => 'x', 'roles_json' => [], 'is_active' => 1]);

        return Document::create([
            'template_id' => $tpl->id, 'agency_id' => $agencyId, 'title' => 'Subscription Agreement', 'status' => $status,
            'source' => 'webdoc', 'body_html_snapshot' => '<p>x</p>',
        ]);
    }

    private function setupFor(Agency $agency, array $attrs = []): AgencyOnboardingSetup
    {
        $s = new AgencyOnboardingSetup();
        $s->agency_id = $agency->id;
        $s->token = AgencyOnboardingSetup::generateToken();
        $s->slug = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $s->current_step = 1;
        $s->completed_steps = [];
        $s->expires_at = now()->addDays(30);
        $s->forceFill($attrs);
        $s->save();

        return $s;
    }

    // ── B-M1 / B-L1 ────────────────────────────────────────────────────────

    public function test_reading_a_signed_timeline_never_fires_the_contract_event_and_a_reopen_survives(): void
    {
        $this->seedMini();
        $owner = $this->owner();
        $this->actingAs($owner);
        $agency = $this->agency();
        $tl = $this->svc()->start($agency, now(), $owner->id);
        $fired = 0;
        Event::listen(AgencyContractSigned::class, function () use (&$fired) {
            $fired++;
        });

        $doc = $this->agreementDoc($agency->id, 'completed');
        $this->svc()->linkAgreement($tl, $doc->id, $owner->id);   // the transition: ticks the step, fires once
        $this->assertSame(1, $fired);
        $this->assertSame('done', $this->step($tl, 'Sign agreement')->status);

        // Anonymous public views and admin opens write nothing and fire nothing.
        $this->get($tl->publicUrl())->assertOk();
        $this->get($tl->publicUrl())->assertOk();
        $this->get(route('admin.agency-timelines.show', $tl))->assertOk();
        $this->assertSame(1, $fired, 'page reads must not fire AgencyContractSigned');

        // The owner reopens the auto-ticked step: the next reads must not tick it again.
        $this->svc()->setStatus($this->step($tl, 'Sign agreement'), 'pending', $owner->id);
        $this->get(route('admin.agency-timelines.show', $tl))->assertOk();
        $this->get($tl->publicUrl())->assertOk();
        $this->assertSame('pending', $this->step($tl, 'Sign agreement')->status);
        $this->assertSame(1, $fired);
    }

    public function test_sync_agreement_is_silent_when_no_step_is_waiting(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $tl = $this->svc()->start($agency, now(), null);
        $doc = $this->agreementDoc($agency->id, 'completed');
        $tl->update(['agreement_document_id' => $doc->id]);
        $fired = 0;
        Event::listen(AgencyContractSigned::class, function () use (&$fired) {
            $fired++;
        });

        $this->svc()->syncAgreement($tl->fresh());
        $this->svc()->syncAgreement($tl->fresh());
        $this->svc()->syncAgreement($tl->fresh());

        $this->assertSame(1, $fired, 'once for the waiting step, then silent');
    }

    // ── B-M2 ───────────────────────────────────────────────────────────────

    public function test_a_wizard_finished_before_the_timeline_started_ticks_its_step_at_start(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $this->setupFor($agency, ['completed_at' => now()->subDays(3)]);

        $tl = $this->svc()->start($agency, now(), null);

        $step = $this->step($tl, 'Wizard');
        $this->assertSame('done', $step->status);
        $this->assertSame('setup_wizard_completed', $step->completed_source);
        $this->assertSame('pending', $this->step($tl, 'Sign agreement')->status, 'only the wizard step is caught up');
    }

    public function test_an_unfinished_wizard_does_not_tick_anything_at_start(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $this->setupFor($agency);

        $tl = $this->svc()->start($agency, now(), null);

        $this->assertSame('pending', $this->step($tl, 'Wizard')->status);
    }

    public function test_finishing_the_wizard_ticks_the_timeline_step_end_to_end(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->setupFor($agency);
        $tl = $this->svc()->start($agency, now(), null);

        $this->actingAs($admin)->post(route('corex.agency-setup.finish'))->assertRedirect(route('dashboard'));

        $this->assertSame('done', $this->step($tl, 'Wizard')->status);
    }

    public function test_a_failing_timeline_hook_can_never_break_the_wizard_finish(): void
    {
        $agency = $this->agency();
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $setup = $this->setupFor($agency);
        Event::listen(AgencySetupWizardCompleted::class, function () {
            throw new \RuntimeException('timeline exploded');
        });

        $this->actingAs($admin)->post(route('corex.agency-setup.finish'))->assertRedirect(route('dashboard'));

        $this->assertNotNull($setup->fresh()->completed_at, 'completion is saved even though the hook failed');
    }

    public function test_the_listener_logs_and_swallows_a_failure(): void
    {
        $agency = $this->agency();
        $this->seedMini();
        $this->svc()->start($agency, now(), null);
        $this->mock(AgencyTimelineService::class, function ($m) {
            $m->shouldReceive('setStatus')->andThrow(new \RuntimeException('db down'));
        });

        // Must not throw.
        app(\App\Listeners\Platform\CompleteTimelineItemsOnTrigger::class)->handle(new AgencySetupWizardCompleted($agency->id, null));
        $this->assertTrue(true);
    }

    // ── B-M3 ───────────────────────────────────────────────────────────────

    public function test_start_takes_a_row_lock_on_the_agency_and_a_double_start_creates_one_timeline(): void
    {
        $this->seedMini();
        $agency = $this->agency();

        DB::enableQueryLog();
        $this->svc()->start($agency, now(), null);
        $locked = collect(DB::getQueryLog())->contains(fn ($q) => stripos($q['query'], 'for update') !== false && str_contains($q['query'], 'agencies'));
        DB::disableQueryLog();
        $this->assertTrue($locked, 'the agency row is locked before the exists() re-check');

        try {
            $this->svc()->start($agency, now(), null);
            $this->fail('second start must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('already has a timeline', $e->getMessage());
        }
        $this->assertSame(1, AgencyTimeline::where('agency_id', $agency->id)->count());
    }

    public function test_a_double_click_on_start_lands_on_the_existing_timeline(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $this->actingAs($this->owner());

        $this->post(route('admin.agency-timelines.start', $agency), ['start_date' => now()->toDateString()])->assertRedirect();
        $this->post(route('admin.agency-timelines.start', $agency), ['start_date' => now()->toDateString()])->assertSessionHas('warning');

        $this->assertSame(1, AgencyTimeline::where('agency_id', $agency->id)->count());
    }

    // ── B-L2 ───────────────────────────────────────────────────────────────

    public function test_milestones_only_swap_with_a_neighbour_on_the_same_date(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);
        $a = $this->svc()->addCustomItem($tl, ['kind' => 'milestone', 'title' => 'Same day A', 'due_date' => '2026-10-04'], null);
        $b = $this->svc()->addCustomItem($tl, ['kind' => 'milestone', 'title' => 'Same day B', 'due_date' => '2026-10-04'], null);

        // Questionnaire is on 4 Oct too (offset 3) — three steps share the date. Different dates never swap.
        $sign = $this->step($tl, 'Sign agreement');
        $before = $sign->sort_order;
        $this->svc()->move($sign, 'up', null);
        $this->assertSame($before, $sign->fresh()->sort_order, 'a step on its own date has nothing to swap with');

        $this->svc()->move($b, 'up', null);
        $this->assertLessThan($a->fresh()->sort_order, $b->fresh()->sort_order, 'B moved above A (same date)');
    }

    public function test_the_move_arrows_are_disabled_when_there_is_no_same_day_neighbour(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);
        $this->actingAs($this->owner());

        $html = $this->get(route('admin.agency-timelines.show', $tl))->assertOk()->getContent();
        $this->assertStringContainsString('Steps are listed by date', $html);
    }

    // ── B-L3 ───────────────────────────────────────────────────────────────

    public function test_only_this_agencys_subscription_agreement_can_be_linked(): void
    {
        $this->seedMini();
        $this->actingAs($this->owner());
        $mine = $this->agency('Mine');
        $other = $this->agency('Other');
        $tl = $this->svc()->start($mine, now(), null);

        $theirs = $this->agreementDoc($other->id, 'completed');
        $wrongKind = $this->agreementDoc($mine->id, 'completed', 'debit_order');
        $noAgency = $this->agreementDoc(null, 'completed');
        $ok = $this->agreementDoc($mine->id, 'sent');

        foreach ([$theirs, $wrongKind, $noAgency] as $bad) {
            $this->post(route('admin.agency-timelines.agreement', $tl), ['document_id' => $bad->id])->assertStatus(422);
        }
        $this->assertNull($tl->fresh()->agreement_document_id);
        $this->assertSame('pending', $this->step($tl, 'Sign agreement')->status);

        $this->post(route('admin.agency-timelines.agreement', $tl), ['document_id' => $ok->id])->assertRedirect();
        $this->assertSame($ok->id, $tl->fresh()->agreement_document_id);
    }

    // ── B-L4 / B-L6 ────────────────────────────────────────────────────────

    public function test_a_double_tick_from_the_public_link_is_one_success_one_event_one_history_line(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), now(), null);
        $q = $this->step($tl, 'Questionnaire');
        $fired = 0;
        Event::listen(AgencyTimelineMilestoneCompleted::class, function () use (&$fired) {
            $fired++;
        });
        $url = '/agency-timeline/' . $tl->token . '/steps/' . $q->id;

        $this->post($url, ['status' => 'done'])->assertRedirect($tl->publicUrl())->assertSessionHas('tl_ok');
        $this->post($url, ['status' => 'done'])->assertRedirect($tl->publicUrl())->assertSessionHas('tl_ok');   // double click: same success, not a 403

        $this->assertSame(1, $fired);
        $this->assertSame(1, AgencyTimelineEvent::where('item_id', $q->id)->where('event', 'status_done')->count());
    }

    public function test_set_status_is_atomic_against_a_stale_copy_of_the_row(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), now(), null);
        $stale1 = $this->step($tl, 'Questionnaire');
        $stale2 = $this->step($tl, 'Questionnaire');
        $fired = 0;
        Event::listen(AgencyTimelineMilestoneCompleted::class, function () use (&$fired) {
            $fired++;
        });

        $this->svc()->setStatus($stale1, 'done', null, 'agency');
        $this->svc()->setStatus($stale2, 'done', null, 'agency');   // loaded before the first tick, still says "pending"

        $this->assertSame(1, $fired);
        $this->assertSame(1, AgencyTimelineEvent::where('item_id', $stale1->id)->where('event', 'status_done')->count());
    }

    public function test_the_public_go_live_date_ignores_hidden_internal_steps(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);
        // An internal, hidden step that is badly overdue must not push the date the agency sees.
        $this->svc()->addCustomItem($tl, ['kind' => 'milestone', 'title' => 'Internal chore', 'due_date' => '2026-10-02', 'is_public' => false], null);
        $today = Carbon::parse('2026-10-20');

        $all = $this->svc()->goLive($tl, $today);
        $public = $this->svc()->goLive($tl, $today, true);

        $this->assertGreaterThan(0, $all['slip_days']);
        $this->assertSame('2026-10-31', $public['planned']->toDateString());
        // Public slip is driven only by the public steps (the 4 Oct Questionnaire is 16 days late, the hidden one 18).
        $this->assertSame(16, $public['slip_days']);
        $this->assertSame(18, $all['slip_days']);
    }

    public function test_a_hidden_go_live_step_gives_the_public_page_no_date(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);
        $this->step($tl, 'Go live')->update(['is_public' => false]);

        $this->assertNull($this->svc()->goLive($tl, now(), true)['planned']);
        $this->assertNotNull($this->svc()->goLive($tl, now())['planned']);
    }

    // ── B-L7 ───────────────────────────────────────────────────────────────

    public function test_archived_items_cannot_be_edited_ticked_moved_or_archived_again(): void
    {
        $this->seedMini();
        $owner = $this->owner();
        $this->actingAs($owner);
        $tl = $this->svc()->start($this->agency(), now(), $owner->id);
        $q = $this->step($tl, 'Questionnaire');
        $this->svc()->archiveItem($q, $owner->id);
        $events = AgencyTimelineEvent::where('timeline_id', $tl->id)->count();
        $fired = 0;
        Event::listen(AgencyTimelineMilestoneCompleted::class, function () use (&$fired) {
            $fired++;
        });

        $route = fn ($n) => route('admin.agency-timelines.' . $n, [$tl, $q->id]);
        $this->put($route('items.update'), ['title' => 'Renamed'])->assertSessionHas('warning');
        $this->post($route('items.status'), ['status' => 'done'])->assertSessionHas('warning');
        $this->post($route('items.move'), ['direction' => 'down'])->assertSessionHas('warning');
        $this->delete($route('items.archive'))->assertSessionHas('warning');

        $q = $this->step($tl, 'Questionnaire');
        $this->assertSame('pending', $q->status);
        $this->assertSame('Questionnaire', $q->title);
        $this->assertSame(0, $fired);
        $this->assertSame($events, AgencyTimelineEvent::where('timeline_id', $tl->id)->count(), 'no spurious history lines');
    }

    public function test_restoring_an_active_item_is_refused_without_a_history_line(): void
    {
        $this->seedMini();
        $owner = $this->owner();
        $this->actingAs($owner);
        $tl = $this->svc()->start($this->agency(), now(), $owner->id);
        $q = $this->step($tl, 'Questionnaire');
        $events = AgencyTimelineEvent::where('timeline_id', $tl->id)->count();

        $this->post(route('admin.agency-timelines.items.restore', [$tl, $q->id]))->assertSessionHas('warning');

        $this->assertSame($events, AgencyTimelineEvent::where('timeline_id', $tl->id)->count());
    }

    // ── B-L8 ───────────────────────────────────────────────────────────────

    public function test_garbled_dates_give_messages_not_500s(): void
    {
        $this->seedMini();
        $agency = $this->agency();
        $owner = $this->owner();
        $this->actingAs($owner);

        $this->get(route('admin.agency-timelines.index', ['start_from' => 'abc', 'start_to' => '99/99/99']))->assertOk()->assertSee('was not a valid date');
        $this->get(route('admin.agency-timelines.start-form', [$agency, 'start_date' => 'not-a-date']))->assertOk();
        $this->post(route('admin.agency-timelines.start', $agency), ['start_date' => 'tomorrow-ish'])->assertSessionHasErrors('start_date');
        $this->post(route('admin.agency-timelines.start', $agency), ['start_date' => now()->toDateString() . ' 10:00:00'])->assertSessionHasErrors('start_date');

        $tl = $this->svc()->start($agency, now(), $owner->id);
        $this->put(route('admin.agency-timelines.start-date', $tl), ['start_date' => 'garbage'])->assertSessionHasErrors('start_date');
    }

    public function test_moving_the_start_date_far_into_the_past_needs_an_explicit_confirmation(): void
    {
        $this->seedMini();
        $owner = $this->owner();
        $this->actingAs($owner);
        $tl = $this->svc()->start($this->agency(), now(), $owner->id);
        $old = now()->subMonths(2)->toDateString();

        $this->put(route('admin.agency-timelines.start-date', $tl), ['start_date' => $old])->assertSessionHasErrors('start_date');
        $this->assertSame(now()->toDateString(), $tl->fresh()->start_date->toDateString());

        // Yesterday is fine without ceremony; further back needs the tick.
        $this->put(route('admin.agency-timelines.start-date', $tl), ['start_date' => now()->subDay()->toDateString()])->assertSessionHasNoErrors();
        $this->put(route('admin.agency-timelines.start-date', $tl), ['start_date' => $old, 'confirm_past' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($old, $tl->fresh()->start_date->toDateString());
    }

    // ── B-L9 ───────────────────────────────────────────────────────────────

    public function test_an_apostrophe_in_the_agency_name_cannot_break_the_mark_live_confirmation(): void
    {
        $this->seedMini();
        $owner = $this->owner();
        $this->actingAs($owner);
        $tl = $this->svc()->start($this->agency("O'Brien Properties"), now(), $owner->id);

        $html = $this->get(route('admin.agency-timelines.show', $tl))->assertOk()->getContent();

        // @js() emits a single-quoted JS string with the apostrophe escaped (backslash-u-0027), never a raw ' that would end the string.
        $this->assertStringContainsString('confirm(\'Mark O\\u0027Brien Properties as live?', $html);
        $this->assertStringNotContainsString("confirm('Mark O'Brien", $html);
    }

    // ── INFO ───────────────────────────────────────────────────────────────

    public function test_the_only_go_live_default_cannot_be_archived(): void
    {
        $this->seedMini();
        $this->actingAs($this->owner());
        $live = AgencyTimelineDefaultItem::where('is_go_live', true)->firstOrFail();

        $this->delete(route('admin.timeline-defaults.destroy', $live->id))->assertSessionHas('warning');

        $this->assertNull($live->fresh()->deleted_at);
    }

    public function test_reset_dates_leaves_done_and_skipped_steps_alone(): void
    {
        $this->seedMini();
        $tl = $this->svc()->start($this->agency(), Carbon::parse('2026-10-01'), null);
        $done = $this->step($tl, 'Questionnaire');
        $open = $this->step($tl, 'Sign agreement');
        $this->svc()->setStatus($done, 'done', null);
        $done->update(['due_date' => '2026-12-25']);
        $open->update(['due_date' => '2026-12-26']);

        $n = $this->svc()->resetDates($tl, null);

        $this->assertSame('2026-12-25', $done->fresh()->due_date->toDateString(), 'a done step keeps its date');
        $this->assertSame('2026-10-11', $open->fresh()->due_date->toDateString(), 'an open step is put back');
        $this->assertSame(1, $n);
    }

    public function test_the_index_can_hide_demo_and_inactive_agencies_and_labels_them(): void
    {
        $this->actingAs($this->owner());
        $real = $this->agency('Real Agency');
        $demo = $this->agency('Demo Agency');
        $demo->forceFill(['is_demo' => true])->save();
        $off = $this->agency('Dormant Agency');
        $off->forceFill(['is_active' => false])->save();

        // The layout's brand comment can name an agency, so look at the table's agency cells only.
        $rowOf = fn (string $html, string $name) => (bool) preg_match('/font-medium[^>]*>\s*' . preg_quote($name, '/') . '\b/', $html);

        $all = $this->get(route('admin.agency-timelines.index'))->assertOk()->getContent();
        $this->assertTrue($rowOf($all, 'Real Agency') && $rowOf($all, 'Demo Agency') && $rowOf($all, 'Dormant Agency'));
        $this->assertStringContainsString('>Demo<', $all);
        $this->assertStringContainsString('>Inactive<', $all);

        $hidden = $this->get(route('admin.agency-timelines.index', ['hide_demo' => 1]))->assertOk()->getContent();
        $this->assertTrue($rowOf($hidden, 'Real Agency'));
        $this->assertFalse($rowOf($hidden, 'Demo Agency'));
        $this->assertFalse($rowOf($hidden, 'Dormant Agency'));
    }
}
