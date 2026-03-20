@extends('layouts.corex')

@section('corex-content')
<div class="flex flex-col h-full overflow-hidden"
     x-data="cdsEditor()"
     x-init="init()">

    {{-- STICKY HEADER --}}
    <x-page-header
        title="{{ ($sourceTemplateId ?? null) ? 'Edit CDS Template' : 'CDS Template Builder' }} — {{ $title }}"
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

                {{-- Validate button --}}
                <button type="button" @click="runValidation()"
                        class="text-sm px-3 py-2 rounded-lg border border-blue-300 bg-blue-50 hover:bg-blue-100 text-blue-700 transition-colors flex items-center gap-1.5 whitespace-nowrap">
                    Validate
                </button>

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
                                x-text="'Fix next \u2192 (' + (fixNextPosition) + ' of ' + outstandingCount + ')'">
                        </button>
                    </div>
                </template>

                {{-- State 2: All linked --}}
                <template x-if="allMapped && totalTagCount > 0">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2 bg-emerald-100 border border-emerald-300 text-emerald-800 text-sm px-3 py-2 rounded-lg">
                            <svg class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            <span x-text="'All ' + totalTagCount + ' fields linked — ready to save'"></span>
                        </div>
                        <form x-ref="generateForm" method="POST" action="{{ route('docuperfect.cds.generate') }}" class="inline">
                            @csrf
                            <input type="hidden" name="draft_id" :value="draftId">
                            <input type="hidden" name="template_name" :value="templateName">
                            <input type="hidden" name="is_esign" :value="isEsign ? 1 : 0">
                            <input type="hidden" name="party_mode" :value="partyMode">
                            <input type="hidden" name="allowed_delivery_modes" :value="deliveryModes.join(',')">
                            <input type="hidden" name="security_tier" :value="securityTier">
                            <button type="submit"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-4 py-2 rounded-lg transition-colors font-semibold">
                                Save Template &rarr;
                            </button>
                        </form>
                    </div>
                </template>

                {{-- State 3: No tags yet — still allow save --}}
                <template x-if="totalTagCount === 0">
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-400">No fields tagged yet</span>
                        <form x-ref="generateForm" method="POST" action="{{ route('docuperfect.cds.generate') }}" class="inline">
                            @csrf
                            <input type="hidden" name="draft_id" :value="draftId">
                            <input type="hidden" name="template_name" :value="templateName">
                            <input type="hidden" name="is_esign" :value="isEsign ? 1 : 0">
                            <input type="hidden" name="party_mode" :value="partyMode">
                            <input type="hidden" name="allowed_delivery_modes" :value="deliveryModes.join(',')">
                            <input type="hidden" name="security_tier" :value="securityTier">
                            <button type="submit"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-4 py-2 rounded-lg transition-colors font-semibold">
                                Save Template &rarr;
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
        <span><strong>Editing existing template #{{ $sourceTemplateId }}.</strong> Saving will update the existing template instead of creating a new one.</span>
    </div>
    @endif

    {{-- Template Name Bar --}}
    <div class="bg-white border-b border-gray-200 px-6 py-2 flex items-center gap-4 flex-shrink-0">
        <label class="text-xs font-semibold text-gray-500 whitespace-nowrap">Template Name</label>
        <input type="text" x-model="templateName"
               class="flex-1 text-sm border border-gray-300 rounded-lg bg-white text-gray-900 px-3 py-1.5 focus:ring-teal-400 focus:border-teal-400"
               placeholder="Enter template name...">
    </div>

    {{-- TWO-PANE AREA --}}
    <div class="flex flex-1 min-h-0">

        {{-- LEFT PANE: Document (70%) --}}
        <div class="w-[70%] h-full overflow-y-auto bg-gray-100" id="doc-pane">
            <div class="py-6 px-4 flex justify-center">
                <div class="doc-tagging-page" id="docContainer" contenteditable="true" spellcheck="false" @click="handleDocClick($event)">
                    {!! $html !!}
                </div>
            </div>

            {{-- Floating tagging + formatting toolbar --}}
            <div class="doc-format-toolbar" id="formatToolbar">
                <button type="button" title="Tag as Input Field" @mousedown.prevent="tagSelection('input')" class="tag-btn tag-btn-input">
                    <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#dc2626;margin-right:4px;"></span>
                    Input
                </button>
                <button type="button" title="Tag as Signature" @mousedown.prevent="tagSelection('signature')" class="tag-btn tag-btn-sig">
                    <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#f59e0b;margin-right:4px;"></span>
                    Signature
                </button>
                <button type="button" title="Tag as Initial" @mousedown.prevent="tagSelection('initial')" class="tag-btn tag-btn-ini">
                    <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#16a34a;margin-right:4px;"></span>
                    Initial
                </button>
                <span class="tb-sep"></span>
                <button type="button" title="Bold" data-cmd="bold"><b>B</b></button>
                <button type="button" title="Italic" data-cmd="italic"><i>I</i></button>
                <button type="button" title="Underline" data-cmd="underline"><u>U</u></button>
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
                        <span class="text-xs text-gray-500" x-text="partiesExpanded ? 'Done &#9650;' : 'Manage &#9660;'"></span>
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

                {{-- ===== TEMPLATE SETTINGS (collapsible) ===== --}}
                <div class="mb-4 border border-gray-200 rounded-lg overflow-hidden">
                    <button type="button" @click="settingsExpanded = !settingsExpanded"
                            class="w-full flex items-center justify-between px-3 py-2.5 bg-gray-50 hover:bg-gray-100 transition-colors text-left">
                        <span class="text-xs font-semibold text-gray-700 flex items-center gap-1.5">
                            &#9881; Template Settings
                        </span>
                        <span class="text-xs text-gray-500" x-text="settingsExpanded ? '&#9650;' : '&#9660;'"></span>
                    </button>

                    <div x-show="settingsExpanded" x-transition class="border-t border-gray-200 p-3 space-y-3">
                        {{-- Delivery Modes --}}
                        <div>
                            <label class="text-[10px] font-semibold text-gray-500 uppercase block mb-1">Delivery Modes</label>
                            <div class="flex flex-wrap gap-3">
                                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                    <input type="checkbox" value="esign" x-model="deliveryModes"
                                           class="w-3 h-3 rounded border-gray-300 text-teal-600 focus:ring-teal-400"> E-Sign
                                </label>
                                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                    <input type="checkbox" value="wet_ink" x-model="deliveryModes"
                                           class="w-3 h-3 rounded border-gray-300 text-teal-600 focus:ring-teal-400"> Wet Ink
                                </label>
                                <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                    <input type="checkbox" value="download" x-model="deliveryModes"
                                           class="w-3 h-3 rounded border-gray-300 text-teal-600 focus:ring-teal-400"> Download
                                </label>
                            </div>
                        </div>

                        {{-- Security Level --}}
                        <div>
                            <label class="text-[10px] font-semibold text-gray-500 uppercase block mb-1">Security Level</label>
                            <select x-model="securityTier"
                                    class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white text-gray-700 focus:ring-teal-400 focus:border-teal-400">
                                <option value="standard">Standard (ID + DOB)</option>
                                <option value="enhanced">Enhanced (+ Email OTP)</option>
                                <option value="high">High (+ SMS OTP)</option>
                            </select>
                        </div>

                        {{-- Signing Mode --}}
                        <div>
                            <label class="text-[10px] font-semibold text-gray-500 uppercase block mb-1">Signing Mode</label>
                            <select x-model="partyMode"
                                    class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white text-gray-700 focus:ring-teal-400 focus:border-teal-400">
                                <option value="shared">Shared &mdash; all parties sign same document</option>
                                <option value="per_party">Per Party &mdash; one copy per signer (e.g. FICA)</option>
                            </select>
                        </div>

                        {{-- E-Sign Eligible --}}
                        <div class="flex items-center gap-2">
                            <input type="checkbox" x-model="isEsign"
                                   class="w-3 h-3 rounded border-gray-300 text-teal-600 focus:ring-teal-400">
                            <span class="text-xs text-gray-700">Eligible for E-Signature</span>
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
                                              x-text="isTagComplete(tag) ? '\u2713' : '\u2717'"></span>
                                        <span :class="getRowLabelClass(tag)" x-text="getDisplayLabel(tag)"></span>
                                        <template x-if="getMapping(tag.id).confidence">
                                            <span class="w-2 h-2 rounded-full flex-shrink-0"
                                                  :class="{
                                                      'bg-green-500': getMapping(tag.id).confidence === 'high',
                                                      'bg-orange-400': getMapping(tag.id).confidence === 'medium',
                                                      'bg-gray-300': getMapping(tag.id).confidence === 'low',
                                                  }"
                                                  :title="'Confidence: ' + getMapping(tag.id).confidence"></span>
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

                                    {{-- Editable at signing by --}}
                                    <div class="mb-1.5">
                                        <label class="text-[10px] font-semibold text-gray-500 uppercase">Editable at signing by</label>
                                        <p class="text-[10px] text-gray-400 mb-1">If none selected, field is locked after agent fills it</p>
                                        <div class="mt-1 space-y-1">
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox"
                                                       :checked="(getMapping(tag.id).editable_by || []).includes('owner_party')"
                                                       @change="toggleEditableBy(tag.id, 'owner_party', $event.target.checked)"
                                                       class="w-3 h-3 text-teal-600 rounded">
                                                Lessor / Seller
                                            </label>
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox"
                                                       :checked="(getMapping(tag.id).editable_by || []).includes('acquiring_party')"
                                                       @change="toggleEditableBy(tag.id, 'acquiring_party', $event.target.checked)"
                                                       class="w-3 h-3 text-teal-600 rounded">
                                                Lessee / Buyer
                                            </label>
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox"
                                                       :checked="(getMapping(tag.id).editable_by || []).includes('agent')"
                                                       @change="toggleEditableBy(tag.id, 'agent', $event.target.checked)"
                                                       class="w-3 h-3 text-teal-600 rounded">
                                                Agent
                                            </label>
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox"
                                                       :checked="(getMapping(tag.id).editable_by || []).includes('witness')"
                                                       @change="toggleEditableBy(tag.id, 'witness', $event.target.checked)"
                                                       class="w-3 h-3 text-teal-600 rounded">
                                                Witness
                                            </label>
                                            <label class="flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer border-t border-gray-100 pt-1 mt-1">
                                                <input type="checkbox"
                                                       :checked="(getMapping(tag.id).editable_by || []).includes('all')"
                                                       @change="toggleEditableByAll(tag.id, $event.target.checked)"
                                                       class="w-3 h-3 text-teal-600 rounded">
                                                <span class="font-semibold">All parties</span>
                                            </label>
                                        </div>
                                    </div>

                                    {{-- Party dropdown --}}
                                    <select class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 mb-1.5 bg-white"
                                            :value="getMapping(tag.id).party"
                                            :disabled="getMapping(tag.id).partyLocked"
                                            @change="setParty(tag.id, $event.target.value)">
                                        <option value="auto">Auto-detect</option>
                                        <option value="owner_party">Lessor / Seller</option>
                                        <option value="acquiring_party">Lessee / Buyer</option>
                                        <option value="agent">Agent</option>
                                        <option value="witness">Witness</option>
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
                                              x-text="isTagComplete(tag) ? '\u2713' : '\u2717'"></span>
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
                                              x-text="isTagComplete(tag) ? '\u2713' : '\u2717'"></span>
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

    {{-- Validation results modal --}}
    <template x-if="showValidationModal">
        <div class="fixed inset-0 z-50 flex items-center justify-center"
             style="background: rgba(0,0,0,0.4);">
            <div class="bg-white rounded-lg shadow-xl p-6 max-w-lg w-full mx-4">
                <h3 class="text-sm font-semibold mb-3"
                    :class="validationResult.status === 'pass'
                        ? 'text-emerald-700' : 'text-amber-700'"
                    x-text="validationResult.status === 'pass'
                        ? '\u2713 Validation Passed' : '\u26A0 Differences Found'">
                </h3>

                <p class="text-sm text-gray-600 mb-4"
                   x-text="validationResult.message"></p>

                <template x-if="validationResult.details && validationResult.details.length > 0">
                    <div class="max-h-60 overflow-y-auto border border-gray-200 rounded p-3 mb-4">
                        <template x-for="(diff, idx) in validationResult.details" :key="idx">
                            <div class="mb-3 text-xs">
                                <div class="text-red-600">
                                    <span class="font-semibold">Original:</span>
                                    <span x-text="'...' + diff.original + '...'"></span>
                                </div>
                                <div class="text-blue-600">
                                    <span class="font-semibold">Current:</span>
                                    <span x-text="'...' + diff.current + '...'"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="flex justify-end gap-2">
                    <button @click="showValidationModal = false"
                            class="text-sm px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

{{-- CoreX document CSS --}}
<link rel="stylesheet" href="/css/corex-document.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

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
        font-family: 'Plus Jakarta Sans', Arial, Helvetica, sans-serif;
        font-size: 10.5pt; line-height: 1.55; color: #1e293b;
        text-align: justify; cursor: default;
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

    /* CoreX signature section styling in context */
    .doc-tagging-page .corex-signature-grid { pointer-events: none; }
</style>

<script>
function cdsEditor() {
    return {
        activeTool: null,
        selectedTagId: null,
        tags: [],
        mappings: {},
        counts: { input: 0, signature: 0, initial: 0 },
        saveStatus: '',
        draftId: @json($draftId),
        csrfToken: '{{ csrf_token() }}',

        // CDS-specific data
        cdsJson: @json($cds),
        cdsFields: @json($fields),
        templateName: @json($templateName ?? $title),

        // DB-backed draft state
        sourceTemplateId: @json($sourceTemplateId ?? null),
        hasSavedState: @json($hasSavedState ?? false),
        savedTags: @json($savedTags ?? []),
        savedMappings: @json($savedMappings ?? (object)[]),
        savedTaggedHtml: @json($savedTaggedHtml ?? ''),
        savedSettings: @json($savedSettings ?? []),

        // Template settings
        isEsign: true,
        partyMode: 'shared',
        deliveryModes: ['esign', 'wet_ink', 'download'],
        securityTier: 'enhanced',
        settingsExpanded: false,

        // Draft save state
        draftSaving: false,
        lastSavedAt: null,
        lastSavedLabel: '',
        _autoSaveInterval: null,
        _lastSavedTimer: null,
        mappingsSaveUrl: @json(route('docuperfect.cds.mappings')),
        draftSaveUrl: @json(route('docuperfect.cds.draft.save')),

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
            return [
                ...this.inputTags.filter(t => !this.isTagComplete(t)),
                ...this.sigTags.filter(t => !this.isTagComplete(t)),
                ...this.iniTags.filter(t => !this.isTagComplete(t)),
            ];
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
                        template_name: this.templateName,
                        tags: this.tags,
                        mappings: this.mappings,
                        tagged_html: document.getElementById('docContainer').innerHTML,
                        settings: {
                            is_esign: this.isEsign,
                            party_mode: this.partyMode,
                            allowed_delivery_modes: this.deliveryModes.join(','),
                            security_tier: this.securityTier,
                        },
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

        // ===== Init — convert CDS field spans to tags + auto-suggest =====

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

                    // Restore settings
                    if (this.savedSettings && Object.keys(this.savedSettings).length > 0) {
                        if (this.savedSettings.is_esign !== undefined) this.isEsign = !!this.savedSettings.is_esign;
                        if (this.savedSettings.party_mode) this.partyMode = this.savedSettings.party_mode;
                        if (this.savedSettings.allowed_delivery_modes) {
                            this.deliveryModes = this.savedSettings.allowed_delivery_modes.split(',');
                        }
                        if (this.savedSettings.security_tier) this.securityTier = this.savedSettings.security_tier;
                    }

                    this._startAutoSave();
                    this._initFormatToolbar();
                    this._initSigPlaceholderHandler();
                    this._initUndoKeyHandler();
                    return; // Skip fresh parse path
                }

                // ── FRESH PATH: convert CDS field spans to tags + auto-suggest ──
                // Convert CDS .corex-field spans to doc-tag system
                // Handles: input fields, marker-based SIG (%%%%), marker-based INI (####)
                const cdsFieldSpans = document.querySelectorAll('#docContainer .corex-field');
                let inputIdx = 0;
                cdsFieldSpans.forEach((el) => {
                    const markerType = el.dataset.markerType;

                    // %%%% marker → SIG tag
                    if (markerType === 'signature') {
                        const tag = this._createTagData('signature');
                        const span = this._createTagElement(tag);
                        el.replaceWith(span);
                        this.tags.push(tag);
                        this.mappings[tag.id] = {
                            parties: [],
                            variant: 'sig_full',
                        };
                        return;
                    }

                    // #### marker → INI tag
                    if (markerType === 'initial') {
                        const tag = this._createTagData('initial');
                        const span = this._createTagElement(tag);
                        el.replaceWith(span);
                        this.tags.push(tag);
                        this.mappings[tag.id] = {
                            parties: this.signingParties.map(sp => sp.name),
                        };
                        return;
                    }

                    // Input field (default — @@@@ markers or legacy field_placeholder)
                    const tag = this._createTagData('input');
                    tag.parserIndex = inputIdx++;

                    const confidence = el.dataset.confidence || 'low';
                    const fieldName = el.dataset.fieldName || '';
                    const fieldLabel = el.dataset.fieldLabel || '';

                    el.classList.remove('corex-field');
                    el.classList.add('doc-tag', 'doc-tag-input');
                    el.setAttribute('data-tag-id', tag.id);
                    el.setAttribute('contenteditable', 'false');
                    el.textContent = tag.label;
                    this._attachTagClickHandler(el, tag.id);
                    this.tags.push(tag);

                    // Auto-suggest from context identification data attributes
                    if (fieldName) {
                        const match = this._findBestNamedFieldMatch(
                            fieldLabel || fieldName,
                            fieldName
                        );
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
                                confidence: confidence,
                                editable_by: this._autoEditableBy(match.source_type, match.source_contact_type, typeKey),
                            });
                            return;
                        }
                    }

                    // Fallback: auto-suggest from CDS JSON fields array (legacy path)
                    const cdsField = this.cdsFields[tag.parserIndex];
                    if (!fieldName && cdsField && (cdsField.label || cdsField.field_name)) {
                        const match = this._findBestNamedFieldMatch(
                            cdsField.label || cdsField.field_name,
                            cdsField.field_name
                        );
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
                                confidence: 'medium',
                                editable_by: this._autoEditableBy(match.source_type, match.source_contact_type, typeKey),
                            });
                            return;
                        }
                    }

                    // No DB match — use parser suggestion if available
                    this.mappings[tag.id] = this._emptyInputMapping(confidence);
                    this.mappings[tag.id].editable_by = ['agent'];
                    if (fieldLabel && fieldLabel !== 'Input' && fieldLabel !== 'FIELD') {
                        this.mappings[tag.id].manualLabel = fieldLabel;
                        this.mappings[tag.id].mappingType = 'manual';
                        this.mappings[tag.id].label = fieldLabel;
                    }
                });

                // Convert inline signatures to SIG tags
                const inlineSigs = document.querySelectorAll(
                    '#docContainer .corex-signature-section'
                );
                inlineSigs.forEach((el) => {
                    const blocks = el.querySelectorAll('.corex-signature-block');
                    blocks.forEach((block) => {
                        const roleEl = block.querySelector('.corex-signature-role');
                        const roleName = roleEl ? roleEl.textContent.trim() : 'PARTY';

                        const tag = this._createTagData('signature');
                        const span = this._createTagElement(tag);

                        block.replaceWith(span);

                        this.tags.push(tag);
                        const partyMatch = this.signingParties.find(
                            sp => sp.name.toLowerCase() === roleName.toLowerCase()
                        );
                        this.mappings[tag.id] = {
                            parties: partyMatch ? [partyMatch.name] : [],
                            variant: 'sig_full',
                        };
                    });

                    // Remove the now-empty signature section wrapper grid
                    if (el.querySelectorAll('.corex-signature-block').length === 0) {
                        const grid = el.querySelector('.corex-signature-grid');
                        if (grid) grid.remove();
                    }
                });

                // Convert page initials to INI tags
                const pageInitials = document.querySelectorAll(
                    '#docContainer .corex-page-initials'
                );
                pageInitials.forEach((el) => {
                    const tag = this._createTagData('initial');
                    const span = this._createTagElement(tag);

                    const placeholder = el.querySelector('.corex-page-initials-placeholder');
                    if (placeholder) {
                        placeholder.replaceWith(span);
                    } else {
                        el.appendChild(span);
                    }

                    this.tags.push(tag);
                    this.mappings[tag.id] = {
                        parties: this.signingParties.map(sp => sp.name),
                    };
                });

                // Convert disclosure checklists to non-editable blocks
                const disclosures = document.querySelectorAll(
                    '#docContainer .corex-disclosure-checklist'
                );
                disclosures.forEach((el, idx) => {
                    el.setAttribute('data-disclosure-index', idx);
                    el.setAttribute('contenteditable', 'false');

                    const label = document.createElement('div');
                    label.className = 'text-[10px] font-semibold text-purple-600 uppercase tracking-wider mb-1 mt-3';
                    label.textContent = 'Disclosure Checklist \u2014 completed by recipient at signing';
                    label.setAttribute('contenteditable', 'false');
                    el.parentNode.insertBefore(label, el);
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

        // ===== Selection — doc <-> panel sync =====

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
                const row = document.getElementById('tag-row-' + tagId);
                const linkPane = document.getElementById('link-pane');
                if (row && linkPane) {
                    const paneRect = linkPane.getBoundingClientRect();
                    const rowRect = row.getBoundingClientRect();
                    const scrollTop = linkPane.scrollTop + (rowRect.top - paneRect.top) - (paneRect.height / 2) + (rowRect.height / 2);
                    linkPane.scrollTo({ top: scrollTop, behavior: 'smooth' });
                }

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

                // Purple bottom border for editable fields
                const eb = (mapping.editable_by || []);
                if (eb.length > 0) {
                    el.style.borderBottom = '2px solid #a855f7';
                    el.title = 'Editable by: ' + eb.join(', ');
                } else {
                    el.style.borderBottom = '';
                    el.title = 'Locked after agent fills';
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

        // ===== Display labels =====

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
                editable_by: [],
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

        toggleEditableBy(tagId, role, checked) {
            const m = this.getMapping(tagId);
            if (!m.editable_by) m.editable_by = [];

            // Remove 'all' if individual is toggled
            m.editable_by = m.editable_by.filter(r => r !== 'all');

            if (checked && !m.editable_by.includes(role)) {
                m.editable_by.push(role);
            } else if (!checked) {
                m.editable_by = m.editable_by.filter(r => r !== role);
            }

            this.mappings[tagId] = m;
            this._syncTagColor(tagId);
            this._persistMappings();
        },

        toggleEditableByAll(tagId, checked) {
            const m = this.getMapping(tagId);
            if (checked) {
                m.editable_by = ['all'];
            } else {
                m.editable_by = [];
            }
            this.mappings[tagId] = m;
            this._syncTagColor(tagId);
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
            const group = typeKey.substring(3);
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
            if (typeKey === 'sf:contact_lessor' || typeKey === 'sf:contact_seller') return 'owner_party';
            if (typeKey === 'sf:contact_lessee' || typeKey === 'sf:contact_buyer') return 'acquiring_party';
            return 'auto';
        },

        _autoEditableBy(sourceType, sourceContactType, typeKey) {
            // Contact fields → editable by the contact's party
            if (sourceType === 'contact' && sourceContactType) {
                const ct = sourceContactType.toLowerCase();
                if (ct === 'lessor' || ct === 'seller') return ['owner_party'];
                if (ct === 'lessee' || ct === 'buyer') return ['acquiring_party'];
            }
            // Property and deal fields → locked (auto-filled from DB)
            if (sourceType === 'property' || sourceType === 'deal') return [];
            // Agent fields → locked
            if (typeKey === 'sf:agent') return [];
            // Manual/unknown → agent editable
            return ['agent'];
        },

        _autoPartyForFieldGroup(fg) {
            if (!fg || !fg.fields || fg.fields.length === 0) return 'auto';
            const contactTypes = fg.fields
                .filter(f => f.source_type === 'contact' && f.source_contact_type)
                .map(f => f.source_contact_type.toLowerCase());
            if (contactTypes.length > 0) {
                const counts = {};
                contactTypes.forEach(t => { counts[t] = (counts[t] || 0) + 1; });
                const dominant = Object.entries(counts).sort((a, b) => b[1] - a[1])[0][0];
                if (dominant === 'lessor' || dominant === 'seller') return 'owner_party';
                if (dominant === 'lessee' || dominant === 'buyer') return 'acquiring_party';
                return dominant;
            }
            const agentFields = fg.fields.filter(f => f.source_type === 'agent');
            if (agentFields.length > 0) return 'agent';
            return 'auto';
        },

        _findBestNamedFieldMatch(suggestedLabel, suggestedKey) {
            if (!suggestedLabel) return null;
            const needle = suggestedLabel.toLowerCase().trim();

            let match = this.namedFieldsAll.find(nf => nf.name.toLowerCase() === needle);
            if (match) return match;

            match = this.namedFieldsAll.find(nf => {
                const name = nf.name.toLowerCase();
                return needle.includes(name) || name.includes(needle);
            });
            if (match) return match;

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

            if (!this.activeTool) {
                if (clickedTag && clickedTag.dataset.tagId) {
                    event.stopPropagation();
                    this.selectTag(clickedTag.dataset.tagId);
                }
                return;
            }

            if (clickedTag) return;

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
                this._syncTagLabel(tag.id);
            });
        },

        _updateCounts() {
            this.counts.input = this.tags.filter(t => t.type === 'input').length;
            this.counts.signature = this.tags.filter(t => t.type === 'signature').length;
            this.counts.initial = this.tags.filter(t => t.type === 'initial').length;
        },

        // ===== Persistence (DB-backed, debounced) =====

        _saveTimer: null,

        async _persistMappings() {
            clearTimeout(this._saveTimer);
            this._saveTimer = setTimeout(() => this._doSaveMappings(), 400);
        },

        async _doSaveMappings() {
            this.saveStatus = 'Saving...';
            try {
                const response = await fetch(this.mappingsSaveUrl, {
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
                console.error('Mapping save error:', e);
            }
        },

        // ===== Floating format toolbar =====

        _toolbarEl: null,
        _toolbarVisible: false,

        _initFormatToolbar() {
            this._toolbarEl = document.getElementById('formatToolbar');
            const docPane = document.getElementById('doc-pane');

            document.addEventListener('mouseup', (e) => {
                setTimeout(() => this._checkShowToolbar(e), 10);
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this._hideToolbar();
            });

            if (docPane) {
                docPane.addEventListener('scroll', () => this._hideToolbar());
            }

            this._toolbarEl.querySelectorAll('button[data-cmd]').forEach(btn => {
                btn.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    document.execCommand(btn.dataset.cmd, false, null);
                    this._updateToolbarState();
                });
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

            const range = sel.getRangeAt(0);
            if (!container.contains(range.startContainer) || !container.contains(range.endContainer)) {
                this._hideToolbar();
                return;
            }

            // Don't show if selection is inside a tag
            const insideTag = (range.startContainer.parentElement ? range.startContainer.parentElement.closest('.doc-tag') : null) ||
                              (range.endContainer.parentElement ? range.endContainer.parentElement.closest('.doc-tag') : null);
            if (insideTag) {
                this._hideToolbar();
                return;
            }

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
                const placeholder = e.target.closest('.sig-strip-placeholder, .corex-signature-grid, .corex-sig-block');
                if (!placeholder) return;

                e.preventDefault();
                e.stopPropagation();

                this._pushUndoState();

                const tag = this._createTagData('signature');
                const span = this._createTagElement(tag);

                placeholder.parentNode.insertBefore(span, placeholder);
                placeholder.remove();

                this.tags.push(tag);
                this.mappings[tag.id] = this._emptySigIniMapping('signature');
                this._updateCounts();
                this._syncTagColor(tag.id);
                this._persistMappings();

                this.selectTag(tag.id);
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

            const container = document.getElementById('docContainer');
            container.innerHTML = snapshot.html;

            this.tags = snapshot.tags;
            this.mappings = snapshot.mappings;

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
                    const container = document.getElementById('docContainer');
                    if (container && container.contains(e.target)) {
                        return;
                    }
                    e.preventDefault();
                    this.performUndo();
                }
            });
        },

        // ===== Highlight-to-tag: select text → replace with tag =====

        tagSelection(type) {
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.toString().trim().length === 0) {
                return;
            }

            const range = sel.getRangeAt(0);
            const container = document.getElementById('docContainer');

            // Verify selection is inside the document
            if (!container.contains(range.startContainer) ||
                !container.contains(range.endContainer)) {
                return;
            }

            // Don't allow tagging inside an existing tag
            const insideTag = (range.startContainer.parentElement ? range.startContainer.parentElement.closest('.doc-tag') : null) ||
                              (range.endContainer.parentElement ? range.endContainer.parentElement.closest('.doc-tag') : null);
            if (insideTag) return;

            // Push undo state BEFORE making changes
            this._pushUndoState();

            // Store the original selected text
            const originalText = sel.toString();

            // Create the tag
            const tag = this._createTagData(type);
            tag.originalText = originalText;

            // Delete the selected content and insert the tag span
            range.deleteContents();
            const span = this._createTagElement(tag);
            range.insertNode(span);

            // Clean up selection
            sel.removeAllRanges();

            // Register the tag
            this.tags.push(tag);
            if (type === 'input') {
                this.mappings[tag.id] = this._emptyInputMapping(null);
            } else {
                this.mappings[tag.id] = this._emptySigIniMapping(type);
            }

            this._updateCounts();
            this._syncTagColor(tag.id);
            this._hideToolbar();

            // Select the new tag to open config
            this.selectTag(tag.id);

            // Persist
            this._persistMappings();
        },

        // ===== Validation: compare original text vs current =====

        validationResult: null,
        showValidationModal: false,

        runValidation() {
            const originalText = (this.cdsJson || {}).original_text || '';
            const currentContainer = document.getElementById('docContainer');

            // Get current text, skipping tag content
            let currentText = '';
            const walker = document.createTreeWalker(
                currentContainer,
                NodeFilter.SHOW_TEXT,
                {
                    acceptNode: function(node) {
                        if (node.parentElement && node.parentElement.closest('.doc-tag')) {
                            return NodeFilter.FILTER_REJECT;
                        }
                        return NodeFilter.FILTER_ACCEPT;
                    }
                }
            );

            while (walker.nextNode()) {
                currentText += walker.currentNode.textContent;
            }

            // Normalize both texts: collapse whitespace, trim
            const normalize = (t) => t.replace(/\s+/g, ' ').trim();
            const origNorm = normalize(originalText);
            const currNorm = normalize(currentText);

            if (!origNorm) {
                this.validationResult = {
                    status: 'warning',
                    message: 'No original text available for comparison (document was parsed before this feature was added).',
                    details: [],
                };
                this.showValidationModal = true;
                return;
            }

            // Find differences
            const origWords = origNorm.split(' ');
            const currWords = currNorm.split(' ');

            let differences = [];
            let oi = 0, ci = 0;

            while (oi < origWords.length && ci < currWords.length) {
                if (origWords[oi] === currWords[ci]) {
                    oi++; ci++;
                } else {
                    // Found a difference — is it a field replacement?
                    let lookAhead = oi;

                    while (lookAhead < origWords.length && lookAhead < oi + 20) {
                        if (origWords[lookAhead] === currWords[ci]) break;
                        lookAhead++;
                    }

                    if (lookAhead < origWords.length && origWords[lookAhead] === currWords[ci]) {
                        // Gap in original = likely a field replacement — OK
                        oi = lookAhead;
                    } else {
                        // Unexpected difference
                        differences.push({
                            position: oi,
                            original: origWords.slice(oi, oi + 5).join(' '),
                            current: currWords.slice(ci, ci + 5).join(' '),
                        });
                        oi++; ci++;
                    }
                }
            }

            // Show results
            if (differences.length === 0) {
                this.validationResult = {
                    status: 'pass',
                    message: 'Document text matches original. ' +
                             this.tags.length + ' field tag(s) placed.',
                };
            } else {
                this.validationResult = {
                    status: 'warning',
                    message: differences.length + ' text difference(s) found.',
                    details: differences,
                };
            }

            this.showValidationModal = true;
        },

        _uuid() {
            return 'tag-' + Date.now().toString(36) + '-' + Math.random().toString(36).substr(2, 6);
        },
    };
}
</script>
@endsection
