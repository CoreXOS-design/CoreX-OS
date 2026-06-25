<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Push a CoreX user to Property24 (register / update agent details + photo)
 * off the web request.
 *
 * Why a job: the P24 agent sync makes several synchronous outbound HTTP
 * round-trips — getAgents, create/update agent, and a profile-photo download
 * + upload. Run inline behind a full-page form POST, that chain blocks the
 * request for as long as P24 (and the photo fetch) take, so the page "hangs".
 * Worse, locally the photo fetch falls back to GET-ting config('app.url')
 * /storage/... — a call straight back into the single-threaded `php artisan
 * serve` that is busy handling this very request, which self-deadlocks until
 * the timeout and leaves the photo bytes empty (so the photo never reaches
 * P24). Running on a worker process removes both problems: the request
 * returns instantly, and the photo fetch hits a free dev server.
 *
 * Dispatched on the default queue (non-negotiable: the live/staging workers
 * only drain `default`; named queues are stranded).
 */
class SyncAgentToP24Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int  $userId           CoreX user to sync.
     * @param bool $registerIfMissing When true (explicit "Sync to P24" / test-agent
     *                                creation) register the agent on P24 if not found.
     *                                When false (incidental edits/role/toggle/delete)
     *                                only push updates for agents already on P24 — never
     *                                auto-register someone who was never deliberately synced.
     */
    public function __construct(
        public int $userId,
        public bool $registerIfMissing = false,
    ) {}

    public function handle(Property24SyndicationService $p24): void
    {
        // withTrashed: the delete flow dispatches this before the soft-delete
        // commits behind the worker, and we still need the record to push the
        // "Inactive" state to P24.
        $user = User::withTrashed()->find($this->userId);
        if (!$user) {
            Log::channel('property24')->warning("SyncAgentToP24Job: user #{$this->userId} not found");
            return;
        }

        try {
            if (!$this->registerIfMissing && $p24->getP24AgentId($user) === null) {
                Log::channel('property24')->info("SyncAgentToP24Job: user #{$user->id} not on P24 and registerIfMissing=false — skipping");
                return;
            }

            // updateAgentOnP24 registers first when the agent isn't on P24 yet,
            // otherwise PUT-updates; both branches upload the photo.
            $result = $p24->updateAgentOnP24($user, pushPhoto: true);

            // Refresh the cached agent map so the Users page badge reflects the
            // new/updated P24 agent ID on next load.
            Cache::forget('p24:agent-map:by-source-ref');

            if ($result === true) {
                Log::channel('property24')->info("SyncAgentToP24Job: user #{$user->id} synced to P24");
            } else {
                Log::channel('property24')->error("SyncAgentToP24Job: user #{$user->id} sync failed", [
                    'message' => is_string($result) ? $result : 'unknown',
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('property24')->error("SyncAgentToP24Job: user #{$user->id} sync error", [
                'error' => $e->getMessage(),
            ]);
            throw $e; // let the queue retry/fail-log it
        }
    }
}
