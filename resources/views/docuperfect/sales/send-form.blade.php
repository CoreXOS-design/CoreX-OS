@extends('layouts.nexus')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Send Document for Signing</h2>
            <div class="text-sm text-white/60">Upload a document and build the signing chain.</div>
        </div>
        <a href="{{ route('docuperfect.sales') }}" class="text-sm text-white/70 hover:text-white">Back to Dashboard</a>
    </div>

    {{-- Flash / errors --}}
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
    <div class="ds-status-card rounded-2xl p-6" x-data="salesSendForm()">

        <form action="{{ route('docuperfect.sales.send.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            @if($documentId)
                <input type="hidden" name="document_id" value="{{ $documentId }}">
            @endif

            {{-- Document name --}}
            <div class="mb-5">
                <label class="block text-sm font-medium text-slate-700 mb-1">Document Name</label>
                <input type="text" name="document_name" value="{{ old('document_name', $documentName) }}"
                       class="w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                       placeholder="e.g. Offer to Purchase — 14 Marine Drive"
                       required>
            </div>

            {{-- File upload --}}
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 mb-1">Upload Document (PDF)</label>
                <input type="file" name="uploaded_file" accept=".pdf,.doc,.docx"
                       class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                <p class="text-xs text-slate-400 mt-1">PDF, DOC, or DOCX — max 20MB. This file will be attached to the email.</p>
            </div>

            {{-- ═══════ Signing Chain ═══════ --}}
            <div class="mb-6">
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium text-slate-700">Signing Chain (in order)</label>
                    <span class="text-xs text-slate-400">Each person receives the document after the previous person returns their signed copy.</span>
                </div>

                <div class="space-y-3">
                    <template x-for="(recipient, index) in recipients" :key="index">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-bold text-slate-500" x-text="(index + 1) + '.'"></span>
                                <button type="button" @click="removeRecipient(index)" x-show="recipients.length > 1"
                                        class="text-xs text-red-500 hover:text-red-700 font-medium">
                                    Remove
                                </button>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Name</label>
                                    <input type="text" :name="'recipients[' + index + '][name]'" x-model="recipient.name"
                                           class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                                           placeholder="John Smith" required>
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Email</label>
                                    <input type="email" :name="'recipients[' + index + '][email]'" x-model="recipient.email"
                                           class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                                           placeholder="john@email.com" required>
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Role</label>
                                    <select :name="'recipients[' + index + '][role]'" x-model="recipient.role"
                                            class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
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
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button" @click="addRecipient()"
                        class="mt-3 inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 font-medium">
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
                <label class="block text-sm font-medium text-slate-700 mb-1">Message (optional)</label>
                <textarea name="message" rows="3"
                          class="w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                          placeholder="Please sign and return at your earliest convenience.">{{ old('message') }}</textarea>
                <p class="text-xs text-slate-400 mt-1">This message will be included in all emails to recipients.</p>
            </div>

            {{-- Submit --}}
            <button type="submit"
                    class="w-full sm:w-auto px-6 py-3 bg-slate-800 text-white text-sm font-semibold rounded-xl hover:bg-slate-700 transition-colors"
                    x-text="'Send to ' + (recipients[0]?.name || 'First Recipient') + ' →'">
                Send →
            </button>
            <p class="text-xs text-slate-400 mt-2">First person in the chain will receive the email immediately.</p>
        </form>
    </div>
</div>

<script>
function salesSendForm() {
    return {
        recipients: [
            { name: '', email: '', role: 'seller' }
        ],
        addRecipient() {
            this.recipients.push({ name: '', email: '', role: 'client' });
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
