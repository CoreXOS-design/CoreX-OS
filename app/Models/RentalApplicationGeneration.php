<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reopen/resubmit, 2026-09-08 — one immutable, hash-chained snapshot of an
 * applicant's answers exactly as they stood at a submission. Deliberately
 * copies App\Models\Docuperfect\DocumentSealedVersion's shape (append-only,
 * no updated_at, refuses any update, content_hash = sha256(prev_hash .
 * snapshot_json) chained to the previous generation) — the reopen-flow
 * investigation identified that model as the existing, proven "what was
 * signed at this point" pattern in this codebase, so this reuses it rather
 * than inventing a second one.
 *
 * One row is written per submit() — the very first submission AND every
 * resubmit after a reopen — sealing the content that submission just
 * signed. RentalApplication::signatures() for that SAME generation are the
 * signatures that belong to this snapshot.
 */
class RentalApplicationGeneration extends Model
{
    protected $table = 'rental_application_generations';

    // Write-once: created_at only, no updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'rental_application_id',
        'generation',
        'agency_id',
        'snapshot_json',
        'submitted_at',
        'ip_address',
        'user_agent',
        'content_hash',
        'prev_hash',
    ];

    protected $casts = [
        'generation' => 'integer',
        'snapshot_json' => 'array',
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Immutability guard — a sealed generation is a legal record and may
     * never change. Mirrors DocumentSealedVersion::save() exactly. Inserts
     * pass; any update throws.
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new \DomainException('RentalApplicationGeneration is append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }

    /** The most recently sealed generation for an application (the chain head). */
    public static function latestFor(int $rentalApplicationId): ?self
    {
        return static::where('rental_application_id', $rentalApplicationId)->orderByDesc('generation')->first();
    }

    /**
     * Compute the chained content hash for a given prior hash + snapshot.
     *
     * 2026-09-08 — caught by RentalApplicationReopenTest: MySQL's native
     * JSON column type normalizes key order on storage, so re-encoding a
     * value read back from `snapshot_json` (via Eloquent's array cast)
     * produced a DIFFERENT byte sequence than the encoding used when the
     * hash was first computed at seal() time — even though the underlying
     * data was identical, making verifyChain() report tampering on every
     * genuine row. ksort() here, on both the seal-time and verify-time
     * call, makes the encoding canonical regardless of MySQL's own storage
     * order or PHP's array insertion order. Flat, one level — snapshot_json
     * is a flat field=>scalar map (see seal()), never nested, so a single
     * top-level ksort is sufficient.
     */
    public static function computeHash(?string $prevHash, array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', ($prevHash ?? '') . json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /**
     * Seal the application's CURRENT live answer fields as the next
     * generation. Called from inside submit()'s own DB transaction, after
     * the new answers have already been written onto the live row and the
     * new signatures captured — this snapshot is "what was just submitted
     * and signed," chained to whatever generation preceded it.
     */
    public static function seal(RentalApplication $application, \Illuminate\Http\Request $request): self
    {
        $snapshot = collect(RentalApplication::fieldValidationRules())
            ->keys()
            ->mapWithKeys(fn ($field) => [$field => $application->getAttribute($field)])
            ->all();

        $prev = self::latestFor($application->id);
        $prevHash = $prev?->content_hash;

        return self::create([
            'rental_application_id' => $application->id,
            'generation' => $application->current_generation,
            'agency_id' => $application->agency_id,
            'snapshot_json' => $snapshot,
            'submitted_at' => $application->submitted_at ?? now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_hash' => self::computeHash($prevHash, $snapshot),
            'prev_hash' => $prevHash,
        ]);
    }

    /**
     * Walk an application's sealed chain in order and verify every link.
     * Returns ['ok' => bool, 'count' => int, 'breaks' => [generation, ...]].
     * A break means tampering — a stored content_hash that doesn't match
     * sha256(prev_hash . snapshot_json), or a prev_hash that doesn't equal
     * the prior row's content_hash.
     */
    public static function verifyChain(int $rentalApplicationId): array
    {
        $rows = static::where('rental_application_id', $rentalApplicationId)->orderBy('generation')->get();
        $breaks = [];
        $expectedPrev = null;
        foreach ($rows as $row) {
            $recomputed = self::computeHash($row->prev_hash, $row->snapshot_json);
            $linkOk = ($row->content_hash === $recomputed) && ($row->prev_hash === $expectedPrev);
            if (! $linkOk) {
                $breaks[] = (int) $row->generation;
            }
            $expectedPrev = $row->content_hash;
        }

        return ['ok' => empty($breaks), 'count' => $rows->count(), 'breaks' => $breaks];
    }
}
