<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_templates';

    protected $fillable = [
        'name',
        'template_type',
        'document_type_id',
        'category',
        'page_count',
        'fields_json',
        'is_global',
        'is_esign',
        'party_mode',
        'wizard_config',
        'sections',
        'render_type',
        'blade_view',
        'signing_parties',
        'header_display',
        'editor_state',
        'cds_json',
        'field_mappings',
        'allowed_delivery_modes',
        'security_tier',
        'owner_id',
        'archived_at',
    ];

    protected $casts = [
        'fields_json' => 'array',
        'wizard_config' => 'array',
        'sections' => 'array',
        'signing_parties' => 'array',
        'editor_state' => 'array',
        'cds_json' => 'array',
        'field_mappings' => 'array',
        'is_global' => 'boolean',
        'is_esign' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'docuperfect_template_branches', 'template_id', 'branch_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'template_id');
    }

    public function flows()
    {
        return $this->hasMany(Flow::class, 'template_id');
    }

    public function signatureZones()
    {
        return $this->hasMany(TemplateSignatureZone::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'templates');

        if ($scope === 'all') return $query;

        $branchId = $user->effectiveBranchId();

        return $query->where(function ($q) use ($branchId) {
            $q->where('is_global', true);
            if ($branchId) {
                $q->orWhereHas('branches', function ($bq) use ($branchId) {
                    $bq->where('branches.id', $branchId);
                });
            }
        });
    }

    public function isPerParty(): bool
    {
        return $this->party_mode === 'per_party';
    }

    /**
     * Detect if this is a sales-context document.
     * Layered: explicit signing_parties roles > name pattern matching.
     * Accepts optional $propertySource ('properties' or 'rental_properties') for step-data context.
     */
    public function isSalesDocument(?string $propertySource = null): bool
    {
        // Layer 1: check signing_parties for explicit sales/rental roles
        $parties = $this->signing_parties ?? [];
        if (is_array($parties) && !empty($parties)) {
            $roles = array_map('strtolower', $parties);
            $hasSales = !empty(array_intersect($roles, ['seller', 'buyer']));
            $hasRental = !empty(array_intersect($roles, ['landlord', 'tenant', 'lessor', 'lessee']));
            if ($hasSales && !$hasRental) return true;
            if ($hasRental && !$hasSales) return false;
        }

        // Layer 2: explicit category / template_type set by the builder.
        // Authoritative — covers CDS templates whose signing_parties use the
        // generic owner_party/acquiring_party tokens (so Layer 1 can't tell)
        // and whose name carries no sales keyword. Without this a sales CDS
        // template falls through to the name heuristic and wrongly renders
        // Lessor. template_type 'cds' is neutral and skipped (no sale/rent
        // substring), so category decides.
        foreach ([strtolower((string) ($this->category ?? '')), strtolower((string) ($this->template_type ?? ''))] as $sig) {
            if ($sig === '') continue;
            if (str_contains($sig, 'sale') || $sig === 'otp') return true;
            if (str_contains($sig, 'rent') || str_contains($sig, 'lett') || str_contains($sig, 'lease')) return false;
        }

        // Layer 3: property source table
        if ($propertySource === 'properties') return true;
        if ($propertySource === 'rental_properties') return false;

        // Layer 4: template name pattern matching (last-resort fallback)
        $name = strtolower($this->name ?? '');
        return str_contains($name, 'sell') || str_contains($name, 'sale')
            || str_contains($name, 'authority') || str_contains($name, 'otp')
            || str_contains($name, 'purchase');
    }

    /**
     * Check if this template type is legally blocked from e-signing.
     * Sale agreements and OTPs must be signed with wet ink per Alienation of
     * Land Act §2(1) + ECTA §13(1).
     *
     * Spec: .ai/specs/esign-v3-complete-spec.md §5
     *
     * Four-layer defence:
     *   Layer 1 — document_type_id slug match (the canonical classification)
     *   Layer 2 — template_type string fallback (for templates without a slug)
     *   Layer 3 — name regex with word boundaries (catches unclassified inputs)
     *   Layer 5 — every trigger writes to legal_block_audit_log (insert-only)
     */
    public function isEsignBlocked(): bool
    {
        $blockedSlugs = [
            'otp',
            'sale_agreement',
            'deed_of_sale',
            'deed_of_alienation',
            // offer_to_purchase is the pre-ES-1 slug that 6 templates already
            // carry — keep it blocked so existing classifications stay safe.
            'offer_to_purchase',
        ];

        // Layer 1 + 2 — slug or template_type string match
        $slug = $this->documentType?->slug ?? $this->template_type ?? '';
        if (in_array($slug, $blockedSlugs, true) && $slug !== '') {
            $this->logBlockTrigger('document_type_match', $slug);
            return true;
        }

        // Layer 3 — name regex with word boundaries.
        // "Photoshop" / "Photoshop Workflow" must NOT match; "SB 2026 OTP" must.
        $pattern = '/\b(otp|deed of alienation|agreement for sale|sale agreement|agreement of sale|deed of sale|offer to purchase)\b/i';
        if (preg_match($pattern, $this->name ?? '', $matches)) {
            $this->logBlockTrigger('name_pattern_match', $matches[0]);
            return true;
        }

        return false;
    }

    /**
     * ES-1 — write an insert-only audit row for every legal-block trigger.
     * Failure to write the log MUST NOT break the block — the block always
     * stands regardless of audit-log persistence.
     */
    private function logBlockTrigger(string $reason, ?string $matchedPattern): void
    {
        try {
            \App\Models\LegalBlockAuditLog::create([
                'agency_id'          => auth()->user()?->effectiveAgencyId(),
                'template_id'        => $this->id,
                'template_name'      => $this->name,
                'document_type_slug' => $this->documentType?->slug,
                'user_id'            => auth()->id(),
                'block_reason'       => $reason,
                'matched_pattern'    => $matchedPattern,
                'request_context'    => [
                    'route'      => request()->route()?->getName(),
                    'ip'         => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 500),
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Legal block audit log write failed: ' . $e->getMessage(), [
                'template_id' => $this->id,
                'reason'      => $reason,
            ]);
        }
    }

    /**
     * Get allowed delivery modes as an array.
     */
    public function getAllowedDeliveryModesArray(): array
    {
        $modes = $this->allowed_delivery_modes ?? 'esign,wet_ink,download';
        return array_filter(array_map('trim', explode(',', $modes)));
    }

    /**
     * Check if a specific delivery mode is allowed.
     */
    public function allowsDeliveryMode(string $mode): bool
    {
        // Sale agreements can NEVER use e-sign
        if ($mode === 'esign' && $this->isEsignBlocked()) {
            return false;
        }
        return in_array($mode, $this->getAllowedDeliveryModesArray());
    }

    /**
     * Get effective delivery modes (enforcing legal restrictions).
     */
    public function getEffectiveDeliveryModes(): array
    {
        $modes = $this->getAllowedDeliveryModesArray();
        if ($this->isEsignBlocked()) {
            $modes = array_values(array_diff($modes, ['esign']));
            if (empty($modes)) {
                $modes = ['wet_ink', 'download'];
            }
        }
        return $modes;
    }

    /**
     * Map generic signing party keys to display names based on document context.
     */
    public static function mapSigningPartyKeys(array $keys, bool $isSales): array
    {
        $map = $isSales
            ? ['owner_party' => 'Seller', 'acquiring_party' => 'Buyer', 'agent' => 'Agent']
            : ['owner_party' => 'Lessor', 'acquiring_party' => 'Lessee', 'agent' => 'Agent'];

        return array_values(array_map(
            fn($k) => $map[$k] ?? ucfirst(str_replace('_', ' ', $k)),
            $keys
        ));
    }

    public function getPageImagesAttribute(): array
    {
        $urls = [];
        for ($n = 0; $n < $this->page_count; $n++) {
            $urls[] = route('docuperfect.page.image', ['id' => $this->id, 'page' => $n]);
        }
        return $urls;
    }
}
