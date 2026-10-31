<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketIntelligence\Comps\CompDuplicateFlagger;
use Illuminate\Support\Facades\DB;

/**
 * MIC step 5.3: a CMA comp row that arrives with a new report is placed (canonical or repeat of an existing sale) at save time, a
 * soft-deleted canonical hands its sale to the next row, a restored row is placed again. Never throws - a duplicate check must not
 * fail a report import. Raw inserts (DB::table) skip this; `php artisan mic:flag-comp-duplicates --apply` is idempotent and covers them.
 */
final class MicCompDuplicateObserver
{
    public function created(MarketReportCompRow $row): void
    {
        $this->guard(fn () => $this->bucket($row));
    }

    public function deleted(MarketReportCompRow $row): void
    {
        $this->guard(fn () => app(CompDuplicateFlagger::class)->onRowRemoved((int) $row->id));
    }

    public function restored(MarketReportCompRow $row): void
    {
        $this->guard(function () use ($row) {
            DB::table('market_report_comp_rows')->where('id', $row->id)->update(['duplicate_of_id' => null, 'dup_checked_at' => null]);
            $this->bucket($row);
        });
    }

    private function bucket(MarketReportCompRow $row): void
    {
        if ($row->sale_price === null || $row->sale_date === null || ! in_array($row->row_type, ['comp', 'subject'], true)) {
            return;
        }
        app(CompDuplicateFlagger::class)->flagBucket((int) $row->agency_id, $row->sale_date->toDateString(), (int) $row->sale_price);
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
