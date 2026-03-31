<x-app-layout>
    <div x-data="pipelineEditor()" x-cloak>
        {{-- Sticky header --}}
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('deals-v2.pipeline.index') }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0 transition-colors" style="color: var(--text-muted);">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <span class="flex-shrink-0" style="color: var(--border);">|</span>
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">Edit: {{ $template->name }}</h1>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <form method="POST" action="{{ route('deals-v2.pipeline.duplicate', $template) }}" class="inline">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            Duplicate
                        </button>
                    </form>
                    <button type="submit" form="templateForm" class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                        Save Template
                    </button>
                </div>
            </div>
        </div>

        <div class="p-4 lg:p-6 max-w-4xl mx-auto">
            {{-- Toast --}}
            <div x-show="toast" x-transition
                 class="fixed top-16 right-6 z-50 px-4 py-2.5 rounded-lg text-sm font-medium shadow-lg"
                 :style="toastType === 'success' ? 'background:rgba(16,185,129,0.9);color:#fff;' : 'background:rgba(239,68,68,0.9);color:#fff;'"
                 x-text="toastMessage"></div>

            {{-- Flash --}}
            @if(session('status'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171;">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Template Details Card --}}
            <form id="templateForm" method="POST" action="{{ route('deals-v2.pipeline.update', $template) }}" class="rounded-xl p-5 mb-6" style="border: 1px solid var(--border); background: var(--surface);">
                @csrf @method('PUT')
                <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Template Details</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs mb-1" style="color: var(--text-muted);">Name</label>
                        <input type="text" name="name" required value="{{ $template->name }}"
                               class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color: var(--text-muted);">Deal Type</label>
                        <select name="deal_type" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="bond" {{ $template->deal_type === 'bond' ? 'selected' : '' }}>Bond Sale</option>
                            <option value="cash" {{ $template->deal_type === 'cash' ? 'selected' : '' }}>Cash Sale</option>
                            <option value="sale_of_2nd" {{ $template->deal_type === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd Property</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color: var(--text-muted);">Branch</label>
                        <select name="branch_id" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="">All Branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ $template->branch_id == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-6">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="hidden" name="is_default" value="0">
                            <input type="checkbox" name="is_default" value="1" {{ $template->is_default ? 'checked' : '' }} class="rounded" style="accent-color: #14b8a6;">
                            <span class="text-sm" style="color: var(--text-secondary);">Default</span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ $template->is_active ? 'checked' : '' }} class="rounded" style="accent-color: #14b8a6;">
                            <span class="text-sm" style="color: var(--text-secondary);">Active</span>
                        </label>
                    </div>
                </div>
            </form>

            {{-- Step Builder --}}
            <div class="mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                        Pipeline Steps (<span x-text="steps.length"></span>)
                    </h2>
                    <button @click="addNewStep()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                        + Add Step
                    </button>
                </div>

                {{-- Step list --}}
                <div class="space-y-2" x-ref="stepList">
                    <template x-for="(step, idx) in steps" :key="step.id || ('new-' + idx)">
                        <div class="rounded-xl transition-all" style="border: 1px solid var(--border); background: var(--surface);"
                             draggable="true"
                             @dragstart="dragStart($event, idx)"
                             @dragover.prevent="dragOver($event, idx)"
                             @drop="drop($event, idx)"
                             @dragend="dragEnd()">

                            {{-- Collapsed view --}}
                            <div x-show="editingStepId !== step.id" class="px-4 py-2.5 flex items-center gap-3 cursor-pointer" @click="toggleStep(step)">
                                <span class="cursor-grab flex-shrink-0" style="color: var(--text-muted);" @click.stop>
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                                </span>
                                <span class="text-xs font-mono flex-shrink-0 w-5 text-center" style="color: var(--text-muted);" x-text="idx + 1"></span>
                                <span class="font-medium truncate" style="color: var(--text-primary);" x-text="step.name"></span>
                                <span x-show="step.is_locked" class="flex-shrink-0" title="Locked" style="color: #fbbf24;">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                                </span>
                                <span x-show="step.is_milestone" class="flex-shrink-0" title="Milestone" style="color: #60a5fa;">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>
                                </span>
                                <span class="text-xs px-1.5 py-0.5 rounded flex-shrink-0" style="background: var(--surface-2); color: var(--text-muted);" x-text="completionLabel(step.completion_type)"></span>
                                <span class="text-xs flex-shrink-0 ml-auto hidden sm:inline" style="color: var(--text-muted);" x-text="triggerLabel(step)"></span>
                                <span class="flex-shrink-0 flex items-center gap-1 ml-2 hidden sm:flex">
                                    <span class="inline-block w-2 h-2 rounded-full" style="background: #22c55e;" :title="step.rag_green_days + 'd'"></span>
                                    <span class="text-xs font-mono" style="color: var(--text-muted);" x-text="step.rag_green_days"></span>
                                    <span class="inline-block w-2 h-2 rounded-full" style="background: #f59e0b;"></span>
                                    <span class="text-xs font-mono" style="color: var(--text-muted);" x-text="step.rag_amber_days"></span>
                                    <span class="inline-block w-2 h-2 rounded-full" style="background: #ef4444;"></span>
                                    <span class="text-xs font-mono" style="color: var(--text-muted);" x-text="step.rag_red_days"></span>
                                </span>
                                <button @click.stop="deleteStep(step)" class="p-1 rounded hover:bg-red-500/20 transition-colors flex-shrink-0 ml-1"
                                        :style="step.is_locked ? 'color: var(--surface-2); cursor: not-allowed;' : 'color: var(--text-muted);'"
                                        :disabled="step.is_locked" :title="step.is_locked ? 'Locked step' : 'Delete'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                </button>
                            </div>

                            {{-- Expanded edit form --}}
                            <div x-show="editingStepId === step.id" class="px-4 py-4" style="border-top: 1px solid var(--border);">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                                    <div class="md:col-span-2">
                                        <label class="block text-xs mb-1" style="color: var(--text-muted);">Step Name</label>
                                        <input type="text" x-model="editForm.name" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-xs mb-1" style="color: var(--text-muted);">Description</label>
                                        <input type="text" x-model="editForm.description" placeholder="Optional" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                </div>

                                {{-- Trigger --}}
                                <div class="rounded-lg p-3 mb-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                                    <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Trigger</div>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div>
                                            <select x-model="editForm.trigger_type" :disabled="step.is_locked && step.id" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="on_creation">On deal creation</option>
                                                <option value="after_step">After another step</option>
                                                <option value="manual">Manual activation</option>
                                                <option value="on_date">On a specific date</option>
                                            </select>
                                        </div>
                                        <div x-show="editForm.trigger_type === 'after_step'">
                                            <select x-model="editForm.trigger_step_id" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="">Select step...</option>
                                                <template x-for="s in steps.filter(s => s.id !== step.id && s.id)" :key="s.id">
                                                    <option :value="s.id" x-text="s.position + '. ' + s.name" :selected="s.id == editForm.trigger_step_id"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div x-show="editForm.trigger_type === 'after_step' || editForm.trigger_type === 'on_date'">
                                            <div class="flex items-center gap-2">
                                                <input type="number" x-model="editForm.days_offset" min="0" class="w-20 rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <span class="text-xs" style="color: var(--text-muted);">days after</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Completion + RAG --}}
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                    <div class="rounded-lg p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                                        <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Completion Type</div>
                                        <select x-model="editForm.completion_type" :disabled="step.is_locked && step.id" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="manual_tick">Manual Tick</option>
                                            <option value="date_input">Date Input</option>
                                            <option value="amount_input">Amount Input</option>
                                            <option value="document_upload">Document Upload</option>
                                            <option value="document_signed">Document Signed</option>
                                            <option value="text_input">Text Input</option>
                                            <option value="multi_field">Multi Field</option>
                                            <option value="auto_from_linked_deal">Auto (Linked Deal)</option>
                                        </select>
                                    </div>
                                    <div class="rounded-lg p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                                        <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">RAG Thresholds (days)</div>
                                        <div class="flex items-center gap-3">
                                            <div class="flex items-center gap-1">
                                                <span class="w-2.5 h-2.5 rounded-full" style="background: #22c55e;"></span>
                                                <input type="number" x-model="editForm.rag_green_days" min="1" class="w-14 rounded-md text-sm px-2 py-1 focus:outline-none text-center"
                                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <span class="w-2.5 h-2.5 rounded-full" style="background: #f59e0b;"></span>
                                                <input type="number" x-model="editForm.rag_amber_days" min="1" class="w-14 rounded-md text-sm px-2 py-1 focus:outline-none text-center"
                                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <span class="w-2.5 h-2.5 rounded-full" style="background: #ef4444;"></span>
                                                <input type="number" x-model="editForm.rag_red_days" min="1" class="w-14 rounded-md text-sm px-2 py-1 focus:outline-none text-center"
                                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Notifications + Options --}}
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                                    <div class="rounded-lg p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                                        <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Notifications</div>
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                                <input type="checkbox" x-model="editForm.notify_agent" class="rounded" style="accent-color: #14b8a6;"> Agent
                                            </label>
                                            <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                                <input type="checkbox" x-model="editForm.notify_bm" class="rounded" style="accent-color: #14b8a6;"> BM
                                            </label>
                                            <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                                <input type="checkbox" x-model="editForm.notify_admin" class="rounded" style="accent-color: #14b8a6;"> Admin
                                            </label>
                                        </div>
                                    </div>
                                    <div class="rounded-lg p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                                        <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Options</div>
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                                <input type="checkbox" x-model="editForm.is_milestone" class="rounded" style="accent-color: #14b8a6;"> Milestone
                                            </label>
                                            <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                                <input type="checkbox" x-model="editForm.is_locked" :disabled="step.is_locked && step.id" class="rounded" style="accent-color: #14b8a6;"> Locked
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                {{-- Deal Status --}}
                                <div class="rounded-lg p-3 mb-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                                    <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Deal Status</div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs mb-1" style="color: var(--text-muted);">On completion, change deal status to:</label>
                                            <select x-model="editForm.status_trigger" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="">— No change —</option>
                                                <option value="granted">Granted</option>
                                                <option value="completed">Completed</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Negative outcome status:</label>
                                            <select x-model="editForm.negative_status_trigger" class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="">— None —</option>
                                                <option value="cancelled">Cancelled</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div x-show="editForm.negative_status_trigger" class="mt-3">
                                        <label class="block text-xs mb-1" style="color: var(--text-muted);">Negative button label:</label>
                                        <input type="text" x-model="editForm.negative_outcome_label" placeholder='e.g. "Bond Declined"'
                                               class="w-full md:w-1/2 rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div x-show="editForm.status_trigger || editForm.negative_status_trigger" class="mt-3">
                                        <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                                            <input type="checkbox" x-model="editForm.requires_bm_approval" class="rounded" style="accent-color: #14b8a6;">
                                            Requires BM approval before status changes
                                        </label>
                                    </div>
                                </div>

                                <div class="flex items-center justify-end gap-2">
                                    <button @click="cancelEdit()" class="px-3 py-1.5 rounded-lg text-sm transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Cancel</button>
                                    <button @click="saveStep(step)" class="px-4 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors" :disabled="saving">
                                        <span x-text="saving ? 'Saving...' : (step.id ? 'Save Step' : 'Create Step')"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div x-show="steps.length === 0" class="py-8 text-center rounded-xl" style="border: 1px dashed var(--border); color: var(--text-muted);">
                    No steps yet. Click "+ Add Step" to build your pipeline.
                </div>
            </div>

            {{-- Dependency Chain Visualisation --}}
            <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);" x-show="steps.length > 0">
                <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Pipeline Flow</h2>
                <div class="font-mono text-xs leading-relaxed" style="color: var(--text-secondary);">
                    <template x-for="line in dependencyTree" :key="line">
                        <div x-html="line"></div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <script>
        function pipelineEditor() {
            return {
                steps: @json($stepsJson),
                editingStepId: null,
                editForm: {},
                saving: false,
                toast: false,
                toastMessage: '',
                toastType: 'success',
                dragIdx: null,

                templateId: {{ $template->id }},

                toggleStep(step) {
                    if (this.editingStepId === step.id) {
                        this.editingStepId = null;
                        return;
                    }
                    this.editingStepId = step.id || 'new';
                    this.editForm = { ...step };
                },

                cancelEdit() {
                    // If new step (no id), remove it
                    if (!this.editForm.id) {
                        this.steps = this.steps.filter(s => s.id);
                    }
                    this.editingStepId = null;
                    this.editForm = {};
                },

                addNewStep() {
                    const newStep = {
                        id: null,
                        name: '',
                        description: '',
                        position: this.steps.length + 1,
                        is_locked: false,
                        is_milestone: false,
                        completion_type: 'manual_tick',
                        trigger_type: 'after_step',
                        trigger_step_id: this.steps.length > 0 ? this.steps[this.steps.length - 1].id : null,
                        days_offset: 7,
                        rag_green_days: 14,
                        rag_amber_days: 7,
                        rag_red_days: 3,
                        notify_agent: true,
                        notify_bm: true,
                        notify_admin: false,
                        status_trigger: null,
                        negative_status_trigger: null,
                        negative_outcome_label: null,
                        requires_bm_approval: false,
                    };
                    this.steps.push(newStep);
                    this.editingStepId = 'new';
                    this.editForm = { ...newStep };
                },

                async saveStep(step) {
                    this.saving = true;
                    const isNew = !step.id;
                    const url = isNew
                        ? `/deals-v2/pipeline-setup/${this.templateId}/steps`
                        : `/deals-v2/pipeline-setup/steps/${step.id}`;
                    const method = isNew ? 'POST' : 'PUT';

                    try {
                        const { data } = await axios({
                            method,
                            url,
                            data: {
                                name: this.editForm.name,
                                description: this.editForm.description || null,
                                is_locked: this.editForm.is_locked ? 1 : 0,
                                is_milestone: this.editForm.is_milestone ? 1 : 0,
                                completion_type: this.editForm.completion_type,
                                trigger_type: this.editForm.trigger_type,
                                trigger_step_id: this.editForm.trigger_type === 'after_step' ? this.editForm.trigger_step_id : null,
                                days_offset: parseInt(this.editForm.days_offset) || 0,
                                rag_green_days: parseInt(this.editForm.rag_green_days) || 14,
                                rag_amber_days: parseInt(this.editForm.rag_amber_days) || 7,
                                rag_red_days: parseInt(this.editForm.rag_red_days) || 3,
                                notify_agent: this.editForm.notify_agent ? 1 : 0,
                                notify_bm: this.editForm.notify_bm ? 1 : 0,
                                notify_admin: this.editForm.notify_admin ? 1 : 0,
                                status_trigger: this.editForm.status_trigger || null,
                                negative_status_trigger: this.editForm.negative_status_trigger || null,
                                negative_outcome_label: this.editForm.negative_outcome_label || null,
                                requires_bm_approval: this.editForm.requires_bm_approval ? 1 : 0,
                            },
                        });

                        if (data.success) {
                            const idx = this.steps.findIndex(s => s === step);
                            if (idx !== -1) {
                                this.steps[idx] = data.step;
                            }
                            this.editingStepId = null;
                            this.showToast('Step saved.', 'success');
                        }
                    } catch (err) {
                        const msg = err.response?.data?.message || err.response?.data?.errors
                            ? Object.values(err.response.data.errors).flat().join(', ')
                            : 'Failed to save step.';
                        this.showToast(msg, 'error');
                    }

                    this.saving = false;
                },

                async deleteStep(step) {
                    if (step.is_locked) return;
                    if (!step.id) {
                        this.steps = this.steps.filter(s => s !== step);
                        return;
                    }
                    if (!confirm('Delete step "' + step.name + '"?')) return;

                    try {
                        const { data } = await axios.delete(`/deals-v2/pipeline-setup/steps/${step.id}`);
                        if (data.success) {
                            this.steps = this.steps.filter(s => s.id !== step.id);
                            this.showToast('Step deleted.', 'success');
                        }
                    } catch (err) {
                        this.showToast(err.response?.data?.message || 'Failed to delete step.', 'error');
                    }
                },

                // Drag-and-drop reorder
                dragStart(e, idx) {
                    this.dragIdx = idx;
                    e.dataTransfer.effectAllowed = 'move';
                },
                dragOver(e, idx) {
                    if (this.dragIdx === null || this.dragIdx === idx) return;
                    const moved = this.steps.splice(this.dragIdx, 1)[0];
                    this.steps.splice(idx, 0, moved);
                    this.dragIdx = idx;
                },
                async drop(e, idx) {
                    if (this.dragIdx === null) return;
                    // Update positions
                    const payload = this.steps.map((s, i) => ({ id: s.id, position: i + 1 })).filter(s => s.id);
                    try {
                        await axios.post(`/deals-v2/pipeline-setup/${this.templateId}/steps/reorder`, { steps: payload });
                        this.steps.forEach((s, i) => s.position = i + 1);
                    } catch (err) {
                        this.showToast('Failed to reorder.', 'error');
                    }
                },
                dragEnd() { this.dragIdx = null; },

                showToast(message, type = 'success') {
                    this.toastMessage = message;
                    this.toastType = type;
                    this.toast = true;
                    setTimeout(() => { this.toast = false; }, 3000);
                },

                completionLabel(type) {
                    const map = {
                        manual_tick: 'Tick', date_input: 'Date', amount_input: 'Amount',
                        document_upload: 'Upload', document_signed: 'Signed', text_input: 'Text',
                        multi_field: 'Multi', auto_from_linked_deal: 'Auto',
                    };
                    return map[type] || type;
                },

                triggerLabel(step) {
                    if (step.trigger_type === 'on_creation') return 'On creation';
                    if (step.trigger_type === 'manual') return 'Manual';
                    if (step.trigger_type === 'on_date') return 'On date +' + step.days_offset + 'd';
                    if (step.trigger_type === 'after_step') {
                        const ts = this.steps.find(s => s.id == step.trigger_step_id);
                        const name = ts ? ts.name : (step.trigger_step_name || '?');
                        return 'After ' + name + ' +' + step.days_offset + 'd';
                    }
                    return '';
                },

                get dependencyTree() {
                    // Build tree from steps
                    const roots = [];
                    const childrenMap = {};

                    this.steps.forEach(s => {
                        if (s.trigger_type === 'after_step' && s.trigger_step_id) {
                            if (!childrenMap[s.trigger_step_id]) childrenMap[s.trigger_step_id] = [];
                            childrenMap[s.trigger_step_id].push(s);
                        } else {
                            roots.push(s);
                        }
                    });

                    const lines = [];
                    const render = (step, prefix, isLast) => {
                        const connector = prefix === '' ? '' : (isLast ? '└→ ' : '├→ ');
                        const daysStr = step.days_offset > 0 ? ` <span style="color:var(--text-muted);">(+${step.days_offset}d)</span>` : '';
                        const milestoneStr = step.is_milestone ? ' <span style="color:#60a5fa;">★</span>' : '';
                        const lockedStr = step.is_locked ? ' <span style="color:#fbbf24;">🔒</span>' : '';
                        lines.push(`<span style="color:var(--text-muted);">${prefix}${connector}</span><span style="color:var(--text-primary);">${step.name}</span>${daysStr}${milestoneStr}${lockedStr}`);

                        const children = childrenMap[step.id] || [];
                        children.forEach((child, i) => {
                            const newPrefix = prefix === '' ? '' : prefix + (isLast ? '   ' : '│  ');
                            render(child, prefix === '' ? '  ' : newPrefix, i === children.length - 1);
                        });
                    };

                    roots.forEach((r, i) => render(r, '', i === roots.length - 1));
                    return lines;
                },
            };
        }
    </script>
</x-app-layout>
