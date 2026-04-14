<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class P24ImportRow extends Model
{
    use SoftDeletes;

    protected $table = 'p24_import_rows';

    protected $fillable = [
        'run_id',
        'row_type',
        'external_id',
        'payload_json',
        'mapped_json',
        'action',
        'status',
        'resolved_agent_id',
        'target_id',
        'errors_json',
        'image_urls_json',
        'confirmed_at',
        'excluded_at',
        'confirmed_by',
    ];

    protected $casts = [
        'payload_json'    => 'array',
        'mapped_json'     => 'array',
        'errors_json'     => 'array',
        'image_urls_json' => 'array',
        'confirmed_at'    => 'datetime',
        'excluded_at'     => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(P24ImportRun::class, 'run_id');
    }

    public function resolvedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_agent_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
