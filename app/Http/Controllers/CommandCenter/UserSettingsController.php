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

        // If agency-controlled, users cannot edit
        if ($settings->getAttribute('is_agency_controlled') ?? false) {
            return back()->with('error', 'Dashboard settings are managed by your agency administrator.');
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
            'default_calendar_view'      => 'required|in:month,week,day,agenda',
            'weekend_visible'            => 'nullable|boolean',
            'working_hours_start'        => 'required|date_format:H:i,H:i:s',
            'working_hours_end'          => 'required|date_format:H:i,H:i:s',
            'notify_in_app'              => 'nullable|boolean',
            'notify_email'               => 'nullable|boolean',
        ]);

        // Boolean fields default to false if not sent
        foreach ([
            'idle_alerts_enabled', 'doc_reminders_enabled', 'lease_expiry_reminders',
            'fica_reminders', 'ffc_reminders', 'task_due_reminders',
            'weekend_visible', 'notify_in_app', 'notify_email',
        ] as $boolField) {
            $validated[$boolField] = $request->boolean($boolField);
        }

        // Trim seconds from time fields so the DB stores a clean H:i
        foreach (['idle_alert_time', 'working_hours_start', 'working_hours_end'] as $timeField) {
            if (!empty($validated[$timeField])) {
                $validated[$timeField] = substr($validated[$timeField], 0, 5);
            }
        }

        UserDashboardSetting::updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        return back()->with('success', 'Dashboard settings saved.');
    }
}
