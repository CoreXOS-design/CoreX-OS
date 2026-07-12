{{-- Notifications & dashboard — inline wizard step.
     Fields post through the wizard form to updateDashboardMode +
     updateAgencyDashboardSettings. $dashboard = AgencyDashboardSetting (current). --}}
@php
    $d = $dashboard;
    $on = fn($f, $default = false) => old($f, $d->$f ?? $default) ? true : false;
    $toggles = [
        'Reminders' => [
            'idle_alerts_enabled'   => 'Idle-property alerts',
            'doc_reminders_enabled' => 'Missing-document reminders',
            'lease_expiry_reminders'=> 'Lease-expiry reminders',
            'fica_reminders'        => 'FICA reminders',
            'ffc_reminders'         => 'FFC (Fidelity Fund) reminders',
            'task_due_reminders'    => 'Task-due reminders',
            'overdue_daily_digest'  => 'Daily overdue digest',
        ],
        'Delivery channels' => [
            'notify_in_app' => 'In-app notifications',
            'notify_email'  => 'Email notifications',
            'notify_push'   => 'Push notifications',
        ],
    ];
@endphp
<div class="space-y-5">
    {{-- Reminder control mode --}}
    <div>
        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Who controls reminders</label>
        <p class="text-xs mb-2" style="color:var(--text-muted);">Whether reminder preferences are set per-agent or centrally by the agency.</p>
        <select name="dashboard_settings_mode"
                class="w-full max-w-sm rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="user"   @selected(old('dashboard_settings_mode', $agency->dashboard_settings_mode ?? 'user') === 'user')>Each agent controls their own reminders</option>
            <option value="agency" @selected(old('dashboard_settings_mode', $agency->dashboard_settings_mode ?? 'user') === 'agency')>The agency controls reminders for everyone</option>
        </select>
    </div>

    @foreach ($toggles as $groupLabel => $items)
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">{{ $groupLabel }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach ($items as $field => $label)
                    <label class="flex items-center justify-between gap-3 rounded-md px-3 py-2" style="border:1px solid var(--border);">
                        <span class="text-sm" style="color:var(--text-primary);">{{ $label }}</span>
                        <span class="relative inline-flex items-center flex-shrink-0">
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input type="checkbox" name="{{ $field }}" value="1" class="sr-only peer" @checked($on($field, in_array($field, ['notify_in_app','notify_email'])))>
                            <span class="w-11 h-6 rounded-full transition-colors bg-slate-300 peer-checked:bg-[var(--brand-button,#0ea5e9)]"></span>
                            <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Timing. A toggle only says WHETHER CoreX nudges; these say WHEN. Every
         field here is one updateAgencyDashboardSettings already accepts. --}}
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Timing</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">
            When the reminders above actually fire. The defaults suit most agencies.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">A property counts as idle after (days)</label>
                <input type="number" name="idle_threshold_days" min="1" max="365"
                       value="{{ old('idle_threshold_days', $d->idle_threshold_days ?? 21) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <p class="text-[11px] mt-1" style="color:var(--text-muted);">Untouched for this long and CoreX nudges the listing agent.</p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Send the idle alert on</label>
                <select name="idle_alert_day"
                        class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    @foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day)
                        <option value="{{ $day }}" @selected(old('idle_alert_day', $d->idle_alert_day ?? 'monday') === $day)>{{ ucfirst($day) }}</option>
                    @endforeach
                </select>
                <p class="text-[11px] mt-1" style="color:var(--text-muted);">One digest a week beats a trickle every day.</p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Idle alert time</label>
                <input type="time" name="idle_alert_time"
                       value="{{ old('idle_alert_time', substr((string) ($d->idle_alert_time ?? '08:00'), 0, 5)) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Daily overdue digest time</label>
                <input type="time" name="digest_time"
                       value="{{ old('digest_time', substr((string) ($d->digest_time ?? '07:00'), 0, 5)) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <p class="text-[11px] mt-1" style="color:var(--text-muted);">Only fires if the digest toggle above is on.</p>
            </div>
        </div>
    </div>

    {{-- Quiet hours. Rendered here WITH a hidden "0" companion, so the saver
         sees the field and honours an explicit off — see the $request->has()
         guard in SettingsController@updateAgencyDashboardSettings. --}}
    <div x-data="{ quiet: {{ $on('open_hours_enabled') ? 'true' : 'false' }} }">
        <label class="flex items-center justify-between gap-3 rounded-md px-3 py-2 mb-3" style="border:1px solid var(--border);">
            <span class="min-w-0">
                <span class="block text-sm" style="color:var(--text-primary);">Quiet hours</span>
                <span class="block text-xs" style="color:var(--text-muted);">Hold notifications outside your working day so nobody is pinged at 22:00.</span>
            </span>
            <span class="relative inline-flex items-center flex-shrink-0">
                <input type="hidden" name="open_hours_enabled" value="0">
                <input type="checkbox" name="open_hours_enabled" value="1" x-model="quiet" class="sr-only peer">
                <span class="w-11 h-6 rounded-full transition-colors bg-slate-300 peer-checked:bg-[var(--brand-button,#0ea5e9)]"></span>
                <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
            </span>
        </label>
        <div x-show="quiet" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Start sending at</label>
                <input type="time" name="open_hours_start"
                       value="{{ old('open_hours_start', substr((string) ($d->open_hours_start ?? '07:00'), 0, 5)) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Stop sending at</label>
                <input type="time" name="open_hours_end"
                       value="{{ old('open_hours_end', substr((string) ($d->open_hours_end ?? '18:00'), 0, 5)) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
        </div>
    </div>

    <p class="text-[11px] italic" style="color:var(--text-muted);">Agents can still fine-tune their own reminder timing later from their dashboard settings.</p>
</div>
