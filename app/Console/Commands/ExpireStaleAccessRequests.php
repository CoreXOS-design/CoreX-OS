<?php

namespace App\Console\Commands;

use App\Models\AgencyAccessRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Expires stale Agency Access Requests:
 *  - pending requests past their `expires_at` → status=expired
 *  - approved requests past their `granted_session_expires_at`
 *    are NOT mutated here (the session check on next request
 *    handles revocation); we just emit an audit line.
 *
 * See .ai/specs/agency-access-authorization-spec.md.
 */
class ExpireStaleAccessRequests extends Command
{
    protected $signature   = 'agency-access:expire';
    protected $description = 'Mark pending agency access requests as expired once their TTL has passed.';

    public function handle(): int
    {
        $expiredCount = 0;

        AgencyAccessRequest::query()
            ->where('status', AgencyAccessRequest::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$expiredCount) {
                foreach ($chunk as $req) {
                    $req->markExpired();
                    Log::info('agency_access_expired', ['request_id' => $req->id]);
                    $expiredCount++;
                }
            });

        $this->info("Expired {$expiredCount} stale request(s).");
        return self::SUCCESS;
    }
}
