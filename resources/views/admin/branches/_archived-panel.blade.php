{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Archived branches panel with Restore — spec .ai/specs/branch-archive-reassignment.md §6, §7.5 (AT-420).

     Must be included OUTSIDE any other <form> (each Restore is its own form + confirm modal).

     Expects:
       $archivedBranches  Collection<Branch> (soft-deleted, newest first)
       $context           array name => value of hidden fields the redirect needs (may be empty)
--}}
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <div>
        <h3 class="ds-section-header">Archived Branches</h3>
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            An archived branch keeps every record made while it was open. Restore brings it back as an active branch; the people who were moved stay where they are now.
        </div>
    </div>

    @forelse($archivedBranches as $archived)
        @php $restoreModal = 'restore-branch-' . $archived->id; @endphp
        <div class="flex items-center justify-between gap-4 pb-2" style="border-bottom: 1px solid var(--border);">
            <div>
                <div class="font-medium" style="color: var(--text-primary);">
                    {{ $archived->name }}@if($archived->code) <span style="color: var(--text-muted);">({{ $archived->code }})</span>@endif
                    <span class="ds-badge ds-badge-default ml-2">Archived</span>
                </div>
                <div class="text-xs" style="color: var(--text-muted);">
                    Archived {{ optional($archived->deleted_at)->format('d M Y') ?? '—' }}
                </div>
            </div>

            <button type="button" class="text-xs font-semibold" style="color: var(--brand-icon);"
                    x-data @click="$dispatch('open-modal', '{{ $restoreModal }}')">Restore</button>

            <x-modal name="{{ $restoreModal }}" max-width="md">
                <form method="POST" action="{{ route('admin.branches.restore', $archived->id) }}" class="p-6 space-y-4">
                    @csrf
                    @foreach(($context ?? []) as $field => $value)
                        <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                    @endforeach
                    <h3 class="text-base font-bold" style="color: var(--text-primary);">Restore {{ $archived->name }}?</h3>
                    <p class="text-sm" style="color: var(--text-secondary);">
                        The branch becomes active again with all its historical records. Agents stay where they are now — move them back from Branch Assignments if needed.
                    </p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="corex-btn-outline text-sm" @click="$dispatch('close-modal', '{{ $restoreModal }}')">Cancel</button>
                        <button type="submit" class="corex-btn-primary text-sm">Restore branch</button>
                    </div>
                </form>
            </x-modal>
        </div>
    @empty
        <div class="rounded-md py-6 px-6 text-center text-sm" style="color: var(--text-muted);">
            No archived branches.
        </div>
    @endforelse
</div>
