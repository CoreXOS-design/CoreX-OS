<?php

namespace App\Observers;

use App\Events\Website\AgentVisibilityChanged;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Agency Public API — emit agent.* webhooks when an agent's website presence
 * changes. Guarded (only fires on a real transition or a public-profile change
 * of a visible agent) and failure-isolated so it never breaks a user save.
 *
 * Spec: .ai/specs/agency-public-api.md §6.1, §2 (layer 3).
 */
class UserObserver
{
    /** Public-profile fields exposed by the website AgentResource. */
    private const PROFILE_FIELDS = ['name', 'email', 'phone', 'cell', 'agent_photo_path'];

    public function created(User $user): void
    {
        try {
            if ($user->show_on_website) {
                event(new AgentVisibilityChanged($user, 'published'));
            }
        } catch (\Throwable $e) {
            Log::warning("Agent website webhook (create) failed for user #{$user->id}: {$e->getMessage()}");
        }
    }

    public function updated(User $user): void
    {
        // AT-366 (Q4 enabler) — record every branch move with its date so the
        // Performance & ROI report can attribute activity to the branch the agent
        // was in AT THE TIME. Runs FIRST and isolated so it fires even when the
        // same save also flips show_on_website (which returns early below), and
        // never breaks a user save.
        if ($user->wasChanged('branch_id')) {
            try {
                \App\Models\UserBranchHistory::create([
                    'user_id'          => $user->id,
                    'agency_id'        => $user->agency_id,
                    'from_branch_id'   => $user->getOriginal('branch_id'),
                    'to_branch_id'     => $user->branch_id,
                    'moved_at'         => now(),
                    'moved_by_user_id' => auth()->id(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("AT-366 branch-move history failed for user #{$user->id}: {$e->getMessage()}");
            }
        }

        try {
            // show_on_website flipped → published / removed.
            if ($user->wasChanged('show_on_website')) {
                event(new AgentVisibilityChanged($user, $user->show_on_website ? 'published' : 'removed'));
                return;
            }

            // A visible agent's public profile changed → updated.
            if ($user->show_on_website && $user->wasChanged(self::PROFILE_FIELDS)) {
                event(new AgentVisibilityChanged($user, 'updated'));
            }
        } catch (\Throwable $e) {
            Log::warning("Agent website webhook (update) failed for user #{$user->id}: {$e->getMessage()}");
        }
    }

    public function deleted(User $user): void
    {
        try {
            if ($user->show_on_website) {
                event(new AgentVisibilityChanged($user, 'removed'));
            }
        } catch (\Throwable $e) {
            Log::warning("Agent website webhook (delete) failed for user #{$user->id}: {$e->getMessage()}");
        }
    }
}
