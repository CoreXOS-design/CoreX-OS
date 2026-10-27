{{-- AT-454 v2 phase 2 - the capture form of a captured task. Lives inside the page's rtsPage() Alpine component (openCapture / cap* state).
     What the agent found becomes real records: properties (match-or-create), people (found by phone or email first) and an owner linked
     to a property. Every field has an id of the form cap-<field name>, so an error message can link straight to it. --}}
@php
    $capInput = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $backDays = \App\Services\RoadToSold\SettingsService::forUser(auth()->user(), $track)->captureBackDateWorkingDays();
@endphp
<div x-show="capOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" @keydown.escape.window="capOpen = false">
    <form method="POST" action="{{ route('road-to-sold.captures.store') }}" enctype="multipart/form-data" class="w-full max-w-3xl rounded-md p-5 space-y-4 overflow-y-auto"
          style="background: var(--surface); border: 1px solid var(--border); max-height: 92vh;">
        @csrf
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <input type="hidden" name="token" :value="capToken">

        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Capture</h2>
            <select id="cap-task_id" name="task_id" x-model="capTaskId" @change="capReset()" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Task">
                <template x-for="t in capTasks" :key="t.id"><option :value="t.id" x-text="t.label" :selected="String(t.id) === String(capTaskId)"></option></template>
            </select>
        </div>

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));">
            <div x-show="capTask && capTask.kind !== 'none'">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="cap-area_label">Area</label>
                <input id="cap-area_label" type="text" name="area_label" x-model="capArea" maxlength="120" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $capInput }}">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="cap-occurred_at">When</label>
                <input id="cap-occurred_at" type="datetime-local" name="occurred_at" x-model="capWhen" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" title="Leave empty for now. Up to {{ $backDays }} working days back.">
            </div>
            <div x-show="capTask && capTask.kind === 'none'">
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="cap-quantity">How many</label>
                <input id="cap-quantity" type="number" name="quantity" min="1" max="1000" x-model="capQty" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $capInput }}">
            </div>
        </div>

        {{-- Properties found (and, for "properties and contacts", the owner of each) --}}
        <template x-if="capTask && (capTask.kind === 'property' || capTask.kind === 'both')">
            <div class="space-y-3">
                <template x-for="(p, i) in capProps" :key="p.key">
                    <div class="rounded-md p-3 space-y-2" style="border: 1px solid var(--border);">
                        <div class="grid gap-2" style="grid-template-columns: repeat(auto-fit, minmax(6.5rem, 1fr));">
                            <input :id="'cap-properties-' + i + '-street_number'" type="text" :name="'properties[' + i + '][street_number]'" x-model="p.street_number" placeholder="Number" maxlength="20" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Street number">
                            <input :id="'cap-properties-' + i + '-street_name'" type="text" :name="'properties[' + i + '][street_name]'" x-model="p.street_name" placeholder="Street" maxlength="120" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Street name">
                            <input :id="'cap-properties-' + i + '-suburb'" type="text" :name="'properties[' + i + '][suburb]'" x-model="p.suburb" placeholder="Suburb" maxlength="120" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Suburb">
                            <input :id="'cap-properties-' + i + '-complex_name'" type="text" :name="'properties[' + i + '][complex_name]'" x-model="p.complex_name" placeholder="Complex" maxlength="120" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Complex">
                            <input :id="'cap-properties-' + i + '-unit_number'" type="text" :name="'properties[' + i + '][unit_number]'" x-model="p.unit_number" placeholder="Unit" maxlength="20" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Unit">
                            <input :id="'cap-properties-' + i + '-erf_number'" type="text" :name="'properties[' + i + '][erf_number]'" x-model="p.erf_number" placeholder="Erf" maxlength="40" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Erf number">
                        </div>
                        <template x-if="capTask.kind === 'both'">
                            <div class="grid gap-2" style="grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));">
                                <input :id="'cap-properties-' + i + '-owner_name'" type="text" :name="'properties[' + i + '][owner_name]'" x-model="p.owner_name" placeholder="Owner name" maxlength="160" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Owner name">
                                <input :id="'cap-properties-' + i + '-owner_phone'" type="text" :name="'properties[' + i + '][owner_phone]'" x-model="p.owner_phone" placeholder="Owner phone" maxlength="40" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Owner phone">
                                <input :id="'cap-properties-' + i + '-owner_email'" type="email" :name="'properties[' + i + '][owner_email]'" x-model="p.owner_email" placeholder="Owner email" maxlength="190" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Owner email">
                            </div>
                        </template>
                        <div class="text-right" x-show="capProps.length > 1"><button type="button" class="corex-btn-outline corex-btn-xs" @click="capProps.splice(i, 1)">Remove</button></div>
                    </div>
                </template>
                <button type="button" class="corex-btn-outline corex-btn-xs" x-show="capProps.length < {{ \App\Services\RoadToSold\TaskCaptureService::MAX_PROPERTIES }}" @click="capAddProp()">Add a property</button>
            </div>
        </template>

        {{-- People met --}}
        <template x-if="capTask && capTask.kind === 'contact'">
            <div class="space-y-3">
                <template x-for="(c, i) in capContacts" :key="c.key">
                    <div class="rounded-md p-3 space-y-2" style="border: 1px solid var(--border);">
                        <div class="grid gap-2" style="grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));">
                            <input :id="'cap-contacts-' + i + '-name'" type="text" :name="'contacts[' + i + '][name]'" x-model="c.name" placeholder="Name" maxlength="160" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Name">
                            <input :id="'cap-contacts-' + i + '-phone'" type="text" :name="'contacts[' + i + '][phone]'" x-model="c.phone" placeholder="Phone" maxlength="40" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Phone">
                            <input :id="'cap-contacts-' + i + '-email'" type="email" :name="'contacts[' + i + '][email]'" x-model="c.email" placeholder="Email" maxlength="190" class="rounded-md px-3 py-2 text-xs" style="{{ $capInput }}" aria-label="Email">
                        </div>
                        <div class="text-right" x-show="capContacts.length > 1"><button type="button" class="corex-btn-outline corex-btn-xs" @click="capContacts.splice(i, 1)">Remove</button></div>
                    </div>
                </template>
                <button type="button" class="corex-btn-outline corex-btn-xs" x-show="capContacts.length < {{ \App\Services\RoadToSold\TaskCaptureService::MAX_CONTACTS }}" @click="capAddContact()">Add a person</button>
            </div>
        </template>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="cap-note">Note</label>
            <textarea id="cap-note" name="note" rows="3" maxlength="5000" x-model="capNote" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $capInput }}"></textarea>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="cap-photos">Photos</label>
            <input id="cap-photos" type="file" name="photos[]" accept="image/*" multiple class="text-xs" style="color: var(--text-primary);">
        </div>

        <div class="flex items-center justify-end gap-2 rts-actions">
            <button type="button" class="corex-btn-outline corex-btn-xs" @click="capOpen = false">Cancel</button>
            <button type="submit" class="corex-btn-primary corex-btn-xs" :disabled="!capTask">Save</button>
        </div>
    </form>
</div>
