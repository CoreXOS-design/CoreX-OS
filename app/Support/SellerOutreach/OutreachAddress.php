<?php

declare(strict_types=1);

namespace App\Support\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;

/**
 * AT-61 — the address source a seller pitch is composed against.
 *
 * A pitch needs an address to reference and a suburb/town to make its
 * (honest) area-level demand statement. That address can come from EITHER:
 *   - a linked Property (the full pitch, incl. the per-property matching
 *     claim — property carries property_type/beds/price), OR
 *   - a Contact's structured property-address (AT-60 columns), with NO
 *     Property created — an area-level pitch only.
 *
 * This DTO is the single shape both sources produce so the composer's pitch
 * builder consumes an address, never a hard-typed Property. The five fields
 * are exactly what an area-level pitch needs: a street line, a suburb, an
 * (optional pre-resolved) town, and the P24 suburb id for segment lookups.
 *
 * The per-property matching claim (matching_buyer_count) is NOT derivable
 * from an address — it needs property_type/beds/price — so it lives with the
 * Property, not here. See SellerOutreachComposerService::buildMergeFields().
 */
final class OutreachAddress
{
    public function __construct(
        public readonly ?string $streetNumber,
        public readonly ?string $streetName,
        public readonly ?string $suburb,
        public readonly ?string $town,
        public readonly ?int $p24SuburbId,
    ) {}

    public static function fromProperty(Property $property): self
    {
        return new self(
            streetNumber: self::clean($property->street_number),
            streetName:   self::clean($property->street_name),
            suburb:       self::clean($property->suburb),
            // Property does not store a resolved town — the composer derives
            // it from the suburb via ProspectingConfigurationService, exactly
            // as the existing (pre-AT-61) flow did. Leave null here.
            town:         null,
            p24SuburbId:  $property->p24_suburb_id !== null ? (int) $property->p24_suburb_id : null,
        );
    }

    public static function fromContact(Contact $contact): self
    {
        return new self(
            streetNumber: self::clean($contact->street_number),
            streetName:   self::clean($contact->street_name),
            suburb:       self::clean($contact->suburb),
            town:         null,
            p24SuburbId:  $contact->p24_suburb_id !== null ? (int) $contact->p24_suburb_id : null,
        );
    }

    /**
     * Composed one-line address — mirrors the property-mode address string the
     * pitch used pre-AT-61 (street line + suburb). Returns a clear stand-in
     * rather than an empty string so a send log line is never blank.
     */
    public function displayAddress(): string
    {
        $line1 = trim(((string) ($this->streetNumber ?? '')) . ' ' . ((string) ($this->streetName ?? '')));
        $parts = array_filter([
            $line1 !== '' ? $line1 : null,
            ($this->suburb !== null && $this->suburb !== '') ? $this->suburb : null,
        ]);
        return !empty($parts) ? implode(', ', $parts) : '(address unavailable)';
    }

    /** True when no usable address component is present. */
    public function isEmpty(): bool
    {
        return ($this->streetNumber === null || $this->streetNumber === '')
            && ($this->streetName === null || $this->streetName === '')
            && ($this->suburb === null || $this->suburb === '');
    }

    private static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
