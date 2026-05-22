<?php

declare(strict_types=1);

namespace App\Models\Compliance\Rcr;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RcrQuestion extends Model
{
    public const TYPE_YES_NO       = 'yes_no';
    public const TYPE_YES_NO_NA    = 'yes_no_na';
    public const TYPE_FREE_TEXT    = 'free_text';
    public const TYPE_NUMBER       = 'number';
    public const TYPE_PERCENTAGE   = 'percentage';
    public const TYPE_MULTI_SELECT = 'multi_select';
    public const TYPE_SINGLE_SELECT = 'single_select';
    public const TYPE_FILE_UPLOAD  = 'file_upload';
    public const TYPE_COMPOSITE    = 'composite';

    public const ALL_TYPES = [
        self::TYPE_YES_NO, self::TYPE_YES_NO_NA, self::TYPE_FREE_TEXT,
        self::TYPE_NUMBER, self::TYPE_PERCENTAGE, self::TYPE_MULTI_SELECT,
        self::TYPE_SINGLE_SELECT, self::TYPE_FILE_UPLOAD, self::TYPE_COMPOSITE,
    ];

    protected $fillable = [
        'questionnaire_id', 'section_id', 'question_code', 'question_text',
        'answer_type', 'answer_options_json', 'is_required',
        'auto_population_source', 'help_text', 'sort_order',
    ];

    protected $casts = [
        'answer_options_json' => 'array',
        'is_required'         => 'boolean',
    ];

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(RcrQuestionnaire::class, 'questionnaire_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(RcrQuestionnaireSection::class, 'section_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RcrAnswer::class, 'question_id');
    }
}
