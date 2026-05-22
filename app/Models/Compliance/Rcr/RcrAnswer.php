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

    /** Phase 9d.1 — period_code discriminator for FIC P1/P2/P3 answer rows. */
    public const PERIOD_P1     = 'p1';
    public const PERIOD_P2     = 'p2';
    public const PERIOD_P3     = 'p3';
    public const PERIOD_STATIC = 'static';
    public const PERIOD_DATE_RANGES = [
        self::PERIOD_P1 => ['2023-07-01', '2024-03-31'],
        self::PERIOD_P2 => ['2024-04-01', '2025-03-31'],
        self::PERIOD_P3 => ['2025-04-01', '2026-03-31'],
    ];

    /** Phase 9d.1 — reusable response-option bands referenced by the FIC seeder. */
    public const OPTIONS_PERCENTAGE_BAND = ['0%', '1-25%', '26-50%', '51-75%', '76-100%'];
    public const OPTIONS_FREQUENCY_BAND  = ['Never', 'Rarely', 'Sometimes', 'Often', 'Always'];
    public const OPTIONS_YES_NO          = ['Yes', 'No'];

    protected $fillable = [
        'submission_id', 'question_id', 'period_code', 'answer_value', 'answer_data_json',
        'is_auto_populated', 'auto_population_source_data', 'manually_edited',
        'last_edited_at', 'last_edited_by_user_id', 'notes', 'status',
        'reviewer_user_id', 'reviewed_at',
        // Phase 9d.1 — clipboard + transposed tracking + final answer format.
        'copied_to_clipboard_at', 'copied_to_clipboard_count',
        'transposed_to_goaml_at', 'final_answer_format',
    ];

    protected $casts = [
        'answer_data_json'             => 'array',
        'is_auto_populated'            => 'boolean',
        'auto_population_source_data'  => 'array',
        'manually_edited'              => 'boolean',
        'last_edited_at'               => 'datetime',
        'reviewed_at'                  => 'datetime',
        // Phase 9d.1.
        'copied_to_clipboard_at'       => 'datetime',
        'copied_to_clipboard_count'    => 'integer',
        'transposed_to_goaml_at'       => 'datetime',
    ];

    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period_code', $period);
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_REVIEWED]);
    }

    public function scopeTransposed($query)
    {
        return $query->whereNotNull('transposed_to_goaml_at');
    }

    public function scopeAwaitingApproval($query)
    {
        return $query->whereIn('status', [self::STATUS_AUTO_FILLED, self::STATUS_IN_PROGRESS, self::STATUS_ANSWERED]);
    }

    /**
     * Phase 9d.1 — formatted value for clipboard / goAML transposition,
     * driven by final_answer_format. Falls back to trimmed answer_value.
     */
    public function getFormattedForClipboardAttribute(): string
    {
        $raw = (string) ($this->answer_value ?? '');
        if ($raw === '') return '';
        $fmt = (string) ($this->final_answer_format ?? '');
        return match ($fmt) {
            'yes_no' => in_array(strtolower(trim($raw)), ['yes', 'y', 'true', '1'], true) ? 'Yes'
                : (in_array(strtolower(trim($raw)), ['no', 'n', 'false', '0'], true) ? 'No' : trim($raw)),
            'percentage' => str_ends_with(trim($raw), '%') ? trim($raw) : trim($raw) . '%',
            'number'     => preg_replace('/[^\d.\-]/', '', $raw) ?? trim($raw),
            'text'       => trim($raw),
            'multi_select' => is_array($this->answer_data_json) && count($this->answer_data_json) > 0
                ? implode(', ', $this->answer_data_json)
                : trim($raw),
            default => trim($raw),
        };
    }

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
