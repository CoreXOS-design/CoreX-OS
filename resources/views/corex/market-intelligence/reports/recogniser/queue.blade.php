{{-- Report recogniser — "Unrecognised & AI-read reports" (CoreX owner roles only, all agencies). Spec: .ai/specs/mic-complete-spec.md §8.9. No Alpine. --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $f = $filters;
    $sortUrl = function (string $key) use ($f) {
        $dir = ($f['sort'] === $key && $f['dir'] === 'asc') ? 'desc' : 'asc';
        return route('market-intelligence.reports.recogniser.queue', array_merge(request()->except('page'), ['sort' => $key, 'dir' => $dir]));
    };
    $arrow = fn (string $key) => $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $th = 'text-align: left; padding: 8px 12px;';
    $input = 'padding: 6px 8px; font-size: 0.8125rem; background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px;';
@endphp
<div style="width: 100%;">

    <x-mic-page-header
        title="Unrecognised & AI-read reports"
        subtitle="Every report that no known format recognised, across all agencies. Reports read by AI need their figures checked; reports that could not be read are saved here.">
        <x-slot:actions>
            <a href="{{ route('market-intelligence.reports.recogniser.layouts') }}" class="corex-btn-outline text-xs">Learned layouts</a>
            <a href="{{ route('market-intelligence.reports.parser-dashboard') }}" class="corex-btn-outline text-xs">← Parser accuracy</a>
        </x-slot:actions>
    </x-mic-page-header>

    @include('corex.market-intelligence.partials.tabs')

    @if(session('status'))
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem; background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent); color: var(--ds-green, #10b981); border: 1px solid var(--ds-green, #10b981); border-radius: 4px;">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem; background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color: var(--ds-crimson, #dc2626); border: 1px solid var(--ds-crimson, #dc2626); border-radius: 4px;">{{ session('error') }}</div>
    @endif

    @if($focus !== null)
        <div style="margin-bottom: 12px; padding: 8px 12px; font-size: 0.8125rem; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); border-radius: 4px;">
            <span>Showing one report (opened from a message). </span>
            <a href="{{ route('market-intelligence.reports.recogniser.queue') }}" class="corex-btn-outline text-xs">Show the whole queue</a>
        </div>
    @endif

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px; margin-bottom: 16px;">
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Read by AI — to check</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--ds-amber, #d97706);">{{ number_format($counts['open_ai']) }}</div>
        </div>
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Could not be read</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--ds-crimson, #dc2626);">{{ number_format($counts['open_unread']) }}</div>
        </div>
        <div style="padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <div style="font-size: 0.625rem; text-transform: uppercase; font-weight: 600; color: var(--text-muted);">Resolved</div>
            <div style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">{{ number_format($counts['resolved']) }}</div>
        </div>
    </div>

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('market-intelligence.reports.recogniser.queue') }}"
          style="display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 12px; padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
        <label style="font-size: 0.6875rem; color: var(--text-muted);">Search<br>
            <input type="text" name="q" value="{{ $f['q'] }}" placeholder="File, agency, publisher, uploader" style="{{ $input }} min-width: 230px;">
        </label>
        <label style="font-size: 0.6875rem; color: var(--text-muted);">Status<br>
            <select name="status" style="{{ $input }}">
                <option value="open" @selected($f['status'] === 'open')>Open</option>
                <option value="resolved" @selected($f['status'] === 'resolved')>Resolved</option>
                <option value="all" @selected($f['status'] === 'all')>All</option>
            </select>
        </label>
        <label style="font-size: 0.6875rem; color: var(--text-muted);">How it was read<br>
            <select name="how" style="{{ $input }}">
                <option value="all" @selected($f['how'] === 'all')>All</option>
                <option value="ai" @selected($f['how'] === 'ai')>Read by AI</option>
                <option value="unread" @selected($f['how'] === 'unread')>Could not be read</option>
                <option value="held" @selected($f['how'] === 'held')>Held – AI limit / layout already queued</option>
            </select>
        </label>
        <label style="font-size: 0.6875rem; color: var(--text-muted);">Agency<br>
            <select name="agency" style="{{ $input }}">
                <option value="">All agencies</option>
                @foreach($agencies as $a)
                    <option value="{{ $a->id }}" @selected($f['agency'] === (string) $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </label>
        <label style="font-size: 0.6875rem; color: var(--text-muted);">Publisher<br>
            <select name="publisher" style="{{ $input }}">
                <option value="">All publishers</option>
                @foreach($publishers as $p)
                    <option value="{{ $p }}" @selected($f['publisher'] === $p)>{{ $p }}</option>
                @endforeach
            </select>
        </label>
        <label style="font-size: 0.6875rem; color: var(--text-muted);">Uploaded from<br>
            <input type="date" name="from" value="{{ $f['from'] }}" style="{{ $input }}">
        </label>
        <label style="font-size: 0.6875rem; color: var(--text-muted);">to<br>
            <input type="date" name="to" value="{{ $f['to'] }}" style="{{ $input }}">
        </label>
        <input type="hidden" name="sort" value="{{ $f['sort'] }}"><input type="hidden" name="dir" value="{{ $f['dir'] }}">
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
        <a href="{{ route('market-intelligence.reports.recogniser.queue') }}" class="corex-btn-outline text-xs">Clear</a>
    </form>

    @if($reports->isEmpty())
        <div style="padding: 24px; text-align: center; background: var(--surface); border: 1px dashed var(--border); border-radius: 6px;">
            <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0;">
                @if($focus !== null)
                    This report is no longer in the queue (it has since been read by a known format or a learned layout).
                    <a href="{{ route('market-intelligence.reports.show', $focus) }}" style="color: var(--brand-button);">Open the report</a>
                @elseif($f['q'] !== '' || $f['agency'] !== '' || $f['publisher'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['how'] !== 'all' || $f['status'] !== 'open')
                    No reports match these filters.
                @else
                    Nothing to look at — every uploaded report was read by a known format or a learned layout.
                @endif
            </p>
        </div>
    @else
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="{{ $th }}"><a href="{{ $sortUrl('file') }}" style="color: inherit; text-decoration: none;">File{{ $arrow('file') }}</a></th>
                        <th style="{{ $th }}"><a href="{{ $sortUrl('agency') }}" style="color: inherit; text-decoration: none;">Agency{{ $arrow('agency') }}</a></th>
                        <th style="{{ $th }}"><a href="{{ $sortUrl('publisher') }}" style="color: inherit; text-decoration: none;">Publisher{{ $arrow('publisher') }}</a></th>
                        <th style="{{ $th }}">How read</th>
                        <th style="text-align: right; padding: 8px 12px;"><a href="{{ $sortUrl('confidence') }}" style="color: inherit; text-decoration: none;">Confidence{{ $arrow('confidence') }}</a></th>
                        <th style="text-align: right; padding: 8px 12px;"><a href="{{ $sortUrl('figures') }}" style="color: inherit; text-decoration: none;">Figures{{ $arrow('figures') }}</a></th>
                        <th style="{{ $th }}"><a href="{{ $sortUrl('uploaded') }}" style="color: inherit; text-decoration: none;">Uploaded{{ $arrow('uploaded') }}</a></th>
                        <th style="{{ $th }}">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $r)
                        @php($layout = $layoutByReport->get($r->id))
                        <tr id="report-{{ $r->id }}" style="border-top: 1px solid var(--border); {{ $r->resolved_at ? 'opacity: 0.65;' : '' }} {{ $focus === (int) $r->id ? 'background: color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent);' : '' }}">
                            <td style="padding: 8px 12px; max-width: 260px;">
                                <a href="{{ route('market-intelligence.reports.show', $r->id) }}" style="color: var(--brand-button); text-decoration: none; word-break: break-word;">{{ $r->file_name }}</a>
                                @if($r->ai_read_note)
                                    <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">{{ $r->ai_read_note }}</div>
                                @endif
                                @if($r->layout_case_id)
                                    <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">Layout case #{{ $r->layout_case_id }} · {{ $r->case_reports }} report{{ (int) $r->case_reports === 1 ? '' : 's' }} in this layout</div>
                                @endif
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $r->agency_name ?? '—' }}</td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $r->read_publisher ?? '—' }}</td>
                            <td style="padding: 8px 12px; font-weight: 600; color: {{ $r->read_method === 'ai' ? 'var(--ds-amber, #d97706)' : 'var(--ds-crimson, #dc2626)' }};">
                                {{ $r->read_method === 'ai' ? 'Read by AI' : ($r->heldLabel() ?? 'Could not be read') }}
                                @if($r->resolved_at)<span style="font-weight: 400; color: var(--text-muted);"> · resolved</span>@endif
                            </td>
                            <td style="padding: 8px 12px; text-align: right;">{{ $r->ai_read_confidence !== null ? number_format((float) $r->ai_read_confidence, 2) : '—' }}</td>
                            <td style="padding: 8px 12px; text-align: right;">{{ number_format($r->data_points_count) }}</td>
                            <td style="padding: 8px 12px; color: var(--text-muted); font-size: 0.75rem;">
                                {{ $r->created_at?->format('j M Y H:i') }}<br>{{ $r->uploader_name ?? '—' }}
                            </td>
                            <td style="padding: 8px 12px; white-space: nowrap;">
                                @if($r->read_method === 'ai')
                                    <a href="{{ route('market-intelligence.reports.recogniser.figures', $r->id) }}" class="corex-btn-primary text-xs">View figures</a>
                                    @if($layout && !$layout->trashed() && $layout->status === 'learned')
                                        <form method="POST" action="{{ route('market-intelligence.reports.recogniser.layouts.approve', $layout->id) }}" style="display: inline-block; margin-left: 4px;"
                                              onsubmit="return confirm('Approve this layout? Its queued reports are re-run on it and their agents are told.');">
                                            @csrf <button type="submit" class="corex-btn-outline text-xs" title="Same action as Approve on Learned layouts">Approve layout</button>
                                        </form>
                                    @elseif($layout && !$layout->trashed())
                                        <span style="font-size: 0.6875rem; color: var(--ds-green, #10b981); margin-left: 4px;">Layout approved</span>
                                    @elseif($layout)
                                        <span style="font-size: 0.6875rem; color: var(--text-muted); margin-left: 4px;">Layout retired</span>
                                    @else
                                        <span style="font-size: 0.6875rem; color: var(--text-muted); margin-left: 4px;" title="{{ $r->ai_read_note }}">No layout saved</span>
                                    @endif
                                    <br>
                                @endif
                                <a href="{{ route('market-intelligence.reports.recogniser.download', $r->id) }}" class="corex-btn-outline text-xs" style="margin-top: 4px; display: inline-block;">Download</a>
                                <form method="POST" action="{{ route('market-intelligence.reports.recogniser.rerun', $r->id) }}" style="display: inline-block; margin-left: 4px;"
                                      onsubmit="return confirm('Re-run this report through the whole reading pipeline?');">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs">Re-run</button>
                                    <label style="font-size: 0.625rem; color: var(--text-muted);" title="Pays for a brand-new AI read instead of using the saved answer for this file">
                                        <input type="checkbox" name="force_new_ai" value="1"> new AI read
                                    </label>
                                </form>
                                @if($r->resolved_at)
                                    <form method="POST" action="{{ route('market-intelligence.reports.recogniser.reopen', $r->id) }}" style="display: inline-block; margin-left: 4px;">
                                        @csrf <button type="submit" class="corex-btn-outline text-xs">Reopen</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('market-intelligence.reports.recogniser.resolve', $r->id) }}" style="display: inline-block; margin-left: 4px;">
                                        @csrf <button type="submit" class="corex-btn-primary text-xs">Mark resolved</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding: 12px 4px;">{{ $reports->links() }}</div>
    @endif
</div>
@endsection
