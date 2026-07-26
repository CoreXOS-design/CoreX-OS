@extends('layouts.corex')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Upload &amp; Send for Signing</h2>
            <div class="text-sm text-white/60">Upload a rental document and build the signing chain.</div>
        </div>
        <a href="{{ route('rental.signatures') }}" class="text-sm text-white/70 hover:text-white">Back to Dashboard</a>
    </div>

    {{-- Flash / errors --}}
    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Send form --}}
    <div class="ds-status-card rounded-2xl p-6" x-data="rentalSendForm()">

        <form action="{{ route('docuperfect.rental.uploadAndSend.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            {{-- Document name --}}
            <div class="mb-5">
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Document Name</label>
                <input type="text" name="document_name" value="{{ old('document_name') }}"
                       class="w-full rounded-xl shadow-sm text-sm"
                       style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                       placeholder="e.g. Lease Agreement — 14 Marine Drive, Unit 3"
                       required>
            </div>

            {{-- File upload --}}
            <div class="mb-5">
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Upload Document (PDF)</label>
                <input type="file" name="uploaded_file" accept=".pdf,.doc,.docx"
                       class="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-[var(--surface-2)] file:text-[var(--brand-icon)] hover:file:opacity-90"
                       style="color:var(--text-muted)"
                       required>
                <p class="text-xs mt-1" style="color:var(--text-faint)">PDF, DOC, or DOCX — max 20MB. This file will be sent to recipients for signing.</p>
            </div>

            {{-- Property reference (optional) --}}
            <div class="mb-6">
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Property Reference <span class="font-normal" style="color:var(--text-faint)">(optional)</span></label>
                <input type="text" name="property_reference" value="{{ old('property_reference') }}"
                       class="w-full rounded-xl shadow-sm text-sm"
                       style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                       placeholder="e.g. Unit 3, 14 Marine Drive, Shelly Beach">
            </div>

            {{-- ═══════ Signing Chain ═══════ --}}
            <div class="mb-6">
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium" style="color:var(--text-secondary)">Signing Chain (in order)</label>
                    <span class="text-xs" style="color:var(--text-faint)">Each person receives the document after the previous person returns their signed copy.</span>
                </div>

                <div class="space-y-3">
                    <template x-for="(recipient, index) in recipients" :key="index">
                        <div class="rounded-xl border p-4" style="border-color:var(--border); background:var(--surface-2)">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-bold" style="color:var(--text-muted)" x-text="(index + 1) + '.'"></span>
                                <button type="button" @click="removeRecipient(index)" x-show="recipients.length > 1"
                                        class="text-xs text-red-500 hover:text-red-700 font-medium">
                                    Remove
                                </button>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Name</label>
                                    <input type="text" :name="'recipients[' + index + '][name]'" x-model="recipient.name"
                                           class="w-full rounded-lg shadow-sm text-sm"
                                           style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                                           placeholder="John Smith" required>
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Email</label>
                                    <input type="email" :name="'recipients[' + index + '][email]'" x-model="recipient.email"
                                           class="w-full rounded-lg shadow-sm text-sm"
                                           style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                                           placeholder="john@email.com" required>
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Role</label>
                                    <select :name="recipient.roleSelect !== 'Other' ? 'recipients[' + index + '][role]' : ''"
                                            x-model="recipient.roleSelect"
                                            @change="recipient.role = $event.target.value === 'Other' ? '' : $event.target.value"
                                            class="w-full rounded-lg shadow-sm text-sm"
                                            style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                                            required>
                                        <option value="" disabled>Select role...</option>
                                        <option value="Tenant">Tenant</option>
                                        <option value="Landlord">Landlord</option>
                                        <option value="Buyer">Buyer</option>
                                        <option value="Seller">Seller</option>
                                        <option value="Witness">Witness</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    <input type="text"
                                           x-show="recipient.roleSelect === 'Other'"
                                           :name="recipient.roleSelect === 'Other' ? 'recipients[' + index + '][role]' : ''"
                                           x-model="recipient.role"
                                           class="w-full mt-2 rounded-lg shadow-sm text-sm"
                                           style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                                           placeholder="Enter custom role"
                                           :required="recipient.roleSelect === 'Other'">
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color:var(--text-muted)">ID / Passport No.</label>
                                    <input type="text" :name="'recipients[' + index + '][id_number]'" x-model="recipient.id_number"
                                           class="w-full rounded-lg shadow-sm text-sm"
                                           style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                                           placeholder="SA ID or passport number" maxlength="20" required>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button" @click="addRecipient()"
                        class="mt-3 inline-flex items-center gap-1 text-sm font-medium"
                        style="color:var(--brand-icon)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Add Next Recipient
                </button>
            </div>

            {{-- Chain preview --}}
            <div class="mb-6 p-3 rounded-xl border" x-show="recipients.length > 0"
                 style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); border-color:color-mix(in srgb, var(--brand-icon) 30%, transparent)">
                <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--brand-icon)">Flow</div>
                <div class="text-sm" style="color:var(--text-primary)">
                    <span style="color:var(--text-faint)">You (Agent)</span>
                    <template x-for="(r, i) in recipients" :key="'preview-'+i">
                        <span>
                            <span class="mx-1" style="color:var(--text-faint)">&rarr;</span>
                            <span x-text="r.name || '(unnamed)'"></span>
                        </span>
                    </template>
                </div>
            </div>

            {{-- Optional message --}}
            <div class="mb-6">
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Message (optional)</label>
                <textarea name="message" rows="3"
                          class="w-full rounded-xl shadow-sm text-sm"
                          style="border-color:var(--border); color:var(--text-primary); background:var(--surface)"
                          placeholder="Please sign and return at your earliest convenience.">{{ old('message') }}</textarea>
                <p class="text-xs mt-1" style="color:var(--text-faint)">This message will be included in all emails to recipients.</p>
            </div>

            {{-- Submit --}}
            <button type="submit"
                    class="w-full sm:w-auto px-6 py-3 text-white text-sm font-semibold rounded-xl transition-colors hover:opacity-90"
                    style="background:var(--brand-button)"
                    x-text="'Send to ' + (recipients[0]?.name || 'First Recipient') + ' →'">
                Send &rarr;
            </button>
            <p class="text-xs mt-2" style="color:var(--text-faint)">The agent step is marked as complete automatically. The first recipient in the chain will receive the signing email immediately.</p>
        </form>
    </div>
</div>

<script>
function rentalSendForm() {
    return {
        recipients: [
            { name: '', email: '', role: 'Tenant', roleSelect: 'Tenant', id_number: '' }
        ],
        addRecipient() {
            this.recipients.push({ name: '', email: '', role: '', roleSelect: '', id_number: '' });
        },
        removeRecipient(index) {
            if (this.recipients.length > 1) {
                this.recipients.splice(index, 1);
            }
        }
    };
}
</script>
@endsection
