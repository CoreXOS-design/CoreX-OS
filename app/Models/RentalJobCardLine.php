<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-442 — a job card's parts/labour line, from the catalogue or free text.
 * `rental_vat_type_id`/`custom_vat_rate` are the agent's live VAT choice for
 * this line (Pastel-style, per CLAUDE.md agency-VAT ruling); the
 * `vat_*_snapshot` columns are null until frozen once, at "send to owner as
 * quote" or job-card completion (RentalJobCardVatService::snapshotLines()).
 */
class RentalJobCardLine extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
        'rental_job_card_task_id',
        'rental_catalogue_item_id',
        'type',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'line_total',
        'rental_vat_type_id',
        'custom_vat_rate',
        'vat_type_name_snapshot',
        'vat_rate_snapshot',
        'vat_excl_snapshot',
        'vat_amount_snapshot',
        'vat_incl_snapshot',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'custom_vat_rate' => 'decimal:2',
        'vat_rate_snapshot' => 'decimal:2',
        'vat_excl_snapshot' => 'decimal:2',
        'vat_amount_snapshot' => 'decimal:2',
        'vat_incl_snapshot' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    /** Null means this line sits in the built-in "General" group (no task). */
    public function task(): BelongsTo
    {
        return $this->belongsTo(RentalJobCardTask::class, 'rental_job_card_task_id');
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(RentalCatalogueItem::class, 'rental_catalogue_item_id');
    }

    public function vatType(): BelongsTo
    {
        return $this->belongsTo(RentalVatType::class, 'rental_vat_type_id');
    }

    public function isVatSnapshotted(): bool
    {
        return $this->vat_rate_snapshot !== null || $this->vat_amount_snapshot !== null;
    }
}
