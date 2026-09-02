<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Communications\CommunicationMailbox;
use Illuminate\Support\Facades\DB;

/**
 * Webinar-eve gap fix (2026-09-02) — Johan: "can we build a couple of fake
 * email boxes under email boxes (import)".
 *
 * INERT BY CONSTRUCTION. `App\Console\Commands\Communications\PollMailboxes`
 * (run every 5 minutes via routes/console.php) is the ONLY code path that
 * ever opens an IMAP connection, and its entire selection query is
 * `CommunicationMailbox::query()->where('active', true)` — confirmed by
 * reading the command source, not inferred. Every row this seeder writes has
 * `active = false`, which structurally removes it from that query — there is
 * no other condition to satisfy and no other poller. Defense in depth on top
 * of that: obviously-fake host (`imap.demo-inert.invalid` — an RFC 2606
 * .invalid TLD, guaranteed to never resolve), fake credentials, and
 * `last_polled_at`/`last_error` left null so the health badge reads
 * "inactive", never a scary false failure.
 *
 * Idempotent: firstOrCreate on (agency_id, email_address).
 */
final class DemoCommunicationMailboxesSeeder
{
    private const PLAN = [
        [
            'email_address' => 'listings-demo@demo-inert.invalid',
            'imap_host' => 'imap.demo-inert.invalid',
            'username' => 'listings-demo@demo-inert.invalid',
            'label_agent_idx' => 0,
        ],
        [
            'email_address' => 'transfers-demo@demo-inert.invalid',
            'imap_host' => 'imap.demo-inert.invalid',
            'username' => 'transfers-demo@demo-inert.invalid',
            'label_agent_idx' => 1,
        ],
    ];

    public function run(int $agencyId): array
    {
        $agentIds = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager', 'admin'])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (empty($agentIds)) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} has no users."];
        }

        $inserted = 0;

        foreach (self::PLAN as $plan) {
            $mailbox = CommunicationMailbox::firstOrCreate(
                ['agency_id' => $agencyId, 'email_address' => $plan['email_address']],
                [
                    'user_id' => $agentIds[$plan['label_agent_idx'] % count($agentIds)],
                    'imap_host' => $plan['imap_host'],
                    'imap_port' => 993,
                    'username' => $plan['username'],
                    'encrypted_password' => 'demo-inert-not-a-real-credential',
                    'auth_type' => 'imap',
                    'poll_inbox' => true,
                    'poll_sent' => false,
                    'poll_interval_minutes' => 5,
                    'active' => false,
                ]
            );
            if ($mailbox->wasRecentlyCreated) {
                $inserted++;
            }
        }

        return ['inserted' => $inserted, 'note' => 'Both rows active=false — never polled, host is an RFC 2606 .invalid domain.'];
    }
}
