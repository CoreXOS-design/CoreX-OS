<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\NextActionService;
use App\Services\RoadToSold\RecipeProgressService;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TaskCaptureService;
use App\Services\RoadToSold\TaskCompletionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-454 Road to Sold v2 phase 2 - the agent's "do this next" view and the capture form (road-to-sold.md sections 5.1, 5.3, 9.4, 10.1,
 * 10.4, 10.5, 11.1).
 *
 * Proven: progress states (achieved / ahead / on track / needs a push / behind / to do / none), a personal target beats the level's,
 * archived completions do not count; "do this next" rules in order; a capture makes real records (match-or-create property with an
 * area_drive source, contact found by phone before it is made, owner linked only when none) and lists them on the agent's list now and
 * on every later night until worked; the same press of Save saves once; every refusal is keyed by form field and writes nothing; an
 * assistant's capture counts under the agent; archive / restore / change the note, with own / branch / agency breadth in the query and
 * a 404 outside it; the page, its sticky header, the captures list and the error links render.
 */
final class AgentViewAndCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $manager;
    private User $agent;
    private User $agentSouth;
    private RecipeService $recipe;
    private TaskCompletionService $engine;
    private TaskCaptureService $capture;
    private RecipeProgressService $progress;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        // A Wednesday: Monday-Friday are working days, three of them have passed.
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', config('app.timezone')));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);
        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->manager = $this->mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agent = $this->mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth = $this->mk('Shawn Du Bois', 'agent', $this->south);

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_recipe', null], ['admin', 'road_to_sold.manage_settings', null],
            ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
            ['assistant', 'access_road_to_sold', null], ['assistant', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $this->recipe = app(RecipeService::class);
        $this->engine = app(TaskCompletionService::class);
        $this->capture = app(TaskCaptureService::class);
        $this->progress = app(RecipeProgressService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(string $name, string $role, Branch $b, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function assistantFor(User $agent): User
    {
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        $assistant = $this->mk('Amy Assistant', 'assistant', $this->north, ['is_assistant' => true]);
        AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->north->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $agent->id, 'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        return $assistant->fresh();
    }

    /** A captured task in Level 1 (the recipe is started on first use). */
    private function custom(string $kind = 'property', string $unit = 'week', int $target = 1, string $evidence = 'none', float $points = 5.0, string $label = 'Drive your area'): RtsTask
    {
        $this->recipe->start($this->agency->id, 'sales');
        $step = RtsStep::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'find')->first();
        $task = $this->recipe->saveTask($this->agency->id, 'sales', null, [
            'label' => $label . ' ' . (++$this->n), 'step_id' => $step->id, 'type' => 'custom', 'recurrence_unit' => $unit,
            'capture_kind' => $kind, 'evidence_required' => $evidence,
        ]);
        $level = RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 1)->first();
        RtsLevelTask::create(['agency_id' => $this->agency->id, 'level_id' => $level->id, 'task_id' => $task->id, 'target' => $target, 'points' => $points, 'required_for_signoff' => false]);

        return $task;
    }

    private function input(RtsTask $task, array $extra = []): array
    {
        return $extra + ['task_id' => $task->id, 'token' => (string) Str::uuid()];
    }

    private function house(string $street = 'Marine Drive', string $number = '12', string $suburb = 'Uvongo', array $extra = []): array
    {
        return $extra + ['street_number' => $number, 'street_name' => $street, 'suburb' => $suburb];
    }

    private function tps()
    {
        return TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agency->id);
    }

    private function done(?RtsTask $task = null)
    {
        $q = RtsTaskCompletion::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agency->id);

        return $task ? $q->where('task_id', $task->id) : $q;
    }

    /** Put $n completions of a task in the current period (distinct tokens, so none is a repeat). */
    private function complete(RtsTask $task, User $agent, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->engine->complete($task, $agent, null, ['source' => 'captured', 'token' => (string) Str::uuid()]);
        }
    }

    private function row(array $progress, RtsTask $task): array
    {
        return collect($progress['tasks'])->firstWhere('task_id', $task->id);
    }

    // ── Progress and the five states ──────────────────────────────────────

    public function test_progress_states_follow_the_agency_lines_and_the_working_days_passed(): void
    {
        $task = $this->custom('none', 'week', 10);    // Wednesday: 3 of 5 working days passed, so 6 of 10 are expected

        $state = function (int $n) use ($task) {
            $this->done($task)->forceDelete();
            $this->complete($task, $this->agent, $n);

            return $this->row($this->progress->forAgent($this->agent), $task)['state'];
        };

        $this->assertSame('behind', $state(2), '2 of the 6 expected is 33%, under the red line');
        $this->assertSame('amber', $state(5), '5 of 6 is 83%, between the red and the green line');
        $this->assertSame('on_track', $state(6));
        $this->assertSame('ahead', $state(7), '7 of 6 is 116%, over the ahead line');
        $this->assertSame('achieved', $state(10));
    }

    public function test_a_day_task_has_no_verdict_until_met_and_a_zero_target_has_none(): void
    {
        $day = $this->custom('none', 'day', 3, 'none', 1, 'Door knock');
        $zero = $this->custom('none', 'week', 0, 'none', 1, 'Optional');
        $this->complete($day, $this->agent, 1);

        $p = $this->progress->forAgent($this->agent);
        $this->assertSame('open', $this->row($p, $day)['state']);
        $this->assertSame('none', $this->row($p, $zero)['state']);

        $this->complete($day, $this->agent, 2);
        $this->assertSame('achieved', $this->row($this->progress->forAgent($this->agent), $day)['state']);
    }

    public function test_the_agencys_ahead_line_is_a_setting(): void
    {
        $task = $this->custom('none', 'week', 10);
        $this->complete($task, $this->agent, 7);   // 116% of the 6 expected
        $this->assertSame('ahead', $this->row($this->progress->forAgent($this->agent), $task)['state']);

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['summary_ahead_from_pct' => 150])->assertRedirect();
        SettingsService::forget();
        $this->assertSame(150, SettingsService::forAgency($this->agency->id, 'sales')->summaryAheadFromPct());
        $this->assertSame('on_track', $this->row($this->progress->forAgent($this->agent), $task)['state']);
    }

    public function test_a_personal_target_beats_the_levels_and_an_archived_completion_does_not_count(): void
    {
        $task = $this->custom('none', 'week', 10);
        RtsUserTaskOverride::create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'task_id' => $task->id, 'level_id' => null, 'level_key' => 0,
            'target' => 4, 'set_by_user_id' => $this->admin->id]);
        $this->complete($task, $this->agent, 3);

        $row = $this->row($this->progress->forAgent($this->agent), $task);
        $this->assertSame(4, $row['target']);
        $this->assertSame(3, $row['actual']);

        $this->done($task)->first()->delete();
        $this->assertSame(2, $this->row($this->progress->forAgent($this->agent), $task)['actual']);
    }

    public function test_reading_progress_writes_nothing_and_an_unstarted_recipe_says_so(): void
    {
        $p = $this->progress->forAgent($this->agent);
        $this->assertFalse($p['started']);
        $this->assertSame(0, RtsStep::withoutGlobalScopes()->count());
        $this->assertSame(0, RtsTask::withoutGlobalScopes()->count());
    }

    public function test_steps_roll_up_into_the_summary_bar_with_tasks_met(): void
    {
        $a = $this->custom('none', 'week', 2, 'none', 1, 'Alpha');
        $b = $this->custom('none', 'week', 4, 'none', 1, 'Beta');
        $this->complete($a, $this->agent, 2);
        $this->complete($b, $this->agent, 1);

        $step = collect($this->progress->forAgent($this->agent)['steps'])->firstWhere('key', 'find');
        $this->assertSame(1, $step['met']);
        $this->assertSame(2, $step['total']);
        $this->assertSame(63, $step['pct'], 'the mean of 100% and 25%');
    }

    // ── Do this next ──────────────────────────────────────────────────────

    public function test_do_this_next_takes_what_is_waiting_then_the_list_then_the_soonest_task_then_nothing(): void
    {
        $svc = new NextActionService();
        $weekly = ['task_id' => 1, 'label' => 'Drive your area', 'is_custom' => true, 'event_key' => null, 'target' => 3, 'actual' => 1, 'unit_label' => 'week', 'period_end' => Carbon::parse('2026-10-26')];
        $daily = ['task_id' => 2, 'label' => 'Knock doors', 'is_custom' => true, 'event_key' => null, 'target' => 5, 'actual' => 0, 'unit_label' => 'day', 'period_end' => Carbon::parse('2026-10-22')];
        $progress = ['tasks' => [$weekly, $daily]];
        $feeds = ['leads' => ['count' => 2, 'items' => [['title' => 'Mia (Uvongo flat)', 'sub' => '', 'url' => '/lead/1']]], 'buyers' => ['count' => 0, 'items' => []], 'mandates' => ['count' => 0, 'items' => []]];
        $list = ['open' => 4, 'first' => ['tracked_property_id' => 7, 'list_item_id' => 9, 'address' => '12 Marine Drive']];

        $a = $svc->resolve($progress, $feeds, $list, true);
        $this->assertSame('follow_up', $a['kind']);
        $this->assertSame('Respond to Mia (Uvongo flat)', $a['title']);
        $this->assertSame('/lead/1', $a['action']['url']);

        $b = $svc->resolve($progress, [], $list, true);
        $this->assertSame('call', $b['kind']);
        $this->assertSame('contact', $b['action']['type']);
        $this->assertSame(7, $b['action']['tp']);

        $c = $svc->resolve($progress, [], ['open' => 0, 'first' => null], true);
        $this->assertSame('task', $c['kind']);
        $this->assertSame('Knock doors', $c['title'], 'the daily task ends first');
        $this->assertSame('capture', $c['action']['type']);

        $d = $svc->resolve(['tasks' => [['target' => 3, 'actual' => 3] + $weekly]], [], ['open' => 0, 'first' => null], true);
        $this->assertSame('done', $d['kind']);
        $this->assertNull($d['action']);

        $this->assertNull($svc->resolve($progress, [], $list, false)['action'], 'someone looking at an agent sees the item but has no button');
    }

    public function test_do_this_next_never_says_done_while_a_tile_on_the_page_says_behind(): void
    {
        $svc = new NextActionService();
        $none = ['open' => 0, 'first' => null];
        $tile = fn (string $key, string $label, string $status, int $actual, ?float $should) => ['key' => $key, 'label' => $label, 'actual' => $actual, 'should_be' => $should, 'pct' => $should ? (int) round($actual / $should * 100) : null, 'status' => $status];

        // An agency that has not started the recipe has no tasks; the summary bar still has the agent's own call target.
        $bar = ['steps' => [$tile('calls_made', 'Calls made', 'red', 0, 90.0), $tile('conversations', 'Conversations', 'none', 0, null)]];
        $a = $svc->resolve(['tasks' => []], [], $none, true, $bar);
        $this->assertSame('task', $a['kind']);
        $this->assertSame('Calls made', $a['title']);
        $this->assertSame('0 so far this month, 90 should be done by today', $a['detail']);
        $this->assertNull($a['action']);

        // Red is named before amber, whatever the order of the tiles.
        $mixed = ['steps' => [$tile('conversations', 'Conversations', 'amber', 8, 10.0), $tile('calls_made', 'Calls made', 'red', 1, 90.0)]];
        $this->assertSame('Calls made', $svc->resolve(['tasks' => []], [], $none, true, $mixed)['title']);

        // On track, ahead and "no target" tiles say nothing is due.
        $fine = ['steps' => [$tile('calls_made', 'Calls made', 'green', 90, 90.0), $tile('listings_live', 'Listings live', 'none', 2, null)]];
        $this->assertSame('done', $svc->resolve(['tasks' => []], [], $none, true, $fine)['kind']);
        $this->assertSame('done', $svc->resolve(['tasks' => []], [], $none, true)['kind'], 'no bar given = the old behaviour');

        // The call-result task is unfinished and the list has nothing to call: say so (no button; the list is how it is done).
        $calls = ['task_id' => 3, 'label' => 'Record a result for each call', 'is_custom' => false, 'event_key' => \App\Events\RoadToSold\RoadToSoldOutcomeRecorded::class, 'target' => 10, 'actual' => 6, 'unit_label' => 'day', 'period_end' => Carbon::parse('2026-10-26')];
        $b = $svc->resolve(['tasks' => [$calls]], [], $none, true, $fine);
        $this->assertSame('task', $b['kind']);
        $this->assertSame('Record a result for each call', $b['title']);
        $this->assertSame('4 to go today', $b['detail']);
        $this->assertNull($b['action']);
        // ... and while the list still has something open the list wins, as before.
        $this->assertSame('call', $svc->resolve(['tasks' => [$calls]], [], ['open' => 2, 'first' => ['tracked_property_id' => 7, 'list_item_id' => 9, 'address' => '12 Marine Drive']], true, $fine)['kind']);
    }

    // ── A capture makes real records ──────────────────────────────────────

    public function test_a_property_capture_goes_through_match_or_create_and_lands_on_the_agents_list(): void
    {
        $task = $this->custom('property', 'week', 1, 'none', 5.0);

        $r = $this->capture->capture($this->agent, $this->agent, $this->input($task, [
            'area_label' => 'Uvongo', 'properties' => [$this->house(), $this->house('Beach Road', '4')],
        ]));

        $this->assertFalse($r['duplicate']);
        $this->assertSame(2, $r['properties']);
        $this->assertSame(2, $r['created']);
        $this->assertSame(2, $this->tps()->count());
        $tp = $this->tps()->where('street_name', 'Marine Drive')->first();
        $this->assertSame('area_drive', $tp->source_chain[0]['source_type'] ?? $tp->source_chain[0]['type'] ?? null, 'the source chain records where it came from');

        $c = $r['completion'];
        $this->assertSame('captured', $c->source);
        $this->assertSame($this->agent->id, (int) $c->user_id);
        $this->assertSame('5.00', (string) $c->points_awarded);
        $this->assertSame(2, $c->records()->count());
        $this->assertSame(2, $c->evidence['properties_found']);
        $this->assertSame('Uvongo', $c->evidence['area_label']);

        $this->assertSame(2, $r['listed']);
        $items = RtsListItem::withoutGlobalScopes()->where('user_id', $this->agent->id)->get();
        $this->assertSame(['found_by_you'], $items->pluck('selection')->unique()->values()->all());
        $this->assertSame(['added'], $items->pluck('origin')->unique()->values()->all(), 'over and above the day\'s target');
    }

    public function test_a_property_cortex_already_knows_is_matched_not_doubled(): void
    {
        $task = $this->custom('property');
        $this->capture->capture($this->agent, $this->agent, $this->input($task, ['properties' => [$this->house()]]));
        $this->assertSame(1, $this->tps()->count());

        $again = $this->capture->capture($this->agent, $this->agent, $this->input($task, ['properties' => [$this->house()]]));

        $this->assertSame(1, $this->tps()->count(), 'the same address on a second drive is matched');
        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['properties']);
        $this->assertSame(2, $this->done($task)->count(), 'a second drive in the same week is its own completion');
    }

    public function test_the_same_press_of_save_twice_saves_once(): void
    {
        $task = $this->custom('property');
        $input = $this->input($task, ['properties' => [$this->house()]]);

        $first = $this->capture->capture($this->agent, $this->agent, $input);
        $second = $this->capture->capture($this->agent, $this->agent, $input);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, $this->done($task)->count());
        $this->assertSame(1, $this->tps()->count());
    }

    public function test_a_property_and_its_owner_link_the_owner_only_when_there_is_none(): void
    {
        $task = $this->custom('both');

        $r = $this->capture->capture($this->agent, $this->agent, $this->input($task, [
            'properties' => [$this->house('Marine Drive', '12', 'Uvongo', ['owner_name' => 'Pieter van der Merwe', 'owner_phone' => '082 551 2345', 'owner_email' => 'pieter@example.test'])],
        ]));

        $tp = $this->tps()->first();
        $contact = Contact::withoutGlobalScopes()->find($tp->owner_contact_id);
        $this->assertNotNull($contact, 'the owner is linked');
        $this->assertSame('Pieter', $contact->first_name);
        $this->assertSame('van der Merwe', $contact->last_name);
        $this->assertSame($this->agent->id, (int) $contact->agent_id);
        $this->assertSame(1, $r['contacts']);
        $this->assertSame(2, $r['completion']->records()->count(), 'the property and the owner');
        $this->assertTrue($tp->owners()->where('contact_id', $contact->id)->exists());

        // a second drive finds a different person at the same house: the owner on file is never replaced
        $this->capture->capture($this->agent, $this->agent, $this->input($task, [
            'properties' => [$this->house('Marine Drive', '12', 'Uvongo', ['owner_name' => 'Someone Else', 'owner_phone' => '0831110000'])],
        ]));
        $this->assertSame($contact->id, (int) $tp->fresh()->owner_contact_id);
        $this->assertSame(1, $this->tps()->count());
    }

    public function test_a_person_is_found_by_phone_before_a_new_contact_is_made(): void
    {
        $task = $this->custom('contact');
        $existing = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->north->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi', 'phone' => '0825550000', 'created_by_user_id' => $this->agent->id]);
        $before = Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count();

        $r = $this->capture->capture($this->agent, $this->agent, $this->input($task, [
            'contacts' => [['name' => 'Thandi N', 'phone' => '082 555 0000'], ['name' => 'New Person', 'phone' => '0839998888', 'email' => 'new@example.test']],
        ]));

        $this->assertSame(2, $r['contacts']);
        $this->assertSame($before + 1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'only the new person was made');
        $ids = $r['completion']->records()->pluck('record_id')->all();
        $this->assertContains($existing->id, $ids);
    }

    public function test_a_notes_only_task_counts_how_many_and_needs_what_the_agency_asks_for(): void
    {
        $task = $this->custom('none', 'day', 3, 'note', 2.0, 'Sales meeting');

        $r = $this->capture->capture($this->agent, $this->agent, $this->input($task, ['note' => 'Attended the Monday meeting', 'quantity' => 2]));
        $this->assertSame(2, (int) $r['completion']->quantity);
        $this->assertSame('4.00', (string) $r['completion']->points_awarded);
        $this->assertSame('Attended the Monday meeting', $r['completion']->evidence_note);

        $this->expectField('note', fn () => $this->capture->capture($this->agent, $this->agent, $this->input($task, ['note' => ''])));
    }

    public function test_a_photo_can_stand_in_for_a_note_and_is_kept_privately(): void
    {
        Storage::fake('local');
        $task = $this->custom('none', 'week', 1, 'note_or_photo');

        $r = $this->capture->capture($this->agent, $this->agent, $this->input($task, ['photos' => [UploadedFile::fake()->image('door.jpg')]]));

        $paths = $r['completion']->evidence['photos'];
        $this->assertCount(1, $paths);
        $this->assertStringStartsWith('rts-evidence/' . $this->agency->id . '/' . $this->agent->id . '/', $paths[0]);
        Storage::disk('local')->assertExists($paths[0]);
    }

    // ── Refusals are keyed by field and write nothing ─────────────────────

    private function expectField(string $field, callable $do): void
    {
        try {
            $do();
            $this->fail("Expected a refusal on {$field}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'refused on ' . $field . ' but on ' . implode(', ', array_keys($e->errors())));
        }
    }

    public function test_every_refusal_names_the_field_and_saves_nothing(): void
    {
        $task = $this->custom('both', 'week', 1, 'none');
        $go = fn (array $extra) => fn () => $this->capture->capture($this->agent, $this->agent, $this->input($task, $extra));

        $this->expectField('properties.0.street_name', $go([]));
        $this->expectField('properties.0.street_name', $go(['properties' => [['suburb' => 'Uvongo']]]));
        $this->expectField('properties.0.owner_name', $go(['properties' => [$this->house('A Road', '1', 'X', ['owner_phone' => '0820000000'])]]));
        $this->expectField('properties.0.owner_email', $go(['properties' => [$this->house('A Road', '1', 'X', ['owner_name' => 'Pat', 'owner_email' => 'not-an-email'])]]));
        $this->expectField('occurred_at', $go(['properties' => [$this->house()], 'occurred_at' => '2026-10-22T10:00']));
        $this->expectField('occurred_at', $go(['properties' => [$this->house()], 'occurred_at' => '2026-10-10T10:00']));
        $this->expectField('occurred_at', $go(['properties' => [$this->house()], 'occurred_at' => 'garbage']));

        $this->assertSame(0, $this->done()->count());
        $this->assertSame(0, $this->tps()->count());
        $this->assertSame(0, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('notes', 'like', 'Found on:%')->count());
    }

    public function test_back_dating_is_limited_by_the_agencys_setting(): void
    {
        // "Now" is Wednesday 21 Oct 2026. Working days back from it (Mon-Fri, public holidays skipped): Tue 20 = 1, Mon 19 = 2, Fri 16 = 3, Thu 15 = 4, Wed 14 = 5.
        $task = $this->custom('none', 'week', 5);
        $fridayLastWeek = '2026-10-16T09:00';
        $this->assertFalse($this->capture->capture($this->agent, $this->agent, $this->input($task, ['occurred_at' => $fridayLastWeek]))['duplicate']);

        $beyondFive = '2026-10-13T23:00';   // the sixth working day back
        $this->expectField('occurred_at', fn () => $this->capture->capture($this->agent, $this->agent, $this->input($task, ['occurred_at' => $beyondFive])));

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['capture_back_date_working_days' => 10])->assertRedirect();
        SettingsService::forget();
        $this->assertFalse($this->capture->capture($this->agent, $this->agent, $this->input($task, ['occurred_at' => $beyondFive]))['duplicate']);
    }

    public function test_a_task_that_is_not_in_the_agents_recipe_is_refused(): void
    {
        $task = $this->custom('none');
        $this->expectField('task_id', fn () => $this->capture->capture($this->agent, $this->agent, ['task_id' => 999999, 'token' => 'x']));

        RtsLevelTask::withoutGlobalScopes()->where('task_id', $task->id)->delete();   // out of Level 1
        $this->expectField('task_id', fn () => $this->capture->capture($this->agent, $this->agent, $this->input($task)));

        $core = RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('type', 'core')->first();
        $this->expectField('task_id', fn () => $this->capture->capture($this->agent, $this->agent, $this->input($core)), );
    }

    // ── Assistants, the next night, second agency ─────────────────────────

    public function test_an_assistants_capture_counts_under_the_agent_and_records_who_did_it(): void
    {
        $task = $this->custom('none');
        $assistant = $this->assistantFor($this->agent);

        $this->assertSame($this->agent->id, (int) $assistant->ownershipUserId(), 'the controller captures for the list owner, never an id from the request');
        $this->capture->capture($assistant, $this->agent, $this->input($task, ['note' => 'x']));

        $row = $this->done($task)->first();
        $this->assertSame($this->agent->id, (int) $row->user_id);
        $this->assertSame($assistant->id, (int) $row->recorded_by_user_id);
        $this->assertSame('assistant', $row->recorded_by_kind);
    }

    public function test_what_a_drive_found_comes_back_on_every_working_day_until_it_is_worked(): void
    {
        $task = $this->custom('property');
        $this->capture->capture($this->agent, $this->agent, $this->input($task, ['properties' => [$this->house()]]));
        $tp = $this->tps()->first();
        $this->assertSame([$tp->id], app(ListBuilderService::class)->foundTrackedIds($this->agent, $this->agency->id, 'sales'));

        $next = app(ListBuilderService::class)->buildFor($this->agent, '2026-10-22', 'sales');
        $this->assertSame([$tp->id], $next->where('selection', 'found_by_you')->pluck('tracked_property_id')->all());
        $this->assertSame('auto', $next->firstWhere('selection', 'found_by_you')->origin, 'on the night list it counts toward the target');

        // once a result is recorded for it, it no longer comes back
        \Illuminate\Support\Facades\DB::table('rts_contact_outcomes')->insert([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'user_id' => $this->agent->id, 'tracked_property_id' => $tp->id,
            'outcome_key' => 'spoke', 'outcome_label' => 'Spoke', 'kind' => 'conversation', 'next_action' => 'none',
            'occurred_at' => now(), 'is_current' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame([], app(ListBuilderService::class)->foundTrackedIds($this->agent, $this->agency->id, 'sales'));
    }

    public function test_another_agencys_agent_cannot_capture_for_this_recipe(): void
    {
        $task = $this->custom('none');
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . Str::random(6)]);
        $b = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $stranger = $this->mk('Sam Stranger', 'agent', $b);

        $this->expectField('task_id', fn () => $this->capture->capture($stranger, $stranger, $this->input($task)));
    }

    // ── Archive, restore, change the note: breadth in the query ───────────

    private function captured(User $agent): RtsTaskCompletion
    {
        $task = $this->custom('none', 'week', 5);

        return $this->capture->capture($agent, $agent, $this->input($task, ['note' => 'first']))['completion'];
    }

    public function test_archive_takes_it_out_of_progress_and_restore_puts_it_back(): void
    {
        $c = $this->captured($this->agent);
        $task = RtsTask::withoutGlobalScopes()->find($c->task_id);

        $this->actingAs($this->agent)->delete(route('road-to-sold.captures.archive', $c->id))->assertRedirect();
        $this->assertTrue(RtsTaskCompletion::withoutGlobalScopes()->withTrashed()->find($c->id)->trashed(), 'soft delete, never a hard delete');
        $this->assertSame(0, $this->row($this->progress->forAgent($this->agent), $task)['actual']);

        $this->actingAs($this->agent)->post(route('road-to-sold.captures.restore', $c->id))->assertRedirect();
        $this->assertSame(1, $this->row($this->progress->forAgent($this->agent), $task)['actual']);
    }

    public function test_breadth_is_applied_in_the_query_own_branch_agency(): void
    {
        $mine = $this->captured($this->agent);
        $south = $this->captured($this->agentSouth);

        // an agent cannot touch a colleague's capture, by direct URL
        $this->actingAs($this->agent)->delete(route('road-to-sold.captures.archive', $south->id))->assertNotFound();
        $this->actingAs($this->agentSouth)->delete(route('road-to-sold.captures.archive', $mine->id))->assertNotFound();
        // a branch manager reaches their own branch only
        $this->actingAs($this->manager)->put(route('road-to-sold.captures.update', $mine->id), ['note' => 'coached'])->assertRedirect();
        $this->actingAs($this->manager)->put(route('road-to-sold.captures.update', $south->id), ['note' => 'nope'])->assertNotFound();
        // an admin reaches the agency
        $this->actingAs($this->admin)->put(route('road-to-sold.captures.update', $south->id), ['note' => 'seen'])->assertRedirect();

        $this->assertSame('coached', $mine->fresh()->evidence_note);
        $this->assertSame('seen', RtsTaskCompletion::withoutGlobalScopes()->find($south->id)->evidence_note);

    }

    public function test_another_agencys_admin_gets_a_404_for_the_id(): void
    {
        $mine = $this->captured($this->agent);

        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . Str::random(6)]);
        $b = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $outsider = $this->mk('Olive Owner', 'admin', $b);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_road_to_sold', 'scope' => null, 'agency_id' => $other->id]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'road_to_sold.view', 'scope' => 'all', 'agency_id' => $other->id]);
        PermissionService::clearCache();
        $this->actingAs($outsider)->delete(route('road-to-sold.captures.archive', $mine->id))->assertNotFound();
    }

    public function test_an_automatic_completion_cannot_be_archived_by_hand_and_a_required_note_cannot_be_emptied(): void
    {
        $this->recipe->start($this->agency->id, 'sales');
        $core = RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'call-outcome')->first();
        $auto = $this->engine->complete($core, $this->agent, ['X', 1], ['source' => 'auto_event', 'event_id' => (string) Str::uuid()]);
        $this->actingAs($this->agent)->delete(route('road-to-sold.captures.archive', $auto->id))->assertForbidden();

        $task = $this->custom('none', 'week', 1, 'note');
        $c = $this->capture->capture($this->agent, $this->agent, $this->input($task, ['note' => 'something']))['completion'];
        $this->actingAs($this->agent)->put(route('road-to-sold.captures.update', $c->id), ['note' => ''])->assertSessionHasErrors('note');
        $this->assertSame('something', $c->fresh()->evidence_note);
    }

    // ── The page ──────────────────────────────────────────────────────────

    public function test_the_page_leads_with_the_next_action_and_shows_the_header_status_the_tasks_and_the_step_tile(): void
    {
        $task = $this->custom('property', 'week', 2, 'none', 5.0, 'Drive your area');
        $this->complete($task, $this->agent, 1);

        // v3: the task progress table lives on the "My progress" block; the header, next action and step tile stay on every block
        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'progress']));

        $res->assertOk();
        $res->assertSee('rts-sticky', false);
        $res->assertSee('Level 1');
        $res->assertSee('targets met');
        $res->assertSee('points in');
        $res->assertSee($task->label);
        $res->assertSee('1 of 2');
        $res->assertSee('1 to go this week', false);
        $res->assertSee('Capture');
        $res->assertSee('Report');
    }

    public function test_the_page_names_a_missing_recipe_and_links_to_where_to_start_it_for_those_who_can(): void
    {
        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'progress']))->assertOk()->assertSee('No recipe is set up for this agency yet.')->assertDontSee('Start the recipe');
        $this->actingAs($this->admin)->get(route('road-to-sold.index', ['agent' => $this->agent->id, 'block' => 'progress']))->assertOk()
            ->assertSee('No recipe is set up for this agency yet.')->assertSee('Start the recipe')->assertSee(route('road-to-sold.settings.recipe.index'), false);
    }

    public function test_a_manager_sees_the_agents_progress_but_gets_no_capture_button(): void
    {
        $task = $this->custom('property', 'week', 2, 'none', 5.0, 'Drive your area');
        $this->complete($task, $this->agent, 1);

        $res = $this->actingAs($this->manager)->get(route('road-to-sold.index', ['agent' => $this->agent->id, 'block' => 'progress']));

        $res->assertOk()->assertSee($task->label);
        $res->assertDontSee('@click="openCapture(', false);
        $this->actingAs($this->manager)->get(route('road-to-sold.index', ['agent' => $this->agentSouth->id]))->assertNotFound();
    }

    public function test_the_captures_list_searches_sorts_filters_pages_and_has_an_empty_state(): void
    {
        $a = $this->custom('none', 'week', 5, 'none', 1, 'Alpha walk');
        $b = $this->custom('none', 'week', 5, 'none', 3, 'Beta walk');
        $this->capture->capture($this->agent, $this->agent, $this->input($a, ['note' => 'saw the Smiths']));
        $this->capture->capture($this->agent, $this->agent, $this->input($b, ['note' => 'quiet street']));

        $get = fn (array $q) => $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'captures'] + $q));
        $tasks = fn ($res) => collect($res->viewData('captures')->items())->pluck('task_id')->all();

        $res = $get([]);
        $res->assertOk()->assertSee('Edit note');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $tasks($res));
        $this->assertSame([$a->id], $tasks($get(['q' => 'Smiths'])), 'search reaches the note');
        $this->assertSame([$b->id], $tasks($get(['q' => $b->label])), 'and the task name');
        $this->assertSame([$b->id], $tasks($get(['task' => $b->id])));
        $this->assertSame([$b->id, $a->id], $tasks($get(['sort' => 'points', 'dir' => 'desc'])));
        $this->assertSame([$a->id, $b->id], $tasks($get(['sort' => 'task', 'dir' => 'asc'])));
        $this->assertSame([], $tasks($get(['q' => 'nothing like this'])));
        $get(['q' => 'nothing like this'])->assertSee('Nothing matches this search.');
        $get(['from' => '2026-12-01'])->assertSee('Nothing matches this search.');
        $this->assertSame([], $tasks($get(['status' => 'archived'])));

        $this->done($a)->first()->delete();
        $this->assertSame([$a->id], $tasks($get(['status' => 'archived'])));
        $get(['status' => 'archived'])->assertSee('Restore');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $tasks($get(['status' => 'all'])));
        $this->assertSame([$b->id], $tasks($get([])));
    }

    public function test_the_captures_list_is_empty_with_a_plain_message_and_only_shows_the_agents_own(): void
    {
        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'captures']))->assertOk()->assertSee('Nothing has been recorded yet.');

        $this->captured($this->agentSouth);
        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'captures']));
        $this->assertSame([], $res->viewData('captures')->items(), 'a colleague\'s captures are not in the agent\'s list');
        $res->assertSee('Nothing has been recorded yet.');
        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'captures', 'agent' => $this->agentSouth->id]))->assertNotFound();
    }

    public function test_a_refused_capture_links_each_message_to_the_field_to_fix(): void
    {
        $task = $this->custom('both');

        $res = $this->actingAs($this->agent)->from(route('road-to-sold.index'))->followingRedirects()
            ->post(route('road-to-sold.captures.store'), $this->input($task, ['properties' => [['suburb' => 'Uvongo', 'owner_phone' => '0820000000']]]));

        $res->assertOk();
        $res->assertSee('#cap-properties-0-street_name', false);
        $res->assertSee('focusCapture(', false);
        $res->assertSee('id="cap-note"', false);
        $this->assertSame(0, $this->done()->count());
    }

    public function test_a_good_capture_over_http_says_what_it_made(): void
    {
        $task = $this->custom('property');

        $res = $this->actingAs($this->agent)->from(route('road-to-sold.index'))->followingRedirects()
            ->post(route('road-to-sold.captures.store'), $this->input($task, ['properties' => [$this->house()]]));

        $res->assertOk()->assertSee('Saved: 1 property (1 new) recorded.')->assertSee('1 added to your list.');
    }

}
