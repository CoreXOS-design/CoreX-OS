<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl leading-tight" style="color:var(--text-primary)">
                Dashboard
            </h2>

            <div class="text-sm" style="color:var(--text-secondary)">
                {{ auth()->user()->name }}
                <span class="mx-2" style="color:var(--border)">|</span>
                <span class="capitalize">{{ str_replace('_', ' ', auth()->user()->effectiveRole()) }}</span>

                @if(session('view_as_role'))
                    <span class="ml-2 text-xs" style="color:var(--brand-icon)">
                        (view-as: {{ session('view_as_role') }}{{ session('view_as_branch_id') ? ', branch ' . session('view_as_branch_id') : '' }})
                    </span>
                @endif
            </div>
        </div>
    </x-slot>

    @php
        $u = auth()->user();
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-6">
                <p style="color:var(--text-secondary)">
                    Quick access to the main areas of the system.
                </p>
            </div>

            {{-- Tiles: wrap automatically on half-screen via responsive grid --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">

                {{-- Always useful --}}
                <a href="{{ route('worksheet.index') }}"
                   class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-lg font-semibold" style="color:var(--text-primary)">Worksheet</div>
                            <div class="mt-1 text-sm" style="color:var(--text-secondary)">Capture targets and income plan.</div>
                        </div>
                        <div class="text-2xl">🧾</div>
                    </div>
                </a>

                {{-- Agent --}}
                @permission('view_own_stats')
                    <a href="{{ route('agent.dashboard') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Agent Dashboard</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Your performance overview.</div>
                            </div>
                            <div class="text-2xl">📈</div>
                        </div>
                    </a>

                    <a href="{{ route('agent.daily') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">My Daily Activity</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Capture today’s activity points.</div>
                            </div>
                            <div class="text-2xl">✅</div>
                        </div>
                    </a>
                @endpermission

                {{-- Branch Manager --}}
                @permission('view_branch_stats')
                      <a href="{{ route('bm.my.dashboard') }}"
                         class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                          <div class="flex items-start justify-between">
                              <div>
                                  <div class="text-lg font-semibold" style="color:var(--text-primary)">My Agent Dashboard</div>
                                  <div class="mt-1 text-sm" style="color:var(--text-secondary)">Your personal performance overview.</div>
                              </div>
                              <div class="text-2xl">📈</div>
                          </div>
                      </a>


                    

                      <a href="{{ route('bm.worksheet.market') }}"
                         class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                          <div class="flex items-start justify-between">
                              <div>
                                  <div class="text-lg font-semibold" style="color:var(--text-primary)">Worksheet Market</div>
                                  <div class="mt-1 text-sm" style="color:var(--text-secondary)">Set branch market averages for worksheets.</div>
                              </div>
                              <div class="text-2xl">🧮</div>
                          </div>
                      </a>
<a href="{{ route('bm.performance') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Branch Performance</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Branch dashboard & targets.</div>
                            </div>
                            <div class="text-2xl">🏢</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.deals') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Deal Register</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Track deals, pipeline, and commission.</div>
                            </div>
                            <div class="text-2xl">📒</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.targets') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Targets</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">View targets and progress.</div>
                            </div>
                            <div class="text-2xl">🎯</div>
                        </div>
                    </a>
                    <a href="{{ route('admin.targets.activity.setup') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Daily Activity Setup</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Manage activities & weights.</div>
                            </div>
                            <div class="text-2xl">🛠️</div>
                        </div>
                    </a>

                    @if($u?->branch_id)
                        <a href="{{ route('agent.daily') }}"
                           class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="text-lg font-semibold" style="color:var(--text-primary)">Daily Activity Capture</div>
                                    <div class="mt-1 text-sm" style="color:var(--text-secondary)">Capture activity for your branch.</div>
                                </div>
                                <div class="text-2xl">✅</div>
                            </div>
                        </a>
                    @endif
                @endpermission

                {{-- Admin --}}
                @permission('view_company_stats')
                    <a href="{{ route('admin.dashboard') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Admin Control Centre</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Quick access to admin tools.</div>
                            </div>
                            <div class="text-2xl">🧠</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.performance') }}"
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Performance</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Company performance rollups.</div>
                            </div>
                            <div class="text-2xl">📊</div>
                        </div>
                    </a>
                      @if(\Illuminate\Support\Facades\Route::has('admin.worksheet-market'))
                          <a href="{{ route('admin.worksheet-market') }}"
                             class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                              <div class="flex items-start justify-between">
                                  <div>
                                      <div class="text-lg font-semibold" style="color:var(--text-primary)">Worksheet Market</div>
                                      <div class="mt-1 text-sm" style="color:var(--text-secondary)">Set market averages per branch/agent.</div>
                                  </div>
                                  <div class="text-2xl">🧮</div>
                              </div>
                          </a>
                      @endif


                    <a href="{{ route('admin.branch-assignments') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Branch Assignments</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Assign users to branches.</div>
                            </div>
                            <div class="text-2xl">🧩</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.users') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Users</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Manage users & roles.</div>
                            </div>
                            <div class="text-2xl">👥</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.deals') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Deal Register</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Manage deals and commission status.</div>
                            </div>
                            <div class="text-2xl">📒</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.targets') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Targets</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Configure targets.</div>
                            </div>
                            <div class="text-2xl">🎯</div>
                        </div>
                    </a>

                      <a href="{{ route('admin.targets.activity.definitions') }}"
                         class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                          <div class="flex items-start justify-between">
                              <div>
                                  <div class="text-lg font-semibold" style="color:var(--text-primary)">Activity Definitions</div>
                                  <div class="mt-1 text-sm" style="color:var(--text-secondary)">Manage activities (global/branch).</div>
                              </div>
                              <div class="text-2xl">🧾</div>
                          </div>
                      </a>


                    <a href="{{ route('admin.targets.activity.setup') }}"
                       class="border rounded-lg shadow-sm hover:shadow p-5 transition" style="background:var(--surface); border-color:var(--border)">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold" style="color:var(--text-primary)">Daily Activity Setup</div>
                                <div class="mt-1 text-sm" style="color:var(--text-secondary)">Definitions, weights, branch scopes.</div>
                            </div>
                            <div class="text-2xl">🛠️</div>
                        </div>
                    </a>
                @endpermission

            </div>
        </div>
    </div>
</x-app-layout>
