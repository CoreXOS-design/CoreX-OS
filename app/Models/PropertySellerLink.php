<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class PropertySellerLink extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'property_id', 'token', 'contact_id', 'generated_by_user_id',
        'generated_at', 'last_accessed_at', 'access_count',
        'revoked_at', 'revoked_by_user_id',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function generatedBy(): BelongsTo { return $this->belongsTo(User::class, 'generated_by_user_id'); }

    public function isActive(): bool { return $this->revoked_at === null; }

    /** The contact_property roles that make a contact a seller-side party of a property. */
    public const SELLER_SIDE_ROLES = ['owner', 'seller', 'landlord', 'lessor'];

    /**
     * Links that are still IN FORCE: not manually revoked, and whose seller has not been
     * taken off the property since (spec seller-live-link.md, "When the seller is removed").
     *
     * "Removed" is read from the contact_property row for the same (property, contact) pair —
     * the ONE row per pair — as evidence: it is archived, or its role is now something other
     * than a seller-side role. A pair with no contact_property row at all (links issued
     * before the pivot was reliably written) or a null role is NOT evidence of removal, so a
     * legacy link for a current seller is never switched off by this. Because it is derived
     * from the relationship on every read, it covers every removal path (and links orphaned
     * before this rule existed) with no data repair, and re-linking the seller switches the
     * very same link back on. A manual Revoke (revoked_at) is permanent and never undone here.
     */
    public function scopeStillHeld($query)
    {
        return $query->whereNull('property_seller_links.revoked_at')
            ->whereNotExists(function ($removed) {
                $removed->select(\DB::raw(1))
                    ->from('contact_property')
                    ->whereColumn('contact_property.property_id', 'property_seller_links.property_id')
                    ->whereColumn('contact_property.contact_id', 'property_seller_links.contact_id')
                    ->where(function ($w) {
                        $w->whereNotNull('contact_property.deleted_at')
                            ->orWhere(function ($r) {
                                $r->whereNotNull('contact_property.role')
                                    ->whereNotIn('contact_property.role', self::SELLER_SIDE_ROLES);
                            });
                    });
            });
    }

    /** True while this link is not revoked and its seller is not known to have been removed. */
    public function isHeld(): bool
    {
        return static::withoutGlobalScopes()->whereKey($this->getKey())->stillHeld()->exists();
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32)); // 64-char hex
    }

    /**
     * Ensure an active seller link exists for a (property, contact) pair.
     * Returns the existing active link or creates a new one. Idempotent.
     */
    public static function ensureExists(int $propertyId, int $contactId, ?int $generatedByUserId = null): self
    {
        // AT-260 / AT-253 (STANDARDS Rule 17) — DERIVE the agency from the PROPERTY.
        //
        // This method was unusable outside a web request. `agency_id` is NOT NULL and nothing
        // supplied it: BelongsToAgency fills it from the ACTING USER, and a console command, a
        // queued job or a webhook has no acting user — so MySQL rejected the insert with a 1364
        // and the whole job died. (Found the hard way: seeding qa1 walk data from the CLI.)
        //
        // The link belongs to the PROPERTY's tenant, not to whoever happens to be clicking, so
        // the property is the honest source. That also fixes the subtler bug: a web user acting
        // outside their own agency would previously have stamped the link with THEIR agency
        // rather than the property's.
        $property = Property::withoutGlobalScopes()->find($propertyId);
        $agencyId = (int) ($property?->agency_id ?? 0);

        if ($agencyId <= 0) {
            // No property, or a property with no tenant: there is nothing to derive from and
            // nothing honest to write. Refuse rather than invent one (Rule 17 — writes never
            // guess a tenant).
            throw new \App\Exceptions\MissingAgencyContextException('a seller link');
        }

        // Scope the lookup to the property's agency explicitly. The global scope resolves from
        // the acting user, which is absent in console — so it must not be what decides whether
        // an existing link is found.
        $existing = static::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('property_id', $propertyId)
            ->where('contact_id', $contactId)
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::create([
            'agency_id'   => $agencyId,
            'property_id' => $propertyId,
            'contact_id'  => $contactId,
            'token'       => static::generateToken(),
            // ...and never attribute the link to USER 1 just because nobody was logged in.
            // The column is nullable: an unattributed link is the truth in a console context,
            // and a false attribution to a real person is worse than none.
            'generated_by_user_id' => $generatedByUserId ?? auth()->id(),
            'generated_at' => now(),
        ]);
    }
}
