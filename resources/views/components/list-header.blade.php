{{--
    List Header — Sticky header for list/index pages with search, filters, and actions.

    Usage (server-side — preferred for large datasets):
    <x-list-header
        title="Deal Register"
        :form-action="route('admin.deals')"
        :paginator="$deals"
        search-placeholder="Search deals..."
    >
        <x-slot:filters>
            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All statuses</option>
            </select>
        </x-slot:filters>
        <x-slot:actions>
            <a href="..." class="corex-btn-primary text-sm">+ Add Deal</a>
        </x-slot:actions>
    </x-list-header>

    Usage (client-side Alpine — for small datasets):
    <div x-data="{ search: '' }">
        <x-list-header
            title="Users"
            :count="$users->count()"
            search-placeholder="Search by name, email..."
            search-model="search"
        >
            ...
        </x-list-header>
    </div>

    Props:
        title               (required)  Page title
        form-action         (optional)  URL for server-side GET form (enables form mode)
        paginator           (optional)  Laravel paginator instance (auto-extracts from/to/total)
        count               (optional)  Showing count (client-side mode)
        total               (optional)  Total count (client-side mode or override)
        search-placeholder  (optional)  Placeholder text for search input
        search-name         (optional)  Name attr for server-side search input (default: "search")
        search-model        (optional)  Alpine x-model binding name (default: "search")
        sticky              (optional)  Whether to stick on scroll (default: true)
        back-route          (optional)  URL for back arrow link
        back-label          (optional)  Text next to back arrow (default: "Back")

    Slots:
        filters   (optional)  Filter dropdowns (use name= attrs in form mode)
        actions   (optional)  Action buttons (right side)
--}}

@props([
    'title',
    'formAction' => null,
    'paginator' => null,
    'count' => null,
    'total' => null,
    'searchPlaceholder' => 'Search...',
    'searchName' => 'search',
    'searchModel' => 'search',
    'sticky' => true,
    'backRoute' => null,
    'backLabel' => 'Back',
])

@php
    // Auto-extract pagination info
    $showFrom = null;
    $showTo = null;
    $showTotal = $total;
    $showCount = $count;

    if ($paginator && method_exists($paginator, 'total')) {
        $showFrom = $paginator->firstItem();
        $showTo = $paginator->lastItem();
        $showTotal = $paginator->total();
    }

    $isServerSide = !is_null($formAction);
    $searchValue = $isServerSide ? request($searchName, '') : '';
@endphp

<div class="{{ $sticky ? 'sticky top-0 z-30' : '' }} -mx-4 -mt-4 mb-6 lg:-mx-6 lg:-mt-6 lg:mb-8" style="background: var(--surface); border-bottom: 1px solid var(--border); box-shadow: 0 1px 2px var(--shadow, rgba(0,0,0,0.05));">
    <div class="px-4 sm:px-6 lg:px-8 py-3">
        {{-- Top row: title + count + actions --}}
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                @if($backRoute)
                <a href="{{ $backRoute }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0 transition-all duration-300" style="color: var(--text-muted);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    {{ $backLabel }}
                </a>
                <span class="flex-shrink-0" style="color: var(--border);">|</span>
                @endif
                <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">{{ $title }}</h1>
                @if(!is_null($showFrom) && !is_null($showTo) && !is_null($showTotal))
                <span class="text-sm flex-shrink-0" style="color: var(--text-muted);">Showing {{ $showFrom }} to {{ $showTo }} of {{ $showTotal }} results</span>
                @elseif(!is_null($showCount) && !is_null($showTotal))
                <span class="text-sm flex-shrink-0" style="color: var(--text-muted);">Showing {{ $showCount }} of {{ $showTotal }}</span>
                @elseif(!is_null($showCount))
                <span class="text-sm flex-shrink-0" style="color: var(--text-muted);">{{ $showCount }} results</span>
                @endif
            </div>

            {{-- Always rendered so the tour "?" launcher has a header home on every
                 page using this component; the launcher self-gates on tour-less pages. --}}
            <div class="flex items-center gap-2 flex-shrink-0">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                {{ $actions ?? '' }}
            </div>
        </div>

        {{-- Bottom row: search + filters --}}
        @if($isServerSide)
        <form method="GET" action="{{ $formAction }}" class="flex flex-wrap items-center gap-3 mt-2">
            {{-- Preserve sort params --}}
            @if(request('sort'))
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="direction" value="{{ request('direction', 'asc') }}">
            @endif

            <div class="relative flex-1 max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text"
                       name="{{ $searchName }}"
                       value="{{ $searchValue }}"
                       placeholder="{{ $searchPlaceholder }}"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md focus:outline-none transition-all duration-300"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            @if(isset($filters))
            <div class="flex items-center gap-2 flex-shrink-0 flex-wrap">
                {{ $filters }}
            </div>
            @endif

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if(collect(request()->except(['sort', 'direction', 'page']))->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
            <a href="{{ $formAction }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
            @endif
        </form>
        @else
        <div class="flex items-center gap-3 mt-2">
            <div class="relative flex-1 max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text"
                       x-model.debounce.300ms="{{ $searchModel }}"
                       placeholder="{{ $searchPlaceholder }}"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md focus:outline-none transition-all duration-300"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            @if(isset($filters))
            <div class="flex items-center gap-2 flex-shrink-0">
                {{ $filters }}
            </div>
            @endif
        </div>
        @endif
    </div>
</div>
