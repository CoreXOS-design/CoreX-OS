<x-app-layout>
    <x-slot name="header">Edit Deal</x-slot>

    <div class="max-w-6xl mx-auto p-6">
        @include('admin.deals.form', ['mode' => 'edit', 'deal' => $deal, 'agents' => $agents, 'branches' => $branches])
    </div>
</x-app-layout>
