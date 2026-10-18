<?php

namespace App\Services\LeadResponse;

use App\Models\AgencyContactSettings;
use App\Support\LeadResponse\BusinessHours;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The agency's lead-response target + counting hours: validate, save, audit (Johan, 2026-10-07). The ONE write
 * path — used by the Settings page and the Setup Wizard step, so the two can never drift.
 */
class LeadResponseSettingsService
{
    /**
     * Validate the submitted hours; returns the canonical per-day array. A day left unticked is "not counted";
     * a counted day needs a start and an end, the end not before the start (equal = an empty window that counts
     * nothing); at least one day must count something.
     *
     * @param  array<string,mixed>  $input  lead_response_hours as posted
     * @return array<string,array{counted:bool,start:string,end:string}>
     */
    public function validateHours(array $input): array
    {
        $labels = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
        $errors = [];
        $out = [];

        foreach (BusinessHours::DAYS as $day) {
            $row = (array) ($input[$day] ?? []);
            $counted = filter_var($row['counted'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $start = BusinessHours::time($row['start'] ?? null);
            $end = BusinessHours::time($row['end'] ?? null);

            if ($counted && ($start === null || $end === null)) {
                $errors["lead_response_hours.$day"] = "{$labels[$day]}: give a start and an end time, or untick the day so it is not counted.";
            } elseif ($counted && $end < $start) {
                $errors["lead_response_hours.$day"] = "{$labels[$day]}: the end time must not be before the start time.";
            }

            $out[$day] = ['counted' => $counted, 'start' => $start ?? BusinessHours::DEFAULT_START, 'end' => $end ?? BusinessHours::DEFAULT_END];
        }

        if ($errors === [] && ! BusinessHours::hasCountedTime($out)) {
            $errors['lead_response_hours'] = 'At least one day must have counted hours (start before end).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Save whatever was submitted (either field may be absent — the wizard and the page post the same names).
     * Returns true when something changed (and was audited).
     *
     * @param  array<string,mixed>|null  $hours
     */
    public function save(int $agencyId, ?int $targetMinutes, ?array $hours, ?int $userId): bool
    {
        $settings = AgencyContactSettings::forAgency($agencyId);

        $old = ['target_minutes' => $settings->leadResponseTargetMinutes(), 'hours' => $settings->leadResponseHours()];
        $new = [
            'target_minutes' => $targetMinutes ?? $old['target_minutes'],
            'hours' => $hours !== null ? BusinessHours::normalise($hours) : $old['hours'],
        ];

        if ($old === $new) {
            return false;
        }

        DB::transaction(function () use ($settings, $new, $old, $agencyId, $userId) {
            $settings->update([
                'lead_response_target_minutes' => $new['target_minutes'],
                'lead_response_hours' => $new['hours'],
            ]);

            DB::table('lead_response_setting_audit')->insert([
                'agency_id' => $agencyId,
                'changed_by_user_id' => $userId,
                'old_values' => json_encode($old),
                'new_values' => json_encode($new),
                'changed_at' => now(),
            ]);
        });

        return true;
    }
}
