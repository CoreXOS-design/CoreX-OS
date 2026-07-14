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
