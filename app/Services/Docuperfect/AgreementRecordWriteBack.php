<?php

namespace App\Services\Docuperfect;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Rules\SouthAfricanIdNumber;
use App\Services\Audit\ContactAuditService;
use App\Services\Audit\PropertyAuditService;
use App\Services\ContactDuplicateService;
use App\Services\Rentals\LeaseSigningLauncher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * .ai/specs/leases.md "Agreement write-back" (Johan, 14 Oct 2026) - what an agent types into an agreement's Fill & review that CoreX
 * did not already know goes back onto the record it would have pre-filled from, so every later document and renewal fills itself.
 *
 * THE CLASS (the only fields written back - each is one a pre-fill reads from the contact / property):
 *   party, per role (landlord/lessor, tenant/lessee, seller, buyer; 1st party, and a 2nd where the data service has a 2nd key):
 *     address -> contacts.address        (overwrite allowed WITH the agent's tick; written without a tick only when the record is empty)
 *     email   -> contacts.email          (only when the record's is EMPTY - an existing address is never replaced from a document)
 *     phone   -> contacts.phone          (only when EMPTY - same reason: a contact's other numbers must not be lost)
 *     id_number -> contacts.id_number    (only when EMPTY and a valid SA ID; stamped id_number_source = agreement_writeback)
 *   property:
 *     erf     -> properties.erf_number / property_number  (the column the pre-fill read; both empty -> the form's "Property / Erf Number" box)
 *     complex_name -> properties.complex_name, unit_no -> properties.unit_number (overwrite with the tick; the property's normal
 *                     save pipeline recomposes its address and re-submits a live listing, exactly as an edit on the property page does)
 * Never written: names, bank details, street/suburb/town (a composed, portal-coded address), anything a document computes.
 *
 * THE RULE: a value is a candidate only when the agent supplied something CoreX did not already show - non-blank, different from the
 * record, and different from what the pre-fill itself showed. It is written when the record's field is EMPTY (default on) or when the agent
 * ticked "Also update the landlord's record?" (default off over a non-empty record). Blank never overwrites anything. Every write is an
 * audit line on the contact / property naming the agreement. Agency-scoped by the models' global scopes; assistants never write back.
 */
class AgreementRecordWriteBack
{
    /** field name (snake case) => [role bucket, position within the role (1-based), attribute] */
    public const FIELD_MAP = [
        // landlord / lessor
        'lessor_address' => ['landlord', 1, 'address'], 'lessor1_address' => ['landlord', 1, 'address'], 'lessor2_address' => ['landlord', 2, 'address'],
        'lessor_email' => ['landlord', 1, 'email'], 'lessor1_email' => ['landlord', 1, 'email'], 'lessor2_email' => ['landlord', 2, 'email'], 'owner_email' => ['landlord', 1, 'email'],
        'lessor_cell' => ['landlord', 1, 'phone'], 'lessor_tel' => ['landlord', 1, 'phone'], 'lessor1_tel' => ['landlord', 1, 'phone'], 'lessor2_tel' => ['landlord', 2, 'phone'], 'owner_contact' => ['landlord', 1, 'phone'],
        'lessor_id_number' => ['landlord', 1, 'id_number'], 'lessor_id' => ['landlord', 1, 'id_number'], 'lessor2_id_number' => ['landlord', 2, 'id_number'], 'lessor_id_number_2' => ['landlord', 2, 'id_number'],
        // tenant / lessee
        'lessee_address' => ['tenant', 1, 'address'], 'current_address_1' => ['tenant', 1, 'address'],
        'lessee_email' => ['tenant', 1, 'email'], 'email_address' => ['tenant', 1, 'email'],
        'lessee_cell' => ['tenant', 1, 'phone'], 'lessee_tel' => ['tenant', 1, 'phone'], 'cell_number' => ['tenant', 1, 'phone'],
        'lessee_id_number' => ['tenant', 1, 'id_number'], 'lessee_id' => ['tenant', 1, 'id_number'],
        // seller
        'seller_address' => ['seller', 1, 'address'], 'seller_address_1' => ['seller', 1, 'address'], 'seller_address_2' => ['seller', 2, 'address'],
        'seller_email' => ['seller', 1, 'email'], 'seller_1_email' => ['seller', 1, 'email'], 'seller_2_email' => ['seller', 2, 'email'],
        'seller_cell' => ['seller', 1, 'phone'], 'seller_1_phone' => ['seller', 1, 'phone'], 'seller_2_phone' => ['seller', 2, 'phone'],
        'seller_id_number' => ['seller', 1, 'id_number'], 'seller_2_id_number' => ['seller', 2, 'id_number'],
        // buyer
        'buyer_address' => ['buyer', 1, 'address'], 'buyer_email' => ['buyer', 1, 'email'], 'buyer_id_number' => ['buyer', 1, 'id_number'],
        // the property
        'erf_no' => ['property', 1, 'erf'], 'property_erf_number' => ['property', 1, 'erf'],
        'complex_name' => ['property', 1, 'complex_name'], 'property_complex_name' => ['property', 1, 'complex_name'],
        'unit_no' => ['property', 1, 'unit_number'],
    ];

    /** Role bucket => the wizard recipient role tokens that belong to it. */
    private const ROLE_TOKENS = [
        'landlord' => ['landlord', 'lessor'],
        'tenant' => ['tenant', 'lessee'],
        'seller' => ['seller'],
        'buyer' => ['buyer'],
    ];

    /** Attributes a document may only FILL (record empty), never replace. */
    private const FILL_ONLY = ['email', 'phone', 'id_number'];

    private const TARGET_LABEL = [
        'landlord' => "the landlord's record", 'tenant' => "the tenant's record", 'seller' => "the seller's record",
        'buyer' => "the buyer's record", 'property' => "the property's record",
    ];

    private const ATTR_LABEL = [
        'address' => 'address', 'email' => 'email', 'phone' => 'phone number', 'id_number' => 'ID number',
        'erf' => 'erf number', 'complex_name' => 'complex name', 'unit_number' => 'unit number',
    ];

    /**
     * Every field of this flow that could be written back, with what the record holds now - for the Fill & review screen's tick
     * (shown only while the typed value differs). Keyed by the wizard field id (instance ids "{id}__r{n}" included).
     *
     * @param array<int,array<string,mixed>> $fields   the wizard fields (expanded per recipient when there are several)
     * @param array<string,mixed>            $stepData the flow's step data
     * @return array<string,array<string,mixed>>
     */
    public function meta(array $fields, array $stepData, ?User $user): array
    {
        $out = [];
        foreach ($fields as $field) {
            $id = (string) ($field['id'] ?? '');
            $spec = $this->specFor($field);
            if ($id === '' || $spec === null) {
                continue;
            }
            $target = $this->targetFor($spec, $field, $stepData, $user);
            if ($target === null) {
                continue;
            }
            $out[$id] = [
                'target' => self::TARGET_LABEL[$spec['bucket']],
                'what' => self::ATTR_LABEL[$spec['attr']] ?? $spec['attr'],
                'record' => $target['record'],
                'prefill' => $target['prefill'],
                'can_overwrite' => ! in_array($spec['attr'], self::FILL_ONLY, true),
            ];
        }

        return $out;
    }

    /**
     * Write back what the agent supplied. `$decisions` = field id => bool from the agent's ticks (absent = the default: on over an
     * empty record, off over a filled one).
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed>            $stepData
     * @param array<string,mixed>            $fieldValues field id => typed value
     * @param array<string,mixed>            $decisions
     * @return array<int,string> one plain line per record updated
     */
    public function apply(array $fields, array $stepData, array $fieldValues, array $decisions, ?User $user, ?int $flowId = null): array
    {
        if (! $user || $user->is_assistant) {
            return [];
        }

        $fields = $this->withInstances($fields, $fieldValues);
        $lines = [];
        $done = [];   // "contact:12:address" / "property:5:erf" - the first supplied value for a target wins
        foreach ($fields as $field) {
            $id = (string) ($field['id'] ?? '');
            $spec = $this->specFor($field);
            if ($id === '' || $spec === null || ! array_key_exists($id, $fieldValues)) {
                continue;
            }
            $typed = $this->clean((string) $fieldValues[$id], $spec['attr']);
            if ($typed === '') {
                continue;   // blank never overwrites anything
            }
            $target = $this->targetFor($spec, $field, $stepData, $user);
            if ($target === null) {
                continue;
            }
            if ($typed === $target['record'] || $typed === $target['prefill']) {
                continue;   // nothing new: CoreX already knew it (the record, or what the pre-fill showed)
            }
            $key = $target['key'] . ':' . $spec['attr'];
            if (isset($done[$key])) {
                continue;
            }

            $recordEmpty = $target['record'] === '';
            $fillOnly = in_array($spec['attr'], self::FILL_ONLY, true);
            if (! $recordEmpty && $fillOnly) {
                continue;   // a document never replaces an existing email / phone / ID
            }
            $tick = $this->decisionFor($decisions[$id] ?? null, $fieldValues[$id], $recordEmpty);
            if (! $tick) {
                continue;
            }

            try {
                $line = $target['model'] instanceof Contact
                    ? $this->writeContact($target['model'], $spec, $typed, $target['record'], $user, $flowId)
                    : $this->writeProperty($target['model'], $spec, $typed, $target['record'], $user, $flowId);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Agreement write-back failed', ['flow' => $flowId, 'field' => $id, 'error' => $e->getMessage()]);
                $line = null;
            }
            if ($line !== null) {
                $done[$key] = true;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────

    /**
     * The agent's tick for one field. The screen posts {on, value}: the value the tick was made about - a tick made about an EARLIER value
     * (the agent typed something else since) is ignored, so a new value always starts from the default (on over an empty record, off over
     * a filled one). A bare boolean is accepted too (API callers).
     */
    private function decisionFor(mixed $decision, mixed $typedRaw, bool $recordEmpty): bool
    {
        if (is_array($decision)) {
            $about = $this->clean((string) ($decision['value'] ?? ''), 'text');
            $now = $this->clean((string) $typedRaw, 'text');
            if ($about !== '' && $about === $now && array_key_exists('on', $decision)) {
                return filter_var($decision['on'], FILTER_VALIDATE_BOOLEAN);
            }

            return $recordEmpty;
        }
        if ($decision !== null) {
            return filter_var($decision, FILTER_VALIDATE_BOOLEAN);
        }

        return $recordEmpty;
    }

    /**
     * The saved field list holds each field once; a per-recipient edit arrives as "{id}__r{n}". Give every such key its own field
     * entry (a copy of the base, marked as the n-th person of its role) so it maps to that person's record.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed>            $fieldValues
     * @return array<int,array<string,mixed>>
     */
    private function withInstances(array $fields, array $fieldValues): array
    {
        $byId = [];
        foreach ($fields as $f) {
            if (isset($f['id'])) {
                $byId[(string) $f['id']] = $f;
            }
        }
        foreach (array_keys($fieldValues) as $key) {
            $key = (string) $key;
            if (isset($byId[$key]) || ! preg_match('/^(.*)__r(\d+)$/', $key, $m) || ! isset($byId[$m[1]])) {
                continue;
            }
            $copy = $byId[$m[1]];
            $copy['id'] = $key;
            $copy['_instance_index'] = (int) $m[2];
            $fields[] = $copy;
        }

        return $fields;
    }

    /** @return array{bucket:string,pos:int,attr:string,instance:?int}|null */
    private function specFor(array $field): ?array
    {
        $name = (string) ($field['field_name'] ?? $field['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
        $map = self::FIELD_MAP[$snake] ?? self::FIELD_MAP[$name] ?? null;
        if ($map === null) {
            return null;
        }
        // A signature / initial / tick is never data.
        if (in_array($field['tag_type'] ?? $field['type'] ?? '', ['signature', 'initial', 'tick', 'toggle', 'strikethrough'], true)) {
            return null;
        }

        return ['bucket' => $map[0], 'pos' => $map[1], 'attr' => $map[2], 'instance' => isset($field['_instance_index']) ? (int) $field['_instance_index'] : null];
    }

    /**
     * The record behind a field: which contact / property, what it holds now, and what the pre-fill showed.
     *
     * @return array{model:Contact|Property,key:string,record:string,prefill:string}|null
     */
    private function targetFor(array $spec, array $field, array $stepData, ?User $user): ?array
    {
        if ($spec['bucket'] === 'property') {
            $pid = (int) ($stepData['property']['property_id'] ?? 0);
            if ($pid <= 0 || ($stepData['property']['_property_source'] ?? 'properties') !== 'properties') {
                return null;
            }
            $property = Property::query()->find($pid);   // global scopes: agency (and the user's data scope)
            if (! $property) {
                return null;
            }
            $record = match ($spec['attr']) {
                'erf' => $this->erfOf($property),
                default => trim((string) $property->{$spec['attr']}),
            };

            return ['model' => $property, 'key' => 'property:' . $property->id, 'record' => $record, 'prefill' => $record];
        }

        $contact = $this->contactFor($spec, $stepData);
        if (! $contact || $contact->isEntity()) {
            return null;   // an entity signs through its representatives
        }
        $column = match ($spec['attr']) { 'phone' => 'phone', default => $spec['attr'] };
        $record = trim((string) $contact->{$column});
        $prefill = $spec['attr'] === 'address'
            ? app(LeaseSigningLauncher::class)->addressFor($contact, $spec['bucket'])
            : $record;

        return ['model' => $contact, 'key' => 'contact:' . $contact->id, 'record' => $record, 'prefill' => trim($prefill)];
    }

    private function contactFor(array $spec, array $stepData): ?Contact
    {
        $position = $spec['instance'] ?? $spec['pos'];   // an expanded "{id}__r{n}" field IS the n-th person of its role
        $tokens = self::ROLE_TOKENS[$spec['bucket']] ?? [];
        $living = [];
        foreach (($stepData['recipients']['recipients'] ?? []) as $r) {
            if (! empty($r['_is_deceased'])) {
                continue;
            }
            if (in_array(strtolower(trim((string) ($r['role'] ?? ''))), $tokens, true)) {
                $living[] = $r;
            }
        }
        $row = $living[$position - 1] ?? null;
        $cid = (int) ($row['_contact_id'] ?? $row['contact_id'] ?? 0);

        return $cid > 0 ? Contact::query()->find($cid) : null;
    }

    private function erfOf(Property $property): string
    {
        $a = trim((string) ($property->erf_number ?? ''));

        return $a !== '' ? $a : trim((string) ($property->property_number ?? ''));
    }

    private function clean(string $value, string $attr): string
    {
        $v = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return match ($attr) {
            'email' => strtolower($v),
            'phone', 'id_number' => preg_replace('/\s+/', '', $v) ?? '',
            default => $v,
        };
    }

    private function writeContact(Contact $contact, array $spec, string $typed, string $old, User $user, ?int $flowId): ?string
    {
        $attr = $spec['attr'];
        $agencyId = (int) $contact->agency_id;
        if ($attr === 'email' && ! filter_var($typed, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if ($attr === 'phone' && (strlen(preg_replace('/\D/', '', $typed)) < 7 || strlen($typed) > 30)) {
            return null;
        }
        if ($attr === 'id_number' && ! Validator::make(['v' => $typed], ['v' => ['string', 'max:20', new SouthAfricanIdNumber()]])->passes()) {
            return null;
        }
        if ($attr === 'address' && mb_strlen($typed) > 500) {
            $typed = mb_substr($typed, 0, 500);
        }
        // Never make two contacts share a number / email through a document.
        if (in_array($attr, ['email', 'phone'], true)) {
            $clash = app(ContactDuplicateService::class)->findDuplicatesForIdentifiers(
                $attr === 'phone' ? [$typed] : [], $attr === 'email' ? [$typed] : [], null, $agencyId, (int) $contact->id
            );
            if ($clash->isNotEmpty()) {
                return null;
            }
        }

        $contact->{$attr} = $typed;
        if ($attr === 'id_number') {
            $contact->id_number_captured_at = now();
            $contact->id_number_source = 'agreement_writeback';
        }
        $contact->save();

        $what = self::ATTR_LABEL[$attr];
        app(ContactAuditService::class)->log(
            $contact, 'contact', 'agreement_writeback', $user,
            oldValues: [$attr => $old === '' ? null : $old], newValues: [$attr => $typed],
            metadata: ['source' => 'agreement', 'flow_id' => $flowId, 'field' => $attr],
            humanSummary: ucfirst($what) . ' saved from the agreement the agent filled in' . ($flowId ? " (e-sign flow #{$flowId})" : '')
                . ($old === '' ? ' - the record had none' : ' - agent confirmed the change'),
        );

        return trim($contact->full_name) . ': ' . $what;
    }

    private function writeProperty(Property $property, array $spec, string $typed, string $old, User $user, ?int $flowId): ?string
    {
        $attr = $spec['attr'];
        if (mb_strlen($typed) > 150) {
            return null;
        }
        $column = $attr;
        if ($attr === 'erf') {
            $column = trim((string) ($property->erf_number ?? '')) !== '' ? 'erf_number'
                : (trim((string) ($property->property_number ?? '')) !== '' ? 'property_number' : 'property_number');
        }
        $before = $property->{$column};
        $property->{$column} = $typed;
        $property->save();

        $what = self::ATTR_LABEL[$attr];
        app(PropertyAuditService::class)->log(
            $property, 'property', 'agreement_writeback', $user,
            oldValues: [$column => $before], newValues: [$column => $typed],
            metadata: ['source' => 'agreement', 'flow_id' => $flowId, 'field' => $column],
            humanSummary: ucfirst($what) . ' saved from the agreement the agent filled in' . ($flowId ? " (e-sign flow #{$flowId})" : '')
                . ($old === '' ? ' - the record had none' : ' - agent confirmed the change'),
        );

        return 'Property: ' . $what;
    }
}
