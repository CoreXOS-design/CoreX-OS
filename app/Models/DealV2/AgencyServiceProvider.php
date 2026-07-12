<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WS2 (AT-158 / DR2, D2) — a reusable agency service provider (electrician,
 * entomologist, transfer/bond attorney, bond originator, …). Agency-scoped,
 * soft-deleted (deactivate preserves historic deal references). Optionally
 * points at a CoreX contact but is not itself a contact type.
 */
class AgencyServiceProvider extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'contact_id',
        'name',
        'specialty',
        'company',
        'email',
        'phone',
        'address',
        'notes',
        'is_preferred',
        'is_active',
        'created_by_id',
    ];

    /**
     * AT-217 (DR2 respec) — a firm has 1..n working contacts (attorney + contact
     * person). Active, non-deleted only for pickers; the pivot deal keeps its own FKs.
     */
    public function serviceContacts()
    {
        return $this->hasMany(AgencyServiceProviderContact::class, 'service_provider_id')->orderBy('attorney_name');
    }

    protected $casts = [
        'is_preferred' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForSpecialty(Builder $q, string $specialty): Builder
    {
        return $q->where('specialty', $specialty);
    }

    /** Preferred first, then alphabetical — the picker order. */
    public function scopePickerOrder(Builder $q): Builder
    {
        return $q->orderByDesc('is_preferred')->orderBy('name');
    }
}
