@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14.19 — the dry-run preview. Nothing in
    `rows` has been written yet; Confirm below is the only action that does.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Import preview — {{ $source_filename }}</h1>
        <a href="{{ route('corex.rental-catalogue-items.import.index') }}" class="text-xs underline" style="color: var(--text-muted);">&larr; Upload a different file</a>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="flex flex-wrap gap-3 text-sm">
        <span class="ds-badge ds-badge-muted">{{ $counts['total'] }} row(s)</span>
        <span class="ds-badge ds-badge-info">{{ $counts['create'] }} new</span>
        <span class="ds-badge" style="background: #eff6ff; color: #1d4ed8;">{{ $counts['update'] }} will update</span>
        <span class="ds-badge ds-badge-muted">{{ $counts['skip'] }} will skip (already exist)</span>
        @if($counts['error'] > 0)
            <span class="ds-badge" style="background: #fef2f2; color: #991b1b;">{{ $counts['error'] }} have errors — will be skipped</span>
        @endif
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-3 py-2 font-medium">Row</th>
                    <th class="text-left px-3 py-2 font-medium">Code</th>
                    <th class="text-left px-3 py-2 font-medium">Description</th>
                    <th class="text-left px-3 py-2 font-medium">Type</th>
                    <th class="text-left px-3 py-2 font-medium">Unit</th>
                    <th class="text-left px-3 py-2 font-medium">VAT type</th>
                    <th class="text-right px-3 py-2 font-medium">Price (excl)</th>
                    @if(!empty($with_cost))<th class="text-right px-3 py-2 font-medium">Cost (excl)</th>@endif
                    <th class="text-left px-3 py-2 font-medium">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-3 py-2" style="color: var(--text-muted);">{{ $row['row_number'] }}</td>
                        <td class="px-3 py-2" style="font-family: monospace;">{{ $row['code'] }}</td>
                        <td class="px-3 py-2">{{ $row['description'] }}</td>
                        <td class="px-3 py-2">{{ $row['type_name'] ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $row['unit_name'] ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $row['vat_type_name'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['resolved']['default_price'] !== null ? 'R' . number_format($row['resolved']['default_price'], 2) : '—' }}</td>
                        @if(!empty($with_cost))<td class="px-3 py-2 text-right">{{ ($row['resolved']['default_cost'] ?? null) !== null ? 'R' . number_format($row['resolved']['default_cost'], 2) : '—' }}</td>@endif
                        <td class="px-3 py-2">
                            @if($row['action'] === 'create')
                                <span class="ds-badge ds-badge-info">New</span>
                            @elseif($row['action'] === 'update')
                                <span class="ds-badge" style="background: #eff6ff; color: #1d4ed8;">Will update</span>
                            @elseif($row['action'] === 'skip')
                                <span class="ds-badge ds-badge-muted">Already exists — skip</span>
                            @else
                                <span class="ds-badge" style="background: #fef2f2; color: #991b1b;">Error</span>
                                <ul class="text-xs mt-1" style="color: #991b1b;">
                                    @foreach($row['errors'] as $msg)
                                        <li>{{ $msg }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <form method="POST" action="{{ route('corex.rental-catalogue-items.import.confirm', $token) }}" data-confirm="Import {{ $counts['create'] }} new item(s) and update {{ $counts['update'] }} existing item(s)?" data-confirm-label="Import">
        @csrf
        <button type="submit" class="corex-btn-primary text-sm" @disabled($counts['create'] === 0 && $counts['update'] === 0)>
            Confirm import ({{ $counts['create'] + $counts['update'] }} row(s))
        </button>
    </form>
</div>
@endsection
