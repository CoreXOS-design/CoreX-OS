{{-- TFS Screening — full-screen modal --}}
<?php
    $tfsData = $submission->form_data ?? [];
    $tfsPersonal = $tfsData['personal'] ?? [];
    $tfsEntity = $tfsData['entity'] ?? [];
    $tfsContactName = $tfsPersonal['full_name'] ?? $submission->contact?->full_name ?? 'Unknown';
    $tfsIdNumber = $tfsPersonal['id_number'] ?? '';
    $tfsDob = $tfsPersonal['date_of_birth'] ?? '';
    $tfsNationality = $tfsPersonal['nationality'] ?? '';
    $tfsEntityName = '';
    if ($submission->entity_type === 'company') { $tfsEntityName = $tfsEntity['company_name'] ?? ''; }
    elseif ($submission->entity_type === 'trust') { $tfsEntityName = $tfsEntity['trust_name'] ?? ''; }
    elseif ($submission->entity_type === 'partnership') { $tfsEntityName = $tfsEntity['partnership_name'] ?? ''; }

    $tfsFields = array_filter([
        ['label' => 'Full Name', 'value' => $tfsContactName],
        ['label' => 'ID / Passport', 'value' => $tfsIdNumber],
        ['label' => 'Date of Birth', 'value' => $tfsDob],
        ['label' => 'Nationality', 'value' => $tfsNationality],
        ['label' => 'Entity Name', 'value' => $tfsEntityName],
    ], fn($f) => !empty($f['value']));
?>

<div x-data="{ tfsModal: false, iframeError: false }">
    {{-- Trigger button --}}
    <button type="button" @click="tfsModal = true"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 text-teal-600"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
        TFS Screening
    </button>

    {{-- Full-screen modal --}}
    <div x-show="tfsModal" x-cloak
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         @keydown.escape.window="tfsModal = false"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);">

        {{-- Modal body --}}
        <div @click.away="tfsModal = false"
             class="bg-white flex flex-col"
             style="width: 95vw; height: 90vh; max-width: 1600px;">

            {{-- Top bar --}}
            <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 flex-shrink-0" style="background: var(--text-primary);">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-teal-400"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    <span class="text-sm font-bold text-white">TFS Screening</span>
                    <span class="text-sm text-slate-400">&mdash; {{ $tfsContactName }}</span>
                </div>
                <button type="button" @click="tfsModal = false" class="text-slate-400 hover:text-white transition p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            {{-- Two-column content --}}
            <div class="flex flex-1 overflow-hidden">
                {{-- LEFT: Contact details (fixed 300px) --}}
                <div class="w-[300px] flex-shrink-0 border-r border-slate-200 overflow-y-auto p-5" style="background: #f8fafc;">
                    <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-4">Contact Details for Screening</h4>

                    <div class="space-y-3">
                        @foreach($tfsFields as $field)
                        <div class="bg-white border border-slate-200 p-3">
                            <div class="text-xs text-slate-400 mb-0.5">{{ $field['label'] }}</div>
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-base font-bold text-slate-900 break-all leading-tight">{{ $field['value'] }}</div>
                                <button type="button"
                                        onclick="tfsCopyField('{{ addslashes($field['value']) }}', this)"
                                        class="flex-shrink-0 w-7 h-7 flex items-center justify-center text-slate-400 hover:text-teal-600 hover:bg-teal-50 transition" title="Copy">
                                    <svg class="tfs-copy-icon w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0" /></svg>
                                    <svg class="tfs-ok-icon w-4 h-4 text-emerald-500" style="display:none;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                </button>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <p class="text-xs text-slate-400 mt-4 leading-relaxed">Copy each field and paste into the FIC search form.</p>

                    <div class="mt-6 pt-4 border-t border-slate-200">
                        <a href="https://tfs.fic.gov.za/Pages/Search" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-teal-600 transition">
                            Open FIC TFS in new tab
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                        </a>
                    </div>
                </div>

                {{-- RIGHT: Embedded FIC search --}}
                <div class="flex-1 flex flex-col overflow-hidden">
                    <div x-show="!iframeError" class="flex-1">
                        <iframe src="https://tfs.fic.gov.za/Pages/Search"
                                style="width: 100%; height: 100%; border: none;"
                                x-on:error="iframeError = true"
                                sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
                                referrerpolicy="no-referrer"></iframe>
                    </div>

                    <div x-show="iframeError" x-cloak class="flex-1 flex flex-col items-center justify-center text-center" style="background: #f8fafc;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-12 h-12 text-slate-300 mb-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                        <p class="text-base font-semibold text-slate-600 mb-2">Unable to embed FIC search</p>
                        <p class="text-sm text-slate-400 mb-4">The FIC website does not allow embedding. Open it in a new tab instead.</p>
                        <a href="https://tfs.fic.gov.za/Pages/Search" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">
                            Open FIC TFS in New Tab
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function tfsCopyField(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
    var ci = btn.querySelector('.tfs-copy-icon'), oi = btn.querySelector('.tfs-ok-icon');
    if (ci && oi) { ci.style.display = 'none'; oi.style.display = ''; setTimeout(function() { ci.style.display = ''; oi.style.display = 'none'; }, 1500); }
}
</script>
