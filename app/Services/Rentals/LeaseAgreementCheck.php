<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use Illuminate\Support\Str;

/**
 * .ai/specs/leases.md §15.8 (Build L3c) — compares what the lease agreement PRINTS with the lease record, so a value
 * changed in e-sign is never absorbed silently (R6).
 *
 * It does NOT listen for edits (§15.8.1). Whichever route an edit took — an agent field save, a signer's field save,
 * a text amend, a struck-and-reworded field — the end state is the same: a printed value that no longer matches the
 * lease. So at the moments that matter the document's printed values are read back through the agency's own field map
 * (LeaseAgreementValuesReader) and compared, key by key, with the lease side.
 *
 * Every key the agency's map carries becomes one ROW, in the registry's order:
 *   agree          the document and the lease say the same (or both say nothing)
 *   differs        a value the agent may accept from the document — rent, dates, deposit, the agreement terms
 *   blocked        a different PERSON (name, ID, address — a contact's own details). Never acceptable: a change of
 *                  tenant is a new lease (R7); the agent corrects the contact record and re-checks
 *   cannot_verify  the document prints nothing where the lease has a value, or text where a number or date belongs.
 *                  The agent types what they can plainly read (it is logged as "entered by the agent")
 *   informational  a calculated value (rent in words, fee, net) that no longer matches the figures. Never blocks and
 *                  never opens the confirm screen on its own — there is no lease column to update
 *
 * The check is read-only. It writes nothing: the only way a document value reaches the lease is the agent's explicit
 * confirmation (LeaseAgreementConfirmService), which logs each change old → new.
 */
class LeaseAgreementCheck
{
    public const STATE_AGREE = 'agree';
    public const STATE_DIFFERS = 'differs';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_CANNOT_VERIFY = 'cannot_verify';
    public const STATE_INFORMATIONAL = 'informational';

    /** Words that may sit beside a name, an ID or an address in a printed party field without being a different person. */
    private const NOISE = [
        'and', 'or', 'en', 'mr', 'mrs', 'ms', 'miss', 'mnr', 'mev', 'dr', 'prof', 'id', 'passport', 'no', 'nr',
        'number', 'reg', 'registration', 'rsa', 'south', 'african', 'identity',
    ];

    private const CALCULATED = ['property_description', 'rent_in_words', 'escalation_in_words', 'agent_service_fee', 'net_to_owner'];

    /**
     * @param  array<string,string>  $entered  key => what the agent typed for a value the document does not show
     *                                         clearly (a "cannot verify" row). Treated as the document's value.
     * @return array{
     *     applicable: bool,
     *     document_id: ?int,
     *     has_differences: bool,
     *     needs_confirmation: bool,
     *     blocked: bool,
     *     differences: array<int,array<string,mixed>>,
     *     cannot_verify: array<int,array<string,mixed>>,
     *     informational: array<int,array<string,mixed>>,
     *     rows: array<int,array<string,mixed>>,
     *     text_changes: array<int,array<string,mixed>>,
     *     fingerprint: ?string
     * }
     */
    public function verdict(Lease $lease, array $entered = []): array
    {
        $document = $this->documentFor($lease);
        $map = $document ? app(LeaseAgreementHarvest::class)->mapFor($lease, $document) : [];
        if (! $document || $map === []) {
            // A lease with no agreement document, or one made from a document nobody mapped: nothing to compare with
            // and never a guess (§15.7.3). The fallback documents of §15.16 behave exactly as before.
            return $this->result(false, null, [], [], null);
        }

        $reader = app(LeaseAgreementValuesReader::class);
        $normalised = $reader->normaliseMap($map);
        $reads = $reader->read($document, $map);
        $terms = $lease->agreementTerms()->first();
        $registry = (array) config('lease-agreement-fields.fields', []);

        $rows = [];
        $accepted = ['lease' => [], 'terms' => []];

        foreach ($normalised as $key => $entry) {
            $def = $this->definitionFor($key, $registry);
            if ($def !== null && ($def['side'] ?? null) === 'calculated') {
                continue; // after the figures are known (below)
            }

            $read = $reads[$key] ?? ['printed' => null, 'parsed' => null];
            $typedByAgent = isset($entered[$key]) && trim((string) $entered[$key]) !== '';
            $printed = $typedByAgent ? LeaseAgreementValuesReader::collapseText((string) $entered[$key]) : $read['printed'];
            $type = (string) ($def['type'] ?? 'text');
            $parsed = $typedByAgent ? $reader->parse((string) $printed, $type) : $read['parsed'];
            $label = $entry['label'] ?: (string) ($def['label'] ?? Str::headline($key));
            $group = (string) ($def['group'] ?? 'agreement');

            if (($def['side'] ?? null) === 'contact') {
                $row = $this->personRow($lease, $key, $label, $printed, $typedByAgent);
            } else {
                $row = $this->valueRow($lease, $terms, $key, $def, $label, $group, $type, $printed, $parsed, $typedByAgent);
            }
            if ($row === null) {
                continue;
            }

            if ($row['state'] === self::STATE_DIFFERS && $row['target'] !== null) {
                if ($row['target']['type'] === 'lease') {
                    $accepted['lease'][$row['target']['column']] = $row['agreement_value'];
                } else {
                    $accepted['terms'][$key] = $row['agreement_value'];
                }
            }
            $rows[$key] = $row;
        }

        // Calculated values are recomputed from the figures being confirmed (§15.8.3), so a rent accepted from the
        // document is judged against ITS words and fee, not the old rent's.
        foreach ($normalised as $key => $entry) {
            if (! in_array($key, self::CALCULATED, true)) {
                continue;
            }
            $row = $this->calculatedRow($lease, $map, $key, $entry['label'] ?: (string) ($registry[$key]['label'] ?? Str::headline($key)), $reads[$key]['printed'] ?? null, $accepted, $entered);
            if ($row !== null) {
                $rows[$key] = $row;
            }
        }

        $ordered = $this->inRegistryOrder($rows, array_keys($registry));

        return $this->result(true, $document->id, $ordered, $this->textChanges($document), $this->fingerprint($normalised, $reads));
    }

    // ── Rows ─────────────────────────────────────────────────────────────────────────────

    /**
     * rent, dates, deposit (the lease record) and the agreement terms / agency extras (lease_agreement_terms).
     *
     * @return array<string,mixed>
     */
    private function valueRow(Lease $lease, $terms, string $key, ?array $def, string $label, string $group, string $type, ?string $printed, mixed $parsed, bool $typedByAgent): array
    {
        $side = (string) ($def['side'] ?? 'terms');
        $column = $def['column'] ?? null;
        $accept = (bool) ($def['accept'] ?? true);

        if ($side === 'lease') {
            $leaseValue = $column === 'end_date' && $lease->is_month_to_month ? null : $lease->{$column};
            $target = ['type' => 'lease', 'column' => $column];
        } elseif ($column) {
            $leaseValue = $terms?->{$column};
            $target = ['type' => 'terms', 'column' => $column];
        } else {
            $leaseValue = ($terms?->extra ?? [])[$key] ?? null;
            $target = ['type' => 'extra', 'column' => $key];
        }

        $leaseCmp = $this->norm($leaseValue, $type);
        $docCmp = $this->norm($parsed, $type);
        $printedEmpty = $printed === null || $printed === '';

        if ($printedEmpty) {
            $state = $leaseCmp === null ? self::STATE_AGREE : self::STATE_CANNOT_VERIFY;
        } elseif ($parsed === null || $docCmp === null) {
            // A month-to-month lease whose end-date field says so in words is in agreement with its lease.
            $state = ($column === 'end_date' && $lease->is_month_to_month && stripos((string) $printed, 'month') !== false)
                ? self::STATE_AGREE
                : self::STATE_CANNOT_VERIFY;
        } elseif ($leaseCmp === null) {
            $state = self::STATE_DIFFERS;
        } else {
            $state = $this->equal($leaseCmp, $docCmp) ? self::STATE_AGREE : self::STATE_DIFFERS;
        }

        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'kind' => 'value',
            'state' => $state,
            'lease' => $this->show($leaseValue, $type),
            'agreement' => $printedEmpty ? null : ($parsed !== null ? $this->show($parsed, $type) : $printed),
            'printed' => $printed,
            'agreement_value' => $parsed,
            'acceptable' => $accept,
            'entered' => $typedByAgent,
            'target' => $target,
            'type' => $type,
        ];
    }

    /**
     * A contact's own details printed in the agreement: names, IDs and addresses. Compared case/spacing/punctuation
     * insensitively and word-order insensitively (the agreement may print "Smith, John"), never accepted from the
     * document (R7). Null when the contact side holds nothing to compare with.
     *
     * @return array<string,mixed>|null
     */
    private function personRow(Lease $lease, string $key, string $label, ?string $printed, bool $typedByAgent): ?array
    {
        $expected = $this->expectedParty($lease, $key);
        if ($expected === null || $expected['parts'] === []) {
            return null;
        }

        $leaseDisplay = implode(' and ', $expected['parts']);
        $printedEmpty = $printed === null || $printed === '';
        $state = $printedEmpty
            ? self::STATE_CANNOT_VERIFY
            : ($this->partiesMatch($printed, $expected['parts'], $expected['kind'], $expected['entity']) ? self::STATE_AGREE : self::STATE_BLOCKED);

        return [
            'key' => $key,
            'label' => $label,
            'group' => 'parties',
            'kind' => 'person',
            'state' => $state,
            'lease' => $leaseDisplay,
            'agreement' => $printedEmpty ? null : $printed,
            'printed' => $printed,
            'agreement_value' => $printed,
            'acceptable' => false,
            'entered' => $typedByAgent,
            'target' => null,
            'type' => 'text',
        ];
    }

    /**
     * rent in words, escalation in words, property description, service fee, net to owner — recomputed with the same
     * class that wrote them into the document (LeaseAgreementDocumentValues), from the lease as it WOULD be once the
     * agent has accepted the differences. Only ever informational.
     *
     * @param  array<string,mixed>  $map
     * @param  array{lease: array<string,mixed>, terms: array<string,mixed>}  $accepted
     * @param  array<string,string>  $entered
     * @return array<string,mixed>|null
     */
    private function calculatedRow(Lease $lease, array $map, string $key, string $label, ?string $printed, array $accepted, array $entered): ?array
    {
        $candidate = clone $lease;
        foreach ($accepted['lease'] as $column => $value) {
            $candidate->setAttribute($column, $value);
        }
        $agreement = (new RentalLeaseTemplate)->forceFill(['field_map' => $map]);
        $expected = app(LeaseAgreementDocumentValues::class)->forLease($candidate, $agreement, $accepted['terms']);
        $want = $expected[$key] ?? null;
        if ($want === null || $want === '') {
            return null; // nothing computable (e.g. no commission % known) — never invent a comparison
        }

        $type = (string) (config("lease-agreement-fields.fields.{$key}.type") ?? 'text');
        $typed = isset($entered[$key]) && trim((string) $entered[$key]) !== '' ? (string) $entered[$key] : $printed;
        $printedEmpty = $typed === null || trim($typed) === '';

        if ($printedEmpty) {
            $agree = false;
        } elseif ($type === 'money') {
            $a = LeaseAgreementValuesReader::parseMoney($typed);
            $b = LeaseAgreementValuesReader::parseMoney((string) $want);
            $agree = $a !== null && $b !== null && abs($a - $b) < 0.005;
        } else {
            $agree = $this->tokens($typed) === $this->tokens((string) $want);
        }

        $printedMoney = ! $printedEmpty && $type === 'money' ? LeaseAgreementValuesReader::parseMoney($typed) : null;

        return [
            'key' => $key,
            'label' => $label,
            'group' => 'calculated',
            'kind' => 'calculated',
            'state' => $agree ? self::STATE_AGREE : self::STATE_INFORMATIONAL,
            'lease' => $type === 'money' ? $this->show(LeaseAgreementValuesReader::parseMoney((string) $want), 'money') : (string) $want,
            'agreement' => $printedEmpty ? null : ($printedMoney !== null ? $this->show($printedMoney, 'money') : $typed),
            'printed' => $printed,
            'agreement_value' => null,
            'acceptable' => false,
            'entered' => false,
            'target' => null,
            'type' => $type,
        ];
    }

    // ── Parties ──────────────────────────────────────────────────────────────────────────

    /**
     * What the contact side says for a party key: tenant_name / landlord_name (combined, or "_2" for one party in
     * order), tenant_address, tenant_id, landlord_address, landlord_id. Tenants are in the signing order's order
     * (primary first); landlords as the property gives them.
     *
     * @return array{kind: string, parts: array<int,string>, entity: bool}|null
     */
    private function expectedParty(Lease $lease, string $key): ?array
    {
        if (! preg_match('/^(tenant|landlord)_(name|address|id)(?:_(\d+))?$/', $key, $m)) {
            return null;
        }

        $contacts = $m[1] === 'tenant'
            ? $lease->tenants()->with('contact')->orderByDesc('is_primary')->orderBy('id')->get()->map(fn ($t) => $t->contact)->filter()->values()
            : $lease->landlordContacts()->values();

        if (isset($m[3]) && $m[3] !== '') {
            $contacts = $contacts->slice(((int) $m[3]) - 1, 1)->values();
        }

        $parts = $contacts->map(function ($c) use ($m) {
            return match ($m[2]) {
                'name' => (string) $c->full_name,
                'address' => (string) ($c->address ?? ''),
                default => (string) ($c->id_number ?: ($c->passport_number ?? '')),
            };
        })->map(fn ($v) => trim($v))->filter(fn ($v) => $v !== '')->values()->all();

        return [
            'kind' => $m[2],
            'parts' => $parts,
            'entity' => $m[2] === 'name' && $contacts->contains(fn ($c) => method_exists($c, 'isEntity') && $c->isEntity()),
        ];
    }

    /**
     * Every word of every expected part must be printed, and what is left over may only be connectors and titles (and,
     * for a name, ID numbers — an identity field often prints "Name (ID …)"). A company may be followed by whoever
     * represents it, so its leftovers are not held against it.
     *
     * @param  array<int,string>  $parts
     */
    private function partiesMatch(string $printed, array $parts, string $kind, bool $entity): bool
    {
        $remaining = $this->tokens($printed);

        foreach ($parts as $part) {
            foreach ($this->tokens($part) as $token) {
                $at = array_search($token, $remaining, true);
                if ($at === false) {
                    return false;
                }
                unset($remaining[$at]);
            }
        }

        if ($entity) {
            return true;
        }

        foreach ($remaining as $token) {
            if (in_array($token, self::NOISE, true)) {
                continue;
            }
            if ($kind === 'name' && preg_match('/\d/', $token)) {
                continue;
            }

            return false;
        }

        return true;
    }

    // ── Reading the document ─────────────────────────────────────────────────────────────

    /** The e-sign document this lease's agreement is: the one linked at launch, else the envelope's own. */
    public function documentFor(Lease $lease): ?Document
    {
        $id = $lease->agreement_document_id
            ?: ($lease->signature_template_id ? SignatureTemplate::withoutGlobalScopes()->whereKey($lease->signature_template_id)->value('document_id') : null)
            ?: $lease->source_document_id;

        return $id ? Document::withoutGlobalScopes()->find($id) : null;
    }

    /**
     * Strike / reword / added-condition changes, read-only. They are free text, not fields, and cannot be compared;
     * the engine's own gate already makes every party initial each of them. CoreX adds visibility, not a second gate.
     *
     * @return array<int,array{old: ?string, new: ?string, actor: ?string, at: ?string}>
     */
    private function textChanges(Document $document): array
    {
        $data = is_array($document->web_template_data) ? $document->web_template_data : [];
        $out = [];
        foreach ((array) ($data['pending_body_changes'] ?? []) as $change) {
            if (! is_array($change) || ! empty($change['reverted']) || ($change['status'] ?? null) === 'reverted') {
                continue;
            }
            $out[] = [
                'old' => isset($change['old']) ? trim((string) $change['old']) : null,
                'new' => isset($change['new']) ? trim((string) $change['new']) : null,
                'actor' => isset($change['actor_name']) ? (string) $change['actor_name'] : null,
                'at' => isset($change['at']) ? (string) $change['at'] : null,
            ];
        }

        return $out;
    }

    /**
     * §15.8.5 — a confirmation is valid only for the exact printed values it confirmed. sha256 of every mapped key's
     * printed text; any later edit changes it and the check runs again.
     *
     * @param  array<string,array<string,mixed>>  $normalised
     * @param  array<string,array<string,mixed>>  $reads
     */
    private function fingerprint(array $normalised, array $reads): string
    {
        $printed = [];
        foreach (array_keys($normalised) as $key) {
            $printed[$key] = $reads[$key]['printed'] ?? null;
        }
        ksort($printed);

        return hash('sha256', (string) json_encode($printed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ── Shape ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,array<string,mixed>>  $rows
     * @param  array<int,array<string,mixed>>  $textChanges
     */
    private function result(bool $applicable, ?int $documentId, array $rows, array $textChanges, ?string $fingerprint): array
    {
        $differences = array_values(array_filter($rows, fn ($r) => in_array($r['state'], [self::STATE_DIFFERS, self::STATE_BLOCKED], true)));
        $cannot = array_values(array_filter($rows, fn ($r) => $r['state'] === self::STATE_CANNOT_VERIFY));

        return [
            'applicable' => $applicable,
            'document_id' => $documentId,
            'has_differences' => $differences !== [],
            'needs_confirmation' => $differences !== [] || $cannot !== [],
            'blocked' => (bool) array_filter($rows, fn ($r) => $r['state'] === self::STATE_BLOCKED),
            'differences' => $differences,
            'cannot_verify' => $cannot,
            'informational' => array_values(array_filter($rows, fn ($r) => $r['state'] === self::STATE_INFORMATIONAL)),
            'rows' => array_values($rows),
            'text_changes' => $textChanges,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     * @param  array<int,string>  $registryOrder
     * @return array<string,array<string,mixed>>
     */
    private function inRegistryOrder(array $rows, array $registryOrder): array
    {
        $rank = array_flip($registryOrder);
        uksort($rows, function ($a, $b) use ($rank) {
            $ra = $rank[$a] ?? ($rank[preg_replace('/_\d+$/', '', $a)] ?? PHP_INT_MAX);
            $rb = $rank[$b] ?? ($rank[preg_replace('/_\d+$/', '', $b)] ?? PHP_INT_MAX);

            return $ra === $rb ? strcmp($a, $b) : $ra <=> $rb;
        });

        return $rows;
    }

    /** @return array<string,mixed>|null the registry definition, the indexed family's for "_2", or null for an agency extra */
    private function definitionFor(string $key, array $registry): ?array
    {
        if (isset($registry[$key])) {
            return $registry[$key];
        }
        if (preg_match('/^(.*)_\d+$/', $key, $m) && ! empty($registry[$m[1]]['indexed'])) {
            return $registry[$m[1]];
        }

        return null;
    }

    // ── Comparable values and how they are shown ─────────────────────────────────────────

    private function norm(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'money', 'percent' => is_numeric($value) ? round((float) $value, 2) : null,
            'integer', 'month' => is_numeric($value) ? (int) $value : null,
            'date' => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) ? substr((string) $value, 0, 10) : null),
            default => mb_strtolower((string) LeaseAgreementValuesReader::collapseText((string) $value)),
        };
    }

    private function equal(mixed $a, mixed $b): bool
    {
        return is_float($a) || is_float($b) ? abs((float) $a - (float) $b) < 0.005 : $a === $b;
    }

    /** What the agent reads on the confirm screen: "R6 500", "13 October 2026", "7.5 %", "October". */
    private function show(mixed $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'money' => is_numeric($value)
                ? 'R' . number_format((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2, '.', ' ')
                : (string) $value,
            'date' => ($d = $this->norm($value, 'date')) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $d)->format('j F Y') : (string) $value,
            'percent' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' %' : (string) $value,
            'month' => is_numeric($value) && (int) $value >= 1 && (int) $value <= 12
                ? \DateTimeImmutable::createFromFormat('!n', (string) (int) $value)->format('F')
                : (string) $value,
            default => (string) LeaseAgreementValuesReader::collapseText((string) $value),
        };
    }

    /** @return array<int,string> lowercase letters and digits only, split on everything else */
    private function tokens(string $text): array
    {
        $clean = trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii($text))));

        return $clean === '' ? [] : explode(' ', $clean);
    }
}
