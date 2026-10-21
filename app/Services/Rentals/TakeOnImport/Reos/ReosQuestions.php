<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\RentalTakeOnImportRow;

/**
 * AT-481 / rental-takeon-import.md "reOS review screen rebuild" — what is still OPEN on a lease, in the order the screen asks it.
 *
 * A lease is in exactly one of four states, worked out here and nowhere else:
 *   imported  it has been brought into CoreX
 *   failed    it cannot be imported as it stands and a button cannot fix it (the file itself is wrong, the lease is archived in CoreX,
 *             or the import tried and said why not)
 *   needs     one or more questions are open (which property, is this the same person, which agent, which suburb)
 *   ready     nothing is open — it is imported as it stands when Import is pressed
 *
 * `needs`, `needs_count` and `hard` are stored on `decisions_json` by ReosBlockers::refresh (so the list can filter on them in SQL);
 * the functions here read the stored values, and `open()` derives them from the row.
 */
final class ReosQuestions
{
    public const PROPERTY = 'property';
    public const SUBURB = 'suburb';
    public const AGENT = 'agent';
    public const NAME = 'name';        // a contact matched under a different name: same person?
    public const COMPANY = 'company';  // a company matched a person: create the company with this person as its contact person?

    public const READY = 'ready';
    public const NEEDS = 'needs';
    public const FAILED = 'failed';
    public const IMPORTED = 'imported';

    /** The blockers that are questions a button answers; every other blocker (the file's own errors, a lease archived in CoreX…) is a hard stop. */
    private const QUESTION_BLOCKERS = ['property_choice_required', 'suburb_required', 'agent_required'];

    /** @param list<array{code:string,message:string,field:?string}> $blockers */
    public static function isHard(array $blockers): bool
    {
        foreach ($blockers as $b) {
            if (! in_array($b['code'], self::QUESTION_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The open questions, in the order they are asked: property, then each person, then suburb, then agent.
     *
     * @param list<array{code:string,message:string,field:?string}> $blockers  ReosBlockers::compute($row)
     * @return list<array{code:string, party:?string, key:string}>
     */
    public static function open(RentalTakeOnImportRow $row, array $blockers): array
    {
        if ($row->status === RentalTakeOnImportRow::STATUS_ERROR || self::isHard($blockers) || $row->existing_lease_id !== null) {
            return [];   // a lease already in CoreX is updated as it stands: only its terms change, so nothing is asked
        }

        $codes = array_column($blockers, 'code');
        $out = [];
        if (in_array('property_choice_required', $codes, true)) {
            $out[] = ['code' => self::PROPERTY, 'party' => null, 'key' => self::PROPERTY];
        }

        $d = $row->decisions_json ?? [];
        foreach (['owner', 'tenant'] as $party) {
            $m = self::match($row, $party);
            if (! $m || empty($m['name_flag']) || ! self::usesMatched($d[$party] ?? [], $m) || ! empty($d[$party]['confirmed'])) {
                continue;
            }
            if ($m['name_flag'] === ReosNameCompare::PERSON_FOR_COMPANY) {
                $out[] = ['code' => self::COMPANY, 'party' => $party, 'key' => self::COMPANY . ':' . $party];
            } elseif ($m['name_flag'] === ReosNameCompare::DIFFERS) {
                $out[] = ['code' => self::NAME, 'party' => $party, 'key' => self::NAME . ':' . $party];
            }
            // SPELLING is deliberately not a question: the existing contact is used and the line carries a small marker.
        }

        if (in_array('suburb_required', $codes, true)) {
            $out[] = ['code' => self::SUBURB, 'party' => null, 'key' => self::SUBURB];
        }
        if (in_array('agent_required', $codes, true)) {
            $out[] = ['code' => self::AGENT, 'party' => null, 'key' => self::AGENT];
        }

        return $out;
    }

    public static function state(RentalTakeOnImportRow $row): string
    {
        if ($row->status === RentalTakeOnImportRow::STATUS_CONFIRMED) {
            return self::IMPORTED;
        }
        $d = $row->decisions_json ?? [];
        if ($row->status === RentalTakeOnImportRow::STATUS_ERROR || $row->import_error || ! empty($d['hard'])) {
            return self::FAILED;
        }

        return (int) ($d['needs_count'] ?? 0) > 0 ? self::NEEDS : self::READY;
    }

    /** @return list<string>  stored open-question keys, e.g. ["property", "name:tenant", "agent"] */
    public static function needs(RentalTakeOnImportRow $row): array
    {
        return array_values((array) (($row->decisions_json ?? [])['needs'] ?? []));
    }

    /** @return array<string,mixed>|null */
    public static function match(RentalTakeOnImportRow $row, string $party): ?array
    {
        return ($party === 'owner' ? $row->landlord_match_json : $row->tenant_match_json)[1] ?? null;
    }

    /** The row is still going to use the CoreX contact that matched (not a new contact, not left out, not another contact). */
    public static function usesMatched(array $partyDecision, array $match): bool
    {
        return ($partyDecision['action'] ?? null) === 'link'
            && (int) ($partyDecision['contact_id'] ?? 0) === (int) ($match['existing_contact_id'] ?? -1);
    }

    /**
     * Pure spelling differences that were auto-accepted: [party => ['reos' => name, 'corex' => name]] — shown as a small marker on the line.
     *
     * @return array<string, array{reos:string, corex:string}>
     */
    public static function spellingMarkers(RentalTakeOnImportRow $row): array
    {
        $d = $row->decisions_json ?? [];
        $out = [];
        foreach (['owner', 'tenant'] as $party) {
            $m = self::match($row, $party);
            if ($m && ($m['name_flag'] ?? null) === ReosNameCompare::SPELLING && self::usesMatched($d[$party] ?? [], $m)) {
                $out[$party] = ['reos' => (string) $m['name'], 'corex' => (string) ($m['matched_name'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * What importing this lease will do, in a few words: "Creates property", "Uses existing contacts"…
     *
     * @return string
     */
    public static function outcome(RentalTakeOnImportRow $row): string
    {
        if ($row->existing_lease_id !== null) {
            return 'Updates the lease already in CoreX';
        }
        $d = $row->decisions_json ?? [];
        $parts = [];
        $parts[] = $row->property_match_action === RentalTakeOnImportRow::ACTION_MATCH ? 'uses existing property' : 'creates property';

        $creates = 0;
        $links = 0;
        foreach (['owner', 'tenant'] as $party) {
            $m = self::match($row, $party);
            if (! $m) {
                continue;
            }
            ($d[$party]['action'] ?? null) === 'link' ? $links++ : (($d[$party]['action'] ?? null) === 'create' ? $creates++ : null);
        }
        if ($creates > 0 && $links > 0) {
            $parts[] = "creates {$creates}, uses {$links} existing " . ($links === 1 ? 'contact' : 'contacts');
        } elseif ($creates > 0) {
            $parts[] = 'creates ' . $creates . ($creates === 1 ? ' contact' : ' contacts');
        } elseif ($links > 0) {
            $parts[] = 'uses existing contacts';
        }

        return ucfirst(implode(' · ', $parts));
    }
}
