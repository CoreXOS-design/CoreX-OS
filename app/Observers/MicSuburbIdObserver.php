<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MarketReports\MarketReportCompRow;
use App\Models\ProspectingListing;
use App\Services\Address\SuburbResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * MIC step 5.1: every NEW or re-suburbed prospecting listing / CMA comp row gets its `p24_suburb_id` (OUR p24_suburbs.id) at save
 * time, from the suburb text it carries, so the id stays filled without anyone re-running the backfill. Never guesses (an
 * unresolvable text leaves the id NULL), never overwrites an id that is already set while the text is unchanged, and never throws -
 * a suburb lookup problem must not fail a capture or a report import. Raw inserts (DB::table) skip this; the backfill command
 * covers them.
 */
final class MicSuburbIdObserver
{
    private const TEXT_COLUMN = [
        ProspectingListing::class => 'suburb',
        MarketReportCompRow::class => 'suburb_normalised',
    ];

    /** @var array<string, ?int> per-process memo of text => id (a capture batch repeats the same few suburbs) */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public function saving(Model $model): void
    {
        $column = self::TEXT_COLUMN[$model::class] ?? null;
        if ($column === null) {
            return;
        }

        try {
            $text = trim((string) $model->getAttribute($column));
            if ($text === '') {
                return;
            }
            if ($model->getAttribute('p24_suburb_id') !== null && ! $model->isDirty($column)) {
                return;
            }

            $key = mb_strtolower($text);
            if (! array_key_exists($key, self::$memo)) {
                self::$memo[$key] = SuburbResolver::resolve($text)['p24_suburb_id'];
            }
            if (self::$memo[$key] !== null) {
                $model->setAttribute('p24_suburb_id', self::$memo[$key]);
            } elseif ($model->isDirty($column)) {
                $model->setAttribute('p24_suburb_id', null);
            }
        } catch (\Throwable $e) {
            // never block a save
        }
    }
}
