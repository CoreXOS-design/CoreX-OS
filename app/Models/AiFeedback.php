<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    use SoftDeletes;

    protected $table = 'ai_feedback';

    protected $fillable = [
        'message_id',
        'user_id',
        'rating',
        'comment',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePositive($query)
    {
        return $query->where('rating', 'up');
    }

    public function scopeNegative($query)
    {
        return $query->where('rating', 'down');
    }
}
