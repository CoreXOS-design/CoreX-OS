@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Approve RMCP v{{ $version->version_number }}" :back-route="route('compliance.rmcp.show', $version)" back-label="Back to RMCP" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            {{-- Warning --}}
            <div class="mb-6 px-4 py-3 text-sm" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid rgba(234,179,8,0.3); border-radius:6px; color:#ca8a04;">
                By approving, you confirm you have reviewed this RMCP and accept board-level responsibility per section 42 of the FIC Act and Revised Guidance Note 7A (September 2025).
            </div>

            <form method="POST" action="{{ route('compliance.rmcp.approve', $version) }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Approver Title *</label>
                    <input type="text" name="approver_title" value="{{ old('approver_title') }}" required
                           placeholder="e.g. Principal, Director, Sole Proprietor"
                           class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    @error('approver_title') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Board Approval Document (PDF) *</label>
                    <input type="file" name="board_approval_document" accept=".pdf" required
                           class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    <p class="text-xs mt-1" style="color:#94a3b8;">Upload signed board resolution or approval document. Max 10MB.</p>
                    @error('board_approval_document') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Effective From *</label>
                        <input type="date" name="effective_from" value="{{ old('effective_from', now()->format('Y-m-d')) }}" required
                               min="{{ now()->format('Y-m-d') }}"
                               class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        @error('effective_from') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Next Review Due *</label>
                        <input type="date" name="next_review_due" value="{{ old('next_review_due', now()->addYear()->format('Y-m-d')) }}" required
                               class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        @error('next_review_due') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Approval Notes</label>
                    <textarea name="approval_notes" rows="3" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">{{ old('approval_notes') }}</textarea>
                    @error('approval_notes') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                        Approve RMCP
                    </button>
                    <a href="{{ route('compliance.rmcp.show', $version) }}" class="text-sm" style="color:#6b7280;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
