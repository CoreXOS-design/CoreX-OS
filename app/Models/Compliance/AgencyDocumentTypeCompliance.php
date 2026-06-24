<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-agency override marking a (global) document type as required for a
 * property to be marketing-compliant. The `document_types` catalogue is
 * GLOBAL (shared across agencies); this pivot lets each agency set its OWN
 * required list without HFC's choices dictating another agency's.
 *
 * Read by App\Services\Compliance\AgencyComplianceDocTypeService and, through
 * it, the marketing-readiness gate.
 */
class AgencyDocumentTypeCompliance extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'agency_document_type_compliance';

    protected $fillable = [
        'agency_id',
        'document_type_id',
        'is_compliance_required',
    ];

    protected $casts = [
        'is_compliance_required' => 'boolean',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
