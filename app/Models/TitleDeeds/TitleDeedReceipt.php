<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The handover record. ONE per deed (unique), so two concurrent handovers can never both land. */
class TitleDeedReceipt extends Model
{
    use BelongsToAgency;

    protected $table = 'title_deed_receipts';

    protected $fillable = [
        'agency_id', 'title_deed_id', 'method', 'collector_type', 'collector_contact_id', 'collector_name',
        'collector_id_number', 'collector_relationship', 'id_number_seen', 'consent_id', 'signature_path',
        'signed_typed_name', 'signed_at', 'signed_ip', 'signed_user_agent', 'recorded_by_user_id',
        'authority_letter_document_id', 'collector_id_photo_document_id', 'override_user_id', 'override_reason',
    ];

    protected $casts = ['signed_at' => 'datetime'];

    public function deed(): BelongsTo
    {
        return $this->belongsTo(TitleDeed::class, 'title_deed_id')->withTrashed();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function methodLabel(): string
    {
        return TitleDeed::METHOD_LABELS[$this->method] ?? ucfirst(str_replace('_', ' ', (string) $this->method));
    }
}
