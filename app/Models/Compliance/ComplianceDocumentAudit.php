<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/** Append-only trail for an RMCP / policy version: signed copy uploaded or replaced, staff training sent. */
class ComplianceDocumentAudit extends Model
{
    use BelongsToAgency;

    public const KIND_RMCP   = 'rmcp';
    public const KIND_POLICY = 'policy';

    public $timestamps = false;

    protected $table = 'compliance_document_audit_log';

    protected $fillable = [
        'agency_id', 'document_kind', 'version_id', 'event_type',
        'actor_user_id', 'actor_label', 'ip_address', 'meta',
    ];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public static function record(string $kind, int $agencyId, int $versionId, string $event, ?User $actor, array $meta = []): self
    {
        return static::withoutGlobalScopes()->create([
            'agency_id'     => $agencyId,
            'document_kind' => $kind,
            'version_id'    => $versionId,
            'event_type'    => $event,
            'actor_user_id' => $actor?->id,
            'actor_label'   => $actor?->name,
            'ip_address'    => Request::ip(),
            'meta'          => $meta ?: null,
        ]);
    }
}
