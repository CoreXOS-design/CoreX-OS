{{-- Portal credentials — renders INSIDE the wizard's main form (partial_after),
     so it posts through to SettingsController@updatePortalCredentials alongside
     the syndication toggles above it.

     Deliberately NOT wired to AgencyController@update: that method requires
     `name` and force-defaults is_active, the brand colours and the feature
     flags, so a partial post would deactivate the agency and reset its
     branding. The narrow saver touches the portal columns and nothing else. --}}
@php
    $hasP24 = filled($agency->p24_username) && filled($agency->p24_password);
    $hasPp  = filled($agency->pp_username)  && filled($agency->pp_password);
@endphp

<div class="rounded-lg p-4" style="border:1px solid var(--border,#e5e7eb);">
    <h3 class="text-sm font-bold" style="color:var(--text-primary,#0f172a);">Portal credentials</h3>
    <p class="text-xs mt-0.5 mb-1" style="color:var(--text-muted,#64748b);">
        The logins Property24 and Private Property issued you when you opened your portal accounts. Without
        them the switches above have nothing to send with, so nothing sends.
    </p>
    <p class="text-[11px] mb-4" style="color:var(--text-muted,#94a3b8);">
        <span class="font-semibold">What this changes:</span> whether a listing your agent marks for
        syndication actually reaches the portal. Don't have them yet? Leave this blank, skip the step, and
        come back — everything else on this page still saves.
    </p>

    {{-- Property24 --}}
    <div class="mb-4">
        <div class="flex items-center gap-2 mb-2">
            <h4 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted,#64748b);">Property24</h4>
            @if ($hasP24)
                <span class="text-[11px] font-medium px-2 py-0.5 rounded"
                      style="background: color-mix(in srgb, var(--ds-emerald,#10b981) 14%, transparent); color: var(--ds-emerald,#10b981);">
                    Saved
                </span>
            @endif
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Username</label>
                <input type="text" name="p24_username" value="{{ old('p24_username', $agency->p24_username) }}"
                       autocomplete="off"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @error('p24_username')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Password</label>
                <input type="password" name="p24_password" value="" autocomplete="new-password"
                       placeholder="{{ $hasP24 ? '•••••••• (unchanged)' : '' }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @if ($hasP24)
                    <p class="text-[11px] mt-1" style="color:var(--text-muted,#94a3b8);">Leave blank to keep the saved one.</p>
                @endif
                @error('p24_password')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Agency ID</label>
                <input type="text" name="p24_agency_id" value="{{ old('p24_agency_id', $agency->p24_agency_id) }}"
                       autocomplete="off"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @error('p24_agency_id')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>

    {{-- Private Property --}}
    <div>
        <div class="flex items-center gap-2 mb-2">
            <h4 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted,#64748b);">Private Property</h4>
            @if ($hasPp)
                <span class="text-[11px] font-medium px-2 py-0.5 rounded"
                      style="background: color-mix(in srgb, var(--ds-emerald,#10b981) 14%, transparent); color: var(--ds-emerald,#10b981);">
                    Saved
                </span>
            @endif
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Username</label>
                <input type="text" name="pp_username" value="{{ old('pp_username', $agency->pp_username) }}"
                       autocomplete="off"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @error('pp_username')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Password</label>
                <input type="password" name="pp_password" value="" autocomplete="new-password"
                       placeholder="{{ $hasPp ? '•••••••• (unchanged)' : '' }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @if ($hasPp)
                    <p class="text-[11px] mt-1" style="color:var(--text-muted,#94a3b8);">Leave blank to keep the saved one.</p>
                @endif
                @error('pp_password')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Branch GUID</label>
                <input type="text" name="pp_branch_guid" value="{{ old('pp_branch_guid', $agency->pp_branch_guid) }}"
                       autocomplete="off"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @error('pp_branch_guid')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>
</div>
