@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">

    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('command-center.settings') }}" class="text-xs" style="color:var(--text-muted);">← Back to Settings</a>
            <h1 class="text-xl font-bold mt-1" style="color:var(--text-primary);">Event Classes</h1>
            <p class="text-sm mt-1" style="color:var(--text-muted);">
                Each class controls when calendar events transition between green/amber/red,
                who sees them, and what notifications fire. Changes apply for your agency.
            </p>
        </div>
    </div>

    @if(session('success'))
        <div class="px-4 py-3 rounded-md text-sm" style="background:rgba(20,184,166,0.1); color:#14b8a6; border:1px solid rgba(20,184,166,0.3);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="px-4 py-3 rounded-md text-sm" style="background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.3);">
            {{ session('error') }}
        </div>
    @endif

    <div x-data="{ openClass: null }" class="space-y-1">
        @foreach($effective as $row)
            @php
                $cfg = $row['config'];
                $overridden = $row['is_overridden'];
                $cls = $cfg->event_class;
            @endphp
            <div class="corex-panel" style="margin-bottom:0;">
                <button type="button"
                        @click="openClass = openClass === '{{ $cls }}' ? null : '{{ $cls }}'"
                        class="w-full flex items-center justify-between px-4 py-3 hover:opacity-90 transition text-left">
                    <div class="flex items-center gap-3">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $cfg->is_active ? 'var(--brand-button)' : 'var(--text-muted)' }};"></span>
                        <div>
                            <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $cfg->label }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">
                                {{ $cls }}
                                @if($overridden)
                                    <span class="ml-2" style="color:#f59e0b;">&#x2022; custom</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-xs" style="color:var(--text-muted);">
                        <span style="color:#14b8a6;">{{ $cfg->green_days }}d</span>
                        <span style="color:#f59e0b;">{{ $cfg->amber_days }}d</span>
                        <span style="color:#ef4444;">{{ $cfg->red_days }}d</span>
                        <svg class="w-4 h-4 transition-transform" :class="openClass === '{{ $cls }}' ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                </button>

                <div x-show="openClass === '{{ $cls }}'" x-cloak class="border-t" style="border-color:var(--border-default);">
                    <form method="POST" action="{{ route('command-center.settings.event-classes.update', $cls) }}"
                          class="px-4 py-4 space-y-4">
                        @csrf
                        @method('PUT')

                        @if($cfg->description)
                            <p class="text-xs" style="color:var(--text-muted);">{{ $cfg->description }}</p>
                        @endif

                        {{-- Active toggle --}}
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1"
                                   {{ $cfg->is_active ? 'checked' : '' }}
                                   class="rounded">
                            <span class="text-sm" style="color:var(--text-primary);">Class active</span>
                        </label>

                        {{-- Thresholds --}}
                        <div class="grid grid-cols-4 gap-3">
                            @foreach([
                                ['green_days', 'Green (days)', '#14b8a6'],
                                ['amber_days', 'Amber (days)', '#f59e0b'],
                                ['red_days', 'Red (days)', '#ef4444'],
                                ['show_days', 'Show window', null],
                            ] as [$field, $label, $colour])
                                <div>
                                    <label class="block text-xs mb-1" style="color:{{ $colour ?? 'var(--text-muted)' }};">{{ $label }}</label>
                                    <input type="number" name="{{ $field }}" value="{{ $cfg->$field }}"
                                           min="0" max="{{ $field === 'show_days' ? 730 : 365 }}"
                                           {{ $field !== 'show_days' ? 'required' : '' }}
                                           class="w-full px-2 py-1 rounded text-sm border"
                                           style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);"
                                           placeholder="{{ $field === 'show_days' ? 'always' : '' }}">
                                </div>
                            @endforeach
                        </div>
                        <p class="text-xs" style="color:var(--text-muted);">Order required: red &le; amber &le; green.</p>

                        {{-- Visibility per colour --}}
                        <div>
                            <h4 class="text-sm font-semibold mb-2" style="color:var(--text-primary);">Who sees events at each stage</h4>
                            <div class="grid grid-cols-3 gap-3 text-xs">
                                @foreach(['green' => '#14b8a6', 'amber' => '#f59e0b', 'red' => '#ef4444'] as $colour => $hex)
                                    <div class="rounded p-3 border" style="background:var(--surface-2); border-color:var(--border-default);">
                                        <div class="font-semibold mb-2 capitalize" style="color:{{ $hex }};">{{ $colour }}</div>
                                        @php
                                            $visibleRoles = $cfg->{$colour . '_visibility'} ?? [];
                                            // Alias map: legacy role names → current role names
                                            $roleAliases = ['bm' => 'branch_manager', 'branch_manager' => 'bm'];
                                        @endphp
                                        @foreach($availableRoles as $role)
                                            @php
                                                $isChecked = in_array($role, $visibleRoles)
                                                    || (isset($roleAliases[$role]) && in_array($roleAliases[$role], $visibleRoles));
                                            @endphp
                                            <label class="flex items-center gap-2 mb-1">
                                                <input type="checkbox"
                                                       name="{{ $colour }}_visibility[]"
                                                       value="{{ $role }}"
                                                       {{ $isChecked ? 'checked' : '' }}
                                                       class="rounded">
                                                <span style="color:var(--text-secondary);">{{ str_replace('_', ' ', ucfirst($role)) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Notification routing per colour --}}
                        <div>
                            <h4 class="text-sm font-semibold mb-2" style="color:var(--text-primary);">Notifications on transition</h4>
                            <div class="space-y-3">
                                @foreach(['green' => '#14b8a6', 'amber' => '#f59e0b', 'red' => '#ef4444'] as $colour => $hex)
                                    <div class="rounded p-3 border" style="background:var(--surface-2); border-color:var(--border-default);">
                                        <div class="font-semibold mb-2 capitalize text-xs" style="color:{{ $hex }};">{{ $colour }} stage</div>
                                        <table class="w-full text-xs">
                                            <thead>
                                                <tr style="color:var(--text-muted);">
                                                    <th class="text-left py-1">Role</th>
                                                    @foreach($availableChannels as $ch)
                                                        <th class="text-center py-1 w-16">{{ $ch }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @php $routing = $cfg->{$colour . '_notifications'} ?? []; @endphp
                                                @foreach($availableRoles as $role)
                                                    <tr class="border-t" style="border-color:var(--border-default);">
                                                        <td class="py-1" style="color:var(--text-secondary);">{{ $role }}</td>
                                                        @foreach($availableChannels as $ch)
                                                            <td class="py-1 text-center">
                                                                <input type="checkbox"
                                                                       name="{{ $colour }}_notifications[{{ $role }}][]"
                                                                       value="{{ $ch }}"
                                                                       {{ in_array($ch, $routing[$role] ?? []) ? 'checked' : '' }}
                                                                       class="rounded">
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Daily digest --}}
                        <div class="rounded p-3 border" style="background:var(--surface-2); border-color:var(--border-default);">
                            <h4 class="text-sm font-semibold mb-2" style="color:var(--text-primary);">Daily digest</h4>
                            <label class="flex items-center gap-2 mb-2">
                                <input type="hidden" name="daily_digest_enabled" value="0">
                                <input type="checkbox" name="daily_digest_enabled" value="1"
                                       {{ $cfg->daily_digest_enabled ? 'checked' : '' }}
                                       class="rounded">
                                <span class="text-sm" style="color:var(--text-primary);">Include in daily digest email</span>
                            </label>
                            <div class="text-xs mb-1" style="color:var(--text-muted);">Digest recipients (roles):</div>
                            <div class="flex flex-wrap gap-3">
                                @php $digestRoles = $cfg->daily_digest_roles ?? []; @endphp
                                @foreach($availableRoles as $role)
                                    <label class="flex items-center gap-1">
                                        <input type="checkbox" name="daily_digest_roles[]" value="{{ $role }}"
                                               {{ in_array($role, $digestRoles) ? 'checked' : '' }}
                                               class="rounded">
                                        <span class="text-xs" style="color:var(--text-secondary);">{{ $role }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Actions --}}
                        <div class="flex items-center justify-between pt-3 border-t" style="border-color:var(--border-default);">
                            <button type="submit"
                                    class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button);">
                                Save
                            </button>
                            @if($overridden)
                                <button type="button"
                                        onclick="if(confirm('Reset this class to global defaults?')) { document.getElementById('reset-{{ $cls }}').submit(); }"
                                        class="text-sm" style="color:var(--text-muted);">
                                    Reset to default
                                </button>
                            @endif
                        </div>
                    </form>

                    @if($overridden)
                        <form id="reset-{{ $cls }}" method="POST"
                              action="{{ route('command-center.settings.event-classes.reset', $cls) }}"
                              class="hidden">
                            @csrf
                            @method('DELETE')
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
