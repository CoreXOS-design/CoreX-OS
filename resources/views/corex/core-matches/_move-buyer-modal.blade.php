{{--
    "Move buyer to another agent" popup — ONE for the whole page; the row/card button supplies the
    buyer's name, current agent and the POST endpoint (route corex.core-matches.reassign-buyer).
    Johan, 2026-10-07: the primary agent is the one who works the buyer, so this moves the primary agent
    AND all of the buyer's saved searches in one go, logged in the contact history. A reason is required
    (the manager has the conversation with the agent first). Used by the Core Matches board and the
    Buyer Pipeline. Expects $moveBuyerAgents (id, name of active agents).
--}}
<div x-data="{ name: '', current: '', currentId: 0, action: '', agentId: '',
               open(d) { this.name = d.name; this.current = d.current || 'nobody'; this.currentId = d.currentId || 0; this.action = d.action; this.agentId = ''; this.$dispatch('open-modal', 'move-buyer'); } }"
     @open-move-buyer.window="open($event.detail)">
    <x-modal name="move-buyer" max-width="md">
        <form method="POST" :action="action" class="p-5 space-y-3">
            @csrf
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Move <span x-text="name"></span> to another agent</div>
            <p class="text-xs" style="color:var(--text-muted);">
                Currently with <strong x-text="current"></strong>. The new agent becomes this buyer's primary agent and takes over all of their saved searches. The move is recorded in the contact's history.
            </p>
            <label class="block text-xs font-medium" style="color:var(--text-secondary);">
                New agent
                <select name="to_agent_id" x-model="agentId" required class="mt-1 w-full rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">Choose an agent…</option>
                    @foreach($moveBuyerAgents as $moveAgent)
                    <option value="{{ $moveAgent->id }}" x-bind:disabled="currentId === {{ (int) $moveAgent->id }}">{{ $moveAgent->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs font-medium" style="color:var(--text-secondary);">
                Reason
                <textarea name="reason" required rows="2" maxlength="2000" placeholder="e.g. Kym is on leave"
                          class="mt-1 w-full rounded-md px-3 py-2 text-sm"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
            </label>
            <div class="flex justify-end items-center gap-2">
                <button type="button" class="text-xs" style="color:var(--text-muted);" @click="$dispatch('close-modal', 'move-buyer')">Cancel</button>
                <button type="submit" class="corex-btn-primary text-sm" :disabled="!agentId">Move buyer</button>
            </div>
        </form>
    </x-modal>
</div>
