<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\UserDashboardSetting;
use Illuminate\Http\Request;

class UserSettingsController extends Controller
{
    /**
     * Show user's personal dashboard settings page.
     */
    public function index(Request $request)
    {
        $user     = $request->user();
        $settings = UserDashboardSetting::getEffective($user);

        // Check if agency controls settings
        $isAgencyControlled = $settings->getAttribute('is_agency_controlled') ?? false;

        return view('command-center.user-settings', [
            'user'                => $user,
            'settings'            => $settings,
            'isAgencyControlled'  => $isAgencyControlled,
        ]);
    }

    /**
     * Save user's personal dashboard settings.
     */
    public function update(Request $request)
    {
        $user     = $request->user();
        $settings = UserDashboardSetting::getEffective($user);

        // Under agency lock, users may still toggle their own mobile push master.
        // Everything else is read-only.
        if ($settings->getAttribute('is_agency_controlled') ?? false) {
            \App\Models\CommandCenter\UserDashboardSetting::updateOrCreate(
                ['user_id' => $user->id],
                ['notify_push' => $request->boolean('notify_push')]
            );
            return back()->with('success', 'Push setting saved. Other notification settings are managed by your agency.');
        }

        $validated = $request->validate([
            'idle_alerts_enabled'        => 'nullable|boolean',
            'idle_threshold_days'        => 'required|integer|min:1|max:365',
            'idle_alert_day'             => 'nullable|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'idle_alert_time'            => 'required|date_format:H:i,H:i:s',
            'doc_reminders_enabled'      => 'nullable|boolean',
            'doc_reminder_hours_before'  => 'required|integer|min:1|max:168',
            'lease_expiry_reminders'     => 'nullable|boolean',
            'lease_reminder_days_before' => 'required|integer|min:1|max:365',
            'fica_reminders'             => 'nullable|boolean',
            'ffc_reminders'              => 'nullable|boolean',
            'task_due_reminders'         => 'nullable|boolean',
            'task_reminder_hours_before'  => 'required|integer|min:1|max:168',
            'event_reminder_hours_before' => 'required|integer|min:1|max:168',
            'auto_archive_done_days'      => 'nullable|integer|min:0|max:365',
            'default_calendar_view'      => 'required|in:month,week,day,agenda',
            'weekend_visible'            => 'nullable|boolean',
            'working_hours_start'        => 'required|date_format:H:i,H:i:s',
            'working_hours_end'          => 'required|date_format:H:i,H:i:s',
            'notify_in_app'              => 'nullable|boolean',
            'notify_email'               => 'nullable|boolean',
            'notify_push'                => 'nullable|boolean',
            'open_hours_enabled'         => 'nullable|boolean',
            'open_hours_start'           => 'required|date_format:H:i,H:i:s',
            'open_hours_end'             => 'required|date_format:H:i,H:i:s',
            'min_minutes_between_same'   => 'required|integer|min:0|max:10080',
        ]);

        // Boolean fields default to false if not sent
        foreach ([
            'idle_alerts_enabled', 'doc_reminders_enabled', 'lease_expiry_reminders',
            'fica_reminders', 'ffc_reminders', 'task_due_reminders',
            'weekend_visible', 'notify_in_app', 'notify_email',
            'notify_push', 'open_hours_enabled',
        ] as $boolField) {
            $validated[$boolField] = $request->boolean($boolField);
        }

        // Trim seconds from time fields so the DB stores a clean H:i
        foreach (['idle_alert_time', 'working_hours_start', 'working_hours_end', 'open_hours_start', 'open_hours_end'] as $timeField) {
            if (!empty($validated[$timeField])) {
                $validated[$timeField] = substr($validated[$timeField], 0, 5);
            }
        }

        // Empty string → null for nullable integer fields
        if (array_key_exists('auto_archive_done_days', $validated) && $validated['auto_archive_done_days'] === '') {
            $validated['auto_archive_done_days'] = null;
        }

        UserDashboardSetting::updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        return back()->with('success', 'Dashboard settings saved.');
    }
}
