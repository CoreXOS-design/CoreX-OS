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
 */
class OversightDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(OversightService $service): void
    {
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

                $alreadyAlerted = OversightNudge::query()
                    ->where('to_user_id', $manager->id)
                    ->where('category', $row['category'])
                    ->where('subject_type', $row['subject_type'])
                    ->where('subject_id', $row['subject_id'])
                    ->where('created_at', '>=', now()->subHours(max(1, (int) ($pref->threshold_hours ?? 24))))
                    ->exists();

                if ($alreadyAlerted) {
                    continue;
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
                    Mail::to($manager->email)->queue(new OversightNudgeMail($nudge, $manager));
                }
            }
        }
    }
}
