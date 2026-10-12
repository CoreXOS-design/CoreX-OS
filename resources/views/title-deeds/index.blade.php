@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data="deedRowActions({ returnTo: @js(request()->getRequestUri()) })">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Title Deeds</h1>
            <div class="flex flex-wrap items-center gap-2">
                @if($total > 0)
                <a href="{{ route('title-deeds.export', request()->query()) }}" class="corex-btn-outline text-xs">Download as a spreadsheet (CSV)</a>
                @endif
                @permission('title_deeds.create')
                <a href="{{ route('title-deeds.create') }}" class="corex-btn-primary text-xs">Log a deed</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>@endif

    {{-- Tiles: a count of each KIND of title deed for the person's scope and the current filters (not narrowed by the ticks). A tile click sets the ticks
         to that tile's kinds and opens its section. Same look as the Properties list tiles. --}}
    @php
        $keep = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->all();
        $tickSet = collect($ticks)->sort()->values()->all();
    @endphp
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2" data-td-tiles>
        @foreach($tiles as $tile)
            @php
                $active = collect($tile['ticks'])->sort()->values()->all() === $tickSet;
                $tileUrl = route('title-deeds.index', array_merge($keep, ['show' => $tile['ticks'], 'focus' => $tile['focus']]));
            @endphp
            <a href="{{ $tileUrl }}" data-td-tile="{{ $tile['key'] }}" aria-label="{{ $tile['label'] }}: {{ $tile['value'] }}"
               class="pstat-v2 px-3.5 py-2 flex items-center justify-between gap-3 no-underline cursor-pointer"
               style="{{ $active ? 'border-color:color-mix(in srgb, var(--brand-icon,#6366f1) 40%, transparent);background:color-mix(in srgb, var(--brand-icon,#6366f1) 10%, var(--surface));' : '' }}">
                <div class="min-w-0">
                    <div class="text-lg font-bold leading-none tabular-nums" style="color:var(--text-primary);" data-td-tile-count>{{ number_format($tile['value']) }}</div>
                    <div class="text-[0.6875rem] font-medium mt-0.5 uppercase tracking-wider" style="color:var(--text-muted);">{{ $tile['label'] }}</div>
                </div>
                <span class="pstat-v2__tub inline-flex items-center justify-center w-7 h-7 rounded-md flex-shrink-0" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        @if($tile['key'] === 'collected')<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                        @elseif($tile['key'] === 'waiting')<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                        @elseif($tile['key'] === 'not_told')<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                        @else<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>@endif
                    </svg>
                </span>
            </a>
        @endforeach
    </div>

    @if($pendingWhatsapp->isNotEmpty())
        @include('title-deeds._prepared-whatsapp', ['prepared' => $pendingWhatsapp, 'showProperty' => true])
    @endif

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('title-deeds.index') }}" class="flex flex-wrap items-end gap-3" id="td-filter-form">
            <input type="hidden" name="focus" value="">
            <div class="flex-1 min-w-[220px]">
                <label for="td-search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <input id="td-search" type="text" name="search" value="{{ $filters['search'] }}" maxlength="100"
                       placeholder="Reference, property, owner, attorney, collector"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            @if($isAll && $branches->count() > 1)
            <div>
                <label for="td-branch" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch</label>
                <select id="td-branch" name="branch_id" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) $filters['branch_id'] === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
            @endif
            @if($agents->count() > 1)
            <div>
                <label for="td-agent" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                <select id="td-agent" name="agent_id" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All agents</option>
                    @foreach($agents as $a)<option value="{{ $a->id }}" @selected((string) $filters['agent_id'] === (string) $a->id)>{{ $a->label }}</option>@endforeach
                </select>
            </div>
            @endif
            <div>
                <label for="td-from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Received from</label>
                <input id="td-from" type="date" name="from" value="{{ $filters['from'] }}" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="td-to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">to</label>
                <input id="td-to" type="date" name="to" value="{{ $filters['to'] }}" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary">Apply the filters</button>
                @if($hasFilters)<a href="{{ route('title-deeds.index', ['show' => $ticks ?: ['']]) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear the filters</a>@endif
            </div>

            {{-- The kinds of title deed to show. Ticking one applies at once and opens its section. An empty show[] marks "nothing ticked". --}}
            <fieldset class="w-full flex flex-wrap items-center gap-x-5 gap-y-2 pt-3" style="border-top: 1px solid var(--border);" data-td-ticks>
                <legend class="sr-only">Show these title deeds</legend>
                <span class="text-xs font-medium" style="color: var(--text-secondary);">Show:</span>
                <input type="hidden" name="show[]" value="">
                @foreach(['not_told' => ['Owner not yet told', 'agency'], 'waiting' => ['Waiting for collection', 'agency'], 'collected' => ['Collected', 'collected'], 'archived' => ['Archived', 'archived']] as $tickKey => [$tickLabel, $tickFocus])
                <label class="inline-flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
                    <input type="checkbox" name="show[]" value="{{ $tickKey }}" data-focus="{{ $tickFocus }}" @checked(in_array($tickKey, $ticks, true)) onchange="tdTick(this)" style="accent-color: var(--brand-icon);">
                    {{ $tickLabel }}
                </label>
                @endforeach
            </fieldset>
        </form>
    </div>

    @if(empty($sections))
        <div class="rounded-md px-4 py-8 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);" data-td-nothing-ticked>
            Tick at least one kind of title deed to see it.
        </div>
    @endif

    @foreach($sections as $sec)
        @include('title-deeds._list-section', ['sec' => $sec])
    @endforeach

    {{-- ONE shared confirm dialog for every state-changing row action (same look as the app dialog); the form it posts carries the one-time token and returns to this exact list. --}}
    <div x-show="ask" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(15, 23, 42, .5);" x-on:click.self="ask = null" x-on:keydown.escape.window="ask = null" role="dialog" aria-modal="true">
        <form method="POST" :action="ask ? ask.url : '#'" class="rounded-md p-5 w-full max-w-md space-y-3" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" x-on:submit="sending = true">
            @csrf
            <input type="hidden" name="_method" :value="ask && ask.method ? ask.method : 'POST'">
            <input type="hidden" name="_form_token" :value="ask && ask.token ? ask.token : ''">
            <input type="hidden" name="return_to" :value="returnTo">
            <input type="hidden" name="channel" :value="ask && ask.channel ? ask.channel : ''">
            <h3 class="text-sm font-semibold" x-text="ask ? ask.title : ''"></h3>
            <p class="text-sm" x-text="ask ? ask.message : ''"></p>
            <div class="flex justify-end gap-2">
                <button type="button" class="corex-btn-outline text-xs" x-on:click="ask = null">Cancel</button>
                <button type="submit" class="corex-btn-primary text-xs" :disabled="sending" x-text="ask ? ask.label : 'Continue'"></button>
            </div>
        </form>
    </div>

    {{-- "More": every other action for one row, each as a worded button. Archive sits last and apart. --}}
    <div x-show="more" x-cloak class="fixed inset-0 z-40 flex items-center justify-center p-4" style="background: rgba(15, 23, 42, .5);" x-on:click.self="more = null" x-on:keydown.escape.window="more = null" role="dialog" aria-modal="true" aria-label="More actions">
        <div class="rounded-md p-5 w-full max-w-sm space-y-2" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            <h3 class="text-sm font-semibold" x-text="more ? more.title : ''"></h3>
            <template x-for="(it, i) in (more ? more.items : [])" :key="i">
                <div :style="it.danger ? 'border-top: 1px solid var(--border); margin-top: .75rem; padding-top: .75rem;' : ''">
                    <template x-if="it.href"><a :href="it.href" class="corex-btn-outline text-sm w-full block text-left no-underline" x-text="it.label"></a></template>
                    <template x-if="it.ask"><button type="button" class="corex-btn-outline text-sm w-full text-left" :style="it.danger ? 'color: var(--ds-crimson);' : ''" x-text="it.label" @click="const a = it.ask; more = null; openAsk(a)"></button></template>
                </div>
            </template>
            <div class="flex justify-end pt-2"><button type="button" class="corex-btn-outline text-xs" x-on:click="more = null">Close</button></div>
        </div>
    </div>
</div>

<script>
    // Ticking a kind of title deed applies at once and opens that kind's section.
    function tdTick(box) {
        const form = box.form;
        form.querySelector('[name=focus]').value = box.checked ? box.dataset.focus : '';
        form.submit();
    }
    // A section's open / closed state: the section a tile or tick just chose is forced open; otherwise the person's last choice in this browser
    // wins over the default (at the agency open, the others closed).
    function tdSectionState(userId, key, defaultOpen, forced) {
        const storeKey = 'td-list-open-' + userId + '-' + key;
        let stored = null;
        try { stored = window.localStorage.getItem(storeKey); } catch (e) { /* private window: the default applies */ }
        const open = forced ? true : (stored === null ? defaultOpen : stored === '1');
        // A section the person chose (a tile or a tick) stays open from then on, until they collapse it themselves.
        if (forced) { try { window.localStorage.setItem(storeKey, '1'); } catch (e) { /* not remembered */ } }
        return {
            open,
            toggle() { this.open = !this.open; try { window.localStorage.setItem(storeKey, this.open ? '1' : '0'); } catch (e) { /* not remembered */ } },
        };
    }
    function deedRowActions(cfg) {
        return {
            ask: null, more: null, sending: false, returnTo: cfg.returnTo,
            openMore(m) { this.more = m; },
            openAsk(a) { this.sending = false; this.ask = a; },
            // The whole row opens the record - except a click on a link, button or form inside it.
            rowClick(e, url) {
                if (e.target.closest('a, button, form, input, select, textarea, label, [data-row-actions]')) return;
                window.location.href = url;
            },
        };
    }
</script>
@endsection
