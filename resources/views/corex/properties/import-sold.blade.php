@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-5xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Import Sold Properties</h1>
                <p class="text-sm text-white/60">Bulk-import a spreadsheet of sold listings into the Property register.</p>
            </div>
            <a href="{{ route('corex.properties.index') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold"
               style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);">
                Back to Properties
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:rgba(16,185,129,0.12);color:#10b981;border:1px solid rgba(16,185,129,0.3);">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background:rgba(239,68,68,0.12);color:#ef4444;border:1px solid rgba(239,68,68,0.3);">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- ───────────── Step 1: Upload ───────────── --}}
    @if ($stage === 'upload')
        <div class="rounded-md p-6" style="background:var(--surface);border:1px solid var(--border);">
            <form method="POST" action="{{ route('corex.properties.import-sold.preview') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Spreadsheet (.xlsx)</label>
                    <input type="file" name="file" accept=".xlsx,.xls" required
                           class="block w-full text-sm rounded-md px-3 py-2"
                           style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-primary);">
                    <p class="text-xs mt-2" style="color:var(--text-muted);">
                        Each row becomes a <strong>Sold</strong> property. The <strong>Primary Photo</strong> column is used as the
                        listing image, and the <strong>Agents</strong> column is matched against CoreX users. You'll review and
                        confirm the agent for every listing before anything is created.
                    </p>
                </div>
                <button type="submit" class="corex-btn-primary inline-flex items-center gap-2">Continue to review</button>
            </form>
        </div>
    @endif

    {{-- ───────────── Step 2: Review & assign ───────────── --}}
    @if ($stage === 'review')
        @php $unmatched = collect($rows)->whereNull('matched_agent_id')->count(); @endphp
        <form method="POST" action="{{ route('corex.properties.import-sold.run') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="rounded-md px-4 py-3 text-sm" style="background:var(--surface-2);color:var(--text-primary);border:1px solid var(--border);">
                <strong>{{ count($rows) }}</strong> listings parsed.
                @if ($unmatched > 0)
                    <span style="color:#f59e0b;"><strong>{{ $unmatched }}</strong> have no matched agent — choose one for each (highlighted) before importing.</span>
                @else
                    All agents auto-matched. Review and confirm.
                @endif
            </div>

            <div class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="background:var(--surface-2);color:var(--text-muted);">
                            <th class="text-left px-3 py-2 font-semibold">#</th>
                            <th class="text-left px-3 py-2 font-semibold">Listing</th>
                            <th class="text-left px-3 py-2 font-semibold">Suburb</th>
                            <th class="text-right px-3 py-2 font-semibold">Price</th>
                            <th class="text-left px-3 py-2 font-semibold">From file</th>
                            <th class="text-left px-3 py-2 font-semibold">Assigned agent</th>
                            <th class="text-center px-3 py-2 font-semibold">Photo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr style="border-top:1px solid var(--border); {{ $r['matched_agent_id'] ? '' : 'background:rgba(245,158,11,0.08);' }}">
                                <td class="px-3 py-2" style="color:var(--text-muted);">{{ $loop->iteration }}</td>
                                <td class="px-3 py-2" style="color:var(--text-primary);">{{ $r['title'] }}</td>
                                <td class="px-3 py-2" style="color:var(--text-muted);">{{ $r['suburb'] }}</td>
                                <td class="px-3 py-2 text-right" style="color:var(--text-primary);">{{ $r['price'] ? 'R ' . number_format($r['price']) : '—' }}</td>
                                <td class="px-3 py-2" style="color:var(--text-muted);">{{ $r['agents_text'] ?: '—' }}</td>
                                <td class="px-3 py-2">
                                    <select name="agents[{{ $r['row'] }}]" required
                                            class="w-full text-sm rounded-md px-2 py-1.5"
                                            style="background:var(--surface-2);border:1px solid {{ $r['matched_agent_id'] ? 'var(--border)' : '#f59e0b' }};color:var(--text-primary);">
                                        <option value="">— Select agent —</option>
                                        @foreach ($agents as $a)
                                            <option value="{{ $a->id }}" @selected($r['matched_agent_id'] === $a->id)>{{ $a->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    @if ($r['has_image'])
                                        <span title="Primary photo found" style="color:#10b981;">●</span>
                                    @else
                                        <span title="No photo" style="color:var(--text-muted);">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary inline-flex items-center gap-2">Import {{ count($rows) }} sold properties</button>
                <a href="{{ route('corex.properties.import-sold') }}" class="corex-btn-outline inline-flex text-sm">Cancel</a>
            </div>
        </form>
    @endif

    {{-- ───────────── Step 3: Result ───────────── --}}
    @if ($stage === 'done' && !empty($result))
        <div class="rounded-md p-6 space-y-4" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-base font-bold" style="color:var(--text-primary);">Import Result</h2>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-md px-4 py-3" style="background:var(--surface-2);">
                    <div class="text-2xl font-bold" style="color:var(--brand-icon,#0ea5e9);">{{ $result['created'] }}</div>
                    <div style="color:var(--text-muted);">Properties created</div>
                </div>
                <div class="rounded-md px-4 py-3" style="background:var(--surface-2);">
                    <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ $result['rows'] }}</div>
                    <div style="color:var(--text-muted);">Data rows read</div>
                </div>
            </div>

            @if (!empty($result['issues']))
                <div>
                    <h3 class="text-sm font-semibold mb-1" style="color:#ef4444;">Row issues ({{ count($result['issues']) }})</h3>
                    <ul class="text-xs space-y-1" style="color:var(--text-primary);">
                        @foreach ($result['issues'] as $issue)
                            <li>• {{ $issue }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center gap-2">
                <a href="{{ route('corex.properties.index', ['status' => 'sold']) }}" class="corex-btn-primary inline-flex text-sm">View imported sold properties</a>
                <a href="{{ route('corex.properties.import-sold') }}" class="corex-btn-outline inline-flex text-sm">Import another file</a>
            </div>
        </div>
    @endif
</div>
@endsection
