{{-- props: $contact, $sends, $clickCounts, $optedOut, $optedIn, $outcomeOptions --}}
@php($optedIn = $optedIn ?? ($contact->messaging_opted_in_at !== null))

<div x-data="{ openSendId: null, optOutFormOpen: false, optInFormOpen: false }" class="space-y-4">

    {{-- Flash --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <strong>{{ $errors->first() }}</strong>
        </div>
    @endif

    {{-- Opt-out banner --}}
    @if($optedOut)
        <div class="rounded-md p-4"
             style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson);">
            <div class="font-semibold mb-1" style="color: var(--ds-crimson);">
                Contact opted out of messaging
            </div>
            <div class="text-xs" style="color: var(--text-secondary);">
                Opted out on {{ optional($contact->messaging_opt_out_at)->format('j M Y g:i a') }}.
                @if($contact->messaging_opt_out_reason)
                    <br>Reason: <em>{{ $contact->messaging_opt_out_reason }}</em>
                @endif
            </div>
        </div>
    @endif

    {{-- Opt-in banner (AT-45) — recorded consent fact; does not lift opt-out --}}
    @if($optedIn)
        <div class="rounded-md p-4"
             style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid var(--ds-green);">
            <div class="font-semibold mb-1" style="color: var(--ds-green);">
                Contact opted in to messaging
            </div>
            <div class="text-xs" style="color: var(--text-secondary);">
                Opted in on {{ optional($contact->messaging_opted_in_at)->format('j M Y g:i a') }}
                @if($contact->messaging_opt_in_recorded_by_user_id)
                    by {{ optional($contact->optInRecordedBy)->name ?? ('user #' . $contact->messaging_opt_in_recorded_by_user_id) }}
                @endif.
                @if($contact->messaging_opt_in_reason)
                    <br>Reason: <em>{{ $contact->messaging_opt_in_reason }}</em>
                @endif
            </div>
        </div>
    @endif

    {{-- Header + actions --}}
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div>
            <h3 class="text-base font-semibold" style="color: var(--text-primary);">
                Outreach timeline
            </h3>
            <p class="text-xs" style="color: var(--text-muted);">
                {{ $sends->count() }} send{{ $sends->count() === 1 ? '' : 's' }} recorded
                {{ $sends->count() === 50 ? '(showing latest 50)' : '' }}
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @if(!$optedOut)
                <a href="{{ route('seller-outreach.composer.show', $contact) }}"
                   class="px-3 py-1.5 text-sm font-semibold rounded"
                   style="background: #00d4aa; color: #003a2f;">
                    + Compose pitch
                </a>
                <button type="button" @click="optOutFormOpen = !optOutFormOpen"
                        class="px-3 py-1.5 text-sm rounded"
                        style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson); border: 1px solid var(--ds-crimson);">
                    Record opt-out
                </button>
            @endif
            @if(!$optedIn)
                {{-- AT-45 — available even when opted out (the re-consent / "YES reply" path) --}}
                <button type="button" @click="optInFormOpen = !optInFormOpen"
                        class="px-3 py-1.5 text-sm rounded"
                        style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border: 1px solid var(--ds-green);">
                    Record opt-in (YES reply)
                </button>
            @endif
        </div>
    </div>

    {{-- Opt-out form --}}
    <div x-show="optOutFormOpen" x-cloak class="rounded-md p-4"
         style="background: var(--surface); border: 1px solid var(--ds-crimson);">
        <form method="POST" action="{{ route('seller-outreach.composer.opt-out', $contact) }}">
            @csrf
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                Reason for opt-out
            </label>
            <input type="text" name="reason" required maxlength="500"
                   placeholder="e.g. Seller replied STOP via WhatsApp"
                   class="w-full px-3 py-2 text-sm rounded mb-3"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <div class="flex items-center gap-2">
                <button type="submit"
                        onclick="return confirm('Record opt-out? This will block all future pitches to this contact.');"
                        class="px-4 py-2 text-sm font-semibold rounded"
                        style="background: var(--ds-crimson); color: #fff;">
                    Confirm opt-out
                </button>
                <button type="button" @click="optOutFormOpen = false"
                        class="px-4 py-2 text-sm rounded"
                        style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    {{-- Opt-in form (AT-45) --}}
    <div x-show="optInFormOpen" x-cloak class="rounded-md p-4"
         style="background: var(--surface); border: 1px solid var(--ds-green);">
        <form method="POST" action="{{ route('seller-outreach.composer.opt-in', $contact) }}">
            @csrf
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                How the opt-in was given
            </label>
            <input type="text" name="reason" required maxlength="500"
                   placeholder="e.g. Seller replied YES via WhatsApp"
                   class="w-full px-3 py-2 text-sm rounded mb-3"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <div class="flex items-center gap-2">
                <button type="submit"
                        onclick="return confirm('Record opt-in? This logs that the seller has confirmed consent. It does not lift any existing opt-out.');"
                        class="px-4 py-2 text-sm font-semibold rounded"
                        style="background: var(--ds-green); color: #fff;">
                    Confirm opt-in
                </button>
                <button type="button" @click="optInFormOpen = false"
                        class="px-4 py-2 text-sm rounded"
                        style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    {{-- Timeline --}}
    @if($sends->isEmpty())
        <div class="rounded-md px-6 py-8 text-center text-sm"
             style="background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted);">
            No outreach yet. Click <strong>+ Compose pitch</strong> to send the first one.
        </div>
    @else
        <div class="space-y-3">
            @foreach($sends as $send)
                @include('seller-outreach.contact-timeline._row', [
                    'send'           => $send,
                    'clickInfo'      => $clickCounts->get($send->id),
                    'outcomeOptions' => $outcomeOptions,
                    'optedOut'       => $optedOut,
                ])
            @endforeach
        </div>
    @endif
</div>
