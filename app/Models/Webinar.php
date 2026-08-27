<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A webinar, and the public registration link that feeds it.
 *
 * Spec: .ai/specs/webinar-registration.md §3.1
 *
 * System-owner sales tooling. Not tenant-scoped — a webinar belongs to RR
 * Technologies' sales process, exactly like the demo grants it issues. Lives on
 * PRIMARY.
 *
 * The registration PAGE is not in this codebase: it lives on the CoreX marketing
 * website, which posts to /api/v1/webinars/{slug}/register. This model is the record
 * that page registers against, and the policy it registers under.
 */
class Webinar extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'description',
        'starts_at',
        'registration_closes_at',
        'duration_minutes',
        'join_url',
        'access_ends_days_after',
        'reminder_hours_before',
        'created_by_user_id',
        'archived_at',
    ];

    protected $casts = [
        'starts_at'              => 'datetime',
        // Cast, or isOpenForRegistration() compares a Carbon against a raw string
        // and the cut-off silently never fires.
        'registration_closes_at' => 'datetime',
        'duration_minutes'       => 'integer',
        'access_ends_days_after' => 'integer',
        'reminder_hours_before'  => 'integer',
        'archived_at'            => 'datetime',
    ];

    // ---- The access policy -------------------------------------------------

    /**
     * When demo access for this webinar's whole cohort dies.
     *
     * Johan's rule: the demo "runs until that date or even like 3 days after, and
     * anyone that doesn't use the login just loses access". So this is an ABSOLUTE
     * deadline shared by everyone who registered through this link — not a per-person
     * trial that starts when each of them first logs in.
     *
     * END OF DAY, not the same clock time as the webinar. "Access runs until three
     * days after" means the end of that third day to a human; expiring someone at
     * 14:00 because that is when the webinar happened to start would be technically
     * consistent and practically wrong.
     *
     * Copied onto each grant at issue (DemoAccessService::issue), never referenced
     * from there afterwards — so editing this later cannot retroactively shorten
     * access already promised to someone who has registered.
     */
    public function demoAccessEndsAt(): Carbon
    {
        return $this->starts_at->copy()
            ->addDays($this->access_ends_days_after)
            ->endOfDay();
    }

    /** When the single reminder email becomes due. */
    public function reminderDueAt(): Carbon
    {
        return $this->starts_at->copy()->subHours($this->reminder_hours_before);
    }

    /**
     * Can someone still register?
     *
     * DERIVED, never stored. There is no "registration open" switch to forget to
     * flip: a webinar that has started stops accepting registrations by arithmetic.
     * Without that, a finished webinar's link would keep minting free demo logins
     * indefinitely — a public form that hands out working credentials is only ever as
     * safe as the thing that closes it.
     *
     * `registration_closes_at` is the OPTIONAL earlier cut-off (§3.1a) — "register by
     * Friday 17:00", so the attendee list is final before the day. NULL means no
     * cut-off and the original three-clause behaviour, which is why adding the column
     * changed nothing for any webinar that already existed.
     *
     * It is derived here rather than stored as a flag for the same reason as the rest:
     * a flag needs something to flip it, and until that something ran the API would
     * report a webinar as open past its own cut-off.
     */
    public function isOpenForRegistration(?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();

        return $this->archived_at === null
            && $this->starts_at->greaterThan($now)
            && $this->demoAccessEndsAt()->greaterThan($now)
            && ($this->registration_closes_at === null
                || $this->registration_closes_at->greaterThan($now));
    }

    /** Has an earlier cut-off been set, and has it passed? */
    public function registrationCutOffHasPassed(?Carbon $now = null): bool
    {
        return $this->registration_closes_at !== null
            && $this->registration_closes_at->lessThanOrEqualTo($now ?: Carbon::now());
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Plain-English state for the admin list — never a raw flag on screen.
     *
     * The cut-off branch (§3.1a) comes BEFORE the starts_at check on purpose. Without
     * it, a webinar whose cut-off has passed but whose start is still in the future
     * would read "Open for registration" on the admin list while the API correctly
     * refuses every sign-up — the label and the behaviour disagreeing on screen, which
     * is worse than either being wrong on its own.
     */
    public function statusLabel(): string
    {
        if ($this->archived_at !== null) {
            return 'Archived';
        }

        if ($this->registrationCutOffHasPassed()) {
            return 'Registration closed';
        }

        if ($this->starts_at->isFuture()) {
            return 'Open for registration';
        }

        return 'Closed';
    }

    /** The public URL the marketing website registers against. */
    public function registrationEndpoint(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1/webinars/' . $this->slug . '/register';
    }

    // ---- Slugs -------------------------------------------------------------

    /**
     * A URL-safe slug that is not already taken.
     *
     * The slug is in a public URL the website hard-codes into a page, so a collision
     * would silently point one webinar's registration form at another webinar's
     * cohort — and therefore at the wrong access deadline. Suffixing is cheaper than
     * explaining that.
     */
    public static function uniqueSlug(string $candidate, ?int $ignoreId = null): string
    {
        $base = Str::slug($candidate) ?: 'webinar';
        $slug = $base;
        $n    = 2;

        while (static::where('slug', $slug)
                     ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
                     ->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    // ---- Scopes ------------------------------------------------------------

    public function scopeNotArchived(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('archived_at')->where('starts_at', '>', Carbon::now());
    }

    // ---- Relationships -----------------------------------------------------

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
