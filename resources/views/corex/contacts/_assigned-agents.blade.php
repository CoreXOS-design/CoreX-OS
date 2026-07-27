{{-- Assigned Agents — the operational Primary/Co-Agent (agent_id /
     second_agent_id). created_by stays the immutable capture audit
     (shown as "Captured by"), never as the assigned agent. AT-118:
     changing the assignment requires contacts.reassign_agent.

     Extracted as its own partial (pre-existing bug fix, found while
     verifying Phase 4 in a real browser — NOT a Phase 4 change): the
     surrounding show.blade.php is a ~2300-line file, and Blade's compiler
     silently failed to compile the @php…@endphp opening tag right here
     (a PCRE backtrack/recursion-limit-class failure on very large files —
     confirmed by testing Blade::compileString() against the file exactly
     as it stood before Phase 4 touched anything). $canReassign never got
     assigned, so @if($canReassign) threw "Undefined variable" on EVERY
     contact page — a pre-existing 500, not something Phase 4 introduced.
     Moving this self-contained section into its own file compiles it
     independently and resolves it, same technique used for
     _recent-sends.blade.php. Reported to Johan; no logic changed here. --}}
@php $canReassign = auth()->user()?->hasPermission('contacts.reassign_agent'); @endphp
<div class="pt-2 border-t" style="border-color:var(--border);">
    <h3 class="text-xs font-bold uppercase tracking-widest pt-4 mb-1" style="color:var(--text-muted);">Assigned Agents</h3>
    <p class="text-[11px] mb-3" style="color:var(--text-muted);">The agent(s) assigned to this contact. Captured by {{ $contact->createdBy?->name ?? 'Unknown' }}.</p>
    @if($canReassign)
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Agent</label>
            <select name="agent_id"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">— Unassigned —</option>
                @foreach($agencyAgents as $agent)
                    <option value="{{ $agent->id }}" @selected((int) old('agent_id', $contact->agent_id) === $agent->id)>{{ $agent->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Co-Agent <span class="font-normal normal-case">(optional)</span></label>
            <select name="second_agent_id"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">— None —</option>
                @foreach($agencyAgents as $agent)
                    <option value="{{ $agent->id }}" @selected((int) old('second_agent_id', $contact->second_agent_id) === $agent->id)>{{ $agent->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    @error('second_agent_id')
        <p class="text-[11px] mt-1" style="color:var(--ds-crimson);">{{ $message }}</p>
    @enderror
    @else
    {{-- No Silent Locks: show the current assignment read-only + why it's locked. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Agent</label>
            <p class="text-sm" style="color:var(--text-primary);">{{ $contact->agent?->name ?? 'Unassigned' }}</p>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Co-Agent</label>
            <p class="text-sm" style="color:var(--text-primary);">{{ $contact->secondAgent?->name ?? 'None' }}</p>
        </div>
    </div>
    <p class="text-[11px] mt-2" style="color:var(--text-muted);">Only a manager can change the agent assigned to a contact. Ask an admin or branch manager to reassign it.</p>
    @endif
</div>
