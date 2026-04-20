@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Select an Agency" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-3xl mx-auto">
            {{-- Info --}}
            <div class="mb-5 text-sm" style="color:#64748b; line-height:1.6;">
                You are logged in as a platform-level user without a direct agency assignment. Select which agency you want to work with for this session. You can change this anytime using the agency switcher in the sidebar.
            </div>

            @if(session('intended_after_agency_select'))
            <div class="mb-4 px-4 py-2 text-xs" style="background:rgba(0,212,170,0.06); border:1px solid rgba(0,212,170,0.2); border-radius:3px; color:#64748b;">
                You will be redirected after selecting.
            </div>
            @endif

            @if(session('info'))
            <div class="mb-4 px-4 py-2 text-xs font-semibold" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.2); border-radius:3px; color:#ca8a04;">
                {{ session('info') }}
            </div>
            @endif

            {{-- Search (if many agencies) --}}
            @if($agencies->count() > 10)
            <div class="mb-4" x-data="{ search: '' }">
                <input type="text" x-model="search" placeholder="Search agencies..."
                       class="w-full max-w-xs px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px; font-family:'Plus Jakarta Sans',sans-serif;">
            </div>
            @endif

            {{-- Agency cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" x-data="{ search: '' }">
                @foreach($agencies as $ag)
                <div class="bg-white border p-4 flex flex-col" style="border-color:var(--border, #e5e7eb); border-radius:3px;"
                     x-show="!search || '{{ strtolower($ag->name) }}'.includes(search.toLowerCase())">
                    <div class="flex items-center gap-3 mb-3">
                        @if($ag->logo_path)
                        <img src="{{ Storage::url($ag->logo_path) }}" alt="" class="w-10 h-10 rounded object-cover" style="background:#f8fafc;">
                        @else
                        <div class="w-10 h-10 rounded flex items-center justify-center text-sm font-bold" style="background:rgba(0,212,170,0.1); color:#00d4aa;">
                            {{ strtoupper(substr($ag->name, 0, 2)) }}
                        </div>
                        @endif
                        <div class="min-w-0">
                            <div class="text-sm font-bold truncate" style="color:#0f172a; font-family:'Plus Jakarta Sans',sans-serif;">{{ $ag->name }}</div>
                            @if($ag->trading_name && $ag->trading_name !== $ag->name)
                            <div class="text-xs truncate" style="color:#64748b;">{{ $ag->trading_name }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="text-xs mb-3" style="color:#94a3b8;">
                        @php
                            $branchCount = $ag->branches()->count();
                            $userCount = \App\Models\User::where('agency_id', $ag->id)->where('is_active', true)->count();
                        @endphp
                        {{ $branchCount }} {{ Str::plural('branch', $branchCount) }} / {{ $userCount }} {{ Str::plural('user', $userCount) }}
                    </div>
                    <form method="POST" action="{{ route('agency.select.submit', $ag) }}" class="mt-auto">
                        @csrf
                        <button type="submit" class="w-full py-2 text-xs font-semibold transition" style="background:#00d4aa; color:#0f172a; border-radius:3px; border:none; cursor:pointer;">
                            Select
                        </button>
                    </form>
                </div>
                @endforeach
            </div>

            @if($agencies->isEmpty())
            <div class="text-center py-8 text-sm" style="color:#94a3b8;">No agencies found.</div>
            @endif
        </div>
    </div>
</div>
@endsection
