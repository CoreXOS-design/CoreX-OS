@extends('layouts.nexus')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Launch Pack &mdash; {{ $pack->name }}</h2>
                @if($pack->description)
                <div class="text-sm text-white/60 mt-1">{{ $pack->description }}</div>
                @endif
            </div>
            <a href="{{ route('docuperfect.packs.index') }}" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
        <div class="mt-3">
            @if($pack->creation_mode === 'linked')
                <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-1 rounded bg-cyan-500/20 text-cyan-200">
                    <i class="fas fa-link text-[10px]"></i> Linked Pack &mdash; named fields will sync across documents
                </span>
            @else
                <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-1 rounded bg-slate-500/20 text-slate-200">
                    <i class="fas fa-file text-[10px]"></i> Individual Documents &mdash; each document is standalone
                </span>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('docuperfect.packs.launch', $pack->id) }}"
          class="space-y-4"
          x-data="{ prefix: @js($pack->name) }"
          x-effect="document.querySelectorAll('[data-template-name]').forEach(function(el) {
              el.value = prefix ? prefix + ' \u2014 ' + el.dataset.templateName : el.dataset.templateName;
          })">
        @csrf

        {{-- Document name prefix --}}
        <div class="ds-status-card p-4">
            <label class="block text-sm font-semibold text-slate-700 mb-1">Document name prefix</label>
            <input type="text" x-model="prefix"
                   class="w-full rounded border border-slate-200 bg-slate-50 text-slate-700 px-3 py-2 text-sm focus:ring-1 focus:ring-blue-400 focus:border-blue-400 focus:bg-white"
                   placeholder="e.g. Oats" maxlength="200">
            <div class="text-xs text-slate-400 mt-1">This will be added to the front of every document name.</div>
        </div>

        @foreach($pack->slots as $slot)
            <div class="ds-status-card p-4">
                {{-- REQUIRED --}}
                @if($slot->slot_type === 'required')
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-full bg-green-100 text-green-700 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-check text-xs"></i>
                        </span>
                        <div class="flex-1">
                            <div class="text-sm font-semibold text-slate-900">{{ $slot->label }}</div>
                            @if($slot->template)
                                <div class="text-xs text-slate-500">{{ $slot->template->name }}</div>
                                <input type="hidden" name="selected_templates[]" value="{{ $slot->template->id }}">
                                <input type="text"
                                       name="document_names[{{ $slot->template->id }}]"
                                       data-template-name="{{ $slot->template->name }}"
                                       value="{{ $pack->name }} — {{ $slot->template->name }}"
                                       class="mt-1.5 w-full rounded border border-slate-200 bg-slate-50 text-slate-700 px-2 py-1 text-xs focus:ring-1 focus:ring-blue-400 focus:border-blue-400 focus:bg-white"
                                       placeholder="Document name" maxlength="255">
                            @else
                                <div class="text-xs text-red-500">Template not found &mdash; this slot will be skipped.</div>
                            @endif
                        </div>
                        <span class="text-[10px] uppercase tracking-wider text-green-700 font-semibold">Required</span>
                    </div>

                {{-- SELECTABLE --}}
                @elseif($slot->slot_type === 'selectable')
                    <div class="mb-2 flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-list text-xs"></i>
                        </span>
                        <div class="text-sm font-semibold text-slate-900">{{ $slot->label }}</div>
                        @if($slot->is_optional)
                            <span class="text-[10px] text-slate-400">(Optional &mdash; uncheck to skip)</span>
                        @endif
                    </div>

                    @php
                        $typeId = $slot->document_type_id;
                        $typeTemplates = $selectableTemplates[$typeId] ?? collect();
                    @endphp

                    @if($typeTemplates->isEmpty())
                        <div class="text-xs text-slate-400 ml-8">No templates available for this type.</div>
                    @else
                        <div class="ml-8 space-y-2">
                            @foreach($typeTemplates as $tpl)
                                <div x-data="{ checked: false }">
                                    <label class="flex items-center gap-2 text-sm text-slate-700 hover:bg-slate-50 rounded px-2 py-1 cursor-pointer">
                                        @if($slot->allow_multiple)
                                            <input type="checkbox" name="selected_templates[]" value="{{ $tpl->id }}"
                                                   class="rounded border-slate-300"
                                                   x-model="checked">
                                        @else
                                            <input type="radio" name="selectable_slot_{{ $slot->id }}" value="{{ $tpl->id }}"
                                                   class="rounded-full border-slate-300"
                                                   onchange="document.querySelector('#sel_hidden_{{ $slot->id }}').value = this.value"
                                                   @change="checked = true">
                                        @endif
                                        <span>{{ $tpl->name }}</span>
                                        <span class="text-xs text-slate-400 ml-auto">{{ $tpl->page_count }} pg</span>
                                    </label>
                                    <div x-show="checked" x-cloak class="ml-8 mt-1">
                                        <input type="text"
                                               name="document_names[{{ $tpl->id }}]"
                                               data-template-name="{{ $tpl->name }}"
                                               value="{{ $pack->name }} — {{ $tpl->name }}"
                                               class="w-full rounded border border-slate-200 bg-slate-50 text-slate-700 px-2 py-1 text-xs focus:ring-1 focus:ring-blue-400 focus:border-blue-400 focus:bg-white"
                                               placeholder="Document name" maxlength="255">
                                    </div>
                                </div>
                            @endforeach
                            @if(!$slot->allow_multiple)
                                <input type="hidden" name="selected_templates[]" id="sel_hidden_{{ $slot->id }}" value="">
                            @endif
                        </div>
                    @endif

                {{-- ATTACHMENT --}}
                @elseif($slot->slot_type === 'attachment')
                    <div class="mb-2 flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-paperclip text-xs"></i>
                        </span>
                        <div class="text-sm font-semibold text-slate-900">{{ $slot->label }}</div>
                        @if($slot->is_optional)
                            <span class="text-[10px] text-slate-400">(Optional &mdash; uncheck to skip)</span>
                        @endif
                    </div>

                    @php
                        $catId = $slot->knowledge_category_id;
                        $kbDocs = $knowledgeDocuments[$catId] ?? collect();
                    @endphp

                    @if($kbDocs->isEmpty())
                        <div class="text-xs text-slate-400 ml-8">No documents available in this category.</div>
                    @else
                        <div class="ml-8 space-y-1.5">
                            @foreach($kbDocs as $kbDoc)
                                <label class="flex items-center gap-2 text-sm text-slate-700 hover:bg-slate-50 rounded px-2 py-1 cursor-pointer">
                                    @if($slot->allow_multiple)
                                        <input type="checkbox" name="selected_kb_docs[]" value="{{ $kbDoc->id }}"
                                               class="rounded border-slate-300">
                                    @else
                                        <input type="radio" name="kb_slot_{{ $slot->id }}" value="{{ $kbDoc->id }}"
                                               class="rounded-full border-slate-300"
                                               onchange="document.querySelector('#kb_hidden_{{ $slot->id }}').value = this.value">
                                    @endif
                                    <span>{{ $kbDoc->title }}</span>
                                    @if($kbDoc->category)
                                        <span class="text-xs text-slate-400 ml-auto">{{ $kbDoc->category->name }}</span>
                                    @endif
                                </label>
                            @endforeach
                            @if(!$slot->allow_multiple)
                                <input type="hidden" name="selected_kb_docs[]" id="kb_hidden_{{ $slot->id }}" value="">
                            @endif
                        </div>
                    @endif
                @endif
            </div>
        @endforeach

        <div class="pt-2">
            <button type="submit" class="nexus-btn-primary text-sm px-6 py-2.5 w-full md:w-auto" style="background:#10b981;">
                <i class="fas fa-rocket mr-1"></i> Create Documents
            </button>
        </div>
    </form>

</div>
@endsection
