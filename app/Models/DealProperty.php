<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-398 — the real deal↔property link, for EVERY deal (old single-property
 * deals get one backfilled row here too — see the creating migration). A
 * custom Pivot model (not an anonymous pivot array) specifically so this can
 * be soft-deleted: removing a property from a deal keeps the row (Johan:
 * "keep a note that it was once there"), it never disappears.
 *
 * No hard delete, ever, matching every other pivot-as-real-record in this
 * codebase (deal_branches has the same "originator/co-branch" shape without
 * SoftDeletes only because a branch is never "removed", just replaced).
 */
class DealProperty extends Pivot
{
    use SoftDeletes;

    protected $table = 'deal_properties';

    public $incrementing = true;

    protected $fillable = [
        'deal_id', 'property_id', 'is_primary', 'allocated_price', 'allocated_commission',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'allocated_price' => 'integer',
        'allocated_commission' => 'decimal:2',
    ];
}
