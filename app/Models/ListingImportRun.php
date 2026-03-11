<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListingImportRun extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'imported_by_user_id',
        'branch_id',
        'source',
        'original_filename',
        'header_row',
        'column_mapping',
        'agent_mapping',
        'status',
        'error_message',
    ];

    protected $casts = [
        'branch_id'       => 'integer',
        'header_row'      => 'array',
        'column_mapping'  => 'array',
        'agent_mapping'   => 'array',
    ];

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ListingImportRow::class, 'run_id');
    }
}
