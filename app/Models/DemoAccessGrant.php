<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A time-boxed, company-attributed grant of access to the CoreX demo.
 *
 * Spec: .ai/specs/demo-access-control.md §4.2
 *
 * Lives on PRIMARY. Not tenant-scoped (no BelongsToAgency) — a grant belongs to
 * RR Technologies' sales process, not to an agency. It is reached only through the
 * owner_only admin surface (§8) and the universal DemoConnector API (§5.1).
 *
 * NOT SoftDeletes — see the migration docblock. "Delete" sets archived_at and
 * the row stays.
 */
class DemoAccessGrant extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_REVOKED  = 'revoked';
    public const STATUS_ARCHIVED = 'archived';

    /** Plain-English chips (STANDARDS F.8 — no enum values shown raw). */
    public const STATUS_LABELS = [
        self::STATUS_PENDING  => 'Not used yet',
        self::STATUS_ACTIVE   => 'Active',
        self::STATUS_EXPIRED  => 'Expired',
        self::STATUS_REVOKED  => 'Revoked',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    protected $fillable = [
        'company_name',
        'contact_email',
        'contact_name',
        'contact_id',
        'credential_hash',
        'expiry_hours',
        'first_login_at',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
        'archived_at',
        'issued_by_user_id',
        'notes',
    ];

    protected $casts = [
        'expiry_hours'   => 'integer',
        'first_login_at' => 'datetime',
        'expires_at'     => 'datetime',
        'revoked_at'     => 'datetime',
        'archived_at'    => 'datetime',
    ];

    /** The hash is a credential. It never leaves the server. */
    protected $hidden = ['credential_hash'];

    // ---- Credential --------------------------------------------------------

    /**
     * Mint a fresh access code. Returns the plaintext ONCE — it is emailed and
     * shown on screen, and then it is gone. Only bcrypt(code) is persisted.
     *
     * Crockford-style base32 alphabet: no I, L, O, U. A prospect reads this off
     * an email and types it, and 0/O and 1/I/L are how that goes wrong.
     */
    public static function mintCode(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $code     = '';

        for ($i = 0; $i < 16; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Grouped for legibility in the email: XXXX-XXXX-XXXX-XXXX
        return implode('-', str_split($code, 4));
    }

    /** Normalise for comparison — users paste with spaces, lowercase, no dashes. */
    public static function normaliseCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    public function verifyCode(string $code): bool
    {
        return Hash::check(self::normaliseCode($code), $this->credential_hash);
    }

    public static function hashCode(string $code): string
    {
        return Hash::make(self::normaliseCode($code));
    }

    // ---- Status (DERIVED, never stored) ------------------------------------

    /**
     * The single source of truth for "can this grant be used".
     *
     * ══ THE EXPIRY CHECK COMES FIRST, AND THE NULL GUARD IS WHAT MAKES THAT SAFE ══
     *
     * It used to come second, so that a never-used grant (expires_at NULL) could not
     * be caught by a naive expiry test — `null->isPast()` fatals, and `NULL > NOW()`
     * is falsy in SQL, either of which locks out every grant we just emailed
     * (demo-access-control.md §11 R4).
     *
     * Fixed-deadline grants (webinar cohorts — expiry_hours NULL, expires_at written
     * at issue) need the opposite: a grant nobody ever logged into MUST expire on the
     * date, or the cohort's access never ends. Johan's rule is "anyone that doesn't
     * use the login just loses access".
     *
     * Both hold at once because the guard is now the EXPLICIT `!== null` rather than
     * a lucky ordering. A rolling grant's expires_at is NULL for exactly as long as it
     * is pending, so the new first branch cannot fire for one — it falls through to
     * PENDING precisely as before. Behaviour for rolling grants is unchanged; that is
     * asserted directly by WebinarExpiryModelTest.
     *
     * Spec: .ai/specs/webinar-registration.md §5.2
     */
    public function status(): string
    {
        if ($this->archived_at !== null) {
            return self::STATUS_ARCHIVED;
        }

        if ($this->revoked_at !== null) {
            return self::STATUS_REVOKED;
        }

        // A deadline that has passed ends the grant whether or not it was ever used.
        // NULL is not a deadline — see the docblock.
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        // Issued but never used, and no deadline has bitten.
        if ($this->first_login_at === null) {
            return self::STATUS_PENDING;
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Does this grant run on an absolute deadline rather than a clock that starts
     * at first login?
     *
     * expiry_hours NULL is the mode flag — see the migration docblock for why the
     * mode is encoded in this column rather than a second one it could disagree with.
     */
    public function hasFixedDeadline(): bool
    {
        return $this->expiry_hours === null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status()] ?? $this->status();
    }

    /** Usable = the gate lets this grant in right now. */
    public function isUsable(): bool
    {
        return in_array($this->status(), [self::STATUS_PENDING, self::STATUS_ACTIVE], true);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    // ---- First-login stamping (RACE-SAFE) ----------------------------------

    /**
     * Start the clock. Returns true if THIS call won the race.
     *
     * Two tabs, one credential. A read-then-write —
     *
     *     if (! $grant->first_login_at) { $grant->update([...]); }
     *
     * — lets BOTH tabs pass the check, and the second write moves expires_at
     * forward, silently extending the trial. The conditional UPDATE is atomic in
     * MySQL: exactly one statement matches `WHERE first_login_at IS NULL`, so
     * exactly one writer wins and the loser is told so by the affected-row count.
     *
     * Spec §6.2 step 6 / §11 R5.
     *
     * ══ COALESCE PROTECTS A FIXED DEADLINE ══
     *
     * A webinar grant already carries its expires_at, written at issue (§5.2). This
     * method used to overwrite expires_at unconditionally, which would have handed
     * every webinar registrant a fresh window starting whenever they happened to log
     * in — silently converting an absolute cohort deadline back into the rolling
     * clock it was designed to replace, with nothing raised.
     *
     * COALESCE(expires_at, ?) writes the rolling value ONLY into a column that is
     * still NULL. For a fixed grant the binding is NULL as well, so the expression
     * reduces to expires_at and the deadline is left exactly as issued.
     *
     * Still ONE statement. The two-tab race guard (WHERE first_login_at IS NULL) is
     * untouched and still decides the winner by affected-row count.
     *
     * Spec: .ai/specs/webinar-registration.md §5.2
     */
    public function stampFirstLogin(?Carbon $now = null): bool
    {
        $now = $now ? $now->copy() : Carbon::now();

        // NULL for a fixed-deadline grant — there is no rolling value to fall back on,
        // and addHours(null) would quietly mean "expires now".
        $rollingExpiry = $this->expiry_hours !== null
            ? $now->copy()->addHours($this->expiry_hours)->toDateTimeString()
            : null;

        $won = DB::update(
            'UPDATE ' . $this->getTable() . '
                SET first_login_at = ?,
                    expires_at     = COALESCE(expires_at, ?),
                    updated_at     = ?
              WHERE id = ? AND first_login_at IS NULL',   // ← the guard IS the fix
            [$now->toDateTimeString(), $rollingExpiry, $now->toDateTimeString(), $this->getKey()]
        );

        // Either way, this instance must reflect what is actually in the DB —
        // the loser needs the WINNER's expires_at, not its own would-be value.
        $this->refresh();

        return $won === 1;
    }

    // ---- Query scopes ------------------------------------------------------

    /** Live grants — not archived. The default view of the world. */
    public function scopeNotArchived(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    /**
     * Grants the gate would currently admit.
     *
     * Note the NULL-safe expiry predicate: `expires_at > NOW()` alone evaluates
     * to NULL (falsy) for a never-used grant and would exclude every prospect we
     * just emailed.
     */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->whereNull('archived_at')
                 ->whereNull('revoked_at')
                 ->where(function (Builder $w) {
                     $w->whereNull('expires_at')
                       ->orWhere('expires_at', '>', Carbon::now());
                 });
    }

    // ---- Relationships -----------------------------------------------------

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(DemoTncAcceptance::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DemoSession::class);
    }

    /**
     * Has this grant accepted the CURRENT T&C version?
     *
     * Publishing v2 makes this false for everyone who only accepted v1 —
     * including users mid-session — which is exactly what re-prompts them.
     */
    public function hasAcceptedCurrentTnc(): bool
    {
        $current = DemoTncVersion::current();

        if (! $current) {
            // No T&C published at all. Fail CLOSED: the clickwrap is a legal
            // control, and "no text to show" is not a reason to skip it. The
            // seeder (registered in deploy:sync-reference-data) guarantees v1
            // exists on every install — if it doesn't, that is the bug to fix.
            return false;
        }

        return $this->acceptances()
                    ->where('demo_tnc_version_id', $current->id)
                    ->exists();
    }

    /** A short, non-identifying handle for logs. Never log the code. */
    public function logRef(): string
    {
        return 'grant#' . $this->getKey() . ' (' . Str::limit($this->company_name, 40) . ')';
    }
}
