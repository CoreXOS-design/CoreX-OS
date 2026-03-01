@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page header --}}
    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Settings</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">System configuration and preferences.</div>
    </div>

    @if(session('success'))
        <div style="border-radius:12px; border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; padding:12px 16px; font-size:0.875rem; font-weight:500;">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- General --}}
        <div style="background:#0d1f35; border:1px solid rgba(255,255,255,0.07); border-radius:16px; padding:20px 24px;">
            <h3 style="font-size:1rem; font-weight:700; color:#fff; border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 20px;">General</h3>
            <div style="display:flex; flex-direction:column; gap:16px;">
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:600; color:rgba(255,255,255,0.45); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">Application Name</label>
                    <input type="text" value="{{ config('app.name') }}" disabled
                           style="width:100%; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.04); color:rgba(255,255,255,0.5); padding:8px 12px; font-size:0.875rem; cursor:not-allowed; box-sizing:border-box;">
                    <p style="font-size:0.7rem; color:rgba(255,255,255,0.3); margin-top:4px;">Configured in environment settings.</p>
                </div>
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:600; color:rgba(255,255,255,0.45); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">Environment</label>
                    <span style="display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; {{ config('app.env') === 'production' ? 'background:#fef2f2; color:#991b1b;' : 'background:#f0fdf4; color:#166534;' }}">
                        {{ config('app.env') }}
                    </span>
                </div>
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:600; color:rgba(255,255,255,0.45); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">Debug Mode</label>
                    <span style="display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; {{ config('app.debug') ? 'background:#fffbeb; color:#92400e;' : 'background:#f0fdf4; color:#166534;' }}">
                        {{ config('app.debug') ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Quick Links --}}
        <div style="background:#0d1f35; border:1px solid rgba(255,255,255,0.07); border-radius:16px; padding:20px 24px;">
            <h3 style="font-size:1rem; font-weight:700; color:#fff; border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 16px;">Quick Links</h3>
            <div style="display:flex; flex-direction:column; gap:4px;">

                <a href="{{ route('nexus.role-manager') }}" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:10px; text-decoration:none; transition:background 0.12s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                    <div style="width:36px; height:36px; border-radius:9px; background:rgba(0,180,216,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    </div>
                    <div>
                        <div style="font-size:0.875rem; font-weight:600; color:#fff;">Role &amp; Permissions Manager</div>
                        <div style="font-size:0.75rem; color:rgba(255,255,255,0.4);">Manage role-based access and user roles</div>
                    </div>
                </a>

                <a href="{{ route('admin.users') }}" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:10px; text-decoration:none; transition:background 0.12s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                    <div style="width:36px; height:36px; border-radius:9px; background:rgba(34,197,94,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#22c55e" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                    </div>
                    <div>
                        <div style="font-size:0.875rem; font-weight:600; color:#fff;">User Management</div>
                        <div style="font-size:0.75rem; color:rgba(255,255,255,0.4);">Activate, deactivate, or remove users</div>
                    </div>
                </a>

                <a href="{{ route('admin.designations.index') }}" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:10px; text-decoration:none; transition:background 0.12s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                    <div style="width:36px; height:36px; border-radius:9px; background:rgba(245,158,11,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#f59e0b" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" /></svg>
                    </div>
                    <div>
                        <div style="font-size:0.875rem; font-weight:600; color:#fff;">Designations</div>
                        <div style="font-size:0.75rem; color:rgba(255,255,255,0.4);">Manage user designation types</div>
                    </div>
                </a>

                <a href="{{ route('admin.branch-assignments') }}" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:10px; text-decoration:none; transition:background 0.12s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                    <div style="width:36px; height:36px; border-radius:9px; background:rgba(14,165,233,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#0ea5e9" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" /></svg>
                    </div>
                    <div>
                        <div style="font-size:0.875rem; font-weight:600; color:#fff;">Branch Assignments</div>
                        <div style="font-size:0.75rem; color:rgba(255,255,255,0.4);">Manage branches and user assignments</div>
                    </div>
                </a>

                <a href="{{ route('admin.p24-suburbs.index') }}" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:10px; text-decoration:none; transition:background 0.12s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                    <div style="width:36px; height:36px; border-radius:9px; background:rgba(0,180,216,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                    </div>
                    <div>
                        <div style="font-size:0.875rem; font-weight:600; color:#fff;">P24 Suburbs</div>
                        <div style="font-size:0.75rem; color:rgba(255,255,255,0.4);">Manage Property24 suburb mappings</div>
                    </div>
                </a>

                @if(auth()->user()?->isSuperAdmin())
                <a href="{{ route('agencies.index') }}" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:10px; text-decoration:none; transition:background 0.12s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">
                    <div style="width:36px; height:36px; border-radius:9px; background:rgba(99,102,241,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#818cf8" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    </div>
                    <div>
                        <div style="font-size:0.875rem; font-weight:600; color:#fff;">Agency Management</div>
                        <div style="font-size:0.75rem; color:rgba(255,255,255,0.4);">Create and manage agencies on the platform</div>
                    </div>
                </a>
                @endif

            </div>
        </div>

        {{-- System Information --}}
        <div style="background:#0d1f35; border:1px solid rgba(255,255,255,0.07); border-radius:16px; padding:20px 24px;" class="lg:col-span-2">
            <h3 style="font-size:1rem; font-weight:700; color:#fff; border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 20px;">System Information</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:16px;">
                    <div style="font-size:0.7rem; font-weight:700; color:rgba(255,255,255,0.35); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:6px;">Laravel Version</div>
                    <div style="font-size:1.5rem; font-weight:700; color:#fff;">{{ app()->version() }}</div>
                </div>

                <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:16px;">
                    <div style="font-size:0.7rem; font-weight:700; color:rgba(255,255,255,0.35); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:6px;">PHP Version</div>
                    <div style="font-size:1.5rem; font-weight:700; color:#fff;">{{ PHP_VERSION }}</div>
                </div>

                <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:16px;">
                    <div style="font-size:0.7rem; font-weight:700; color:rgba(255,255,255,0.35); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:6px;">Database</div>
                    <div style="font-size:1.5rem; font-weight:700; color:#fff;">{{ config('database.default') }}</div>
                </div>

                <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:12px; padding:16px;">
                    <div style="font-size:0.7rem; font-weight:700; color:rgba(255,255,255,0.35); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:6px;">Total Users</div>
                    <div style="font-size:1.5rem; font-weight:700; color:#fff;">{{ \App\Models\User::count() }}</div>
                </div>

            </div>
        </div>

    </div>
</div>
@endsection
