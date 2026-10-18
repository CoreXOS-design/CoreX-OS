<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * lead-response:backfill — give OLD portal enquiries a first response ONLY where history can prove one
 * (Johan, 2026-10-07; spec .ai/specs/lead-response-time.md §12). Before response tracking existed nothing
 * recorded the first contact, and "last contacted" was overwritten, so most old leads stay "Not measured".
 * What is provable, taking the earliest event AT/AFTER the lead arrived for that contact:
 *   - an outbound message that actually went out (communications: outbound, sent, not provisional/purged,
 *     linked to the contact),
 *   - a Core Match link share confirmed as sent,
 *   - feedback captured on a calendar appointment the contact attended.
 * NOT provable, never guessed: the old contacted action (overwritten), phone calls, notes.
 *
 * Idempotent: only touches untracked leads with no first response; each fill also marks the lead tracked
 * (it now has a real response to measure). Only ever FILLS blanks. --dry-run (default is to apply) prints
 * what would change. Scope with --agency=ID.
 */
class BackfillLeadFirstResponse extends Command
{
    protected $signature = 'lead-response:backfill {--dry-run : Report what would change without writing} {--agency= : Only this agency id}';

    protected $description = 'Backfill the first response of old portal enquiries where it is provable from history (message sent, link share, appointment feedback)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $filled = 0;
        $byChannel = ['message' => 0, 'shared_link' => 0, 'appointment_feedback' => 0];
        $examined = 0;

        DB::table('portal_leads')
            ->whereNull('deleted_at')
            ->where('response_tracked', false)
            ->whereNull('first_response_at')
            ->whereNotNull('contact_id')
            ->when($this->option('agency'), fn ($q, $a) => $q->where('agency_id', (int) $a))
            ->orderBy('id')
            ->chunkById(200, function ($leads) use (&$filled, &$byChannel, &$examined, $dry) {
                foreach ($leads as $lead) {
                    $examined++;
                    $best = $this->earliest($lead);
                    if ($best === null) {
                        continue;
                    }
                    $filled++;
                    $byChannel[$best['channel']]++;
                    if (! $dry) {
                        DB::table('portal_leads')->where('id', $lead->id)->whereNull('first_response_at')->update([
                            'first_response_at' => $best['at'],
                            'first_response_by_user_id' => $best['by'],
                            'first_response_channel' => $best['channel'],
                            'response_tracked' => true,
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        $this->info(($dry ? '[DRY RUN] would fill ' : 'Filled ') . "{$filled} of {$examined} untracked enquiries with a provable first response "
            . '(' . implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($byChannel), $byChannel)) . '). The rest stay "Not measured".');

        return self::SUCCESS;
    }

    /** @return array{at:string,by:?int,channel:string}|null */
    private function earliest(object $lead): ?array
    {
        $contactId = (int) $lead->contact_id;
        $since = $lead->received_at;
        $found = [];

        $msg = DB::table('communications as c')
            ->join('communication_links as l', 'l.communication_id', '=', 'c.id')
            ->where('l.linkable_type', \App\Models\Contact::class)->where('l.linkable_id', $contactId)
            ->where('c.direction', 'outbound')->where('c.send_status', 'sent')
            ->whereNull('c.provisional_at')->whereNull('c.purged_at')->whereNull('c.deleted_at')
            ->where('c.occurred_at', '>=', $since)
            ->orderBy('c.occurred_at')->first(['c.occurred_at as at', 'c.owner_user_id as by']);
        if ($msg) {
            $found[] = ['at' => $msg->at, 'by' => $msg->by ? (int) $msg->by : null, 'channel' => 'message'];
        }

        $share = DB::table('contact_match_shares as s')
            ->join('contact_matches as m', 'm.id', '=', 's.contact_match_id')
            ->where('m.contact_id', $contactId)->whereNull('s.deleted_at')
            ->whereNotNull('s.confirmed_at')->where('s.confirmed_at', '>=', $since)
            ->orderBy('s.confirmed_at')->first(['s.confirmed_at as at', 's.shared_by_user_id as by']);
        if ($share) {
            $found[] = ['at' => $share->at, 'by' => $share->by ? (int) $share->by : null, 'channel' => 'shared_link'];
        }

        $fb = DB::table('calendar_event_feedback as f')
            ->where(function ($q) use ($contactId) {
                $q->where('f.contact_id', $contactId)->orWhereIn('f.calendar_event_id', function ($sub) use ($contactId) {
                    $sub->select('calendar_event_id')->from('calendar_event_links')
                        ->where('linkable_type', \App\Models\Contact::class)->where('linkable_id', $contactId);
                });
            })
            ->whereNotNull('f.captured_at')->where('f.captured_at', '>=', $since)
            ->orderBy('f.captured_at')->first(['f.captured_at as at', 'f.captured_by_user_id as by']);
        if ($fb) {
            $found[] = ['at' => $fb->at, 'by' => $fb->by ? (int) $fb->by : null, 'channel' => 'appointment_feedback'];
        }

        if ($found === []) {
            return null;
        }
        usort($found, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $found[0];
    }
}
