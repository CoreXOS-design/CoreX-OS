@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto py-6">
    <div class="bg-white shadow rounded p-6">
        <h1 class="text-xl font-semibold mb-4">Company Settings</h1>

        @if (session('status'))
            <div class="mb-4 p-3 rounded bg-green-50 border border-green-200 text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.performance-settings.update') }}" class="space-y-4" enctype="multipart/form-data">
        <div class="border rounded p-4 bg-gray-50">
            <h2 class="text-base font-semibold mb-3">Company Settings</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                    <input type="text" name="company_name"
                           value="{{ old('company_name', $companyName ?? '') }}"
                           class="w-full border rounded px-3 py-2">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">FFC</label>
                    <input type="text" name="company_ffc"
                           value="{{ old('company_ffc', $companyFfc ?? '') }}"
                           class="w-full border rounded px-3 py-2">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <input type="text" name="company_address"
                           value="{{ old('company_address', $companyAddress ?? '') }}"
                           class="w-full border rounded px-3 py-2">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Telephone</label>
                    <input type="text" name="company_tel"
                           value="{{ old('company_tel', $companyTel ?? '') }}"
                           class="w-full border rounded px-3 py-2">
                </div>
                  <div class="md:col-span-2">
                      <label class="block text-sm font-medium text-gray-700 mb-1">Company Logo</label>

                      @if(!empty($companyLogoUrl))
                          <div class="mb-2 flex items-center gap-3">
                              <img src="{{ $companyLogoUrl }}" alt="Company Logo" class="h-10 w-auto rounded border bg-white">
                              <span class="text-xs text-gray-600">Current logo will be used on printouts.</span>
                          </div>
                      @endif

                      <input type="file" name="company_logo" accept="image/*" class="w-full border rounded px-3 py-2 bg-white">

                      <div class="mt-2 flex items-center gap-2">
                          <input type="hidden" name="clear_company_logo" value="0">
                          <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                              <input type="checkbox" name="clear_company_logo" value="1">
                              Clear logo
                          </label>
                          <span class="text-xs text-gray-500">Upload replaces the current logo. Max 2MB.</span>
                      </div>
                  </div>
            </div>
        </div>

            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">VAT Rate (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="vat_rate"
                       value="{{ old('vat_rate', $vatRate) }}"
                       class="w-full border rounded px-3 py-2">
                <p class="text-xs text-gray-500 mt-1">Commission is stored as GROSS; we remove VAT using this rate.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Listings per Sale (Correctly priced)</label>
                <input type="number" step="0.01" min="0.01" name="listings_per_sale"
                       value="{{ old('listings_per_sale', $listingsPerSale) }}"
                       class="w-full border rounded px-3 py-2">
                <p class="text-xs text-gray-500 mt-1">Used to calculate how many correctly-priced listings are needed for the target sales.</p>
            </div>

            <div class="pt-2">
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
