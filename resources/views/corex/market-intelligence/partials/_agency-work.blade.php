{{-- Lane B (property-identity.md section 12): this agency's OWN record of the property - status, own stock, claim, history. Only this agency ever sees it. --}}
@php
    $statuses = \App\Models\Prospecting\TrackedPropertyAgencyWork::STATUSES;
    $workInput = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
@endphp
<div data-qa="agency-work" style="margin-bottom: 14px; padding: 12px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
    <div class="flex flex-wrap items-baseline justify-between gap-2" style="margin-bottom: 8px;">
        <div class="text-sm font-bold" style="color: var(--text-primary);">Your agency's notes on this property</div>
        <div class="text-xs" style="color: var(--text-muted);">Only people in your agency see this - never another agency.</div>
    </div>

    <div class="flex flex-wrap gap-x-8 gap-y-2 text-xs" style="color: var(--text-secondary);">
        <div data-qa="work-status">
            <span style="color: var(--text-muted);">Where it stands:</span>
            @if($work && $work->prospecting_status)
                <strong style="color: var(--text-primary);">{{ $work->statusLabel() }}</strong>
                @if($work->prospecting_reason) - {{ $work->prospecting_reason }}@endif
                <span style="color: var(--text-muted);">({{ $work->statusSetBy?->name ?? 'someone' }}, {{ $work->status_set_at?->format('j M Y') }})</span>
            @else
                Nothing recorded yet
            @endif
        </div>
        <div data-qa="work-own-stock">
            <span style="color: var(--text-muted);">Our stock:</span>
            <strong style="color: var(--text-primary);">{{ $work && $work->is_own_stock ? 'Yes - we hold this property' : 'No' }}</strong>
        </div>
        <div data-qa="work-claim">
            <span style="color: var(--text-muted);">Claimed by:</span>
            @if($work && $work->claimed_by_user_id)
                <strong style="color: var(--text-primary);">{{ $work->claimedBy?->name ?? 'an agent' }}</strong> since {{ $work->claimed_at?->format('j M Y') }}
            @else
                Nobody
            @endif
        </div>
    </div>

    @if($canSetStatus)
        <form method="POST" action="{{ route('corex.tracked-properties.prospecting-status', $tp->id) }}" class="flex flex-wrap items-end gap-2" style="margin-top: 10px;" data-qa="work-status-form">
            @csrf
            <div>
                <label class="block text-xs font-semibold" style="margin-bottom: 2px; color: var(--text-muted);" for="work-status-select">Change where it stands</label>
                <select id="work-status-select" name="prospecting_status" class="rounded-md px-2 py-1.5 text-xs" style="{{ $workInput }}">
                    <option value="">Nothing recorded</option>
                    @foreach($statuses as $key => $label)<option value="{{ $key }}" @selected($work?->prospecting_status === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div style="flex: 1 1 16rem;">
                <label class="block text-xs font-semibold" style="margin-bottom: 2px; color: var(--text-muted);" for="work-status-reason">Why (optional)</label>
                <input id="work-status-reason" type="text" name="prospecting_reason" maxlength="500" value="{{ old('prospecting_reason', $work?->prospecting_reason) }}" class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $workInput }}" placeholder="e.g. Owner says not for two years">
            </div>
            <button type="submit" class="corex-btn-primary corex-btn-xs">Save</button>
        </form>
    @endif

    @if($workHistory->isNotEmpty())
        <details style="margin-top: 10px;" data-qa="work-history">
            <summary class="text-xs font-semibold" style="cursor: pointer; color: var(--text-secondary);">History ({{ $workHistory->count() }})</summary>
            <ul class="text-xs" style="margin-top: 6px; color: var(--text-secondary);">
                @foreach($workHistory as $e)
                    <li style="padding: 2px 0; border-top: 1px solid var(--border);">
                        {{ $e->occurred_at->format('j M Y H:i') }} -
                        @if($e->kind === 'status')
                            {{ $e->to_value ? 'Status set to ' . ($statuses[$e->to_value] ?? $e->to_value) : 'Status cleared' }}@if($e->reason): {{ $e->reason }}@endif
                        @elseif($e->kind === 'own_stock')
                            {{ $e->to_value ? 'Marked as our stock' : 'No longer our stock' }}
                        @else
                            {{ $e->to_value ? 'Claimed' : 'Claim released' }}
                        @endif
                        @if($e->user)<span style="color: var(--text-muted);">({{ $e->user->name }})</span>@endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
