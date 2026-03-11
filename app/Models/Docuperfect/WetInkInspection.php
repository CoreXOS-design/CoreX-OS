<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WetInkInspection extends Model
{
    use SoftDeletes;

    protected $table = 'wet_ink_inspections';

    protected $fillable = [
        'signature_request_id',
        'inspector_user_id',
        'checklist_json',
        'result',
        'notes',
    ];

    protected $casts = [
        'checklist_json' => 'array',
    ];

    // Result constants
    const RESULT_APPROVED = 'approved';
    const RESULT_REJECTED = 'rejected';

    // --- Relationships ---

    public function signingRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    // --- Helpers ---

    public function isApproved(): bool
    {
        return $this->result === self::RESULT_APPROVED;
    }
}
