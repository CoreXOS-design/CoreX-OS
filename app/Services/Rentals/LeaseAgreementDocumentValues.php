<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\PerformanceSetting;
use App\Models\RentalLeaseTemplate;
use App\Support\AmountInWords;
use App\Support\Money\Zar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * .ai/specs/leases.md §15.4 step 4 / §15.8.3 / §15.12.5 (Build L3a) — the values CoreX writes INTO the agency's
 * own lease agreement when it prepares a lease for signing, worked out from the lease record, its agreement
 * terms and the calculations the map asks for. One class so the launcher (which seeds the document) and the
 * later change check (L3c, which compares what the document prints) can never disagree about what a value is.
 *
 * Only keys the agency's own field map carries are returned — a lease agreement with no fee schedule never gets
 * a service fee, an "other deduction" or a net amount (§15.12.5, multi-agency). Contact-side keys (names,
 * addresses, ID numbers) are NOT produced here: the e-sign wizard already resolves those from the recipients.
 *
 * DISPLAY (one place, {@see present()}): every key is worked out RAW and turned into the text a person reads only at
 * the very end, by its registry type — money as the document money format (App\Support\Money\Zar, "R 8,500.00"), a
 * date the way CoreX documents print dates ("1 November 2026", the 'j F Y' of WebTemplateDataService's lease/mandate
 * dates), a percentage with its "%", a month by name, a number bare. The stored lease record stays raw.
 *
 * A template that already prints the unit itself must not get it twice, so the launcher passes a per-key
 * $presentation ({@see LeaseSeedPresentation}): 'bare' = the template adds the "R" / "%" (the number alone is
 * seeded), 'iso' = the field is a native date input (Y-m-d, which the input needs to show anything at all).
 */
class LeaseAgreementDocumentValues
{
    /**
     * @param array<string,mixed>|null $typedTerms agreement values typed on the capture screen but not yet saved
     *                                             (key => value); overrides the saved terms row when present
     * @param array<string,string> $presentation   key => 'bare' | 'iso' — how THIS template prints that key ({@see LeaseSeedPresentation})
     * @return array<string, string> registry/agency key => the string to print
     */
    public function forLease(Lease $lease, RentalLeaseTemplate $agreement, ?array $typedTerms = null, array $presentation = []): array
    {
        $map = app(LeaseAgreementValuesReader::class)->normaliseMap((array) ($agreement->field_map ?? []));
        $registry = (array) config('lease-agreement-fields.fields', []);
        $terms = $lease->relationLoaded('agreementTerms') ? $lease->agreementTerms : $lease->agreementTerms()->first();
        $typed = $typedTerms ?? [];

        // key => [raw value, registry type]. Nothing in here is formatted: a calculation below must never read back
        // a string it (or this method) already dressed up for the page.
        $raw = [];

        // ── From the lease record ─────────────────────────────────────────────────────────
        if (isset($map['rent']) && $lease->rental_amount !== null) {
            $raw['rent'] = [$lease->rental_amount, 'money'];
        }
        if (isset($map['start_date']) && $lease->start_date) {
            $raw['start_date'] = [$lease->start_date, 'date'];
        }
        if (isset($map['end_date']) && $lease->end_date) {
            $raw['end_date'] = [$lease->end_date, 'date'];
        }
        if (isset($map['deposit']) && $lease->deposit_amount !== null) {
            $raw['deposit'] = [$lease->deposit_amount, 'money'];
        }
        // leases.md "Lease fee": null = not on record (the box stays empty), 0 = on record as not charged (prints R 0.00, like a zero deposit).
        if (isset($map['lease_fee']) && $lease->lease_fee !== null) {
            $raw['lease_fee'] = [$lease->lease_fee, 'money'];
        }

        // ── Agreement terms and schedule (typed, stored on lease_agreement_terms) ────────────
        foreach ($registry as $key => $def) {
            if (! isset($map[$key]) || ! in_array($def['group'] ?? '', ['agreement', 'notice', 'schedule'], true)) {
                continue;
            }
            $value = array_key_exists($key, $typed) ? $typed[$key] : $this->termValue($terms, $def, $key);
            if (! $this->isBlank($value)) {
                $raw[$key] = [$value, (string) ($def['type'] ?? 'text')];
            }
        }
        // Agency-specific extras the map declares (stored in `extra`).
        foreach ($map as $key => $entry) {
            if (isset($registry[$key]) || (preg_match('/^(.*)_\d+$/', $key, $m) && ! empty($registry[$m[1]]['indexed']))) {
                continue;
            }
            $value = array_key_exists($key, $typed) ? $typed[$key] : (($terms?->extra ?? [])[$key] ?? null);
            if (! $this->isBlank($value)) {
                $raw[$key] = [$value, 'text'];
            }
        }

        // ── Calculated (§15.12.5): the launcher seeds these itself because the resolver blanks or omits them ──
        if (isset($map['property_description']) && $lease->property) {
            $raw['property_description'] = [$lease->property->buildDisplayAddress(), 'text'];
        }
        if (isset($map['rent_in_words']) && $lease->rental_amount !== null) {
            $raw['rent_in_words'] = [AmountInWords::rands($lease->rental_amount), 'text'];
        }
        if (isset($map['escalation_in_words']) && isset($raw['escalation_percent']) && is_numeric($raw['escalation_percent'][0])) {
            $raw['escalation_in_words'] = [$this->percentInWords((float) $raw['escalation_percent'][0]), 'text'];
        }

        $schedule = $this->schedule($lease, $map, $raw, $terms, $typed);
        foreach (['agent_service_fee', 'net_to_owner'] as $key) {
            if (isset($map[$key]) && isset($schedule[$key])) {
                $raw[$key] = [$schedule[$key], 'money'];
            }
        }

        $values = [];
        foreach ($raw as $key => [$value, $type]) {
            $display = $this->present($value, $type, $presentation[$key] ?? null);
            if ($display !== null) {
                $values[$key] = $display;
            }
        }

        return $values;
    }

    /**
     * The letting-commission side of the schedule: the service fee (rent × commission %, plus VAT only when the
     * AGENCY is VAT-registered, at the agency's own rate) and the net amount to the owner. Empty when the map
     * carries neither key, or when the commission % is unknown. Amounts come back RAW (floats) — {@see present()}
     * dresses them.
     *
     * @param array<string,array{0:mixed,1:string}> $raw
     * @return array{agent_service_fee?: float, net_to_owner?: float}
     */
    private function schedule(Lease $lease, array $map, array $raw, ?LeaseAgreementTerms $terms, array $typed): array
    {
        if (! isset($map['agent_service_fee']) && ! isset($map['net_to_owner'])) {
            return [];
        }

        $commission = $this->commissionPercent($lease, $terms, $typed);
        if ($commission === null || $lease->rental_amount === null) {
            return [];
        }

        $rent = (float) $lease->rental_amount;
        $vatRate = $this->vatRateFor((int) $lease->agency_id);
        $fee = round($rent * $commission / 100 * (1 + $vatRate / 100), 2);

        $deduction = isset($raw['other_deduction']) && is_numeric($raw['other_deduction'][0]) ? (float) $raw['other_deduction'][0] : 0.0;

        return [
            'agent_service_fee' => $fee,
            'net_to_owner' => round($rent - $fee - $deduction, 2),
        ];
    }

    /**
     * The letting commission % — what was typed on the capture screen, else what the lease's terms hold, else the
     * property's own. Null when none is known (the launcher then reports it as missing).
     */
    public function commissionPercent(Lease $lease, ?LeaseAgreementTerms $terms = null, array $typed = []): ?float
    {
        $terms ??= $lease->agreementTerms()->first();
        foreach ([$typed['commission_percent'] ?? null, ($terms?->extra ?? [])['commission_percent'] ?? null, $lease->property?->commission_percent] as $candidate) {
            if ($candidate !== null && $candidate !== '' && is_numeric($candidate) && (float) $candidate >= 0) {
                return (float) $candidate;
            }
        }

        return null;
    }

    /** The VAT % added to the service fee: the agency's own rate when it is VAT-registered, else none. */
    public function vatRateFor(int $agencyId): float
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        if (! $agency || ! $agency->vat_registered) {
            return 0.0;
        }

        return (float) PerformanceSetting::get('vat_rate', 15, $agencyId);
    }

    /** "seven point five" — a percentage read out; whole numbers have no "point". */
    public function percentInWords(float $percent): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
            'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

        $whole = (int) floor(abs($percent));
        $words = match (true) {
            $whole < 20 => $ones[$whole],
            $whole < 100 => $tens[intdiv($whole, 10)] . ($whole % 10 ? '-' . $ones[$whole % 10] : ''),
            $whole === 100 => 'one hundred',
            default => (string) $whole,
        };

        $fraction = rtrim(substr(number_format(abs($percent), 2, '.', ''), -2), '0');
        if ($fraction !== '') {
            $words .= ' point ' . implode(' ', array_map(fn ($d) => $ones[(int) $d], str_split($fraction)));
        }

        return $words;
    }

    private function termValue(?LeaseAgreementTerms $terms, array $def, string $key): mixed
    {
        if (! $terms) {
            return null;
        }

        return ($def['column'] ?? null) !== null ? $terms->{$def['column']} : (($terms->extra ?? [])[$key] ?? null);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * THE one place a seeded lease value is turned into what a person reads — by its registry type, the way CoreX's
     * other documents show the same kinds of value:
     *   money   → App\Support\Money\Zar ("R 8,500.00", the AT-177 document/money format)
     *   date    → "1 November 2026" ('j F Y', WebTemplateDataService's lease and mandate dates; the change check reads it back)
     *   percent → "10%" (7.5 → "7.5%")
     *   month   → "March"; anything else → trimmed text
     * $how = 'bare' leaves the "R" / "%" off (this template prints its own — never twice); 'iso' keeps a date as
     * Y-m-d (the field is a native date input, which shows nothing for any other shape). A value that is not the
     * type it claims (words typed in a money box) is kept exactly as typed — never invented, never blanked.
     */
    private function present(mixed $value, string $type, ?string $how = null): ?string
    {
        if ($this->isBlank($value)) {
            return null;
        }

        return match ($type) {
            'money' => $this->presentMoney($value, $how === 'bare'),
            'percent' => $this->presentPercent($value, $how === 'bare'),
            'date' => $this->presentDate($value, $how === 'iso'),
            'month' => $this->monthName($value),
            default => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : trim((string) $value),
        };
    }

    /** {@see present()} for callers outside this class (the lease source of the template builder dresses a proposed figure the same way). */
    public function dress(mixed $value, string $type, ?string $how = null): ?string
    {
        return $this->present($value, $type, $how);
    }

    private function presentMoney(mixed $value, bool $bare): string
    {
        if (! is_numeric($value)) {
            return trim((string) $value);
        }

        return $bare ? number_format((float) $value, 2, '.', ',') : Zar::format((float) $value);
    }

    private function presentPercent(mixed $value, bool $bare): string
    {
        if (! is_numeric($value)) {
            return trim((string) $value);
        }

        $number = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return $bare ? $number : $number . '%';
    }

    private function presentDate(mixed $value, bool $iso): string
    {
        try {
            $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse(trim((string) $value));
        } catch (\Throwable) {
            return trim((string) $value);
        }

        return $date->format($iso ? 'Y-m-d' : 'j F Y');
    }

    private function monthName(mixed $raw): string
    {
        if (is_numeric($raw) && (int) $raw >= 1 && (int) $raw <= 12) {
            return \DateTime::createFromFormat('!n', (string) (int) $raw)->format('F');
        }

        return trim((string) $raw);
    }

    /**
     * The contacts a lease's agreement is signed by, in the engine's fixed order (R4): tenants (primary first),
     * then landlords. Entities are included — they sign through their representatives, which the engine expands.
     *
     * @return Collection<int, array{role: string, contact: Contact}>
     */
    public function signersFor(Lease $lease): Collection
    {
        $rows = collect();
        foreach ($lease->tenants()->with('contact')->orderByDesc('is_primary')->orderBy('id')->get() as $tenant) {
            if ($tenant->contact) {
                $rows->push(['role' => 'tenant', 'contact' => $tenant->contact]);
            }
        }
        foreach ($lease->landlordContacts() as $landlord) {
            $rows->push(['role' => 'landlord', 'contact' => $landlord]);
        }

        return $rows;
    }
}
