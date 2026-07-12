<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An immutable published version of the demo Terms & Conditions.
 *
 * Spec: .ai/specs/demo-access-control.md §4.1
 *
 * IMMUTABLE. There is no update path and there must never be one. "Edit" in the
 * admin UI calls publish() and mints version N+1.
 *
 * WHY: a DemoTncAcceptance is evidence that a named human at a named company
 * agreed to a specific body of text. If that text can be edited afterwards, the
 * acceptance proves nothing — it points at whatever the text says today. That is
 * the entire point of clickwrap, and a mutable row silently destroys it.
 */
class DemoTncVersion extends Model
{
    protected $fillable = [
        'version',
        'body',
        'published_at',
        'published_by_user_id',
    ];

    protected $casts = [
        'version'      => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * Publish a new version. The ONLY way text enters this table.
     *
     * Never updates an existing row — takes max(version) + 1. Concurrent
     * publishes would collide on the `version` unique index rather than
     * silently overwriting each other, which is the correct failure.
     */
    public static function publish(string $body, ?int $userId = null): self
    {
        $next = (int) (static::max('version') ?? 0) + 1;

        return static::create([
            'version'              => $next,
            'body'                 => $body,
            'published_at'         => Carbon::now(),
            'published_by_user_id' => $userId,
        ]);
    }

    /** The version every prospect must currently accept. */
    public static function current(): ?self
    {
        return static::orderByDesc('version')->first();
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(DemoTncAcceptance::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function isCurrent(): bool
    {
        $current = static::current();

        return $current !== null && $current->getKey() === $this->getKey();
    }
}
