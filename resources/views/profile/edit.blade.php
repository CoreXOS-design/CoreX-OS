@extends('layouts.corex')

@section('corex-content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page header --}}
    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Profile</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Manage your account settings and API access.</div>
    </div>

    @if(session('status') === 'profile-updated')
        <div style="border-radius:12px; border:1px solid #bbf7d0; background:rgba(34,197,94,0.1); color:#22c55e; padding:12px 16px; font-size:0.875rem; font-weight:500;">
            Profile updated successfully.
        </div>
    @endif

    @if(session('status') === 'password-updated')
        <div style="border-radius:12px; border:1px solid #bbf7d0; background:rgba(34,197,94,0.1); color:#22c55e; padding:12px 16px; font-size:0.875rem; font-weight:500;">
            Password updated successfully.
        </div>
    @endif

    {{-- API Token --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px 24px;"
         x-data="{
            hasToken: {{ auth()->user()->api_token ? 'true' : 'false' }},
            plaintext: null,
            loading: false,
            copied: false,
            async generate() {
                this.loading = true;
                this.copied = false;
                try {
                    const res = await fetch('{{ route('corex.settings.generate-token') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json'
                        }
                    });
                    const data = await res.json();
                    this.plaintext = data.token;
                    this.hasToken = true;
                } finally {
                    this.loading = false;
                }
            },
            copyToken() {
                navigator.clipboard.writeText(this.plaintext);
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            }
         }">
        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 20px;">API Token</h3>

        <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 16px; line-height:1.5;">
            Used by the Portal Capture Chrome extension to authenticate with Nexus. Paste this token into the extension settings.
        </p>

        {{-- Token just generated — show plaintext --}}
        <template x-if="plaintext">
            <div>
                <div style="background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.3); border-radius:10px; padding:12px 16px; margin-bottom:14px;">
                    <div style="font-size:0.8rem; font-weight:700; color:#f59e0b; margin-bottom:4px;">Copy this token now — you won't be able to see it again.</div>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="text" readonly :value="plaintext"
                           style="flex:1; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8rem; font-family:monospace; box-sizing:border-box;">
                    <button @click="copyToken()"
                            style="padding:9px 16px; border-radius:8px; border:none; font-size:0.8rem; font-weight:600; cursor:pointer; white-space:nowrap;"
                            :style="copied ? 'background:#22c55e; color:#fff;' : 'background:#00b4d8; color:#fff;'">
                        <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                    </button>
                </div>
                <div style="margin-top:12px;">
                    <button @click="generate()" :disabled="loading"
                            style="padding:8px 16px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-secondary); font-size:0.8rem; font-weight:500; cursor:pointer;">
                        <span x-text="loading ? 'Generating...' : 'Regenerate Token'"></span>
                    </button>
                </div>
            </div>
        </template>

        {{-- No plaintext visible --}}
        <template x-if="!plaintext">
            <div>
                <template x-if="hasToken">
                    <div>
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                            <span style="display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; background:#dcfce7; color:#166534;">Token active</span>
                            <span style="font-size:0.8rem; color:var(--text-muted); font-family:monospace;">••••••••••••••••</span>
                        </div>
                        <button @click="generate()" :disabled="loading"
                                style="padding:9px 18px; border-radius:8px; border:none; background:#00b4d8; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">
                            <span x-text="loading ? 'Generating...' : 'Regenerate Token'"></span>
                        </button>
                        <p style="font-size:0.7rem; color:var(--text-muted); margin-top:8px;">This will invalidate your current token.</p>
                    </div>
                </template>
                <template x-if="!hasToken">
                    <div>
                        <button @click="generate()" :disabled="loading"
                                style="padding:9px 18px; border-radius:8px; border:none; background:#00b4d8; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">
                            <span x-text="loading ? 'Generating...' : 'Generate Token'"></span>
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Social Media Accounts --}}
    @if(\Illuminate\Support\Facades\Route::has('corex.social.oauth.redirect'))
    @php
        $fbSocial = $socialAccounts->firstWhere('platform', 'facebook');
        $igSocial = $socialAccounts->firstWhere('platform', 'instagram');
    @endphp
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px 24px;">
        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 6px;">Social Media Accounts</h3>
        <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 20px;">Connect your <strong>Facebook Page</strong> or Instagram Business account to publish property listings directly from CoreX. Facebook requires a Page (not a personal profile) — create one at facebook.com/pages/create if you don't have one yet.</p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">

            {{-- Facebook --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:12px; padding:16px;">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                    <div style="width:40px; height:40px; border-radius:10px; background:#1877f222; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1877f2" style="width:20px; height:20px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.875rem; font-weight:600; color:var(--text-primary);">Facebook</div>
                        @if($fbSocial)
                        <div style="font-size:0.75rem; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $fbSocial->platform_page_name }}</div>
                        <span style="display:inline-block; font-size:0.625rem; font-weight:700; padding:2px 8px; border-radius:20px; background:rgba(34,197,94,0.12); color:#22c55e; margin-top:2px;">Connected</span>
                        @else
                        <span style="display:inline-block; font-size:0.625rem; font-weight:700; padding:2px 8px; border-radius:20px; background:rgba(148,163,184,0.12); color:var(--text-muted); margin-top:2px;">Not Connected</span>
                        @endif
                    </div>
                </div>
                @if($fbSocial)
                <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">
                    @csrf
                    <input type="hidden" name="platform" value="facebook">
                    <button type="submit" style="font-size:0.75rem; padding:6px 14px; border-radius:8px; font-weight:500; background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.2); cursor:pointer;">Disconnect</button>
                </form>
                @else
                <a href="{{ route('corex.social.oauth.redirect', ['platform' => 'facebook']) }}"
                   style="display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; padding:6px 14px; border-radius:8px; font-weight:600; background:#1877f2; color:#fff; text-decoration:none;">
                    Connect Facebook
                </a>
                @endif
            </div>

            {{-- Instagram --}}
            <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:12px; padding:16px;">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                    <div style="width:40px; height:40px; border-radius:10px; background:#e1306c22; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#e1306c" style="width:20px; height:20px;"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.875rem; font-weight:600; color:var(--text-primary);">Instagram</div>
                        @if($igSocial)
                        <div style="font-size:0.75rem; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $igSocial->platform_page_name }}</div>
                        <span style="display:inline-block; font-size:0.625rem; font-weight:700; padding:2px 8px; border-radius:20px; background:rgba(34,197,94,0.12); color:#22c55e; margin-top:2px;">Connected</span>
                        @else
                        <span style="display:inline-block; font-size:0.625rem; font-weight:700; padding:2px 8px; border-radius:20px; background:rgba(148,163,184,0.12); color:var(--text-muted); margin-top:2px;">Not Connected</span>
                        @endif
                    </div>
                </div>
                @if($igSocial)
                <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">
                    @csrf
                    <input type="hidden" name="platform" value="instagram">
                    <button type="submit" style="font-size:0.75rem; padding:6px 14px; border-radius:8px; font-weight:500; background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.2); cursor:pointer;">Disconnect</button>
                </form>
                @else
                <a href="{{ route('corex.social.oauth.redirect', ['platform' => 'instagram']) }}"
                   style="display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; padding:6px 14px; border-radius:8px; font-weight:600; background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); color:#fff; text-decoration:none;">
                    Connect Instagram
                </a>
                @endif
            </div>

        </div>
    </div>
    @endif

    {{-- Agent Documents (read-only) --}}
    @if($user->agent_photo_path || $user->ffc_certificate_path)
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px 24px;">
        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 20px;">Agent Documents</h3>

        <div style="display:flex; gap:24px; flex-wrap:wrap;">
            @if($user->agent_photo_path)
            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.06em;">Agent Photo</div>
                <img src="{{ asset('storage/' . $user->agent_photo_path) }}" alt="Agent photo"
                     style="width:80px; height:80px; object-fit:cover; border-radius:10px; border:1px solid var(--border);">
            </div>
            @endif

            @if($user->ffc_certificate_path)
            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.06em;">FFC Certificate</div>
                <a href="{{ asset('storage/' . $user->ffc_certificate_path) }}" target="_blank"
                   style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:#00b4d8; font-size:0.8rem; font-weight:500; text-decoration:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    {{ basename($user->ffc_certificate_path) }}
                </a>
            </div>
            @endif
        </div>

        <p style="font-size:0.7rem; color:var(--text-muted); margin-top:14px;">Managed by admin — contact your branch manager to update.</p>
    </div>
    @endif

    {{-- Profile Information --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px 24px;">
        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 6px;">Profile Information</h3>
        <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 20px;">Update your name and email address.</p>

        <form id="send-verification" method="post" action="{{ route('verification.send') }}">
            @csrf
        </form>

        <form method="post" action="{{ route('profile.update') }}">
            @csrf
            @method('patch')

            <div style="display:flex; flex-direction:column; gap:16px; max-width:480px;">
                <div>
                    <label for="name" style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autocomplete="name"
                           style="width:100%; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:8px 12px; font-size:0.875rem; box-sizing:border-box;">
                    @error('name')
                        <p style="font-size:0.75rem; color:#f87171; margin-top:4px;">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username"
                           style="width:100%; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:8px 12px; font-size:0.875rem; box-sizing:border-box;">
                    @error('email')
                        <p style="font-size:0.75rem; color:#f87171; margin-top:4px;">{{ $message }}</p>
                    @enderror

                    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <div style="margin-top:8px;">
                            <p style="font-size:0.8rem; color:var(--text-secondary);">
                                Your email address is unverified.
                                <button form="send-verification" style="background:none; border:none; color:#00b4d8; text-decoration:underline; cursor:pointer; font-size:0.8rem; padding:0;">
                                    Click here to re-send the verification email.
                                </button>
                            </p>
                            @if (session('status') === 'verification-link-sent')
                                <p style="font-size:0.8rem; color:#22c55e; margin-top:4px;">A new verification link has been sent to your email address.</p>
                            @endif
                        </div>
                    @endif
                </div>

                <div>
                    <button type="submit"
                            style="padding:9px 18px; border-radius:8px; border:none; background:#00b4d8; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">
                        Save
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- Update Password --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px 24px;">
        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); border-left:3px solid #00b4d8; padding-left:12px; margin:0 0 6px;">Update Password</h3>
        <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 20px;">Ensure your account is using a long, random password to stay secure.</p>

        <form method="post" action="{{ route('password.update') }}">
            @csrf
            @method('put')

            <div style="display:flex; flex-direction:column; gap:16px; max-width:480px;">
                <div>
                    <label for="update_password_current_password" style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">Current Password</label>
                    <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password"
                           style="width:100%; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:8px 12px; font-size:0.875rem; box-sizing:border-box;">
                    @error('current_password', 'updatePassword')
                        <p style="font-size:0.75rem; color:#f87171; margin-top:4px;">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="update_password_password" style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">New Password</label>
                    <input id="update_password_password" name="password" type="password" autocomplete="new-password"
                           style="width:100%; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:8px 12px; font-size:0.875rem; box-sizing:border-box;">
                    @error('password', 'updatePassword')
                        <p style="font-size:0.75rem; color:#f87171; margin-top:4px;">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="update_password_password_confirmation" style="display:block; font-size:0.75rem; font-weight:600; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.06em;">Confirm Password</label>
                    <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                           style="width:100%; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:8px 12px; font-size:0.875rem; box-sizing:border-box;">
                    @error('password_confirmation', 'updatePassword')
                        <p style="font-size:0.75rem; color:#f87171; margin-top:4px;">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <button type="submit"
                            style="padding:9px 18px; border-radius:8px; border:none; background:#00b4d8; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">
                        Update Password
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- Delete Account --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px 24px;" x-data="{ confirmDelete: false }">
        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); border-left:3px solid #ef4444; padding-left:12px; margin:0 0 6px;">Delete Account</h3>
        <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 20px;">Once your account is deleted, all of its resources and data will be permanently deleted.</p>

        <button @click="confirmDelete = true" x-show="!confirmDelete"
                style="padding:9px 18px; border-radius:8px; border:none; background:#ef4444; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">
            Delete Account
        </button>

        <div x-show="confirmDelete" x-cloak x-transition
             style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:12px; padding:16px; max-width:480px;">
            <p style="font-size:0.85rem; font-weight:600; color:#ef4444; margin:0 0 4px;">Are you sure you want to delete your account?</p>
            <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 16px;">Please enter your password to confirm.</p>

            <form method="post" action="{{ route('profile.destroy') }}">
                @csrf
                @method('delete')

                <div style="margin-bottom:12px;">
                    <input name="password" type="password" placeholder="Password"
                           style="width:100%; max-width:300px; border-radius:8px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:8px 12px; font-size:0.875rem; box-sizing:border-box;">
                    @error('password', 'userDeletion')
                        <p style="font-size:0.75rem; color:#f87171; margin-top:4px;">{{ $message }}</p>
                    @enderror
                </div>

                <div style="display:flex; gap:8px;">
                    <button type="button" @click="confirmDelete = false"
                            style="padding:8px 16px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-secondary); font-size:0.8rem; font-weight:500; cursor:pointer;">
                        Cancel
                    </button>
                    <button type="submit"
                            style="padding:8px 16px; border-radius:8px; border:none; background:#ef4444; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">
                        Confirm Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
