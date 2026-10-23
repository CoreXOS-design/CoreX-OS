@extends('layouts.corex-app')

@section('corex-content')
{{-- AT-454 step 8 (agency-tracker-rehome.md 10.2): TV Display settings - TV codes and TV messages in one place, scoped to the
     viewer's breadth in the controller (whole agency: company code, every branch, every message; branch manager: own branch). --}}
@php
    $field = 'width:100%; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $tvPh = ['{{branch_name}}','{{period}}','{{deals_target}}','{{deals_actual}}','{{deals_remaining}}','{{value_target}}','{{value_actual}}','{{value_remaining}}','{{points_target}}','{{points_actual}}','{{points_status}}','{{listings_active}}','{{listings_avg_dom}}','{{listings_stale}}','{{listings_expiring}}','{{listings_expired}}'];
    $areaLabel = ['both' => 'Hero + Ticker', 'hero' => 'Hero only', 'ticker' => 'Ticker only'];
@endphp
<div class="w-full space-y-6" id="tv-display-settings">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">TV Display</h1>
        <p class="text-xs" style="color: var(--text-muted);">
            The codes your office TVs sign in with, and the messages they show.
            On the TV, open <span class="font-mono" style="color: var(--text-secondary);">{{ url('/tv') }}</span> and enter a code.
        </p>
    </div>

    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);"><strong>Saved.</strong> {{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);"><strong>Couldn’t save.</strong> {{ $errors->first() }}</div>
    @endif

    {{-- ───────── TV codes ───────── --}}
    <div class="space-y-3">
        <h2 class="ds-section-header">TV codes</h2>

        @if($wide)
        <div class="ds-status-card p-5" id="tv-company-code" style="border-left-color: var(--border);">
            <div class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Company TV display <span class="text-xs font-normal" style="color: var(--text-muted);">(all branches together)</span></div>
            @if($companyCode)
                <div class="flex items-center gap-4 mb-3">
                    <span class="font-mono text-2xl font-black tracking-[0.3em] select-all" style="color: var(--text-primary);">{{ $companyCode->code }}</span>
                    <div class="text-xs leading-snug" style="color: var(--text-secondary);">
                        <div>Generated {{ $companyCode->created_at->diffForHumans() }} by {{ $companyCode->creator->name ?? '—' }}</div>
                        <div>{{ $companyCode->last_used_at ? 'Last used ' . $companyCode->last_used_at->diffForHumans() : 'Never used' }}</div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('corex.settings.tv-display.codes.generate') }}">@csrf<input type="hidden" name="company" value="1"><button type="submit" class="corex-btn-primary">Regenerate code</button></form>
                    <form method="POST" action="{{ route('corex.settings.tv-display.codes.revoke') }}" onsubmit="return confirm('Revoke the company TV code? TVs using it will stop working.')">@csrf<input type="hidden" name="company" value="1"><button type="submit" class="corex-btn-outline">Revoke</button></form>
                </div>
            @else
                <div class="text-sm mb-3" style="color: var(--text-muted);">No active company TV code.</div>
                <form method="POST" action="{{ route('corex.settings.tv-display.codes.generate') }}">@csrf<input type="hidden" name="company" value="1"><button type="submit" class="corex-btn-primary">Generate company TV code</button></form>
            @endif
        </div>
        @endif

        <div class="ds-status-card p-5" id="tv-branch-codes" style="border-left-color: var(--border);">
            <div class="font-semibold text-sm mb-3" style="color: var(--text-primary);">{{ $wide ? 'Branch TV codes' : 'Your branch TV code' }}</div>
            @if($branchCodes->count())
                <div class="overflow-x-auto">
                    <table class="ds-table w-full text-sm">
                        <thead><tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Code</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Generated by</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Last used</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr></thead>
                        <tbody>
                        @foreach($branchCodes as $tc)
                            <tr>
                                <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $tc->branch->display_name ?? $tc->branch->name ?? 'Unknown' }}</td>
                                <td class="px-4 py-3"><span class="font-mono text-lg font-black tracking-widest select-all" style="color: var(--text-primary);">{{ $tc->code }}</span></td>
                                <td class="px-4 py-3 ds-label">{{ $tc->creator->name ?? '—' }} · {{ $tc->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-3 ds-label">{{ $tc->last_used_at ? $tc->last_used_at->diffForHumans() : 'Never' }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <form method="POST" action="{{ route('corex.settings.tv-display.codes.generate') }}" class="inline">@csrf<input type="hidden" name="branch_id" value="{{ $tc->branch_id }}"><button type="submit" class="corex-btn-outline text-xs">New code</button></form>
                                    <form method="POST" action="{{ route('corex.settings.tv-display.codes.revoke') }}" class="inline" onsubmit="return confirm('Revoke this code? TVs using it will stop working.')">@csrf<input type="hidden" name="code_id" value="{{ $tc->id }}"><button type="submit" class="corex-btn-outline text-xs">Revoke</button></form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-sm" style="color: var(--text-muted);">{{ $wide ? 'No active branch TV codes yet.' : 'No active TV code for your branch yet.' }}</div>
            @endif

            <form method="POST" action="{{ route('corex.settings.tv-display.codes.generate') }}" class="mt-4 flex flex-wrap items-center gap-2">
                @csrf
                @if($wide)
                    <select name="branch_id" required class="list-header-filter" aria-label="Branch">
                        <option value="">Select branch…</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                    </select>
                @endif
                <button type="submit" class="corex-btn-primary">{{ $wide ? 'Generate branch code' : 'Generate my branch code' }}</button>
            </form>
        </div>
    </div>

    {{-- ───────── TV messages ───────── --}}
    <div class="space-y-3" id="tv-messages">
        <h2 class="ds-section-header">TV messages</h2>

        @if($status === 'active')
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Add a TV message</h3>
            <form method="POST" action="{{ route('corex.settings.tv-display.messages.store') }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                    <div class="md:col-span-3">
                        <label for="add_branch_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Shows on</label>
                        @if($wide)
                            <select id="add_branch_id" name="branch_id" class="rounded-md text-sm px-3 py-2" style="{{ $field }}">
                                <option value="">All branches</option>
                                @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                            </select>
                        @else
                            <div class="text-sm px-3 py-2" style="color: var(--text-primary);">{{ $branches->first()->name ?? 'Your branch' }}</div>
                        @endif
                    </div>
                    <div class="md:col-span-9">
                        <label for="add_message" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</label>
                        <input id="add_message" name="message" required class="rounded-md text-sm px-3 py-2" style="{{ $field }}" placeholder="Motivational message, announcement, etc.">
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($tvPh as $ph)<button type="button" class="tv-ph-pill" data-ph="{{ $ph }}" onclick="window.__tvInsertPh(this)">{{ $ph }}</button>@endforeach
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                    <div class="md:col-span-3">
                        <label for="add_display_area" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Show on screen as</label>
                        <select id="add_display_area" name="display_area" class="rounded-md text-sm px-3 py-2" style="{{ $field }}">
                            @foreach($areaLabel as $k => $l)<option value="{{ $k }}" @selected($k === 'both')>{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2"><label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">From (optional)</label><input type="date" name="starts_at" class="rounded-md text-sm px-3 py-2" style="{{ $field }}"></div>
                    <div class="md:col-span-2"><label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Until (optional)</label><input type="date" name="ends_at" class="rounded-md text-sm px-3 py-2" style="{{ $field }}"></div>
                    <div class="md:col-span-2 flex items-center gap-2"><input type="hidden" name="is_enabled" value="0"><input type="checkbox" id="add_is_enabled" name="is_enabled" value="1" checked class="rounded"><label for="add_is_enabled" class="text-sm" style="color: var(--text-secondary);">Enabled</label></div>
                    <div class="md:col-span-3"><button type="submit" class="w-full corex-btn-primary text-sm">Add message</button></div>
                </div>
            </form>
        </div>
        @endif

        {{-- Search / filter / sort --}}
        <form method="GET" action="{{ route('corex.settings.tv-display.index') }}" class="rounded-md px-4 py-3 flex flex-wrap items-center gap-3" style="background: var(--surface); border: 1px solid var(--border);">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search title or message…" class="list-header-filter {{ $search !== '' ? 'is-active' : '' }}" style="min-width: 220px;">
            <select name="status" onchange="this.form.submit()" class="list-header-filter {{ $status === 'archived' ? 'is-active' : '' }}">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="archived" @selected($status === 'archived')>Archived</option>
            </select>
            @if($wide)
            <select name="branch" onchange="this.form.submit()" class="list-header-filter {{ $branchFilter !== '' ? 'is-active' : '' }}">
                <option value="">All branches and agency-wide</option>
                <option value="all" @selected($branchFilter === 'all')>Agency-wide only</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchFilter === (string) $b->id)>{{ $b->name }}</option>@endforeach
            </select>
            @endif
            <select name="sort" onchange="this.form.submit()" class="list-header-filter" aria-label="Sort">
                @foreach(['newest' => 'Newest first', 'oldest' => 'Oldest first', 'starts' => 'Start date', 'ends' => 'End date', 'title' => 'Title A–Z'] as $k => $l)<option value="{{ $k }}" @selected($sort === $k)>{{ $l }}</option>@endforeach
            </select>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if($search !== '' || $branchFilter !== '' || $status === 'archived' || $sort !== 'newest')<a href="{{ route('corex.settings.tv-display.index') }}" class="text-xs underline" style="color: var(--text-muted);">Clear</a>@endif
            <span class="text-xs ml-auto" style="color: var(--text-muted);">{{ number_format($messages->total()) }} {{ $status === 'archived' ? 'archived' : 'message' . ($messages->total() === 1 ? '' : 's') }}</span>
        </form>

        <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
            @forelse($messages as $m)
                @php $mine = $canEditMessage($m); @endphp
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);" data-tv-message="{{ $m->id }}">
                    @if($status === 'archived')
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm" style="color: var(--text-primary);">{{ $m->message }}</div>
                                <div class="text-xs mt-1.5 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                                    <span class="ds-badge ds-badge-warning">Archived</span>
                                    <span>{{ $m->branch?->display_name ?? $m->branch?->name ?? 'All branches' }}</span><span>&middot;</span>
                                    <span>Created by {{ $m->creator->name ?? 'System' }}</span>
                                </div>
                            </div>
                            @if($mine)
                            <form method="POST" action="{{ route('corex.settings.tv-display.messages.restore', $m->id) }}" class="shrink-0">@csrf<button type="submit" class="corex-btn-outline text-xs">Restore</button></form>
                            @endif
                        </div>
                    @elseif($mine)
                        <form method="POST" action="{{ route('corex.settings.tv-display.messages.update', $m->id) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-3">
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Shows on</label>
                                @if($wide)
                                    <select name="branch_id" class="rounded-md text-sm px-3 py-2" style="{{ $field }}">
                                        <option value="">All branches</option>
                                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected((int) $m->branch_id === (int) $b->id)>{{ $b->name }}</option>@endforeach
                                    </select>
                                @else
                                    <div class="text-sm px-3 py-2" style="color: var(--text-primary);">{{ $m->branch?->display_name ?? $m->branch?->name }}</div>
                                @endif
                            </div>
                            <div class="md:col-span-9">
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</label>
                                <input name="message" required value="{{ $m->message }}" class="rounded-md text-sm px-3 py-2" style="{{ $field }}">
                            </div>
                            <div class="md:col-span-3">
                                <select name="display_area" class="rounded-md text-sm px-3 py-2" style="{{ $field }}" aria-label="Show on screen as">
                                    @foreach($areaLabel as $k => $l)<option value="{{ $k }}" @selected($m->display_area === $k)>{{ $l }}</option>@endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2"><input type="date" name="starts_at" value="{{ optional($m->starts_at)->format('Y-m-d') }}" class="rounded-md text-sm px-3 py-2" style="{{ $field }}" aria-label="From"></div>
                            <div class="md:col-span-2"><input type="date" name="ends_at" value="{{ optional($m->ends_at)->format('Y-m-d') }}" class="rounded-md text-sm px-3 py-2" style="{{ $field }}" aria-label="Until"></div>
                            <div class="md:col-span-2 flex items-center gap-2"><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($m->is_enabled) class="rounded"><span class="text-sm" style="color: var(--text-secondary);">Enabled</span></div>
                            <div class="md:col-span-3 flex items-center gap-2">
                                <button type="submit" class="corex-btn-primary text-xs">Save</button>
                                <button type="submit" form="archive-{{ $m->id }}" class="corex-btn-outline text-xs" onclick="return confirm('Archive this message? You can restore it later.')">Archive</button>
                            </div>
                        </form>
                        <form id="archive-{{ $m->id }}" method="POST" action="{{ route('corex.settings.tv-display.messages.archive', $m->id) }}" class="hidden">@csrf</form>
                    @else
                        {{-- an agency-wide message that plays on this branch's TV: shown, not editable by a branch manager --}}
                        <div class="text-sm" style="color: var(--text-primary);">{{ $m->message }}</div>
                        <div class="text-xs mt-1.5 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                            <span class="ds-badge ds-badge-default">Agency-wide</span><span>{{ $areaLabel[$m->display_area] ?? $m->display_area }}</span><span>&middot;</span><span>Set by the agency, so it cannot be changed here.</span>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-5 py-12 text-center">
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $status === 'archived' ? 'No archived messages.' : ($search !== '' || $branchFilter !== '' ? 'No messages match that search.' : 'No TV messages yet.') }}</div>
                    @if($status === 'active' && $search === '' && $branchFilter === '')<div class="text-xs mt-1" style="color: var(--text-muted);">Add one above and it shows on the TV straight away.</div>@endif
                </div>
            @endforelse
        </div>
        <div>{{ $messages->links() }}</div>
    </div>
</div>

<style>
    .tv-ph-pill { padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.75rem; background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); cursor: pointer; }
    .tv-ph-pill:hover { background: var(--surface); color: var(--text-primary); }
</style>
<script>
(function () {
  window.__tvInsertPh = function (btn) {
    var token = btn && btn.getAttribute('data-ph'); if (!token) return;
    var form = btn.closest('form'); var input = form ? form.querySelector('input[name="message"]') : null;
    if (!input) return;
    var s = input.selectionStart == null ? input.value.length : input.selectionStart, e = input.selectionEnd == null ? s : input.selectionEnd;
    input.value = input.value.slice(0, s) + token + input.value.slice(e);
    input.focus(); input.setSelectionRange(s + token.length, s + token.length);
  };
})();
</script>
@endsection
