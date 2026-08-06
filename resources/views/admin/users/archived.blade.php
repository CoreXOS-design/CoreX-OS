{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    Archived Agents — AT-278.
    Spec: .ai/specs/agent-seat-release-lock.md §6.1

    Before this page there was NO way back: "reinstating" an agent meant
    re-creating them, which hard-deleted the archived record and orphaned their
    deals, QR code and audit trail. This is the restore door, and the 30-day
    seat hold is enforced ON it — never as a dead button, always with the date
    and the way forward on the control itself (STANDARDS: No Silent Locks).
--}}
@extends('layouts.corex')

@section('corex-content')

<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Archived Agents</h1>
                <p class="text-sm text-white/60">
                    {{ number_format($users->count()) }} archived —
                    restore an agent to give them back their login and their billable seat.
                </p>
            </div>
            <a href="{{ route('admin.users') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
               style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.18);">
                &larr; Back to Users
            </a>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Why the hold exists — stated once, plainly, so the rule is never a mystery --}}
    <div class="rounded-md px-4 py-3 text-sm"
         style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-secondary);">
        An archived agent stops being billed straight away. To keep the monthly bill honest, that
        person cannot occupy a seat again for <strong>{{ $lockDays }} days</strong> — so agents
        cannot be removed to lower a bill and added back afterwards. If an agent was archived by
        mistake, contact CoreX support: a System Owner can lift the hold immediately.
    </div>

    @if($users->isEmpty())
        <div class="rounded-md px-6 py-10 text-center text-sm"
             style="background: var(--surface-1); border:1px solid var(--border); color: var(--text-secondary);">
            No archived agents. Everyone on this agency is active.
        </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface-1); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-secondary);">
                        <th class="text-left font-semibold px-4 py-3">Agent</th>
                        <th class="text-left font-semibold px-4 py-3">Role</th>
                        <th class="text-left font-semibold px-4 py-3">Archived</th>
                        <th class="text-left font-semibold px-4 py-3">Seat hold</th>
                        <th class="text-right font-semibold px-4 py-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($users as $u)
                    @php
                        $release  = $releases[$u->id] ?? null;
                        $blocking = $release && $release->isBlocking();
                    @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3">
                            <div class="font-semibold" style="color: var(--text-primary);">{{ $u->name }}</div>
                            <div class="text-xs" style="color: var(--text-secondary);">{{ $u->email }}</div>
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">
                            {{ ucwords(str_replace('_', ' ', $u->role ?? '—')) }}
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">
                            {{ optional($u->deleted_at)->format('j M Y') ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($blocking)
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-xs font-semibold"
                                      style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);"
                                      title="A released seat is held for {{ $lockDays }} days before that person can occupy a seat again.">
                                    Held until {{ $release->reinstatable_at->format('j M Y') }}
                                    ({{ $release->remainingDays() }} {{ \Illuminate\Support\Str::plural('day', $release->remainingDays()) }})
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-xs font-semibold"
                                      style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);">
                                    Eligible to restore
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if(! $blocking)
                                <form method="POST" action="{{ route('admin.users.restore', $u->id) }}" class="inline"
                                      onsubmit="return confirm('Restore {{ addslashes($u->name) }}? They will regain their login and occupy a billable seat again.');">
                                    @csrf
                                    <button type="submit" class="corex-btn-primary text-xs">Restore</button>
                                </form>
                            @elseif($canOverride)
                                {{-- System Owner only. The button is absent for everyone
                                     else — a dead button is worse than no button — and the
                                     server re-checks the real role regardless. --}}
                                <div x-data="{ open: false, reason: '' }" class="inline-block">
                                    <button type="button" @click="open = true" class="corex-btn-primary text-xs">
                                        Override &amp; restore now
                                    </button>

                                    <div x-show="open" x-cloak
                                         class="fixed inset-0 z-50 flex items-center justify-center px-4"
                                         style="background: rgba(0,0,0,0.6);"
                                         @keydown.escape.window="open = false">
                                        <div class="w-full max-w-lg rounded-md p-5 text-left"
                                             style="background: var(--surface-1); border:1px solid var(--border);"
                                             @click.outside="open = false">
                                            <h3 class="text-base font-bold mb-2" style="color: var(--text-primary);">
                                                Lift the seat hold on {{ $u->name }}
                                            </h3>
                                            <p class="text-sm mb-3" style="color: var(--text-secondary);">
                                                This hold runs until
                                                <strong>{{ $release->reinstatable_at->format('j F Y') }}</strong>.
                                                Lifting it early is recorded permanently against this agency.
                                            </p>
                                            <form method="POST" action="{{ route('admin.users.restore', $u->id) }}">
                                                @csrf
                                                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                                                    Reason (required, at least 10 characters)
                                                </label>
                                                <textarea name="override_reason" x-model="reason" rows="3" required minlength="10"
                                                          placeholder="e.g. Archived in error — agent never resigned, confirmed with the principal."
                                                          class="w-full rounded-md px-3 py-2 text-sm"
                                                          style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);"></textarea>
                                                <div class="flex items-center justify-end gap-2 mt-4">
                                                    <button type="button" @click="open = false"
                                                            class="px-3 py-1.5 rounded-md text-xs font-semibold"
                                                            style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="corex-btn-primary text-xs"
                                                            :disabled="reason.trim().length < 10">
                                                        Lift hold &amp; restore
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @else
                                {{-- Locked, and this admin cannot lift it. Say why and say
                                     what to do — never a silent refusal. --}}
                                <span class="text-xs" style="color: var(--text-secondary);"
                                      title="Only a CoreX System Owner can lift a seat hold early.">
                                    Restore available {{ $release->reinstatable_at->format('j M Y') }} —
                                    contact CoreX support if archived in error
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>
@endsection
