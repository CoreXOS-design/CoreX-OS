<?php

namespace App\Jobs;

use App\Mail\OversightNudgeMail;
use App\Models\OversightNudge;
use App\Models\User;
use App\Models\UserOversightPreference;
use App\Services\Oversight\OversightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Hourly digest: for every manager with oversight permission, evaluate their
 * preferences and dispatch in-app / email notifications for newly-outstanding
 * items they have not yet been alerted about.
 *
 * Idempotency is achieved via the `oversight_nudges` table — we record an
 * auto-nudge per (manager, agent, subject, category) and skip if one exists
 * within the last threshold window.
 *
 * NUDGE EMAILS ARE OFF BY DEFAULT (Johan, 2026-08-23 — see config/oversight.php
 * and .ai/audits/2026-08-23-queue-failed-jobs-triage.md). The gate is on the
 * outbound Mail::queue() call only, inside run() below — the OversightNudge
 * idempotency row and the in-app DatabaseNotification are UNCHANGED by this
 * flag and keep recording normally. This is deliberate: if idempotency
 * tracking were also suppressed while off, every nudge that "should" have
 * fired during the off period would look brand new the instant this is
 * switched on and all fire in one burst — recreating the exact flood the
 * flag exists to prevent.
 */
class OversightDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(OversightService $service): void
    {
        $this->run($service, persist: true);
    }

    /**
     * Core evaluation loop, shared between the real hourly run (persist=true)
     * and the read-only volume-report command (persist=false — evaluates
     * against real current state via the SAME idempotency query, but never
     * writes OversightNudge/DatabaseNotification rows and never sends mail,
     * so running the report changes nothing and can be run as often as
     * needed without consuming the idempotency window it's trying to measure).
     *
     * @return array<int, array{manager_id:int, manager_email:?string, category:string, channel:string, subject_type:string, subject_id:int|string, threshold_hours:int}>
     *         Every item that WOULD fire (or did fire, when persist=true) on this run.
     */
    public function run(OversightService $service, bool $persist): array
    {
        $fired = [];

        $managers = User::query()
            ->whereNotNull('agency_id')
            ->get()
            ->filter(fn ($u) => $u->hasPermission('dashboard.oversight.view'));

        foreach ($managers as $manager) {
            $rows = $service->feed($manager);
            if ($rows->isEmpty()) {
                continue;
            }

            $prefs = UserOversightPreference::query()
                ->where('user_id', $manager->id)
                ->get()
                ->keyBy('category');

            foreach ($rows as $row) {
                $pref = $prefs[$row['category']] ?? null;
                if ($pref && !$pref->enabled) {
                    continue;
                }
                $channel = $pref?->notify_channel ?? (UserOversightPreference::DEFAULTS[$row['category']]['notify_channel'] ?? 'in_app');

                // NOTE (found 2026-08-23, reported not fixed — see the audit):
                // this falls back to a flat 24h when no explicit preference row
                // exists, NOT to UserOversightPreference::DEFAULTS[category]
                // ['threshold_hours'] the way OversightService::feed() does at
                // its own threshold lookup. With zero preference rows saved
                // anywhere on staging today, every category's idempotency
                // window is effectively 24h flat right now, not the intended
                // 168/336/720h for deals_near_expiry/expiring_mandates/
                // expiring_ffcs. Left exactly as-is — changing re-nudge cadence
                // is a product decision, not a mechanical bug fix, and it
                // directly shapes the volume numbers this run() method exists
                // to measure honestly, bug included.
                $thresholdHours = max(1, (int) ($pref->threshold_hours ?? 24));

                $alreadyAlerted = OversightNudge::query()
                    ->where('to_user_id', $manager->id)
                    ->where('category', $row['category'])
                    ->where('subject_type', $row['subject_type'])
                    ->where('subject_id', $row['subject_id'])
                    ->where('created_at', '>=', now()->subHours($thresholdHours))
                    ->exists();

                if ($alreadyAlerted) {
                    continue;
                }

                $fired[] = [
                    'manager_id'      => $manager->id,
                    'manager_email'   => $manager->email,
                    'category'        => $row['category'],
                    'channel'         => $channel,
                    'subject_type'    => $row['subject_type'],
                    'subject_id'      => $row['subject_id'],
                    'threshold_hours' => $thresholdHours,
                ];

                if (!$persist) {
                    continue; // dry run — evaluate only, never write, never send
                }

                $nudge = OversightNudge::create([
                    'agency_id'    => $manager->agency_id,
                    'from_user_id' => $manager->id,
                    'to_user_id'   => $manager->id,
                    'subject_type' => $row['subject_type'],
                    'subject_id'   => $row['subject_id'],
                    'category'     => $row['category'],
                    'message'      => '[digest] ' . $row['summary'],
                    'sent_at'      => now(),
                ]);

                if (in_array($channel, ['in_app', 'both'], true)) {
                    DatabaseNotification::create([
                        'id'              => (string) Str::uuid(),
                        'type'            => 'oversight.digest',
                        'notifiable_type' => User::class,
                        'notifiable_id'   => $manager->id,
                        'data'            => [
                            'message'  => $row['summary'],
                            'category' => $row['category'],
                            'agent_id' => $row['agent_id'],
                        ],
                    ]);
                }

                if (in_array($channel, ['email', 'both'], true) && $manager->email) {
                    if (config('oversight.nudges_enabled')) {
                        Mail::to($manager->email)->queue(new OversightNudgeMail($nudge, $manager));
                    } else {
                        Log::info('OversightDigestJob: nudge email suppressed (oversight.nudges_enabled=false)', [
                            'manager_id'   => $manager->id,
                            'category'     => $row['category'],
                            'subject_type' => $row['subject_type'],
                            'subject_id'   => $row['subject_id'],
                        ]);
                    }
                }
            }
        }

        return $fired;
    }
}
