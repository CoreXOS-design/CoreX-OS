<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;

/**
 * AT-177 — deterministic CDS import binding suggester (the convergence point for the
 * import/cds path).
 *
 * Johan's imported documents mark their fill-ins with an EXPLICIT convention:
 * "{Party} - {Attribute}" tokens (`~~~~Seller - Physical address~~~~`) which the CDS
 * parser captures as `insertable_block_placeholder`s with a canonical `block_id`
 * (`seller_physical_address`). That convention is deterministic — far more reliable than
 * the context heuristics the .docx/PDF path uses — so this service resolves each token to
 * its exact binding up front, and the cds-builder shows it bound OUT OF THE BOX (the human
 * vet CONFIRMS rather than REPAIRS).
 *
 * It closes three divergences the old builder-side substring matcher left open, measured
 * against Johan's hand-fixed "EXCLUSIVE AUTHORITY TO SELL" template (#70):
 *   D1 — the party IDENTITY token ("Seller - Full name and surname") binds to the party's
 *        FIELD GROUP (first name + last name + ID), which renders the single shared
 *        "I / We Name (ID) and Name (ID)" clause — NOT the bare party-name field.
 *   D2 — each attribute token binds to its OWN attribute (address/phone/email/id/…) with a
 *        populated `editable_by` so the field is actually fillable, instead of collapsing
 *        to the party name with an empty `editable_by`.
 *
 * Output is an ORDERED list, one entry per input placeholder in document order — the same
 * order `TemplateController::extractFieldsFromCds()` walks and the cds-builder assigns
 * `parserIndex`. An entry is `null` when the token is not confidently resolvable; the
 * builder then falls back to its existing best-match logic. Nothing here ever overrides a
 * human's saved binding — it only seeds the FRESH import.
 */
class CdsBindingSuggester
{
    public function __construct(private ?int $agencyId = null)
    {
    }

    /**
     * @return array{bindings: array<int, array<string,mixed>|null>, primary_party: string, primary_role: string}
     */
    public function suggest(array $cds): array
    {
        $primaryRole = $this->inferPrimaryContactRole($cds);       // 'Seller' | 'Buyer' | 'Lessor' | 'Lessee' | ''
        $primaryParty = $this->partyKeyForRole($primaryRole) ?: 'owner_party';

        $bindings = [];
        foreach ($this->collectInputTokens($cds) as $token) {
            $bindings[] = $this->resolveToken($token['field_name'] ?? '', $token['label'] ?? '', $primaryParty);
        }

        return [
            'bindings' => $bindings,
            'primary_party' => $primaryParty,
            'primary_role' => $primaryRole,
        ];
    }

    /**
     * Walk the CDS in the SAME order the renderer emits input `.corex-field` spans and
     * `extractFieldsFromCds()` collects them: content items first, then label_value pairs.
     *
     * @return array<int, array{field_name:string,label:string}>
     */
    public function collectInputTokens(array $cds): array
    {
        $out = [];
        foreach ($cds['sections'] ?? [] as $section) {
            foreach ($section['content'] ?? [] as $item) {
                $type = $item['type'] ?? '';
                if ($type === 'field_placeholder') {
                    $out[] = ['field_name' => (string) ($item['field_name'] ?? ''), 'label' => (string) ($item['label'] ?? '')];
                } elseif ($type === 'insertable_block_placeholder' && ($item['purpose'] ?? '') === 'custom_named') {
                    $out[] = [
                        'field_name' => (string) ($item['block_id'] ?? ''),
                        'label' => (string) ($item['custom_label'] ?? $item['raw_token'] ?? ''),
                    ];
                }
            }
            foreach ($section['pairs'] ?? [] as $pair) {
                foreach ($pair['fields'] ?? [] as $item) {
                    if (($item['type'] ?? '') === 'field_placeholder') {
                        $out[] = ['field_name' => (string) ($item['field_name'] ?? ''), 'label' => (string) ($item['label'] ?? '')];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Resolve one "{Party} - {Attribute}" token to a builder binding, or null to defer.
     *
     * @return array<string,mixed>|null
     */
    public function resolveToken(string $blockId, string $rawLabel, string $primaryParty): ?array
    {
        // The key we reason on: prefer the canonical block_id; fall back to a slug of the label.
        $key = strtolower(trim($blockId));
        if ($key === '') {
            $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($rawLabel)));
            $key = trim((string) $key, '_');
        }
        if ($key === '') {
            return null;
        }

        [$partyKind, $role, $attr] = $this->splitPartyAttribute($key);
        if ($attr === '') {
            return null;
        }

        // ---- CONTACT party token ------------------------------------------------------
        if ($partyKind === 'contact') {
            $partyKey = $this->partyKeyForRole($role) ?: $primaryParty;

            // D1 — the IDENTITY token binds to the party's field group (name + surname + ID).
            if ($this->isIdentityAttribute($attr)) {
                $fg = $this->resolveIdentityFieldGroup($role);
                if ($fg) {
                    return $this->fieldGroupBinding($fg, $role, $partyKey);
                }
                // No group configured — fall back to the composite name named field.
                $nf = $this->findNamedField('contact', $this->contactNameColumn(), $role);
                return $nf ? $this->namedFieldBinding($nf, $role, $partyKey, [$partyKey, 'agent', 'witness']) : null;
            }

            $col = $this->contactColumnForAttribute($attr);
            if ($col === null) {
                return null;
            }
            $nf = $this->findNamedField('contact', $col, $role, $attr);
            if (! $nf) {
                return null;
            }
            $editable = $col === 'email' ? [$partyKey] : [$partyKey, 'agent'];
            return $this->namedFieldBinding($nf, $role, $partyKey, $editable);
        }

        // ---- PROPERTY token -----------------------------------------------------------
        if ($partyKind === 'property') {
            $col = $this->propertyColumnForAttribute($attr);
            if ($col === null) {
                return null;
            }
            $nf = $this->findNamedField('property', $col, null, $attr);
            if (! $nf) {
                return null;
            }
            // Location components a party confirms at signing → fillable; pure auto-fill locked.
            $locked = in_array($col, ['price', 'expiry_date'], true);
            $editable = $locked ? [] : [$primaryParty, 'agent'];
            return $this->namedFieldBinding($nf, '', 'auto', $editable);
        }

        // ---- DOCUMENT token (commission / price / words / expiry / other conditions) --
        if ($partyKind === 'document') {
            // AT-177 R2 — commission / professional fee % → property.commission_percent,
            // editable by the party (they agree the rate) and the agent.
            if ($this->attrContains($attr, ['commission', 'professional_fee', 'centum'])) {
                $nf = $this->findNamedField('property', 'commission_percent', null, $attr);
                return $nf ? $this->namedFieldBinding($nf, '', 'auto', [$primaryParty, 'agent']) : null;
            }
            if ($this->attrContains($attr, ['in_words', 'words'])) {
                $nf = $this->findNamedField('computed', 'price_in_words', null);
                return $nf ? $this->namedFieldBinding($nf, '', 'auto', []) : null;
            }
            if ($this->attrContains($attr, ['other', 'condition'])) {
                return $this->manualBinding($rawLabel !== '' ? $rawLabel : 'Other conditions', ['agent', $primaryParty]);
            }
            if ($this->attrContains($attr, ['expiry', 'mandate_expiry', 'expiry_date'])) {
                $nf = $this->findNamedField('property', 'expiry_date', null);
                return $nf ? $this->namedFieldBinding($nf, '', 'auto', []) : null;
            }
            if ($this->attrContains($attr, ['price', 'amount', 'gross'])) {
                $nf = $this->findNamedField('property', 'price', null);
                return $nf ? $this->namedFieldBinding($nf, '', 'auto', []) : null;
            }
            // Unknown document attribute — leave as a manual field the vet can name.
            return $this->manualBinding($rawLabel !== '' ? $rawLabel : ucwords(str_replace('_', ' ', $attr)), ['agent', $primaryParty]);
        }

        return null;
    }

    // =====================================================================================
    // Token parsing
    // =====================================================================================

    /**
     * @return array{0:string,1:string,2:string}  [partyKind, role, attribute]
     */
    private function splitPartyAttribute(string $key): array
    {
        $map = [
            'seller_'   => ['contact', 'Seller'],
            'buyer_'    => ['contact', 'Buyer'],
            'purchaser_'=> ['contact', 'Buyer'],
            'lessor_'   => ['contact', 'Lessor'],
            'landlord_' => ['contact', 'Lessor'],
            'lessee_'   => ['contact', 'Lessee'],
            'tenant_'   => ['contact', 'Lessee'],
            'property_' => ['property', ''],
            'document_' => ['document', ''],
        ];
        foreach ($map as $prefix => [$kind, $role]) {
            if (str_starts_with($key, $prefix)) {
                return [$kind, $role, substr($key, strlen($prefix))];
            }
        }
        return ['', '', ''];
    }

    private function isIdentityAttribute(string $attr): bool
    {
        // "full name and surname", "full name", "name and surname", bare "name".
        if ($this->attrContains($attr, ['full_name', 'name_and_surname'])) {
            return true;
        }
        // bare "name" / "names" but NOT "..._name" attributes like company_name (none here).
        return $attr === 'name' || $attr === 'names' || $attr === 'full_names';
    }

    private function contactColumnForAttribute(string $attr): ?string
    {
        return match (true) {
            $this->attrContains($attr, ['email'])                                        => 'email',
            $this->attrContains($attr, ['telephone', 'tel', 'phone', 'cell', 'mobile', 'landline']) => 'phone',
            $this->attrContains($attr, ['id_number', 'identity', 'passport']) || $attr === 'id' => 'id_number',
            $this->attrContains($attr, ['physical_address', 'postal_address', 'residential_address', 'address']) => 'address',
            default => null,
        };
    }

    private function propertyColumnForAttribute(string $attr): ?string
    {
        return match (true) {
            $this->attrContains($attr, ['complex', 'estate'])                    => 'complex_name',
            $this->attrContains($attr, ['erf', 'scheme', 'unit', 'property_number']) || $attr === 'number' => 'property_number',
            $this->attrContains($attr, ['street'])                              => 'address',
            $this->attrContains($attr, ['township', 'town', 'suburb'])          => 'town',
            $this->attrContains($attr, ['district'])                            => 'district',
            $this->attrContains($attr, ['in_words', 'words'])                   => null, // handled by document branch
            $this->attrContains($attr, ['price', 'amount', 'gross'])            => 'price',
            $this->attrContains($attr, ['expiry'])                              => 'expiry_date',
            default => null,
        };
    }

    private function contactNameColumn(): string
    {
        return 'first_name+last_name';
    }

    private function attrContains(string $attr, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($attr, $n)) {
                return true;
            }
        }
        return false;
    }

    // =====================================================================================
    // Binding builders
    // =====================================================================================

    /** @return array<string,mixed> */
    private function namedFieldBinding(NamedField $nf, string $role, string $party, array $editable): array
    {
        $typeKey = $nf->source_type === 'contact' && $nf->source_contact_type
            ? 'sf:contact_' . strtolower($nf->source_contact_type)
            : 'sf:' . ($nf->source_type ?: 'manual');

        return [
            'mappingType'       => 'named_field',
            'typeKey'           => $typeKey,
            'namedFieldId'      => $nf->id,
            'fieldGroupId'      => null,
            'label'             => $nf->name,
            'sigType'           => 'electronic',
            'sourceType'        => $nf->source_type,
            'sourceContactType' => $nf->source_contact_type ?: ($role ?: ''),
            'party'             => $party,
            'partyLocked'       => false,
            'confidence'        => 'high',
            'manualLabel'       => null,
            'editable_by'       => array_values(array_unique($editable)),
        ];
    }

    /** @return array<string,mixed> */
    private function fieldGroupBinding(FieldGroup $fg, string $role, string $party): array
    {
        return [
            'mappingType'       => 'field_group',
            'typeKey'           => 'fg:' . $fg->id,
            'namedFieldId'      => null,
            'fieldGroupId'      => $fg->id,
            'label'             => $fg->name,
            'sigType'           => 'electronic',
            'sourceType'        => null,
            'sourceContactType' => $role,
            'party'             => $party,
            'partyLocked'       => true,
            'confidence'        => 'high',
            'manualLabel'       => null,
            'editable_by'       => [$party, 'agent', 'witness'],
        ];
    }

    /** @return array<string,mixed> */
    private function manualBinding(string $label, array $editable): array
    {
        return [
            'mappingType'       => 'manual',
            'typeKey'           => null,
            'namedFieldId'      => null,
            'fieldGroupId'      => null,
            'label'             => $label,
            'sigType'           => 'electronic',
            'sourceType'        => null,
            'sourceContactType' => null,
            'party'             => 'auto',
            'partyLocked'       => false,
            'confidence'        => 'high',
            'manualLabel'       => $label,
            'editable_by'       => array_values(array_unique($editable)),
        ];
    }

    // =====================================================================================
    // DB resolution (agency-aware, deterministic)
    // =====================================================================================

    private function findNamedField(string $sourceType, string $column, ?string $contactType, string $attrHint = ''): ?NamedField
    {
        $q = NamedField::query()
            ->whereNull('deleted_at')
            ->where('source_type', $sourceType)
            ->where('source_column', $column);
        if ($contactType !== null) {
            $q->where('source_contact_type', $contactType);
        }
        $candidates = $q->orderBy('id')->get();
        if ($candidates->count() <= 1 || $attrHint === '') {
            return $candidates->first();
        }

        // Two named fields share a source_column (e.g. "Number" vs "Property Number",
        // "Complex" vs "Rental Complex"). They render the SAME value, but pick the one whose
        // NAME best matches the token's own attribute words and carries no foreign context
        // word (a "Rental …" field must not win on a sale document). Score +1 per name word
        // present in the attribute, -1 per foreign word; tie → lowest id.
        $attrWords = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($attrHint))));
        $best = null;
        $bestScore = PHP_INT_MIN;
        foreach ($candidates as $nf) {
            $nameWords = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower((string) $nf->name))));
            $score = 0;
            foreach ($nameWords as $w) {
                $score += in_array($w, $attrWords, true) ? 1 : -1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $nf;
            }
        }
        return $best ?? $candidates->first();
    }

    /**
     * Resolve the party's IDENTITY field group — a group whose members are that contact
     * role's first name + last name (and ideally ID number). Prefers a group named "… full".
     */
    private function resolveIdentityFieldGroup(string $role): ?FieldGroup
    {
        if ($role === '') {
            return null;
        }

        $groups = FieldGroup::query()
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('is_global', true);
                if ($this->agencyId) {
                    $q->orWhere('agency_id', $this->agencyId);
                }
            })
            ->orderByRaw("CASE WHEN LOWER(name) LIKE '%full%' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        foreach ($groups as $g) {
            $memberIds = collect($g->fields ?? [])->pluck('named_field_id')->filter()->all();
            if (empty($memberIds)) {
                continue;
            }
            $members = NamedField::whereIn('id', $memberIds)->get();
            $roleMembers = $members->filter(fn ($m) => strcasecmp((string) $m->source_contact_type, $role) === 0);
            if ($roleMembers->isEmpty()) {
                continue;
            }
            $cols = $roleMembers->pluck('source_column')->map(fn ($c) => strtolower((string) $c))->all();
            $hasFirst = in_array('first_name', $cols, true);
            $hasLast = in_array('last_name', $cols, true);
            if ($hasFirst && $hasLast) {
                return $g;
            }
        }
        return null;
    }

    // =====================================================================================
    // Party inference
    // =====================================================================================

    /**
     * The document's primary counterparty role, inferred from the contact tokens present.
     * Dominant contact prefix wins; ties resolve Seller/Lessor (owner side) first.
     */
    public function inferPrimaryContactRole(array $cds): string
    {
        $counts = [];
        foreach ($this->collectInputTokens($cds) as $token) {
            [$kind, $role] = $this->splitPartyAttribute(strtolower(trim($token['field_name'] ?? '')));
            if ($kind === 'contact' && $role !== '') {
                $counts[$role] = ($counts[$role] ?? 0) + 1;
            }
        }
        if (empty($counts)) {
            return '';
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    private function partyKeyForRole(string $role): ?string
    {
        return match (strtolower($role)) {
            'seller', 'lessor', 'landlord', 'owner' => 'owner_party',
            'buyer', 'purchaser', 'lessee', 'tenant' => 'acquiring_party',
            'agent' => 'agent',
            default => null,
        };
    }
}
