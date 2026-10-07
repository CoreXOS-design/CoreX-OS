<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\Concerns\BelongsToAgency;
class ContactSource extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id','name', 'color', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * This agency's own contact-source row by name, or null if the agency has none.
     *
     * For NON-AUTHENTICATED ingress (portal pulls, webhooks, queue jobs): with no
     * logged-in user BelongsToAgency's AgencyScope does not filter, so a plain
     * `ContactSource::where('name', 'Private Property')->value('id')` returns
     * whichever agency's row sorts first — another agency's source id would be
     * stamped on this agency's contact (audit 2026-10-07, defect 3). Always
     * pass the agency of the record being created.
     */
    public static function idForAgencyByName(int $agencyId, string $name): ?int
    {
        $id = static::query()
            ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('name', $name)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'contact_source_id');
    }
}
