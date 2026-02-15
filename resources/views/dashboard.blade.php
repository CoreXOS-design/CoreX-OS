<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard
            </h2>

            <div class="text-sm text-gray-600">
                {{ auth()->user()->name }}
                <span class="mx-2 text-gray-300">|</span>
                <span class="capitalize">{{ str_replace('_', ' ', auth()->user()->effectiveRole()) }}</span>

                @if(session('view_as_role'))
                    <span class="ml-2 text-xs text-blue-600">
                        (view-as: {{ session('view_as_role') }}{{ session('view_as_branch_id') ? ', branch ' . session('view_as_branch_id') : '' }})
                    </span>
                @endif
            </div>
        </div>
    </x-slot>

    @php
        $u = auth()->user();
        $role = strtolower(trim((string)($u?->effectiveRole() ?? ($u->role ?? ''))));
        $isAdmin = (bool)($u?->isEffectiveAdmin());
        $isBM = ($role === 'branch_manager');
        $isAgent = ($role === 'agent');
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-6">
                <p class="text-gray-600">
                    Quick access to the main areas of the system.
                </p>
            </div>

            {{-- Tiles: wrap automatically on half-screen via responsive grid --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">

                {{-- Always useful --}}
                <a href="{{ route('worksheet.index') }}"
                   class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-lg font-semibold text-gray-900">Worksheet</div>
                            <div class="mt-1 text-sm text-gray-600">Capture targets and income plan.</div>
                        </div>
                        <div class="text-2xl">🧾</div>
                    </div>
                </a>

                {{-- Agent --}}
                @if($isAgent)
                    <a href="{{ route('agent.dashboard') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Agent Dashboard</div>
                                <div class="mt-1 text-sm text-gray-600">Your performance overview.</div>
                            </div>
                            <div class="text-2xl">📈</div>
                        </div>
                    </a>

                    <a href="{{ route('agent.daily') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">My Daily Activity</div>
                                <div class="mt-1 text-sm text-gray-600">Capture today’s activity points.</div>
                            </div>
                            <div class="text-2xl">✅</div>
                        </div>
                    </a>
                @endif

                {{-- Branch Manager (match navigation.blade.php BM items) --}}
                @if($isBM)
                      <a href="{{ route('bm.my.dashboard') }}"
                         class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                          <div class="flex items-start justify-between">
                              <div>
                                  <div class="text-lg font-semibold text-gray-900">My Agent Dashboard</div>
                                  <div class="mt-1 text-sm text-gray-600">Your personal performance overview.</div>
                              </div>
                              <div class="text-2xl">📈</div>
                          </div>
                      </a>


                    

                      <a href="{{ route('bm.worksheet.market') }}"
                         class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                          <div class="flex items-start justify-between">
                              <div>
                                  <div class="text-lg font-semibold text-gray-900">Worksheet Market</div>
                                  <div class="mt-1 text-sm text-gray-600">Set branch market averages for worksheets.</div>
                              </div>
                              <div class="text-2xl">🧮</div>
                          </div>
                      </a>
<a href="{{ route('bm.performance') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Branch Performance</div>
                                <div class="mt-1 text-sm text-gray-600">Branch dashboard & targets.</div>
                            </div>
                            <div class="text-2xl">🏢</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.deals') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Deal Register</div>
                                <div class="mt-1 text-sm text-gray-600">Track deals, pipeline, and commission.</div>
                            </div>
                            <div class="text-2xl">📒</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.targets') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Targets</div>
                                <div class="mt-1 text-sm text-gray-600">View targets and progress.</div>
                            </div>
                            <div class="text-2xl">🎯</div>
                        </div>
                    </a>
                    <a href="{{ route('admin.targets.activity.setup') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Daily Activity Setup</div>
                                <div class="mt-1 text-sm text-gray-600">Manage activities & weights.</div>
                            </div>
                            <div class="text-2xl">🛠️</div>
                        </div>
                    </a>

                    @if($u?->branch_id)
                        <a href="{{ route('agent.daily') }}"
                           class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="text-lg font-semibold text-gray-900">Daily Activity Capture</div>
                                    <div class="mt-1 text-sm text-gray-600">Capture activity for your branch.</div>
                                </div>
                                <div class="text-2xl">✅</div>
                            </div>
                        </a>
                    @endif
                @endif

                {{-- Admin (match navigation admin items) --}}
                @if($isAdmin)
                    <a href="{{ route('admin.dashboard') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Admin Control Centre</div>
                                <div class="mt-1 text-sm text-gray-600">Quick access to admin tools.</div>
                            </div>
                            <div class="text-2xl">🧠</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.performance') }}"
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Performance</div>
                                <div class="mt-1 text-sm text-gray-600">Company performance rollups.</div>
                            </div>
                            <div class="text-2xl">📊</div>
                        </div>
                    </a>
                      @if(\Illuminate\Support\Facades\Route::has('admin.worksheet-market'))
                          <a href="{{ route('admin.worksheet-market') }}"
                             class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                              <div class="flex items-start justify-between">
                                  <div>
                                      <div class="text-lg font-semibold text-gray-900">Worksheet Market</div>
                                      <div class="mt-1 text-sm text-gray-600">Set market averages per branch/agent.</div>
                                  </div>
                                  <div class="text-2xl">🧮</div>
                              </div>
                          </a>
                      @endif


                    <a href="{{ route('admin.branch-assignments') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Branch Assignments</div>
                                <div class="mt-1 text-sm text-gray-600">Assign users to branches.</div>
                            </div>
                            <div class="text-2xl">🧩</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.users') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Users</div>
                                <div class="mt-1 text-sm text-gray-600">Manage users & roles.</div>
                            </div>
                            <div class="text-2xl">👥</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.deals') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Deal Register</div>
                                <div class="mt-1 text-sm text-gray-600">Manage deals and commission status.</div>
                            </div>
                            <div class="text-2xl">📒</div>
                        </div>
                    </a>

                    <a href="{{ route('admin.targets') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Targets</div>
                                <div class="mt-1 text-sm text-gray-600">Configure targets.</div>
                            </div>
                            <div class="text-2xl">🎯</div>
                        </div>
                    </a>

                      <a href="{{ route('admin.targets.activity.definitions') }}"
                         class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                          <div class="flex items-start justify-between">
                              <div>
                                  <div class="text-lg font-semibold text-gray-900">Activity Definitions</div>
                                  <div class="mt-1 text-sm text-gray-600">Manage activities (global/branch).</div>
                              </div>
                              <div class="text-2xl">🧾</div>
                          </div>
                      </a>


                    <a href="{{ route('admin.targets.activity.setup') }}"
                       class="bg-white border rounded-lg shadow-sm hover:shadow p-5 transition">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-lg font-semibold text-gray-900">Daily Activity Setup</div>
                                <div class="mt-1 text-sm text-gray-600">Definitions, weights, branch scopes.</div>
                            </div>
                            <div class="text-2xl">🛠️</div>
                        </div>
                    </a>
                @endif

            </div>
        </div>
    </div>
</x-app-layout>
