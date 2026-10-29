<?php

namespace App\Services\Docuperfect;

/**
 * esign-lease-field-source.md - the importer's lease wording. A residential lease says the same things in much the same words
 * ("shall be personally occupied by __ Adults and not more than __ other persons", "escalation of __% (__) per annum",
 * "shall commence on __", "expire at midnight on __"), so each blank is read from the words around it and pointed at the
 * Lease data source (key `lease.<field>`), never at the mandate or property dates.
 *
 * Only ever asked about a LEASE document ({@see isLeaseDocument()}); a mandate's "commence on" is the mandate's start and keeps
 * its own rules. Every rule reads the text before the blank, the text after it, the previous blank's answer (for "(in words)"
 * companions) and, for a blank that sits alone under a heading, the text of the section above it.
 *
 * Pure text in, answer out - no database, no agency. Whether the matching named field exists is the binding step's concern.
 */
class LeasePhraseMatcher
{
    /** key => the figure it is the "in words" companion of. */
    private const WORDS_OF = [
        'rent_in_words' => 'rent',
        'deposit_in_words' => 'deposit',
        'escalation_in_words' => 'escalation_percent',
    ];

    /** key => [label, field_type, rule that says it fits] - see match(). */
    private const LABELS = [
        'adults' => ['Number of adults', 'number'],
        'max_other_persons' => ['Maximum other persons', 'number'],
        'rent' => ['Monthly rent', 'currency'],
        'rent_in_words' => ['Monthly rent in words', 'text'],
        'deposit' => ['Deposit', 'currency'],
        'deposit_in_words' => ['Deposit in words', 'text'],
        'lease_fee' => ['Lease fee', 'currency'],
        'escalation_percent' => ['Yearly escalation (%)', 'number'],
        'escalation_in_words' => ['Escalation in words', 'text'],
        'escalation_month' => ['Escalation month', 'text'],
        'start_date' => ['Lease start date', 'date'],
        'end_date' => ['Lease end date', 'date'],
        'earliest_termination_date' => ['Earliest date notice may expire', 'date'],
        'renewal_option_months' => ['Renewal period (months)', 'number'],
        'pets' => ['Pets allowed', 'text'],
        'other_conditions' => ['Other conditions', 'text'],
        'agent_service_fee' => ['Agent service fee', 'currency'],
        'net_to_owner' => ['Net amount to owner', 'currency'],
    ];

    /**
     * @param string|null $leaseFeeLabel what THIS agency calls its lease fee (Rentals settings, "Lease fee label"); a fee line
     *                                   carrying that name is the lease fee. No agency's wording lives in this class.
     */
    public function __construct(private ?string $leaseFeeLabel = null)
    {
    }

    /**
     * Whether the document is a lease: "lease" or "rental agreement" in its heading, and not one of the lease-related papers that
     * are not the lease itself (a mandate to let, a notice, an inspection report, an application).
     */
    public function isLeaseDocument(string $headText): bool
    {
        $head = $this->normal($headText);
        if (preg_match('/\b(mandate|notice|inspection|inventory|application)\b/', $head)) {
            return false;
        }

        return (bool) preg_match('/\b(lease|rental agreement|tenancy agreement)\b/', $head);
    }

    /**
     * What a blank in a lease means, or null when no lease wording fits (the importer's other rules then decide).
     *
     * @param string      $before         text just before the blank (inside its paragraph)
     * @param string      $after          text just after it
     * @param string      $clause         the whole paragraph's text
     * @param string      $aboveSection   text of the paragraph above (a blank alone under "23. OTHER CONDITIONS")
     * @param string|null $previousKey    the lease key the blank before this one (same paragraph) was given
     * @return array{label:string,field_name:string,field_type:string,source:string,confidence:string}|null
     */
    public function match(string $before, string $after, string $clause = '', string $aboveSection = '', ?string $previousKey = null): ?array
    {
        $b = $this->normal($before);
        $a = $this->normal($after);
        $c = $this->normal($clause);
        $above = $this->normal($aboveSection);

        $key = $this->key($b, $a, $c, $above, $previousKey);

        return $key === null ? null : $this->answer($key);
    }

    /** @return array{label:string,field_name:string,field_type:string,source:string,confidence:string} */
    public function answer(string $key): array
    {
        [$label, $type] = self::LABELS[$key];

        return ['label' => $label, 'field_name' => 'lease.' . $key, 'field_type' => $type, 'source' => 'lease', 'confidence' => 'high'];
    }

    private function key(string $b, string $a, string $c, string $above, ?string $previousKey): ?string
    {
        // "( … )" right after a figure: its words version - R 5 000 ( five thousand rand ), 10 % ( ten ).
        if ($previousKey !== null) {
            foreach (self::WORDS_OF as $wordsKey => $figureKey) {
                if ($previousKey === $figureKey && (preg_match('/\(\s*$/', $b) || preg_match('/in words\W*\(?\s*$/', $b))) {
                    return $wordsKey;
                }
            }
        }

        // Who lives there.
        if (preg_match('/^\W*adults?\b/', $a)) {
            return 'adults';
        }
        if (preg_match('/adults?\s+and\s+not\s+more\s+than\s*$/', $b) || (preg_match('/(more than|maximum of|up to)\s*$/', $b) && preg_match('/^\W*other\s+(persons?|occupants?)\b/', $a))) {
            return 'max_other_persons';
        }

        // Escalation (before the rent rule: "the rental shall be subject to an escalation of" is the escalation).
        if (preg_match('/escalat\w*\s+(of|by|at)\s*$/', $b) || (preg_match('/escalat/', $c) && preg_match('/^\s*%/', $a) && preg_match('/(of|by|at)\s*$/', $b))) {
            return 'escalation_percent';
        }
        if (preg_match('/(1st|first)\s+day\s+of\s*$/', $b) && preg_match('/escalat|per annum|each year|annually/', $c)) {
            return 'escalation_month';
        }

        // The money: the rental, the deposit, the fees in the service-fee breakdown.
        if (preg_match('/net\s+(amount\s+)?(payable\s+)?to\s+(the\s+)?(owner|landlord|lessor)\b/', $b)) {
            return 'net_to_owner';
        }
        if (preg_match('/service\s+fee\b/', $b)) {
            return 'agent_service_fee';
        }
        $feeLabel = $this->leaseFeeLabel !== null ? $this->normal($this->leaseFeeLabel) : '';
        if (($feeLabel !== '' && str_contains($b, $feeLabel)) || preg_match('/\b(lease|letting|placement|administration|admin)\s+fee\s*$/', $b)) {
            return 'lease_fee';
        }
        if (preg_match('/\bdeposit\b[^.]{0,60}\b(of|is|shall be|amount(ing)? to)\s*(r|zar)?\s*$/', $b)) {
            return 'deposit';
        }
        if (preg_match('/^(the\s+|total\s+)*(monthly\s+)?(rental|rent)(\s+amount)?\s*:?$/', $b)
            || preg_match('/\b(rental|rent)\s+(shall be|is|will be|of|payable is)\s*(r|zar)?\s*$/', $b)
            || preg_match('/\bmonthly\s+(rental|rent)\s*(is|of|:)?\s*(r|zar)?\s*$/', $b)) {
            return 'rent';
        }

        // The dates.
        if (preg_match('/expire\s+prior\s+to(\s+the)?\s*$/', $b)) {
            return 'earliest_termination_date';
        }
        if (preg_match('/\bcommenc(e|es|ing)\s+(on|from)(\s+the)?\s*$/', $b)) {
            return 'start_date';
        }
        if (preg_match('/midnight\s+on(\s+the)?\s*$/', $b) || preg_match('/\b(expire|terminate|end)s?\s+on(\s+the)?\s*$/', $b)) {
            return 'end_date';
        }
        if (preg_match('/^\s*months?\b/', $a) && preg_match('/(further\s+period\s+of|renew\w*[^.]{0,80})\s*$/', $b)) {
            return 'renewal_option_months';
        }

        // A blank alone under its heading / lead-in ("… the following type and number of pets on the premises:").
        $lead = $b !== '' ? $b : $above;
        if ($lead !== '') {
            if (preg_match('/other\s+conditions\s*:?\s*$/', $lead) || preg_match('/(special|additional)\s+conditions\s*:?\s*$/', $lead)) {
                return 'other_conditions';
            }
            if (preg_match('/\bpets?\b[^.]{0,80}$/', $lead)) {
                return 'pets';
            }
        }

        return null;
    }

    /** Lower-case, curly quotes straight, whitespace (tabs, line breaks) collapsed and trimmed. */
    private function normal(string $t): string
    {
        $t = str_replace(["\u{2018}", "\u{2019}", "\u{02BC}"], "'", mb_strtolower($t));

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }
}
