{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    // 2026-09-08 — sort indicator: which column is ACTUALLY driving the
    // current order, including the implicit default (no ?sort= at all
    // still sorts by 'date' server-side — see applySearchSortAndDateRange's
    // $defaultSort) so the arrow is never missing on first load.
    $activeSort = request('sort', 'date');
    $activeDirection = request('direction', 'desc');
    $sortLink = fn ($col) => route('corex.rental-applications.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($activeSort === $col && $activeDirection === 'desc') ? 'asc' : 'desc']
    ));
    $sortIndicator = fn ($col) => $activeSort === $col ? ($activeDirection === 'asc' ? ' ▲' : ' ▼') : '';
@endphp

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Applications</h1>
                <p class="text-xs" style="color: var(--text-muted);">Send a rental application to a prospective tenant.</p>
            </div>
            @permission('rental_applications.create')
            <a href="{{ route('corex.rental-applications.create') }}" class="corex-btn-primary text-xs">New Rental Application</a>
            @endpermission
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif

    {{--
        AT-392, Johan (2026-09-08): "I create a rental application — it
        will sit under rental applications until the application has been
        returned." The two-screen split is deliberate and stays — this is
        the discoverability fix, not a redesign. Always visible (not just
        in the empty state) so an agent who sends an application and comes
        back later never hits a dead end wondering where it went once the
        tenant replies.
    --}}
    @if($returnedCount > 0)
        <div class="rounded-md px-4 py-3 text-sm flex items-center justify-between flex-wrap gap-2" style="background: var(--ds-blue-soft, #eff6ff); border: 1px solid var(--ds-blue, #2563eb); color: var(--text-primary);">
            <span>
                {{ $returnedCount }} {{ Str::plural('application', $returnedCount) }} the tenant has sent back {{ $returnedCount === 1 ? "isn't" : "aren't" }} shown here — they move to Returned Applications once the tenant replies.
            </span>
            <a href="{{ route('corex.rental-applications.returned') }}" class="corex-btn-primary text-xs shrink-0">Go to Returned Applications ({{ $returnedCount }}) &rarr;</a>
        </div>
    @endif

    {{-- 2026-09-08 — Johan's permanent CRUD standard: own/branch/agency
         scope TOGGLE, same segmented-link pattern as the buyer pipeline
         board (command-center/buyers/pipeline.blade.php's "Layer 3" toggle)
         — options above the user's own permission ceiling are not shown at
         all (RentalApplication::clampScope() also enforces this server-side
         regardless, so hiding here is UX, not the security boundary). --}}
    <div class="flex items-center gap-2">
        <span class="text-xs font-medium" style="color: var(--text-secondary);">Showing:</span>
        <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
            <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['scope' => 'own'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ request('scope', 'own') === 'own' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Own</a>
            @if($canSeeBranch)
            <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['scope' => 'branch'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="border-left: 1px solid var(--border); {{ request('scope', 'own') === 'branch' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Branch</a>
            @endif
            @if($canSeeAgency)
            <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['scope' => 'agency'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="border-left: 1px solid var(--border); {{ request('scope', 'own') === 'agency' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Agency</a>
            @endif
        </div>
    </div>

    <form method="GET" action="{{ route('corex.rental-applications.index') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="hidden" name="scope" value="{{ request('scope', 'own') }}">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Applicant name, email, ID number, property, or #id"
                   class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); min-width: 260px;">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['draft', 'sent', 'in_progress', 'withdrawn'] as $statusOption)
                    <option value="{{ $statusOption }}" @selected(request('status') === $statusOption)>{{ str_replace('_', ' ', ucfirst($statusOption)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sent from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sent to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Per page</label>
            <select name="per_page" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);" onchange="this.form.submit()">
                @foreach([10, 25, 50, 100] as $option)
                    <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'date_from', 'date_to', 'per_page']))
            <a href="{{ route('corex.rental-applications.index', request()->only('scope')) }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
        <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['archived' => request()->boolean('archived') ? null : 1])) }}"
           class="corex-btn-outline text-xs {{ request()->boolean('archived') ? 'corex-tab-active' : '' }}">
            {{ request()->boolean('archived') ? 'Hide archived' : 'Show archived' }}
        </a>
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('contact') }}" style="color: var(--text-muted);">Applicant{{ $sortIndicator('contact') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('agent') }}" style="color: var(--text-muted);">Agent{{ $sortIndicator('agent') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('date') }}" style="color: var(--text-muted);">Created{{ $sortIndicator('date') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('updated') }}" style="color: var(--text-muted);">Last updated{{ $sortIndicator('updated') }}</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $application)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2">{{ $application->contact->full_name ?? $application->full_name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '—' }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $application->status === 'draft' ? 'ds-badge-muted' : 'ds-badge-info' }}">{{ str_replace('_', ' ', $application->status) }}</span></td>
                    <td class="px-4 py-2">{{ $application->createdBy->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-2">{{ $application->updated_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <a href="{{ route('corex.rental-applications.show', $application) }}" class="corex-btn-outline text-xs">Open</a>
                        @permission('rental_applications.create')
                            @if($application->recipientEmail())
                                <form method="POST" action="{{ route('corex.rental-applications.send', $application) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs">{{ $application->status === 'draft' ? 'Send' : 'Resend' }}</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('corex.rental-applications.destroy', $application) }}"
                                  onsubmit="return confirm('Archive this rental application? It can be restored later.');" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                            </form>
                        @endpermission
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(request()->hasAny(['q', 'status', 'date_from', 'date_to']))
                        No rental applications match this search. Try clearing a filter{{ ($canSeeBranch || $canSeeAgency) && request('scope', 'own') === 'own' ? ', or widen the scope above' : '' }}.
                    @elseif(request('scope', 'own') === 'own' && ($canSeeBranch || $canSeeAgency))
                        You have no rental applications of your own yet. Try {{ $canSeeAgency ? 'Agency' : 'Branch' }} above if you're expecting to see a colleague's.
                        @if($returnedCount > 0)
                            <br>Applications the tenant has sent back don't show here — see <a href="{{ route('corex.rental-applications.returned') }}" style="color: var(--brand-icon, #2563eb);">Returned Applications ({{ $returnedCount }})</a>.
                        @endif
                    @else
                        No rental applications yet.
                        @if($returnedCount > 0)
                            <br>Applications the tenant has sent back don't show here — see <a href="{{ route('corex.rental-applications.returned') }}" style="color: var(--brand-icon, #2563eb);">Returned Applications ({{ $returnedCount }})</a>.
                        @endif
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $applications->links() }}

    @if($archived !== null)
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 text-sm font-semibold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">Archived</div>
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Contact</th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Property</th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Archived</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($archived as $application)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2">{{ $application->contact->full_name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->deleted_at->format('d M Y') }}</td>
                    <td class="px-4 py-2 text-right">
                        @permission('rental_applications.create')
                        <form method="POST" action="{{ route('corex.rental-applications.restore', $application->id) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                        </form>
                        @endpermission
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">Nothing archived.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $archived->links() }}
    @endif
</div>
@endsection
