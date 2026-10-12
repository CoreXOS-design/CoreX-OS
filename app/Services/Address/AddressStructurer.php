<?php

declare(strict_types=1);

namespace App\Services\Address;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Parser + suburb resolver + the rules for writing the structured layer onto a row
 * (.ai/specs/structured-address-matching.md §4, §5). ONE place decides what the structured columns
 * of a Property / TrackedProperty / TrackedPropertyAddress hold, whether the write comes from a model
 * save (the writers) or from the backfill command.
 *
 * Two kinds of column, two rules:
 *   - DERIVED (street_core, street_type, township, lpi_code, address_parse_status/note, tracked
 *     p24_suburb_id/p24_city_id, address_raw once): recomputed when an address source column changed.
 *   - EXISTING columns that are EMPTY (street_number, unit_number, complex_name, scheme_number,
 *     erf_portion, properties.street_name, properties.p24_suburb_id): filled ONLY while empty. Nothing already holding a value is
 *     ever overwritten — an agent's correction, a form's P24 choice and the raw `street_name` all stand.
 */
final class AddressStructurer
{
    /** Source columns whose change means the derived layer must be recomputed. */
    private const SOURCE_COLUMNS = [
        'street_number', 'street_name', 'unit_number', 'section_number', 'complex_name', 'scheme_name', 'scheme_number',
        'suburb', 'town', 'city', 'province', 'erf_number', 'erf_portion', 'lpi_code', 'address',
    ];

    /** Existing columns filled only while empty. */
    private const FILL_IF_EMPTY = ['street_number', 'unit_number', 'complex_name', 'scheme_number', 'erf_portion', 'erf_number'];

    /** @var array<string, array<string, bool>> table => column => exists */
    private static array $columns = [];

    public function __construct(private readonly AddressParser $parser = new AddressParser())
    {
    }

    /**
     * Parse + resolve the suburb. $in uses the AddressParser keys (+ latitude/longitude for the suburb tie-break).
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>  AddressParser::parse() output + p24_suburb_id, p24_city_id, suburb_resolved (bool)
     */
    public function structure(array $in): array
    {
        $parsed = $this->parser->parse($in);

        $suburb = isset($in['suburb']) && trim((string) $in['suburb']) !== '' ? trim((string) $in['suburb']) : null;
        $res = $suburb === null
            ? ['p24_suburb_id' => null, 'p24_city_id' => null]
            : SuburbResolver::resolve(
                $suburb,
                isset($in['province']) ? (string) $in['province'] : null,
                isset($in['latitude']) && is_numeric($in['latitude']) ? (float) $in['latitude'] : null,
                isset($in['longitude']) && is_numeric($in['longitude']) ? (float) $in['longitude'] : null,
            );

        $parsed['p24_suburb_id'] = $res['p24_suburb_id'];
        $parsed['p24_city_id'] = $res['p24_city_id'];
        $parsed['suburb_resolved'] = $res['p24_suburb_id'] !== null;

        // A readable address whose suburb is not on the Property24 list is `review`, never guessed.
        if ($parsed['status'] === 'parsed' && $suburb !== null && ! $parsed['suburb_resolved']) {
            $parsed['status'] = 'review';
            $parsed['notes'][] = 'The suburb "' . $suburb . '" is not a Property24 suburb CoreX knows.';
            $parsed['conflicts'][] = 'suburb_unresolved';
        }

        // No address text held at all (a portal listing only ever carries a suburb) is not an address CoreX failed to read:
        // nothing to review, so the status stays empty and the row never reaches the admin Address Review list.
        if ($parsed['status'] === 'unparseable' && $parsed['raw'] === null) {
            $parsed['status'] = null;
            $parsed['notes'] = [];
        }

        return $parsed;
    }

    /** Parser input built from a model's own attributes (any of the three address models). */
    public function inputFrom(Model $m): array
    {
        $g = fn (string $k) => $this->has($m, $k) || $m->offsetExists($k) ? $m->getAttribute($k) : null;

        return [
            'address'        => $g('address'),
            'street_number'  => $g('street_number'),
            'street_name'    => $g('street_name'),
            'unit_number'    => $g('unit_number'),
            'section_number' => $g('section_number'),
            'complex_name'   => $g('complex_name'),
            'scheme_name'    => $g('scheme_name'),
            'scheme_number'  => $g('scheme_number'),
            'suburb'         => $g('suburb'),
            'town'           => $g('town'),
            'city'           => $g('city'),
            'province'       => $g('province'),
            'erf_number'     => $g('erf_number'),
            'erf_portion'    => $g('erf_portion'),
            'lpi_code'       => $g('lpi_code'),
            'latitude'       => $g('latitude'),
            'longitude'      => $g('longitude'),
        ];
    }

    /**
     * What to write onto $m: [column => value]. Only columns that exist on the table, only
     * derived columns or EMPTY existing ones. Pure with respect to $m (does not set anything).
     *
     * @return array<string, mixed>
     */
    public function updatesFor(Model $m): array
    {
        $s = $this->structure($this->inputFrom($m));
        $out = [];
        $set = function (string $col, mixed $val) use (&$out, $m) {
            if ($this->has($m, $col)) {
                $out[$col] = $val;
            }
        };

        // derived — recomputed
        $set('street_core', $s['street_core']);
        $set('street_type', $s['street_type']);
        $set('township', $s['township']);
        $set('address_parse_status', $s['status']);
        $set('address_parse_note', $s['notes'] === [] ? null : mb_substr(implode(' ', $s['notes']), 0, 255));
        if ($s['lpi_code'] !== null && $this->empty($m, 'lpi_code')) {
            $set('lpi_code', $s['lpi_code']);
        }
        if ($this->has($m, 'p24_city_id') && $m->getTable() === 'tracked_properties') {
            $set('p24_city_id', $s['p24_city_id']);
        }
        if ($m->getTable() === 'tracked_properties' || $m->getTable() === 'tracked_property_addresses') {
            $set('p24_suburb_id', $s['p24_suburb_id']); // derived on these tables
        } elseif ($this->empty($m, 'p24_suburb_id') && $s['p24_suburb_id'] !== null) {
            $set('p24_suburb_id', $s['p24_suburb_id']); // properties: a form's choice stands; fill only when empty
        }
        if ($this->empty($m, 'address_raw') && $s['raw'] !== null) {
            $set('address_raw', $s['raw']);
        }

        // existing columns — only while empty
        foreach (self::FILL_IF_EMPTY as $col) {
            if ($this->empty($m, $col) && ($s[$col] ?? null) !== null) {
                $set($col, $s[$col]);
            }
        }

        // A property typed as free text ("12 Beach Road") has its NUMBER lifted above, so its street NAME must be
        // lifted with it: PropertyObserver derives `address` from the parts, and a number without its street
        // collapses the address to "12". Properties only — tracked rows normalise street_name on their own save.
        if ($m->getTable() === 'properties' && $this->empty($m, 'street_name') && ($s['street_name_typed'] ?? null) !== null) {
            $set('street_name', $s['street_name_typed']);
        }

        return $out;
    }

    /**
     * The model-save writer: sets the structured layer on $m in place when it is due — a new row,
     * a changed address source column, or a row never structured. Never throws (a parser problem is
     * logged and absorbed; a save must never fail because of it) and never touches a row an admin
     * has marked `manual` / `dismissed` unless an address source column actually changed.
     */
    public function apply(Model $m): void
    {
        try {
            if (! $this->has($m, 'street_core')) {
                return; // older schema — nothing to write onto
            }
            $sourceDirty = false;
            foreach (self::SOURCE_COLUMNS as $c) {
                if ($m->isDirty($c)) {
                    $sourceDirty = true;
                    break;
                }
            }
            // The history table keeps no status column: it is structured when its street_core is (or there is nothing to read).
            $status = $this->has($m, 'address_parse_status') ? $m->getAttribute('address_parse_status') : null;
            $notYetStructured = $this->has($m, 'address_parse_status')
                ? $status === null
                : ($m->getAttribute('street_core') === null && filled($m->getAttribute('street_name')));
            $due = ! $m->exists || $sourceDirty || $notYetStructured;
            if (! $due) {
                return;
            }
            if (in_array($status, ['manual', 'dismissed'], true) && ! $sourceDirty) {
                return;
            }
            foreach ($this->updatesFor($m) as $col => $val) {
                $m->setAttribute($col, $val);
            }
        } catch (\Throwable $e) {
            Log::warning('AddressStructurer::apply failed (absorbed)', [
                'model' => $m::class, 'id' => $m->getKey(), 'error' => $e->getMessage(),
            ]);
        }
    }

    private function has(Model $m, string $col): bool
    {
        $t = $m->getTable();

        return self::$columns[$t][$col] ??= Schema::hasColumn($t, $col);
    }

    private function empty(Model $m, string $col): bool
    {
        if (! $this->has($m, $col)) {
            return true;
        }
        $v = $m->getAttribute($col);

        return $v === null || (is_string($v) && trim($v) === '');
    }

    /** Forget the per-table column cache (tests). */
    public static function flushColumnCache(): void
    {
        self::$columns = [];
    }
}
