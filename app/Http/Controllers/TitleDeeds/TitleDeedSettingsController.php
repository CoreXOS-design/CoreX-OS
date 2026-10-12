<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Http\Controllers\Controller;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\TitleDeeds\TitleDeedSettingHistory;
use App\Models\TitleDeeds\TitleDeedStorageLocation;
use App\Services\TitleDeeds\TitleDeedPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/title-deed-register.md sections 9 and 15 - the agency's Title Deed Register settings. ONE saver for the settings screen
 * AND the Setup Wizard, and every field is has()-guarded: a post that never rendered a field (the wizard step posts a subset, and a
 * setting hidden by its parent is not posted) leaves it exactly as it was (agency-onboarding-setup.md section 6.1).
 * Combinations that cannot work are refused with a plain message (TitleDeedPolicy::conflicts) and every change is written to
 * title_deed_setting_history.
 */
class TitleDeedSettingsController extends Controller
{
    public function edit(Request $request)
    {
        abort_unless($request->user()->hasPermission('title_deeds.manage_settings'), 403);
        $agencyId = $request->user()->effectiveAgencyId();

        return view('title-deeds.settings', [
            'values' => $agencyId ? $this->current($agencyId) : [],
            'repModes' => TitleDeedSetting::REP_MODES,
            'proofModes' => TitleDeedSetting::PROOF_MODES,
            'ranges' => TitleDeedSetting::INT_RANGES,
            'noAgency' => ! $agencyId,
            'locationCount' => $agencyId ? TitleDeedStorageLocation::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->count() : 0,
            'history' => $agencyId ? TitleDeedSettingHistory::query()->where('agency_id', $agencyId)->with('user:id,name')->orderByDesc('id')->limit(100)->get() : collect(),
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
                'bool' => ['required', 'in:0,1,true,false'],
                'proof' => ['required', Rule::in(array_keys(TitleDeedSetting::PROOF_MODES))],
                'mode' => ['required', Rule::in(array_keys(TitleDeedSetting::REP_MODES))],
                'int' => ['required', 'integer', 'min:' . TitleDeedSetting::INT_RANGES[$key][0], 'max:' . TitleDeedSetting::INT_RANGES[$key][1]],
            };
        }
        if ($rules === []) {
            return;
        }

        $messages = [];
        foreach (TitleDeedSetting::FIELDS as $key => $type) {
            $label = TitleDeedSetting::LABELS[$key] ?? $key;
            $messages["$key.required"] = "Choose an answer for \"$label\".";
            $messages["$key.in"] = "That is not one of the choices for \"$label\".";
            if ($type === 'int') {
                [$min, $max] = TitleDeedSetting::INT_RANGES[$key];
                $messages["$key.integer"] = "Enter a whole number for \"$label\".";
                $messages["$key.min"] = "\"$label\" must be at least $min.";
                $messages["$key.max"] = "\"$label\" can be at most $max.";
            }
        }
        $data = $request->validate($rules, $messages);

        // What the settings would be after this save: the saved choices overlaid with what was posted.
        $before = $this->current($agencyId);
        $after = $before;
        foreach ($data as $key => $value) {
            $after[$key] = TitleDeedSetting::FIELDS[$key] === 'bool' ? $request->boolean($key) : (TitleDeedSetting::FIELDS[$key] === 'int' ? (int) $value : $value);
        }
        if ($conflicts = TitleDeedPolicy::conflicts($after)) {
            throw ValidationException::withMessages($conflicts);
        }

        $changed = [];
        foreach (array_keys($data) as $key) {
            if ($before[$key] !== $after[$key]) {
                $changed[$key] = [$before[$key], $after[$key]];
            }
        }

        DB::transaction(function () use ($agencyId, $data, $after, $changed, $user, $request) {
            $row = TitleDeedSetting::withoutGlobalScopes()->firstOrNew(['agency_id' => $agencyId]);
            foreach (array_keys($data) as $key) {
                $row->{$key} = $after[$key];
            }
            $row->agency_id = $agencyId;
            $row->save();

            $source = $request->routeIs('corex.settings.title-deeds.*') ? 'screen' : 'wizard';
            foreach ($changed as $key => [$from, $to]) {
                TitleDeedSettingHistory::query()->create([
                    'agency_id' => $agencyId, 'setting_key' => $key,
                    'from_value' => $this->store($from), 'to_value' => $this->store($to),
                    'changed_by_user_id' => $user->id, 'source' => $source, 'created_at' => now(),
                ]);
            }
        });

        if ($changed) {
            Log::info('Title deed settings changed', ['agency_id' => $agencyId, 'user_id' => $user->id, 'changed' => array_map(fn ($c) => $c[1], $changed)]);
        }
    }

    /** The agency's settings as chosen (defaults where it never chose), keyed as TitleDeedSetting::FIELDS. */
    private function current(int $agencyId): array
    {
        $values = [];
        foreach (array_keys(TitleDeedSetting::FIELDS) as $key) {
            $values[$key] = TitleDeedSetting::{Str::camel($key) . 'For'}($agencyId);
        }

        return $values;
    }

    private function store(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    }
}
