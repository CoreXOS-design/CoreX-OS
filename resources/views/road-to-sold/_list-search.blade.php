{{-- Page layout v2 (23.31): the list's search, Status and Filters, now on the ONE toolbar row (tabs left, this in the middle, More on the right). Inherits the page variables. --}}
    @php
        $foldSet = collect([$filters['view'] !== 'today', $filters['selection'] !== '', $filters['sort'] !== 'order', $filters['dir'] === 'desc', ! $date->isToday()])->filter()->count();
    @endphp
    <form method="GET" class="rts-filters" x-data="{ f: false }" @keydown.escape="f = false" @click.outside="f = false" data-tour="smk-rts-filters" data-qa="list-filters">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        @if(! $viewingOwn)<input type="hidden" name="agent" value="{{ $agentId }}">@endif
        <div class="rts-fl" data-tour="smk-rts-search">
            <label class="sr-only" for="rts-q">Search</label>
            <input id="rts-q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="Address, suburb, owner or phone" class="rounded-md px-3 py-2 text-xs rts-fl-q" style="{{ $inputStyle }}">
        </div>
        @if($listView)
            <div class="rts-fl" data-tour="smk-rts-status">
                <label class="sr-only" for="rts-status">Status</label>
                <select id="rts-status" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    @foreach($statusLabels as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
        @endif
        <button type="submit" class="corex-btn-outline corex-btn-xs" data-tour="smk-rts-search-btn">Search</button>
        @if($filters['q'] !== '' || $filters['selection'] !== '' || $filters['status'] !== 'open' || $filters['view'] !== 'today')
            <a href="{{ route('road-to-sold.index', ($viewingOwn ? [] : ['agent' => $agentId]) + \App\Services\RoadToSold\Track::params($track)) }}" class="corex-btn-outline corex-btn-xs">Clear</a>
        @endif
        <div class="rts-fold-wrap">
            <button type="button" class="corex-btn-outline corex-btn-xs" @click="f = !f" :aria-expanded="f ? 'true' : 'false'" data-qa="filters-toggle">Filters{{ $foldSet > 0 ? ' (' . $foldSet . ')' : '' }} &#9662;</button>
            <div x-show="f" x-cloak class="rts-fold-panel" data-qa="filters-panel">
                <div data-tour="smk-rts-view">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-view">Show</label>
                    <select id="rts-view" name="view" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                        @foreach($viewLabels as $v => $l)<option value="{{ $v }}" @selected($filters['view'] === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                @if($listView)
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-sel">Selection</label>
                        <select id="rts-sel" name="selection" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                            <option value="">All selections</option>
                            @foreach($selections as $k => $l)<option value="{{ $k }}" @selected($filters['selection'] === $k)>{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-date">Day</label>
                        <x-dmy-date id="rts-date" name="date" :value="$date->toDateString()" :required="true" :submit="true" />
                    </div>
                @endif
                <div data-tour="smk-rts-sort">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-sort">Sort</label>
                    <select id="rts-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                        @foreach($sortLabels as $v => $l)<option value="{{ $v }}" @selected($filters['sort'] === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rts-dir">Order</label>
                    <select id="rts-dir" name="dir" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                        <option value="asc" @selected($filters['dir'] === 'asc')>Ascending</option>
                        <option value="desc" @selected($filters['dir'] === 'desc')>Descending</option>
                    </select>
                </div>
            </div>
        </div>
    </form>
