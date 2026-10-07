<?php

namespace App\Services\PlatformEsign\Agreement;

/**
 * Publish-time guard (spec §11.14, audit E3): the Rates tab drives the fee table cells and the calculator (through {{rate:…}} tokens), but
 * the tier breakpoints, the "quoted rate" threshold and the worked example are written as plain words in the contract. A draft whose
 * Rates tab disagrees with those words would print a contract that contradicts itself, so publishing is refused — in plain language, naming
 * the sentence and the number — until the wording and the rates agree.
 *
 * What is read from the prose: "seats A to B", "N and more", "up to N seats", "more than / over N agents", the worked example
 * "N agents on the Agency plan are a × Rx + b × Ry + c × Rz", and any literal amount that is still the OLD price of a rate that was changed.
 */
class AgreementConsistency
{
    /** A South African formatted amount: R295, R1 495, R 1495, R295.50 → float. */
    private const AMOUNT = 'R\s?(\d{1,3}(?:[ \x{00A0}]\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)';

    /**
     * @param array<string,mixed> $rates       the draft's rates
     * @param array<string,mixed> $parentRates the rates of the version the draft was copied from (to spot prices left over from it)
     * @return string[] human messages
     */
    public static function check(string $md, array $rates, array $parentRates, string $where): array
    {
        $rates = array_merge(AgreementPricing::DEFAULT_RATES, $rates);
        $parentRates = array_merge(AgreementPricing::DEFAULT_RATES, $parentRates);
        $t1 = (int) $rates['agency_t1_max'];
        $t2 = (int) $rates['agency_t2_max'];
        $team = (int) $rates['team_max_seats'];
        $quote = (int) $rates['quote_above_agents'];
        $text = preg_replace_callback('/\{\{rate:([a-z0-9_]+)\}\}/', fn ($m) => AgreementPricing::rate((float) ($rates[$m[1]] ?? 0)), $md);
        $text = preg_replace('/\{\{[^}]*\}\}/', ' ', (string) $text);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $errors = [];

        // "seats 1 to 10", "seats 11 to 20"
        if (preg_match_all('/\bseats (\d{1,4}) to (\d{1,4})\b/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $ok = ((int) $x[1] === 1 && (int) $x[2] === $t1) || ((int) $x[1] === $t1 + 1 && (int) $x[2] === $t2);
                if (!$ok) {
                    $errors[] = $where . ': the wording says “seats ' . $x[1] . ' to ' . $x[2] . '” but the Rates tab has the first tier ending at seat ' . $t1 . ' and the second at seat ' . $t2
                        . '. Change the wording (seats 1 to ' . $t1 . ', seats ' . ($t1 + 1) . ' to ' . $t2 . ') or the Rates tab so they agree.';
                }
            }
        }
        // "seats 21 and more", "21 and more"
        if (preg_match_all('/\b(\d{1,4}) and more\b/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if ((int) $x[1] !== $t2 + 1) {
                    $errors[] = $where . ': the wording says “' . $x[1] . ' and more” but, with the second tier ending at seat ' . $t2 . ', the last tier starts at seat ' . ($t2 + 1) . '. Make the wording and the Rates tab agree.';
                }
            }
        }
        // "up to 10 seats"
        if (preg_match_all('/\bup to (\d{1,4}) seats\b/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if ((int) $x[1] !== $team) {
                    $errors[] = $where . ': the wording says “up to ' . $x[1] . ' seats” but the Rates tab lets the CoreX Team plan have ' . $team . ' seats. Make them agree.';
                }
            }
        }
        // "more than 40 agents", "Over 40 agents"
        if (preg_match_all('/\b(?:more than|over|above) (\d{1,4}) agents\b/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if ((int) $x[1] !== $quote) {
                    $errors[] = $where . ': the wording says “more than ' . $x[1] . ' agents” but the Rates tab shows the quoted-rate notice above ' . $quote . ' agents. Make them agree.';
                }
            }
        }
        // The worked example: "25 agents on the Agency plan are 10 × R295 + 10 × R250 + 5 × R195"
        if (preg_match_all('/(\d{1,4}) agents on the Agency plan are ((?:\d{1,4} ?[×x] ?' . self::AMOUNT . '(?: ?\+ ?)?)+)/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                preg_match_all('/(\d{1,4}) ?[×x] ?' . self::AMOUNT . '/iu', $x[2], $terms, PREG_SET_ORDER);
                $written = array_map(fn ($t) => [(int) $t[1], round((float) preg_replace('/[ \x{00A0}]/u', '', $t[2]), 2)], $terms);
                $calc = AgreementPricing::compute('agency', (int) $x[1], 0, 0.0, $rates);
                $expected = [];
                foreach (['agency_t1', 'agency_t2', 'agency_t3'] as $line) {
                    if ($calc['lines'][$line]['qty'] > 0) {
                        $expected[] = [$calc['lines'][$line]['qty'], round((float) $calc['lines'][$line]['rate'], 2)];
                    }
                }
                if ($written !== $expected) {
                    $says = implode(' + ', array_map(fn ($e) => $e[0] . ' × ' . AgreementPricing::rate($e[1]), $expected));
                    $errors[] = $where . ': the worked example (“' . $x[1] . ' agents on the Agency plan”) does not match the Rates tab — with the current rates it should read ' . $says . '. Update the example.';
                }
            }
        }
        // A price that is still the OLD value of a rate that was changed on the Rates tab.
        $now = array_map(fn ($k) => round((float) $rates[$k], 2), array_keys(AgreementPricing::DEFAULT_RATES));
        foreach (AgreementVersions::RATE_FIELDS as $key => [$label, $kind]) {
            if ($kind !== 'money' || round((float) $rates[$key], 2) === round((float) $parentRates[$key], 2)) {
                continue;
            }
            $old = round((float) $parentRates[$key], 2);
            if (in_array($old, $now, true)) {
                continue; // the old amount is somebody else's current price — cannot tell, so do not guess
            }
            if (preg_match_all('/' . self::AMOUNT . '/u', $text, $am)) {
                foreach ($am[1] as $s) {
                    if (round((float) preg_replace('/[ \x{00A0}]/u', '', $s), 2) === $old) {
                        $errors[] = $where . ': the wording still says ' . AgreementPricing::rate($old) . ', which was the price for “' . $label . '” before it was changed to ' . AgreementPricing::rate((float) $rates[$key]) . ' on the Rates tab. Update the wording (or use the price marker) so the contract does not contradict itself.';
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }
}
