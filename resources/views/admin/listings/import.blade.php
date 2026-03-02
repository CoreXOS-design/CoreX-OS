@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Import Listings (Propcon XLSX)</h2>
        <div class="text-sm text-white/60">Upload the Propcon export as-is. We store the file locally and apply updates into listing stock.</div>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-100">
            <div class="font-semibold mb-1">Import problem</div>
            <ul class="list-disc pl-5 text-sm space-y-1">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ds-status-card p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="ds-section-header">Upload XLSX</h3>
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">We will upsert into listing stock using the Code/Reference fields. Manual pricing fields will be preserved in a later phase.</div>
            </div>
        </div>

        <form method="post" action="{{ route('admin.listings.import.store') }}" enctype="multipart/form-data" class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end">
            @csrf
            <div class="flex-1">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Propcon XLSX file</label>
                <input type="file" name="file" accept=".xlsx" required
                       class="block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-slate-900 file:text-white hover:file:bg-slate-800 dark:file:bg-white dark:file:text-slate-900 dark:hover:file:bg-slate-100
                              rounded-lg border border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 px-3 py-2" />
            </div>
            <button class="corex-btn-primary">
                Import
            </button>
        </form>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800">
            <h3 class="ds-section-header">Recent import runs</h3>
            <div class="text-xs text-slate-500 dark:text-slate-400">Audit trail from listing_import_runs</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-300">
                    <tr>
                        <th class="text-left px-4 py-3">ID</th>
                        <th class="text-left px-4 py-3">When</th>
                        <th class="text-left px-4 py-3">Filename</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Error</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($runs as $r)
                        <tr>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">#{{ $r->id }}</td>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-200">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-slate-900 dark:text-slate-100">{{ $r->original_filename }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border
                                    @if($r->status === 'applied') border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-100
                                    @elseif($r->status === 'failed') border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-100
                                    @else border-slate-200 bg-slate-50 text-slate-800 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-200
                                    @endif
                                ">
                                    {{ $r->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                {{ $r->error_message }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-6 text-center text-slate-500 dark:text-slate-400" colspan="5">No imports yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
