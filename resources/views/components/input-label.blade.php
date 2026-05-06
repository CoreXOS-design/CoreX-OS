@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm']) }} style="color: var(--text-secondary);">
    {{ $value ?? $slot }}
</label>
