<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\RentalTakeOnImportRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * AT-479 / rental-takeon-import.md R4 "List standard" — the review screen's search, filters and sort, in ONE place so the
 * list, the bulk actions ("tick all ready" under the current filter) and the tests all mean the same thing.
 */
final class ReosRowQuery
{
    public const SORTS = ['row' => 'row_number', 'address' => 'payload_json->address', 'owner' => 'payload_json->owner_name', 'tenant' => 'payload_json->tenant_name', 'status' => 'payload_json->lease_status', 'rent' => 'payload_json->rent'];

    public const FILTER_KEYS = ['q', 'property', 'readiness', 'lease_status', 'state', 'names', 'sort', 'dir'];

    /** @param array<string,mixed> $p  request query values */
    public static function apply(Builder $query, array $p): Builder
    {
        if (($q = trim((string) ($p['q'] ?? ''))) !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('payload_json->address', 'like', $like)
                    ->orWhere('payload_json->owner_name', 'like', $like)
                    ->orWhere('payload_json->tenant_name', 'like', $like)
                    ->orWhere('payload_json->agent_text', 'like', $like)
                    ->orWhere('external_id', 'like', $like);
            });
        }

        match ($p['property'] ?? '') {
            'matched' => $query->where('property_match_action', RentalTakeOnImportRow::ACTION_MATCH),
            'unmatched' => $query->where(fn (Builder $w) => $w->where('property_match_action', '!=', RentalTakeOnImportRow::ACTION_MATCH)->orWhereNull('property_match_action')),
            default => null,
        };

        match ($p['readiness'] ?? '') {
            'blocked' => $query->where('is_blocked', true),
            'ready' => $query->where('is_blocked', false)->where('status', '!=', RentalTakeOnImportRow::STATUS_CONFIRMED),
            'ticked' => $query->where('selected', true),
            'unticked' => $query->where('selected', false),
            default => null,
        };

        if (in_array($p['lease_status'] ?? '', ['Active', 'Expiring', 'Draft'], true)) {
            $query->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.lease_status'))) = ?", [mb_strtolower($p['lease_status'])]);
        }

        match ($p['state'] ?? '') {
            'waiting' => $query->where('status', '!=', RentalTakeOnImportRow::STATUS_CONFIRMED),
            'imported' => $query->where('status', RentalTakeOnImportRow::STATUS_CONFIRMED),
            'failed' => $query->whereNotNull('import_error'),
            'existing' => $query->whereNotNull('existing_lease_id'),
            default => null,
        };

        // Contacts matched on a phone / e-mail under a different name than reOS gives (a warning, kept as a filter).
        if (($p['names'] ?? '') === 'differs') {
            $query->where('decisions_json->name_differs', true);
        } elseif (($p['names'] ?? '') === 'spelling') {
            $query->where('decisions_json->spelling_differs', true);   // the muted "Spelling differs" — not counted in the amber total
        }

        $sort = self::SORTS[$p['sort'] ?? 'row'] ?? self::SORTS['row'];
        $dir = strtolower((string) ($p['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        if ($sort === 'payload_json->rent') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.rent')) AS DECIMAL(14,2)) {$dir}");
        } else {
            $query->orderBy($sort, $dir);
        }

        return $query->orderBy('row_number');
    }

    /** @param array<string,mixed> $all */
    public static function only(array $all): array
    {
        return array_filter(array_intersect_key($all, array_flip(self::FILTER_KEYS)), fn ($v) => $v !== null && $v !== '');
    }
}
