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
