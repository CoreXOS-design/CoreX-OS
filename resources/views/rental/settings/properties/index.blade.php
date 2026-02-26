@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Rental Properties</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.settings') }}" class="text-white/60 hover:text-white">&larr; Rental Settings</a>
            </div>
        </div>
        <a href="{{ route('rental.settings.properties.create') }}"
           class="bg-white/15 hover:bg-white/25 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
            + Add Property
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('success') }}
        </div>
    @endif

    <div class="space-y-3">
        @forelse($active as $property)
        <div class="bg-white border rounded-lg p-4">
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="font-semibold text-gray-900">{{ $property->full_address }}</h4>
                    <div class="flex flex-wrap gap-3 mt-2 text-sm text-gray-500">
                        @if($property->property_type)
                            <span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs">
                                {{ \App\Models\Rental\RentalProperty::PROPERTY_TYPES[$property->property_type] ?? $property->property_type }}
                            </span>
                        @endif
                        @if($property->landlord_name)
                            <span>Owner: {{ $property->landlord_name }}</span>
                        @endif
                        @if($property->monthly_rental)
                            <span>R {{ number_format($property->monthly_rental, 2) }} /month</span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('rental.settings.properties.edit', $property) }}"
                       class="text-sm text-blue-600 hover:text-blue-800">Edit</a>
                    <form method="POST" action="{{ route('rental.settings.properties.toggle', $property) }}">
                        @csrf
                        <button type="submit" class="text-sm text-orange-500 hover:text-orange-700"
                                onclick="return confirm('Deactivate this property?')">Deactivate</button>
                    </form>
                </div>
            </div>
        </div>
        @empty
        <div class="bg-white border rounded-lg p-8 text-center text-gray-400">
            <p class="text-lg">No properties yet</p>
            <p class="text-sm mt-1">Add your first rental property to get started</p>
            <a href="{{ route('rental.settings.properties.create') }}"
               class="inline-block mt-4 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                + Add Property
            </a>
        </div>
        @endforelse

        {{-- Inactive properties --}}
        @if($inactive->count() > 0)
        <div x-data="{ show: false }" class="mt-6">
            <button @click="show = !show" class="text-sm text-gray-400 hover:text-gray-600">
                Inactive Properties ({{ $inactive->count() }}) <span x-text="show ? '&#9660;' : '&#9654;'"></span>
            </button>
            <div x-show="show" x-collapse class="mt-2 space-y-2">
                @foreach($inactive as $property)
                <div class="bg-gray-50 border rounded-lg p-4 opacity-60">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">{{ $property->full_address }}</span>
                        <form method="POST" action="{{ route('rental.settings.properties.toggle', $property) }}">
                            @csrf
                            <button type="submit" class="text-sm text-green-600 hover:text-green-800">Reactivate</button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

</div>
@endsection
