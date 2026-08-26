{{-- props: $send, $clickInfo, $outcomeOptions, $optedOut --}}

@php
    $channelIcon = $send->channel === 'whatsapp' ? '💬' : '✉️';
    $channelLabel = $send->channel === 'whatsapp' ? 'WhatsApp' : 'Email';
    $outcomeColor = match ($send->outcome) {
        'sent'           => 'var(--text-muted)',
        'clicked'        => '#00d4aa',
        'replied'        => 'var(--ds-green)',
        'booked'         => 'var(--ds-green)',
        'no_response'    => 'var(--text-muted)',
        'not_interested' => 'var(--ds-crimson)',
        'bounced'        => 'var(--ds-crimson)',
        default          => 'var(--text-secondary)',
    };
    $outcomeLabel = match ($send->outcome) {
        'sent'           => 'Sent',
        'clicked'        => 'Clicked',
        'replied'        => 'Replied',
        'booked'         => 'Booked',
        'no_response'    => 'No response',
        'not_interested' => 'Not interested',
        'bounced'        => 'Bounced',
        default          => ucfirst((string) $send->outcome),
    };
    // AT-266 — canonical street line (unit + complex + street); suburb appended below.
    $propAddr = $send->property
        ? $send->property->composeAddressFromParts()
        : '';
    // AT-61 — address-only sends have no property; fall back to the recorded
    // address/suburb snapshot so the row still says what was pitched about.
    $sendAddr = $send->property
        ? trim($propAddr . (!empty($send->property->suburb) ? ', ' . $send->property->suburb : ''))
        : trim((string) ($send->address_snapshot ?? ''));
    $sendAddr = $sendAddr !== '' ? $sendAddr : null;
@endphp

<div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">

    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap mb-1">
                <span class="text-base">{{ $channelIcon }}</span>
                <span class="font-semibold text-sm" style="color: var(--text-primary);">{{ $channelLabel }} pitch</span>
                @if($send->template)
                    <span class="text-xs" style="color: var(--text-muted);">· template: {{ $send->template->name }}</span>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider"
                      style="background: color-mix(in srgb, {{ $outcomeColor }} 18%, transparent); color: {{ $outcomeColor }};">
                    {{ $outcomeLabel }}
                </span>
            </div>

            <div class="text-xs" style="color: var(--text-muted);">
                Sent {{ optional($send->sent_at)->format('j M Y g:i a') }}
                @if($send->agent)
                    by {{ $send->agent->name ?? ('agent #' . $send->agent_id) }}
                @endif
                @if($sendAddr)
                    · about {{ $sendAddr }}{{ !$send->property ? ' (address only)' : '' }}
                @endif
            </div>

            @if($clickInfo)
                <div class="text-xs mt-1" style="color: #00d4aa;">
                    {{ $clickInfo->total }} click{{ (int) $clickInfo->total === 1 ? '' : 's' }}
                    · last clicked {{ \Carbon\Carbon::parse($clickInfo->last_click_at)->diffForHumans() }}
                </div>
            @endif

            @if($send->outcome_note)
                <div class="text-xs mt-1 italic" style="color: var(--text-secondary);">"{{ $send->outcome_note }}"</div>
            @endif
        </div>

        <div class="flex items-center gap-2 flex-shrink-0 flex-wrap">
            <a href="{{ $send->landingUrl() }}" target="_blank" rel="noopener"
               class="text-xs font-semibold px-2 py-1 rounded"
               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
               title="Open the seller's landing page in a new tab">
                Landing ↗
            </a>
            @if(!$optedOut)
                <a href="{{ route('seller-outreach.composer.show', ['contact' => $send->contact_id, 'property_id' => $send->property_id, 'channel' => $send->channel, 'template_id' => $send->template_id]) }}"
                   class="text-xs font-semibold px-2 py-1 rounded"
                   style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
                   title="Open the composer pre-filled from this send">
                    Resend ↗
                </a>
            @endif
            <button type="button" @click="openSendId = (openSendId === {{ $send->id }} ? null : {{ $send->id }})"
                    class="text-xs font-semibold px-2 py-1 rounded"
                    style="background: #00d4aa; color: #003a2f;">
                <span x-show="openSendId !== {{ $send->id }}">Details</span>
                <span x-show="openSendId === {{ $send->id }}" x-cloak>Close</span>
            </button>
        </div>
    </div>

    {{-- Expanded details --}}
    <div x-show="openSendId === {{ $send->id }}" x-cloak class="mt-3 pt-3" style="border-top: 1px solid var(--border);">

        {{-- Outcome update form --}}
        <form method="POST" action="{{ route('seller-outreach.composer.outcome', ['contact' => $send->contact_id, 'send' => $send->id]) }}"
              class="mb-3">
            @csrf
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                Update outcome
            </label>
            <div class="flex items-center gap-2 flex-wrap">
                <select name="outcome"
                        class="px-2 py-1 text-sm rounded"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($outcomeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($send->outcome === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <input type="text" name="outcome_note" value="{{ $send->outcome_note }}" maxlength="1000"
                       placeholder="Optional note (e.g. Called back, scheduling viewing)"
                       class="flex-1 min-w-[150px] px-2 py-1 text-sm rounded"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <button type="submit"
                        class="px-3 py-1 text-sm font-semibold rounded"
                        style="background: #00d4aa; color: #003a2f;">
                    Save
                </button>
            </div>
        </form>

        {{-- Body snapshot --}}
        <div>
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                Body sent
            </div>
            @if($send->subject_snapshot)
                <div class="text-xs mb-2" style="color: var(--text-secondary);">
                    <strong>Subject:</strong> {{ $send->subject_snapshot }}
                </div>
            @endif
            <pre class="text-xs p-3 rounded whitespace-pre-wrap overflow-x-auto"
                 style="background: var(--surface-2); color: var(--text-secondary); font-family: ui-monospace, SFMono-Regular, monospace;">{{ $send->body_snapshot }}</pre>
        </div>

        {{-- Facts snapshot --}}
        @if(!empty($send->facts_snapshot))
            <details class="mt-3">
                <summary class="cursor-pointer text-xs" style="color: var(--text-muted);">
                    Facts captured at send time (PPRA snapshot)
                </summary>
                <pre class="mt-2 text-xs p-3 rounded overflow-x-auto"
                     style="background: var(--surface-2); color: var(--text-secondary); font-family: ui-monospace, SFMono-Regular, monospace;">{{ json_encode($send->facts_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endif
    </div>
</div>
