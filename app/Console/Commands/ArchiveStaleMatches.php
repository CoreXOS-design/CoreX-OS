<?php

namespace App\Console\Commands;

use App\Models\ContactMatch;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveStaleMatches extends Command
{
    protected $signature = 'corex:matches:archive-stale {--days=90 : Idle threshold}';
    protected $description = 'Expire matches that have not engaged for N days, mark fulfilled when contact has a recent deal.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        // 1. Expire matches with no engagement in N days
        $expired = ContactMatch::withoutGlobalScope(AgencyScope::class)
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_engaged_at')->where('created_at', '<', $cutoff)
                  ->orWhere('last_engaged_at', '<', $cutoff);
            })
            ->update(['status' => ContactMatch::STATUS_EXPIRED]);

        // 2. Mark fulfilled where contact has a deal in the last 60 days
        $fulfilled = 0;
        if (\Schema::hasTable('deals')) {
            $hasContactCol = \Schema::hasColumn('deals', 'buyer_email');
            // best-effort: we don't have a single FK field — keep simple with email match
            if ($hasContactCol) {
                $fulfilled = DB::update("
                    UPDATE contact_matches cm
                    INNER JOIN contacts c ON c.id = cm.contact_id
                    INNER JOIN deals d ON d.buyer_email = c.email
                    SET cm.status = ?
                    WHERE cm.status = ?
                      AND d.created_at >= ?
                ", [ContactMatch::STATUS_FULFILLED, ContactMatch::STATUS_ACTIVE, now()->subDays(60)]);
            }
        }

        $this->info("Archived stale matches: expired={$expired}, fulfilled={$fulfilled}");
        return self::SUCCESS;
    }
}
