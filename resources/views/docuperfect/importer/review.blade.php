@extends('layouts.corex')

@section('corex-content')
<div class="flex flex-col h-full overflow-hidden"
     x-data="tagEditor()"
     x-init="init()">

    {{-- STICKY HEADER --}}
    <x-page-header
        title="Tag Document Fields — {{ $templateName }}"
        :back-route="route('docuperfect.import.index')"
        back-label="Import"
        :flush="true"
    >
        <x-slot:actions>
            <div class="flex items-center gap-3">
                {{-- Save Draft button --}}
                <div class="flex items-center gap-2">
                    <button type="button" @click="manualSaveDraft()"
                            :disabled="draftSaving"
                            class="text-sm px-3 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 transition-colors flex items-center gap-1.5 whitespace-nowrap disabled:opacity-50">
                        <svg x-show="draftSaving" class="animate-spin h-3.5 w-3.5 text-gray-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="draftSaving ? 'Saving...' : 'Save Draft'"></span>
                    </button>
                    <span class="text-[10px] text-gray-400 whitespace-nowrap" x-show="lastSavedLabel" x-text="lastSavedLabel"></span>
                </div>

                {{-- Undo button --}}
                <button type="button" @click="performUndo()"
                        :disabled="undoStack.length === 0"
                        class="text-sm px-3 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 transition-colors flex items-center gap-1.5 whitespace-nowrap disabled:opacity-40 disabled:cursor-not-allowed">
                    <span>&#8617;</span>
                    <span x-text="undoStack.length > 0 ? 'Undo (' + undoStack.length + ')' : 'Undo'"></span>
                </button>
                <span class="text-xs font-medium px-2 py-1 rounded transition-opacity duration-300"
                      :class="undoToast === 'Undone' ? 'text-emerald-600 bg-emerald-50' : 'text-gray-500 bg-gray-100'"
                      x-show="undoToast" x-transition x-text="undoToast"></span>

                {{-- Completion status + Generate button --}}
                {{-- State 1: Outstanding fields --}}
                <template x-if="!allMapped && totalTagCount > 0">
                    <div class="flex items-center gap-2 bg-amber-100 border border-amber-300 text-amber-800 text-sm px-4 py-2 rounded-lg cursor-pointer"
                         @click="fixNext()">
                        <svg class="w-4 h-4 flex-shrink-0 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                        <span x-text="linkedCount + ' of ' + totalTagCount + ' fields linked — ' + outstandingCount + ' outstanding'"></span>
                        <button type="button" @click.stop="fixNext()"
                                class="ml-1 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold px-3 py-1 rounded transition-colors whitespace-nowrap"
                                x-text="'Fix next → (' + (fixNextPosition) + ' of ' + outstandingCount + ')'">
                        </button>
                    </div>
                </template>

                {{-- State 2: All linked --}}
                <template x-if="allMapped && totalTagCount > 0">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2 bg-emerald-100 border border-emerald-300 text-emerald-800 text-sm px-3 py-2 rounded-lg">
                            <svg class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span x-text="'All ' + totalTagCount + ' fields linked — ready to generate'"></span>
                        </div>
                        <form x-ref="generateForm" method="POST" action="{{ route('docuperfect.import.generate') }}" class="inline">
                            @csrf
                            <input type="hidden" name="draft_id" :value="draftId">
                            <input type="hidden" name="template_name" value="{{ $templateName }}">
                            <button type="submit"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-4 py-2 rounded-lg transition-colors font-semibold">
                                Generate Template &rarr;
                            </button>
                        </form>
                    </div>
                </template>
            </div>
        </x-slot:actions>
    </x-page-header>

    @if(!empty($sourceTemplateId))
    <div class="bg-amber-50 border-b border-amber-200 px-6 py-2 flex items-center gap-2 text-sm text-amber-800 flex-shrink-0">
        <svg class="w-4 h-4 text-amber-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/></svg>
        <span><strong>Editing existing template #{{ $sourceTemplateId }}.</strong> Generating will update the existing template instead of creating a new one.</span>
    </div>
    @endif

    {{-- TWO-PANE AREA --}}
    <div class="flex flex-1 min-h-0">

        {{-- LEFT PANE: Document (70%) --}}
        <div class="w-[70%] h-full overflow-y-auto bg-gray-100" id="doc-pane">
            <div class="py-6 px-4 flex justify-center">
                <div class="doc-tagging-page" id="docContainer" contenteditable="true" spellcheck="false" @click="handleDocClick($event)">
                    {!! $parsed['html'] !!}
                </div>
            </div>

            {{-- Floating formatting toolbar --}}
            <div class="doc-format-toolbar" id="formatToolbar">
                <button type="button" title="Bold (Ctrl+B)" data-cmd="bold"><b>B</b></button>
                <button type="button" title="Italic (Ctrl+I)" data-cmd="italic"><i>I</i></button>
                <button type="button" title="Underline (Ctrl+U)" data-cmd="underline"><u>U</u></button>
                <button type="button" title="Strikethrough" data-cmd="strikeThrough"><s>del</s></button>
                <span class="tb-sep"></span>
                <select id="tbHeadingSelect" title="Text style">
                    <option value="">Normal</option>
                    <option value="heading">Bold Heading</option>
                    <option value="subheading">Subheading</option>
                </select>
                <select id="tbFontSize" title="Font size">
                    <option value="">12pt</option>
                    <option value="8">8</option>
                    <option value="9">9</option>
                    <option value="10">10</option>
                    <option value="11">11</option>
                    <option value="12">12</option>
                    <option value="14">14</option>
                    <option value="16">16</option>
                </select>
            </div>
        </div>

        {{-- RIGHT PANE: Toolbar + Linking (30%) --}}
        <div class="w-[30%] h-full overflow-y-auto bg-white border-l border-gray-200" id="link-pane">
            <div class="p-4">

                {{-- ===== SIGNING PARTIES (collapsible) ===== --}}
                <div class="mb-4 border border-gray-200 rounded-lg overflow-hidden">
                    {{-- Collapsed header --}}
                    <button type="button" @click="partiesExpanded = !partiesExpanded"
                            class="w-full flex items-center justify-between px-3 py-2.5 bg-gray-50 hover:bg-gray-100 transition-colors text-left">
                        <span class="text-xs font-semibold text-gray-700 flex items-center gap-1.5">
                            <span>&#128101;</span> Signing Parties
                            <span class="text-gray-400" x-text="'(' + signingParties.length + ')'"></span>
                        </span>
                        <span class="text-xs text-gray-500" x-text="partiesExpanded ? 'Done ▲' : 'Manage ▼'"></span>
                    </button>

                    {{-- Expanded body --}}
                    <div x-show="partiesExpanded" x-transition class="border-t border-gray-200">
                        <div class="p-3 space-y-1.5">
                            <template x-for="(party, idx) in signingParties" :key="party.id">
                                <div class="flex items-center gap-2 py-1 group">
                                    {{-- Drag handle --}}
                                    <span class="text-gray-300 cursor-grab text-xs select-none" title="Drag to reorder">&#9776;</span>

                                    {{-- Name (editable on click) --}}
                                    <template x-if="editingPartyId !== party.id">
                                        <span class="flex-1 text-xs text-gray-700 cursor-pointer hover:text-gray-900"
                                              @click="startEditParty(party)"
                                              x-text="party.name"></span>
                                    </template>
                                    <template x-if="editingPartyId === party.id">
                                        <input type="text" class="flex-1 text-xs border border-blue-300 rounded px-1.5 py-0.5 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400"
                                               x-ref="partyEditInput"
                                               :value="party.name"
                                               @keydown.enter="saveEditParty(party, $event.target.value)"
                                               @keydown.escape="editingPartyId = null"
                                               @blur="saveEditParty(party, $event.target.value)">
                                    </template>

                                    {{-- Rename button --}}
                                    <button type="button" @click="startEditParty(party)"
                                            class="text-[10px] text-gray-400 hover:text-blue-500 opacity-0 group-hover:opacity-100 transition-opacity"
                                            x-show="editingPartyId !== party.id">rename</button>

                                    {{-- Delete --}}
                                    <button type="button" @click="deleteParty(party)"
                                            class="text-gray-300 hover:text-red-500 text-xs opacity-0 group-hover:opacity-100 transition-opacity"
                                            title="Delete party">&#128465;</button>
                                </div>
                            </template>

                            {{-- Add new party --}}
                            <div class="pt-2 border-t border-gray-100">
                                <template x-if="!addingParty">
                                    <button type="button" @click="addingParty = true"
                                            class="text-xs text-blue-600 hover:text-blue-700 font-medium">+ Add Party</button>
                                </template>
                                <template x-if="addingParty">
                                    <div class="flex items-center gap-1.5">
                                        <input type="text" x-ref="newPartyInput" placeholder="Party name..."
                                               class="flex-1 text-xs border border-gray-300 rounded px-1.5 py-1 bg-white"
                                               @keydown.enter="confirmAddParty($event.target.value)"
                                               @keydown.escape="addingParty = false">
                                        <button type="button" @click="confirmAddParty($refs.newPartyInput.value)"
                                                class="text-xs bg-blue-600 text-white px-2 py-0.5 rounded hover:bg-blue-700">Add</button>
                                        <button type="button" @click="addingParty = false"
                                                class="text-xs text-gray-400 hover:text-gray-600">Cancel</button>
                                    </div>
                                </template>
                            </div>

                            <p class="text-[10px] text-gray-400 pt-1">Saved for your agency</p>
                        </div>
                    </div>
                </div>

                {{-- ===== TAG TOOLS ===== --}}
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Tag Tools</h3>
                <div class="flex gap-2 mb-4">
                    <button type="button" @click="setActiveTool('input')"
                            :class="activeTool === 'input' ? 'ring-2 ring-red-500 bg-red-50' : 'bg-white hover:bg-gray-50'"
                            class="flex-1 text-center px-2 py-2 border border-gray-200 rounded-lg transition-all text-xs">
                        <span class="inline-block w-2 h-2 rounded-full bg-red-500 mr-1"></span>Input
                        <span class="text-gray-400" x-text="'(' + counts.input + ')'"></span>
                    </button>
                    <button type="button" @click="setActiveTool('signature')"
                            :class="activeTool === 'signature' ? 'ring-2 ring-yellow-500 bg-yellow-50' : 'bg-white hover:bg-gray-50'"
                            class="flex-1 text-center px-2 py-2 border border-gray-200 rounded-lg transition-all text-xs">
                        <span class="inline-block w-2 h-2 rounded-full bg-yellow-400 mr-1"></span>Sig
                        <span class="text-gray-400" x-text="'(' + counts.signature + ')'"></span>
                    </button>
                    <button type="button" @click="setActiveTool('initial')"
                            :class="activeTool === 'initial' ? 'ring-2 ring-green-500 bg-green-50' : 'bg-white hover:bg-gray-50'"
                            class="flex-1 text-center px-2 py-2 border border-gray-200 rounded-lg transition-all text-xs">
                        <span class="inline-block w-2 h-2 rounded-full bg-green-500 mr-1"></span>Ini
                        <span class="text-gray-400" x-text="'(' + counts.initial + ')'"></span>
                    </button>
                </div>
                <p class="text-[10px] text-gray-400 mb-1" x-show="activeTool" x-transition>
                    Click in the document to place a <span x-text="activeTool"></span> tag.
                    <a href="#" @click.prevent="setActiveTool(null)" class="underline">Cancel</a>
                </p>

                {{-- ===== SECTION 1: INPUT FIELDS ===== --}}
                <template x-if="inputTags.length > 0">
                    <div class="mt-4">
                        <hr class="border-gray-200 mb-4">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">
                            Input Fields <span class="text-gray-400" x-text="'(' + inputTags.length + ')'"></span>
                        </h3>
                        <div class="space-y-3">
                            <template x-for="tag in inputTags" :key="tag.id">
                                <div :id="'tag-row-' + tag.id"
                                     class="border rounded-lg p-3 transition-all duration-200"
                                     :class="selectedTagId === tag.id
                                         ? 'border-teal-400 bg-teal-50 ring-2 ring-teal-300'
                                         : 'border-gray-200 bg-gray-50'">
                                    {{-- Tag label + confidence dot + locate + delete --}}
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="flex-shrink-0 text-xs font-bold w-4 text-center"
                                              :class="isTagComplete(tag) ? 'text-emerald-500' : 'text-red-400'"
                                              x-text="isTagComplete(tag) ? '✓' : '✗'"></span>
                                        <span :class="getRowLabelClass(tag)" x-text="getDisplayLabel(tag)"></span>
                                        <template x-if="getMapping(tag.id).confidence">
                                            <span class="w-2 h-2 rounded-full flex-shrink-0"
                                                  :class="{
                                                      'bg-green-500': getMapping(tag.id).confidence === 'high',
                                                      'bg-orange-400': getMapping(tag.id).confidence === 'medium',
                                                      'bg-gray-300': getMapping(tag.id).confidence === 'low',
                                                  }"
                                                  :title="'Parser confidence: ' + getMapping(tag.id).confidence"></span>
                                        </template>
                                        <template x-if="selectedTagId === tag.id">
                                            <a href="#" @click.prevent="scrollToDocTag(tag.id)"
                                               class="text-[10px] text-teal-600 hover:text-teal-800 whitespace-nowrap">&larr; in document</a>
                                        </template>
                                        <button @click="removeTag(tag.id)" class="ml-auto text-gray-400 hover:text-red-500 text-xs flex items-center gap-0.5" title="Delete tag">
                                            <span>&#128465;</span> <span class="sr-only">Delete</span>
                                        </button>
                                    </div>

                                    {{-- Type dropdown — single fields + field groups --}}
                                    <select class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 mb-1.5 bg-white"
                                            :value="getMapping(tag.id).typeKey"
                                            @change="setType(tag.id, $event.target.value)">
                                        <option value="">Select type...</option>
                                        <optgroup label="Single Field">
                                            <option value="sf:property">Property</option>
                                            <option value="sf:contact_lessor">Contact &mdash; Lessor</option>
                                            <option value="sf:contact_lessee">Contact &mdash; Lessee</option>
                                            <option value="sf:contact_seller">Contact &mdash; Seller</option>
                                            <option value="sf:contact_buyer">Contact &mdash; Buyer</option>
                                            <option value="sf:agent">Agent</option>
                                            <option value="sf:computed">Computed</option>
                                            <option value="sf:static">Static</option>
                                            <option value="sf:manual">Manual</option>
                                        </optgroup>
                                        <optgroup label="Field Group">
                                            <template x-for="fg in fieldGroups" :key="'fg-' + fg.id">
                                                <option :value="'fg:' + fg.id" x-text="fg.name"></option>
                                            </template>
                                        </optgroup>
                                    </select>

                                    {{-- Field dropdown (single field, not manual) --}}
                                    <template x-if="getMapping(tag.id).mappingType === 'named_field' && getMapping(tag.id).typeKey && getMapping(tag.id).typeKey !== 'sf:manual'">
                                        <select class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 mb-1.5 bg-white"
                                                :value="getMapping(tag.id).namedFieldId || ''"
                                                @change="setNamedField(tag.id, parseInt($event.target.value))">
                                            <option value="">Select field...</option>
                                            <template x-for="nf in getFieldsForType(getMapping(tag.id).typeKey)" :key="nf.id">
                                                <option :value="nf.id" x-text="nf.name"></option>
                                            </template>
                                        </select>
                                    </template>

                                    {{-- Manual label input --}}
                                    <template x-if="getMapping(tag.id).typeKey === 'sf:manual'">
                                        <input type="text" placeholder="Custom field label..."
                                               class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 mb-1.5 bg-white"
                                               :value="getMapping(tag.id).manualLabel"
                                               @input.debounce.300ms="setManualLabel(tag.id, $event.target.value)">
                                    </template>

                                    {{-- Field group preview --}}
                                    <template x-if="getMapping(tag.id).mappingType === 'field_group'">
                                        <div class="text-[10px] text-gray-500 bg-white rounded px-2 py-1.5 mb-1.5 border border-gray-200">
                                            <span class="font-semibold">Contains:</span>
                                            <span x-text="getGroupPreview(getMapping(tag.id).fieldGroupId)"></span>
                                        </div>
                                    </template>

                                    {{-- Party dropdown --}}
                                    <select class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white"
                                            :value="getMapping(tag.id).party"
                                            :disabled="getMapping(tag.id).partyLocked"
                                            @change="setParty(tag.id, $event.target.value)">
                                        <option value="auto">Auto-fill</option>
                                        <option value="agent">Agent</option>
                                        <option value="lessor">Lessor</option>
                                        <option value="lessee">Lessee</option>
                                        <option value="buyer">Buyer</option>
                                        <option value="seller">Seller</option>
                                    </select>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- ===== SECTION 2: SIGNATURE BLOCKS ===== --}}
                <template x-if="sigTags.length > 0">
                    <div class="mt-4">
                        <hr class="border-gray-200 mb-4">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">
                            Signatures <span class="text-gray-400" x-text="'(' + sigTags.length + ')'"></span>
                        </h3>
                        <div class="space-y-3">
                            <template x-for="tag in sigTags" :key="tag.id">
                                <div :id="'tag-row-' + tag.id"
                                     class="border rounded-lg p-3 transition-all duration-200"
                                     :class="selectedTagId === tag.id
                                         ? 'border-teal-400 bg-teal-50 ring-2 ring-teal-300'
                                         : 'border-yellow-200 bg-yellow-50/50'">
                                    {{-- Header row --}}
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="flex-shrink-0 text-xs font-bold w-4 text-center"
                                              :class="isTagComplete(tag) ? 'text-emerald-500' : 'text-red-400'"
                                              x-text="isTagComplete(tag) ? '✓' : '✗'"></span>
                                        <span :class="getRowLabelClass(tag)" x-text="getDisplayLabel(tag)"></span>
                                        <span class="text-[10px] text-gray-400">Signature Block</span>
                                        <template x-if="selectedTagId === tag.id">
                                            <a href="#" @click.prevent="scrollToDocTag(tag.id)"
                                               class="text-[10px] text-teal-600 hover:text-teal-800 whitespace-nowrap">&larr; in document</a>
                                        </template>
                                        <button @click="removeTag(tag.id)" class="ml-auto text-gray-400 hover:text-red-500 text-xs flex items-center gap-0.5" title="Delete tag">
                                            <span>&#128465;</span> <span class="sr-only">Delete</span>
                                        </button>
                                    </div>

                                    {{-- Variant selector --}}
                                    <div class="mb-2">
                                        <span class="text-[10px] font-semibold text-gray-500 uppercase">Variant:</span>
                                        <div class="mt-1 space-y-1">
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                <input type="radio" :name="'sig-variant-' + tag.id" value="sig_only"
                                                       :checked="getSigVariant(tag.id) === 'sig_only'"
                                                       @change="setSigVariant(tag.id, 'sig_only')"
                                                       class="w-3 h-3 text-blue-600">
                                                Signature only
                                            </label>
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                <input type="radio" :name="'sig-variant-' + tag.id" value="sig_with_location"
                                                       :checked="getSigVariant(tag.id) === 'sig_with_location'"
                                                       @change="setSigVariant(tag.id, 'sig_with_location')"
                                                       class="w-3 h-3 text-blue-600">
                                                Signed at (location + date)
                                            </label>
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                <input type="radio" :name="'sig-variant-' + tag.id" value="sig_full"
                                                       :checked="getSigVariant(tag.id) === 'sig_full'"
                                                       @change="setSigVariant(tag.id, 'sig_full')"
                                                       class="w-3 h-3 text-blue-600">
                                                Full acceptance clause
                                            </label>
                                        </div>
                                    </div>

                                    {{-- Party checkboxes --}}
                                    <div class="mb-2">
                                        <span class="text-[10px] font-semibold text-gray-500 uppercase">Parties signing here:</span>
                                        <div class="mt-1 space-y-1">
                                            <template x-for="sp in signingParties" :key="'sig-' + tag.id + '-' + sp.id">
                                                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                    <input type="checkbox"
                                                           :checked="isSigPartyChecked(tag.id, sp.name)"
                                                           @change="toggleSigParty(tag.id, sp.name, $event.target.checked)"
                                                           class="w-3 h-3 text-blue-600 rounded">
                                                    <span x-text="sp.name"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>

                                    {{-- Live preview --}}
                                    <div class="bg-white border border-gray-200 rounded p-2 text-[10px] text-gray-600 leading-relaxed"
                                         x-show="getSigParties(tag.id).length > 0">
                                        <span class="font-semibold text-gray-500">Preview:</span>
                                        <div class="mt-1 font-mono" x-html="renderSigPreview(tag.id)"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- ===== SECTION 3: INITIAL BLOCKS ===== --}}
                <template x-if="iniTags.length > 0">
                    <div class="mt-4">
                        <hr class="border-gray-200 mb-4">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">
                            Initials <span class="text-gray-400" x-text="'(' + iniTags.length + ')'"></span>
                        </h3>
                        <div class="space-y-3">
                            <template x-for="tag in iniTags" :key="tag.id">
                                <div :id="'tag-row-' + tag.id"
                                     class="border rounded-lg p-3 transition-all duration-200"
                                     :class="selectedTagId === tag.id
                                         ? 'border-teal-400 bg-teal-50 ring-2 ring-teal-300'
                                         : 'border-green-200 bg-green-50/50'">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="flex-shrink-0 text-xs font-bold w-4 text-center"
                                              :class="isTagComplete(tag) ? 'text-emerald-500' : 'text-red-400'"
                                              x-text="isTagComplete(tag) ? '✓' : '✗'"></span>
                                        <span :class="getRowLabelClass(tag)" x-text="getDisplayLabel(tag)"></span>
                                        <template x-if="selectedTagId === tag.id">
                                            <a href="#" @click.prevent="scrollToDocTag(tag.id)"
                                               class="text-[10px] text-teal-600 hover:text-teal-800 whitespace-nowrap">&larr; in document</a>
                                        </template>
                                        <button @click="removeTag(tag.id)" class="ml-auto text-gray-400 hover:text-red-500 text-xs flex items-center gap-0.5" title="Delete tag">
                                            <span>&#128465;</span> <span class="sr-only">Delete</span>
                                        </button>
                                    </div>
                                    {{-- Party checkboxes --}}
                                    <div>
                                        <span class="text-[10px] font-semibold text-gray-500 uppercase">Parties initialling here:</span>
                                        <div class="mt-1 space-y-1">
                                            <template x-for="sp in signingParties" :key="'ini-' + tag.id + '-' + sp.id">
                                                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                    <input type="checkbox"
                                                           :checked="isSigPartyChecked(tag.id, sp.name)"
                                                           @change="toggleSigParty(tag.id, sp.name, $event.target.checked)"
                                                           class="w-3 h-3 text-green-600 rounded">
                                                    <span x-text="sp.name"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Save status --}}
                <div class="mt-4 text-xs text-gray-400" x-show="saveStatus" x-text="saveStatus" x-transition></div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Kill the outer page scroll — this page manages its own panels */
    #appScroll {
        overflow: hidden !important;
        padding: 0 !important;
    }

    .doc-tagging-page {
        width: 210mm; min-height: 297mm; margin: 0 auto;
        padding: 18mm 20mm 15mm 20mm; background: white;
        box-shadow: 0 2px 16px rgba(0,0,0,0.15);
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10pt; line-height: 1.2; color: #1a1a1a; cursor: default;
    }
    .field-blank {
        display: inline; border-bottom: 1pt solid #1a1a1a;
        padding: 0 4pt; min-width: 80pt; background: #fef3c7;
        font-size: 9pt; font-weight: 600; cursor: pointer;
    }
    .doc-tag {
        display: inline; padding: 1px 6px; border-radius: 3px;
        font-size: 8pt; font-weight: 700; letter-spacing: 0.3pt;
        cursor: pointer; user-select: none; white-space: nowrap;
        vertical-align: baseline; line-height: inherit;
        transition: background 0.15s, box-shadow 0.15s;
    }
    .doc-tag:hover { opacity: 0.85; }

    /* INPUT: unlinked = red */
    .doc-tag-input { background: #dc2626; color: #fff; }
    /* INPUT: linked (named_field or field_group) = teal */
    .doc-tag-input-linked { background: #0d9488; color: #fff; }
    /* INPUT: linked manual = orange */
    .doc-tag-input-manual { background: #ea580c; color: #fff; }

    /* SIG: no party = yellow */
    .doc-tag-signature { background: #f59e0b; color: #1a1a1a; }
    /* SIG: party assigned = amber/gold */
    .doc-tag-signature-assigned { background: #b45309; color: #fff; }

    /* INI: no party = green */
    .doc-tag-initial { background: #16a34a; color: #fff; }
    /* INI: party assigned = darker green */
    .doc-tag-initial-assigned { background: #15803d; color: #fff; }

    /* Selected tag pulse */
    .doc-tag-selected {
        box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.5);
        animation: tag-pulse 1.2s ease-in-out 2;
    }
    @keyframes tag-pulse {
        0%, 100% { box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.5); }
        50% { box-shadow: 0 0 0 6px rgba(20, 184, 166, 0.25); }
    }

    /* Floating formatting toolbar */
    .doc-format-toolbar {
        position: absolute;
        z-index: 9999;
        display: none;
        background: #1f2937;
        border-radius: 6px;
        padding: 4px 6px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.25);
        gap: 2px;
        align-items: center;
        flex-wrap: nowrap;
        pointer-events: auto;
    }
    .doc-format-toolbar.visible {
        display: flex;
    }
    .doc-format-toolbar::after {
        content: '';
        position: absolute;
        bottom: -6px;
        left: 50%;
        transform: translateX(-50%);
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-top: 6px solid #1f2937;
    }
    .doc-format-toolbar button {
        background: transparent;
        border: none;
        color: #e5e7eb;
        font-size: 12px;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 3px;
        cursor: pointer;
        line-height: 1;
        min-width: 28px;
        text-align: center;
    }
    .doc-format-toolbar button:hover {
        background: #374151;
        color: #fff;
    }
    .doc-format-toolbar button.active {
        background: #4b5563;
        color: #fff;
    }
    .doc-format-toolbar .tb-sep {
        width: 1px;
        height: 18px;
        background: #4b5563;
        margin: 0 3px;
    }
    .doc-format-toolbar select {
        background: #374151;
        color: #e5e7eb;
        border: none;
        font-size: 11px;
        padding: 3px 4px;
        border-radius: 3px;
        cursor: pointer;
        outline: none;
    }
    .doc-format-toolbar select:hover {
        background: #4b5563;
    }

    /* Signature strip placeholder styling inside editable doc */
    .sig-strip-placeholder:hover {
        background: #fef3c7 !important;
        border-color: #d97706 !important;
    }

    .doc-tagging-page.tool-active { cursor: crosshair; }
    .doc-tagging-page.tool-active .doc-tag { cursor: default; }
    .doc-tagging-page p { margin: 0 0 2pt 0; }
    .doc-tagging-page .clause { margin: 2pt 0; padding-left: 20pt; text-indent: -20pt; }
    .doc-tagging-page .clause-number { font-weight: bold; display: inline; }
    .doc-tagging-page .clause-text { display: inline; }
    .doc-tagging-page .section-label { font-weight: bold; text-decoration: underline; margin: 10pt 0 6pt; }
    .doc-tagging-page table { width: 100%; border-collapse: collapse; }
    .doc-tagging-page td { padding: 3pt 4pt; vertical-align: bottom; }
</style>

<script>
function tagEditor() {
    return {
        activeTool: null,
        selectedTagId: null,
        tags: [],
        mappings: {},
        counts: { input: 0, signature: 0, initial: 0 },
        saveStatus: '',
        draftId: @json($draftId),
        csrfToken: '{{ csrf_token() }}',
        parserFields: @json($fields ?? []),

        // Draft save state
        draftSaving: false,
        lastSavedAt: null,
        lastSavedLabel: '',
        _autoSaveInterval: null,
        _lastSavedTimer: null,
        draftSaveUrl: @json(route('docuperfect.import.draft.save')),
        templateName: @json($templateName),

        // DB-driven data
        groupedFields: @json($groupedFields ?? (object)[]),
        fieldGroups: @json($fieldGroups ?? []),
        namedFieldsAll: @json($namedFieldsAll ?? []),

        // Signing parties (agency-level)
        signingParties: @json($signingParties ?? []),
        partiesExpanded: false,
        editingPartyId: null,
        addingParty: false,
        partiesUrl: @json(route('docuperfect.import.parties.index')),

        // Saved state from draft (for restore on revisit)
        hasSavedState: @json($hasSavedState ?? false),
        savedTags: @json($savedTags ?? []),
        savedMappings: @json($savedMappings ?? (object)[]),
        savedTaggedHtml: @json($savedTaggedHtml ?? ''),

        // Undo stack for tag actions (max 20)
        undoStack: [],
        undoToast: '',
        _undoToastTimer: null,

        get inputTags() { return this.tags.filter(t => t.type === 'input'); },
        get sigTags() { return this.tags.filter(t => t.type === 'signature'); },
        get iniTags() { return this.tags.filter(t => t.type === 'initial'); },

        // ===== Completion tracking =====

        fixNextIdx: 0,

        isTagComplete(tag) {
            const m = this.mappings[tag.id] || {};
            if (tag.type === 'input') {
                if (!m.mappingType) return false;
                if (m.mappingType === 'manual') return !!m.manualLabel;
                if (m.mappingType === 'named_field') return !!m.namedFieldId;
                if (m.mappingType === 'field_group') return !!m.fieldGroupId;
                return false;
            }
            if (tag.type === 'signature') return (m.parties || []).length > 0;
            if (tag.type === 'initial') return (m.parties || []).length > 0;
            return false;
        },

        get incompleteTags() {
            // INPUT first (by number), then SIG (by number), then INI (by number)
            const all = [
                ...this.inputTags.filter(t => !this.isTagComplete(t)),
                ...this.sigTags.filter(t => !this.isTagComplete(t)),
                ...this.iniTags.filter(t => !this.isTagComplete(t)),
            ];
            return all;
        },

        get totalTagCount() {
            return this.tags.length;
        },

        get linkedCount() {
            return this.tags.filter(t => this.isTagComplete(t)).length;
        },

        get outstandingCount() {
            return this.totalTagCount - this.linkedCount;
        },

        get fixNextPosition() {
            const count = this.incompleteTags.length;
            if (count === 0) return 0;
            return (this.fixNextIdx % count) + 1;
        },

        fixNext() {
            const incomplete = this.incompleteTags;
            if (incomplete.length === 0) return;
            const idx = this.fixNextIdx % incomplete.length;
            const tag = incomplete[idx];
            this.selectTag(tag.id);
            this.fixNextIdx = idx + 1;
        },

        get allMapped() {
            if (this.totalTagCount === 0) return false;
            return this.outstandingCount === 0;
        },

        // ===== Draft save =====

        async manualSaveDraft() {
            await this._doSaveDraft(true);
        },

        async _doSaveDraft(showToast) {
            this.draftSaving = true;
            try {
                const response = await fetch(this.draftSaveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        draft_id: this.draftId,
                        tags: this.tags,
                        mappings: this.mappings,
                        tagged_html: document.getElementById('docContainer').innerHTML,
                        template_name: this.templateName,
                    }),
                });
                if (response.ok) {
                    const data = await response.json();
                    this.lastSavedAt = new Date(data.saved_at || Date.now());
                    this._updateLastSavedLabel();
                    if (showToast) {
                        this.saveStatus = 'Draft saved';
                        setTimeout(() => { this.saveStatus = ''; }, 2000);
                    }
                } else {
                    if (showToast) {
                        this.saveStatus = 'Save failed';
                        setTimeout(() => { this.saveStatus = ''; }, 3000);
                    }
                }
            } catch (e) {
                console.error('Draft save error:', e);
                if (showToast) {
                    this.saveStatus = 'Save failed';
                    setTimeout(() => { this.saveStatus = ''; }, 3000);
                }
            }
            this.draftSaving = false;
        },

        _updateLastSavedLabel() {
            if (!this.lastSavedAt) { this.lastSavedLabel = ''; return; }
            const diff = Math.floor((Date.now() - this.lastSavedAt.getTime()) / 1000);
            if (diff < 10) this.lastSavedLabel = 'Saved just now';
            else if (diff < 60) this.lastSavedLabel = 'Saved ' + diff + 's ago';
            else if (diff < 3600) this.lastSavedLabel = 'Saved ' + Math.floor(diff / 60) + 'm ago';
            else this.lastSavedLabel = 'Saved ' + Math.floor(diff / 3600) + 'h ago';
        },

        _startAutoSave() {
            // Auto-save every 60 seconds
            this._autoSaveInterval = setInterval(() => {
                if (this.tags.length > 0) this._doSaveDraft(false);
            }, 60000);
            // Update "Saved X ago" label every 15 seconds
            this._lastSavedTimer = setInterval(() => {
                this._updateLastSavedLabel();
            }, 15000);
        },

        // ===== Init — convert parser blanks to tags + auto-suggest =====

        init() {
            this.$nextTick(() => {
                // ── RESTORE PATH: if we have saved state, inject it instead of regenerating ──
                if (this.hasSavedState) {
                    const container = document.getElementById('docContainer');
                    container.innerHTML = this.savedTaggedHtml;

                    // Restore tags and mappings from saved state
                    this.tags = JSON.parse(JSON.stringify(this.savedTags));
                    this.mappings = JSON.parse(JSON.stringify(this.savedMappings));

                    // Re-attach click handlers on all restored tag spans
                    this.tags.forEach(tag => {
                        const el = container.querySelector(`[data-tag-id="${tag.id}"]`);
                        if (el) {
                            this._attachTagClickHandler(el, tag.id);
                        }
                    });

                    this._updateCounts();
                    this._syncAllTagColors();
                    this._syncAllTagLabels();
                    // Do NOT call _persistMappings — we just loaded, nothing changed
                    this._startAutoSave();
                    this._initFormatToolbar();
                    this._initSigPlaceholderHandler();
                    this._initUndoKeyHandler();
                    return; // skip fresh path
                }

                // ── FRESH PATH: convert parser blanks to tags + auto-suggest ──
                const blanks = document.querySelectorAll('#docContainer .field-blank');
                blanks.forEach((el) => {
                    const tag = this._createTagData('input');
                    tag.parserIndex = parseInt(el.getAttribute('data-index'), 10);

                    el.classList.remove('field-blank');
                    el.classList.add('doc-tag', 'doc-tag-input');
                    el.setAttribute('data-tag-id', tag.id);
                    el.setAttribute('contenteditable', 'false');
                    el.textContent = tag.label;
                    this._attachTagClickHandler(el, tag.id);
                    this.tags.push(tag);

                    // Auto-suggest from parser against real NamedField data
                    const pf = this.parserFields[tag.parserIndex];
                    if (pf && pf.suggested_label && pf.confidence !== 'low') {
                        const match = this._findBestNamedFieldMatch(pf.suggested_label, pf.suggested_key);
                        if (match) {
                            const typeKey = this._typeKeyForNamedField(match);
                            this.mappings[tag.id] = this._makeMapping('named_field', {
                                typeKey: typeKey,
                                namedFieldId: match.id,
                                label: match.name,
                                sourceType: match.source_type,
                                sourceContactType: match.source_contact_type || '',
                                party: this._autoPartyForType(typeKey),
                                partyLocked: typeKey === 'sf:agent',
                                confidence: pf.confidence || 'low',
                            });
                            return;
                        }
                    }
                    // No match — empty mapping
                    this.mappings[tag.id] = this._emptyInputMapping(pf ? (pf.confidence || 'low') : null);
                });
                this._updateCounts();
                this._syncAllTagColors();
                this._syncAllTagLabels();
                if (this.tags.length > 0) this._persistMappings();
                this._startAutoSave();
                this._initFormatToolbar();
                this._initSigPlaceholderHandler();
                this._initUndoKeyHandler();
            });
        },

        // ===== Selection — doc ↔ panel sync =====

        selectTag(tagId) {
            // Deselect previous
            if (this.selectedTagId) {
                const prevEl = document.querySelector(`[data-tag-id="${this.selectedTagId}"]`);
                if (prevEl) prevEl.classList.remove('doc-tag-selected');
            }

            this.selectedTagId = tagId;

            // Highlight tag in document
            const docEl = document.querySelector(`[data-tag-id="${tagId}"]`);
            if (docEl) docEl.classList.add('doc-tag-selected');

            // Scroll BOTH panels to the tag
            this.$nextTick(() => {
                // Scroll right panel (link-pane) to the tag row
                const row = document.getElementById('tag-row-' + tagId);
                const linkPane = document.getElementById('link-pane');
                if (row && linkPane) {
                    const paneRect = linkPane.getBoundingClientRect();
                    const rowRect = row.getBoundingClientRect();
                    const scrollTop = linkPane.scrollTop + (rowRect.top - paneRect.top) - (paneRect.height / 2) + (rowRect.height / 2);
                    linkPane.scrollTo({ top: scrollTop, behavior: 'smooth' });
                }

                // Scroll document pane (doc-pane) to the tag span
                const docPane = document.getElementById('doc-pane');
                if (docEl && docPane) {
                    const paneRect = docPane.getBoundingClientRect();
                    const tagRect = docEl.getBoundingClientRect();
                    const scrollTop = docPane.scrollTop + (tagRect.top - paneRect.top) - (paneRect.height / 2) + (tagRect.height / 2);
                    docPane.scrollTo({ top: scrollTop, behavior: 'smooth' });
                }
            });
        },

        scrollToDocTag(tagId) {
            const docEl = document.querySelector(`[data-tag-id="${tagId}"]`);
            const docPane = document.getElementById('doc-pane');
            if (docEl && docPane) {
                const paneRect = docPane.getBoundingClientRect();
                const tagRect = docEl.getBoundingClientRect();
                const scrollTop = docPane.scrollTop + (tagRect.top - paneRect.top) - (paneRect.height / 2) + (tagRect.height / 2);
                docPane.scrollTo({ top: scrollTop, behavior: 'smooth' });
            }
        },

        // ===== Tag colour sync =====

        _syncTagColor(tagId) {
            const el = document.querySelector(`[data-tag-id="${tagId}"]`);
            if (!el) return;

            const tag = this.tags.find(t => t.id === tagId);
            if (!tag) return;

            const mapping = this.mappings[tagId] || {};

            // Remove all colour classes
            el.classList.remove(
                'doc-tag-input', 'doc-tag-input-linked', 'doc-tag-input-manual',
                'doc-tag-signature', 'doc-tag-signature-assigned',
                'doc-tag-initial', 'doc-tag-initial-assigned'
            );

            if (tag.type === 'input') {
                const mt = mapping.mappingType || '';
                if (mt === 'manual' && mapping.manualLabel) {
                    el.classList.add('doc-tag-input-manual');
                } else if ((mt === 'named_field' && mapping.namedFieldId) || (mt === 'field_group' && mapping.fieldGroupId)) {
                    el.classList.add('doc-tag-input-linked');
                } else {
                    el.classList.add('doc-tag-input');
                }
            } else if (tag.type === 'signature') {
                if ((mapping.parties || []).length > 0) {
                    el.classList.add('doc-tag-signature-assigned');
                } else {
                    el.classList.add('doc-tag-signature');
                }
            } else if (tag.type === 'initial') {
                if ((mapping.parties || []).length > 0) {
                    el.classList.add('doc-tag-initial-assigned');
                } else {
                    el.classList.add('doc-tag-initial');
                }
            }
        },

        _syncAllTagColors() {
            this.tags.forEach(t => this._syncTagColor(t.id));
        },

        // ===== Display labels — reactive panel + document sync =====

        getDisplayLabel(tag) {
            const m = this.mappings[tag.id] || {};
            if (tag.type === 'input') {
                if (m.mappingType === 'named_field' && m.namedFieldId && m.label) return m.label;
                if (m.mappingType === 'field_group' && m.fieldGroupId && m.label) return m.label;
                if (m.mappingType === 'manual' && m.manualLabel) return m.manualLabel;
                return tag.label;
            }
            if (tag.type === 'signature') {
                const sp = m.parties || [];
                if (sp.length > 0) return 'SIG ' + tag.number + ' \u2014 ' + sp.join(', ');
                return tag.label;
            }
            if (tag.type === 'initial') {
                const ip = m.parties || [];
                if (ip.length > 0) return 'INI ' + tag.number + ' \u2014 ' + ip.join(', ');
                return tag.label;
            }
            return tag.label;
        },

        getRowLabelClass(tag) {
            const m = this.mappings[tag.id] || {};
            if (tag.type === 'input') {
                if ((m.mappingType === 'named_field' && m.namedFieldId) || (m.mappingType === 'field_group' && m.fieldGroupId))
                    return 'text-xs font-bold text-teal-600';
                if (m.mappingType === 'manual' && m.manualLabel)
                    return 'text-xs font-bold text-orange-600';
                return 'text-xs font-bold text-red-600';
            }
            if (tag.type === 'signature') {
                return (m.parties || []).length > 0 ? 'text-xs font-bold text-amber-700' : 'text-xs font-bold text-yellow-600';
            }
            if (tag.type === 'initial') {
                return (m.parties || []).length > 0 ? 'text-xs font-bold text-green-800' : 'text-xs font-bold text-green-600';
            }
            return 'text-xs font-bold text-gray-600';
        },

        _syncTagLabel(tagId) {
            const el = document.querySelector(`[data-tag-id="${tagId}"]`);
            const tag = this.tags.find(t => t.id === tagId);
            if (!el || !tag) return;
            const display = this.getDisplayLabel(tag);
            el.textContent = display.startsWith('[') ? display : '[' + display + ']';
        },

        _syncAllTagLabels() {
            this.tags.forEach(t => this._syncTagLabel(t.id));
        },

        // ===== Mapping helpers =====

        getMapping(tagId) {
            return this.mappings[tagId] || this._emptyInputMapping(null);
        },

        _emptyInputMapping(confidence) {
            return {
                mappingType: '',
                typeKey: '',
                namedFieldId: null,
                fieldGroupId: null,
                label: '',
                manualLabel: '',
                party: 'auto',
                partyLocked: false,
                confidence: confidence,
                sigType: 'electronic',
                sourceType: '',
                sourceContactType: '',
            };
        },

        _makeMapping(mappingType, overrides) {
            return Object.assign(this._emptyInputMapping(null), { mappingType }, overrides);
        },

        // ===== Type dropdown handler =====

        setType(tagId, typeKey) {
            if (!typeKey) {
                this.mappings[tagId] = this._emptyInputMapping(this.getMapping(tagId).confidence);
                this._syncTagColor(tagId);
                this._syncTagLabel(tagId);
                this._persistMappings();
                return;
            }

            if (typeKey.startsWith('fg:')) {
                // Field Group selected
                const fgId = parseInt(typeKey.split(':')[1], 10);
                const fg = this.fieldGroups.find(g => g.id === fgId);
                const party = this._autoPartyForFieldGroup(fg);
                this.mappings[tagId] = this._makeMapping('field_group', {
                    typeKey: typeKey,
                    fieldGroupId: fgId,
                    label: fg ? fg.name : '',
                    party: party,
                    partyLocked: !!party && party !== 'auto',
                    confidence: this.getMapping(tagId).confidence,
                });
            } else if (typeKey === 'sf:manual') {
                this.mappings[tagId] = this._makeMapping('manual', {
                    typeKey: typeKey,
                    party: 'auto',
                    partyLocked: false,
                    confidence: this.getMapping(tagId).confidence,
                });
            } else {
                // Single field type
                this.mappings[tagId] = this._makeMapping('named_field', {
                    typeKey: typeKey,
                    party: this._autoPartyForType(typeKey),
                    partyLocked: typeKey === 'sf:agent',
                    confidence: this.getMapping(tagId).confidence,
                });
            }
            this._syncTagColor(tagId);
            this._syncTagLabel(tagId);
            this._persistMappings();
        },

        setNamedField(tagId, nfId) {
            const m = this.getMapping(tagId);
            const nf = this.namedFieldsAll.find(f => f.id === nfId);
            if (m && nf) {
                m.namedFieldId = nf.id;
                m.label = nf.name;
                m.sourceType = nf.source_type;
                m.sourceContactType = nf.source_contact_type || '';
            }
            this.mappings[tagId] = m;
            this._syncTagColor(tagId);
            this._syncTagLabel(tagId);
            this._persistMappings();
        },

        setManualLabel(tagId, label) {
            const m = this.getMapping(tagId);
            m.manualLabel = label;
            m.label = label;
            this.mappings[tagId] = m;
            this._syncTagColor(tagId);
            this._syncTagLabel(tagId);
            this._persistMappings();
        },

        setParty(tagId, party) {
            const m = this.getMapping(tagId);
            m.party = party;
            this.mappings[tagId] = m;
            this._syncTagColor(tagId);
            this._syncTagLabel(tagId);
            this._persistMappings();
        },

        setSigType(tagId, sigType) {
            const m = this.getMapping(tagId);
            m.sigType = sigType;
            this.mappings[tagId] = m;
            this._persistMappings();
        },

        // ===== SIG/INI party checkbox helpers =====

        _emptySigIniMapping(type) {
            return {
                parties: [],
                variant: type === 'signature' ? 'sig_full' : '',
            };
        },

        getSigVariant(tagId) {
            return (this.mappings[tagId] || {}).variant || 'sig_full';
        },

        setSigVariant(tagId, variant) {
            if (!this.mappings[tagId]) this.mappings[tagId] = this._emptySigIniMapping('signature');
            this.mappings[tagId].variant = variant;
            this._persistMappings();
        },

        getSigParties(tagId) {
            return (this.mappings[tagId] || {}).parties || [];
        },

        isSigPartyChecked(tagId, partyName) {
            return (this.getSigParties(tagId)).includes(partyName);
        },

        toggleSigParty(tagId, partyName, checked) {
            if (!this.mappings[tagId]) {
                const tag = this.tags.find(t => t.id === tagId);
                this.mappings[tagId] = this._emptySigIniMapping(tag ? tag.type : 'signature');
            }
            const m = this.mappings[tagId];
            if (!m.parties) m.parties = [];
            if (checked && !m.parties.includes(partyName)) {
                m.parties.push(partyName);
            } else if (!checked) {
                m.parties = m.parties.filter(p => p !== partyName);
            }
            this._syncTagColor(tagId);
            this._syncTagLabel(tagId);
            this._persistMappings();
        },

        renderSigPreview(tagId) {
            const m = this.mappings[tagId] || {};
            const parties = m.parties || [];
            const variant = m.variant || 'sig_full';
            if (parties.length === 0) return '';

            let html = '';

            if (variant === 'sig_full') {
                html += '<div style="margin-bottom:4px;">This agreement has been accepted and signed at ___ on the ___ day of ___ ___</div>';
            } else if (variant === 'sig_with_location') {
                html += '<div style="margin-bottom:4px;">Signed at ___ on ___</div>';
            }

            parties.forEach(p => {
                html += '<div style="margin-top:6px;">_________________________________</div>';
                html += '<div>' + this._escHtml(p) + '</div>';
            });

            return html;
        },

        _escHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

        // ===== Party management AJAX =====

        async startEditParty(party) {
            this.editingPartyId = party.id;
            this.$nextTick(() => {
                const input = this.$refs.partyEditInput;
                if (input) input.focus();
            });
        },

        async saveEditParty(party, newName) {
            this.editingPartyId = null;
            newName = (newName || '').trim();
            if (!newName || newName === party.name) return;

            const oldName = party.name;
            party.name = newName;

            try {
                const res = await fetch(this.partiesUrl + '/' + party.id, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                    body: JSON.stringify({ name: newName }),
                });
                if (!res.ok) party.name = oldName;
            } catch (e) {
                party.name = oldName;
            }
        },

        async deleteParty(party) {
            if (this.signingParties.length <= 1) {
                alert('Cannot delete the last signing party.');
                return;
            }
            if (!confirm('Delete "' + party.name + '"?')) return;

            try {
                const res = await fetch(this.partiesUrl + '/' + party.id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                if (res.ok) {
                    this.signingParties = this.signingParties.filter(p => p.id !== party.id);
                }
            } catch (e) {
                console.error('Delete party error:', e);
            }
        },

        async confirmAddParty(name) {
            name = (name || '').trim();
            if (!name) return;

            try {
                const res = await fetch(this.partiesUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                    body: JSON.stringify({ name }),
                });
                if (res.ok) {
                    const newParty = await res.json();
                    this.signingParties.push(newParty);
                    this.addingParty = false;
                }
            } catch (e) {
                console.error('Add party error:', e);
            }
        },

        // ===== Field lookup helpers =====

        getFieldsForType(typeKey) {
            if (!typeKey || !typeKey.startsWith('sf:')) return [];
            const group = typeKey.substring(3); // e.g. "property", "contact_lessor", "agent"
            return this.groupedFields[group] || [];
        },

        getGroupPreview(fgId) {
            const fg = this.fieldGroups.find(g => g.id === fgId);
            if (!fg || !fg.fields) return '';
            return fg.fields.map(f => f.label).join(', ');
        },

        _typeKeyForNamedField(nf) {
            if (nf.source_type === 'contact' && nf.source_contact_type) {
                return 'sf:contact_' + nf.source_contact_type.toLowerCase();
            }
            return 'sf:' + (nf.source_type || 'manual');
        },

        _autoPartyForType(typeKey) {
            if (typeKey === 'sf:agent') return 'agent';
            if (typeKey === 'sf:contact_lessor') return 'lessor';
            if (typeKey === 'sf:contact_lessee') return 'lessee';
            if (typeKey === 'sf:contact_seller') return 'seller';
            if (typeKey === 'sf:contact_buyer') return 'buyer';
            return 'auto';
        },

        _autoPartyForFieldGroup(fg) {
            if (!fg || !fg.fields || fg.fields.length === 0) return 'auto';
            // Determine party from the group's predominant contact type
            const contactTypes = fg.fields
                .filter(f => f.source_type === 'contact' && f.source_contact_type)
                .map(f => f.source_contact_type.toLowerCase());
            if (contactTypes.length > 0) {
                // Use the most common contact type
                const counts = {};
                contactTypes.forEach(t => { counts[t] = (counts[t] || 0) + 1; });
                return Object.entries(counts).sort((a, b) => b[1] - a[1])[0][0];
            }
            const agentFields = fg.fields.filter(f => f.source_type === 'agent');
            if (agentFields.length > 0) return 'agent';
            return 'auto';
        },

        _findBestNamedFieldMatch(suggestedLabel, suggestedKey) {
            if (!suggestedLabel) return null;
            const needle = suggestedLabel.toLowerCase().trim();

            // Exact name match first
            let match = this.namedFieldsAll.find(nf => nf.name.toLowerCase() === needle);
            if (match) return match;

            // Partial match — suggested label contains named field name or vice versa
            match = this.namedFieldsAll.find(nf => {
                const name = nf.name.toLowerCase();
                return needle.includes(name) || name.includes(needle);
            });
            if (match) return match;

            // Try matching via suggested_key's field portion (e.g. "contact.full_name" → "full_name")
            if (suggestedKey) {
                const parts = suggestedKey.split('.');
                const fieldPart = parts.slice(1).join(' ').replace(/_/g, ' ').toLowerCase();
                if (fieldPart) {
                    match = this.namedFieldsAll.find(nf => {
                        const name = nf.name.toLowerCase().replace(/_/g, ' ');
                        return name.includes(fieldPart) || fieldPart.includes(name);
                    });
                }
            }
            return match || null;
        },

        // ===== Tagging behaviour =====

        setActiveTool(tool) {
            this.activeTool = this.activeTool === tool ? null : tool;
            const container = document.getElementById('docContainer');
            container.classList.toggle('tool-active', !!this.activeTool);
        },

        handleDocClick(event) {
            const clickedTag = event.target.closest('.doc-tag');

            // No tool active — browse mode
            if (!this.activeTool) {
                if (clickedTag && clickedTag.dataset.tagId) {
                    event.stopPropagation();
                    this.selectTag(clickedTag.dataset.tagId);
                }
                return;
            }

            // Tool active — clicking existing tag does nothing
            if (clickedTag) return;

            // Tool active — place new tag
            this._pushUndoState();
            const tag = this._createTagData(this.activeTool);
            const span = this._createTagElement(tag);

            const selection = window.getSelection();
            if (selection && selection.rangeCount > 0) {
                const range = document.caretRangeFromPoint(event.clientX, event.clientY);
                if (range && document.getElementById('docContainer').contains(range.startContainer)) {
                    range.insertNode(span);
                    selection.removeAllRanges();
                    this.tags.push(tag);
                    this.mappings[tag.id] = tag.type === 'input'
                        ? this._emptyInputMapping(null)
                        : this._emptySigIniMapping(tag.type);
                    this._updateCounts();
                    this._syncTagColor(tag.id);
                    this._persistMappings();
                    return;
                }
            }

            const target = event.target.closest('p, div, td, span, li') || event.target;
            if (document.getElementById('docContainer').contains(target)) {
                target.appendChild(document.createTextNode(' '));
                target.appendChild(span);
                this.tags.push(tag);
                this.mappings[tag.id] = tag.type === 'input'
                    ? this._emptyInputMapping(null)
                    : this._emptySigIniMapping(tag.type);
                this._updateCounts();
                this._syncTagColor(tag.id);
                this._persistMappings();
            }
        },

        _attachTagClickHandler(el, tagId) {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!this.activeTool) {
                    this.selectTag(tagId);
                }
                // Tool active → do nothing on existing tag click
            });
        },

        removeTag(tagId) {
            this._pushUndoState();
            const el = document.querySelector(`[data-tag-id="${tagId}"]`);
            if (el) el.remove();
            this.tags = this.tags.filter(t => t.id !== tagId);
            delete this.mappings[tagId];
            if (this.selectedTagId === tagId) this.selectedTagId = null;
            this._renumberTags();
            this._updateCounts();
            this._persistMappings();
        },

        _createTagData(type) {
            const typeCount = this.tags.filter(t => t.type === type).length + 1;
            const prefixes = { input: 'INPUT', signature: 'SIG', initial: 'INI' };
            return {
                id: this._uuid(),
                type: type,
                number: typeCount,
                label: `[${prefixes[type]} ${typeCount}]`,
            };
        },

        _createTagElement(tag) {
            const span = document.createElement('span');
            span.className = `doc-tag doc-tag-${tag.type}`;
            span.setAttribute('data-tag-id', tag.id);
            span.setAttribute('data-tag-type', tag.type);
            span.setAttribute('contenteditable', 'false');
            span.textContent = tag.label;
            this._attachTagClickHandler(span, tag.id);
            return span;
        },

        _renumberTags() {
            const counters = { input: 0, signature: 0, initial: 0 };
            const prefixes = { input: 'INPUT', signature: 'SIG', initial: 'INI' };
            this.tags.forEach(tag => {
                counters[tag.type]++;
                tag.number = counters[tag.type];
                tag.label = `[${prefixes[tag.type]} ${tag.number}]`;
                // Update doc tag text — _syncTagLabel uses getDisplayLabel
                // which may show linked name instead of default label
                this._syncTagLabel(tag.id);
            });
        },

        _updateCounts() {
            this.counts.input = this.tags.filter(t => t.type === 'input').length;
            this.counts.signature = this.tags.filter(t => t.type === 'signature').length;
            this.counts.initial = this.tags.filter(t => t.type === 'initial').length;
        },

        // ===== Persistence =====

        _saveTimer: null,

        async _persistMappings() {
            clearTimeout(this._saveTimer);
            this._saveTimer = setTimeout(() => this._doSave(), 400);
        },

        async _doSave() {
            this.saveStatus = 'Saving...';
            try {
                const response = await fetch('{{ route("docuperfect.import.review.mappings") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        draft_id: this.draftId,
                        tags: this.tags,
                        mappings: this.mappings,
                        tagged_html: document.getElementById('docContainer').innerHTML,
                    }),
                });
                if (response.ok) {
                    this.saveStatus = 'Saved';
                    setTimeout(() => { this.saveStatus = ''; }, 1500);
                } else {
                    this.saveStatus = 'Save failed';
                }
            } catch (e) {
                this.saveStatus = 'Save failed';
                console.error('Save error:', e);
            }
        },

        // ===== Floating format toolbar =====

        _toolbarEl: null,
        _toolbarVisible: false,

        _initFormatToolbar() {
            this._toolbarEl = document.getElementById('formatToolbar');
            const docPane = document.getElementById('doc-pane');

            // Show toolbar on mouseup when text is selected inside docContainer
            document.addEventListener('mouseup', (e) => {
                // Small delay to let selection finalize
                setTimeout(() => this._checkShowToolbar(e), 10);
            });

            // Hide on keydown Escape
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this._hideToolbar();
            });

            // Hide on scroll of doc pane
            if (docPane) {
                docPane.addEventListener('scroll', () => this._hideToolbar());
            }

            // Toolbar button clicks
            this._toolbarEl.querySelectorAll('button[data-cmd]').forEach(btn => {
                btn.addEventListener('mousedown', (e) => {
                    e.preventDefault(); // Prevent losing selection
                    document.execCommand(btn.dataset.cmd, false, null);
                    this._updateToolbarState();
                });
            });

            // Heading select
            const headingSel = document.getElementById('tbHeadingSelect');
            headingSel.addEventListener('mousedown', (e) => e.stopPropagation());
            headingSel.addEventListener('change', (e) => {
                const val = e.target.value;
                const sel = window.getSelection();
                if (!sel || sel.rangeCount === 0 || sel.toString().length === 0) return;

                if (val === 'heading') {
                    document.execCommand('bold', false, null);
                    this._wrapSelectionWithStyle('font-size: 14pt; font-weight: bold;');
                } else if (val === 'subheading') {
                    document.execCommand('bold', false, null);
                    this._wrapSelectionWithStyle('font-size: 12pt; font-weight: bold;');
                } else {
                    // Normal — remove bold and reset size
                    document.execCommand('removeFormat', false, null);
                }
                e.target.value = '';
                this._hideToolbar();
            });

            // Font size select
            const sizeSel = document.getElementById('tbFontSize');
            sizeSel.addEventListener('mousedown', (e) => e.stopPropagation());
            sizeSel.addEventListener('change', (e) => {
                const val = e.target.value;
                if (val) {
                    this._wrapSelectionWithStyle('font-size: ' + val + 'pt');
                }
                e.target.value = '';
                this._hideToolbar();
            });
        },

        _checkShowToolbar(e) {
            const sel = window.getSelection();
            if (!sel || sel.toString().trim().length === 0) {
                this._hideToolbar();
                return;
            }

            const container = document.getElementById('docContainer');
            if (!container) { this._hideToolbar(); return; }

            // Check selection is inside docContainer
            const range = sel.getRangeAt(0);
            if (!container.contains(range.startContainer) || !container.contains(range.endContainer)) {
                this._hideToolbar();
                return;
            }

            // Don't show if selection is inside a doc-tag
            const tagEl = range.startContainer.nodeType === 1
                ? range.startContainer.closest('.doc-tag')
                : (range.startContainer.parentElement ? range.startContainer.parentElement.closest('.doc-tag') : null);
            if (tagEl) {
                this._hideToolbar();
                return;
            }

            // Position toolbar above selection
            const rect = range.getBoundingClientRect();
            const docPane = document.getElementById('doc-pane');
            const paneRect = docPane.getBoundingClientRect();

            const toolbarWidth = 380;
            let left = rect.left + (rect.width / 2) - (toolbarWidth / 2) - paneRect.left + docPane.scrollLeft;
            left = Math.max(8, Math.min(left, paneRect.width - toolbarWidth - 8));
            const top = rect.top - paneRect.top + docPane.scrollTop - 44;

            this._toolbarEl.style.left = left + 'px';
            this._toolbarEl.style.top = top + 'px';
            this._toolbarEl.classList.add('visible');
            this._toolbarVisible = true;
            this._updateToolbarState();
        },

        _hideToolbar() {
            if (this._toolbarEl && this._toolbarVisible) {
                this._toolbarEl.classList.remove('visible');
                this._toolbarVisible = false;
            }
        },

        _updateToolbarState() {
            if (!this._toolbarEl) return;
            this._toolbarEl.querySelectorAll('button[data-cmd]').forEach(btn => {
                const active = document.queryCommandState(btn.dataset.cmd);
                btn.classList.toggle('active', active);
            });
        },

        _wrapSelectionWithStyle(style) {
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.toString().length === 0) return;
            const range = sel.getRangeAt(0);
            const span = document.createElement('span');
            span.setAttribute('style', style);
            range.surroundContents(span);
            sel.removeAllRanges();
        },

        // ===== Signature placeholder click handler =====

        _initSigPlaceholderHandler() {
            const container = document.getElementById('docContainer');
            if (!container) return;

            container.addEventListener('click', (e) => {
                const placeholder = e.target.closest('.sig-strip-placeholder');
                if (!placeholder) return;

                e.preventDefault();
                e.stopPropagation();

                // Push undo state before adding tag
                this._pushUndoState();

                // Create a SIG tag at this position
                const tag = this._createTagData('signature');
                const span = this._createTagElement(tag);

                // Replace the placeholder with the SIG tag
                placeholder.parentNode.insertBefore(span, placeholder);
                placeholder.remove();

                // Register the tag
                this.tags.push(tag);
                this.mappings[tag.id] = this._emptySigIniMapping('signature');
                this._updateCounts();
                this._syncTagColor(tag.id);
                this._persistMappings();

                // Select the new tag so user can configure it
                this.selectTag(tag.id);

                // Activate signature tool briefly so user sees the context
                this.activeTool = null;
            });
        },

        // ===== Undo stack =====

        _pushUndoState() {
            const snapshot = {
                tags: JSON.parse(JSON.stringify(this.tags)),
                mappings: JSON.parse(JSON.stringify(this.mappings)),
                html: document.getElementById('docContainer').innerHTML,
            };
            this.undoStack.push(snapshot);
            if (this.undoStack.length > 20) {
                this.undoStack.shift();
            }
        },

        performUndo() {
            if (this.undoStack.length === 0) {
                this._showUndoToast('Nothing to undo', '#9ca3af');
                return;
            }

            const snapshot = this.undoStack.pop();

            // Restore HTML first
            const container = document.getElementById('docContainer');
            container.innerHTML = snapshot.html;

            // Restore state
            this.tags = snapshot.tags;
            this.mappings = snapshot.mappings;

            // Re-attach click handlers to all restored tag elements
            this.tags.forEach(tag => {
                const el = container.querySelector(`[data-tag-id="${tag.id}"]`);
                if (el) {
                    this._attachTagClickHandler(el, tag.id);
                }
            });

            this._updateCounts();
            this._syncAllTagColors();
            this._syncAllTagLabels();
            this._persistMappings();
            this._showUndoToast('Undone', '#10b981');
        },

        _showUndoToast(msg, color) {
            this.undoToast = msg;
            clearTimeout(this._undoToastTimer);
            this._undoToastTimer = setTimeout(() => { this.undoToast = ''; }, 1500);
        },

        _initUndoKeyHandler() {
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                    // Only intercept if NOT inside docContainer (let browser handle text undo)
                    const container = document.getElementById('docContainer');
                    if (container && container.contains(e.target)) {
                        return; // Let browser handle native text undo
                    }
                    e.preventDefault();
                    this.performUndo();
                }
            });
        },

        _uuid() {
            return 'tag-' + Date.now().toString(36) + '-' + Math.random().toString(36).substr(2, 6);
        },
    };
}
</script>
@endsection
