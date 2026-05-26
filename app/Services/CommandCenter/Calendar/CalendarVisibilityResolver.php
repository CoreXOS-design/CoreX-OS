<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\AgencyLeaveVisibilityMatrix;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\User;

class CalendarVisibilityResolver
{
    public function __construct(
        private CalendarThresholdResolver $thresholdResolver,
    ) {}

    /**
     * Determine if a user can see an event on their calendar.
     *
     * Rules:
     *  1. Cross-agency events are never visible (agency isolation).
     *  2. Super_admin bypasses all further checks.
     *  3. Admin/owner within the event's agency bypasses class visibility.
     *  4. Event creator always sees their own events.
     *  5. Event must currently resolve to a colour (within show window + active).
     *  6. User's role must appear in that colour's visibility list.
     */
    public function canSee(CalendarEvent $event, User $user): bool
    {
        // Agency isolation guard.
        $userAgencyId = $user->effectiveAgencyId();
        if ($userAgencyId !== null && $event->agency_id !== null && (int) $event->agency_id !== (int) $userAgencyId) {
            return false;
        }

        // Super_admin sees everything (cross-agency allowed when effectiveAgencyId is null).
        if ($user->role === 'super_admin') {
            return true;
        }

        // Admin/owner within their own agency sees all events in that agency.
        if (in_array($user->role, ['admin', 'owner'], true) && (int) ($user->agency_id ?? 0) === (int) ($event->agency_id ?? 0)) {
            return true;
        }

        // Event creator always sees their own events.
        if ($event->user_id !== null && (int) $event->user_id === (int) $user->id) {
            return true;
        }

        // Invitation-based visibility: pending/accepted/tentative attendees see the event
        // (pending shown with distinct styling so user knows they haven't responded yet)
        $hasInvitation = \App\Models\CommandCenter\CalendarEventInvitation::where('event_id', $event->id)
            ->where('invitee_user_id', $user->id)
            ->whereIn('status', ['pending', 'accepted', 'tentative'])
            ->exists();
        if ($hasInvitation) {
            return true;
        }

        // Leave visibility matrix check (M3.4) — consult agency-configured matrix
        // for leave event classes before falling through to general class visibility.
        $config = CalendarEventClassSetting::forAgencyAndClass($event->agency_id, $event->category ?? '');
        if ($config && ($config->event_nature ?? 'actionable') === 'informational'
            && str_contains($event->category ?? '', 'leave')
            && $event->user_id && $event->agency_id) {
            $leaveOwner = User::withoutGlobalScopes()->find($event->user_id);
            if ($leaveOwner) {
                $viewingRole = $user->effectiveRole();
                $ownerRole = $leaveOwner->role ?? 'agent';
                $sameBranch = $user->branch_id && $leaveOwner->branch_id
                    && (int) $user->branch_id === (int) $leaveOwner->branch_id;
                $canSeeLeave = AgencyLeaveVisibilityMatrix::canSee(
                    $viewingRole, $ownerRole, $sameBranch, (int) $event->agency_id
                );
                if (!$canSeeLeave) {
                    // Also check cross-branch (same_branch_only=false)
                    $canSeeLeave = AgencyLeaveVisibilityMatrix::canSee(
                        $viewingRole, $ownerRole, false, (int) $event->agency_id
                    );
                }
                return $canSeeLeave;
            }
        }

        $colour = $this->thresholdResolver->resolveForEvent($event);
        if ($colour === null) {
            return false;
        }

        if (!$config) {
            return false;
        }

        $visibleRoles = $config->visibilityFor($colour);
        return $this->userMatchesAnyRole($user, $visibleRoles);
    }

    /**
     * Filter a collection of events down to only those the user may see.
     */
    public function filterVisible(iterable $events, User $user): array
    {
        $visible = [];
        foreach ($events as $event) {
            if ($this->canSee($event, $user)) {
                $visible[] = $event;
            }
        }
        return $visible;
    }

    /**
     * Check if a user's role matches any in the role list.
     *
     * Role widening:
     *  - 'bm' in config matches 'branch_manager' in DB
     *  - 'admin' in config matches 'admin', 'super_admin', 'owner' in DB
     *  - 'all' matches everyone
     */
    private function userMatchesAnyRole(User $user, array $roles): bool
    {
        if (empty($roles)) {
            return false;
        }

        if (in_array('all', $roles, true)) {
            return true;
        }

        $userRole = $user->role ?? null;
        if (!$userRole) {
            return false;
        }

        // Direct match.
        if (in_array($userRole, $roles, true)) {
            return true;
        }

        // Widen: 'bm' in config matches 'branch_manager' in DB.
        if (in_array('bm', $roles, true) && $userRole === 'branch_manager') {
            return true;
        }

        // Widen: owner / super_admin sees admin-level events.
        if (in_array('admin', $roles, true) && in_array($userRole, ['owner', 'super_admin'], true)) {
            return true;
        }

        return false;
    }
}
