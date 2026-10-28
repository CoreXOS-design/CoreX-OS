<?php

namespace App\Models\Docuperfect;

use App\Models\Concerns\StampsOnBehalfOf;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/esign-template-transfer.md §13.2 — the release log (R3). Insert-only: a row is never updated and never deleted.
 * Platform-level history (it spans agencies; reads go through the Releases screen for the owner or through a release the viewer's agency owns).
 */
class TemplateReleaseEvent extends Model
{
    use StampsOnBehalfOf;

    public const EVENTS = [
        'verified', 'verification_cleared', 'released', 'approved', 'declined', 'withdrawn', 'superseded', 'lease_linked', 'authorisers_changed',
    ];

    protected $table = 'docuperfect_template_release_events';

    public $timestamps = false;

    protected $fillable = ['release_id', 'template_id', 'agency_id', 'event', 'actor_user_id', 'actor_name', 'note', 'detail', 'created_at'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    public static function eventLabel(string $event): string
    {
        return [
            'verified' => 'Marked as verified',
            'verification_cleared' => 'Verification cleared (the document changed)',
            'released' => 'Released to the agency',
            'approved' => 'Approved — now live',
            'declined' => 'Declined',
            'withdrawn' => 'Withdrawn',
            'superseded' => 'Replaced by a newer version',
            'lease_linked' => 'Lease agreement linked',
            'authorisers_changed' => 'Who may authorise documents changed',
        ][$event] ?? ucfirst(str_replace('_', ' ', $event));
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new DomainException('docuperfect_template_release_events rows are insert-only.');
        }
        if ($this->created_at === null) {
            $this->created_at = now();
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new DomainException('docuperfect_template_release_events rows are insert-only.');
    }
}
