{{-- Client App Access panel — Spec: .ai/specs/client-auth.md --}}
@php
    $clientUser = $contact->clientUser;
    $canCreate  = auth()->user()->hasPermission('client_app.create_login');
    $canReset   = auth()->user()->hasPermission('client_app.reset_password');
    $canForce   = auth()->user()->hasPermission('client_app.force_logout');
    $canRemove  = auth()->user()->hasPermission('client_app.remove_access');
    $canViewLogs= auth()->user()->hasPermission('client_app.view_logs');
    $logs = $canViewLogs && $clientUser
        ? \App\Models\ClientAccessLog::where('client_user_id', $clientUser->id)
            ->where(function($q) use ($contact) {
                $q->whereNull('contact_id')->orWhere('contact_id', $contact->id);
            })
            ->latest()->limit(20)->get()
        : collect();
@endphp

<div class="rounded-md" style="background: var(--surface-2); border: 1px solid var(--border);"
     x-data="{
        open: {{ $errors->any() || session('client_login_success') ? 'true' : 'false' }},
        showCreate: false,
        showReset: false,
        suggestedEmail: @js(old('client_login_email', $clientUser?->email ?? null)),
        suggestedPassword: ''
     }">

    {{-- Collapsible header (always visible) --}}
    <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between px-5 py-3 text-left hover:opacity-90 transition-opacity"
            :aria-expanded="open">
        <div class="flex items-center gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform"
                 :style="open ? 'transform: rotate(90deg); color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-muted);'"
                 fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
            </svg>
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Client App Access</h3>
                <p class="text-xs mt-0.5" style="color:var(--text-muted);">
                    @if($clientUser)
                        {{ $clientUser->email }}
                        @if($clientUser->password_must_change)
                            <span class="ds-badge ds-badge-warning ml-1">Must Change</span>
                        @elseif($clientUser->hasPassword())
                            <span class="ds-badge ds-badge-success ml-1">Active</span>
                        @else
                            <span class="ds-badge ml-1">Pending OTP</span>
                        @endif
                    @else
                        Not configured
                    @endif
                </p>
            </div>
        </div>
        @if(!$clientUser && $canCreate)
            <span @click.stop="showCreate = true; open = true"
                  class="corex-btn-primary text-xs px-3 py-1.5 cursor-pointer">
                Create Client Login
            </span>
        @endif
    </button>

    {{-- Collapsible body --}}
    <div x-show="open" x-cloak class="px-5 pb-5 pt-1" x-transition>

    @if($clientUser)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mb-4">
            <div class="rounded-md p-3" style="background:var(--surface);">
                <div class="text-[11px] uppercase font-semibold tracking-wider mb-1" style="color:var(--text-muted);">Login Email</div>
                <div style="color:var(--text-primary);">{{ $clientUser->email }}</div>
            </div>
            <div class="rounded-md p-3" style="background:var(--surface);">
                <div class="text-[11px] uppercase font-semibold tracking-wider mb-1" style="color:var(--text-muted);">Activated</div>
                <div style="color:var(--text-primary);">
                    {{ $clientUser->activated_at?->format('d M Y') ?? '—' }}
                </div>
            </div>
            <div class="rounded-md p-3" style="background:var(--surface);">
                <div class="text-[11px] uppercase font-semibold tracking-wider mb-1" style="color:var(--text-muted);">Last Login</div>
                <div style="color:var(--text-primary);">
                    {{ $clientUser->last_login_at?->format('d M Y H:i') ?? '—' }}
                </div>
            </div>
            <div class="rounded-md p-3" style="background:var(--surface);">
                <div class="text-[11px] uppercase font-semibold tracking-wider mb-1" style="color:var(--text-muted);">Password</div>
                <div style="color:var(--text-primary);">
                    @if($clientUser->password_must_change)
                        <span class="ds-badge ds-badge-warning">Must Change</span>
                    @elseif($clientUser->hasPassword())
                        <span class="ds-badge ds-badge-success">Set</span>
                    @else
                        <span class="ds-badge">Not Set</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mb-4">
            @if($canReset)
                <button type="button" class="corex-btn-secondary text-sm" @click="showReset = true">Reset Password</button>
            @endif
            @if($canForce)
                <form method="POST" action="{{ route('corex.contacts.client-login.force-logout', $contact) }}" class="inline">
                    @csrf
                    <button type="submit" class="corex-btn-secondary text-sm"
                            onclick="return confirm('Revoke all active client devices for this contact?')">
                        Force Logout All Devices
                    </button>
                </form>
            @endif
            @if($canRemove)
                <form method="POST" action="{{ route('corex.contacts.client-login.remove', $contact) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-sm"
                            style="color:#dc2626;"
                            onclick="return confirm('Remove client app access? Activity logs are preserved.')">
                        Remove Client Access
                    </button>
                </form>
            @endif
        </div>

        @if($canViewLogs && $logs->count())
            <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                <div class="text-xs font-bold uppercase tracking-widest px-3 py-2" style="background:var(--surface); color:var(--text-muted);">Recent Activity</div>
                <div class="text-sm divide-y" style="background:var(--surface); border-color:var(--border);">
                    @foreach($logs as $log)
                        <div class="px-3 py-2 flex items-center justify-between" style="border-color:var(--border);">
                            <div>
                                <span class="font-mono text-xs" style="color:var(--text-secondary);">{{ $log->event }}</span>
                                @if($log->ip)<span class="ml-2 text-xs" style="color:var(--text-muted);">{{ $log->ip }}</span>@endif
                            </div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ $log->created_at->diffForHumans() }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Reset Password Modal --}}
        <div x-show="showReset" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,0.6);" @keydown.escape.window="showReset = false">
            <div class="rounded-md p-6 w-full max-w-md" style="background:var(--surface);">
                <h3 class="text-lg font-bold mb-3" style="color:var(--text-primary);">Reset Client Password</h3>
                <p class="text-sm mb-4" style="color:var(--text-secondary);">
                    Generate a temporary password. The client will be required to change it on next sign-in.
                </p>
                <form method="POST" action="{{ route('corex.contacts.client-login.reset', $contact) }}">
                    @csrf
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Temporary Password</label>
                    <input name="password" required minlength="8" class="corex-input w-full mb-4" placeholder="At least 8 characters">
                    <div class="flex justify-end gap-2">
                        <button type="button" class="corex-btn-secondary text-sm" @click="showReset = false">Cancel</button>
                        <button type="submit" class="corex-btn-primary text-sm">Reset & Force Change</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <div class="text-sm" style="color:var(--text-muted);">No client app login configured for this contact. Use the button above to create one.</div>
    @endif

    </div>{{-- /collapsible body --}}

    {{-- Create Modal --}}
    <div x-show="showCreate" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,0.6);" @keydown.escape.window="showCreate = false">
        <div class="rounded-md p-6 w-full max-w-md" style="background:var(--surface);">
            <h3 class="text-lg font-bold mb-3" style="color:var(--text-primary);">Create Client App Login</h3>
            <p class="text-sm mb-4" style="color:var(--text-secondary);">
                Email may be a real address (client sets their own password via OTP) or auto-generated under
                <code>@corexclient.co.za</code> (you set a temp password here, client must change on first sign-in).
            </p>
            <form method="POST" action="{{ route('corex.contacts.client-login.create', $contact) }}">
                @csrf
                <label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Login Email</label>
                <input name="email" type="email" required value="{{ old('email', $contact->email ?: app(\App\Services\ClientAuthService::class)->generateFakeLoginEmail($contact)) }}" class="corex-input w-full mb-4">

                <label class="block text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Temporary Password (optional)</label>
                <input name="password" type="text" minlength="8" class="corex-input w-full mb-1" placeholder="Leave blank to send OTP instead">
                <p class="text-xs mb-4" style="color:var(--text-muted);">If set, the client signs in with this password and must change it. If blank, they'll receive an activation OTP via email.</p>

                <div class="flex justify-end gap-2">
                    <button type="button" class="corex-btn-secondary text-sm" @click="showCreate = false">Cancel</button>
                    <button type="submit" class="corex-btn-primary text-sm">Create Login</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if(session('client_login_success'))
    <div class="rounded-md p-3 text-sm" style="background:#dcfce7; color:#166534;">
        {{ session('client_login_success') }}
    </div>
@endif
@if($errors->any())
    <div class="rounded-md p-3 text-sm" style="background:#fee2e2; color:#991b1b;">
        @foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach
    </div>
@endif
