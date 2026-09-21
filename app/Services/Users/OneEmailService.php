<?php

namespace App\Services\Users;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * AT-423 — One email (sub-users). Spec: .ai/specs/one-email-sub-users.md.
 *
 * The one place that knows the rules: whether the switch is on, which account is the
 * agency's shared inbox, how a username is built and validated, and the set-up link.
 * A sub-user's username lives in the unique users.email sign-in column, so every
 * existing login / reset / e-sign lookup keeps finding exactly one person.
 */
class OneEmailService
{
    /** Name part of a username: letters, numbers, dots, dashes, underscores. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,59}$/';

    /**
     * The agency switch (agencies.one_email_enabled) — in its own Settings → Team Inbox section,
     * deliberately not the Features page (Andre, 2026-09-21). It is only ever on together
     * with a chosen shared inbox; off hides "add a sub-user" but locks nobody out.
     */
    public function enabled(?Agency $agency): bool
    {
        return $agency !== null && (bool) $agency->one_email_enabled && (bool) $agency->one_email_user_id;
    }

    public function agencyFor(?User $actor): ?Agency
    {
        $agencyId = (int) ($actor?->effectiveAgencyId() ?: 0);

        return $agencyId > 0 ? Agency::find($agencyId) : null;
    }

    /** The agency's main account (shared inbox), or null if none has been chosen yet. */
    public function mainAccount(?Agency $agency): ?User
    {
        if (!$agency || !$agency->one_email_user_id) {
            return null;
        }

        return User::withoutGlobalScopes()->withTrashed()->find($agency->one_email_user_id);
    }

    /** A real, deliverable address: something@domain.tld (a username has no dot after the @). */
    public function isRealEmail(?string $value): bool
    {
        $value = trim((string) $value);
        $at = strrpos($value, '@');

        return $at !== false && $at > 0 && str_contains(substr($value, $at + 1), '.');
    }

    /**
     * The fixed username ending taken from the shared inbox's domain, up to the first dot:
     * agents@hfcoastal.co.za → "@hfcoastal". Null when the address is not a real email.
     */
    public function stemFor(?User $main): ?string
    {
        if (!$main || !$this->isRealEmail($main->email)) {
            return null;
        }

        $domain = strtolower(substr($main->email, strrpos($main->email, '@') + 1));
        $label = explode('.', $domain)[0] ?? '';

        return $label !== '' ? '@' . $label : null;
    }

    /**
     * Normalise what an admin typed into the bare name part: trimmed, lower-case, with a
     * typed-in ending stripped ("Andre ", "andre@hfcoastal" → "andre").
     */
    public function normaliseName(?string $typed, ?string $stem): string
    {
        $name = strtolower(trim((string) $typed));

        if ($stem && str_ends_with($name, $stem)) {
            $name = substr($name, 0, -strlen($stem));
        }

        return trim($name);
    }

    public function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name);
    }

    /**
     * Users that may be chosen as the shared inbox: this agency's active people who sign
     * in with a real email (never another sub-user, never an assistant).
     */
    public function candidates(Agency $agency): Collection
    {
        return User::withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->where('is_sub_user', false)
            ->where('is_assistant', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $u) => $this->isRealEmail($u->email))
            ->values();
    }

    public function subUserCount(?Agency $agency): int
    {
        if (!$agency) {
            return 0;
        }

        return User::withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->where('is_sub_user', true)
            ->count();
    }

    /** The signed set-up link (same route + 7-day window as every CoreX invite). */
    public function setupUrl(User $user): string
    {
        return URL::temporarySignedRoute('account.setup', now()->addDays(7), ['user' => $user->id]);
    }

    /**
     * Build a sub-user's full username (name@stem) from what an admin typed — the one rule
     * shared by Admin → Users and Admin → Assistants. The ending is fixed by CoreX from the
     * agency's shared inbox. Returns ['email' => username] or ['error' => plain message].
     *
     * An existing sub-user whose name part is unchanged keeps their username exactly, even
     * if the shared inbox has since changed (usernames never change on their own).
     */
    public function buildUsername(?Agency $agency, ?User $existing, ?string $typed): array
    {
        $typedName = strtolower(trim((string) $typed));
        $typedName = str_contains($typedName, '@') ? (string) strstr($typedName, '@', true) : $typedName;

        if ($existing?->isSubUser()) {
            $currentName = (string) strstr((string) $existing->email, '@', true);
            if ($typedName === '' || $typedName === $currentName) {
                return ['email' => $existing->email];
            }
        }

        // Editing someone who already signs in with a username stays possible with the
        // switch off (turning it off never locks anyone out); new sub-users need it on.
        if (!$this->enabled($agency) && !$existing?->isSubUser()) {
            return ['error' => 'Team Inbox is switched off for this agency. Turn it on in Settings → Team Inbox first.'];
        }

        $main = $this->mainAccount($agency);
        if ($existing && $main && (int) $existing->id === (int) $main->id) {
            return ['error' => 'This person is the shared inbox for your sub-users, so they must keep signing in with their own email. Choose a different shared inbox in Settings → Team Inbox first.'];
        }

        $stem = $this->stemFor($main);
        if (!$stem) {
            return ['error' => 'Your agency has no shared inbox set up. Choose one in Settings → Team Inbox first.'];
        }

        $name = $this->normaliseName($typed, $stem);
        if ($name === '') {
            return ['error' => 'Enter a username for this person.'];
        }
        if (!$this->isValidName($name)) {
            return ['error' => 'Usernames can only use letters, numbers, dots, dashes and underscores, and must start with a letter or number.'];
        }

        return ['email' => $name . $stem];
    }

    /** Plain-language validation messages when the sign-in column holds a username. */
    public function usernameMessages(?string $username): array
    {
        $name = strstr((string) $username, '@', true) ?: 'name';

        return [
            'email.unique'   => "That username is already taken. Try {$name}.r or {$name}2.",
            'email.required' => 'Enter a username for this person.',
        ];
    }

    /** What an Add/Edit form needs to offer "A username, sharing an inbox". */
    public function formData(?Agency $agency, ?User $user): array
    {
        $main = $this->mainAccount($agency);

        return [
            'enabled' => $this->enabled($agency),
            'stem'    => $this->stemFor($main),
            'inbox'   => $main?->email,
            'is_main' => $user && $main && (int) $user->id === (int) $main->id,
        ];
    }

    /** Case/space-insensitive check that the typed username is this sub-user's username. */
    public function usernameMatches(User $user, ?string $typed): bool
    {
        return $user->isSubUser()
            && strtolower(trim((string) $typed)) === strtolower(trim((string) $user->email));
    }
}
