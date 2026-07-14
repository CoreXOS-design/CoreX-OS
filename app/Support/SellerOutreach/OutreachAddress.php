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
 * builder consumes an address, never a hard-typed Property. The fields are
 * exactly what an area-level pitch needs: a street line, a suburb, an
 * (optional pre-resolved) town, and the P24 suburb id for segment lookups.
 *
 * The per-property matching claim (matching_buyer_count) is NOT derivable
 * from an address — it needs property_type/beds/price — so it lives with the
 * Property, not here. See SellerOutreachComposerService::buildMergeFields().
 *
 * AT-266 — the address of record wins.
 *
 * This DTO used to compose the street line ONLY from street_number +
 * street_name, and never looked at `properties.address` — the column the agent
 * actually typed and sees on screen. Those derived columns are machine-written
 * (portal imports, parsers), and 17 live properties carried polluted ones: the
 * complex and unit bled into street_name and the number was then prepended a
 * SECOND time, so a property whose address of record is "73 Marine Drive" was
 * pitched to its owner as "73 26 Stafford Close Marine Drive". A multiline
 * address was collapsed with the newline deleted and no separator, producing
 * "Umzimkhulu Court40 Bulwer Street".
 *
 * The class fix, not the 17-row fix: when a Property has an address of record,
 * THAT is what the seller is shown. The derived columns are a fallback for when
 * it is blank — and even then the number is never prepended twice.
 *
 * The asymmetry with Contact is deliberate and load-bearing:
 * `contacts.address` is the person's RESIDENTIAL address, NOT the property being
 * pitched (Contact::composeStructuredAddress: "it does NOT touch the residential
 * `address` field"). A contact may live in Durban and be selling in Uvongo. So
 * fromContact() passes NO address of record — the structured AT-60 columns are
 * the only truth about the property there, and reconciling against the
 * residential address would pitch people about the house they live in.
 */
final class OutreachAddress
{
    public function __construct(
        public readonly ?string $streetNumber,
        public readonly ?string $streetName,
        public readonly ?string $suburb,
        public readonly ?string $town,
        public readonly ?int $p24SuburbId,
        /**
         * The property's own free-text address — authoritative when present.
         * NULL for a contact-sourced address (see the class docblock).
         */
        public readonly ?string $addressOfRecord = null,
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
            addressOfRecord: self::clean($property->address),
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
            // Deliberately NOT $contact->address — that is where the person LIVES,
            // not the property being pitched. See the class docblock.
            addressOfRecord: null,
        );
    }

    /**
     * The street line the seller is shown: the property's address of record when
     * it has one, otherwise the structured columns.
     */
    public function streetLine(): string
    {
        if ($this->addressOfRecord !== null && $this->addressOfRecord !== '') {
            return self::flatten($this->addressOfRecord);
        }
        return $this->composeStreetFromColumns();
    }

    /**
     * Composed one-line address — street line + suburb. Returns a clear stand-in
     * rather than an empty string so a send log line is never blank.
     */
    public function displayAddress(): string
    {
        $line = $this->streetLine();
        $suburb = ($this->suburb !== null && $this->suburb !== '') ? $this->suburb : null;

        // The address of record often already ends in the suburb ("… , Uvongo").
        // Appending it again would read "1 Alamien Avenue, Uvongo, Uvongo".
        if ($suburb !== null && $line !== '' && self::mentions($line, $suburb)) {
            $suburb = null;
        }

        $parts = array_filter([$line !== '' ? $line : null, $suburb]);

        return !empty($parts) ? implode(', ', $parts) : '(address unavailable)';
    }

    /** True when no usable address component is present at all. */
    public function isEmpty(): bool
    {
        return $this->streetLine() === ''
            && ($this->suburb === null || $this->suburb === '');
    }

    /** True when there is a street to name — not just a suburb. */
    public function hasStreet(): bool
    {
        return $this->streetLine() !== '';
    }

    /**
     * AT-266 — what the blank-address send-gate actually needs.
     *
     * Every template opens with "your property at {property_address}". A suburb
     * alone renders "your property at Uvongo" — a pitch that names no address.
     * The old gate was OR-shaped (isEmpty() only blocked when street AND suburb
     * were both missing), so 46 live properties and 211 contacts could send a
     * street-less pitch. Its error message already promised "street and suburb";
     * this is the check finally saying what the message says.
     */
    public function isIncomplete(): bool
    {
        return !$this->hasStreet()
            || $this->suburb === null || $this->suburb === '';
    }

    /**
     * Street line from the structured columns, used only when there is no address
     * of record. Never prepends the number when the name already carries it —
     * street_number "73" + street_name "73 Marine Drive" is "73 Marine Drive",
     * not "73 73 Marine Drive".
     */
    private function composeStreetFromColumns(): string
    {
        $number = (string) ($this->streetNumber ?? '');
        $name   = (string) ($this->streetName ?? '');

        if ($name === '') {
            return trim($number);
        }
        if ($number === '' || self::startsWithNumber($name, $number)) {
            return trim($name);
        }

        return trim($number . ' ' . $name);
    }

    /** Does $name already open with the street number (as a whole token)? */
    private static function startsWithNumber(string $name, string $number): bool
    {
        return (bool) preg_match(
            '/^' . preg_quote(trim($number), '/') . '(?![0-9A-Za-z])/u',
            trim($name)
        );
    }

    /**
     * Collapse a free-text address to one clean line. A multiline address must
     * become "Umzimkhulu Court, 40 Bulwer Street" — never "Umzimkhulu Court40
     * Bulwer Street", which is what deleting the newline outright produced.
     */
    private static function flatten(string $raw): string
    {
        $s = preg_replace('/\R+/u', ', ', trim($raw)) ?? $raw;   // line breaks → separator
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;              // runs of space → one
        $s = preg_replace('/\s*,\s*/u', ', ', $s) ?? $s;         // tidy comma spacing
        $s = preg_replace('/(,\s*)+/u', ', ', $s) ?? $s;         // collapse repeats

        return trim($s, " \t\n\r\0\x0B,");
    }

    /** Case-insensitive "does this line already name the suburb". */
    private static function mentions(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower(trim($needle)));
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
