@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Settings</h2>
        <div class="text-sm text-white/60">System configuration and preferences.</div>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="ds-status-card p-5">
            <h3 class="ds-section-header mb-4">General</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Application Name</label>
                    <input type="text" value="{{ config('app.name') }}" disabled
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 px-3 py-2 text-sm cursor-not-allowed">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Configured in environment settings.</p>
                </div>
                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Environment</label>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ config('app.env') === 'production' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800' }}">
                        {{ config('app.env') }}
                    </span>
                </div>
                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Debug Mode</label>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ config('app.debug') ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                        {{ config('app.debug') ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
            </div>
        </div>

        <div class="ds-status-card p-5">
            <h3 class="ds-section-header mb-4">Quick Links</h3>
            <div class="space-y-2">
                <a href="{{ route('nexus.role-manager') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-900/30 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-sky-50 dark:bg-sky-900/20 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-[#00b4d8]">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 group-hover:text-[#00b4d8]">Role &amp; Permissions Manager</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Manage role-based access and user roles</div>
                    </div>
                </a>
                <a href="{{ route('admin.users') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-900/30 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-emerald-600">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 group-hover:text-emerald-600">User Management</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Activate, deactivate, or remove users</div>
                    </div>
                </a>
                <a href="{{ route('admin.designations.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-900/30 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-amber-600">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 group-hover:text-amber-600">Designations</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Manage user designation types</div>
                    </div>
                </a>
                <a href="{{ route('admin.branch-assignments') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-900/30 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-sky-50 dark:bg-sky-900/20 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-sky-600">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 group-hover:text-sky-600">Branch Assignments</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Manage branches and user assignments</div>
                    </div>
                </a>
                <a href="{{ route('admin.p24-suburbs.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-900/30 transition-colors group">
                    <div class="w-9 h-9 rounded-lg bg-sky-50 dark:bg-sky-900/20 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-[#00b4d8]">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 group-hover:text-[#00b4d8]">P24 Suburbs</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Manage Property24 suburb mappings</div>
                    </div>
                </a>
                @if(auth()->user()?->isSuperAdmin())
                <a href="{{ route('agencies.index') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-900/30 transition-colors group">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:#e8f0fe;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#0b2a4a" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100 group-hover:text-[#0b2a4a]">Agency Management</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Create and manage agencies on the platform</div>
                    </div>
                </a>
                @endif
            </div>
        </div>

        <div class="ds-status-card p-5 lg:col-span-2">
            <h3 class="ds-section-header mb-4">System Information</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="ds-status-card">
                    <div class="ds-label">Laravel Version</div>
                    <div class="ds-value-lg">{{ app()->version() }}</div>
                </div>
                <div class="ds-status-card">
                    <div class="ds-label">PHP Version</div>
                    <div class="ds-value-lg">{{ PHP_VERSION }}</div>
                </div>
                <div class="ds-status-card">
                    <div class="ds-label">Database</div>
                    <div class="ds-value-lg">{{ config('database.default') }}</div>
                </div>
                <div class="ds-status-card">
                    <div class="ds-label">Total Users</div>
                    <div class="ds-value-lg">{{ \App\Models\User::count() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
