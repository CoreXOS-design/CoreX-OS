{{-- Invite your team — inline collection editor (renders OUTSIDE the wizard's
     main form, because it posts its own sub-form to the canonical
     UserManagementController@store).

     $teamMembers / $teamBranches / $teamRoles come from
     AgencySetupWizardController::stepData(). Editing, deactivating and deleting
     a member stay on the User Management page, which the step links to. --}}
@php
    $pending = $teamMembers->whereNull('email_verified_at')->count();
@endphp

<div class="rounded-lg p-4" style="border:1px solid var(--border,#e5e7eb);">
    <div class="flex items-baseline justify-between gap-3 mb-3">
        <h3 class="text-sm font-bold" style="color:var(--text-primary,#0f172a);">Your team</h3>
        <span class="text-xs" style="color:var(--text-muted,#64748b);">
            {{ $teamMembers->count() }} {{ Str::plural('person', $teamMembers->count()) }}
            @if ($pending) &middot; {{ $pending }} awaiting acceptance @endif
        </span>
    </div>

    {{-- Current roster --}}
    @if ($teamMembers->isEmpty())
        <p class="text-sm mb-4" style="color:var(--text-muted,#64748b);">
            It's just you so far. Add your first agent below.
        </p>
    @else
        <ul class="mb-4 space-y-1.5">
            @foreach ($teamMembers as $member)
                <li class="flex items-center justify-between gap-3 rounded-md px-3 py-2"
                    style="border:1px solid var(--border,#e5e7eb);">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium truncate" style="color:var(--text-primary,#0f172a);">
                            {{ $member->name }}
                        </span>
                        <span class="block text-xs truncate" style="color:var(--text-muted,#64748b);">
                            {{ $member->email }} &middot; {{ Str::headline($member->role) }}
                        </span>
                    </span>
                    @if (!$member->email_verified_at)
                        <span class="text-[11px] font-medium px-2 py-1 rounded flex-shrink-0"
                              style="background: color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color: var(--brand-icon,#0ea5e9);">
                            Invited
                        </span>
                    @elseif (!$member->is_active)
                        <span class="text-[11px] font-medium px-2 py-1 rounded flex-shrink-0"
                              style="background: color-mix(in srgb, var(--text-muted,#64748b) 12%, transparent); color: var(--text-muted,#64748b);">
                            Inactive
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Invite form — posts to the canonical UserManagementController@store --}}
    <form method="POST" action="{{ route('corex.agency-setup.collection.add', ['collection' => 'user']) }}"
          class="space-y-3">
        @csrf

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">First name</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @error('name')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Surname</label>
                <input type="text" name="surname" value="{{ old('surname') }}" required
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @error('surname')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                       placeholder="agent@youragency.co.za"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                <p class="text-[11px] mt-1" style="color:var(--text-muted,#94a3b8);">Their invitation goes here.</p>
                @error('email')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Cell number</label>
                <input type="text" name="cell" value="{{ old('cell') }}" required
                       placeholder="082 555 1234"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                @error('cell')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Role</label>
                <select name="role" required
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    @foreach ($teamRoles as $role)
                        <option value="{{ $role['name'] }}" @selected(old('role', 'agent') === $role['name'])>
                            {{ $role['label'] }}
                        </option>
                    @endforeach
                </select>
                @error('role')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted,#64748b);">Branch</label>
                <select name="branch_id"
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    <option value="">— No branch —</option>
                    @foreach ($teamBranches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id')<p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>@enderror
            </div>
        </div>

        <button type="submit"
                class="rounded-md px-4 py-2 text-sm font-semibold text-white"
                style="background:var(--brand-button,#0ea5e9);">
            Send invitation
        </button>
    </form>
</div>
