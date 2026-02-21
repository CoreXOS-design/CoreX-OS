<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresentationVersion extends Model
{
    protected $fillable = [
        'presentation_id',
        'compiled_by',
        'blueprint_version',
        'analytics_run_id',
        'probability_run_id',
        'data_snapshot_json',
        'compiled_at',
    ];

    protected $casts = [
        'compiled_at' => 'datetime',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function compiledBy()
    {
        return $this->belongsTo(User::class, 'compiled_by');
    }

    public function getSnapshotArray(): array
    {
        return json_decode($this->data_snapshot_json, true) ?? [];
    }
}
