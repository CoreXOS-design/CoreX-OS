<?php

namespace App\Console\Commands\Matches;

use App\Mail\Matches\MatchDigestMail;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\ContactMatchNotification;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Core Matches — the single daily digest email.
 *
 * Sweeps the contact_match_notifications ledger for rows not yet emailed
 * (emailed_at IS NULL), groups them per agent (and per contact within each
 * agent), and sends ONE email carrying every new match. Replaces the per-match
 * NewPropertyMatchNotification email that flooded inboxes — the bell (database
 * channel) still fires in real time; only the email is coalesced.
 *
 * Guarantees (mirrors the calendar-digest discipline):
 *   - AT MOST ONE match email per agent per run, never one per property.
 *   - Off-market properties are re-checked at send time and excluded — a match
 *     surfaced yesterday whose listing was withdrawn/sold/let-out overnight is
 *     dropped from the email (isMatchableStatus, the same predicate the matcher
 *     uses), so a stale match can never reach the inbox.
 *   - Every swept row is stamped emailed_at regardless of whether it made the
 *     email, so nothing lingers to be re-sent tomorrow.
 *   - Agents with match email turned off still get the real-time bell; their
 *     rows are stamped (no email) so the queue stays drained.
 */
class SendMatchDigests extends Command
{
    protected $signature = 'corex:matches:send-digests {--dry : Print without sending or stamping}';
    protected $description = 'Send the single daily Core Matches digest email to each agent';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        // Distinct agents with un-emailed matches. Console has no Auth::user(),
        // so bypass the agency scope and resolve tenancy off each row explicitly.
        $userIds = ContactMatchNotification::withoutGlobalScopes()
            ->whereNull('emailed_at')
            ->distinct()
            ->pluck('notified_user_id')
            ->filter()
            ->values();

        if ($userIds->isEmpty()) {
            $this->info('No pending matches. Nothing to digest.');
            return self::SUCCESS;
        }

        $users = User::withoutGlobalScopes()
            ->whereIn('id', $userIds->all())
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $sent = 0;
        $emptyOrOff = 0;
        $stampedRows = 0;

        foreach ($userIds as $userId) {
            $rows = ContactMatchNotification::withoutGlobalScopes()
                ->whereNull('emailed_at')
                ->where('notified_user_id', $userId)
                ->orderByDesc('score')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $user = $users->get($userId);

            // Inactive / deleted / email-less agent: drain their rows so the
            // queue can't grow unbounded, but never send.
            if (!$user) {
                $this->stamp($rows, $dry, $stampedRows);
                $emptyOrOff++;
                continue;
            }

            $emailOn = $this->matchEmailEnabled($user);

            $groups = $emailOn ? $this->buildGroups($rows) : [];

            if ($dry) {
                $this->line(sprintf(
                    '[dry] %s <%s> — %d rows, %d emailable matches, email=%s',
                    $user->name, $user->email, $rows->count(),
                    array_sum(array_map(fn ($g) => count($g['items']), $groups)),
                    $emailOn ? 'on' : 'off'
                ));
                continue;
            }

            if ($emailOn && !empty($groups)) {
                try {
                    Mail::to($user->email)->send(new MatchDigestMail($user, array_values($groups)));
                    $sent++;
                } catch (\Throwable $e) {
                    // Send failed: do NOT stamp, so the next run retries.
                    Log::warning('SendMatchDigests: send failed', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                    continue;
                }
            } else {
                // Nothing emailable (all off-market) or email disabled.
                $emptyOrOff++;
            }

            // Stamp everything swept this run — included, off-market, or email-off.
            $this->stamp($rows, false, $stampedRows);
        }

        $this->info("Digests sent: {$sent}. Empty/off (stamped, no email): {$emptyOrOff}. Rows stamped: {$stampedRows}.");
        return self::SUCCESS;
    }

    /**
     * Build the per-contact groups for one agent, dropping any match whose
     * property is missing, soft-deleted, or no longer matchable (off-market).
     *
     * @param  \Illuminate\Support\Collection<int,ContactMatchNotification>  $rows
     * @return array<int,array{contact_id:int,name:string,items:array<int,array>}>
     */
    private function buildGroups($rows): array
    {
        $propertyIds = $rows->pluck('property_id')->unique()->all();
        $properties = Property::withoutGlobalScopes()
            ->whereIn('id', $propertyIds)
            ->get()
            ->keyBy('id');

        $matchIds = $rows->pluck('contact_match_id')->unique()->all();
        $matches = \App\Models\ContactMatch::withoutGlobalScopes()
            ->whereIn('id', $matchIds)
            ->with('contact')
            ->get()
            ->keyBy('id');

        $groups = [];

        foreach ($rows as $row) {
            $property = $properties->get($row->property_id);

            // Off-market / deleted re-check at send time — never email a stale match.
            if (!$property || $property->deleted_at
                || !MatchingService::isMatchableStatus($property->status)) {
                continue;
            }

            $match = $matches->get($row->contact_match_id);
            $contact = $match?->contact;
            $contactId = (int) ($match?->contact_id ?? 0);

            $name = $contact
                ? trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''))
                : '';
            $name = $name !== '' ? $name : ('Buyer #' . $contactId);

            if (!isset($groups[$contactId])) {
                $groups[$contactId] = [
                    'contact_id' => $contactId,
                    'name'       => $name,
                    'items'      => [],
                ];
            }

            $groups[$contactId]['items'][] = [
                'property_id'  => (int) $property->id,
                'address'      => $property->buildDisplayAddress(),
                'price'        => (int) ($property->price ?? 0),
                'score'        => (int) $row->score,
                'listing_type' => $property->listing_type,
            ];
        }

        return $groups;
    }

    /**
     * Does this agent want match emails? Mirrors the gate the per-match
     * notification used before the digest — a user who turned email off keeps
     * the real-time bell but no digest email.
     */
    private function matchEmailEnabled(User $user): bool
    {
        try {
            $settings = UserDashboardSetting::getEffective($user);
            return (bool) ($settings->notify_email ?? true);
        } catch (\Throwable $e) {
            // Settings unavailable — default to sending (the safer failure for a
            // once-daily digest: better a wanted email than a missed one).
            return true;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int,ContactMatchNotification>  $rows
     */
    private function stamp($rows, bool $dry, int &$counter): void
    {
        if ($dry) {
            return;
        }
        $ids = $rows->pluck('id')->all();
        if (empty($ids)) {
            return;
        }
        ContactMatchNotification::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->update(['emailed_at' => now()]);
        $counter += count($ids);
    }
}
