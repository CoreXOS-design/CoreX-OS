<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Support\OwnerEntityClassifier;
use Illuminate\Support\Facades\DB;

/**
 * CMA pre-check — the owner SURNAME as confirming evidence (.ai/specs/deeds-capture.md §9.12).
 *
 * CMA shows the owner NAME before the paid step. Its layout is surname-first ("PRETORIUS LEON ..."), except
 * for a compound surname where CMA pulls only the last word to the front and leaves the prefix words
 * trailing ("MERWE FRANCOIS PHILLIPUS VAN DER" = "Van Der Merwe") — the same rule the extension uses.
 *
 * Only the SURNAME is compared (first names vary: a Lodewyk is captured as Leon), against:
 *   - the contacts linked to the CoreX property as seller / owner / landlord / lessor (contact_property),
 *   - the owners captured on a tracked property (and the contact one of those is linked to),
 *   - for a tracked property already promoted to a property, that property's contacts too.
 * A company / trust owner is compared by its whole name with the legal markers (Pty, Ltd, CC, The …) removed.
 *
 * This is EVIDENCE ONLY. It never creates a candidate — a surname with no address signal matches nothing
 * (the caller only ever passes in candidates the address matcher already found). It is read-only and
 * agency-scoped on every query.
 */
class OwnerSurnameEvidence
{
    /** words CMA leaves trailing (and SA surnames begin with) — kept in step with the extension's SURNAME_PREFIX_WORDS */
    private const PREFIX_WORDS = ['van', 'der', 'den', 'du', 'de', 'le', 'la', 'von', 'ter', 'te', 'op', 'die', 'dos', 'da', 'di', 'janse'];

    /** legal / filler words stripped from a company or trust name before comparing */
    private const ENTITY_NOISE = ['the', 'pty', 'ltd', 'limited', 'proprietary', 'cc', 'inc', 'incorporated', 'npc', 'and', 'en'];

    /** contact_property roles that mean "this contact owns/sells it" */
    private const OWNER_ROLES = ['seller', 'owner', 'landlord', 'lessor'];

    /**
     * What the CMA owner cell(s) say, reduced to comparable keys. One entry per owner.
     *
     * @param  array<int, array{name?: ?string, id_type?: ?string}>  $owners
     * @return array<int, array{entity: bool, surname: ?string, key: ?string, entityKey: ?string, label: string}>
     */
    public static function fromCmaOwners(array $owners): array
    {
        $out = [];
        foreach ($owners as $o) {
            $name = trim((string) ($o['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $entity = OwnerEntityClassifier::isEntity($name, $o['id_type'] ?? null, null);
            if ($entity) {
                $ek = self::entityKey($name);
                if ($ek !== '') {
                    $out[] = ['entity' => true, 'surname' => null, 'key' => null, 'entityKey' => $ek, 'label' => $name];
                }
                continue;
            }
            $surname = self::cmaSurname($name);
            $key = $surname !== null ? self::surnameKey($surname) : null;
            if ($key !== null && $key !== '') {
                $out[] = ['entity' => false, 'surname' => $surname, 'key' => $key, 'entityKey' => null, 'label' => $surname];
            }
        }

        return $out;
    }

    /** "PRETORIUS LEON ..." → "Pretorius"; "MERWE FRANCOIS PHILLIPUS VAN DER" → "Van Der Merwe". */
    public static function cmaSurname(string $raw): ?string
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($raw)) ?: [], fn ($t) => $t !== ''));
        if ($tokens === []) {
            return null;
        }
        $prefix = [];
        for ($i = count($tokens) - 1; $i >= 1; $i--) {
            if (in_array(mb_strtolower($tokens[$i]), self::PREFIX_WORDS, true)) {
                array_unshift($prefix, $tokens[$i]);
            } else {
                break;
            }
        }
        $core = $tokens[0];
        // a leading prefix word ("VAN DER MERWE JOHAN") is already in front — keep it with the next token
        if (in_array(mb_strtolower($core), self::PREFIX_WORDS, true) && count($tokens) > 1) {
            $lead = [$core];
            $j = 1;
            while ($j < count($tokens) - 1 && in_array(mb_strtolower($tokens[$j]), self::PREFIX_WORDS, true)) {
                $lead[] = $tokens[$j];
                $j++;
            }
            return implode(' ', array_merge($lead, [$tokens[$j] ?? '']));
        }

        return implode(' ', array_merge($prefix, [$core]));
    }

    /** lowercase, accents and punctuation gone, compound prefixes joined: "Van der Merwe" = "VAN DER MERWE" = "Vandermerwe". */
    public static function surnameKey(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = self::stripAccents($s);
        $s = str_replace(["'", '’', '`'], '', $s);
        $s = (string) preg_replace('/[^a-z0-9\s]/u', ' ', $s);

        return str_replace(' ', '', trim((string) preg_replace('/\s+/', ' ', $s)));
    }

    /** the key with any compound prefix words removed ("vandermerwe" → "merwe") */
    public static function bareKey(string $key): string
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::PREFIX_WORDS as $p) {
                if (str_starts_with($key, $p) && mb_strlen($key) - mb_strlen($p) >= 3) {
                    $key = substr($key, strlen($p));
                    $changed = true;
                }
            }
        }

        return $key;
    }

    public static function entityKey(string $name): string
    {
        $s = self::stripAccents(mb_strtolower($name));
        $s = (string) preg_replace('/[^a-z0-9\s]/u', ' ', $s);
        $words = array_values(array_filter(explode(' ', $s), fn ($w) => $w !== '' && ! in_array($w, self::ENTITY_NOISE, true)));

        return implode(' ', $words);
    }

    /** two surname keys are the same person's family name (a compound prefix on one side only is tolerated) */
    public static function sameSurname(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $ba = self::bareKey($a);
        $bb = self::bareKey($b);

        return mb_strlen($ba) >= 4 && $ba === $bb;
    }

    private static function sameEntity(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $wa = explode(' ', $a);
        $wb = explode(' ', $b);
        $short = count($wa) <= count($wb) ? $wa : $wb;
        $long = $short === $wa ? $wb : $wa;

        // one name wholly inside the other, with at least two real words so "Trust" alone never matches
        return count($short) >= 2 && array_diff($short, $long) === [];
    }

    // ── candidates → evidence ──────────────────────────────────────────────

    /**
     * @param  array<int, int>  $propertyIds
     * @param  array<int, int>  $trackedIds
     * @param  array<int, array{entity: bool, surname: ?string, key: ?string, entityKey: ?string, label: string}>  $cma
     * @return array{property: array<int, string>, tracked: array<int, string>}  id => the CMA surname/owner that matched
     */
    public function confirm(int $agencyId, array $cma, array $propertyIds, array $trackedIds): array
    {
        $result = ['property' => [], 'tracked' => []];
        if ($cma === [] || ($propertyIds === [] && $trackedIds === [])) {
            return $result;
        }

        // tracked → promoted property (its contacts count too) and the tracked owners themselves
        $promoted = [];
        $trackedOwners = [];
        if ($trackedIds !== []) {
            $promoted = DB::table('tracked_properties')
                ->where('agency_id', $agencyId)->whereIn('id', $trackedIds)->whereNotNull('promoted_to_property_id')
                ->pluck('promoted_to_property_id', 'id')->map(fn ($v) => (int) $v)->all();
            $trackedOwners = DB::table('tracked_property_owners as o')
                ->leftJoin('contacts as c', function ($j) use ($agencyId) {
                    $j->on('c.id', '=', 'o.contact_id')->where('c.agency_id', '=', $agencyId)->whereNull('c.deleted_at');
                })
                ->whereIn('o.tracked_property_id', $trackedIds)
                ->get(['o.tracked_property_id', 'o.name', 'c.first_name', 'c.last_name', 'c.entity_name', 'c.contact_kind'])
                ->groupBy('tracked_property_id');
        }

        $allPropertyIds = array_values(array_unique(array_merge($propertyIds, array_values($promoted))));
        $contactsByProperty = [];
        if ($allPropertyIds !== []) {
            $contactsByProperty = DB::table('contact_property as cp')
                ->join('contacts as c', 'c.id', '=', 'cp.contact_id')
                ->whereIn('cp.property_id', $allPropertyIds)
                ->whereNull('cp.deleted_at')
                ->whereIn('cp.role', self::OWNER_ROLES)
                ->whereNull('c.deleted_at')
                ->where('c.agency_id', $agencyId)
                ->get(['cp.property_id', 'c.first_name', 'c.last_name', 'c.entity_name', 'c.contact_kind'])
                ->groupBy('property_id');
        }

        foreach ($propertyIds as $pid) {
            $hit = $this->firstHit($cma, $contactsByProperty[$pid] ?? collect(), false);
            if ($hit !== null) {
                $result['property'][$pid] = $hit;
            }
        }
        foreach ($trackedIds as $tid) {
            $hit = $this->firstHit($cma, $trackedOwners[$tid] ?? collect(), true);
            if ($hit === null && isset($promoted[$tid])) {
                $hit = $this->firstHit($cma, $contactsByProperty[$promoted[$tid]] ?? collect(), false);
            }
            if ($hit !== null) {
                $result['tracked'][$tid] = $hit;
            }
        }

        return $result;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @param  bool  $tracked  true: rows carry a free-text captured name (first-name-first or surname-first); false: contact rows
     */
    private function firstHit(array $cma, $rows, bool $tracked): ?string
    {
        foreach ($rows as $r) {
            $recordSurnames = [];
            $recordEntities = [];
            $isEntityContact = ($r->contact_kind ?? null) === 'entity' || filled($r->entity_name ?? null);
            if ($isEntityContact && filled($r->entity_name ?? null)) {
                $recordEntities[] = self::entityKey((string) $r->entity_name);
            }
            if (! $isEntityContact && filled($r->last_name ?? null)) {
                $recordSurnames[] = self::surnameKey((string) $r->last_name);
            }
            if ($tracked && filled($r->name ?? null)) {
                $name = (string) $r->name;
                if (OwnerEntityClassifier::isEntity($name, null, null)) {
                    $recordEntities[] = self::entityKey($name);
                } else {
                    // a captured name is stored in either order — every word is a candidate surname
                    foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
                        $k = self::surnameKey($w);
                        if (mb_strlen($k) >= 3 && ! in_array($k, self::PREFIX_WORDS, true)) {
                            $recordSurnames[] = $k;
                        }
                    }
                    // and the joined last two words, for a compound surname written "Van Der Merwe"
                    $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));
                    if (count($parts) >= 2) {
                        $recordSurnames[] = self::surnameKey(implode(' ', array_slice($parts, -2)));
                        if (count($parts) >= 3) {
                            $recordSurnames[] = self::surnameKey(implode(' ', array_slice($parts, -3)));
                        }
                    }
                }
            }

            foreach ($cma as $o) {
                if ($o['entity']) {
                    foreach ($recordEntities as $ek) {
                        if (self::sameEntity((string) $o['entityKey'], $ek)) {
                            return $o['label'];
                        }
                    }
                    continue;
                }
                foreach ($recordSurnames as $sk) {
                    if (self::sameSurname((string) $o['key'], $sk)) {
                        return (string) $o['surname'];
                    }
                }
            }
        }

        return null;
    }

    private static function stripAccents(string $s): string
    {
        // a fixed map — iconv //TRANSLIT depends on the server locale and returns "?" under the C locale
        return strtr(mb_strtolower($s), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ]);
    }
}
