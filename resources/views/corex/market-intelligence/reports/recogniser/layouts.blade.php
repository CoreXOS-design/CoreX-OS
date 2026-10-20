{{-- Report recogniser — learned layouts + system settings (CoreX owner roles only). Spec: .ai/specs/mic-complete-spec.md §8.9. No Alpine. --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $f = $filters;
    $sortUrl = function (string $key) use ($f) {
        $dir = ($f['sort'] === $key && $f['dir'] === 'asc') ? 'desc' : 'asc';
        return route('market-intelligence.reports.recogniser.layouts', array_merge(request()->except('page'), ['sort' => $key, 'dir' => $dir]));
    };
    $arrow = fn (string $key) => $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $th = 'text-align: left; padding: 8px 12px;';
    $input = 'padding: 6px 8px; font-size: 0.8125rem; background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px;';
@endphp
<div style="width: 100%;">

    <x-mic-page-header
        title="Learned layouts"
        subtitle="After the AI reads a report in a layout CoreX has not seen, CoreX remembers that publisher's layout and reads the next one for free. Approve, rename or retire them here.">
        <x-slot:actions>
            <a href="{{ route('market-intelligence.reports.recogniser.queue') }}" class="corex-btn-outline text-xs">← Unrecognised &amp; AI-read reports</a>
            <a href="{{ route('market-intelligence.reports.parser-dashboard') }}" class="corex-btn-outline text-xs">Parser accuracy</a>
        </x-slot:actions>
    </x-mic-page-header>

    @include('corex.market-intelligence.partials.tabs')

    @if(session('status'))
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem; background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent); color: var(--ds-green, #10b981); border: 1px solid var(--ds-green, #10b981); border-radius: 4px;">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem; background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color: var(--ds-crimson, #dc2626); border: 1px solid var(--ds-crimson, #dc2626); border-radius: 4px;">
            <ul style="margin: 0; padding-left: 18px;">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- System settings --}}
    <form method="POST" action="{{ route('market-intelligence.reports.recogniser.settings') }}"
          style="margin-bottom: 16px; padding: 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        @csrf
        <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-primary); margin-bottom: 2px;">System settings — report reading</div>
        <p style="font-size: 0.6875rem; color: var(--text-muted); margin: 0 0 10px 0;">
            These apply to every agency and protect the AI bill. Each agency separately switches AI reading on or off in its own Market Intelligence setup.
        </p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-bottom: 10px;">
            <label style="font-size: 0.6875rem; color: var(--text-muted);">Most pages sent to the AI
                <input type="number" min="1" max="100" step="1" name="report_ai_max_pages" value="{{ old('report_ai_max_pages', $system['report_ai_max_pages']) }}" style="{{ $input }} width: 100%;">
                <span>Longer reports are saved, not sent. Default 40.</span>
            </label>
            <label style="font-size: 0.6875rem; color: var(--text-muted);">AI confidence cut-off (0–1)
                <input type="number" min="0" max="1" step="0.01" name="report_ai_min_confidence" value="{{ old('report_ai_min_confidence', $system['report_ai_min_confidence']) }}" style="{{ $input }} width: 100%;">
                <span>Below this the AI's reading is not used. Default 0.50.</span>
            </label>
            <label style="font-size: 0.6875rem; color: var(--text-muted);">Layout match cut-off (0.5–1)
                <input type="number" min="0.5" max="1" step="0.01" name="report_layout_min_match" value="{{ old('report_layout_min_match', $system['report_layout_min_match']) }}" style="{{ $input }} width: 100%;">
                <span>Share of a layout's identifying phrases that must be on the page. Default 0.85.</span>
            </label>
            <label style="font-size: 0.6875rem; color: var(--text-muted);">Layout self-test cut-off (0.5–1)
                <input type="number" min="0.5" max="1" step="0.01" name="report_layout_selftest_min" value="{{ old('report_layout_selftest_min', $system['report_layout_selftest_min']) }}" style="{{ $input }} width: 100%;">
                <span>How much of the AI's reading a new layout must reproduce before it is saved. Default 0.90.</span>
            </label>
        </div>
        <button type="submit" class="corex-btn-primary text-xs">Save settings</button>
    </form>

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('market-intelligence.reports.recogniser.layouts') }}"
          style="display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 12px; padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <label style="font-size: 0.6875rem; color: var(--text-muted);">Search<br>
            <input type="text" name="q" value="{{ $f['q'] }}" placeholder="Layout name or publisher" style="{{ $input }} min-width: 230px;">
        </label>
        <label style="font-size: 0.6875rem; color: var(--text-muted);">Status<br>
            <select name="status" style="{{ $input }}">
                <option value="all" @selected($f['status'] === 'all')>All</option>
                <option value="learned" @selected($f['status'] === 'learned')>Learned (not yet approved)</option>
                <option value="approved" @selected($f['status'] === 'approved')>Approved</option>
                <option value="retired" @selected($f['status'] === 'retired')>Retired</option>
            </select>
        </label>
        <input type="hidden" name="sort" value="{{ $f['sort'] }}"><input type="hidden" name="dir" value="{{ $f['dir'] }}">
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
        <a href="{{ route('market-intelligence.reports.recogniser.layouts') }}" class="corex-btn-outline text-xs">Clear</a>
    </form>

    @if($layouts->isEmpty())
        <div style="padding: 24px; text-align: center; background: var(--surface); border: 1px dashed var(--border); border-radius: 6px;">
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                @if($f['q'] !== '' || $f['status'] !== 'all')
                    No layouts match these filters.
                @else
                    No layouts learned yet. One is saved automatically after the AI reads a report in a layout CoreX has not seen.
                @endif
            </p>
        </div>
    @else
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="{{ $th }}"><a href="{{ $sortUrl('name') }}" style="color: inherit; text-decoration: none;">Layout{{ $arrow('name') }}</a></th>
                        <th style="{{ $th }}"><a href="{{ $sortUrl('publisher') }}" style="color: inherit; text-decoration: none;">Publisher{{ $arrow('publisher') }}</a></th>
                        <th style="{{ $th }}">Reads</th>
                        <th style="{{ $th }}">Status</th>
                        <th style="text-align: right; padding: 8px 12px;"><a href="{{ $sortUrl('used') }}" style="color: inherit; text-decoration: none;">Times used{{ $arrow('used') }}</a></th>
                        <th style="{{ $th }}"><a href="{{ $sortUrl('last_used') }}" style="color: inherit; text-decoration: none;">Last used{{ $arrow('last_used') }}</a></th>
                        <th style="{{ $th }}"><a href="{{ $sortUrl('learned') }}" style="color: inherit; text-decoration: none;">Learned{{ $arrow('learned') }}</a></th>
                        <th style="{{ $th }}">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($layouts as $l)
                        <tr style="border-top: 1px solid var(--border); {{ $l->trashed() ? 'opacity: 0.6;' : '' }}">
                            <td style="padding: 8px 12px;">
                                @if($l->trashed())
                                    {{ $l->name }}
                                @else
                                    <form method="POST" action="{{ route('market-intelligence.reports.recogniser.layouts.rename', $l->id) }}" style="display: flex; gap: 4px;">
                                        @csrf
                                        <input type="text" name="name" value="{{ $l->name }}" maxlength="160" style="{{ $input }} min-width: 220px;">
                                        <button type="submit" class="corex-btn-outline text-xs">Rename</button>
                                    </form>
                                @endif
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $l->publisher ?? '—' }}</td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ str_replace('+', ' + ', (string) $l->report_kind) }}<br><span style="font-size: 0.6875rem; color: var(--text-muted);">self-test {{ $l->selftest_score !== null ? round($l->selftest_score * 100) . '%' : '—' }}</span></td>
                            <td style="padding: 8px 12px; font-weight: 600; color: {{ $l->trashed() ? 'var(--text-muted)' : ($l->status === 'approved' ? '#10b981' : 'var(--ds-amber, #d97706)') }};">
                                {{ $l->trashed() ? 'Retired' : ($l->status === 'approved' ? 'Approved' : 'Learned') }}
                            </td>
                            <td style="padding: 8px 12px; text-align: right;">{{ number_format($l->times_used) }}</td>
                            <td style="padding: 8px 12px; color: var(--text-muted); font-size: 0.75rem;">{{ $l->last_used_at?->diffForHumans() ?? 'never' }}</td>
                            <td style="padding: 8px 12px; color: var(--text-muted); font-size: 0.75rem;">{{ $l->created_at?->format('j M Y') }}</td>
                            <td style="padding: 8px 12px; white-space: nowrap;">
                                @if($l->trashed())
                                    <form method="POST" action="{{ route('market-intelligence.reports.recogniser.layouts.restore', $l->id) }}" style="display: inline-block;">
                                        @csrf <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                    </form>
                                @else
                                    @if($l->status !== 'approved')
                                        <form method="POST" action="{{ route('market-intelligence.reports.recogniser.layouts.approve', $l->id) }}" style="display: inline-block;">
                                            @csrf <button type="submit" class="corex-btn-primary text-xs">Approve</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('market-intelligence.reports.recogniser.layouts.retire', $l->id) }}" style="display: inline-block; margin-left: 4px;"
                                          onsubmit="return confirm('Retire this layout? Reports in it will go to the AI again. It can be restored.');">
                                        @csrf <button type="submit" class="corex-btn-outline text-xs">Retire</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding: 12px 4px;">{{ $layouts->links() }}</div>
    @endif
</div>
@endsection
