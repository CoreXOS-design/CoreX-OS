@php
    /** @var string $channel */
    /** @var \Illuminate\Database\Eloquent\Collection $templates */
    /** @var array $mergeFields */
    $channelLabel = ucfirst($channel);
@endphp

<div x-data="{
        formOpen: false,
        editingId: null,
        formState: { name: '', subject: '', body: '', description: '', is_active: true, is_default_for_channel: false },
        resetForm() {
            this.formOpen = false;
            this.editingId = null;
            this.formState = { name: '', subject: '', body: '', description: '', is_active: true, is_default_for_channel: false };
        },
        openNew() {
            this.editingId = null;
            this.formState = { name: '', subject: '', body: '', description: '', is_active: true, is_default_for_channel: false };
            this.formOpen = true;
        },
        openEdit(id, state) {
            this.editingId = id;
            this.formState = Object.assign({ name: '', subject: '', body: '', description: '', is_active: true, is_default_for_channel: false }, state);
            this.formOpen = true;
            this.$nextTick(() => { window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }); });
        },
        insertMerge(field) {
            this.formState.body = (this.formState.body || '') + ' {' + field + '} ';
        },
     }"
     class="space-y-4">

    {{-- Templates list --}}
    @if($templates->isEmpty())
        <div class="rounded-md px-6 py-8 text-center text-sm"
             style="background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted);">
            No {{ $channelLabel }} templates yet. Use <strong>+ Add {{ $channelLabel }} Template</strong> below to create one.
        </div>
    @else
        <div class="space-y-3">
            @foreach($templates as $template)
                <div class="rounded-md p-4"
                     style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-semibold text-sm" style="color: var(--text-primary);">{{ $template->name }}</h3>
                                @if($template->is_default_for_channel)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider"
                                          style="background: color-mix(in srgb, #00d4aa 18%, transparent); color: #00d4aa;">
                                        Default
                                    </span>
                                @endif
                                @if(!$template->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider"
                                          style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                                        Inactive
                                    </span>
                                @endif
                            </div>
                            @if($template->description)
                                <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $template->description }}</p>
                            @endif
                            @if($channel === 'email' && $template->subject)
                                <p class="text-xs mt-2" style="color: var(--text-muted);">
                                    Subject: <span style="color: var(--text-secondary);">{{ $template->subject }}</span>
                                </p>
                            @endif
                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs" style="color: var(--text-muted);">View body</summary>
                                <pre class="mt-2 p-3 rounded text-xs overflow-x-auto whitespace-pre-wrap"
                                     style="background: var(--surface-2); color: var(--text-secondary); font-family: ui-monospace, SFMono-Regular, monospace;">{{ $template->body }}</pre>
                            </details>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <button type="button"
                                    @click='openEdit({{ $template->id }}, {
                                        name: @json($template->name),
                                        subject: @json($template->subject ?? ""),
                                        body: @json($template->body),
                                        description: @json($template->description ?? ""),
                                        is_active: {{ $template->is_active ? "true" : "false" }},
                                        is_default_for_channel: {{ $template->is_default_for_channel ? "true" : "false" }}
                                    })'
                                    class="text-xs font-semibold px-3 py-1.5 rounded"
                                    style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                                Edit
                            </button>
                            <form method="POST" action="{{ route('settings.outreach-templates.archive', $template) }}" class="inline">
                                @csrf
                                <button type="submit"
                                        onclick="return confirm('Archive this template? Agents will no longer see it in the composer.');"
                                        @disabled($template->is_default_for_channel)
                                        class="text-xs font-semibold px-3 py-1.5 rounded"
                                        style="background: color-mix(in srgb, var(--ds-crimson) 15%, transparent); color: var(--ds-crimson); {{ $template->is_default_for_channel ? 'opacity:0.5; cursor:not-allowed;' : '' }}"
                                        title="{{ $template->is_default_for_channel ? 'Cannot archive the default template — set another as default first.' : 'Archive this template' }}">
                                    Archive
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Toggle for the "Add template" form --}}
    <div>
        <button type="button" @click="formOpen ? resetForm() : openNew()"
                class="text-sm font-semibold px-4 py-2 rounded"
                style="background: #00d4aa; color: #003a2f;">
            <span x-show="!formOpen">+ Add {{ $channelLabel }} Template</span>
            <span x-show="formOpen" x-cloak>× Close form</span>
        </button>
    </div>

    {{-- Add / edit form --}}
    <div x-show="formOpen" x-cloak
         class="rounded-md p-4"
         style="background: var(--surface); border: 1px solid #00d4aa;">

        <form method="POST"
              :action="editingId
                ? '{{ route('settings.outreach-templates.update', ['template' => '__ID__']) }}'.replace('__ID__', editingId)
                : '{{ route('settings.outreach-templates.store') }}'">
            @csrf
            <template x-if="editingId">
                <input type="hidden" name="_method" value="PUT">
            </template>
            <input type="hidden" name="channel" value="{{ $channel }}">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        Name <span style="color: var(--ds-crimson);">*</span>
                    </label>
                    <input type="text" name="name" x-model="formState.name" required maxlength="150"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                @if($channel === 'email')
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        Subject <span style="color: var(--ds-crimson);">*</span>
                    </label>
                    <input type="text" name="subject" x-model="formState.subject" maxlength="255"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                @endif
            </div>

            <div class="mt-3">
                <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                    <label class="block text-xs font-semibold" style="color: var(--text-secondary);">
                        Body <span style="color: var(--ds-crimson);">*</span>
                    </label>
                    <div class="text-xs flex flex-wrap items-center gap-1" style="color: var(--text-muted);">
                        <span>Insert:</span>
                        @foreach($mergeFields as $field)
                            <button type="button"
                                    @click="insertMerge('{{ $field }}')"
                                    class="inline-flex items-center px-1.5 py-0.5 rounded"
                                    style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); font-family: ui-monospace, SFMono-Regular, monospace;"
                                    title="Insert {{ '{' . $field . '}' }}">{{ $field }}</button>
                        @endforeach
                    </div>
                </div>
                <textarea name="body" x-model="formState.body" required rows="10"
                          class="w-full px-3 py-2 text-sm rounded"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-family: ui-monospace, SFMono-Regular, monospace;"></textarea>
                <div class="text-xs mt-1" style="color: var(--text-muted);">
                    Required: <code style="color:#00d4aa;">{{ '{tracking_link}' }}</code> and an opt-out clause with the word <code style="color:#00d4aa;">STOP</code>.
                </div>
            </div>

            <div class="mt-3">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                    Description (internal hint for agents)
                </label>
                <input type="text" name="description" x-model="formState.description" maxlength="1000"
                       class="w-full px-3 py-2 text-sm rounded"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            <div class="mt-3 flex items-center gap-4 flex-wrap">
                <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                    <input type="checkbox" name="is_active" value="1" x-model="formState.is_active">
                    Active
                </label>
                <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                    <input type="checkbox" name="is_default_for_channel" value="1" x-model="formState.is_default_for_channel">
                    Set as default for {{ $channelLabel }}
                </label>
            </div>

            <div class="mt-4 flex items-center gap-2">
                <button type="submit"
                        class="px-4 py-2 text-sm font-semibold rounded"
                        style="background: #00d4aa; color: #003a2f;">
                    <span x-show="!editingId">Create template</span>
                    <span x-show="editingId" x-cloak>Save changes</span>
                </button>
                <button type="button" @click="resetForm()"
                        class="px-4 py-2 text-sm rounded"
                        style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>
