<?php

namespace App\Jobs\Demo;

use App\Services\Demo\DemoControlClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Forwards one demo page view from the demo host to primary.
 *
 * Spec: .ai/specs/demo-access-control.md §6.4
 *
 * ══ FAILS OPEN ══
 *
 * A dropped page view is a lost data point. A retry storm against a struggling
 * primary — from a host serving a live sales demo — is an outage. So:
 *
 *   $tries = 1     one attempt, no retries
 *   $timeout = 20  never outlive the worker's patience
 *   handle() swallows everything and logs at debug
 *
 * DemoControlClient already never throws, so handle() is belt-and-braces; the
 * try/catch is there so that a future change to the client cannot silently turn
 * telemetry into a failing-job alert storm.
 *
 * NO $queue property — the CoreX workers (corex-worker-live, hfc-staging-queue)
 * run `queue:work` with no --queue flag and drain ONLY `default`. A job pinned to
 * a named queue would sit unprocessed forever.
 */
class FlushDemoPageViewJob implements ShouldQueue
{
    use Queueable;

    /** One attempt. Telemetry is not worth retrying at a prospect's expense. */
    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public readonly string $sessionToken,
        public readonly string $path,
        public readonly ?string $routeName = null,
        public readonly ?string $title = null,
    ) {
    }

    public function handle(DemoControlClient $client): void
    {
        try {
            $res = $client->pageView(
                sessionToken: $this->sessionToken,
                path:         $this->path,
                routeName:    $this->routeName,
                title:        $this->title,
            );

            if (! $res['success']) {
                // debug, not warning: primary being briefly unreachable is not an
                // incident from telemetry's point of view, and paging someone about
                // a lost page view trains them to ignore the channel.
                Log::debug('[demo-access] Page view not delivered to primary.', [
                    'path'    => $this->path,
                    'message' => $res['message'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('[demo-access] Page view flush threw; dropping it.', [
                'path'  => $this->path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Even a hard failure is swallowed. Telemetry must never light up
     * failed_jobs and make a lost page view look like a production incident.
     */
    public function failed(\Throwable $e): void
    {
        Log::debug('[demo-access] Page view job failed; dropped by design.', [
            'path'  => $this->path,
            'error' => $e->getMessage(),
        ]);
    }
}
