{{-- Branches step aux-partial: inline add/list/archive of branches.
     Mirrors Company Settings → Branches (name + code), delegating to the canonical
     BranchAssignmentController::createBranch / deleteBranch. $branches. --}}
<div class="px-6 py-5 space-y-5">
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Your offices</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Give each branch a short code — it appears on deal references and reports.</p>
    </div>

    @if ($errors->any())
        <div class="rounded-md px-3 py-2 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson,#e11d48) 10%, transparent); color: var(--text-primary,#0f172a); border:1px solid color-mix(in srgb, var(--ds-crimson,#e11d48) 30%, transparent);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add a branch --}}
    <form method="POST" action="{{ route('corex.agency-setup.collection.add', ['collection' => 'branch']) }}"
          class="flex flex-wrap items-end gap-2">
        @csrf
        <div class="flex-1 min-w-[10rem]">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch name</label>
            <input type="text" name="name" required maxlength="255" placeholder="e.g. Seabreeze Bay"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
        </div>
        <div class="w-28">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Code</label>
            <input type="text" name="code" required maxlength="50" placeholder="e.g. SBB"
                   class="w-full rounded-md px-3 py-2 text-sm uppercase"
                   style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
        </div>
        <button type="submit" class="rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap"
                style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#475569);">
            + Add branch
        </button>
    </form>

    {{-- Existing branches --}}
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Existing branches</h3>
        @forelse ($branches as $branch)
            <div class="flex items-center justify-between gap-4 py-2" style="border-bottom:1px solid var(--border,#e5e7eb);">
                <div class="min-w-0 text-sm" style="color:var(--text-primary);">
                    {{ $branch->name }}
                    <span class="text-xs font-mono" style="color:var(--text-muted);">({{ $branch->code }})</span>
                </div>
                <form method="POST" action="{{ route('corex.agency-setup.collection.remove', ['collection' => 'branch', 'id' => $branch->id]) }}"
                      onsubmit="return confirm('Archive this branch? Agents assigned to it must be moved first.');">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs font-semibold"
                            style="background:none;border:none;cursor:pointer;color:var(--ds-crimson,#e11d48);">
                        Archive
                    </button>
                </form>
            </div>
        @empty
            <div class="rounded-md py-6 px-4 text-center text-sm" style="color:var(--text-muted); border:1px dashed var(--border,#e5e7eb);">
                No branches yet. Add your first office above.
            </div>
        @endforelse
    </div>

    <p class="text-[11px] italic" style="color:var(--text-muted);">Archiving is reversible — a branch is never hard-deleted. A branch with agents still assigned can't be archived until they're moved.</p>
</div>
