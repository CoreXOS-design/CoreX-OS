<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** A dated CoreX price list (bands + branch fee). The list in force on a date is the latest one whose effective_from is on or before it. */
class BillingPriceList extends Model
{
    protected $fillable = ['effective_from', 'bands', 'branch_rate', 'created_by'];
    protected $casts = ['effective_from' => 'date', 'bands' => 'array', 'branch_rate' => 'float'];

    /** @return array{bands:list<array{from:int,to:int,amount:float}>,branch_rate:float,included:int,effective_from:?Carbon,quote_from:int} */
    public static function asOf(?Carbon $on = null): array
    {
        $on = ($on ?: Carbon::now())->copy()->startOfDay();
        $row = Schema::hasTable('billing_price_lists')
            ? static::query()->whereDate('effective_from', '<=', $on)->orderByDesc('effective_from')->orderByDesc('id')->first()
            : null;
        $bands = $row ? array_values((array) $row->bands) : array_values((array) config('corex-billing.bands.bands', []));
        $top = $bands ? (int) end($bands)['to'] : 0;

        return [
            'bands' => $bands, 'branch_rate' => $row ? (float) $row->branch_rate : (float) config('corex-billing.branches.rate', 750.00),
            'included' => (int) config('corex-billing.branches.included', 1), 'effective_from' => $row?->effective_from, 'quote_from' => $top + 1,
        ];
    }

    /** Lists dated after today, soonest first (scheduled price changes). */
    public static function scheduled(): \Illuminate\Support\Collection
    {
        return Schema::hasTable('billing_price_lists') ? static::query()->whereDate('effective_from', '>', Carbon::today())->orderBy('effective_from')->get() : collect();
    }
}
