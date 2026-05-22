<?php

declare(strict_types=1);

namespace App\Models\Compliance\Rcr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RcrAnswer extends Model
{
    public const STATUS_UNANSWERED  = 'unanswered';
    public const STATUS_AUTO_FILLED = 'auto_filled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ANSWERED    = 'answered';
    public const STATUS_REVIEWED    = 'reviewed';
    public const STATUS_APPROVED    = 'approved';

    protected $fillable = [
        'submission_id', 'question_id', 'answer_value', 'answer_data_json',
        'is_auto_populated', 'auto_population_source_data', 'manually_edited',
        'last_edited_at', 'last_edited_by_user_id', 'notes', 'status',
        'reviewer_user_id', 'reviewed_at',
    ];

    protected $casts = [
        'answer_data_json'             => 'array',
        'is_auto_populated'            => 'boolean',
        'auto_population_source_data'  => 'array',
        'manually_edited'              => 'boolean',
        'last_edited_at'               => 'datetime',
        'reviewed_at'                  => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(RcrSubmission::class, 'submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(RcrQuestion::class, 'question_id');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(RcrAnswerEvidence::class, 'answer_id');
    }

    public function isAnswered(): bool
    {
        return in_array($this->status, [
            self::STATUS_ANSWERED, self::STATUS_REVIEWED, self::STATUS_APPROVED,
        ], true);
    }
}
