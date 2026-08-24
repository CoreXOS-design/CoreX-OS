@extends('layouts.corex')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div style="background:var(--brand-default);" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Send Document for Signing</h2>
            <div class="text-sm text-white/60">Upload a document and build the signing chain.</div>
        </div>
        <a href="{{ route('docuperfect.sales') }}" class="text-sm text-white/70 hover:text-white">Back to Dashboard</a>
    </div>

    {{-- Flash / errors --}}
    {{-- Flash messages handled by global toast system --}}
    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Send form --}}
    <div class="ds-status-card rounded-2xl p-6" x-data="salesSendForm()">

        <form action="{{ route('docuperfect.sales.send.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            @if($documentId)
                <input type="hidden" name="document_id" value="{{ $documentId }}">
            @endif

            {{-- Document name --}}
            <div class="mb-5">
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Document Name</label>
                <input type="text" name="document_name" value="{{ old('document_name', $documentName) }}"
                       class="w-full rounded-xl border-[color:var(--border)] shadow-sm focus:border-[color:var(--brand-icon)] focus:ring-[color:var(--brand-icon)] text-sm"
                       placeholder="e.g. Offer to Purchase — 14 Marine Drive"
                       required>
            </div>

            {{-- File upload --}}
            <div class="mb-6">
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Upload Document (PDF)</label>
                <input type="file" name="uploaded_file" accept=".pdf,.doc,.docx"
                       class="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-[color:var(--surface-2)] file:text-[color:var(--brand-icon)] hover:file:bg-[color:var(--surface-2)]" style="color:var(--text-muted)">
                <p class="text-xs mt-1" style="color:var(--text-faint)">PDF, DOC, or DOCX — max 20MB. This file will be attached to the email.</p>
            </div>

            {{-- ═══════ Signing Chain ═══════ --}}
            <div class="mb-6">
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium" style="color:var(--text-secondary)">Signing Chain (in order)</label>
                    <span class="text-xs" style="color:var(--text-faint)">Each person receives the document after the previous person returns their signed copy.</span>
                </div>

                <div class="space-y-3">
                    <template x-for="(recipient, index) in recipients" :key="index">
                        <div class="rounded-xl border p-4" style="border-color:var(--border);background:var(--surface-2)">
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
                                           class="w-full rounded-lg border-[color:var(--border)] shadow-sm focus:border-[color:var(--brand-icon)] focus:ring-[color:var(--brand-icon)] text-sm"
                                           placeholder="John Smith" required>
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Email</label>
                                    <input type="email" :name="'recipients[' + index + '][email]'" x-model="recipient.email"
                                           class="w-full rounded-lg border-[color:var(--border)] shadow-sm focus:border-[color:var(--brand-icon)] focus:ring-[color:var(--brand-icon)] text-sm"
                                           placeholder="john@email.com" required>
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color:var(--text-muted)">Role</label>
                                    <select :name="'recipients[' + index + '][role]'" x-model="recipient.role"
                                            class="w-full rounded-lg border-[color:var(--border)] shadow-sm focus:border-[color:var(--brand-icon)] focus:ring-[color:var(--brand-icon)] text-sm">
                                        <option value="buyer">Buyer</option>
                                        <option value="seller">Seller</option>
                                        <option value="conveyancer">Conveyancer</option>
                                        <option value="witness">Witness</option>
                                        <option value="landlord">Landlord</option>
                                        <option value="tenant">Tenant</option>
                                        <option value="client">Client</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color:var(--text-muted)">ID / Passport No.</label>
                                    <input type="text" :name="'recipients[' + index + '][id_number]'" x-model="recipient.id_number"
                                           class="w-full rounded-lg border-[color:var(--border)] shadow-sm focus:border-[color:var(--brand-icon)] focus:ring-[color:var(--brand-icon)] text-sm"
                                           placeholder="SA ID or passport number" maxlength="20" required>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button" @click="addRecipient()"
                        class="mt-3 inline-flex items-center gap-1 text-sm font-medium" style="color:var(--brand-icon)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Add Next Recipient
                </button>
            </div>

            {{-- Chain preview --}}
            <div class="mb-6 p-3 rounded-xl bg-blue-50 border border-blue-100" x-show="recipients.length > 0">
                <div class="text-xs text-blue-600 font-semibold uppercase tracking-wider mb-1">Flow</div>
                <div class="text-sm text-blue-800">
                    <template x-for="(r, i) in recipients" :key="'preview-'+i">
                        <span>
                            <span x-text="r.name || '(unnamed)'"></span>
                            <span x-show="i < recipients.length - 1" class="text-blue-400 mx-1">&rarr;</span>
                        </span>
                    </template>
                </div>
            </div>

            {{-- Optional message --}}
            <div class="mb-6">
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Message (optional)</label>
                <textarea name="message" rows="3"
                          class="w-full rounded-xl border-[color:var(--border)] shadow-sm focus:border-[color:var(--brand-icon)] focus:ring-[color:var(--brand-icon)] text-sm"
                          placeholder="Please sign and return at your earliest convenience.">{{ old('message') }}</textarea>
                <p class="text-xs mt-1" style="color:var(--text-faint)">This message will be included in all emails to recipients.</p>
            </div>

            {{-- Submit --}}
            <button type="submit"
                    class="w-full sm:w-auto px-6 py-3 text-white text-sm font-semibold rounded-xl transition-colors bg-[color:var(--brand-button)] hover:opacity-90"
                    x-text="'Send to ' + (recipients[0]?.name || 'First Recipient') + ' →'">
                Send →
            </button>
            <p class="text-xs mt-2" style="color:var(--text-faint)">First person in the chain will receive the email immediately.</p>
        </form>
    </div>
</div>

<script>
function salesSendForm() {
    return {
        recipients: [
            { name: '', email: '', role: 'seller', id_number: '' }
        ],
        addRecipient() {
            this.recipients.push({ name: '', email: '', role: 'client', id_number: '' });
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
