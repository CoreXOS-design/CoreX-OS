<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Models\User;

/**
 * AT-479 / rental-takeon-import.md R4.2 — the reOS "Agent" cell, e.g. "Retha Kelly (0%),Home Finders Coastal (100%)".
 *
 * The (n%) splits and the agency's own entry are ignored; the FIRST person left is matched, by name, to an active user of
 * the agency. No such person, or only the agency named → no match (the user picks). The agency's own name is read from
 * its record, never hard-coded (multi-agency, non-negotiable #9).
 */
class ReosAgentMatcher
{
    /** @var array<int, list<array{id:int,name:string,key:string}>> */
    private array $usersByAgency = [];

    /** @var array<int, list<string>> */
    private array $agencyKeys = [];

    /**
     * @return array{user_id:?int, person:?string, reason:string}
     */
    public function match(?string $agentText, int $agencyId): array
    {
        $people = $this->people($agentText, $agencyId);
        if ($people === []) {
            return ['user_id' => null, 'person' => null, 'reason' => $agentText === null || trim($agentText) === '' ? 'No agent named.' : 'Only the agency is named.'];
        }

        $person = $people[0];
        $key = ReosContactNames::key($person);
        $users = $this->users($agencyId);

        foreach ($users as $u) {
            if ($u['key'] === $key) {
                return ['user_id' => $u['id'], 'person' => $person, 'reason' => 'Matched by name.'];
            }
        }
        // Every word of the reOS name appears in exactly one user's name ("Retha Kelly" vs "Retha J Kelly").
        $words = explode(' ', $key);
        $hits = array_values(array_filter($users, fn ($u) => count(array_diff($words, explode(' ', $u['key']))) === 0));
        if (count($hits) === 1) {
            return ['user_id' => $hits[0]['id'], 'person' => $person, 'reason' => 'Matched by name.'];
        }

        return ['user_id' => null, 'person' => $person, 'reason' => $hits === [] ? "No user called \"{$person}\"." : "More than one user fits \"{$person}\"."];
    }

    /**
     * The named people in the cell, in order, without the percentages and without the agency's own entry.
     *
     * @return list<string>
     */
    public function people(?string $agentText, int $agencyId): array
    {
        if ($agentText === null || trim($agentText) === '') {
            return [];
        }
        $agencyKeys = $this->agencyKeys($agencyId);
        $out = [];
        foreach (explode(',', $agentText) as $entry) {
            $name = trim((string) preg_replace('/\(\s*[\d.,]+\s*%?\s*\)/u', '', $entry));
            if ($name === '') {
                continue;
            }
            if (in_array(ReosContactNames::key($name), $agencyKeys, true)) {
                continue;
            }
            $out[] = $name;
        }

        return $out;
    }

    /**
     * People who can be picked as the agent of a property / lease: active users of the agency, minus system-owner
     * accounts (the property observer refuses those as a property agent).
     *
     * @return list<array{id:int,name:string,key:string}>
     */
    public function users(int $agencyId): array
    {
        if (isset($this->usersByAgency[$agencyId])) {
            return $this->usersByAgency[$agencyId];
        }
        $ownerRoles = User::ownerRoleNames();

        return $this->usersByAgency[$agencyId] = User::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('users.agency_id', $agencyId)
            ->where('users.is_active', true)
            ->when($ownerRoles !== [], fn ($q) => $q->whereNotIn('users.role', $ownerRoles))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => trim((string) $u->name) ?: 'User #' . $u->id, 'key' => ReosContactNames::key($u->name)])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function agencyKeys(int $agencyId): array
    {
        if (isset($this->agencyKeys[$agencyId])) {
            return $this->agencyKeys[$agencyId];
        }
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        $names = array_filter([
            $agency?->name,
            $agency ? Agency::displayNameFor($agency) : null,
            $agency->trading_name ?? null,
        ]);

        return $this->agencyKeys[$agencyId] = array_values(array_unique(array_map([ReosContactNames::class, 'key'], $names)));
    }
}
