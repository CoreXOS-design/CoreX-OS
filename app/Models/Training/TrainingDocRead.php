<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingDocRead extends Model
{
    use BelongsToAgency;

    protected $table = 'training_doc_reads';

    protected $fillable = [
        'user_id', 'doc_id', 'agency_id', 'sections_completed',
        'last_section_read', 'last_read_at', 'completed_at',
        'is_outdated_since',
    ];

    protected $casts = [
        'sections_completed' => 'array',
        'last_read_at'       => 'datetime',
        'completed_at'       => 'datetime',
        'is_outdated_since'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function doc(): BelongsTo
    {
        return $this->belongsTo(TrainingDoc::class, 'doc_id');
    }
}
