<?php

namespace App\Services\RoadToSold;

use App\Models\CommandCenter\CommandTask;
use App\Models\RoadToSold\RtsManagerAction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 v2 phase 5 - what a manager does about a training flag (road-to-sold.md section 9.3): assign the agency's training for that skill
 * (a task for the agent and a notification), leave a coaching note, or acknowledge the flag. Every action is one append-only row in
 * rts_manager_actions. A flag itself is stored nowhere - it is derived on every view - so "open" or "acknowledged" is read from this
 * history: a flag is acknowledged when the manager has acted on that ratio since the start of the window being looked at.
 *
 * Rules, all tested: the viewer needs `road_to_sold.view_funnel`; the agent must be inside their breadth (outside it is a 404); nobody
 * acts on their own flags; assigning training needs the agency to have chosen training for that ratio.
 */
class ManagerActionService
{
    public const NOTE_MAX = 2000;

    public function __construct(private readonly TrainingPointerService $pointers)
    {
    }

    public function assignTraining(User $by, User $agent, string $ratioKey, string $track = 'sales'): RtsManagerAction
    {
        $this->guard($by, $agent, $ratioKey);
        $pointer = $this->pointers->forAgency((int) $agent->agency_id, $track)->get($ratioKey);
        $target = $pointer ? $this->pointers->resolve($pointer) : null;
        if (! $target) {
            throw ValidationException::withMessages(['training' => 'Your agency has not chosen training for this yet - choose it under Settings, Road to Sold, Training & flags.']);
        }
        $skill = FunnelService::RATIOS[$ratioKey][4];

        $action = DB::transaction(function () use ($by, $agent, $ratioKey, $track, $target, $skill) {
            $task = CommandTask::create([
                'title' => 'Training: ' . $target['label'], 'description' => 'Your manager asked you to work on ' . $skill . '. Start here: ' . url($target['url']),
                'task_type' => 'custom', 'status' => CommandTask::STATUS_TODO, 'priority' => 'normal', 'assigned_to' => $agent->id, 'assigned_by' => $by->id,
                'due_date' => now()->addWeek(), 'source_type' => 'road_to_sold_training', 'branch_id' => $agent->branch_id, 'agency_id' => $agent->agency_id,
            ]);

            return $this->record($by, $agent, $track, RtsManagerAction::TRAINING_ASSIGNED, $ratioKey, $target['label'], $task->id);
        });

        try {
            app(\App\Services\CommandCenter\NotificationDispatcher::class)->fire($agent, 'rts.training_assigned', $action, [
                'title' => 'Training assigned: ' . $target['label'], 'body' => 'Your manager asked you to work on ' . $skill . '. It is on your task list.',
                'action_url' => $target['url'], 'severity' => 'info', 'subject_label' => 'Road to Sold training', 'threshold_hit_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[rts] training notification failed', ['agent' => $agent->id, 'error' => $e->getMessage()]);
        }

        return $action;
    }

    public function coachingNote(User $by, User $agent, string $ratioKey, ?string $note, string $track = 'sales'): RtsManagerAction
    {
        $this->guard($by, $agent, $ratioKey);
        $note = trim((string) $note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Write the note first.']);
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => 'Keep the note under ' . self::NOTE_MAX . ' characters.']);
        }

        return $this->record($by, $agent, $track, RtsManagerAction::COACHING_NOTE, $ratioKey, $note);
    }

    public function acknowledge(User $by, User $agent, string $ratioKey, string $track = 'sales'): RtsManagerAction
    {
        $this->guard($by, $agent, $ratioKey);

        return $this->record($by, $agent, $track, RtsManagerAction::FLAG_ACKNOWLEDGED, $ratioKey, null);
    }

    /** The target suggestion a manager chose to leave alone. Hidden until the suggested figure changes. */
    public function ignoreSuggestion(User $by, User $agent, int $taskId, int $suggested, string $track = 'sales'): RtsManagerAction
    {
        abort_unless($by->hasPermission('road_to_sold.view_funnel'), 403);
        abort_unless(VisibleAgents::canSee($by, (int) $agent->id), 404);

        return RtsManagerAction::withoutAgencyStamping(fn () => RtsManagerAction::create([
            'agency_id' => $agent->agency_id, 'branch_id' => $agent->branch_id, 'track' => $track, 'user_id' => $agent->id, 'by_user_id' => $by->id,
            'kind' => RtsManagerAction::SUGGESTION_IGNORED, 'subject_key' => (string) $taskId, 'value_to' => $suggested, 'acted_at' => now(),
        ]));
    }

    /**
     * The actions taken per (agent, ratio) since $since: agent id => ratio key => latest action. Used to tell an open flag from one a
     * manager has already acted on.
     *
     * @param  int[]  $agentIds
     * @return array<int,array<string,RtsManagerAction>>
     */
    public function actedSince(int $agencyId, array $agentIds, string $track, \Carbon\CarbonInterface $since): array
    {
        $out = [];
        $rows = RtsManagerAction::query()->withoutGlobalScopes()->with('by:id,name')
            ->where('agency_id', $agencyId)->where('track', $track)->whereIn('user_id', $agentIds)
            ->whereIn('kind', [RtsManagerAction::TRAINING_ASSIGNED, RtsManagerAction::COACHING_NOTE, RtsManagerAction::FLAG_ACKNOWLEDGED])
            ->where('acted_at', '>=', $since)->orderBy('id')->get();
        foreach ($rows as $r) {
            $out[(int) $r->user_id][$r->subject_key] = $r;   // the latest wins
        }

        return $out;
    }

    /** The history of what was done for one agent, newest first. @return Collection<int,RtsManagerAction> */
    public function historyFor(User $viewer, int $agentId, int $limit = 50): Collection
    {
        return RtsManagerAction::query()->visibleTo($viewer)->with('by:id,name')->where('user_id', $agentId)->orderByDesc('id')->limit($limit)->get();
    }

    private function guard(User $by, User $agent, string $ratioKey): void
    {
        abort_unless($by->hasPermission('road_to_sold.view_funnel'), 403);
        abort_unless(VisibleAgents::canSee($by, (int) $agent->id), 404);
        abort_if(in_array((int) $agent->id, array_map('intval', $by->dataIdentityIds()), true), 403, 'Another manager works on your own flags.');
        if (! isset(FunnelService::RATIOS[$ratioKey])) {
            throw ValidationException::withMessages(['ratio_key' => 'Choose one of the funnel ratios.']);
        }
    }

    private function record(User $by, User $agent, string $track, string $kind, string $ratioKey, ?string $note, ?int $taskId = null): RtsManagerAction
    {
        return RtsManagerAction::withoutAgencyStamping(fn () => RtsManagerAction::create([
            'agency_id' => $agent->agency_id, 'branch_id' => $agent->branch_id, 'track' => $track, 'user_id' => $agent->id, 'by_user_id' => $by->id,
            'kind' => $kind, 'subject_key' => $ratioKey, 'note' => $note, 'command_task_id' => $taskId, 'acted_at' => now(),
        ]));
    }
}
