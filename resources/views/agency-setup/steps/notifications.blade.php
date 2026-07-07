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
    <p class="text-[11px] italic" style="color:var(--text-muted);">Agents can still fine-tune their own reminder timing later from their dashboard settings.</p>
</div>
