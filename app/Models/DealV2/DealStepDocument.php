<?php

namespace App\Models\DealV2;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealStepDocument extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'deal_step_instance_id',
        'document_id',
        'file_path',
        'file_name',
        'uploaded_by_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(DealStepInstance::class, 'deal_step_instance_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
