<?php

declare(strict_types=1);

namespace App\Models\Compliance\Rcr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RcrAnswerEvidence extends Model
{
    protected $table = 'rcr_answer_evidence';

    public const TYPE_DOCUMENT_UPLOAD        = 'document_upload';
    public const TYPE_COREX_RECORD_REFERENCE = 'corex_record_reference';
    public const TYPE_EXTERNAL_URL           = 'external_url';
    public const TYPE_NOTE                   = 'note';

    protected $fillable = [
        'answer_id', 'evidence_type', 'document_path', 'corex_record_table',
        'corex_record_id', 'external_url', 'description', 'added_by_user_id',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(RcrAnswer::class, 'answer_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}
