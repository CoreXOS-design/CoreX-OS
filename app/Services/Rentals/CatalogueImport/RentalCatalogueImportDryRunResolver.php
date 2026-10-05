<?php

namespace App\Services\Rentals\CatalogueImport;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalVatType;
use App\Services\Rentals\RentalJobCardVatService;

/**
 * .ai/specs/rental-work-orders.md §14.19 — resolves one parsed import row
 * against THIS agency's own Type/Unit/VAT-type lists and existing catalogue
 * (duplicate-by-code detection), producing a dry-run result: what would
 * happen (create/update/skip/error) and why, without writing anything.
 * RentalCatalogueImportController::confirm() is the only place that ever
 * persists — this class only ever reads.
 */
class RentalCatalogueImportDryRunResolver
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP = 'skip';
    public const ACTION_ERROR = 'error';

    public const ON_DUPLICATE_UPDATE = 'update';
    public const ON_DUPLICATE_SKIP = 'skip';

    public function __construct(private RentalCatalogueImportRowParser $parser, private RentalJobCardVatService $vat)
    {
    }

    /**
     * @param  array<string, int>  $codesSeenThisFile  code (uppercased) => first row_number it appeared on — mutated across calls so a second occurrence in the same file is caught.
     */
    public function resolve(int $rowNumber, array $payload, Agency $agency, string $onDuplicate, array &$codesSeenThisFile): array
    {
        $errors = [];

        $code = trim((string) ($payload['code'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $typeName = trim((string) ($payload['type'] ?? ''));
        $unitName = trim((string) ($payload['unit'] ?? ''));
        $vatTypeName = trim((string) ($payload['vat_type'] ?? ''));

        if ($code === '') {
            $errors[] = 'Code is required.';
        } elseif (strlen($code) > 50) {
            $errors[] = 'Code is longer than 50 characters.';
        }

        if ($description === '') {
            $errors[] = 'Description is required.';
        } elseif (strlen($description) > 500) {
            $errors[] = 'Description is longer than 500 characters.';
        }

        $typeId = null;
        if ($typeName === '') {
            $errors[] = 'Type is required.';
        } else {
            $type = RentalCatalogueItemType::active()->where('agency_id', $agency->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($typeName)])->first();
            if (! $type) {
                $errors[] = "Unknown type '{$typeName}' — add it first in Settings > Parts & Labour Catalogue > Manage types & units, or check spelling.";
            } else {
                $typeId = $type->id;
            }
        }

        $unitId = null;
        if ($unitName === '') {
            $errors[] = 'Unit is required.';
        } else {
            $unit = RentalCatalogueUnit::active()->where('agency_id', $agency->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($unitName)])->first();
            if (! $unit) {
                $errors[] = "Unknown unit '{$unitName}' — add it first in Settings > Parts & Labour Catalogue > Manage types & units, or check spelling.";
            } else {
                $unitId = $unit->id;
            }
        }

        $vatTypeId = null;
        $vatRate = 0.0;
        $customRate = null;
        if ($agency->vat_registered && $vatTypeName !== '') {
            $vatType = RentalVatType::active()->where('agency_id', $agency->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($vatTypeName)])->first();
            if (! $vatType) {
                $errors[] = "Unknown VAT type '{$vatTypeName}' — add it first in Settings > VAT Types, or check spelling.";
            } elseif ($vatType->rate_mode === RentalVatType::RATE_MODE_CUSTOM_PER_LINE) {
                $errors[] = "'{$vatTypeName}' needs a custom rate per item — the import template has no column for that. Import this row without a VAT type, then set its rate on the edit screen.";
            } else {
                $vatTypeId = $vatType->id;
                $vatRate = (float) $vatType->liveRate();
            }
        }

        // Price — excl wins when both are given (same rule as the template's
        // own Instructions sheet). Not VAT registered: incl is meaningless
        // (no VAT to strip), so excl-or-incl is read as one plain amount.
        $priceExclRaw = $payload['price_excl'] ?? null;
        $priceInclRaw = $payload['price_incl'] ?? null;
        $priceExcl = $this->parser->parseNumber($priceExclRaw);
        $priceIncl = $this->parser->parseNumber($priceInclRaw);
        $exclGivenButInvalid = $priceExclRaw !== null && trim((string) $priceExclRaw) !== '' && $priceExcl === null;
        $inclGivenButInvalid = $priceInclRaw !== null && trim((string) $priceInclRaw) !== '' && $priceIncl === null;
        if ($exclGivenButInvalid) {
            $errors[] = "Price (excl VAT) '{$priceExclRaw}' is not a number.";
        }
        if ($inclGivenButInvalid) {
            $errors[] = "Price (incl VAT) '{$priceInclRaw}' is not a number.";
        }

        $defaultPrice = null;
        if ($priceExcl !== null) {
            $defaultPrice = $priceExcl;
        } elseif ($priceIncl !== null) {
            $defaultPrice = ($agency->vat_registered && $vatRate > 0)
                ? $this->vat->splitAmount($priceIncl, $vatRate, Agency::VAT_CAPTURE_INCL)['excl']
                : $priceIncl;
        }

        // Duplicate-by-code — against the existing catalogue, then against
        // this same file (a second row reusing a code already consumed
        // earlier in the upload is always an error, regardless of mode).
        $action = self::ACTION_CREATE;
        $existingItemId = null;
        if ($code !== '') {
            $codeKey = mb_strtoupper($code);
            if (isset($codesSeenThisFile[$codeKey])) {
                $errors[] = "Duplicate code within this file — also used on row {$codesSeenThisFile[$codeKey]}.";
            } else {
                $codesSeenThisFile[$codeKey] = $rowNumber;

                $existing = RentalCatalogueItem::where('agency_id', $agency->id)->where('code', $code)->first();
                if ($existing) {
                    $existingItemId = $existing->id;
                    $action = $onDuplicate === self::ON_DUPLICATE_UPDATE ? self::ACTION_UPDATE : self::ACTION_SKIP;
                }
            }
        }

        if ($errors !== []) {
            $action = self::ACTION_ERROR;
        }

        return [
            'row_number' => $rowNumber,
            'code' => $code,
            'description' => $description,
            'type_name' => $typeName,
            'unit_name' => $unitName,
            'vat_type_name' => $vatTypeName,
            'price_excl_input' => $priceExclRaw,
            'price_incl_input' => $priceInclRaw,
            'action' => $action,
            'existing_item_id' => $existingItemId,
            'errors' => $errors,
            'resolved' => [
                'rental_catalogue_item_type_id' => $typeId,
                'rental_catalogue_unit_id' => $unitId,
                'default_rental_vat_type_id' => $vatTypeId,
                'default_custom_vat_rate' => $customRate,
                'default_price' => $defaultPrice,
            ],
        ];
    }
}
