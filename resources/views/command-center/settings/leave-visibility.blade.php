@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Leave Visibility Matrix</h1>
        <a href="{{ route('command-center.settings') }}" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Back to Settings</a>
    </div>

    {{-- Relationship to Role Manager --}}
    <div class="flex items-start gap-3 px-4 py-3 rounded-lg" style="background: var(--surface-2); border: 1px solid var(--border);">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color: #00d4aa;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
        </svg>
        <div class="text-xs" style="color: var(--text-secondary);">
            <p>This matrix controls which roles can see whose leave entries on the calendar. Access to the leave calendar feature itself is controlled by Role Manager. When the two combine, the most restrictive rule wins.</p>
            <a href="{{ route('corex.role-manager') }}" class="inline-block mt-1.5 font-medium hover:underline" style="color: #00d4aa;">Configure role permissions in Role Manager &rarr;</a>
        </div>
    </div>

    @if(session('success'))
        <div class="px-4 py-3 rounded-lg text-sm font-medium" style="background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.2);">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('command-center.settings.leave-visibility.update') }}">
        @csrf @method('PUT')

        <div class="corex-panel">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Who Can See Whose Leave</h3>
            </div>
            <div class="corex-panel-body">
                <p class="text-xs mb-4" style="color:var(--text-muted);">Configure which roles can view leave records of other roles. Each cell has two options: same-branch visibility and cross-branch (agency-wide) visibility. Own leave is always visible via creator bypass.</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b" style="border-color:var(--border-default);">
                                <th class="text-left py-3 px-3 text-xs font-medium" style="color:var(--text-muted);">Viewing Role ↓ / Owner →</th>
                                @foreach($roles as $ownerRole)
                                    <th class="text-center py-3 px-3 text-xs font-medium capitalize" style="color:var(--text-muted);">
                                        {{ str_replace('_', ' ', $ownerRole) }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($roles as $viewingRole)
                                <tr class="border-b" style="border-color:var(--border-default);">
                                    <td class="py-3 px-3 text-sm font-medium capitalize" style="color:var(--text-primary);">
                                        {{ str_replace('_', ' ', $viewingRole) }}
                                    </td>
                                    @foreach($roles as $ownerRole)
                                        <td class="py-3 px-3 text-center">
                                            <div class="flex flex-col items-center gap-1.5">
                                                <label class="flex items-center gap-1 text-[11px]" style="color:var(--text-muted);">
                                                    <input type="checkbox"
                                                           name="matrix[{{ $viewingRole }}][{{ $ownerRole }}][same_branch]"
                                                           value="1"
                                                           {{ ($grid[$viewingRole][$ownerRole]['same_branch'] ?? false) ? 'checked' : '' }}>
                                                    Branch
                                                </label>
                                                <label class="flex items-center gap-1 text-[11px]" style="color:var(--text-muted);">
                                                    <input type="checkbox"
                                                           name="matrix[{{ $viewingRole }}][{{ $ownerRole }}][cross_branch]"
                                                           value="1"
                                                           {{ ($grid[$viewingRole][$ownerRole]['cross_branch'] ?? false) ? 'checked' : '' }}>
                                                    All
                                                </label>
                                            </div>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-start gap-2 p-3 rounded-lg" style="background:var(--surface-2);">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                    </svg>
                    <div class="text-xs" style="color:var(--text-muted);">
                        <p><strong>Branch</strong> = can see leave for users in the same branch only.</p>
                        <p><strong>All</strong> = can see leave across the entire agency.</p>
                        <p class="mt-1">Users can always see their own leave regardless of this matrix.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end mt-6">
            <button type="submit" class="px-5 py-2.5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button);">
                Save Matrix
            </button>
        </div>
    </form>
</div>
@endsection
