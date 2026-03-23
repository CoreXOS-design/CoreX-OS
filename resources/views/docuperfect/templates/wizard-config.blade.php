@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="wizardConfigEditor()"
     x-init="init()">

    {{-- Page header --}}
    <x-page-header title="E-Sign Wizard Setup: {{ $template->name }}" :back-route="route('docuperfect.templates.index')">
        <x-slot:actions>
            <button type="button" @click="save()" :disabled="saving"
                    class="px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
                <span x-show="!saving">Save Configuration</span>
                <span x-show="saving">Saving...</span>
            </button>
        </x-slot:actions>
    </x-page-header>

    @if(session('success'))
    <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
        {{ session('success') }}
    </div>
    @endif

    {{-- Template info bar --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">{{ $template->name }}</h2>
        <div class="text-sm text-white/60">
            {{ $template->template_type }} &middot; {{ $template->render_type }}
            @if($template->is_esign) &middot; E-Sign Eligible @endif
        </div>
    </div>

    {{-- Tab bar --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="flex" style="border-bottom:1px solid var(--border);">
            @foreach([
                ['key'=>'steps','label'=>'Wizard Steps'],
                ['key'=>'parties','label'=>'Signing Parties'],
                ['key'=>'sections','label'=>'Document Sections'],
                ['key'=>'delivery','label'=>'Delivery Modes'],
            ] as $t)
            <button type="button"
                    @click="activeTab = '{{ $t['key'] }}'"
                    :class="activeTab === '{{ $t['key'] }}' ? 'border-b-2' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $t['key'] }}' ? 'color:#0ea5e9; border-color:#0ea5e9; background:rgba(14,165,233,0.05);' : 'color:var(--text-secondary);'"
                    class="px-6 py-4 text-sm font-semibold whitespace-nowrap transition-all duration-300 outline-none hover:opacity-80">
                {{ $t['label'] }}
            </button>
            @endforeach
        </div>

        {{-- ═══ WIZARD STEPS TAB ═══ --}}
        <div x-show="activeTab === 'steps'" class="p-6 space-y-4">
            <p class="text-sm text-gray-500 mb-4">Configure which steps appear in the e-sign wizard and in what order. Drag to reorder.</p>

            <div class="space-y-2">
                <template x-for="(step, idx) in wizardSteps" :key="step.key + '_' + idx">
                    <div class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 bg-white">
                        <div class="cursor-grab text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16" /></svg>
                        </div>
                        <span class="text-xs font-bold text-gray-400 w-6 text-center" x-text="idx + 1"></span>
                        <div class="flex-1 min-w-0">
                            <input type="text" x-model="step.label"
                                   class="w-full text-sm font-semibold text-gray-800 border-0 p-0 focus:ring-0 bg-transparent">
                        </div>
                        <select x-model="step.type" class="text-xs rounded border border-gray-300 bg-white text-gray-600 px-2 py-1">
                            <option value="property_selector">Property Selector</option>
                            <option value="contact_selector">Contact Selector</option>
                            <option value="field_group">Field Group</option>
                            <option value="field_entry">Fill & Review</option>
                            <option value="signing">Sign & Send</option>
                        </select>
                        <template x-if="step.type === 'contact_selector'">
                            <select x-model="step.party" class="text-xs rounded border border-gray-300 bg-white text-gray-600 px-2 py-1">
                                <option value="landlord">Landlord</option>
                                <option value="tenant">Tenant</option>
                                <option value="seller">Seller</option>
                                <option value="buyer">Buyer</option>
                            </select>
                        </template>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="moveStep(idx, -1)" :disabled="idx === 0"
                                    class="text-xs px-1.5 py-1 rounded border border-gray-300 hover:bg-gray-100 disabled:opacity-30">
                                &uarr;
                            </button>
                            <button type="button" @click="moveStep(idx, 1)" :disabled="idx === wizardSteps.length - 1"
                                    class="text-xs px-1.5 py-1 rounded border border-gray-300 hover:bg-gray-100 disabled:opacity-30">
                                &darr;
                            </button>
                            <button type="button" @click="removeStep(idx)"
                                    class="text-xs px-1.5 py-1 rounded border border-red-300 text-red-500 hover:bg-red-50">
                                &times;
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" @click="addStep()"
                    class="text-sm px-4 py-2 rounded-lg border border-dashed border-gray-300 text-gray-500 hover:bg-gray-50 hover:border-gray-400 w-full">
                + Add Step
            </button>
        </div>

        {{-- ═══ SIGNING PARTIES TAB ═══ --}}
        <div x-show="activeTab === 'parties'" class="p-6 space-y-4">
            <p class="text-sm text-gray-500 mb-4">Select which roles sign this template. This drives signature block rendering, zone options, and signing chain defaults.</p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach(['agent', 'seller', 'buyer', 'landlord', 'tenant', 'witness', 'supervisor'] as $role)
                <label class="flex items-center gap-2 p-3 rounded-lg border cursor-pointer transition-all"
                       :class="signingParties.includes('{{ $role }}') ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'">
                    <input type="checkbox" value="{{ $role }}"
                           :checked="signingParties.includes('{{ $role }}')"
                           @change="toggleParty('{{ $role }}')"
                           class="rounded border-gray-300 text-blue-600">
                    <span class="text-sm font-medium text-gray-700">{{ ucfirst($role) }}</span>
                </label>
                @endforeach
            </div>

            {{-- Custom roles --}}
            <div class="mt-4">
                <h4 class="text-sm font-semibold text-gray-600 mb-2">Custom Roles</h4>
                <template x-for="(role, idx) in customRoles" :key="idx">
                    <div class="flex items-center gap-2 mb-2">
                        <input type="text" x-model="customRoles[idx]"
                               class="text-sm rounded-lg border border-gray-300 px-3 py-1.5 flex-1"
                               placeholder="Custom role name">
                        <button type="button" @click="removeCustomRole(idx)"
                                class="text-red-400 hover:text-red-600 text-xs px-2 py-1 rounded border border-red-300">
                            Remove
                        </button>
                    </div>
                </template>
                <button type="button" @click="addCustomRole()"
                        class="text-xs px-3 py-1.5 rounded border border-dashed border-gray-300 text-gray-500 hover:bg-gray-50">
                    + Add Custom Role
                </button>
            </div>

            {{-- Default signing order --}}
            <div class="mt-6">
                <h4 class="text-sm font-semibold text-gray-600 mb-2">Default Signing Order</h4>
                <p class="text-xs text-gray-400 mb-2">Drag to set the default order parties sign in.</p>
                <div class="space-y-1">
                    <template x-for="(party, idx) in allParties" :key="party">
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50 border border-gray-200">
                            <span class="text-xs font-bold text-gray-400 w-6 text-center" x-text="idx + 1"></span>
                            <span class="text-sm font-medium text-gray-700" x-text="party.charAt(0).toUpperCase() + party.slice(1)"></span>
                            <div class="ml-auto flex gap-1">
                                <button type="button" @click="movePartyOrder(idx, -1)" :disabled="idx === 0"
                                        class="text-xs px-1.5 py-0.5 rounded border border-gray-300 disabled:opacity-30">&uarr;</button>
                                <button type="button" @click="movePartyOrder(idx, 1)" :disabled="idx === allParties.length - 1"
                                        class="text-xs px-1.5 py-0.5 rounded border border-gray-300 disabled:opacity-30">&darr;</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- ═══ SECTIONS TAB ═══ --}}
        <div x-show="activeTab === 'sections'" class="p-6 space-y-4">
            <p class="text-sm text-gray-500 mb-4">Define document sections for section-by-section signing. If no sections are defined, signers use full-scroll mode.</p>

            <div class="space-y-2">
                <template x-for="(section, idx) in sections" :key="idx">
                    <div class="p-3 rounded-lg border border-gray-200 bg-white space-y-2">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-bold text-gray-400 w-6 text-center" x-text="idx + 1"></span>
                            <input type="text" x-model="section.label"
                                   class="flex-1 text-sm rounded-lg border border-gray-300 px-3 py-1.5"
                                   placeholder="Section label">
                            <button type="button" @click="removeSection(idx)"
                                    class="text-red-400 hover:text-red-600 text-xs px-2 py-1 rounded border border-red-300">
                                &times;
                            </button>
                        </div>
                        <div class="grid grid-cols-4 gap-2 ml-9">
                            <div>
                                <label class="text-xs text-gray-500">Start Page</label>
                                <input type="number" x-model.number="section.startPage" min="1"
                                       class="w-full text-xs rounded border border-gray-300 px-2 py-1">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500">Start Y (%)</label>
                                <input type="number" x-model.number="section.startY" min="0" max="100"
                                       class="w-full text-xs rounded border border-gray-300 px-2 py-1">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500">End Page</label>
                                <input type="number" x-model.number="section.endPage" min="1"
                                       class="w-full text-xs rounded border border-gray-300 px-2 py-1">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500">End Y (%)</label>
                                <input type="number" x-model.number="section.endY" min="0" max="100"
                                       class="w-full text-xs rounded border border-gray-300 px-2 py-1">
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" @click="addSection()"
                    class="text-sm px-4 py-2 rounded-lg border border-dashed border-gray-300 text-gray-500 hover:bg-gray-50 hover:border-gray-400 w-full">
                + Add Section
            </button>
        </div>

        {{-- ═══ DELIVERY MODES TAB ═══ --}}
        <div x-show="activeTab === 'delivery'" class="p-6 space-y-4">
            <p class="text-sm text-gray-500 mb-4">Select which delivery modes are allowed for this template.</p>

            @php
                $isBlocked = $template->isEsignBlocked();
            @endphp

            <div class="space-y-3">
                <label class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-all"
                       :class="deliveryModes.includes('esign') ? 'border-blue-400 bg-blue-50' : 'border-gray-200'"
                       @if($isBlocked) style="opacity:0.5; pointer-events:none;" @endif>
                    <input type="checkbox" value="esign"
                           :checked="deliveryModes.includes('esign')"
                           @change="toggleDeliveryMode('esign')"
                           class="rounded border-gray-300 text-blue-600 mt-0.5"
                           @if($isBlocked) disabled @endif>
                    <div>
                        <span class="text-sm font-semibold text-gray-800">E-Signature</span>
                        <p class="text-xs text-gray-500 mt-0.5">Sign electronically through the secure online portal</p>
                        @if($isBlocked)
                        <p class="text-xs text-amber-600 mt-1 font-medium">Disabled: Sale agreements must be signed with wet ink per the Alienation of Land Act.</p>
                        @endif
                    </div>
                </label>

                <label class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-all"
                       :class="deliveryModes.includes('wet_ink') ? 'border-blue-400 bg-blue-50' : 'border-gray-200'">
                    <input type="checkbox" value="wet_ink"
                           :checked="deliveryModes.includes('wet_ink')"
                           @change="toggleDeliveryMode('wet_ink')"
                           class="rounded border-gray-300 text-blue-600 mt-0.5">
                    <div>
                        <span class="text-sm font-semibold text-gray-800">Wet Ink (Print & Sign)</span>
                        <p class="text-xs text-gray-500 mt-0.5">Download, print, sign in ink, scan and upload through secure portal</p>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-all"
                       :class="deliveryModes.includes('download') ? 'border-blue-400 bg-blue-50' : 'border-gray-200'">
                    <input type="checkbox" value="download"
                           :checked="deliveryModes.includes('download')"
                           @change="toggleDeliveryMode('download')"
                           class="rounded border-gray-300 text-blue-600 mt-0.5">
                    <div>
                        <span class="text-sm font-semibold text-gray-800">Download Only</span>
                        <p class="text-xs text-gray-500 mt-0.5">Generate PDF for download only — no signing pipeline</p>
                    </div>
                </label>
            </div>
        </div>
    </div>

    {{-- Toast notification --}}
    <div x-show="toast.show" x-transition
         :class="toast.type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'"
         class="fixed bottom-4 right-4 px-4 py-3 rounded-lg border shadow-lg text-sm font-medium z-50"
         x-text="toast.message">
    </div>
</div>

<script>
function wizardConfigEditor() {
    const config = @json($template->wizard_config ?? []);
    const parties = @json($template->signing_parties ?? []);
    const sects = @json($template->sections ?? []);
    const modes = '{{ $template->allowed_delivery_modes ?? "esign,wet_ink,download" }}'.split(',').filter(Boolean);

    return {
        activeTab: 'steps',
        saving: false,
        toast: { show: false, message: '', type: 'success' },

        // Wizard steps
        wizardSteps: (config.wizard_steps || [
            { key: 'property', label: 'Property', type: 'property_selector' },
            { key: 'landlord', label: 'Landlord', type: 'contact_selector', party: 'landlord' },
            { key: 'rental_details', label: 'Details', type: 'field_group' },
            { key: 'fill_review', label: 'Review & Fill', type: 'field_entry' },
            { key: 'sign_send', label: 'Sign & Send', type: 'signing' },
        ]),

        // Signing parties
        signingParties: parties.length > 0 ? [...parties] : ['agent'],
        customRoles: [],

        get allParties() {
            return [...this.signingParties, ...this.customRoles.filter(r => r.trim())];
        },

        // Sections
        sections: sects.length > 0 ? [...sects] : [],

        // Delivery modes
        deliveryModes: [...modes],

        init() {},

        // Step methods
        addStep() {
            this.wizardSteps.push({
                key: 'step_' + Date.now(),
                label: 'New Step',
                type: 'field_group',
            });
        },
        removeStep(idx) {
            this.wizardSteps.splice(idx, 1);
        },
        moveStep(idx, dir) {
            const newIdx = idx + dir;
            if (newIdx < 0 || newIdx >= this.wizardSteps.length) return;
            const item = this.wizardSteps.splice(idx, 1)[0];
            this.wizardSteps.splice(newIdx, 0, item);
        },

        // Party methods
        toggleParty(role) {
            const idx = this.signingParties.indexOf(role);
            if (idx >= 0) {
                this.signingParties.splice(idx, 1);
            } else {
                this.signingParties.push(role);
            }
        },
        addCustomRole() {
            this.customRoles.push('');
        },
        removeCustomRole(idx) {
            this.customRoles.splice(idx, 1);
        },
        movePartyOrder(idx, dir) {
            const parties = this.allParties;
            const newIdx = idx + dir;
            if (newIdx < 0 || newIdx >= parties.length) return;
            // Rebuild signingParties and customRoles from reordered list
            const item = parties.splice(idx, 1)[0];
            parties.splice(newIdx, 0, item);
            const standard = ['agent', 'seller', 'buyer', 'landlord', 'tenant', 'witness', 'supervisor'];
            this.signingParties = parties.filter(p => standard.includes(p));
            this.customRoles = parties.filter(p => !standard.includes(p));
        },

        // Section methods
        addSection() {
            this.sections.push({
                label: 'Section ' + (this.sections.length + 1),
                startPage: 1, startY: 0,
                endPage: 1, endY: 100,
            });
        },
        removeSection(idx) {
            this.sections.splice(idx, 1);
        },

        // Delivery mode methods
        toggleDeliveryMode(mode) {
            const idx = this.deliveryModes.indexOf(mode);
            if (idx >= 0) {
                if (this.deliveryModes.length > 1) {
                    this.deliveryModes.splice(idx, 1);
                }
            } else {
                this.deliveryModes.push(mode);
            }
        },

        // Save
        async save() {
            this.saving = true;
            try {
                const allParties = [...this.signingParties, ...this.customRoles.filter(r => r.trim())];
                const resp = await fetch('{{ route("docuperfect.templates.wizardConfig.save", $template->id) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        wizard_config: {
                            wizard_steps: this.wizardSteps,
                            default_signing_order: allParties,
                        },
                        signing_parties: allParties,
                        sections: this.sections,
                        allowed_delivery_modes: this.deliveryModes.join(','),
                    }),
                });

                if (resp.ok) {
                    this.showToast('Configuration saved', 'success');
                } else {
                    const text = await resp.text();
                    this.showToast('Save failed: ' + text, 'error');
                }
            } catch (e) {
                this.showToast('Save failed: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        showToast(message, type) {
            this.toast = { show: true, message, type };
            setTimeout(() => { this.toast.show = false; }, 3000);
        },
    };
}
</script>
@endsection
