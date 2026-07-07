<div x-show="phase !== 'idle'" x-cloak class="mt-2 space-y-1">
    <div class="flex items-center justify-between text-xs">
        <span style="color: var(--text-muted);">
            <span x-show="phase === 'uploading'" x-text="'Uploading ' + formatBytes(bytesSent) + ' / ' + formatBytes(bytesTotal)"></span>
            <span x-show="phase === 'parsing'">Server parsing CSV — this may take a moment for large files…</span>
            <span x-show="phase === 'done'" style="color: var(--ds-green, #059669);">Upload complete — redirecting…</span>
            <span x-show="phase === 'error'" style="color: var(--ds-crimson, #c41e3a);" x-text="error ?? 'Upload failed.'"></span>
        </span>
        <span style="color: var(--text-muted);" x-show="phase === 'uploading' || phase === 'parsing' || phase === 'done'"
              x-text="phase === 'parsing' ? '—' : progress + '%'"></span>
    </div>
    <div class="w-full rounded-md h-2 overflow-hidden" style="background: var(--surface-2);">
        <div class="h-full transition-all duration-200"
             :class="{ 'animate-pulse': phase === 'parsing' }"
             :style="'width: ' + (phase === 'parsing' || phase === 'done' || phase === 'error' ? 100 : progress) + '%; background: ' + (phase === 'error' ? 'var(--ds-crimson, #c41e3a)' : 'var(--brand-button, #0ea5e9)') + ';'"></div>
    </div>
</div>
