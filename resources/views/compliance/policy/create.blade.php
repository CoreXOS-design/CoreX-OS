@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="New Policy" :back-route="route('compliance.policy.index')" back-label="Policies" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            <div class="mb-6 px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); border-radius:6px; color:var(--text-primary);">
                Creates the policy plus a <strong>v1 draft</strong> with two starter sections. The draft is not published — approve it later to make it active and require staff sign-off.
            </div>

            <form method="POST" action="{{ route('compliance.policy.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Policy Name *</label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                           placeholder="e.g. Communication & Marketing Compliance Policy"
                           class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    @error('name') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Policy Key *</label>
                    <input type="text" name="policy_key" value="{{ old('policy_key') }}" required
                           placeholder="e.g. communication_marketing"
                           class="w-full px-3 py-2 text-sm border font-mono" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    <p class="text-xs mt-1" style="color:#94a3b8;">Lowercase letters, numbers and underscores only. Stable identifier — used in URLs and code. Cannot be changed later.</p>
                    @error('policy_key') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Description</label>
                    <textarea name="description" rows="2" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">{{ old('description') }}</textarea>
                    @error('description') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary">Create Policy</button>
                    <a href="{{ route('compliance.policy.index') }}" class="text-sm" style="color:#6b7280;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
