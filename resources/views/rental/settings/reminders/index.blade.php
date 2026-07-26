@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Dark header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Email Reminders</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.settings') }}" class="text-white/60 hover:text-white">&larr; Rental Settings</a>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('rental.settings.reminders.update') }}"
          x-data="{
              mode: '{{ old('mode', $settings->mode) }}',
              enabled: {{ old('enabled', $settings->enabled) ? 'true' : 'false' }}
          }"
          class="max-w-2xl space-y-5">
        @csrf
        @method('PUT')

        {{-- Card 1: Enable/Disable --}}
        <div class="border rounded-lg p-5" style="background:var(--surface); border-color:var(--border)">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold" style="color:var(--text-primary)">Automatic Reminders</h3>
                    <p class="text-sm" style="color:var(--text-muted)">Send automatic email reminders for unsigned documents</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" x-model="enabled"
                           class="sr-only peer" {{ $settings->enabled ? 'checked' : '' }}>
                    <div class="w-11 h-6 bg-[var(--surface-2)] peer-focus:ring-2 peer-focus:ring-[var(--brand-icon)]/30
                                rounded-full peer peer-checked:bg-[var(--brand-icon)]
                                after:content-[''] after:absolute after:top-[2px] after:left-[2px]
                                after:bg-[var(--surface)] after:border after:border-[var(--border)] after:rounded-full after:h-5 after:w-5
                                after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
            </div>
        </div>

        {{-- Card 2: Mode selector --}}
        <div class="border rounded-lg p-5" style="background:var(--surface); border-color:var(--border)" x-show="enabled" x-transition x-cloak>
            <h3 class="font-semibold mb-3" style="color:var(--text-primary)">Reminder Mode</h3>
            <div class="grid grid-cols-2 gap-3">
                <label class="border rounded-lg p-4 cursor-pointer transition"
                       :style="mode === 'escalating' ? 'border-color:var(--brand-icon); background:color-mix(in srgb, var(--brand-icon) 12%, transparent);' : 'border-color:var(--border);'">
                    <input type="radio" name="mode" value="escalating" x-model="mode" class="sr-only">
                    <div class="font-medium text-sm" style="color:var(--text-primary)">Escalating</div>
                    <div class="text-xs mt-1" style="color:var(--text-muted)">Gentle &rarr; Firm &rarr; Team Alert &rarr; Final</div>
                </label>
                <label class="border rounded-lg p-4 cursor-pointer transition"
                       :style="mode === 'simple' ? 'border-color:var(--brand-icon); background:color-mix(in srgb, var(--brand-icon) 12%, transparent);' : 'border-color:var(--border);'">
                    <input type="radio" name="mode" value="simple" x-model="mode" class="sr-only">
                    <div class="font-medium text-sm" style="color:var(--text-primary)">Simple Interval</div>
                    <div class="text-xs mt-1" style="color:var(--text-muted)">Same reminder every N days</div>
                </label>
            </div>
        </div>

        {{-- Card 3: Escalating mode --}}
        <div class="border rounded-lg p-5" style="background:var(--surface); border-color:var(--border)" x-show="enabled && mode === 'escalating'" x-transition x-cloak>
            <h3 class="font-semibold mb-1" style="color:var(--text-primary)">Escalation Schedule</h3>
            <p class="text-xs mb-4" style="color:var(--text-muted)">Days after document is sent before each reminder level triggers</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Gentle reminder after (days)</label>
                    <input type="number" name="gentle_after_days"
                           value="{{ old('gentle_after_days', $settings->gentle_after_days) }}"
                           min="1" max="30"
                           class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Firm reminder after (days)</label>
                    <input type="number" name="firm_after_days"
                           value="{{ old('firm_after_days', $settings->firm_after_days) }}"
                           min="1" max="60"
                           class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Team alert after (days)</label>
                    <input type="number" name="team_alert_after_days"
                           value="{{ old('team_alert_after_days', $settings->team_alert_after_days) }}"
                           min="1" max="60"
                           class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Final reminder after (days)</label>
                    <input type="number" name="final_after_days"
                           value="{{ old('final_after_days', $settings->final_after_days) }}"
                           min="1" max="90"
                           class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Max email reminders per signer</label>
                <input type="number" name="max_escalating_reminders"
                       value="{{ old('max_escalating_reminders', $settings->max_escalating_reminders) }}"
                       min="1" max="10"
                       class="w-32 border rounded-lg px-3 py-1.5 text-sm outline-none"
                       style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
            </div>
        </div>

        {{-- Card 4: Simple mode --}}
        <div class="border rounded-lg p-5" style="background:var(--surface); border-color:var(--border)" x-show="enabled && mode === 'simple'" x-transition x-cloak>
            <h3 class="font-semibold mb-1" style="color:var(--text-primary)">Simple Interval</h3>
            <p class="text-xs mb-4" style="color:var(--text-muted)">Send the same reminder at a fixed interval until the max is reached</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Send reminder every (days)</label>
                    <input type="number" name="interval_days"
                           value="{{ old('interval_days', $settings->interval_days) }}"
                           min="1" max="30"
                           class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Max reminders per signer</label>
                    <input type="number" name="max_simple_reminders"
                           value="{{ old('max_simple_reminders', $settings->max_simple_reminders) }}"
                           min="1" max="20"
                           class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
            </div>
        </div>

        {{-- Card 5: Custom email template --}}
        <div class="border rounded-lg p-5" style="background:var(--surface); border-color:var(--border)" x-show="enabled" x-transition x-cloak>
            <h3 class="font-semibold mb-1" style="color:var(--text-primary)">Custom Email Template</h3>
            <p class="text-xs mb-3" style="color:var(--text-muted)">Leave blank to use the default template. Available placeholders:</p>
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach(['{signer_name}', '{document_name}', '{agent_name}', '{signing_link}', '{days_waiting}'] as $ph)
                    <code class="px-2 py-0.5 rounded text-xs font-mono" style="background:var(--surface-2); color:var(--text-secondary)">{{ $ph }}</code>
                @endforeach
            </div>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Subject</label>
                    <input type="text" name="email_subject"
                           value="{{ old('email_subject', $settings->email_subject) }}"
                           placeholder="e.g. Reminder: Please sign {document_name}"
                           class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                           style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted)">Body</label>
                    <textarea name="email_body" rows="6"
                              placeholder="Hi {signer_name},&#10;&#10;Your signature is needed on {document_name}. It has been {days_waiting} days since this was sent.&#10;&#10;Please click the link below to sign."
                              class="w-full border rounded-lg px-3 py-1.5 text-sm outline-none"
                              style="border-color:var(--border); background:var(--surface); color:var(--text-primary)">{{ old('email_body', $settings->email_body) }}</textarea>
                    <p class="text-xs mt-1" style="color:var(--text-faint)">The Sign Now button and agent footer are always included below your message.</p>
                </div>
            </div>
        </div>

        {{-- Save --}}
        <div class="flex items-center gap-4">
            <button type="submit"
                    class="px-6 py-2 text-white rounded-lg text-sm font-medium transition"
                    style="background:var(--brand-button);"
                    onmouseover="this.style.opacity='0.85'"
                    onmouseout="this.style.opacity='1'">
                Save Settings
            </button>
            @if($settings->updatedByUser)
                <span class="text-xs" style="color:var(--text-faint)">
                    Last updated by {{ $settings->updatedByUser->name }}
                    on {{ $settings->updated_at->format('d M Y H:i') }}
                </span>
            @endif
        </div>
    </form>

</div>
@endsection
