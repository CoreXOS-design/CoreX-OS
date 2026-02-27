<?php

namespace App\Http\Controllers\Rental;

use App\Http\Controllers\Controller;
use App\Models\Rental\RentalReminderSetting;
use Illuminate\Http\Request;

class RentalReminderSettingsController extends Controller
{
    public function index()
    {
        $settings = RentalReminderSetting::current();

        return view('rental.settings.reminders.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'mode' => 'required|in:escalating,simple',

            // Escalating mode
            'gentle_after_days' => 'required_if:mode,escalating|integer|min:1|max:30',
            'firm_after_days' => 'required_if:mode,escalating|integer|min:1|max:60',
            'team_alert_after_days' => 'required_if:mode,escalating|integer|min:1|max:60',
            'final_after_days' => 'required_if:mode,escalating|integer|min:1|max:90',
            'max_escalating_reminders' => 'required_if:mode,escalating|integer|min:1|max:10',

            // Simple mode
            'interval_days' => 'required_if:mode,simple|integer|min:1|max:30',
            'max_simple_reminders' => 'required_if:mode,simple|integer|min:1|max:20',

            // Custom email
            'email_subject' => 'nullable|string|max:200',
            'email_body' => 'nullable|string|max:5000',
        ]);

        $settings = RentalReminderSetting::current();

        $settings->update(array_merge($validated, [
            'enabled' => $request->boolean('enabled'),
            'updated_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Reminder settings saved.');
    }
}
