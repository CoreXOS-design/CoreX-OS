{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agency Management</h1>
                <p class="text-sm text-white/60">Create and manage all agencies on the platform.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('agencies.create') }}" class="corex-btn-primary">+ New Agency</a>
            </div>
        </div>
    </div>

    {{-- Summary KPIs --}}
    @if($agencies->isNotEmpty())
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total agencies" :value="number_format($agencies->count())" />
        <x-corex-kpi-card title="Active" :value="number_format($agencies->where('is_active', true)->count())" />
        <x-corex-kpi-card title="Branches" :value="number_format($agencies->sum('branches_count'))" />
        <x-corex-kpi-card title="Users" :value="number_format($agencies->sum('users_count'))" />
    </div>
    @endif

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif

    {{-- Agencies table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agency</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Slug</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branches</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Users</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Brand Colours</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($agencies as $agency)
                        <tr>
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $agency->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">{{ $agency->slug }}</td>
                            <td class="px-4 py-3 text-center" style="color: var(--text-secondary);">{{ number_format($agency->branches_count) }}</td>
                            <td class="px-4 py-3 text-center" style="color: var(--text-secondary);">{{ number_format($agency->users_count) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-block w-5 h-5 rounded-md"
                                          style="background: {{ $agency->sidebar_color }}; border: 1px solid var(--border);"
                                          title="Sidebar: {{ $agency->sidebar_color }}"></span>
                                    <span class="inline-block w-5 h-5 rounded-md"
                                          style="background: {{ $agency->icon_color }}; border: 1px solid var(--border);"
                                          title="Icons: {{ $agency->icon_color }}"></span>
                                    <span class="inline-block w-5 h-5 rounded-md"
                                          style="background: {{ $agency->default_color }}; border: 1px solid var(--border);"
                                          title="Default: {{ $agency->default_color }}"></span>
                                    <span class="inline-block w-5 h-5 rounded-md"
                                          style="background: {{ $agency->button_color }}; border: 1px solid var(--border);"
                                          title="Button: {{ $agency->button_color }}"></span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($agency->is_active)
                                    <span class="ds-badge ds-badge-success">Active</span>
                                @else
                                    <span class="ds-badge ds-badge-default">Inactive</span>
                                @endif
                                @if($agency->isInMaintenance())
                                    <span class="ds-badge ds-badge-warning"
                                          title="Only System Owners can access this agency. Users see the maintenance screen after login.@if($agency->maintenance_started_at) Since {{ $agency->maintenance_started_at->diffForHumans() }}.@endif">
                                        Maintenance
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('agencies.edit', $agency) }}"
                                       class="text-xs font-semibold" style="color: var(--brand-icon);">
                                        Edit
                                    </a>
                                    <form method="POST" action="{{ route('agencies.toggle-active', $agency) }}"
                                          onsubmit="return confirm('{{ $agency->is_active ? 'Disable' : 'Enable' }} agency &quot;{{ $agency->name }}&quot;? @if($agency->is_active)Users in this agency will not be able to sign in until it is re-enabled.@endif');"
                                          class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold"
                                                style="color: {{ $agency->is_active ? 'var(--ds-amber)' : 'var(--ds-green)' }};">
                                            {{ $agency->is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>
                                    {{-- Per-agency maintenance toggle (AT-93). Login stays up;
                                         only this agency's users see the maintenance screen. --}}
                                    <form method="POST" action="{{ route('agencies.toggle-maintenance', $agency) }}"
                                          class="inline"
                                          onsubmit="
                                              @if($agency->isInMaintenance())
                                              return confirm('Take agency &quot;{{ $agency->name }}&quot; OUT of maintenance? Its users will be able to sign in and work normally again.');
                                              @else
                                              if (!confirm('Put agency &quot;{{ $agency->name }}&quot; INTO maintenance? Only System Owners will be able to access it; all of its users will see the maintenance screen after login. Login stays up for every other agency.')) return false;
                                              var msg = prompt('Optional message to show this agency\'s users (leave blank for the default):', '');
                                              if (msg === null) return false;
                                              this.querySelector('input[name=maintenance_message]').value = msg;
                                              return true;
                                              @endif
                                          ">
                                        @csrf
                                        <input type="hidden" name="maintenance_message" value="">
                                        <button type="submit" class="text-xs font-semibold"
                                                style="color: {{ $agency->isInMaintenance() ? 'var(--ds-green)' : 'var(--ds-amber)' }};">
                                            {{ $agency->isInMaintenance() ? 'End maintenance' : 'Maintenance' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('agencies.destroy', $agency) }}"
                                          class="inline"
                                          onsubmit="
                                              if (!confirm('PERMANENTLY delete agency &quot;{{ $agency->name }}&quot;? This HARD-DELETES every user, branch, property, contact, deal, presentation and document in this agency. This cannot be undone.')) return false;
                                              var pw = prompt('Type the delete password to confirm:');
                                              if (pw === null) return false;
                                              this.querySelector('input[name=delete_password]').value = pw;
                                              return true;
                                          ">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="delete_password" value="">
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No agencies yet.
                                <a href="{{ route('agencies.create') }}" class="font-semibold" style="color: var(--brand-icon);">Create the first one.</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
