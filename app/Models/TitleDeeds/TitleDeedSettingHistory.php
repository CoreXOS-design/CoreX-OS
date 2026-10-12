<?php

namespace App\Models\TitleDeeds;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per setting change: who, from what, to what, when (spec section 15.5). Append-only; shown at the bottom of the
 * settings screen. Not agency-scoped by a trait on purpose - every read names its agency.
 */
class TitleDeedSettingHistory extends Model
{
    public $timestamps = false;

    protected $table = 'title_deed_setting_history';

    protected $fillable = ['agency_id', 'setting_key', 'from_value', 'to_value', 'changed_by_user_id', 'source', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id')->withTrashed();
    }

    public function label(): string
    {
        return TitleDeedSetting::LABELS[$this->setting_key] ?? $this->setting_key;
    }

    public function fromText(): string
    {
        return TitleDeedSetting::displayValue($this->setting_key, $this->from_value);
    }

    public function toText(): string
    {
        return TitleDeedSetting::displayValue($this->setting_key, $this->to_value);
    }
}
