{{-- props: $contact, $property, $linkedProperties, $channel, $availableTemplates, $context, $propertyStatuses --}}

@php
    // A.3.4 — collision badges. Map each prospect-status to a label + tint
    // so the picker shows the agent HFC's existing relationship to each
    // candidate property at a glance. 'available' shows nothing (clean).
    $statusBadgeMap = [
        'available'       => null,
        'held'            => ['label' => 'On HFC books',         'bg' => 'rgba(16,185,129,0.16)', 'fg' => '#10b981', 'tone' => 'positive'],
        'own_draft'       => ['label' => 'Your draft',           'bg' => 'rgba(245,158,11,0.18)', 'fg' => '#d97706', 'tone' => 'caution'],
        'other_draft'     => ['label' => 'Draft by colleague',   'bg' => 'rgba(220,38,38,0.16)',  'fg' => '#dc2626', 'tone' => 'block'],
        'previously_sold' => ['label' => 'Previously sold',      'bg' => 'rgba(100,116,139,0.18)','fg' => '#64748b', 'tone' => 'soft'],
        'previously_held' => ['label' => 'Previously held',      'bg' => 'rgba(100,116,139,0.18)','fg' => '#64748b', 'tone' => 'soft'],
    ];

    $statuses = $propertyStatuses ?? [];
    $selectedStatus = $property ? ($statuses[(int) $property->id] ?? null) : null;
    $selectedBadge  = $selectedStatus ? ($statusBadgeMap[$selectedStatus['status']] ?? null) : null;
@endphp

<div class="space-y-4">

    {{-- Property picker --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);"
         x-data="propertyPickerCollision({
            statuses: @js(collect($statuses)->map(fn ($s) => $s['status'] ?? 'available')),
            agentNames: @js(collect($statuses)->map(fn ($s) => $s['agent_name'] ?? null)),
            currentPropertyId: {{ $property?->id ?? 'null' }},
         })">
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
            Property this pitch is about
        </label>
        <select @change="onPickerChange($event.target.value)"
                class="w-full px-3 py-2 text-sm rounded"
                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($linkedProperties as $p)
                @php
                    $addr = trim(((string) ($p->street_number ?? '')) . ' ' . ((string) ($p->street_name ?? '')));
                    $addr = $addr !== '' ? $addr : '(no address)';
                    $suburb = $p->suburb ?? '';
                    $price = ($p->price ?? 0) > 0 ? ' — R ' . number_format((float) $p->price, 0, '.', ',') : '';
                    $badge = $statusBadgeMap[$statuses[(int) $p->id]['status'] ?? 'available'] ?? null;
                    $badgeSuffix = $badge ? ' · ' . $badge['label'] : '';
                @endphp
                <option value="{{ $p->id }}" @selected($property && (int) $property->id === (int) $p->id)
                        data-prospect-status="{{ $statuses[(int) $p->id]['status'] ?? 'available' }}">
                    {{ $addr }}{{ $suburb !== '' ? ', ' . $suburb : '' }}{{ $price }}{{ $badgeSuffix }}
                </option>
            @endforeach
        </select>

        @if($selectedBadge)
            <div class="mt-2 inline-flex items-center gap-2 px-2 py-1 rounded text-xs font-semibold"
                 data-prospect-status-badge="{{ $selectedStatus['status'] }}"
                 style="background: {{ $selectedBadge['bg'] }}; color: {{ $selectedBadge['fg'] }};">
                <span>{{ $selectedBadge['label'] }}</span>
                @if(in_array($selectedStatus['status'], ['own_draft', 'other_draft'], true) && !empty($selectedStatus['agent_name']))
                    <span style="opacity: 0.85;">· {{ $selectedStatus['agent_name'] }}</span>
                @endif
                @if(!empty($selectedStatus['days_in_state']))
                    <span style="opacity: 0.85;">· {{ $selectedStatus['days_in_state'] }}d</span>
                @endif
                @if(!empty($selectedStatus['sale_date']))
                    <span style="opacity: 0.85;">· {{ $selectedStatus['sale_date'] }}</span>
                @endif
            </div>
        @endif
    </div>

    {{-- Channel toggle --}}
    <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
        <button type="button" @click="switchChannel('whatsapp')"
                class="px-4 py-2 text-sm font-semibold"
                style="background: {{ $channel === 'whatsapp' ? '#00d4aa' : 'var(--surface)' }};
                       color: {{ $channel === 'whatsapp' ? '#003a2f' : 'var(--text-secondary)' }};">
            WhatsApp
        </button>
        <button type="button" @click="switchChannel('email')"
                class="px-4 py-2 text-sm font-semibold"
                style="background: {{ $channel === 'email' ? '#00d4aa' : 'var(--surface)' }};
                       color: {{ $channel === 'email' ? '#003a2f' : 'var(--text-secondary)' }};">
            Email
        </button>
    </div>

    {{-- Template selector --}}
    @if($availableTemplates->isNotEmpty())
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
            Template
        </label>
        <select @change="switchTemplate($event.target.value)"
                class="w-full px-3 py-2 text-sm rounded"
                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($availableTemplates as $t)
                <option value="{{ $t->id }}" @selected($context && $context->template && (int) $context->template->id === (int) $t->id)>
                    {{ $t->name }}{{ $t->is_default_for_channel ? ' (default)' : '' }}
                </option>
            @endforeach
        </select>
    </div>
    @endif

    @if($context)

    {{-- Opt-out hard block --}}
    @if($context->optOutBlocks)
        <div class="rounded-md p-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 18%, transparent); border: 1px solid var(--ds-crimson); color: var(--text-primary);">
            <div class="font-semibold" style="color: var(--ds-crimson);">Contact has opted out</div>
            <div class="mt-1">This contact has been opted out of messaging. No further pitches can be sent until they re-consent.</div>
        </div>
    @endif

    {{-- Subject (email only) --}}
    @if($channel === 'email')
    <div>
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
            Subject
        </label>
        <input type="text" x-model="subject"
               class="w-full px-3 py-2 text-sm rounded"
               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
    </div>
    @endif

    {{-- Body editor --}}
    <div>
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <label class="block text-xs font-semibold" style="color: var(--text-secondary);">
                Message body
            </label>
            <span class="text-xs" style="color: var(--text-muted);">
                Edits are reflected exactly in the recorded send.
            </span>
        </div>
        <textarea x-model="body" rows="14"
                  class="w-full px-3 py-2 text-sm rounded"
                  style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-family: ui-monospace, SFMono-Regular, monospace;"></textarea>
    </div>

    {{-- Validation issues (no phone / no email / no tracking link) --}}
    @if(!empty($context->validationIssues))
        <div class="rounded-md p-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson); color: var(--text-primary);">
            <div class="font-semibold mb-1" style="color: var(--ds-crimson);">Cannot send:</div>
            <ul class="list-disc pl-5">
                @foreach($context->validationIssues as $code => $msg)
                    <li>{{ $msg }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Cooldown soft signal --}}
    @if($context->cooldownSignal && !$context->optOutBlocks)
        <div class="rounded-md p-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, var(--border)); color: var(--text-primary);">
            <div class="font-semibold" style="color: var(--ds-amber, #b45309);">Recently contacted</div>
            <div class="mt-1">
                This contact was messaged on
                <strong>{{ \Carbon\Carbon::parse($context->cooldownSignal['last_sent_at'])->format('j M Y g:i a') }}</strong>
                ({{ $context->cooldownSignal['last_channel'] }}). Make sure your message adds new value before sending.
            </div>
        </div>
    @endif

    {{-- Send button --}}
    <div class="flex items-center gap-3 pt-2">
        <button type="button" @click="submit()"
                :disabled="sending || {{ $context->isSendable() ? 'false' : 'true' }}"
                class="px-6 py-2.5 text-sm font-semibold rounded"
                style="background: {{ $context->isSendable() ? '#00d4aa' : 'var(--surface-2)' }};
                       color: {{ $context->isSendable() ? '#003a2f' : 'var(--text-muted)' }};
                       {{ $context->isSendable() ? '' : 'cursor: not-allowed;' }}">
            <span x-show="!sending">
                {{ $channel === 'whatsapp' ? 'Open WhatsApp & record send' : 'Open Email & record send' }}
            </span>
            <span x-show="sending" x-cloak>Recording…</span>
        </button>
        <a href="{{ route('corex.contacts.show', $contact) }}"
           class="text-sm" style="color: var(--text-muted);">
            Cancel
        </a>
    </div>

    @endif {{-- if $context --}}
</div>
