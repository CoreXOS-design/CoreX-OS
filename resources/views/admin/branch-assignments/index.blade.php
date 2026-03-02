@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Branch Assignments</h2>
        <div class="text-sm text-white/60">Manage branches and their per-branch settings.</div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    @if($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-100">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add / Delete Branches --}}
    <div class="ds-status-card p-4 space-y-4">
        <h3 class="ds-section-header">Add Branch</h3>

        <form method="POST" action="{{ route('admin.branches.store') }}" class="flex flex-wrap gap-3 items-end">
            @csrf
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" name="name" required>
            </div>
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Code</label>
                <input class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm" name="code" required>
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Add Branch</button>
        </form>

        <div class="pt-4 space-y-2">
            <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Existing Branches</h4>

            @foreach($branches as $branch)
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 dark:border-slate-800 pb-2">
                    <div class="font-medium text-slate-900 dark:text-slate-100">
                        {{ $branch->name }} <span class="text-slate-500 dark:text-slate-400">({{ $branch->code }})</span>
                    </div>

                    <form method="POST" action="{{ route('admin.branches.delete', $branch) }}"
                          onsubmit="return confirm('Delete this branch? This cannot be undone.');">
                        @csrf
                        <button class="text-red-600 text-sm font-semibold hover:text-red-700">Delete</button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Branch Settings --}}
    <div class="ds-status-card p-4 space-y-4">
        <div>
            <h3 class="ds-section-header">Branch Settings</h3>
            <div class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                These settings are stored per-branch (key/value). Later we can add more keys (logos, banking, templates, etc).
            </div>
        </div>

        <div class="space-y-4">
            @foreach($branches as $branch)
                @php
                    $bs = $branchSettingsByBranch[$branch->id] ?? [];
                @endphp

                <form method="POST" action="{{ route('admin.branch-settings.update', $branch) }}"
                      class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-4 space-y-3">
                    @csrf

                    <div class="flex items-center justify-between gap-4">
                        <div class="font-semibold text-slate-900 dark:text-slate-100">
                            {{ $branch->name }} <span class="text-slate-500 dark:text-slate-400">({{ $branch->code }})</span>
                        </div>
                        <button type="submit" class="corex-btn-primary text-sm">Save</button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Company Name</label>
                            <input class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                                   name="company_name"
                                   value="{{ $bs['company_name'] ?? '' }}"
                                   placeholder="e.g. Home Finders Coastal">
                        </div>

                        <div>
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">FFC</label>
                            <input class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                                   name="company_ffc"
                                   value="{{ $bs['company_ffc'] ?? '' }}"
                                   placeholder="e.g. 2023116041">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Address</label>
                            <input class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                                   name="company_address"
                                   value="{{ $bs['company_address'] ?? '' }}"
                                   placeholder="e.g. The Emporium Shop 5, Shelly Beach, Margate">
                        </div>

                        <div>
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Telephone</label>
                            <input class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                                   name="company_tel"
                                   value="{{ $bs['company_tel'] ?? '' }}"
                                   placeholder="e.g. (039) 315 0857">
                        </div>

                        <div class="text-xs text-slate-500 dark:text-slate-400 flex items-end">
                            Saved values will be used later for branch-level printing &amp; templates.
                        </div>
                    </div>
                </form>
            @endforeach
        </div>
    </div>

</div>
@endsection
