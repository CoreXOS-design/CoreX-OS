<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Release;

use App\Models\Agency;
use App\Models\Docuperfect\TemplateReleaseEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/esign-template-transfer.md §13.3 — "Who may authorise documents" (Johan's R2): the people and roles an agency names to approve a
 * released template. A person may authorise when they are in the people list OR hold a ticked role. Until an agency picks anyone, its
 * administrators may (so a new agency cannot lock itself out). The CoreX owner never approves for an agency, and the Role Manager key
 * `templates.approve_release` is a floor: a role without it cannot authorise even if it is ticked.
 */
final class AgencyDocumentAuthorisers
{
    public const PERMISSION = 'templates.approve_release';
    public const FALLBACK_ROLE = 'admin';

    /** @return array{user_ids: list<int>, roles: list<string>} */
    public static function settings(int $agencyId): array
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);

        return [
            'user_ids' => array_values(array_unique(array_map('intval', (array) ($agency?->document_authoriser_user_ids ?? [])))),
            'roles' => array_values(array_unique(array_filter(array_map('strval', (array) ($agency?->document_authoriser_roles ?? [])), fn ($r) => $r !== ''))),
        ];
    }

    public static function isConfigured(int $agencyId): bool
    {
        $s = self::settings($agencyId);

        return $s['user_ids'] !== [] || $s['roles'] !== [];
    }

    public static function canAuthorise(?User $user, int $agencyId): bool
    {
        if (! $user || $agencyId <= 0 || $user->isOwnerRole()) {
            return false;
        }
        if ((int) $user->effectiveAgencyId() !== $agencyId || ! $user->hasPermission(self::PERMISSION)) {
            return false;
        }
        $s = self::settings($agencyId);
        if ($s['user_ids'] === [] && $s['roles'] === []) {
            return (string) $user->role === self::FALLBACK_ROLE;
        }

        return in_array((int) $user->id, $s['user_ids'], true) || in_array((string) $user->role, $s['roles'], true);
    }

    /** Everyone in the agency who may authorise right now (active users only) — the people told when a document is released. */
    public static function authorisers(int $agencyId): Collection
    {
        $s = self::settings($agencyId);
        $q = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true);
        if ($s['user_ids'] === [] && $s['roles'] === []) {
            $q->where('role', self::FALLBACK_ROLE);
        } else {
            $q->where(function ($w) use ($s) {
                $w->whereIn('id', $s['user_ids'] ?: [0])->orWhereIn('role', $s['roles'] ?: ['']);
            });
        }

        return $q->get()->filter(fn (User $u) => ! $u->isOwnerRole() && $u->hasPermission(self::PERMISSION))->values();
    }

    /** Roles an agency can tick (the owner role is never offered). */
    public static function assignableRoles(int $agencyId): Collection
    {
        return Role::allRoles($agencyId)->filter(fn ($r) => ! $r->is_owner)->values();
    }

    /**
     * Ticked people whose role cannot open the approval screen yet (Role Manager lacks the key) — shown as a warning on the settings card.
     *
     * @return list<string> names
     */
    public static function peopleWithoutThePermission(int $agencyId): array
    {
        $s = self::settings($agencyId);
        $users = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where(function ($w) use ($s) {
            $w->whereIn('id', $s['user_ids'] ?: [0])->orWhereIn('role', $s['roles'] ?: ['']);
        })->get();

        return $users->filter(fn (User $u) => ! $u->hasPermission(self::PERMISSION))->map(fn (User $u) => (string) $u->name)->values()->all();
    }

    /**
     * Save either list (null = "not posted" = leave that list alone, so a Setup Wizard step that posts only one list never wipes the other).
     * Every user id is re-resolved inside the agency (a tampered id from another agency is refused); every role must be one the agency has.
     *
     * @param  list<int|string>|null  $userIds
     * @param  list<string>|null  $roles
     * @throws TemplateReleaseException (422) when an id or role is not valid for this agency
     */
    public function save(int $agencyId, ?array $userIds, ?array $roles, User $actor, string $source = 'settings'): void
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        if (! $agency) {
            throw new TemplateReleaseException('That agency does not exist.', 404);
        }
        $before = self::settings($agencyId);
        $update = [];

        if ($userIds !== null) {
            $ids = array_values(array_unique(array_map('intval', array_filter($userIds, fn ($v) => $v !== null && $v !== ''))));
            foreach ($userIds as $v) {
                if ($v !== null && $v !== '' && ! is_numeric($v)) {
                    throw new TemplateReleaseException('One of the chosen people is not valid.');
                }
            }
            $ok = $ids === [] ? [] : User::withoutGlobalScopes()->where('agency_id', $agencyId)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (array_diff($ids, $ok) !== []) {
                throw new TemplateReleaseException("One or more of the chosen people don't belong to this agency.");
            }
            $update['document_authoriser_user_ids'] = $ok === [] ? null : array_values($ok);
        }

        if ($roles !== null) {
            $wanted = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) $r), $roles), fn ($r) => $r !== '')));
            $known = self::assignableRoles($agencyId)->pluck('name')->map(fn ($n) => (string) $n)->all();
            if (array_diff($wanted, $known) !== []) {
                throw new TemplateReleaseException("One or more of the chosen roles don't exist for this agency.");
            }
            $update['document_authoriser_roles'] = $wanted === [] ? null : $wanted;
        }

        if ($update === []) {
            return;
        }
        $agency->forceFill($update)->save();

        $after = self::settings($agencyId);
        sort($before['user_ids']);
        sort($after['user_ids']);
        sort($before['roles']);
        sort($after['roles']);
        if ($before !== $after) {
            TemplateReleaseEvent::create([
                'agency_id' => $agencyId,
                'event' => 'authorisers_changed',
                'actor_user_id' => $actor->id,
                'actor_name' => (string) $actor->name,
                'detail' => ['source' => $source, 'before' => $before, 'after' => $after],
            ]);
        }
    }
}
