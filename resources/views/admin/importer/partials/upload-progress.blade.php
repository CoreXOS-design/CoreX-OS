<div x-show="phase !== 'idle'" x-cloak class="mt-2 space-y-1">
    <div class="flex items-center justify-between text-xs">
        <span class="text-muted">
            <span x-show="phase === 'uploading'" x-text="'Uploading ' + formatBytes(bytesSent) + ' / ' + formatBytes(bytesTotal)"></span>
            <span x-show="phase === 'parsing'">Server parsing CSV — this may take a moment for large files…</span>
            <span x-show="phase === 'done'" class="text-emerald-400">Upload complete — redirecting…</span>
            <span x-show="phase === 'error'" class="text-red-400" x-text="error ?? 'Upload failed.'"></span>
        </span>
        <span class="text-muted" x-show="phase === 'uploading' || phase === 'parsing' || phase === 'done'"
              x-text="phase === 'parsing' ? '—' : progress + '%'"></span>
    </div>
    <div class="w-full bg-surface-2 rounded-md h-2 overflow-hidden">
        <div class="h-full transition-all duration-200"
             :class="{
                 'animate-pulse': phase === 'parsing',
                 'bg-red-500': phase === 'error',
             }"
             :style="'width: ' + (phase === 'parsing' || phase === 'done' || phase === 'error' ? 100 : progress) + '%; background: ' + (phase === 'error' ? '#ef4444' : 'var(--brand-button, #0ea5e9)') + ';'"></div>
    </div>
</div>
