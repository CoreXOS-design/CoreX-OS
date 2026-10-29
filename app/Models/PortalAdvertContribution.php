<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * INTERNAL record of which agency contributed which public advert fields to the shared pool
 * (.ai/specs/property-identity.md §14). Agency-scoped at the query layer: an agency can only ever read its OWN
 * contributions — never another agency's. SharedPortalPool writes it; nothing returns it with a pool read.
 */
final class PortalAdvertContribution extends Model
{
    use BelongsToAgency;

    protected $table = 'portal_advert_contributions';

    protected $fillable = ['portal_advert_fact_id', 'agency_id', 'first_seen_at', 'last_seen_at', 'seen_count', 'fields_contributed'];

    protected $casts = [
        'first_seen_at'      => 'datetime',
        'last_seen_at'       => 'datetime',
        'fields_contributed' => 'array',
    ];
}
