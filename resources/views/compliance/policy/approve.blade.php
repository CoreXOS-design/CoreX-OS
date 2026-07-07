{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header :title="'Approve ' . $version->policy->name . ' v' . $version->version_number" :back-route="route('compliance.policy.show', $version)" back-label="Back" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            <div class="mb-6 rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color:var(--text-primary);">
                Approving makes this version <strong>active</strong>, supersedes the current active version, and resets every staff member to "not started" — all staff will be required to re-acknowledge.
            </div>

            <form method="POST" action="{{ route('compliance.policy.approve', $version) }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Approver Title <span class="text-red-500">*</span></label>
                    <input type="text" name="approver_title" value="{{ old('approver_title') }}" required
                           placeholder="e.g. Principal, Director, Compliance Officer"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    @error('approver_title') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Board / Approval Document (PDF)</label>
                    <input type="file" name="board_approval_document" accept=".pdf"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">Optional. Upload a signed approval document. Max 10MB.</p>
                    @error('board_approval_document') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Effective From <span class="text-red-500">*</span></label>
                        <input type="date" name="effective_from" value="{{ old('effective_from', now()->format('Y-m-d')) }}" required
                               min="{{ now()->format('Y-m-d') }}"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        @error('effective_from') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Next Review Due <span class="text-red-500">*</span></label>
                        <input type="date" name="next_review_due" value="{{ old('next_review_due', now()->addYear()->format('Y-m-d')) }}" required
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        @error('next_review_due') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Approval Notes</label>
                    <textarea name="approval_notes" rows="3" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('approval_notes') }}</textarea>
                    @error('approval_notes') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary">Approve &amp; Activate</button>
                    <a href="{{ route('compliance.policy.show', $version) }}" class="text-sm font-medium" style="color:var(--text-muted);">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
