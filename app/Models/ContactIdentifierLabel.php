<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact-details Phase 2 — an agency-configurable label for a contact's
 * phone or email entries (e.g. Personal, Business, Contact). Same shape as
 * ContactSource (name/color/sort_order/is_active), admin-managed from
 * Settings -> Contacts, one shared list used by both contact_phones and
 * contact_emails.
 */
class ContactIdentifierLabel extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'name', 'color', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Seeded on every new agency (AgencyObserver::created()) and backfilled for existing ones. */
    private const DEFAULTS = ['Personal', 'Business', 'Contact'];

    public static function seedDefaultsFor(int $agencyId): void
    {
        foreach (self::DEFAULTS as $i => $name) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'name' => $name],
                ['sort_order' => $i, 'is_active' => true],
            );
        }
    }

    public function phones(): HasMany
    {
        return $this->hasMany(ContactPhone::class, 'contact_identifier_label_id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class, 'contact_identifier_label_id');
    }
}
