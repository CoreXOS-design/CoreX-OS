{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Approve RMCP v{{ $version->version_number }}" :back-route="route('compliance.rmcp.show', $version)" back-label="Back to RMCP" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            {{-- Warning --}}
            <div class="mb-6 px-4 py-3 text-sm rounded-md" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent); color: var(--text-primary);">
                By approving, you confirm you have reviewed this RMCP and accept board-level responsibility per section 42 of the FIC Act and Revised Guidance Note 7A (September 2025).
            </div>

            <form method="POST" action="{{ route('compliance.rmcp.approve', $version) }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-secondary);">Approver Title <span style="color: var(--ds-crimson);">*</span></label>
                    <input type="text" name="approver_title" value="{{ old('approver_title') }}" required
                           placeholder="e.g. Principal, Director, Sole Proprietor"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('approver_title') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-secondary);">Board Approval Document (PDF) <span style="color: var(--ds-crimson);">*</span></label>
                    <input type="file" name="board_approval_document" accept=".pdf" required
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Upload signed board resolution or approval document. Max 10MB.</p>
                    @error('board_approval_document') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color: var(--text-secondary);">Effective From <span style="color: var(--ds-crimson);">*</span></label>
                        <input type="date" name="effective_from" value="{{ old('effective_from', now()->format('Y-m-d')) }}" required
                               min="{{ now()->format('Y-m-d') }}"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        @error('effective_from') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color: var(--text-secondary);">Next Review Due <span style="color: var(--ds-crimson);">*</span></label>
                        <input type="date" name="next_review_due" value="{{ old('next_review_due', now()->addYear()->format('Y-m-d')) }}" required
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        @error('next_review_due') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color: var(--text-secondary);">Approval Notes</label>
                    <textarea name="approval_notes" rows="3" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ old('approval_notes') }}</textarea>
                    @error('approval_notes') <p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="corex-btn-primary">
                        Approve RMCP
                    </button>
                    <a href="{{ route('compliance.rmcp.show', $version) }}" class="corex-btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
