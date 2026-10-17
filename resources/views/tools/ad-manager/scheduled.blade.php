{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Scheduled Posts (Ad Manager, fourth tab) — AT-456 Part D, spec §D.4 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $f = $filters;
    $filtersActive = $f['q'] !== '' || $f['status'] !== '' || $f['platform'] !== '' || $f['agent'] > 0 || $f['from'] || $f['to'];
    $input = 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);';
    $badge = ['scheduled' => 'ds-badge-info', 'publishing' => 'ds-badge-warning', 'published' => 'ds-badge-success', 'failed' => 'ds-badge-danger', 'cancelled' => 'ds-badge-muted'];
    $statusLabel = ['scheduled' => 'Scheduled', 'publishing' => 'Publishing now', 'published' => 'Published', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
    // Sort header link: same filters, flip direction when it is the active column.
    $sortUrl = function (string $col) use ($f) {
        $dir = ($f['sort'] === $col) ? ($f['dir'] === 'asc' ? 'desc' : 'asc') : ($col === 'created' ? 'desc' : 'asc');
        return route('tools.ad-manager.scheduled', array_filter(array_merge($f, ['sort' => $col, 'dir' => $dir, 'agent' => $f['agent'] ?: null]), fn ($v) => $v !== '' && $v !== null));
    };
    $arrow = fn (string $col) => $f['sort'] === $col ? ($f['dir'] === 'asc' ? ' ↑' : ' ↓') : '';
    $disconnected = collect($platforms)->filter(fn ($label, $key) => empty($connections[$key]));
@endphp

<div class="w-full space-y-5" x-data="{
        modal: null, id: null, copy: '', date: '', time: '', platform: '',
        base: @js(url('/tools/ad-manager/scheduled')),
        openEdit(r) { this.modal = 'edit'; this.id = r.id; this.copy = r.copy; this.date = r.date; this.time = r.time; this.platform = r.platform; },
        openResched(r) { this.modal = 'resched'; this.id = r.id; this.date = r.date; this.time = r.time; this.platform = r.platform; },
        close() { this.modal = null; },
    }" @keydown.escape.window="close()">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Scheduled Posts</h1>
                <p class="text-sm text-white/60">Facebook and Instagram posts CoreX will publish for you at the time you chose. Times are shown in {{ $tzLabel }}.</p>
            </div>
            @include('tools.ad-manager._switcher', ['inHeader' => true])
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 30%, transparent); color:var(--text-primary);">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 30%, transparent); color:var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    @if($disconnected->isNotEmpty())
        <div class="rounded-md px-4 py-3 text-sm flex flex-wrap items-center gap-3" style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber,#f59e0b) 30%, transparent); color:var(--text-primary);">
            <span>
                <strong>{{ $disconnected->implode(' and ') }} {{ $disconnected->count() === 1 ? 'is' : 'are' }} not connected for you yet,</strong>
                so you cannot schedule {{ $disconnected->count() === 1 ? 'a post there' : 'posts there' }}. {{ $connectHint }}
            </span>
            <a href="{{ route('agent.portal', ['pane' => 'social']) }}#tools" class="corex-btn-outline text-xs">Connect now</a>
        </div>
    @endif

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('tools.ad-manager.scheduled') }}"
          class="rounded-md p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end" style="background:var(--surface); border:1px solid var(--border);">
        <label class="lg:col-span-2 block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Search</span>
            <input type="search" name="q" value="{{ $f['q'] }}" placeholder="Address, reference, agent or post text…" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Status</span>
            <select name="status" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                <option value="">Any status</option>
                @foreach($statuses as $s)<option value="{{ $s }}" @selected($f['status'] === $s)>{{ $statusLabel[$s] }}</option>@endforeach
            </select>
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Platform</span>
            <select name="platform" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                <option value="">Any platform</option>
                @foreach($platforms as $key => $label)<option value="{{ $key }}" @selected($f['platform'] === $key)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">From</span>
            <input type="date" name="from" value="{{ $f['from'] }}" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">To</span>
            <input type="date" name="to" value="{{ $f['to'] }}" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
        </label>
        @if($agents->isNotEmpty())
            <label class="block lg:col-span-2">
                <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Agent</span>
                <select name="agent" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                    <option value="0">All agents</option>
                    @foreach($agents as $a)<option value="{{ $a->id }}" @selected($f['agent'] === (int) $a->id)>{{ $a->name }}</option>@endforeach
                </select>
            </label>
        @endif
        <input type="hidden" name="sort" value="{{ $f['sort'] }}"><input type="hidden" name="dir" value="{{ $f['dir'] }}">
        <div class="sm:col-span-2 lg:col-span-6 flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Apply</button>
            @if($filtersActive)<a href="{{ route('tools.ad-manager.scheduled') }}" class="corex-btn-outline text-sm">Clear filters</a>@endif
            <a href="{{ route('tools.ad-manager') }}" class="corex-btn-outline text-sm ml-auto" title="Make an ad, then press Schedule on it">Schedule a post</a>
            <span class="text-xs" style="color:var(--text-muted);">{{ $rows->total() }} post{{ $rows->total() === 1 ? '' : 's' }}</span>
        </div>
    </form>

    @if($rows->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            @if($noneAtAll)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">Nothing scheduled yet</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Make an ad in the Ad Manager, then press <strong>Schedule</strong> on it and pick a day and time. CoreX posts it for you.</p>
                <a href="{{ route('tools.ad-manager') }}" class="corex-btn-primary text-sm">Schedule a post</a>
            @else
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No posts match these filters</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Try a different search, or clear the filters to see everything.</p>
                <a href="{{ route('tools.ad-manager.scheduled') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-secondary); border-bottom:1px solid var(--border);">
                        <th class="px-4 py-3">Picture</th>
                        <th class="px-4 py-3">Property</th>
                        <th class="px-4 py-3">Platform</th>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('agent') }}">Agent{{ $arrow('agent') }}</a></th>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('scheduled') }}">Scheduled for{{ $arrow('scheduled') }}</a></th>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('status') }}">Status{{ $arrow('status') }}</a></th>
                        <th class="px-4 py-3">Post text</th>
                        <th class="px-4 py-3 text-right"><a href="{{ $sortUrl('created') }}">Actions{{ $arrow('created') }}</a></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($rows as $r)
                    @php
                        $local = $r->scheduled_for?->setTimezone($tz);
                        $thumb = collect($r->image_urls)->first();
                        $payload = ['id' => $r->id, 'copy' => $r->ad_copy, 'date' => $local?->format('Y-m-d'), 'time' => $local?->format('H:i'), 'platform' => ucfirst($r->platform)];
                    @endphp
                    <tr style="border-bottom:1px solid var(--border);" class="align-top">
                        <td class="px-4 py-3">
                            @if($thumb)<img src="{{ $thumb }}" alt="" class="w-14 h-14 object-cover rounded" loading="lazy" onerror="this.style.display='none'">@endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold" style="color:var(--text-primary);">{{ $r->property?->title ?? 'Listing removed' }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ trim(($r->property?->suburb ?? '') . ($r->property?->city ? ', ' . $r->property->city : ''), ', ') }}</div>
                        </td>
                        <td class="px-4 py-3">{{ ucfirst($r->platform) }}</td>
                        <td class="px-4 py-3">{{ $r->user?->name ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $local?->format('D j M Y, H:i') }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $badge[$r->status] ?? 'ds-badge-muted' }}">{{ $statusLabel[$r->status] ?? $r->status }}</span>
                            @if($r->status === 'failed' && $r->failure_reason)
                                <div class="text-xs mt-1 max-w-xs" style="color:var(--text-secondary);">{{ $r->failure_reason }}</div>
                            @endif
                            @if($r->status === 'published' && $r->published_at)
                                <div class="text-xs mt-1" style="color:var(--text-muted);">Posted {{ $r->published_at->setTimezone($tz)->format('j M, H:i') }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 max-w-xs" style="color:var(--text-secondary);">{{ \Illuminate\Support\Str::limit($r->ad_copy, 110) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($r->status === 'scheduled')
                                <button type="button" class="corex-btn-outline text-xs" @click="openEdit(@js($payload))">Edit</button>
                                <form method="POST" action="{{ route('tools.ad-manager.scheduled.cancel', $r->id) }}" class="inline" onsubmit="return confirm('Cancel this post? It will not be published.');">@csrf<button type="submit" class="corex-btn-outline text-xs">Cancel</button></form>
                            @elseif($r->status === 'cancelled')
                                <button type="button" class="corex-btn-outline text-xs" @click="openResched(@js($payload))">Reschedule</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    @endif

    {{-- One modal for Edit and Reschedule --}}
    <div x-show="modal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,.55);" @click.self="close()">
        <form method="POST" :action="base + '/' + id + (modal === 'edit' ? '/update' : '/reschedule')"
              class="w-full max-w-lg rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
            @csrf
            <h2 class="text-base font-semibold" style="color:var(--text-primary);" x-text="(modal === 'edit' ? 'Edit ' : 'Reschedule ') + platform + ' post'"></h2>
            <template x-if="modal === 'edit'">
                <label class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Post text</span>
                    <textarea name="copy" x-model="copy" rows="7" maxlength="5000" required class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}"></textarea>
                </label>
            </template>
            <div class="grid grid-cols-2 gap-3">
                <label class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Date</span>
                    <input type="date" name="date" x-model="date" required class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                </label>
                <label class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Time</span>
                    <input type="time" name="time" x-model="time" required class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                </label>
            </div>
            <p class="text-xs" style="color:var(--text-muted);">Times are {{ $tzLabel }}. At least {{ $minLead }} minutes from now and no more than {{ $maxDays }} days ahead.</p>
            <div class="flex justify-end gap-2">
                <button type="button" class="corex-btn-outline text-sm" @click="close()">Close</button>
                <button type="submit" class="corex-btn-primary text-sm" x-text="modal === 'edit' ? 'Save changes' : 'Put back on the schedule'"></button>
            </div>
        </form>
    </div>
</div>
@endsection
