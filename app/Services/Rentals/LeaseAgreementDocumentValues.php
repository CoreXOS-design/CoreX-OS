<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\PerformanceSetting;
use App\Models\RentalLeaseTemplate;
use App\Support\AmountInWords;
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
 * Display shape follows what WebTemplateDataService::resolve() already puts in a document: money as a plain
 * decimal string (the "R" is the template's own), dates as Y-m-d, numbers bare, a month by name.
 */
class LeaseAgreementDocumentValues
{
    /**
     * @param array<string,mixed>|null $typedTerms agreement values typed on the capture screen but not yet saved
     *                                             (key => value); overrides the saved terms row when present
     * @return array<string, string> registry/agency key => the string to print
     */
    public function forLease(Lease $lease, RentalLeaseTemplate $agreement, ?array $typedTerms = null): array
    {
        $map = app(LeaseAgreementValuesReader::class)->normaliseMap((array) ($agreement->field_map ?? []));
        $registry = (array) config('lease-agreement-fields.fields', []);
        $terms = $lease->relationLoaded('agreementTerms') ? $lease->agreementTerms : $lease->agreementTerms()->first();
        $typed = $typedTerms ?? [];

        $values = [];

        // ── From the lease record ─────────────────────────────────────────────────────────
        if (isset($map['rent']) && $lease->rental_amount !== null) {
            $values['rent'] = $this->money($lease->rental_amount);
        }
        if (isset($map['start_date']) && $lease->start_date) {
            $values['start_date'] = $lease->start_date->toDateString();
        }
        if (isset($map['end_date']) && $lease->end_date) {
            $values['end_date'] = $lease->end_date->toDateString();
        }
        if (isset($map['deposit']) && $lease->deposit_amount !== null) {
            $values['deposit'] = $this->money($lease->deposit_amount);
        }

        // ── Agreement terms and schedule (typed, stored on lease_agreement_terms) ────────────
        foreach ($registry as $key => $def) {
            if (! isset($map[$key]) || ! in_array($def['group'] ?? '', ['agreement', 'schedule'], true)) {
                continue;
            }
            $raw = array_key_exists($key, $typed) ? $typed[$key] : $this->termValue($terms, $def, $key);
            $display = $this->display($raw, (string) ($def['type'] ?? 'text'));
            if ($display !== null) {
                $values[$key] = $display;
            }
        }
        // Agency-specific extras the map declares (stored in `extra`).
        foreach ($map as $key => $entry) {
            if (isset($registry[$key]) || (preg_match('/^(.*)_\d+$/', $key, $m) && ! empty($registry[$m[1]]['indexed']))) {
                continue;
            }
            $raw = array_key_exists($key, $typed) ? $typed[$key] : (($terms?->extra ?? [])[$key] ?? null);
            $display = $this->display($raw, 'text');
            if ($display !== null) {
                $values[$key] = $display;
            }
        }

        // ── Calculated (§15.12.5): the launcher seeds these itself because the resolver blanks or omits them ──
        if (isset($map['property_description']) && $lease->property) {
            $values['property_description'] = $lease->property->buildDisplayAddress();
        }
        if (isset($map['rent_in_words']) && $lease->rental_amount !== null) {
            $values['rent_in_words'] = AmountInWords::rands($lease->rental_amount);
        }
        if (isset($map['escalation_in_words']) && isset($values['escalation_percent'])) {
            $values['escalation_in_words'] = $this->percentInWords((float) $values['escalation_percent']);
        }

        $schedule = $this->schedule($lease, $map, $values, $terms, $typed);
        foreach (['agent_service_fee', 'net_to_owner'] as $key) {
            if (isset($map[$key]) && isset($schedule[$key])) {
                $values[$key] = $schedule[$key];
            }
        }

        return $values;
    }

    /**
     * The letting-commission side of the schedule: the service fee (rent × commission %, plus VAT only when the
     * AGENCY is VAT-registered, at the agency's own rate) and the net amount to the owner. Empty when the map
     * carries neither key, or when the commission % is unknown.
     *
     * @return array{agent_service_fee?: string, net_to_owner?: string}
     */
    private function schedule(Lease $lease, array $map, array $values, ?LeaseAgreementTerms $terms, array $typed): array
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

        $deduction = isset($values['other_deduction']) ? (float) $values['other_deduction'] : 0.0;

        return [
            'agent_service_fee' => $this->money($fee),
            'net_to_owner' => $this->money(round($rent - $fee - $deduction, 2)),
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

    private function display(mixed $raw, string $type): ?string
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        return match ($type) {
            'money' => is_numeric($raw) ? $this->money($raw) : trim((string) $raw),
            'percent' => is_numeric($raw) ? rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.') : trim((string) $raw),
            'month' => $this->monthName($raw),
            default => trim((string) $raw),
        };
    }

    private function monthName(mixed $raw): string
    {
        if (is_numeric($raw) && (int) $raw >= 1 && (int) $raw <= 12) {
            return \DateTime::createFromFormat('!n', (string) (int) $raw)->format('F');
        }

        return trim((string) $raw);
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
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
