<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Services\Address\AddressParser;

/**
 * AT-479 / rental-takeon-import.md R8 — fills the tenant wallet reference of Tenancy Schedule rows from the optional
 * Rent Roll. A Rent Roll row matches a schedule row on normalised property address + normalised tenant name; exactly
 * one Rent Roll row may match (none = no wallet, two = ignored with a warning, never guessed).
 */
class ReosRentRollMerger
{
    public function __construct(private readonly AddressParser $parser = new AddressParser())
    {
    }

    /**
     * @param list<array{row_number:int, payload:array<string,mixed>, errors:list<string>, warnings:list<string>}> $scheduleRows  edited in place
     * @param list<array{row_number:int, address:?string, tenant:?string, wallet:?string}> $rentRoll
     * @return array{matched:int, unmatched_roll_rows:int, ambiguous:int}
     */
    public function merge(array &$scheduleRows, array $rentRoll): array
    {
        // schedule row index => keys
        $scheduleKeys = [];
        foreach ($scheduleRows as $i => $row) {
            $scheduleKeys[$i] = $this->keys($row['payload']['address'] ?? null, $row['payload']['tenant_name'] ?? null);
        }

        $matched = 0;
        $unmatchedRoll = 0;
        $ambiguous = 0;
        $walletsBySchedule = []; // schedule index => list of wallets

        foreach ($rentRoll as $roll) {
            if ($roll['wallet'] === null) {
                continue;
            }
            $rollKeys = $this->keys($roll['address'], $roll['tenant']);
            $hits = [];
            foreach ($scheduleKeys as $i => $keys) {
                if ($this->same($rollKeys, $keys)) {
                    $hits[] = $i;
                }
            }
            if ($hits === []) {
                $unmatchedRoll++;
                continue;
            }
            foreach ($hits as $i) {
                $walletsBySchedule[$i][] = $roll['wallet'];
            }
        }

        foreach ($walletsBySchedule as $i => $wallets) {
            $distinct = array_values(array_unique($wallets));
            if (count($distinct) > 1) {
                $ambiguous++;
                $scheduleRows[$i]['warnings'][] = 'More than one Rent Roll row matches this lease, so no tenant wallet was filled in — enter it by hand.';
                continue;
            }
            $scheduleRows[$i]['payload']['tenant_wallet_ref'] = $distinct[0];
            $matched++;
        }

        return ['matched' => $matched, 'unmatched_roll_rows' => $unmatchedRoll, 'ambiguous' => $ambiguous];
    }

    /** @return array{text:string, parsed:string, tenant:string} */
    private function keys(?string $address, ?string $tenant): array
    {
        $text = trim((string) preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower((string) $address)));
        $parsedKey = '';
        if ($address !== null && trim($address) !== '') {
            $p = $this->parser->parse(['address' => $address]);
            if (($p['status'] ?? null) === 'parsed') {
                $parsedKey = implode('|', [$p['street_number'] ?? '', $p['street_core'] ?? '', $p['unit_number'] ?? '', mb_strtolower((string) ($p['complex_name'] ?? ''))]);
            }
        }

        return ['text' => $text, 'parsed' => $parsedKey, 'tenant' => ReosContactNames::key($tenant)];
    }

    /** @param array{text:string,parsed:string,tenant:string} $a */
    private function same(array $a, array $b): bool
    {
        if ($a['tenant'] === '' || $a['tenant'] !== $b['tenant']) {
            return false;
        }

        return ($a['text'] !== '' && $a['text'] === $b['text'])
            || ($a['parsed'] !== '' && $a['parsed'] === $b['parsed']);
    }
}
