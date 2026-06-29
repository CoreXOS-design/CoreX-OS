{{-- props: $contact, $property, $linkedProperties, $channel, $availableTemplates, $context, $propertyStatuses, $addressOnly --}}

@php $addressOnly = $addressOnly ?? false; @endphp

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

    {{-- AT-61 — address-only mode: no property to pick. Show the captured
         address the pitch is composed against (read-only). The pitch makes an
         honest area-level demand statement; no per-property matching claim. --}}
    @if($addressOnly)
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between gap-2 mb-1">
            <label class="block text-xs font-semibold" style="color: var(--text-secondary);">
                Address this pitch is about
            </label>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold"
                  style="background: rgba(245,158,11,0.16); color: #b45309;"
                  title="This contact has a captured property address but no linked property. The pitch references the address and area demand only — it does not claim buyers matching a specific property.">
                Address only — no property linked
            </span>
        </div>
        <div class="text-sm" style="color: var(--text-primary);">
            {{ $context?->mergeFields['property_address'] ?? $contact->composeStructuredAddress() ?? '(address unavailable)' }}
        </div>
        @if(!empty($context?->mergeFields['property_suburb']))
            <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $context->mergeFields['property_suburb'] }}</div>
        @endif
        <p class="text-xs mt-2" style="color: var(--text-muted);">
            To make a property-specific pitch, link or create a property on the contact first.
        </p>
    </div>
    @else
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
    @endif {{-- addressOnly picker branch --}}

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
    @else
    {{-- Empty state — no template for this channel. Prevents a silent blank
         composer (the body/subject would otherwise render empty with no cue). --}}
    <div class="rounded-md p-4 text-sm"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, var(--border)); color: var(--text-primary);">
        <div class="font-semibold mb-1" style="color: var(--ds-amber, #b45309);">
            No {{ $channel === 'whatsapp' ? 'WhatsApp' : 'Email' }} template configured
        </div>
        <div style="color: var(--text-secondary);">
            There's no active {{ $channel === 'whatsapp' ? 'WhatsApp' : 'Email' }} template for this agency yet, so there's nothing to pre-fill.
            Add one under Settings → Outreach Templates, or write the message below before sending.
        </div>
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

    {{-- BUG-1 fix — read-only preview. The per-send links (opt-out / opt-in / tracking)
         only get their real URLs at send time, so the editable body above keeps the
         literal {tokens} (they round-trip to the saved template and the sender fills in
         the real URLs into body_snapshot); this preview shows a friendly stand-in so the
         agent never sees raw braces. --}}
    <div>
        <div class="text-xs font-semibold mb-1" style="color: var(--text-secondary);">
            Preview — what the recipient sees
        </div>
        <div class="w-full px-3 py-2 text-sm rounded whitespace-pre-wrap"
             style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
             x-text="previewBody()"></div>
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

    {{-- AT-117 — Add to the outreach queue (ready immediately). Available any time,
         including outside the send-window (prepare now); sending from the queue is
         gated by the send-window. The send-now button above is window-disabled. --}}
    <div class="pt-3 mt-3" style="border-top: 1px solid var(--border);">
        <button type="button" @click="addToQueue()" :disabled="queuing"
                class="px-5 py-2 text-sm font-semibold rounded"
                style="background: var(--surface); border: 1px solid #00d4aa; color: var(--text-primary);">
            <span x-show="!queuing">Add to queue</span>
            <span x-show="queuing" x-cloak>Adding…</span>
        </button>
        <p class="text-[11px] mt-1.5" style="color: var(--text-muted);">
            Saves the prepared message to your Outreach Queue — work the list and tap Send during the send-window.
        </p>
    </div>

    @endif {{-- if $context --}}
</div>
