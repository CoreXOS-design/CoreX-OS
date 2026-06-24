{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    use App\Models\PresentationRefreshRequest;

    // Status → [label, .ds-badge variant]. Amber=needs attention, navy=seen,
    // green=resolved, crimson=declined (genuine negative outcome), grey=cancelled.
    $statusBadge = function (string $s): array {
        return match ($s) {
            PresentationRefreshRequest::STATUS_PENDING      => ['Pending',      'ds-badge-warning'],
            PresentationRefreshRequest::STATUS_ACKNOWLEDGED => ['Acknowledged', 'ds-badge-info'],
            PresentationRefreshRequest::STATUS_RESOLVED     => ['Resolved',     'ds-badge-success'],
            PresentationRefreshRequest::STATUS_DECLINED     => ['Declined',     'ds-badge-danger'],
            default                                         => ['Cancelled',    'ds-badge-default'],
        };
    };
    $tabs = [
        'open'         => 'Open ('         . number_format($counts['open']         ?? 0) . ')',
        'pending'      => 'Pending ('      . number_format($counts['pending']      ?? 0) . ')',
        'acknowledged' => 'Acknowledged (' . number_format($counts['acknowledged'] ?? 0) . ')',
        'resolved'     => 'Resolved ('     . number_format($counts['resolved']     ?? 0) . ')',
        'declined'     => 'Declined ('     . number_format($counts['declined']     ?? 0) . ')',
        'all'          => 'All',
    ];
@endphp

<div class="w-full space-y-5" x-data="{ panel: null }">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="pres-refresh-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Refresh Requests</h1>
                <p class="text-sm text-white/60">
                    Sellers' asks to refresh share-link data. Acknowledge to mark seen; issue a refreshed link to resolve.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if(\Illuminate\Support\Facades\Route::has('presentations.index'))
                <a href="{{ route('presentations.index') }}" class="corex-btn-outline corex-btn-on-brand text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                    Presentations
                </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Flash / validation alerts (§3.9) --}}
    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif
    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Status tabs --}}
    <div class="flex gap-1 overflow-x-auto" style="border-bottom: 1px solid var(--border);" data-tour="pres-refresh-tabs">
        @foreach($tabs as $key => $label)
            @php $active = $status === $key; @endphp
            <a href="{{ route('corex.presentations.refresh-requests.index', ['status' => $key]) }}"
               class="text-[13px] whitespace-nowrap no-underline transition-colors"
               style="padding: 8px 14px;
                      color: {{ $active ? 'var(--text-primary)' : 'var(--text-muted)' }};
                      border-bottom: 2px solid {{ $active ? 'var(--brand-button, #0ea5e9)' : 'transparent' }};
                      font-weight: {{ $active ? '600' : '500' }};">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if($requests->isEmpty())
        {{-- Empty state (§3.10) --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No refresh requests in this view</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">When a seller asks for refreshed share-link data, it lands here for you to action.</p>
            @if(\Illuminate\Support\Facades\Route::has('presentations.index'))
            <a href="{{ route('presentations.index') }}" class="corex-btn-primary text-sm">Go to Presentations</a>
            @endif
        </div>
    @else
        {{-- Requests table (§3.7) --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="pres-refresh-table">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Requester</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Message</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">When</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);" data-tour="pres-refresh-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($requests as $r)
                        @php [$bLabel, $bVariant] = $statusBadge($r->status); @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3" style="color: var(--text-primary);">
                                <a href="{{ route('presentations.show', $r->presentation_id) }}"
                                   class="font-medium no-underline" style="color: var(--text-primary);">
                                    {{ $r->presentation?->property_address ?: 'Presentation #' . $r->presentation_id }}
                                </a>
                                @if($r->presentation?->suburb)
                                    <div class="text-[0.6875rem]" style="color: var(--text-muted);">{{ $r->presentation->suburb }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-primary);">
                                <div class="font-medium">{{ $r->requester_name }}</div>
                                @if($r->requester_email)
                                    <div class="text-[0.6875rem]" style="color: var(--text-muted);">{{ $r->requester_email }}</div>
                                @endif
                                @if($r->requester_phone)
                                    <div class="text-[0.6875rem]" style="color: var(--text-muted);">{{ $r->requester_phone }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary); max-width: 300px;">
                                @if($r->message)
                                    {{ \Illuminate\Support\Str::limit($r->message, 140) }}
                                @else
                                    <span class="italic" style="color: var(--text-muted);">No message</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $bVariant }}">{{ $bLabel }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                {{ $r->created_at?->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($r->isOpen())
                                    <div class="inline-flex items-center gap-2">
                                        @if($r->status === PresentationRefreshRequest::STATUS_PENDING)
                                            <form method="POST" action="{{ route('corex.presentations.refresh-requests.acknowledge', $r) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Acknowledge</button>
                                            </form>
                                        @endif
                                        <button type="button" class="corex-btn-primary text-xs"
                                                @click="panel = panel === '{{ $r->id }}-resolve' ? null : '{{ $r->id }}-resolve'">
                                            Issue refresh
                                        </button>
                                        <button type="button" class="corex-btn-outline text-xs"
                                                style="color: var(--ds-crimson, #c41e3a); border-color: color-mix(in srgb, var(--ds-crimson, #c41e3a) 40%, transparent);"
                                                @click="panel = panel === '{{ $r->id }}-decline' ? null : '{{ $r->id }}-decline'">
                                            Decline
                                        </button>
                                    </div>
                                @elseif($r->isResolved() && $r->resultingLink)
                                    <a href="{{ route('presentation.public.show', $r->resultingLink->token) }}" target="_blank"
                                       class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">
                                        View new link ↗
                                    </a>
                                @endif
                            </td>
                        </tr>

                        {{-- Inline Resolve panel --}}
                        <tr x-show="panel === '{{ $r->id }}-resolve'" x-cloak>
                            <td colspan="6" class="px-5 py-4" style="background: var(--surface-2); border-top: 1px solid var(--border);">
                                <form method="POST" action="{{ route('corex.presentations.refresh-requests.resolve', $r) }}">
                                    @csrf
                                    <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Issue a refreshed share link</h3>
                                    <p class="text-xs mb-3" style="color: var(--text-muted);">
                                        A new /p/{token} link will be created using the same recipient + mode. The original link will be marked superseded so visitors are redirected to the new one.
                                    </p>
                                    <label class="flex items-center gap-2 text-[13px] mb-2" style="color: var(--text-secondary);">
                                        <input type="checkbox" name="keep_old_link_active" value="1">
                                        Keep the old link active (don't supersede)
                                    </label>
                                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Resolution note (optional)</label>
                                    <textarea name="resolution_note" rows="2" maxlength="2000"
                                              placeholder="e.g. Refreshed comps for Q2 — fixed asking range up 5%."
                                              class="w-full rounded-md px-3 py-2 text-[13px]"
                                              style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                                    <div class="mt-3 flex gap-2">
                                        <button type="submit" class="corex-btn-primary text-[13px]">Issue refresh &amp; resolve</button>
                                        <button type="button" class="corex-btn-outline text-[13px]" @click="panel = null">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>

                        {{-- Inline Decline panel --}}
                        <tr x-show="panel === '{{ $r->id }}-decline'" x-cloak>
                            <td colspan="6" class="px-5 py-4"
                                style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 6%, transparent); border-top: 1px solid var(--border);">
                                <form method="POST" action="{{ route('corex.presentations.refresh-requests.decline', $r) }}">
                                    @csrf
                                    <h3 class="text-sm font-semibold mb-2" style="color: var(--ds-crimson, #c41e3a);">Decline this request</h3>
                                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Reason (shown to the requester) <span style="color: var(--ds-crimson, #c41e3a);">*</span></label>
                                    <textarea name="decline_reason" rows="2" maxlength="2000" required minlength="5"
                                              placeholder="e.g. The current report is still accurate — comps haven't moved enough to warrant a refresh."
                                              class="w-full rounded-md px-3 py-2 text-[13px]"
                                              style="background: var(--surface); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent); color: var(--text-primary);"></textarea>
                                    <label class="flex items-center gap-2 text-[13px] mt-2" style="color: var(--text-secondary);">
                                        <input type="checkbox" name="notify_requester" value="1" checked>
                                        Email the requester with this reason
                                    </label>
                                    <div class="mt-3 flex gap-2">
                                        <button type="submit" class="corex-btn-primary text-[13px]" style="background: var(--ds-crimson, #c41e3a); box-shadow: none;">Decline request</button>
                                        <button type="button" class="corex-btn-outline text-[13px]" @click="panel = null">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $requests->links() }}</div>
        </div>
    @endif

</div>
@endsection
