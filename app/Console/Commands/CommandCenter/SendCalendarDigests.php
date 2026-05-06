<?php

namespace App\Console\Commands\CommandCenter;

use App\Mail\CommandCenter\CalendarDailyDigest;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCalendarDigests extends Command
{
    protected $signature = 'corex:calendar:send-digests {--dry : Print without sending}';
    protected $description = 'Send daily calendar digest emails to users per their event class config';

    public function handle(
        CalendarThresholdResolver $resolver,
        CalendarVisibilityResolver $visibility,
    ): int {
        $dry = (bool) $this->option('dry');
        $today = now()->startOfDay();

        // Find all active event classes with daily digest enabled.
        $digestClasses = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('daily_digest_enabled', true)
            ->get();

        if ($digestClasses->isEmpty()) {
            $this->info('No event classes have digest enabled. Nothing to do.');
            return self::SUCCESS;
        }

        // Collect all digest roles across all classes.
        $allRolesNeeded = $digestClasses
            ->flatMap(fn ($cfg) => $cfg->daily_digest_roles ?? [])
            ->unique()
            ->values();

        // Widen role matching: 'bm' in config matches 'branch_manager' in DB.
        $dbRoles = $allRolesNeeded->map(fn ($r) => $r === 'bm' ? 'branch_manager' : $r)->unique();

        if ($dbRoles->isEmpty()) {
            $this->info('No digest roles configured. Nothing to do.');
            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        User::query()
            ->withoutGlobalScopes()
            ->whereIn('role', $dbRoles->all())
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(100, function ($users) use (
                $digestClasses, $resolver, $visibility, $dry, &$sent, &$skipped, $today
            ) {
                foreach ($users as $user) {
                    $grouped = ['red' => [], 'amber' => [], 'green' => []];

                    foreach ($digestClasses as $classConfig) {
                        // Resolve effective config for user's agency.
                        $cfg = CalendarEventClassSetting::forAgencyAndClass(
                            $user->effectiveAgencyId(), $classConfig->event_class
                        );
                        if (!$cfg || !$cfg->is_active || !$cfg->daily_digest_enabled) {
                            continue;
                        }

                        // Check if user's role is in digest recipients.
                        // Widen: 'bm' matches 'branch_manager'.
                        $userRoleForMatch = $user->role === 'branch_manager' ? 'bm' : $user->role;
                        if (!in_array($userRoleForMatch, $cfg->daily_digest_roles ?? [], true)) {
                            continue;
                        }

                        $showDays = $cfg->show_days ?? 365;

                        $candidates = CalendarEvent::withoutGlobalScopes()
                            ->where('category', $cfg->event_class)
                            ->where('status', 'pending')
                            ->whereBetween('event_date', [
                                $today->copy()->subDays(7),
                                $today->copy()->addDays($showDays),
                            ])
                            ->whereNull('deleted_at')
                            ->get();

                        foreach ($candidates as $event) {
                            if (!$visibility->canSee($event, $user)) {
                                continue;
                            }
                            $colour = $resolver->resolveForEvent($event);
                            if (!$colour) {
                                continue;
                            }
                            $grouped[$colour][] = [
                                'event'       => $event,
                                'class_label' => $cfg->label,
                            ];
                        }
                    }

                    $total = count($grouped['red']) + count($grouped['amber']) + count($grouped['green']);
                    if ($total === 0) {
                        $skipped++;
                        continue;
                    }

                    if ($dry) {
                        $this->line(sprintf(
                            '[dry] %s <%s> — red:%d amber:%d green:%d',
                            $user->name, $user->email,
                            count($grouped['red']), count($grouped['amber']), count($grouped['green'])
                        ));
                        continue;
                    }

                    try {
                        Mail::to($user->email)->send(new CalendarDailyDigest($user, $grouped));
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning('SendCalendarDigests: send failed', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Sent: {$sent}. Skipped (empty digest): {$skipped}.");
        return self::SUCCESS;
    }
}
