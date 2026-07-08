<?php

namespace App\Console\Commands\CommandCenter;

use App\Mail\CommandCenter\CalendarDailyDigest;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\Contact;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use App\Services\CommandCenter\NotificationPreferenceService;
use App\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCalendarDigests extends Command
{
    protected $signature = 'corex:calendar:send-digests {--dry : Print without sending}';
    protected $description = 'Send the single daily digest email (calendar items + birthdays) to each user';

    public function handle(
        CalendarThresholdResolver $resolver,
        CalendarVisibilityResolver $visibility,
        NotificationPreferenceService $prefs,
    ): int {
        $dry = (bool) $this->option('dry');
        $today = now()->startOfDay();

        // Calendar event classes opted into the daily digest (role-gated policy).
        $digestClasses = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('daily_digest_enabled', true)
            ->get();

        // Birthdays today, grouped by the OWNING agent. Birthdays are PERSONAL
        // (delivered to whoever created the contact), opt-in per contact
        // (contacts.birthday_reminder) and — since this consolidation — delivered
        // ONLY through this one daily digest, never as a per-contact
        // email/in-app/push. That per-birthday fan-out was the inbox flood ("all
        // users only ever get one email from CoreX"). See
        // .ai/specs/SPEC_calendar_event_classes.md (RS-11 / birthday digest).
        $birthdaysByUser = $this->birthdaysTodayByOwner($prefs);

        // Recipient set = calendar-digest role users UNION birthday owners. A
        // manager with both receives ONE email carrying calendar items AND
        // birthdays; an agent with only birthdays (no digest role) still gets
        // their single email. Union deduplicates so nobody is emailed twice.
        $roleUserIds = collect();
        if ($digestClasses->isNotEmpty()) {
            $allRolesNeeded = $digestClasses
                ->flatMap(fn ($cfg) => $cfg->daily_digest_roles ?? [])
                ->unique()
                ->values();

            // Widen role matching: 'bm' in config matches 'branch_manager' in DB.
            $dbRoles = $allRolesNeeded->map(fn ($r) => $r === 'bm' ? 'branch_manager' : $r)->unique();

            if ($dbRoles->isNotEmpty()) {
                $roleUserIds = User::query()
                    ->withoutGlobalScopes()
                    ->whereIn('role', $dbRoles->all())
                    ->pluck('id');
            }
        }

        $recipientIds = $roleUserIds->merge($birthdaysByUser->keys())->unique()->values();

        if ($recipientIds->isEmpty()) {
            $this->info('No digest recipients (no digest classes, no birthdays). Nothing to do.');
            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        User::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $recipientIds->all())
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(100, function ($users) use (
                $digestClasses, $resolver, $visibility, $dry, &$sent, &$skipped, $today, $birthdaysByUser
            ) {
                foreach ($users as $user) {
                    $grouped = ['red' => [], 'amber' => [], 'green' => []];

                    // Role-driven data-scope ceiling (own | branch | all) — the SAME
                    // clamp the calendar grid applies (CalendarController::index ->
                    // PermissionService::calendarScope -> CalendarEvent::scopeVisibleTo).
                    // Without this the digest fell back to canSee() alone, which grants
                    // role/colour-based visibility of OTHER agents' events — so an
                    // 'own'-scope agent received calendar items that were not theirs.
                    // Mirroring the grid's scope keeps the two exactly in parity.
                    $scope = PermissionService::calendarScope($user);

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
                            ->visibleTo($user, $scope)
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

                    $birthdays = $birthdaysByUser->get($user->id, []);

                    $total = count($grouped['red']) + count($grouped['amber'])
                        + count($grouped['green']) + count($birthdays);
                    if ($total === 0) {
                        $skipped++;
                        continue;
                    }

                    if ($dry) {
                        $this->line(sprintf(
                            '[dry] %s <%s> — red:%d amber:%d green:%d birthdays:%d',
                            $user->name, $user->email,
                            count($grouped['red']), count($grouped['amber']),
                            count($grouped['green']), count($birthdays)
                        ));
                        continue;
                    }

                    try {
                        Mail::to($user->email)->send(new CalendarDailyDigest($user, $grouped, $birthdays));
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

    /**
     * Contacts whose birthday is today, grouped by the owning agent's id.
     *
     * Mirrors the tenant + opt-in guarantees the old hourly ScanContactNotifications
     * enforced, now that birthdays are delivered solely via this digest:
     *   - opt-in per contact (contacts.birthday_reminder = true);
     *   - the contact must carry the OWNER's own agency_id (a system-owner with a
     *     NULL agency never notifies — see .ai/specs/multi-tenancy.md);
     *   - the owner's contact.birthday preference must still be enabled (respects
     *     a user who turned the whole birthday feature off).
     *
     * @return Collection<int,array<int,array{name:string,contact_id:int,action_url:string}>>
     */
    private function birthdaysTodayByOwner(NotificationPreferenceService $prefs): Collection
    {
        $now = now();

        $contacts = Contact::withoutGlobalScopes()
            ->whereNotNull('created_by_user_id')
            ->whereNotNull('agency_id')
            ->where('birthday_reminder', true)
            ->whereNotNull('birthday')
            ->whereMonth('birthday', $now->month)
            ->whereDay('birthday', $now->day)
            ->get(['id', 'created_by_user_id', 'agency_id', 'first_name', 'last_name']);

        if ($contacts->isEmpty()) {
            return collect();
        }

        $owners = User::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $contacts->pluck('created_by_user_id')->unique()->all())
            ->get()
            ->keyBy('id');

        // Cache the per-owner birthday opt-in so we resolve preferences once each.
        $ownerEnabled = [];

        $byUser = collect();
        foreach ($contacts as $contact) {
            $owner = $owners->get($contact->created_by_user_id);
            if (! $owner) {
                continue;
            }

            // Tenant guard: strict agency match; NULL-agency owners never notify.
            $agencyId = $this->agencyIdFor($owner);
            if (! $agencyId || (int) ($contact->agency_id ?? 0) !== $agencyId) {
                continue;
            }

            if (! array_key_exists($owner->id, $ownerEnabled)) {
                $eff = $prefs->effective($owner, 'contact.birthday');
                $ownerEnabled[$owner->id] = (bool) ($eff['enabled'] ?? false);
            }
            if (! $ownerEnabled[$owner->id]) {
                continue;
            }

            $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''))
                ?: ('Contact #' . $contact->id);

            $list = $byUser->get($owner->id, []);
            $list[] = [
                'name'       => $name,
                'contact_id' => (int) $contact->id,
                'action_url' => "/contacts/{$contact->id}",
            ];
            $byUser->put($owner->id, $list);
        }

        return $byUser;
    }

    /**
     * Resolve an agent's effective agency without touching the session (console
     * context). Mirrors ScanContactNotifications::agencyIdFor — a system-owner
     * account with no agency resolves to null and is therefore never notified.
     */
    private function agencyIdFor(User $agent): ?int
    {
        if ($agent->agency_id) {
            return (int) $agent->agency_id;
        }
        if ($agent->branch_id) {
            $branch = Branch::find($agent->branch_id);
            if ($branch?->agency_id) {
                return (int) $branch->agency_id;
            }
        }
        return null;
    }
}
