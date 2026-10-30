<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** CoreX's published prices (corexweb.co.za/pricing) as one owner-editable row. Defaults are the published list; edit them on Revenue > Price list. */
class RevenuePriceList extends Model
{
    protected $table = 'revenue_price_list';
    protected $fillable = ['prices'];
    protected $casts = ['prices' => 'array'];

    /** One flat monthly price per band of ACTIVE agents (unlimited within the band), from corexweb.co.za/pricing. Edit on Revenue > Price list. */
    public const DEFAULTS = [
        'bands' => [
            ['from' => 1,  'to' => 1,  'price' => 450],
            ['from' => 2,  'to' => 2,  'price' => 900],
            ['from' => 3,  'to' => 5,  'price' => 1495],
            ['from' => 6,  'to' => 10, 'price' => 2495],
            ['from' => 11, 'to' => 15, 'price' => 3295],
            ['from' => 16, 'to' => 20, 'price' => 3995],
            ['from' => 21, 'to' => 25, 'price' => 4595],
            ['from' => 26, 'to' => 30, 'price' => 5095],
        ],
        'quote_from' => 31,   // this many agents or more: on quotation, priced by hand
        'branch_fee' => 750,  // each branch after the first, a month
    ];

    public static function current(): array
    {
        $row = static::query()->first();

        $prices = array_merge(self::DEFAULTS, $row?->prices ?? []);
        if (empty($prices['bands']) || ! is_array($prices['bands'])) {
            $prices['bands'] = self::DEFAULTS['bands'];
        }

        return $prices;
    }
}
