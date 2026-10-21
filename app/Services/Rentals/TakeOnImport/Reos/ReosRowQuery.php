<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\RentalTakeOnImportRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * AT-481 / rental-takeon-import.md "reOS review screen rebuild" — the review screen's search and its one "Show" switch, in ONE place so
 * the page, the queue and the tests all mean the same thing. Order is always the sheet's own row order.
 *
 * Show: all | ready | needs | imported | failed — the states of ReosQuestions::state, as SQL over the columns ReosBlockers::refresh keeps.
 */
final class ReosRowQuery
{
    public const SHOWS = ['all' => 'All', 'ready' => 'Ready', 'needs' => 'Needs you', 'imported' => 'Imported', 'failed' => 'Failed'];

    public const FILTER_KEYS = ['q', 'show'];

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

        $confirmed = RentalTakeOnImportRow::STATUS_CONFIRMED;
        // failed = not imported and (the file rejected it, an import attempt said why not, or a hard stop no button answers)
        $failed = fn (Builder $w) => $w->where('status', RentalTakeOnImportRow::STATUS_ERROR)->orWhereNotNull('import_error')->orWhere('decisions_json->hard', true);
        $notFailed = fn (Builder $w) => $w->where('status', '!=', RentalTakeOnImportRow::STATUS_ERROR)->whereNull('import_error')
            ->where(fn (Builder $h) => $h->whereNull('decisions_json->hard')->orWhere('decisions_json->hard', false));
        $needsCount = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(decisions_json, '$.needs_count')), '0') AS UNSIGNED)";

        match ($p['show'] ?? 'all') {
            'imported' => $query->where('status', $confirmed),
            'failed' => $query->where('status', '!=', $confirmed)->where($failed),
            'needs' => $query->where('status', '!=', $confirmed)->where($notFailed)->whereRaw("{$needsCount} > 0"),
            'ready' => $query->where('status', '!=', $confirmed)->where($notFailed)->whereRaw("{$needsCount} = 0"),
            default => null,
        };

        return $query->orderBy('row_number');
    }

    /** @param array<string,mixed> $all */
    public static function only(array $all): array
    {
        $f = array_filter(array_intersect_key($all, array_flip(self::FILTER_KEYS)), fn ($v) => $v !== null && $v !== '');
        if (isset($f['show']) && ! array_key_exists($f['show'], self::SHOWS)) {
            unset($f['show']);
        }

        return $f;
    }
}
