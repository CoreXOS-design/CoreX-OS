<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class P24ImportRun extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'p24_import_runs';

    protected $fillable = [
        'user_id',
        'agency_id',
        'kind',
        'status',
        'agents_csv_path',
        'listings_csv_path',
        'images_csv_path',
        'counts_json',
        'confirmed_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'counts_json'   => 'array',
        'confirmed_at'  => 'datetime',
        'completed_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(P24ImportRow::class, 'run_id');
    }
}
