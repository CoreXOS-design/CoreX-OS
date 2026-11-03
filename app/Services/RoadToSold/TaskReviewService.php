<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 v2 phase 6 - the manager's review of captured tasks (road-to-sold.md sections 8.3, 10.2, 10.4).
 *
 *   approve   a task waiting for review (or one sent back) counts: state -> confirmed
 *   sendBack  a manager returns a task with a comment (mandatory): state -> returned, it stops counting
 *   resubmit  the agent (or their assistant) answers a sent-back task: state -> provisional, back in the manager's queue
 *
 * Every decision runs in one transaction on a row re-read under a lock (the syndication approval pattern, spec 8.3), is appended to the
 * completion's `override_audit` (never overwritten) and records who and when. Hard rules, tested:
 *   - only a CAPTURED completion can be reviewed - an automatic one is the record of something that really happened;
 *   - nobody reviews their own work (the list owner, or whoever recorded it) - the same rule as the level sign-off (spec 8.2);
 *   - the completion must be inside the reviewer's breadth (own / branch / agency, applied in the query) - outside it is a 404.
 * Points are untouched: `points_awarded` is a snapshot and counting reads only confirmed / overridden rows, so a held or returned task
 * simply does not count until it is approved.
 */
class TaskReviewService
{
    public function __construct(private readonly TaskCompletionService $engine)
    {
    }

    public function approve(User $reviewer, int $id): RtsTaskCompletion
    {
        return $this->decide($reviewer, $id, 'approved', [RtsTaskCompletion::STATE_PROVISIONAL, RtsTaskCompletion::STATE_RETURNED], function (RtsTaskCompletion $c) use ($reviewer) {
            $c->state = RtsTaskCompletion::STATE_CONFIRMED;
            $c->confirmed_by_user_id = $reviewer->id;
            $c->confirmed_at = now();
            $c->review_comment = null;
        });
    }

    public function sendBack(User $reviewer, int $id, ?string $comment): RtsTaskCompletion
    {
        $comment = trim((string) $comment);
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Write what needs to change - the agent sees this.']);
        }
        if (mb_strlen($comment) > 2000) {
            throw ValidationException::withMessages(['comment' => 'Keep the comment under 2000 characters.']);
        }

        return $this->decide($reviewer, $id, 'returned', [RtsTaskCompletion::STATE_PROVISIONAL, RtsTaskCompletion::STATE_CONFIRMED], function (RtsTaskCompletion $c) use ($comment) {
            $c->state = RtsTaskCompletion::STATE_RETURNED;
            $c->review_comment = $comment;
        }, $comment);
    }

    /** The agent answers a sent-back task (after fixing the note or photo): it goes back to the manager's queue. Own list (or their assistant's) only. */
    public function resubmit(User $viewer, int $id): RtsTaskCompletion
    {
        return DB::transaction(function () use ($viewer, $id) {
            $c = $this->engine->visibleTo($viewer)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array((int) $c->user_id, array_map('intval', $viewer->dataIdentityIds()), true), 403);
            $this->guardReviewable($c, [RtsTaskCompletion::STATE_RETURNED]);

            $from = $c->state;
            $c->state = RtsTaskCompletion::STATE_PROVISIONAL;
            $this->stamp($c, $viewer, 'resubmitted', $from, null, false);
            $c->save();

            return $c;
        });
    }

    /**
     * @param  string[]  $allowedFrom
     * @param  callable(RtsTaskCompletion):void  $apply
     */
    private function decide(User $reviewer, int $id, string $action, array $allowedFrom, callable $apply, ?string $comment = null): RtsTaskCompletion
    {
        abort_unless($reviewer->hasPermission('road_to_sold.review_completions'), 403);

        return DB::transaction(function () use ($reviewer, $id, $action, $allowedFrom, $apply, $comment) {
            $c = $this->engine->visibleTo($reviewer)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($this->isOwnWork($reviewer, $c)) {
                abort(403, 'You cannot review your own work - another manager does that.');
            }
            $this->guardReviewable($c, $allowedFrom);

            $from = $c->state;
            $apply($c);
            $this->stamp($c, $reviewer, $action, $from, $comment, true);
            $c->save();

            return $c;
        });
    }

    /** Is this the reviewer's own work? Counts for them, or recorded by them (including as the agent's assistant). */
    public function isOwnWork(User $reviewer, RtsTaskCompletion $c): bool
    {
        $mine = array_map('intval', $reviewer->dataIdentityIds());

        return in_array((int) $c->user_id, $mine, true) || (int) $c->recorded_by_user_id === (int) $reviewer->id;
    }

    /** Can this completion take a review decision from its present state? (also drives which buttons the screen draws) */
    public function reviewable(RtsTaskCompletion $c): bool
    {
        return $c->source === RtsTaskCompletion::SOURCE_CAPTURED && ! $c->trashed();
    }

    /** @param string[] $allowedFrom */
    private function guardReviewable(RtsTaskCompletion $c, array $allowedFrom): void
    {
        if (! $this->reviewable($c)) {
            throw ValidationException::withMessages(['completion' => 'Only a captured task can be reviewed - an automatic one is the record of something that really happened.']);
        }
        if (! in_array($c->state, $allowedFrom, true)) {
            throw ValidationException::withMessages(['completion' => 'That task has already moved on - refresh the list to see where it is now.']);
        }
    }

    private function stamp(RtsTaskCompletion $c, User $by, string $action, string $from, ?string $comment, bool $review): void
    {
        $audit = (array) ($c->override_audit ?? []);
        $audit[] = array_filter([
            'at' => now()->toIso8601String(), 'by' => (int) $by->id, 'action' => $action, 'from' => $from, 'to' => $c->state, 'comment' => $comment,
        ], fn ($v) => $v !== null);
        $c->override_audit = $audit;
        if ($review) {
            $c->reviewed_by_user_id = $by->id;
            $c->reviewed_at = now();
        }
    }
}
