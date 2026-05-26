<?php

namespace App\Models\Rental;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentalReminderSetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'enabled',
        'mode',
        'gentle_after_days',
        'firm_after_days',
        'team_alert_after_days',
        'final_after_days',
        'max_escalating_reminders',
        'interval_days',
        'max_simple_reminders',
        'email_subject',
        'email_body',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'gentle_after_days' => 'integer',
        'firm_after_days' => 'integer',
        'team_alert_after_days' => 'integer',
        'final_after_days' => 'integer',
        'max_escalating_reminders' => 'integer',
        'interval_days' => 'integer',
        'max_simple_reminders' => 'integer',
    ];

    /**
     * Get the current settings row (creates default if none exists).
     */
    public static function current(): static
    {
        return static::first() ?? static::create([
            'enabled' => true,
            'mode' => 'escalating',
            'gentle_after_days' => config('signatures.reminders.gentle_after_days', 2),
            'firm_after_days' => config('signatures.reminders.firm_after_days', 5),
            'team_alert_after_days' => config('signatures.reminders.team_alert_after_days', 7),
            'final_after_days' => config('signatures.reminders.final_after_days', 10),
            'max_escalating_reminders' => config('signatures.reminders.max_email_reminders', 3),
            'interval_days' => 3,
            'max_simple_reminders' => 5,
        ]);
    }

    public function updatedByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }
}
