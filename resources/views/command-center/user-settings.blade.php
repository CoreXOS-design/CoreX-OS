@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">

    {{-- ══════ PAGE HEADER (Pattern A — branded) ══════ --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Dashboard Settings</h1>
                <p class="text-sm text-white/60">Customise your reminders, alerts, and calendar preferences.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('corex.dashboard') }}" class="corex-btn-outline">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    @if($isAgencyControlled)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <div class="flex-1">
                <strong>Agency-controlled settings.</strong>
                <span style="color: var(--text-muted);">Your agency administrator manages these settings for all agents. Contact your admin to request changes.</span>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <div class="flex-1">{{ session('error') }}</div>
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <div class="flex-1">
                <strong>Could not save.</strong>
                <ul class="list-disc list-inside mt-1 space-y-0.5" style="color: var(--text-muted);">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('command-center.user-settings.update') }}">
        @csrf @method('PUT')

        <div class="space-y-6">

            {{-- ═══════ PROPERTY IDLE ALERTS ═══════ --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" /></svg>
                        Property Idle Alerts
                    </h3>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                        <input type="hidden" name="idle_alerts_enabled" value="0">
                        <input type="checkbox" name="idle_alerts_enabled" value="1" {{ $settings->idle_alerts_enabled ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                        Enabled
                    </label>
                </div>
                <div class="corex-panel-body space-y-4">
                    <p class="text-xs" style="color:var(--text-muted);">Get reminded about properties that haven't had any activity. For example, every Wednesday review all properties untouched for 2 weeks.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Alert after (days idle)</label>
                            <input type="number" name="idle_threshold_days" value="{{ $settings->idle_threshold_days ?? 14 }}" min="1" max="365"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   {{ $isAgencyControlled ? 'disabled' : '' }}>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Alert on day</label>
                            <select name="idle_alert_day"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                    {{ $isAgencyControlled ? 'disabled' : '' }}>
                                <option value="" {{ !$settings->idle_alert_day ? 'selected' : '' }}>Every day</option>
                                @foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day)
                                    <option value="{{ $day }}" {{ $settings->idle_alert_day === $day ? 'selected' : '' }}>{{ ucfirst($day) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Alert time</label>
                            <input type="time" name="idle_alert_time" value="{{ $settings->idle_alert_time ?? '08:00' }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   {{ $isAgencyControlled ? 'disabled' : '' }}>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════ DOCUMENT REMINDERS ═══════ --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        Document Reminders
                    </h3>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                        <input type="hidden" name="doc_reminders_enabled" value="0">
                        <input type="checkbox" name="doc_reminders_enabled" value="1" {{ $settings->doc_reminders_enabled ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                        Enabled
                    </label>
                </div>
                <div class="corex-panel-body">
                    <div class="max-w-xs">
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Remind me (hours before due)</label>
                        <input type="number" name="doc_reminder_hours_before" value="{{ $settings->doc_reminder_hours_before ?? 24 }}" min="1" max="168"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                               {{ $isAgencyControlled ? 'disabled' : '' }}>
                    </div>
                </div>
            </div>

            {{-- ═══════ COMPLIANCE REMINDERS ═══════ --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        Compliance Reminders
                    </h3>
                </div>
                <div class="corex-panel-body space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                <input type="hidden" name="lease_expiry_reminders" value="0">
                                <input type="checkbox" name="lease_expiry_reminders" value="1" {{ $settings->lease_expiry_reminders ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                                Lease expiry reminders
                            </label>
                            <div class="mt-2">
                                <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Days before expiry</label>
                                <input type="number" name="lease_reminder_days_before" value="{{ $settings->lease_reminder_days_before ?? 90 }}" min="1" max="365"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       {{ $isAgencyControlled ? 'disabled' : '' }}>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                <input type="hidden" name="fica_reminders" value="0">
                                <input type="checkbox" name="fica_reminders" value="1" {{ $settings->fica_reminders ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                                FICA document reminders
                            </label>
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                <input type="hidden" name="ffc_reminders" value="0">
                                <input type="checkbox" name="ffc_reminders" value="1" {{ $settings->ffc_reminders ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                                FFC expiry reminders
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════ TASK REMINDERS ═══════ --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Task Reminders
                    </h3>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                        <input type="hidden" name="task_due_reminders" value="0">
                        <input type="checkbox" name="task_due_reminders" value="1" {{ $settings->task_due_reminders ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                        Enabled
                    </label>
                </div>
                <div class="corex-panel-body space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Task reminder (hours before)</label>
                            <input type="number" name="task_reminder_hours_before" value="{{ $settings->task_reminder_hours_before ?? 4 }}" min="1" max="168"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   {{ $isAgencyControlled ? 'disabled' : '' }}>
                            <p class="mt-1 text-xs" style="color:var(--text-muted);">Email &amp; notification sent this many hours before a task is due.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Event reminder (hours before)</label>
                            <input type="number" name="event_reminder_hours_before" value="{{ $settings->event_reminder_hours_before ?? 24 }}" min="1" max="168"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   {{ $isAgencyControlled ? 'disabled' : '' }}>
                            <p class="mt-1 text-xs" style="color:var(--text-muted);">Email &amp; notification sent this many hours before a calendar event.</p>
                        </div>
                        <div class="flex flex-col justify-center">
                            <p class="text-xs leading-relaxed" style="color:var(--text-muted);">
                                When a task or event is approaching its due date, you'll receive an email and in-app notification at the configured time before.
                                You can also choose per task/event whether to receive a reminder when creating it.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════ TASK BOARD ═══════ --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Task Board
                    </h3>
                </div>
                <div class="corex-panel-body space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Auto-archive Done tasks after</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="auto_archive_done_days"
                                       value="{{ $settings->auto_archive_done_days }}"
                                       min="0" max="365" placeholder="Leave blank = never"
                                       class="w-32 rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       {{ $isAgencyControlled ? 'disabled' : '' }}>
                                <span class="text-xs" style="color:var(--text-muted);">day(s)</span>
                            </div>
                            <p class="mt-1 text-xs leading-relaxed" style="color:var(--text-muted);">
                                Completed tasks older than this are moved to Archived automatically. <strong>0</strong> = archive immediately when marked Done. <strong>Blank</strong> = never auto-archive; you'll clear the Done column manually.
                            </p>
                        </div>
                        <div class="flex items-end">
                            <p class="text-xs leading-relaxed" style="color:var(--text-muted);">
                                You can always restore an archived task from the <a href="{{ route('command-center.tasks.archived') }}" class="underline" style="color:var(--brand-icon);">Archived</a> view. Nothing is ever hard-deleted.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════ CALENDAR PREFERENCES ═══════ --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                        Calendar Preferences
                    </h3>
                </div>
                <div class="corex-panel-body">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Default view</label>
                            <select name="default_calendar_view"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                    {{ $isAgencyControlled ? 'disabled' : '' }}>
                                @foreach(['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'] as $v => $l)
                                    <option value="{{ $v }}" {{ ($settings->default_calendar_view ?? 'month') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Working hours</label>
                            <div class="flex items-center gap-2">
                                <input type="time" name="working_hours_start" value="{{ $settings->working_hours_start ?? '08:00' }}"
                                       class="flex-1 rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       {{ $isAgencyControlled ? 'disabled' : '' }}>
                                <span class="text-xs" style="color:var(--text-muted);">to</span>
                                <input type="time" name="working_hours_end" value="{{ $settings->working_hours_end ?? '17:00' }}"
                                       class="flex-1 rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       {{ $isAgencyControlled ? 'disabled' : '' }}>
                            </div>
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                <input type="hidden" name="weekend_visible" value="0">
                                <input type="checkbox" name="weekend_visible" value="1" {{ $settings->weekend_visible ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                                Show weekends
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════ NOTIFICATION CHANNELS ═══════ --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                        Notification Channels
                    </h3>
                </div>
                <div class="corex-panel-body space-y-4">
                    @if($isAgencyControlled)
                        <div class="text-xs px-3 py-2 rounded-md" style="background:var(--surface-2);color:var(--text-secondary);">
                            Your agency has locked notification settings. You can still control mobile push for your own device.
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-6">
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                            <input type="hidden" name="notify_in_app" value="0">
                            <input type="checkbox" name="notify_in_app" value="1" {{ $settings->notify_in_app ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                            In-app notifications (bell icon)
                        </label>
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                            <input type="hidden" name="notify_email" value="0">
                            <input type="checkbox" name="notify_email" value="1" {{ $settings->notify_email ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                            Email notifications
                        </label>
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                            <input type="hidden" name="notify_push" value="0">
                            <input type="checkbox" name="notify_push" value="1" {{ ($settings->notify_push ?? true) ? 'checked' : '' }} class="rounded">
                            Mobile push notifications (this device)
                        </label>
                    </div>

                    <div class="border-t pt-4" style="border-color:var(--border);">
                        <div class="flex items-center gap-2 mb-3">
                            <label class="flex items-center gap-2 text-sm font-medium" style="color:var(--text-primary);">
                                <input type="hidden" name="open_hours_enabled" value="0">
                                <input type="checkbox" name="open_hours_enabled" value="1" {{ ($settings->open_hours_enabled ?? false) ? 'checked' : '' }} class="rounded" {{ $isAgencyControlled ? 'disabled' : '' }}>
                                Open Hours (suppress email notifications outside this window)
                            </label>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Start</label>
                                <input type="time" name="open_hours_start" value="{{ substr($settings->open_hours_start ?? '07:00', 0, 5) }}" class="corex-input w-full" {{ $isAgencyControlled ? 'disabled' : '' }}>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">End</label>
                                <input type="time" name="open_hours_end" value="{{ substr($settings->open_hours_end ?? '21:00', 0, 5) }}" class="corex-input w-full" {{ $isAgencyControlled ? 'disabled' : '' }}>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Minimum minutes between same alert</label>
                                <input type="number" name="min_minutes_between_same" min="0" max="10080" value="{{ $settings->min_minutes_between_same ?? 360 }}" class="corex-input w-full" {{ $isAgencyControlled ? 'disabled' : '' }}>
                                <p class="text-xs mt-1" style="color:var(--text-tertiary);">Stops repeat pushes for the same item within this window. 360 = 6 hours.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Save button (always shown — push master is editable even under agency lock) --}}
            @unless(false)
                <div class="flex justify-end">
                    <button type="submit" class="corex-btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.875rem;">
                        Save Settings
                    </button>
                </div>
            @endunless

        </div>
    </form>

</div>
@endsection
