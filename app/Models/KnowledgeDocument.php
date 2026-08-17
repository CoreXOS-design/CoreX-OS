<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 2026-08-20 (HFC tenant-isolation, Wave 3) — added BelongsToAgency. Every
 * direct id lookup (toggleActive/toggleEllie/reprocess/destroy) and the
 * automatic global scope protect a document from being read OR managed by
 * another agency "for free" — no per-call-site change needed for those.
 *
 * 2026-08-21 (#9, selectable ownership) — added is_global. A document can
 * now be agency-private (default) or CoreX-global (visible to every
 * agency, but still only manageable by its owning agency or an unscoped
 * System Owner — BelongsToAgency's automatic scope is untouched on the
 * write/manage paths above). scopeVisibleTo() is the ONLY place that
 * widens visibility across agencies: it explicitly drops the automatic
 * scope for that one query and applies its own is_global-aware OR, the
 * same pattern docuperfect_templates already uses and for the same reason
 * (BelongsToAgency's blanket `agency_id = X` would otherwise AND away any
 * OR clause a caller adds, silently making is_global inert — see the
 * Wave 3 migration's docblock). Every READ surface that must see global
 * docs (index listing, category view, preview, Ellie's RAG search) calls
 * ->visibleTo($user) explicitly; every WRITE/manage surface deliberately
 * does not, so a global doc can be seen by any agency but only
 * edited/deleted/toggled by the agency that owns it.
 */
class KnowledgeDocument extends Model
{
    use BelongsToAgency, SoftDeletes;


    protected $table = 'knowledge_documents';

    protected $fillable = [
        'agency_id',
        'is_global',
        'category_id',
        'uploaded_by',
        'title',
        'description',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'chunk_count',
        'page_count',
        'status',
        'error_message',
        'is_active',
        'is_ellie_enabled',
        'version',
        'effective_date',
        'expiry_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_ellie_enabled' => 'boolean',
        'is_global' => 'boolean',
        'effective_date' => 'date',
        'expiry_date' => 'date',
    ];

    /**
     * Cross-agency visibility: this agency's own docs + anything flagged
     * is_global. See the class docblock for why this bypasses the
     * automatic BelongsToAgency scope for this one query only.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : $user->agency_id;

        return $query->withoutGlobalScope(AgencyScope::class)->where(function ($q) use ($agencyId) {
            $q->where('is_global', true);
            if ($agencyId) {
                $q->orWhere('agency_id', $agencyId);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEllieEnabled($query)
    {
        return $query->where('is_ellie_enabled', true);
    }

    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    public function getIsExpiredAttribute(): bool
    {
        if (!$this->expiry_date) {
            return false;
        }
        return $this->expiry_date->isPast();
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'ready' => '<span class="ds-badge ds-badge-success">Ready</span>',
            'processing' => '<span class="ds-badge ds-badge-warning">Processing</span>',
            'error' => '<span class="ds-badge ds-badge-danger">Error</span>',
            default => '<span class="ds-badge ds-badge-default">' . e(ucfirst($this->status)) . '</span>',
        };
    }
}
