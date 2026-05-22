<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 5 — captured lead from a teaser /p/{token} form submission.
 *
 * After capture the row may or may not be linked to a Contact:
 *   - matched existing → contact_id populated, no new Contact created
 *   - new contact     → Contact created in the agency, contact_id populated,
 *                       converted_to_contact_at set
 */
final class PresentationTeaserLead extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'snapshot_link_id',
        'agency_id',
        'presentation_id',
        'contact_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'relationship',
        'intent',
        'consent_marketing',
        'consent_contact',
        'notes',
        'captured_at',
        'converted_to_contact_at',
        'assigned_agent_id',
    ];

    protected $casts = [
        'captured_at'             => 'datetime',
        'converted_to_contact_at' => 'datetime',
        'consent_marketing'       => 'boolean',
        'consent_contact'         => 'boolean',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(PresentationSnapshotLink::class, 'snapshot_link_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
