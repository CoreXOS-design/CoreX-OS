<?php

declare(strict_types=1);

namespace App\Models\Compliance\Rcr;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 9d — versioned RCR template (e.g. FIC 2026 Composite).
 * Future FIC revisions land as new rows; existing submissions remain
 * bound to their original questionnaire for audit fidelity.
 */
final class RcrQuestionnaire extends Model
{
    protected $fillable = [
        'key', 'title', 'description', 'issued_by', 'directive_reference',
        'reporting_period_from', 'reporting_period_to', 'submission_deadline',
        'submission_platform', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'reporting_period_from' => 'date',
        'reporting_period_to'   => 'date',
        'submission_deadline'   => 'date',
        'is_active'             => 'boolean',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(RcrQuestionnaireSection::class, 'questionnaire_id')
            ->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(RcrQuestion::class, 'questionnaire_id')
            ->orderBy('sort_order');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(RcrSubmission::class, 'questionnaire_id');
    }
}
