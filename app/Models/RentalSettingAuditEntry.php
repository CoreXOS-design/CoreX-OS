<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who changed which rentals setting, from what to what, and from where (the Settings page or the Setup Wizard).
 * Written by the savers of the rentals front-half settings (8 Oct 2026); append-only, never edited or deleted.
 * Read per agency on the setting's own card ("Recent changes").
 */
class RentalSettingAuditEntry extends Model
{
    protected $table = 'rental_setting_audit';

    public $timestamps = false;

    protected $fillable = ['agency_id', 'user_id', 'setting_key', 'old_value', 'new_value', 'source', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }

    /** Record one change. A no-op write (same value) leaves no row. Values are stored as readable text. */
    public static function record(int $agencyId, ?User $by, string $key, mixed $old, mixed $new, string $source = 'settings'): void
    {
        $o = self::text($old);
        $n = self::text($new);
        if ($o === $n) {
            return;
        }

        self::create([
            'agency_id' => $agencyId, 'user_id' => $by?->id, 'setting_key' => $key, 'old_value' => $o, 'new_value' => $n,
            'source' => $source, 'created_at' => now(),
        ]);
    }

    private static function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'on' : 'off';
        }

        return (string) $v;
    }
}
