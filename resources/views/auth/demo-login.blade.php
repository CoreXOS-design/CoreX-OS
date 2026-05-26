<x-guest-layout>
    <div class="space-y-4">
        <div class="text-center">
            <h2 class="text-lg font-semibold" style="color: #ffffff;">Demo Mode</h2>
            <p class="text-xs mt-1" style="color: var(--text-secondary);">
                Pick a role to enter the demo. No password required.
            </p>
        </div>

        @if ($errors->any())
            <div class="rounded-md px-3 py-2 text-xs"
                 style="background: color-mix(in srgb, var(--ds-red, #dc2626) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent);
                        color: var(--text-primary);">
                {{ $errors->first() }}
            </div>
        @endif

        @php
            $roles = [
                ['key' => 'admin',          'label' => 'Admin'],
                ['key' => 'branch_manager', 'label' => 'Branch Manager'],
                ['key' => 'agent',          'label' => 'Agent'],
                ['key' => 'viewer',         'label' => 'Viewer'],
            ];
        @endphp

        <div class="grid grid-cols-1 gap-2">
            @foreach ($roles as $r)
                <form method="POST" action="{{ route('demo.login', $r['key']) }}">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-md px-4 py-3 text-sm font-medium transition"
                            style="background: var(--brand-button, #0ea5e9); color: #fff;">
                        Sign in as {{ $r['label'] }}
                    </button>
                </form>
            @endforeach
        </div>

    </div>
</x-guest-layout>
