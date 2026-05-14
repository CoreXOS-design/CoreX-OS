{{-- props: $contact, $property, $linkedProperties, $channel, $availableTemplates, $context --}}

<div class="space-y-4">

    {{-- Property picker --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
            Property this pitch is about
        </label>
        <select @change="switchProperty($event.target.value)"
                class="w-full px-3 py-2 text-sm rounded"
                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($linkedProperties as $p)
                @php
                    $addr = trim(((string) ($p->street_number ?? '')) . ' ' . ((string) ($p->street_name ?? '')));
                    $addr = $addr !== '' ? $addr : '(no address)';
                    $suburb = $p->suburb ?? '';
                    $price = ($p->price ?? 0) > 0 ? ' — R ' . number_format((float) $p->price, 0, '.', ',') : '';
                @endphp
                <option value="{{ $p->id }}" @selected($property && (int) $property->id === (int) $p->id)>
                    {{ $addr }}{{ $suburb !== '' ? ', ' . $suburb : '' }}{{ $price }}
                </option>
            @endforeach
        </select>
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
