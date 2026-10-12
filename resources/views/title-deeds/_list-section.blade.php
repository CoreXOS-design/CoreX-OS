{{--
    AT-450 round 4 - one collapsible section of the title-deed list (spec section 17).
      $sec = ['key' => agency|collected|archived, 'count' => N, 'deeds' => paginator, 'suffix' => a|c|x, 'sort' => ?key, 'dir' => ?asc|desc]
    Each section has its own header count, sort (sort_x / dir_x) and pagination (page_x). Parent scope supplies $can, $pinUsed, $token, $hasFilters, $focus.
    "At the agency" has the Days waiting column; "Collected" has the collected date / time / who and no Days waiting; "Archived" says what it was.
--}}
@php
    $key = $sec['key'];
    $deeds = $sec['deeds'];
    $suffix = $sec['suffix'];
    $title = ['agency' => 'At the agency', 'collected' => 'Collected', 'archived' => 'Archived'][$key];
    $empty = ['agency' => 'No title deeds at the agency.', 'collected' => 'No title deeds collected yet.', 'archived' => 'No archived title deeds.'][$key];
    $defaultOpen = $key === 'agency';
    $forced = ($focus ?? null) === $key;
    $userId = (int) auth()->id();
    $th = 'px-4 py-3 text-xs font-semibold uppercase tracking-wider text-left';
    // A sortable header: a worded link that keeps every other query parameter (ticks, filters, the other sections' sort and page).
    $sortLink = function (string $field, string $label, string $align = 'left', bool $wide = false) use ($sec, $suffix, $th) {
        $current = $sec['sort'] === $field;
        $next = ($current && $sec['dir'] === 'asc') ? 'desc' : 'asc';
        $url = request()->fullUrlWithQuery(['sort_' . $suffix => $field, 'dir_' . $suffix => $next, 'page_' . $suffix => null, 'focus' => null]);
        $arrow = $current ? ($sec['dir'] === 'asc' ? ' ▲' : ' ▼') : '';

        return '<th class="' . $th . ($align === 'right' ? ' text-right' : '') . ($wide ? ' hidden md:table-cell' : '') . '" style="color: var(--text-muted);" aria-sort="' . ($current ? ($sec['dir'] === 'asc' ? 'ascending' : 'descending') : 'none') . '">'
            . '<a href="' . e($url) . '" class="no-underline hover:underline" style="color: inherit;">' . e($label) . $arrow . '</a></th>';
    };
@endphp
<section id="td-sec-{{ $key }}" class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-td-section="{{ $key }}"
         x-data="tdSectionState({{ $userId }}, '{{ $key }}', {{ $defaultOpen ? 'true' : 'false' }}, {{ $forced ? 'true' : 'false' }})"
         @if($forced) x-init="$nextTick(() => $el.scrollIntoView({ block: 'start' }))" @endif>
    <button type="button" class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left" style="color: var(--text-primary);" @click="toggle()" :aria-expanded="open ? 'true' : 'false'" aria-controls="td-sec-body-{{ $key }}">
        <span class="text-sm font-semibold" data-td-section-title>{{ $title }} ({{ number_format($sec['count']) }})</span>
        <span class="text-xs font-medium" style="color: var(--text-secondary);" x-text="open ? 'Hide this list' : 'Show this list'">{{ $defaultOpen ? 'Hide this list' : 'Show this list' }}</span>
    </button>

    <div id="td-sec-body-{{ $key }}" x-show="open" @if(! $defaultOpen && ! $forced) x-cloak @endif style="border-top: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        {!! $sortLink('ref', 'Ref') !!}
                        {!! $sortLink('property', 'Property') !!}
                        <th class="{{ $th }} hidden md:table-cell" style="color: var(--text-muted);">Owner(s)</th>
                        {!! $sortLink('agent', 'Agent', 'left', true) !!}
                        {!! $sortLink('received', 'Received', 'left', true) !!}
                        @if($key === 'agency')
                            {!! $sortLink('status', 'Status', 'left', true) !!}
                            {!! $sortLink('waiting', 'Days waiting', 'right', true) !!}
                        @elseif($key === 'collected')
                            {!! $sortLink('collected', 'Collected') !!}
                        @else
                            {!! $sortLink('status', 'Was', 'left', true) !!}
                        @endif
                        <th class="{{ $th }} text-right" style="color: var(--text-muted);">What to do</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deeds as $d)
                    <tr style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:opacity-90" data-td-row="{{ $d->id }}" @click="rowClick($event, @js(route('title-deeds.show', $d->id)))">
                        <td class="px-3 md:px-4 py-3 whitespace-nowrap font-semibold tabular-nums" style="color: var(--text-primary);" data-td-ref>{{ $d->reference ?: '—' }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('title-deeds.show', $d->id) }}" class="font-medium no-underline hover:underline" style="color: var(--text-primary);">{{ $d->property_display }}</a>
                            {{-- On a phone the columns that do not fit move under the property name. --}}
                            <div class="md:hidden text-xs mt-0.5" style="color: var(--text-secondary);">
                                {{ $d->owners->map->display_name->implode(', ') ?: '—' }}
                                @if($key === 'agency') &middot; {{ $d->status_label }} &middot; {{ $d->days_waiting ?? 0 }} {{ ($d->days_waiting ?? 0) === 1 ? 'day' : 'days' }} waiting
                                @elseif($key === 'archived') &middot; was {{ strtolower($d->status_label) }}
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell" style="color: var(--text-secondary);">{{ $d->owners->map->display_name->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-3 hidden md:table-cell">{{ $d->agent?->name ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap hidden md:table-cell">{{ $d->received_at?->format('d M Y') }}</td>
                        @if($key === 'agency')
                            <td class="px-4 py-3 whitespace-nowrap hidden md:table-cell" style="color: var(--text-primary);">{{ $d->status_label }}</td>
                            <td class="px-4 py-3 text-right tabular-nums hidden md:table-cell">{{ $d->days_waiting ?? '—' }}</td>
                        @elseif($key === 'collected')
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ $d->collected_at?->format('d M Y, H:i') }}@if($d->collected_by_name) &middot; {{ $d->collected_by_name }}@endif
                            </td>
                        @else
                            <td class="px-4 py-3 whitespace-nowrap hidden md:table-cell" style="color: var(--text-secondary);">{{ $d->status_label }}</td>
                        @endif
                        <td class="px-2 md:px-3 py-2 text-right whitespace-nowrap" data-row-actions>
                            @include('title-deeds._row-actions', ['d' => $d])
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);" data-td-empty="{{ $key }}">
                            {{ $empty }}
                            @if($hasFilters)<a href="{{ route('title-deeds.index', ['show' => $ticks ?: ['']]) }}" class="font-semibold" style="color: var(--brand-icon);">Clear the filters</a>@endif
                            @if($key === 'agency' && ! $hasFilters && ($total ?? 0) === 0)
                                @permission('title_deeds.create')<a href="{{ route('title-deeds.create') }}" class="font-semibold" style="color: var(--brand-icon);">Log the first one</a>@endpermission
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($deeds->hasPages())
        <div class="px-4 py-3 flex items-center justify-between flex-wrap gap-2" style="border-top:1px solid var(--border);">
            <div class="text-xs" style="color:var(--text-muted);">{{ number_format($deeds->firstItem() ?? 0) }}–{{ number_format($deeds->lastItem() ?? 0) }} of {{ number_format($deeds->total()) }}</div>
            <div>{{ $deeds->onEachSide(1)->links() }}</div>
        </div>
        @endif
    </div>
</section>
