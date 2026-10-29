<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseAgreementDocumentValues;
use App\Services\Rentals\LeaseSeedPresentation;
use App\Support\AmountInWords;
use Illuminate\Support\Facades\DB;

/**
 * The "Lease" data source of the template builder and the e-sign engine (esign-lease-field-source.md).
 *
 * The lease's own fields were already catalogued for the rental agreement set-up (config/lease-agreement-fields.php) and already
 * worked out and dressed by {@see LeaseAgreementDocumentValues}; what was missing is a way for a CDS template to point a blank at
 * one of them. This class is that bridge, and the only place that knows which lease fields a template can pick:
 *   - catalogue()          the curated list (key => label, type) the builder offers under "Lease",
 *   - ensureNamedFields()  the named-field rows (source_type 'lease') the builder's mappings point at,
 *   - valuesForStepData()  what a lease field prints at signing, worked out from the lease (never typed again).
 *
 * Nothing here names an agency: the labels are neutral and the figures come from the lease and its agreement terms.
 */
class LeaseFieldSource
{
    public const SOURCE = 'lease';

    /**
     * Lease fields a template may pick. Taken from the rental agreement registry (so the lease screen, the agreement set-up and a
     * template can never disagree about what a field is) plus the words versions the registry does not carry.
     * key => [label, type]. The party fields are not here: names, addresses and ID numbers come from the contacts.
     */
    private const EXTRA = [
        'deposit_in_words' => ['Deposit in words', 'text'],
    ];

    /** Registry keys offered under "Lease", in the order a person reads a lease. */
    private const KEYS = [
        'rent', 'rent_in_words', 'deposit', 'deposit_in_words', 'lease_fee',
        'start_date', 'end_date',
        'adults', 'max_other_persons', 'pets',
        'escalation_percent', 'escalation_in_words', 'escalation_month',
        'earliest_termination_date', 'renewal_option_months',
        'electricity_arrangement', 'other_conditions',
        'agent_service_fee', 'net_to_owner',
    ];

    /** @return array<string, array{label: string, type: string}> */
    public static function catalogue(): array
    {
        $registry = (array) config('lease-agreement-fields.fields', []);
        $out = [];
        foreach (self::KEYS as $key) {
            if (isset(self::EXTRA[$key])) {
                $out[$key] = ['label' => self::EXTRA[$key][0], 'type' => self::EXTRA[$key][1]];
            } elseif (isset($registry[$key])) {
                $out[$key] = ['label' => (string) $registry[$key]['label'], 'type' => (string) ($registry[$key]['type'] ?? 'text')];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalogue());
    }

    /**
     * The named-field rows a mapping points at. Idempotent (find-or-update on the source triple), safe on every deploy; a row a person
     * archived stays archived.
     */
    public static function ensureNamedFields(): int
    {
        $now = now();
        $sort = 0;
        $written = 0;
        foreach (self::catalogue() as $key => $def) {
            $sort++;
            $label = 'Lease - ' . $def['label'];
            $type = match ($def['type']) {
                'money', 'percent', 'integer' => 'number',
                'date' => 'date',
                default => 'text',
            };
            $existing = DB::table('docuperfect_named_fields')->where('source_type', self::SOURCE)->where('source_column', $key)->first();
            if ($existing) {
                if ($existing->deleted_at === null && ($existing->name !== $label || $existing->field_type !== $type)) {
                    DB::table('docuperfect_named_fields')->where('id', $existing->id)->update(['name' => $label, 'field_type' => $type, 'updated_at' => $now]);
                }

                continue;
            }
            DB::table('docuperfect_named_fields')->insert([
                'name' => $label, 'field_type' => $type, 'sort_order' => 900 + $sort,
                'source_type' => self::SOURCE, 'source_column' => $key, 'source_contact_type' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * Every lease field's printed value for a document being signed: key => text. The lease is read fresh (its id travels in the
     * flow's step data); where the proposed rent, dates or deposit are in the step data's details they win, so a not-yet-saved term
     * prints what the agent is about to sign. A flow with no lease still gets rent, dates and deposit from its details.
     *
     * @param array<string,mixed> $stepData
     * @return array<string,string>
     */
    public function valuesForStepData(array $stepData, ?Template $template = null): array
    {
        $details = (array) ($stepData['details'] ?? []);
        $values = [];

        $leaseId = (int) ($stepData['lease_id'] ?? 0);
        $lease = $leaseId > 0 ? Lease::withoutGlobalScopes()->find($leaseId) : null;
        $docs = app(LeaseAgreementDocumentValues::class);

        $map = [];
        foreach (self::keys() as $key) {
            $map[$key] = ['field' => 'lease.' . $key];
        }
        $presentation = $template ? app(LeaseSeedPresentation::class)->for($template, $map, []) : [];

        if ($lease) {
            $agreement = new RentalLeaseTemplate(['field_map' => $map]);
            $values = $docs->forLease($lease, $agreement, null, $presentation);
        }

        // Proposed figures in the step data win over the saved lease (same rule the launcher uses when it seeds Fill & review).
        $rent = $details['monthly_rental'] ?? null;
        $deposit = $details['deposit'] ?? null;
        $start = $details['lease_start'] ?? null;
        $end = $details['lease_end'] ?? null;
        if ($this->has($rent)) {
            $values['rent'] = (string) $docs->dress($rent, 'money', $presentation['rent'] ?? null);
            $values['rent_in_words'] = AmountInWords::rands($rent);
        }
        if ($this->has($deposit)) {
            $values['deposit'] = (string) $docs->dress($deposit, 'money', $presentation['deposit'] ?? null);
        }
        if ($this->has($start)) {
            $values['start_date'] = (string) $docs->dress($start, 'date', $presentation['start_date'] ?? null);
        }
        if ($this->has($end)) {
            $values['end_date'] = (string) $docs->dress($end, 'date', $presentation['end_date'] ?? null);
        }

        // Deposit in words: from the deposit being signed (the details), else the lease.
        $depositRaw = $this->has($deposit) ? $deposit : $lease?->deposit_amount;
        if ($this->has($depositRaw) && is_numeric($depositRaw)) {
            $values['deposit_in_words'] = AmountInWords::rands($depositRaw);
        }

        return $values;
    }

    /** One lease field's printed value, '' when the lease does not hold it. */
    public function valueFor(string $key, array $stepData, ?Template $template = null): string
    {
        return (string) ($this->valuesForStepData($stepData, $template)[$key] ?? '');
    }

    private function has(mixed $v): bool
    {
        return $v !== null && trim((string) $v) !== '';
    }
}
