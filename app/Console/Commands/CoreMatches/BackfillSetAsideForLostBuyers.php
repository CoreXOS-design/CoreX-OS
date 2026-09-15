<?php

namespace App\Console\Commands\CoreMatches;

use App\Models\Contact;
use App\Models\ContactMatch;
use Illuminate\Console\Command;

/**
 * Backfill contact_matches.set_aside_at for buyers already sitting in Lost
 * before App\Listeners\CoreMatches\SetAsideCoreMatchesOnBuyerLost existed —
 * the entire reason Lizo (contact 16361) and 85 others like him kept
 * rendering under Core Matches after being marked Lost days or weeks ago.
 *
 * Johan's ruling (via the conductor, 2026-09-15): "we will have to run a
 * backfill on live once we have the upgrade to core matches that we clear
 * the old core matches out." Accepted, expected, and runs on LIVE at
 * upgrade time — NOT on QA1, not by a lane, not without his explicit word.
 *
 * The board itself no longer depends on set_aside_at at all
 * (ContactMatchController::renderBoard() now filters directly on
 * contact.buyer_state) — this command is NOT what makes the board correct;
 * it exists purely to keep the historical record honest for whatever else
 * reads set_aside_at (the restore-on-un-Lost listener, any future
 * reporting). Running it changes nothing a user sees.
 *
 * No deletes. Only ever writes set_aside_at = now() on a row that doesn't
 * have one yet. Reversing a row is the same action a buyer's own move off
 * Lost already performs (App\Listeners\CoreMatches\
 * RestoreCoreMatchesOnBuyerRestored::restoreFromSetAside()) — this command
 * does not need a separate undo mode.
 *
 * Idempotent: only touches rows where set_aside_at IS NULL, so a second run
 * (or an overlapping one) finds nothing left to do.
 */
class BackfillSetAsideForLostBuyers extends Command
{
    protected $signature = 'core-matches:backfill-set-aside-lost-buyers {--dry-run : Report counts without writing anything}';

    protected $description = 'Set aside (never delete) matches for buyers already in Lost, so set_aside_at reflects reality for the historical backlog';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // withoutGlobalScopes() on both, as fresh TOP-LEVEL queries — never
        // inside a whereHas()/withExists()/withMax() closure. Proven
        // elsewhere in this build (.ai/specs/core-matches.md, "Screen &
        // scoping") that a scope bypass written inside one of those
        // closures does not reliably reach the compiled SQL; only a bypass
        // on a fresh top-level query()  is safe. A console command also has
        // no authenticated user/agency session, and this must run once
        // across EVERY agency's data regardless — same bypass the listener
        // this replaces already uses (SetAsideCoreMatchesOnBuyerLost::handle()).
        $lostContactIds = Contact::withoutGlobalScopes()
            ->where('buyer_state', 'lost')
            ->pluck('id');

        // withoutGlobalScopes() also lifts the SoftDeletingScope — restored
        // explicitly, since a soft-deleted match has no business being
        // touched by this backfill at all.
        $matchesQuery = ContactMatch::withoutGlobalScopes()
            ->whereIn('contact_id', $lostContactIds)
            ->whereNull('deleted_at')
            ->whereNull('set_aside_at');

        $contactIds = (clone $matchesQuery)->distinct()->pluck('contact_id');

        $matchCount = (clone $matchesQuery)->count();

        $this->info(($dry ? '[dry-run] ' : '')
            . "{$contactIds->count()} contact(s) in Lost with {$matchCount} match(es) not yet set aside.");

        if ($matchCount === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->line('Run without --dry-run to set these aside. No rows are deleted or modified beyond set_aside_at.');

            return self::SUCCESS;
        }

        $now = now();
        $updated = $matchesQuery->update(['set_aside_at' => $now]);

        $this->info("Set aside {$updated} match(es) across {$contactIds->count()} contact(s), timestamped {$now}.");
        $this->line('Reversible: clearing set_aside_at on any of these rows (or the buyer moving off Lost, which does this automatically) brings a match back.');

        return self::SUCCESS;
    }
}
