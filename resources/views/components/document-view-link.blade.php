{{--
    The "View" affordance that sits next to every Download in a CoreX drive.
    Spec: .ai/specs/document-inline-view.md §5.1

    Usage:
        <x-document-view-link :url="route('corex.properties.files.view', [$property, $doc])"
                              :name="$doc->original_name"
                              :is-image="$doc->isImage()"
                              :download-url="$canDownload ? route('...download', [...]) : null" />

    Props:
        url          (required) the inline (Content-Disposition: inline) URL
        name         file name shown in the viewer header
        isImage      true renders an <img> instead of an <iframe>
        downloadUrl  optional — shows a Download link inside the viewer header
        variant      'link' (default, a text link) or 'icon' (an eye icon button, for dense tables)
        label        override the link text (default "View")

    It is a REAL anchor to the inline URL with target="_blank": if the viewer script has not
    booted, the click still opens the document in a new tab rather than doing nothing.
--}}
@props([
    'url',
    'name'        => 'Document',
    'isImage'     => false,
    'downloadUrl' => null,
    'variant'     => 'link',
    'label'       => 'View',
])

@php
    // json_encode only — Blade's own {{ }} escaping turns the quotes into &quot; for the
    // attribute, which the browser decodes back before running the handler. Do NOT use
    // Js::from() here: it pre-escapes for an attribute, and {{ }} would escape it twice.
    $arg = fn ($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Returns false (cancelling the anchor's own navigation) only when the viewer handled it,
    // so a page where the viewer script never booted falls through to target="_blank".
    $onclick = 'return !(window.CoreXDocViewer && window.CoreXDocViewer.open('
        . $arg($url) . ', '
        . $arg($name) . ', '
        . ($isImage ? 'true' : 'false') . ', '
        . $arg($downloadUrl) . '))';
@endphp

@if($variant === 'icon')
    <a href="{{ $url }}" target="_blank" rel="noopener" onclick="{{ $onclick }}"
       class="p-1.5 rounded hover:bg-[color:var(--surface-2)]" title="View {{ $name }}"
       style="color: var(--brand-icon);">
        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
    </a>
@else
    <a href="{{ $url }}" target="_blank" rel="noopener" onclick="{{ $onclick }}"
       {{ $attributes->merge(['class' => 'text-xs font-semibold no-underline']) }}
       @if(! $attributes->has('style')) style="color: var(--brand-icon, #0ea5e9);" @endif
       title="View {{ $name }} without downloading it">{{ $label }}</a>
@endif
