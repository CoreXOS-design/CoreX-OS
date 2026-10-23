<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A signer's acceptance (or rejection) of one section of a section-by-section document.
 *
 * Johan's signature-evidence rule (9 Sep 2026): a repeat acceptance NEVER overwrites the earlier one. Every
 * acceptance / rejection is its own row (recordGeneration): `generation` counts them per (request, section), the
 * superseded row is stamped `superseded_at` and kept with its initial_image / initialled_at, and ip / user agent
 * are recorded per row. Readers use the signature request's `sectionAcceptances` relation (current rows only) —
 * `sectionAcceptanceHistory` has every generation. Rows are never edited or deleted.
 */
class SectionAcceptance extends Model
{
    protected $table = 'section_acceptances';

    protected $fillable = [
        'signature_request_id',
        'section_index',
        'generation',
        'section_label',
        'accepted',
        'rejected',
        'rejection_reason',
        'initialled_at',
        'initial_image',
        'superseded_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'rejected' => 'boolean',
        'generation' => 'integer',
        'initialled_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if (array_diff(array_keys($row->getDirty()), ['superseded_at', 'updated_at']) !== []) {
                throw new LogicException('A section acceptance is evidence and cannot be edited — record a new generation.');
            }
        });

        static::deleting(function () {
            throw new LogicException('A section acceptance is evidence and cannot be deleted.');
        });
    }

    public function signingRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    /** The live row for each (request, section): the one that has not been superseded. */
    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * Write this acceptance / rejection as the next generation for the (request, section), superseding — never
     * replacing — the one before. Safe against a double submit: the previous row is locked while the next is written.
     *
     * @param array<string,mixed> $attributes section_label, accepted, rejected, rejection_reason, initialled_at, initial_image
     */
    public static function recordGeneration(int $signatureRequestId, int $sectionIndex, array $attributes, ?Request $http = null): self
    {
        return DB::transaction(function () use ($signatureRequestId, $sectionIndex, $attributes, $http) {
            $previous = static::query()
                ->where('signature_request_id', $signatureRequestId)
                ->where('section_index', $sectionIndex)
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->first();

            $row = static::create($attributes + [
                'signature_request_id' => $signatureRequestId,
                'section_index' => $sectionIndex,
                'generation' => $previous ? $previous->generation + 1 : 1,
                'ip_address' => $http?->ip(),
                'user_agent' => $http?->userAgent(),
            ]);

            if ($previous) {
                $previous->update(['superseded_at' => now()]);
            }

            return $row;
        });
    }
}
