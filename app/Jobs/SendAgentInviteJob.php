<?php

namespace App\Jobs;

use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\AgentInviteNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAgentInviteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A single invite is one password-reset token + one SMTP round trip.
     * Without an explicit timeout the job inherits the worker's 60s default,
     * which is what SIGKILLed SyncAgentToP24Job on a slow upstream and left
     * the queue stranded. 120s covers a slow SMTP handshake with headroom.
     */
    public int $timeout = 120;

    public int $tries = 3;

    /** Back off on a flapping mail host rather than burning all 3 tries at once. */
    public array $backoff = [10, 60];

    public function __construct(public int $userId) {}

    public function handle(): void
    {
        // AgencyScope is NOT inert here. On a queued worker there is no auth
        // user so it no-ops, but under QUEUE_CONNECTION=sync this runs inside
        // the owner's request — and if they have switched into an agency via
        // the switcher, a scoped find() returns null and the invite silently
        // vanishes. The importer spans agencies by design, so resolve unscoped.
        $user = User::withoutGlobalScope(AgencyScope::class)->find($this->userId);

        if (!$user) {
            Log::warning('SendAgentInviteJob: user no longer exists', ['user_id' => $this->userId]);
            return;
        }

        if (blank($user->email)) {
            Log::warning('SendAgentInviteJob: user has no email address', ['user_id' => $this->userId]);
            return;
        }

        $user->notify(AgentInviteNotification::createFor($user));

        // Stamped only after the notification is handed off. Both the bulk and
        // the per-agent path run through here, so the two can never disagree
        // about who has been invited.
        $user->forceFill(['invited_at' => now()])->saveQuietly();
    }
}
