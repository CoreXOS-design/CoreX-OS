{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Archive-branch wizard modal — spec .ai/specs/branch-archive-reassignment.md §7 (AT-420).

     Renders ONLY the modal (its own <form>), so it must be included OUTSIDE any
     other <form>. Open it from any button with:
         x-data @click="$dispatch('open-modal', 'archive-branch-{{ $branch->id }}')"

     Expects:
       $branch    App\Models\Branch (active)
       $attached  Collection<User>   — everyone whose home is this branch (may be empty)
       $targets   Collection<Branch> — active branches in the agency ($branch itself is filtered out here)
       $context   array name => value of hidden fields the redirect needs (may be empty)
--}}
@php
    $modalName    = 'archive-branch-' . $branch->id;
    $attachedList = ($attached ?? collect())->values();
    $targetList   = ($targets ?? collect())->where('id', '!=', $branch->id)->values();
    $peopleCount  = $attachedList->count();
    $peopleWord   = $peopleCount === 1 ? '1 person works' : $peopleCount . ' people work';
    $initialPicks = $attachedList->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => ''])->all();
@endphp

<x-modal name="{{ $modalName }}" max-width="lg">
    <form method="POST" action="{{ route('admin.branches.delete', $branch) }}" class="p-6 space-y-5"
          x-data="{
              picks: @js((object) $initialPicks),
              all: '',
              applyAll() { Object.keys(this.picks).forEach(k => { this.picks[k] = this.all; }); },
              ready() { return Object.values(this.picks).every(v => v !== '' && v !== null); }
          }">
        @csrf
        @foreach(($context ?? []) as $field => $value)
            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
        @endforeach

        <div>
            <h3 class="text-base font-bold" style="color: var(--text-primary);">Archive {{ $branch->name }}?</h3>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">
                @if($peopleCount === 0)
                    Nobody works from this branch, so no one needs to be moved.
                @else
                    {{ $peopleWord }} from this branch. Choose where each one works from now.
                @endif
            </p>
        </div>

        @if($peopleCount > 0)
            @if($targetList->isEmpty())
                <div class="rounded-md px-4 py-3 text-sm"
                     style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                            color: var(--text-primary);">
                    There is no other active branch to move them to. Add a branch first, then archive this one.
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <label class="text-xs font-medium" style="color: var(--text-secondary);">Move everyone to</label>
                    <select x-model="all" @change="applyAll()" class="rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">— choose a branch —</option>
                        @foreach($targetList as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}@if($t->code) ({{ $t->code }})@endif</option>
                        @endforeach
                    </select>
                    <span class="text-xs" style="color: var(--text-muted);">or pick per person below</span>
                </div>

                <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <table class="w-full text-sm">
                        <thead>
                            <tr style="background: var(--surface-2); color: var(--text-secondary);">
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider">Person</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider">Works from now</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($attachedList as $person)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="px-3 py-2">
                                        <div class="font-medium" style="color: var(--text-primary);">{{ $person->name }}</div>
                                        <div class="text-xs" style="color: var(--text-muted);">
                                            {{ ucwords(str_replace('_', ' ', (string) ($person->role ?: 'user'))) }}@if(!$person->is_active) · inactive @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <select name="reassignments[{{ $person->id }}]" x-model="picks['{{ $person->id }}']" required
                                                class="w-full rounded-md px-3 py-2 text-sm"
                                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="">— choose —</option>
                                            @foreach($targetList as $t)
                                                <option value="{{ $t->id }}">{{ $t->name }}@if($t->code) ({{ $t->code }})@endif</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        <div class="rounded-md p-3 space-y-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
            <div>
                <span class="font-semibold" style="color: var(--text-primary);">Stays with {{ $branch->name }} (archived):</span>
                every deal, property, contact, document, target and activity recorded while the branch was open.
            </div>
            <div>
                <span class="font-semibold" style="color: var(--text-primary);">Moves:</span>
                @if($peopleCount === 0)
                    nobody.
                @else
                    {{ $peopleCount === 1 ? 'the 1 person' : 'the ' . $peopleCount . ' people' }} above. Anything they load from now on lands on their new branch.
                @endif
            </div>
            <div class="text-xs" style="color: var(--text-muted);">You can restore an archived branch later from the Archived branches list.</div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" class="corex-btn-outline text-sm" @click="$dispatch('close-modal', '{{ $modalName }}')">Cancel</button>
            <button type="submit" class="corex-btn-primary text-sm" :disabled="!ready()" :style="ready() ? '' : 'opacity: .5; cursor: not-allowed;'">
                Archive branch
            </button>
        </div>
    </form>
</x-modal>
