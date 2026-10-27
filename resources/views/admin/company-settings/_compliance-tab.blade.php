{{--
    COMPLIANCE TAB - the board / senior management list (.ai/specs/compliance.md "RMCP board approval letter").
    These are the people asked to sign the RMCP approval letter. Full CRUD: add, edit, archive (soft delete), restore.
    Needs: $agency, $boardMembers, $boardArchived, $boardUsers.
--}}
<div x-show="activeTab === 'compliance'" x-cloak class="space-y-6" x-data="{ showArchived: false }">
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Board / senior management</h3>
        <p class="text-xs" style="color: var(--text-secondary);">These are the people who sign the board approval letter when you approve a new RMCP. Each one gets an email with a link to sign. A person who is also a CoreX user can sign inside CoreX.</p>

        @if($boardMembers->isEmpty())
            <p class="text-sm" style="color: var(--text-primary);">No one is on the list yet. Add the first person below.</p>
        @else
            @foreach($boardMembers as $m)
                <form method="POST" id="bm-{{ $m->id }}" action="{{ route('admin.company-settings.board-members.update', [$agency, $m->id]) }}">@csrf @method('PUT')</form>
            @endforeach
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="py-1.5 pr-3">Name</th><th class="py-1.5 pr-3">Title</th><th class="py-1.5 pr-3">Email</th><th class="py-1.5 pr-3">CoreX user</th><th class="py-1.5 pr-3">Order</th><th class="py-1.5"></th>
                    </tr></thead>
                    <tbody>
                    @foreach($boardMembers as $m)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="py-2 pr-3"><input form="bm-{{ $m->id }}" name="name" value="{{ $m->name }}" required maxlength="150" class="w-full rounded-md px-2 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></td>
                            <td class="py-2 pr-3"><input form="bm-{{ $m->id }}" name="title" value="{{ $m->title }}" required maxlength="150" class="w-full rounded-md px-2 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></td>
                            <td class="py-2 pr-3"><input form="bm-{{ $m->id }}" type="email" name="email" value="{{ $m->email }}" required maxlength="190" class="w-full rounded-md px-2 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></td>
                            <td class="py-2 pr-3">
                                <select form="bm-{{ $m->id }}" name="user_id" class="rounded-md px-2 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    <option value="">Not a CoreX user</option>
                                    @foreach($boardUsers as $u)<option value="{{ $u->id }}" @selected((int) $m->user_id === (int) $u->id)>{{ $u->name }}</option>@endforeach
                                </select>
                            </td>
                            <td class="py-2 pr-3"><input form="bm-{{ $m->id }}" type="number" name="sort_order" value="{{ $m->sort_order }}" min="0" max="9999" class="w-16 rounded-md px-2 py-1 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <button form="bm-{{ $m->id }}" type="submit" class="corex-btn-outline text-xs">Save</button>
                                <form method="POST" action="{{ route('admin.company-settings.board-members.destroy', [$agency, $m->id]) }}" class="inline" onsubmit="return confirm('Archive {{ e($m->name) }}? They stay on letters already sent.');">@csrf @method('DELETE')
                                    <button type="submit" class="text-xs underline ml-2" style="color: var(--text-muted);">Archive</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.company-settings.board-members.store', $agency) }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end p-3 rounded-md" style="background: var(--surface-2); border: 1px solid var(--border);">
            @csrf
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Name</label>
                <input name="name" required maxlength="150" class="w-full rounded-md px-2 py-1.5 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Title</label>
                <input name="title" required maxlength="150" placeholder="e.g. Director" class="w-full rounded-md px-2 py-1.5 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Email</label>
                <input type="email" name="email" required maxlength="190" class="w-full rounded-md px-2 py-1.5 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">CoreX user (optional)</label>
                <select name="user_id" class="w-full rounded-md px-2 py-1.5 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">Not a CoreX user</option>
                    @foreach($boardUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                </select></div>
            <div><button type="submit" class="corex-btn-primary text-xs">Add to the board list</button></div>
        </form>

        @if($boardArchived->isNotEmpty())
            <div>
                <button type="button" @click="showArchived = !showArchived" class="text-xs underline" style="color: var(--text-muted);" x-text="showArchived ? 'Hide archived' : 'Show archived ({{ $boardArchived->count() }})'"></button>
                <ul x-show="showArchived" x-cloak class="mt-2 space-y-1 text-sm" style="color: var(--text-secondary);">
                    @foreach($boardArchived as $m)
                        <li class="flex items-center gap-3">{{ $m->name }}, {{ $m->title }}
                            <form method="POST" action="{{ route('admin.company-settings.board-members.restore', [$agency, $m->id]) }}">@csrf
                                <button type="submit" class="text-xs underline">Restore</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
