{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Import Listings (Propcon XLSX)</h1>
                <p class="text-sm text-white/60">Upload the Propcon export as-is. We store the file locally and apply updates into listing stock.</p>
            </div>
        </div>
    </div>

    {{-- Success message --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--ds-green, #059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- Error messages --}}
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--ds-crimson, #c41e3a);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">
                <strong class="block mb-1">Import problem</strong>
                <ul class="list-disc pl-5 space-y-1">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- Upload Card --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Upload XLSX</h3>
            <p class="text-xs mt-1" style="color: var(--text-muted);">We will upsert into listing stock using the Code/Reference fields. Manual pricing fields will be preserved in a later phase.</p>
        </div>

        <form method="post" action="{{ route('admin.listings.import.store') }}" enctype="multipart/form-data" class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end">
            @csrf
            <div class="flex-1">
                <label for="file" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Propcon XLSX file</label>
                <input id="file" type="file" name="file" accept=".xlsx" required
                       class="block w-full text-sm rounded-md px-3 py-2 transition-all duration-300
                              file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold
                              file:cursor-pointer file:text-white file:bg-[color:var(--brand-button)] hover:file:brightness-110"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
            </div>
            <button type="submit" class="corex-btn-primary">Import</button>
        </form>
    </div>

    {{-- Recent Import Runs --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Recent import runs</div>
            <div class="text-xs" style="color: var(--text-muted);">Audit trail from listing_import_runs</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">ID</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">When</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Filename</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Error</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($runs as $r)
                        <tr>
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">#{{ $r->id }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3" style="color: var(--text-primary);">{{ $r->original_filename }}</td>
                            <td class="px-4 py-3">
                                @if($r->status === 'applied')
                                    <span class="ds-badge ds-badge-success">{{ ucfirst($r->status) }}</span>
                                @elseif($r->status === 'failed')
                                    <span class="ds-badge ds-badge-danger">{{ ucfirst($r->status) }}</span>
                                @else
                                    <span class="ds-badge ds-badge-default">{{ ucfirst($r->status) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                {{ $r->error_message }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);" colspan="5">No imports yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
