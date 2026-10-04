<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-442 — a job card's parts/labour line, from the catalogue or free text. */
class RentalJobCardLine extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
        'rental_catalogue_item_id',
        'type',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'line_total',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(RentalCatalogueItem::class, 'rental_catalogue_item_id');
    }
}
