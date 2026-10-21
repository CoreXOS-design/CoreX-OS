<?php

declare(strict_types=1);

namespace App\Support\Docuperfect;

/**
 * THE one rule for "who does a proxy sign for" (HOTFIX 2026-10-21, AT-498).
 *
 * Until now a person marked Proxy collapsed EVERY other person in their role — the rule was written three times
 * (the wizard's array filter, RoleBlockExpansionService's in-memory filter, SignatureRequest::nonSigningReason's
 * query). Now a proxy covers every other party of the SAME role EXCEPT the ones the agent un-ticked
 * (`_proxy_excludes` on the recipient row / `proxy_excludes` on the signature request: a list of the excluded parties'
 * stable keys). An EMPTY / absent list means "everyone else" — exactly the old behaviour, so every flow that exists
 * today (no proxy, or a proxy with no exclusions) resolves identically.
 *
 * A party is identified by `_recipient_local_key` / `recipient_local_key` (the key every recipient already carries and
 * the signature request freezes at creation). A party with NO identity can never appear in an exclusion list: an "everyone"
 * proxy still covers it (the old rule), but a partial proxy never does — a party is never silently dropped.
 *
 * Rows are normalised to ['role' => string, 'proxy' => bool, 'key' => ?string, 'excludes' => list<string>] so the
 * wizard arrays and the SignatureRequest models share the same predicate.
 */
final class ProxyCoverage
{
    /** @return array{role: string, proxy: bool, key: ?string, excludes: list<string>, entity: bool} */
    public static function fromArray(array $r): array
    {
        $key = $r['_recipient_local_key'] ?? null;

        return [
            'role'     => strtolower((string) ($r['role'] ?? '')),
            'proxy'    => ! empty($r['_is_proxy']),
            'key'      => is_string($key) && $key !== '' ? $key : null,
            'excludes' => self::cleanList($r['_proxy_excludes'] ?? null),
            'entity'   => ! empty($r['_entity_contact_id']),
        ];
    }

    /** @return array{role: string, proxy: bool, key: ?string, excludes: list<string>, entity: bool} */
    public static function fromRequest(object $r): array
    {
        $key = $r->recipient_local_key ?? null;

        return [
            'role'     => strtolower((string) ($r->party_role ?? '')),
            'proxy'    => ! empty($r->is_proxy),
            'key'      => is_string($key) && $key !== '' ? $key : null,
            'excludes' => self::cleanList($r->proxy_excludes ?? null),
            'entity'   => ! empty($r->represented_contact_id),
        ];
    }

    /**
     * Is $row covered by a proxy elsewhere in its role group (and so displayed but not asked to sign)?
     * A proxy itself always signs. Pass every row of the document — the predicate looks at the whole group.
     *
     * @param  array{role: string, proxy: bool, key: ?string, excludes: list<string>, entity?: bool}  $row
     * @param  list<array{role: string, proxy: bool, key: ?string, excludes: list<string>}>  $all
     */
    public static function isCovered(array $row, array $all): bool
    {
        if ($row['proxy']) {
            return false;
        }
        foreach ($all as $p) {
            if ($p['proxy'] && $p['role'] === $row['role'] && self::proxyCovers($p, $row)) {
                return true;
            }
        }

        return false;
    }

    /** Does proxy $p sign for $row? (same role assumed) */
    public static function proxyCovers(array $p, array $row): bool
    {
        // No exclusions = "everyone else in the role" (the original behaviour, whoever they are).
        if ($p['excludes'] === []) {
            return true;
        }
        // A partial proxy only ever covers parties it can positively identify: a party with no key can never have been
        // un-ticked, so it is NOT assumed covered — a party is never silently dropped. The same goes for the signers of a
        // COMPANY in the role (their keys are derived, never offered in the picker): once the agent chooses specific people,
        // the company's own representatives keep signing for the company.
        if ($row['key'] === null || ($row['entity'] ?? false)) {
            return false;
        }

        return ! in_array($row['key'], $p['excludes'], true);
    }

    /**
     * Wizard array form of the old `filterToSigningParticipants` rule: drop deceased rows, and rows a proxy covers.
     *
     * @param  list<array<string, mixed>>  $recipients
     * @return list<array<string, mixed>>
     */
    public static function filterSigners(array $recipients): array
    {
        $norm = array_map([self::class, 'fromArray'], $recipients);

        $out = [];
        foreach ($recipients as $i => $r) {
            if (! empty($r['_is_deceased'])) {
                continue;
            }
            if (self::isCovered($norm[$i], $norm)) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * For each proxy row (by index), the rows it signs for: the others of its role it does not exclude.
     * Deceased rows are never "represented" (they are replaced, a different mechanism).
     *
     * @param  list<array<string, mixed>>  $recipients
     * @return array<int, list<int>>  proxy index => covered indexes
     */
    public static function coverageMap(array $recipients): array
    {
        $norm = array_map([self::class, 'fromArray'], $recipients);
        $map = [];
        foreach ($recipients as $pi => $p) {
            if (! $norm[$pi]['proxy'] || ! empty($p['_is_entity'])) {
                continue;
            }
            $covered = [];
            foreach ($recipients as $ri => $r) {
                if ($ri === $pi || ! empty($r['_is_deceased']) || $norm[$ri]['proxy'] || $norm[$ri]['role'] !== $norm[$pi]['role']) {
                    continue;
                }
                if (self::proxyCovers($norm[$pi], $norm[$ri])) {
                    $covered[] = $ri;
                }
            }
            $map[$pi] = $covered;
        }

        return $map;
    }

    /**
     * A proxy must sign for at least one other party. Returns a message when some proxy row covers nobody
     * (the agent un-ticked everyone, or is the only party in the role), else null.
     *
     * @param  list<array<string, mixed>>  $recipients
     */
    public static function violation(array $recipients): ?string
    {
        foreach (self::coverageMap($recipients) as $pi => $covered) {
            if ($covered === []) {
                $name = trim((string) ($recipients[$pi]['name'] ?? '')) ?: 'The proxy';

                return "{$name} is marked as a proxy but signs for nobody. Tick at least one other party they sign for, or untick Proxy.";
            }
        }

        return null;
    }

    /**
     * The wording: a party a proxy signs for reads "{name} ({ID}), herein represented by {proxy name} ({ID}) in terms of a
     * proxy". Written onto the covered row's `_party_clause_text` ONLY when the row has no clause of its own (a
     * deceased-party replacement or an entity clause is never overwritten). The proxy keeps their own plain name — they are
     * a party in their own right — and gets a signature caption saying they also sign for the others.
     *
     * Rows that are not covered, and every row of a document with no proxy, are returned untouched.
     *
     * @param  list<array<string, mixed>>  $recipients
     * @return list<array<string, mixed>>
     */
    public static function withRepresentation(array $recipients): array
    {
        $map = self::coverageMap($recipients);
        if ($map === []) {
            return $recipients;
        }

        foreach ($map as $pi => $covered) {
            $proxy = $recipients[$pi];
            $proxyLabel = self::label($proxy);
            foreach ($covered as $ri) {
                if (! empty($recipients[$ri]['_party_clause_text'])) {
                    continue;
                }
                $recipients[$ri]['_party_clause_text'] = self::label($recipients[$ri])
                    . ', herein represented by ' . $proxyLabel . ' in terms of a proxy';
                $recipients[$ri]['_represented_by_proxy'] = true;
            }
            if (empty($recipients[$pi]['_signature_caption']) && $covered !== []) {
                $names = array_map(fn (int $i) => trim((string) ($recipients[$i]['name'] ?? '')), $covered);
                $recipients[$pi]['_signature_caption'] = 'For self and as proxy for ' . self::joinNames(array_filter($names));
            }
        }

        return $recipients;
    }

    /**
     * Wording order: the proxy holder first, then the parties they sign for, then everyone else — "Peter Proxy (ID…); Paula Partner (ID…),
     * herein represented by Peter Proxy (ID…); and Sam Separate (ID…)". A proxy and the parties it covers form one group, placed where
     * the earliest of its members stood; everyone else keeps their place. No proxy → returned untouched.
     *
     * @param  list<array<string, mixed>>  $recipients
     * @return list<array<string, mixed>>
     */
    public static function orderForWording(array $recipients): array
    {
        $map = self::coverageMap($recipients);
        if ($map === []) {
            return array_values($recipients);
        }

        $coveredOf = [];
        foreach ($map as $pi => $cs) {
            foreach ($cs as $ci) {
                $coveredOf[$ci] ??= $pi;
            }
        }
        $members = [];
        $emitAt = [];
        foreach ($map as $pi => $cs) {
            $members[$pi] = array_values(array_filter($cs, fn (int $ci) => $coveredOf[$ci] === $pi));
            $emitAt[min(array_merge([$pi], $members[$pi]))] = $pi;
        }

        $out = [];
        foreach ($recipients as $i => $r) {
            if (isset($emitAt[$i])) {
                $pi = $emitAt[$i];
                $out[] = $recipients[$pi];
                foreach ($members[$pi] as $ci) {
                    $out[] = $recipients[$ci];
                }
                continue;
            }
            if (isset($coveredOf[$i]) || isset($map[$i])) {
                continue;   // already placed with its group
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * For wizard rows: the index of the proxy covering row $ri, or null. (Same rule as {@see isCovered()}, naming the proxy.)
     *
     * @param  list<array<string, mixed>>  $recipients
     */
    public static function proxyIndexFor(array $recipients, int $ri): ?int
    {
        foreach (self::coverageMap($recipients) as $pi => $covered) {
            if (in_array($ri, $covered, true)) {
                return $pi;
            }
        }

        return null;
    }

    /** "Name (ID 800101…)" — the ID is shown the way the rest of the document shows a party. */
    private static function label(array $r): string
    {
        $name = trim((string) ($r['name'] ?? ''));
        if ($name === '') {
            $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
        }
        $id = trim((string) ($r['id_number'] ?? ''));
        if ($id === '') {
            $id = trim((string) ($r['passport_number'] ?? ''));
            $idLabel = $id !== '' ? 'Passport: ' : '';
        } else {
            $idLabel = 'ID: ';
        }

        return $id !== '' ? "{$name} ({$idLabel}{$id})" : $name;
    }

    /** @param list<string> $names */
    private static function joinNames(array $names): string
    {
        $names = array_values($names);
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /** @return list<string> */
    private static function cleanList(mixed $v): array
    {
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        if (! is_array($v)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $v), fn ($s) => $s !== ''));
    }
}
