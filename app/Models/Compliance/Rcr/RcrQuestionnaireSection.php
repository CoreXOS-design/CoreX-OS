<?php

declare(strict_types=1);

namespace App\Models\Compliance\Rcr;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RcrQuestionnaireSection extends Model
{
    protected $table = 'rcr_questionnaire_sections';

    protected $fillable = [
        'questionnaire_id', 'section_code', 'title', 'description', 'sort_order',
        // Phase 9d.1 — drives 3-period vs static answer-row creation, and
        // sector-specific applicability gates (e.g. only show Part 8 to
        // estate-agent agencies via applies_when_json).
        'has_period_columns', 'applies_when_json',
    ];

    protected $casts = [
        'has_period_columns' => 'boolean',
        'applies_when_json'  => 'array',
    ];

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(RcrQuestionnaire::class, 'questionnaire_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(RcrQuestion::class, 'section_id')->orderBy('sort_order');
    }
}
