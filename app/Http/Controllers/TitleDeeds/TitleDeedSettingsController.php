<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Http\Controllers\Controller;
use App\Models\TitleDeeds\TitleDeedSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/title-deed-register.md §9 - the agency's Title Deed Register settings. ONE saver for the settings screen AND the
 * Setup Wizard, and every field is has()-guarded: a post that never rendered a field (the wizard step posts a subset)
 * leaves it exactly as it was (agency-onboarding-setup.md §6.1).
 */
class TitleDeedSettingsController extends Controller
{
    public function edit(Request $request)
    {
        abort_unless($request->user()->hasPermission('title_deeds.manage_settings'), 403);
        $agencyId = $request->user()->effectiveAgencyId();

        $values = [];
        foreach (array_keys(TitleDeedSetting::FIELDS) as $key) {
            $values[$key] = TitleDeedSetting::{lcfirst(str_replace('_', '', ucwords($key, '_'))) . 'For'}($agencyId);
        }

        return view('title-deeds.settings', [
            'values' => $values,
            'repModes' => TitleDeedSetting::REP_MODES,
            'ranges' => TitleDeedSetting::INT_RANGES,
            'noAgency' => ! $agencyId,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->save($request);

        return redirect()->route('corex.settings.title-deeds.edit')->with('success', 'Title deed settings saved.');
    }

    /** Also invoked by the Setup Wizard as a step saver (Request only). */
    public function save(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('title_deeds.manage_settings'), 403);
        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 422, 'Switch into an agency first.');

        $rules = [];
        foreach (TitleDeedSetting::FIELDS as $key => $type) {
            if (! $request->has($key)) {
                continue;
            }
            $rules[$key] = match ($type) {
                'bool' => ['nullable', 'boolean'],
                'mode' => ['required', Rule::in(array_keys(TitleDeedSetting::REP_MODES))],
                'int' => ['required', 'integer', 'min:' . TitleDeedSetting::INT_RANGES[$key][0], 'max:' . TitleDeedSetting::INT_RANGES[$key][1]],
            };
        }
        if ($rules === []) {
            return;
        }

        $messages = [];
        foreach (TitleDeedSetting::INT_RANGES as $key => [$min, $max]) {
            $label = str_replace('_', ' ', $key);
            $messages["$key.integer"] = "Enter a whole number for $label.";
            $messages["$key.required"] = "Enter a number for $label.";
            $messages["$key.min"] = "$label must be at least $min.";
            $messages["$key.max"] = "$label can be at most $max.";
        }
        $data = $request->validate($rules, $messages);

        $row = TitleDeedSetting::withoutGlobalScopes()->firstOrNew(['agency_id' => $agencyId]);
        $before = $row->exists ? $row->only(array_keys(TitleDeedSetting::FIELDS)) : [];
        foreach ($data as $key => $value) {
            $row->{$key} = TitleDeedSetting::FIELDS[$key] === 'bool' ? $request->boolean($key) : $value;
        }
        $row->agency_id = $agencyId;
        $row->save();

        Log::info('Title deed settings changed', [
            'agency_id' => $agencyId, 'user_id' => $user->id,
            'changed' => array_diff_assoc($row->only(array_keys($data)), $before),
        ]);
    }
}
