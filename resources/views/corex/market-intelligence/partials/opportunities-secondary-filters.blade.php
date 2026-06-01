{{--
    MIC Phase D4 — Opportunities tab secondary filters: suburb / source /
    status dropdowns + search box. GET-form so the URL captures state.
--}}
@php
    $statuses = [
        ''         => 'Any status',
        'active'   => 'Active',
        'promoted' => 'Promoted',
        'archived' => 'Archived',
        'duplicate'=> 'Duplicate',
    ];
@endphp
<form method="GET" action="{{ route('market-intelligence.opportunities') }}"
      style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
             margin-bottom: 16px; padding: 10px 12px;
             background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
    {{-- Preserve active filter chip when changing secondaries. --}}
    @if(($activeFilter ?? 'all') !== 'all')
        <input type="hidden" name="filter" value="{{ $activeFilter }}">
    @endif

    <select name="suburb"
            style="padding: 5px 8px; font-size: 0.8125rem;
                   background: var(--surface-2); color: var(--text-primary);
                   border: 1px solid var(--border); border-radius: 6px;">
        <option value="">Any suburb</option>
        @foreach(($suburbCounts ?? []) as $row)
            <option value="{{ $row->suburb }}" {{ ($activeSuburb ?? '') === $row->suburb ? 'selected' : '' }}>
                {{ $row->suburb }} ({{ $row->cnt }})
            </option>
        @endforeach
    </select>

    <select name="source"
            style="padding: 5px 8px; font-size: 0.8125rem;
                   background: var(--surface-2); color: var(--text-primary);
                   border: 1px solid var(--border); border-radius: 6px;">
        <option value="">Any source</option>
        @foreach(($sourceCounts ?? []) as $type => $row)
            <option value="{{ $type }}" {{ ($activeSource ?? '') === $type ? 'selected' : '' }}>
                {{ strtoupper(str_replace('_', ' ', $type)) }} ({{ $row->cnt }})
            </option>
        @endforeach
    </select>

    <select name="status"
            style="padding: 5px 8px; font-size: 0.8125rem;
                   background: var(--surface-2); color: var(--text-primary);
                   border: 1px solid var(--border); border-radius: 6px;">
        @foreach($statuses as $key => $label)
            <option value="{{ $key }}" {{ ($activeStatus ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>

    <input type="search" name="search" placeholder="Search address, erf, ref…"
           value="{{ $activeSearch ?? '' }}"
           style="flex: 1; min-width: 180px; padding: 5px 10px; font-size: 0.8125rem;
                  background: var(--surface-2); color: var(--text-primary);
                  border: 1px solid var(--border); border-radius: 6px;">

    <button type="submit" class="corex-btn-primary">
        Apply
    </button>
    @if(($activeSuburb ?? '') !== '' || ($activeSource ?? '') !== '' || ($activeStatus ?? '') !== '' || ($activeSearch ?? '') !== '')
        <a href="{{ route('market-intelligence.opportunities', ($activeFilter ?? 'all') !== 'all' ? ['filter' => $activeFilter] : []) }}"
           style="font-size: 0.75rem; color: var(--text-muted); text-decoration: underline;">
            Clear filters
        </a>
    @endif
</form>
