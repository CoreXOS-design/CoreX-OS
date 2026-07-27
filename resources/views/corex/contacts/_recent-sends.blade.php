{{-- Recent Sends — Contact-details Phase 4: could-not-send / reselect+resend / revert.
     No per-send action list existed before this (the Communications tab is
     thread-level; Outreach is a separate table). Each row here is one
     `communications` send with its own status + audit chain.

     Kept as its OWN partial (not inlined into show.blade.php) — that host view
     is already huge, and stacking this much directive nesting into it tripped
     Blade's compiler (some if/foreach pairs were left silently uncompiled — a
     real, reproduced failure, not a hypothetical one). A separate include
     compiles independently and keeps the host file's own compile budget
     untouched. --}}
@if($recentSends->isNotEmpty())
<div x-data="{ openHistory: null, openResend: null }" class="rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);">
    <div class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Recent Sends</div>
    <div class="space-y-2">
        @foreach($recentSends as $send)
        @php
            $sendHistory = $sendAuditLog->filter(function ($row) use ($send) {
                $ctx = $row->context;
                return ($ctx['communication_id'] ?? null) == $send->id
                    || ($ctx['original_communication_id'] ?? null) == $send->id
                    || ($ctx['new_communication_id'] ?? null) == $send->id;
            })->values();
            $recipient = collect($send->participant_identifiers ?? [])->first() ?: $send->from_identifier;
        @endphp
        <div class="rounded-md px-3 py-2.5" style="background:var(--surface); border:1px solid var(--border);">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-xs font-semibold uppercase" style="color:var(--text-muted);">{{ $send->channel }}</span>
                    <span style="color:var(--text-primary);">{{ $recipient ?: '—' }}</span>
                    <span class="text-xs" style="color:var(--text-muted);">{{ optional($send->occurred_at)->format('d M Y H:i') }}</span>
                    @if($send->send_status === \App\Models\Communications\Communication::SEND_STATUS_NOT_DELIVERED)
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:rgba(220,38,38,0.12); color:#dc2626;">Not Delivered</span>
                    @else
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md" style="background:rgba(37,211,102,0.12); color:#16a34a;">Sent</span>
                    @endif
                    @if($send->resent_from_communication_id)
                        <span class="text-[10px]" style="color:var(--text-muted);">(resend of #{{ $send->resent_from_communication_id }})</span>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if($send->send_status === \App\Models\Communications\Communication::SEND_STATUS_SENT)
                    <form method="POST" action="{{ route('corex.contacts.communications.not-delivered', [$contact, $send]) }}">
                        @csrf
                        <button type="submit" class="text-[10px] font-semibold px-2.5 py-1 rounded-md" style="color:#dc2626; border:1px solid rgba(220,38,38,0.3);">
                            Mark could not send
                        </button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('corex.contacts.communications.revert', [$contact, $send]) }}">
                        @csrf
                        <button type="submit" class="text-[10px] font-semibold px-2.5 py-1 rounded-md" style="color:var(--text-muted); border:1px solid var(--border);">
                            Revert
                        </button>
                    </form>
                    <button type="button" @click="openResend = (openResend === {{ $send->id }} ? null : {{ $send->id }})"
                            class="text-[10px] font-semibold px-2.5 py-1 rounded-md" style="color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);">
                        Reselect &amp; Resend
                    </button>
                    @endif
                    @if($sendHistory->isNotEmpty())
                    <button type="button" @click="openHistory = (openHistory === {{ $send->id }} ? null : {{ $send->id }})"
                            class="text-[10px] underline" style="color:var(--text-muted);">
                        History ({{ $sendHistory->count() }})
                    </button>
                    @endif
                </div>
            </div>

            @if($send->send_status === \App\Models\Communications\Communication::SEND_STATUS_NOT_DELIVERED)
            <div x-show="openResend === {{ $send->id }}" x-cloak class="mt-2 pt-2" style="border-top:1px solid var(--border);">
                <form method="POST" action="{{ route('corex.contacts.communications.resend', [$contact, $send]) }}" class="flex items-center gap-2 flex-wrap">
                    @csrf
                    @if($send->channel === \App\Models\Communications\Communication::CHANNEL_WHATSAPP)
                        <select name="contact_phone_id" required class="rounded-md px-2 py-1 text-xs" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="">Select number…</option>
                            @foreach($contact->phones as $p)
                            <option value="{{ $p->id }}">{{ $p->phone }}@if($p->label) ({{ $p->label }})@endif</option>
                            @endforeach
                        </select>
                    @else
                        <select name="contact_email_id" required class="rounded-md px-2 py-1 text-xs" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="">Select email…</option>
                            @foreach($contact->emails as $e)
                            <option value="{{ $e->id }}">{{ $e->email }}@if($e->label) ({{ $e->label }})@endif</option>
                            @endforeach
                        </select>
                    @endif
                    <button type="submit" class="text-[10px] font-semibold px-2.5 py-1 rounded-md text-white" style="background:var(--brand-icon, #0ea5e9);">
                        Resend
                    </button>
                </form>
            </div>
            @endif

            @if($sendHistory->isNotEmpty())
            <div x-show="openHistory === {{ $send->id }}" x-cloak class="mt-2 pt-2 space-y-1" style="border-top:1px solid var(--border);">
                @foreach($sendHistory as $event)
                @php
                    $label = match($event->event_name) {
                        \App\Events\Communication\CommunicationMarkedNotDelivered::class => 'Marked could not send',
                        \App\Events\Communication\CommunicationSendStatusReverted::class => 'Reverted to sent',
                        \App\Events\Communication\CommunicationResent::class => 'Resent',
                        default => $event->event_name,
                    };
                    $actorName = $sendAuditActors[$event->actor_user_id] ?? 'System';
                @endphp
                <div class="text-[11px]" style="color:var(--text-muted);">
                    {{ $label }} — {{ $actorName }} — {{ \Illuminate\Support\Carbon::parse($event->occurred_at)->format('d M Y H:i') }}
                    @if(!empty($event->context['reason']))
                        ({{ $event->context['reason'] }})
                    @endif
                </div>
                @endforeach
            </div>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif
