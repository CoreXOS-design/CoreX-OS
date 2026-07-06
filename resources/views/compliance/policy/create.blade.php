{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="New Policy" :back-route="route('compliance.policy.index')" back-label="Policies" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            <div class="mb-6 rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); color:var(--text-primary);">
                Creates the policy plus a <strong>v1 draft</strong> with two starter sections. The draft is not published — approve it later to make it active and require staff sign-off.
            </div>

            <form method="POST" action="{{ route('compliance.policy.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Policy Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                           placeholder="e.g. Communication &amp; Marketing Compliance Policy"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    @error('name') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Policy Key <span class="text-red-500">*</span></label>
                    <input type="text" name="policy_key" value="{{ old('policy_key') }}" required
                           placeholder="e.g. communication_marketing"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">Lowercase letters, numbers and underscores only. Stable identifier — used in URLs and code. Cannot be changed later.</p>
                    @error('policy_key') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Description</label>
                    <textarea name="description" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('description') }}</textarea>
                    @error('description') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary">Create Policy</button>
                    <a href="{{ route('compliance.policy.index') }}" class="text-sm font-medium" style="color:var(--text-muted);">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
