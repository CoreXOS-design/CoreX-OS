<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalLead extends Model
{
    use HasFactory, SoftDeletes, BelongsToAgency;

    public const PORTAL_P24     = 'p24';
    public const PORTAL_PP      = 'pp';
    public const PORTAL_WEBSITE = 'website';

    protected $fillable = [
        'agency_id',
        'portal',
        'lead_type',
        'listing_id',
        'listing_portal_ref',
        'contact_id',
        'contact_exists',
        'existing_contact_agent_id',
        'name',
        'email',
        'phone',
        'message',
        'is_whatsapp',
        'lead_source_raw',
        'received_at',
        'notified_at',
    ];

    protected $casts = [
        'contact_exists'  => 'boolean',
        'is_whatsapp'     => 'boolean',
        'lead_source_raw' => 'array',
        'received_at'     => 'datetime',
        'notified_at'     => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'listing_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function existingContactAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'existing_contact_agent_id');
    }

    public function portalLabel(): string
    {
        return match ($this->portal) {
            self::PORTAL_P24     => 'Property24',
            self::PORTAL_WEBSITE => 'Website',
            default              => 'Private Property',
        };
    }

    /**
     * The agents who "own" this lead — the canonical recipient set for lead
     * notifications (mobile push + email).
     *
     * For P24 / Private Property this is the listing's primary + second agent
     * PLUS, when the enquirer matched an existing contact, that contact's agent
     * (the buyer already has a relationship with that agent, so they're told the
     * buyer enquired again).
     *
     * For WEBSITE leads the enquiry is explicitly about one listing, so it routes
     * to the LISTING agent(s) ONLY. The matched-contact's owner is deliberately
     * NOT buzzed — otherwise a website enquiry on agent A's listing pings agent B
     * merely because B once captured that buyer (the reported cross-agent noise).
     * The contact's owner is still recorded on the lead and can still SEE it via
     * the visibility scope; they're just not notified.
     *
     * @return int[]
     */
    public function agentIds(): array
    {
        $this->loadMissing('listing:id,agent_id,pp_second_agent_id');

        $ids = [
            $this->listing?->agent_id,
            $this->listing?->pp_second_agent_id,
        ];

        if ($this->portal !== self::PORTAL_WEBSITE) {
            $ids[] = $this->existing_contact_agent_id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Constrain the query to the leads a user may see, honouring the Portal
     * Leads "Data Scope" set in Role Manager (portal_leads.view: own|branch|all).
     * Owners always resolve to 'all'. A role that holds the permission but has
     * no explicit scope defaults to 'own' (most restrictive) — set the scope to
     * "All" in Role Manager to give a role agency-wide lead visibility.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = PermissionService::getDataScope($user, 'portal_leads') ?? 'own';
        if ($scope === 'all') {
            return $query;
        }

        if ($scope === 'branch' && ($branchId = $user->effectiveBranchId())) {
            $agentIds = User::where('branch_id', $branchId)->pluck('id')->all();
        } else { // 'own' (and the null default)
            $agentIds = [$user->id];
        }

        return $query->where(function (Builder $q) use ($agentIds) {
            $q->whereHas('listing', fn ($lq) => $lq
                    ->whereIn('agent_id', $agentIds)
                    ->orWhereIn('pp_second_agent_id', $agentIds))
              ->orWhereIn('existing_contact_agent_id', $agentIds);
        });
    }
}
