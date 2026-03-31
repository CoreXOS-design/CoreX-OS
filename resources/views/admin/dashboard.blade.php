<x-app-layout>
    <div class="max-w-7xl mx-auto p-6 space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white">Admin Control Centre</h1>
                <p class="text-sm text-white/70">Quick access to admin tools and setup.</p>
            </div>

            <a href="{{ route('admin.deals') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/10 hover:bg-white/15 text-white border border-white/10">
                <span>Open Deal Register</span>
                <span class="text-white/50">→</span>
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <a href="{{ route('admin.deals') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Money</div>
                <div class="mt-1 text-lg font-semibold text-white">Deal Register</div>
                <div class="mt-2 text-sm text-white/60">Capture & settle deals, track commission status.</div>
            </a>

            <a href="{{ route('admin.performance') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Performance</div>
                <div class="mt-1 text-lg font-semibold text-white">Company Performance</div>
                <div class="mt-2 text-sm text-white/60">View company-wide performance dashboards.</div>
            </a>

            <a href="{{ route('admin.targets') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Targets</div>
                <div class="mt-1 text-lg font-semibold text-white">Targets</div>
                <div class="mt-2 text-sm text-white/60">Review and adjust targets across users/branches.</div>
            </a>

            <a href="{{ route('admin.targets.activity.definitions') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Daily Activity</div>
                <div class="mt-1 text-lg font-semibold text-white">Activity Definitions</div>
                <div class="mt-2 text-sm text-white/60">Manage activities (global/branch), enable/disable.</div>
            </a>

            <a href="{{ route('admin.targets.activity.setup') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Daily Activity</div>
                <div class="mt-1 text-lg font-semibold text-white">Activity Setup</div>
                <div class="mt-2 text-sm text-white/60">Configure columns/weights per branch.</div>
            </a>

            <a href="{{ route('admin.branch-assignments') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Org</div>
                <div class="mt-1 text-lg font-semibold text-white">Branch Assignments</div>
                <div class="mt-2 text-sm text-white/60">Assign users to branches and manage branches.</div>
            </a>

            <a href="{{ route('admin.users') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Users</div>
                <div class="mt-1 text-lg font-semibold text-white">User Management</div>
                <div class="mt-2 text-sm text-white/60">Roles, status, defaults, and toggles.</div>
            </a>

            <a href="{{ route('admin.performance-settings.edit') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Settings</div>
                <div class="mt-1 text-lg font-semibold text-white">Company Settings</div>
                <div class="mt-2 text-sm text-white/60">Configure performance rules and defaults.</div>
            </a>

            <a href="{{ route('admin.monthly-goals') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Goals</div>
                <div class="mt-1 text-lg font-semibold text-white">Monthly Goals</div>
                <div class="mt-2 text-sm text-white/60">Company/branch goals for a selected period.</div>
            </a>

            <a href="{{ route('admin.listing-targets') }}" class="block rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 p-5">
                <div class="text-sm text-white/60">Listings</div>
                <div class="mt-1 text-lg font-semibold text-white">Listing Targets</div>
                <div class="mt-2 text-sm text-white/60">Manage listing targets and tracking.</div>
            </a>
        </div>

        {{-- System Health (super_admin / owner only) --}}
        @if(auth()->user()?->isOwnerRole() || auth()->user()?->effectiveRole() === 'super_admin')
        @php $faultNewCount = \App\Models\FaultReport::where('status','new')->count(); @endphp
        <a href="{{ route('admin.fault-reports') }}" class="block rounded-2xl border p-5 {{ $faultNewCount > 0 ? 'border-red-500/30 bg-red-500/5 hover:bg-red-500/10' : 'border-emerald-500/30 bg-emerald-500/5 hover:bg-emerald-500/10' }}">
            <div class="flex items-center gap-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center {{ $faultNewCount > 0 ? 'bg-red-500/20' : 'bg-emerald-500/20' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 {{ $faultNewCount > 0 ? 'text-red-400' : 'text-emerald-400' }}">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                </div>
                <div>
                    <div class="text-sm text-white/60">System Health</div>
                    @if($faultNewCount > 0)
                    <div class="text-lg font-semibold text-red-400">{{ $faultNewCount }} new {{ Str::plural('error', $faultNewCount) }}</div>
                    @else
                    <div class="text-lg font-semibold text-emerald-400">All clear</div>
                    @endif
                </div>
            </div>
        </a>
        @endif

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="text-sm text-white/60">Legacy</div>
                    <div class="text-lg font-semibold text-white">Cashflow Dashboard</div>
                    <div class="mt-1 text-sm text-white/60">
                        The older worksheet-based cashflow view is still available for now.
                    </div>
                </div>

                <a href="{{ route('admin.dashboard', ['view' => 'cashflow']) }}"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/10 hover:bg-white/15 text-white border border-white/10">
                    <span>Open Cashflow View</span>
                    <span class="text-white/50">→</span>
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
