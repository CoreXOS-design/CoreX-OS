<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Support;

use App\Models\Docuperfect\DataDictionaryEntry;
use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Contracts\DictionaryEntry;
use App\Support\Docuperfect\DataDictionary\DataType;

/**
 * AT-177 — the WS0-owned, Eloquent-backed {@see DataDictionaryResolver} (the seam cc3's WS1
 * linter contract explicitly delegates to WS0/cc2).
 *
 * Resolves a binding ref against the versioned `data_dictionary_entries` store, point-in-time
 * at the pinned dictionary version, and maps the CoreX {@see DataDictionaryEntry} row into the
 * framework-free {@see DictionaryEntry} VO the linter reads for L1 (binding resolves) and L5
 * (validation coherence). Optionally agency-scoped so agency overrides (Door B) resolve ahead
 * of the CoreX-standard entry; default scope is CoreX-standard (Door A, the reference proofs).
 */
final class EloquentDataDictionaryResolver implements DataDictionaryResolver
{
    public function __construct(
        private readonly ?int $agencyId = null,
    ) {
    }

    public function has(string $ref, int $dictionaryVersion): bool
    {
        return DataDictionaryEntry::resolve($ref, $this->agencyId, $dictionaryVersion) !== null;
    }

    public function get(string $ref, int $dictionaryVersion): ?DictionaryEntry
    {
        $entry = DataDictionaryEntry::resolve($ref, $this->agencyId, $dictionaryVersion);

        return $entry === null ? null : self::toContractEntry($entry);
    }

    /**
     * Pure mapping: a CoreX dictionary row → the linter's framework-free contract VO.
     *
     * Translates the WS0 `data_type` into the linter's canonical `type` and normalises the
     * entry's stored validation params into the contract's recognised constraint keys
     * (required/type/min_length/max_length/min/max/regex/enum/decimals).
     */
    public static function toContractEntry(DataDictionaryEntry $model): DictionaryEntry
    {
        $type = DataType::from($model->data_type);
        $params = is_array($model->validation) ? $model->validation : [];

        $validation = ['type' => self::canonicalType($type)];

        // marital_status option list → enum constraint.
        if ($type === DataType::MaritalStatus && ! empty($params['options']) && is_array($params['options'])) {
            $validation['enum'] = array_values(array_map('strval', $params['options']));
        }

        // Free-text length bounds (property_address, designation, scheme, generic text).
        if (isset($params['min'])) {
            $validation['min_length'] = (int) $params['min'];
        }
        if (isset($params['max'])) {
            $validation['max_length'] = (int) $params['max'];
        }

        // Absolute date bounds → min/max (ISO strings compare lexically, per the contract).
        if (isset($params['after_date'])) {
            $validation['min'] = (string) $params['after_date'];
        }
        if (isset($params['before_date'])) {
            $validation['max'] = (string) $params['before_date'];
        }

        if (isset($params['regex'])) {
            $validation['regex'] = (string) $params['regex'];
        }

        return new DictionaryEntry(
            ref: $model->key,
            category: $model->category,
            type: self::canonicalType($type),
            validation: $validation,
            label: $model->label,
        );
    }

    /**
     * Map the WS0 {@see DataType} to the linter contract's canonical `type` string
     * (documented set: string|integer|decimal|money_zar|sa_id|ppra_no|date|email|tel|
     * boolean|enum). Types without a dedicated canonical form degrade to 'string' — the
     * per-value FORMAT rule still lives on the WS0 entry; the linter's `type` is for
     * structural coherence (L5), not the field-level format check.
     */
    private static function canonicalType(DataType $type): string
    {
        return match ($type) {
            DataType::ZarMoney => 'money_zar',
            DataType::SaId => 'sa_id',
            DataType::PpraNo, DataType::FfcNo => 'ppra_no',
            DataType::Date => 'date',
            DataType::MaritalStatus => 'enum',
            DataType::ErfNumber, DataType::TitleDeed, DataType::SchemeName,
            DataType::UnitNo, DataType::Gps, DataType::FullName, DataType::Text => 'string',
        };
    }
}
