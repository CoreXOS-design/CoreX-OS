@extends('layouts.corex')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Company Settings</h2>
        <div class="text-sm text-white/60">VAT, listings-per-sale ratio, company details &amp; logo.</div>
    </div>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-100">
                <ul class="list-disc pl-5 text-sm space-y-1">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.performance-settings.update') }}" class="space-y-6" enctype="multipart/form-data">
            @csrf

        <div class="ds-status-card p-5">
            <h3 class="ds-section-header mb-4">Company Details</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Company Name</label>
                    <input type="text" name="company_name"
                           value="{{ old('company_name', $companyName ?? '') }}"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">FFC</label>
                    <input type="text" name="company_ffc"
                           value="{{ old('company_ffc', $companyFfc ?? '') }}"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Address</label>
                    <input type="text" name="company_address"
                           value="{{ old('company_address', $companyAddress ?? '') }}"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Telephone</label>
                    <input type="text" name="company_tel"
                           value="{{ old('company_tel', $companyTel ?? '') }}"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Company Logo</label>

                    @if(!empty($companyLogoUrl))
                        <div class="mb-2 flex items-center gap-3">
                            <img src="{{ $companyLogoUrl }}" alt="Company Logo" class="h-10 w-auto rounded border bg-white">
                            <span class="text-xs text-slate-500 dark:text-slate-400">Current logo will be used on printouts.</span>
                        </div>
                    @endif

                    <input type="file" name="company_logo" accept="image/*"
                           class="block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-[#0b2a4a] file:text-white hover:file:bg-[#163d5f] rounded-lg border border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 px-3 py-2">

                    <div class="mt-2 flex items-center gap-2">
                        <input type="hidden" name="clear_company_logo" value="0">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input type="checkbox" name="clear_company_logo" value="1" class="rounded border-slate-300 dark:border-slate-700">
                            Clear logo
                        </label>
                        <span class="text-xs text-slate-500 dark:text-slate-400">Upload replaces the current logo. Max 2MB.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="ds-status-card p-5">
            <h3 class="ds-section-header mb-4">Finance Defaults</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">VAT Rate (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="vat_rate"
                           value="{{ old('vat_rate', $vatRate) }}"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Commission is stored as GROSS; we remove VAT using this rate.</p>
                </div>

                <div>
                    <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Listings per Sale (Correctly priced)</label>
                    <input type="number" step="0.01" min="0.01" name="listings_per_sale"
                           value="{{ old('listings_per_sale', $listingsPerSale) }}"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Used to calculate how many correctly-priced listings are needed for the target sales.</p>
                </div>
            </div>
        </div>

            <div class="flex justify-end">
                <button class="corex-btn-primary">Save Settings</button>
            </div>
        </form>
</div>
@endsection
