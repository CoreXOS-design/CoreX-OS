@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6" x-data="importReview()">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Review Imported Fields</h2>
            <div class="text-sm text-white/60">{{ count($fields) }} fields detected &mdash; review and confirm mappings below</div>
        </div>
        <a href="{{ route('docuperfect.import.index') }}" class="text-sm text-white/60 hover:text-white">&larr; Upload Different File</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- LEFT: Document Preview --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Document Preview</h3>
            </div>
            <div class="p-4 overflow-auto max-h-[75vh]">
                <div class="prose prose-sm max-w-none text-[10pt] leading-tight dark:prose-invert" id="docPreview">
                    {!! $parsed['html'] !!}
                </div>
            </div>
        </div>

        {{-- RIGHT: Field Mappings --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Field Mappings</h3>
                <div class="flex items-center gap-3 text-xs">
                    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> High</span>
                    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Medium</span>
                    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-500"></span> Low</span>
                </div>
            </div>

            <form action="{{ route('docuperfect.import.generate') }}" method="POST" id="generateForm" class="flex flex-col flex-1">
                @csrf

                {{-- Template Name --}}
                <div class="px-4 pt-4 pb-2">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Template Name</label>
                    <input type="text" name="template_name" value="{{ $templateName }}"
                           class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white mt-1"
                           required>
                </div>

                {{-- Fields list --}}
                <div class="flex-1 overflow-auto max-h-[55vh] px-4 pb-2">
                    <div class="space-y-2" id="fieldsList">
                        <template x-for="(field, idx) in fields" :key="idx">
                            <div class="border rounded-lg p-3 transition-all"
                                 :class="{
                                     'border-green-300 bg-green-50/50': field.confidence === 'high',
                                     'border-amber-300 bg-amber-50/50': field.confidence === 'medium',
                                     'border-red-300 bg-red-50/50': field.confidence === 'low',
                                 }"
                                 :id="'field-card-' + idx"
                                 @click="highlightPreviewField(idx)">

                                {{-- Top row: confidence + index --}}
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full"
                                          :class="{
                                              'bg-green-100 text-green-700': field.confidence === 'high',
                                              'bg-amber-100 text-amber-700': field.confidence === 'medium',
                                              'bg-red-100 text-red-700': field.confidence === 'low',
                                          }"
                                          x-text="field.confidence.charAt(0).toUpperCase() + field.confidence.slice(1)"></span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] text-gray-400" x-text="'#' + (idx + 1)"></span>
                                        <button type="button" @click.stop="removeField(idx)" class="text-red-400 hover:text-red-600" title="Remove field">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- Context --}}
                                <p class="text-[10px] text-gray-400 mb-2 font-mono truncate" x-text="field.context" :title="field.context"></p>

                                {{-- Form inputs --}}
                                <div class="grid grid-cols-2 gap-1.5">
                                    <div>
                                        <label class="text-[10px] text-gray-400">Label</label>
                                        <input type="text" :name="'fields[' + idx + '][label]'"
                                               x-model="field.label"
                                               class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-2 py-1">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-gray-400">Field Key</label>
                                        <input type="text" :name="'fields[' + idx + '][key]'"
                                               x-model="field.key"
                                               class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-2 py-1">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-gray-400">Pillar</label>
                                        <select :name="'fields[' + idx + '][pillar]'"
                                                x-model="field.pillar"
                                                class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-2 py-1">
                                            <option value="property">Property</option>
                                            <option value="contact">Contact</option>
                                            <option value="deal">Deal</option>
                                            <option value="agent">Agent</option>
                                            <option value="custom">Custom</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-gray-400">Assigned To</label>
                                        <select :name="'fields[' + idx + '][assigned_to]'"
                                                x-model="field.assigned_to"
                                                class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-2 py-1">
                                            <option value="agent">Agent</option>
                                            <option value="lessor">Lessor</option>
                                            <option value="lessee">Lessee</option>
                                            <option value="buyer">Buyer</option>
                                            <option value="seller">Seller</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-gray-400">Type</label>
                                        <select :name="'fields[' + idx + '][field_type]'"
                                                x-model="field.field_type"
                                                class="w-full text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-2 py-1">
                                            <option value="text">Text</option>
                                            <option value="date">Date</option>
                                            <option value="number">Number</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Empty state --}}
                    <div x-show="fields.length === 0" class="text-center py-8 text-gray-400">
                        <p class="text-sm">No fillable fields detected.</p>
                        <p class="text-xs mt-1">Click "Add Custom Field" to add fields manually.</p>
                    </div>
                </div>

                {{-- Bottom actions --}}
                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 space-y-2">
                    <button type="button" @click="addCustomField()"
                            class="w-full text-xs text-blue-600 dark:text-blue-400 font-medium py-1.5 border border-dashed border-blue-300 dark:border-blue-600 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        + Add Custom Field
                    </button>
                    <div class="flex gap-3">
                        <button type="submit"
                                class="flex-1 bg-blue-600 text-white px-5 py-2.5 rounded-lg font-medium text-sm hover:bg-blue-700 transition-colors">
                            Generate Template
                        </button>
                        <a href="{{ route('docuperfect.import.index') }}"
                           class="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .field-blank { border-bottom: 2px solid #d1d5db; padding: 1px 4px; display: inline-block; min-width: 80px; }
    .field-blank-highlight { background: #fef3c7; border-bottom-color: #f59e0b; outline: 2px solid #f59e0b; outline-offset: 1px; }
    #docPreview .field-blank[data-confidence="high"] { border-bottom-color: #22c55e; }
    #docPreview .field-blank[data-confidence="medium"] { border-bottom-color: #f59e0b; }
    #docPreview .field-blank[data-confidence="low"] { border-bottom-color: #ef4444; }
</style>

<script>
    function importReview() {
        const serverFields = @json($fields);
        let customCounter = serverFields.length;

        return {
            fields: serverFields.map(f => ({
                label: f.suggested_label,
                key: f.suggested_key,
                pillar: f.pillar,
                assigned_to: f.assigned_to,
                field_type: 'text',
                confidence: f.confidence,
                context: f.context || '',
            })),

            init() {
                this.tagPreviewBlanks();
            },

            tagPreviewBlanks() {
                const blanks = document.querySelectorAll('#docPreview .field-blank');
                blanks.forEach((el, i) => {
                    if (this.fields[i]) {
                        el.setAttribute('data-field-index', i);
                        el.setAttribute('data-confidence', this.fields[i].confidence);
                        el.style.cursor = 'pointer';
                        el.addEventListener('click', () => this.scrollToFieldCard(i));
                    }
                });
            },

            scrollToFieldCard(idx) {
                const card = document.getElementById('field-card-' + idx);
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.classList.add('ring-2', 'ring-blue-400');
                    setTimeout(() => card.classList.remove('ring-2', 'ring-blue-400'), 1500);
                }
            },

            highlightPreviewField(idx) {
                document.querySelectorAll('#docPreview .field-blank').forEach(el => el.classList.remove('field-blank-highlight'));
                const blank = document.querySelector('#docPreview .field-blank[data-field-index="' + idx + '"]');
                if (blank) {
                    blank.classList.add('field-blank-highlight');
                    blank.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            },

            addCustomField() {
                customCounter++;
                this.fields.push({
                    label: 'Custom Field ' + customCounter,
                    key: 'custom.field_' + customCounter,
                    pillar: 'custom',
                    assigned_to: 'agent',
                    field_type: 'text',
                    confidence: 'low',
                    context: '(manually added)',
                });
            },

            removeField(idx) {
                this.fields.splice(idx, 1);
            },
        };
    }
</script>
@endsection
