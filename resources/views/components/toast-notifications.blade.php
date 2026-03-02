{{-- Global Toast Notification System --}}
{{-- Include once in the main layout (layouts.corex) --}}
{{-- Usage from JS: window.showToast('Message', 'success|error|info|warning', 4000) --}}
{{-- Automatically picks up Laravel session('success'), session('error'), session('warning'), session('info') --}}

<div id="toast-container"
     class="fixed top-4 right-4 z-50 flex flex-col gap-2 pointer-events-none"
     x-data="toastSystem()"
     @toast.window="addToast($event.detail.message, $event.detail.type || 'success', $event.detail.duration || 4000)">

    <template x-for="toast in toasts" :key="toast.id">
        <div class="pointer-events-auto transform transition-all duration-300 ease-out"
             x-show="toast.visible"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-x-8"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-8">

            <div class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg min-w-[300px] max-w-[450px]"
                 :class="{
                     'bg-green-600 text-white': toast.type === 'success',
                     'bg-red-600 text-white': toast.type === 'error',
                     'bg-blue-600 text-white': toast.type === 'info',
                     'bg-yellow-500 text-white': toast.type === 'warning',
                 }">

                {{-- Icon --}}
                <div class="flex-shrink-0">
                    <template x-if="toast.type === 'success'">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </template>
                    <template x-if="toast.type === 'error'">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </template>
                    <template x-if="toast.type === 'info'">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </template>
                    <template x-if="toast.type === 'warning'">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.072 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </template>
                </div>

                {{-- Message --}}
                <p class="text-sm font-medium flex-1" x-text="toast.message"></p>

                {{-- Close button --}}
                <button @click="removeToast(toast.id)" class="flex-shrink-0 opacity-70 hover:opacity-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </template>
</div>

<script>
function toastSystem() {
    return {
        toasts: [],
        nextId: 0,

        addToast(message, type = 'success', duration = 4000) {
            const id = this.nextId++;
            this.toasts.push({ id, message, type, visible: true });

            if (duration > 0) {
                setTimeout(() => this.removeToast(id), duration);
            }
        },

        removeToast(id) {
            const toast = this.toasts.find(t => t.id === id);
            if (toast) {
                toast.visible = false;
                setTimeout(() => {
                    this.toasts = this.toasts.filter(t => t.id !== id);
                }, 300);
            }
        }
    };
}

// Global function callable from anywhere
window.showToast = function(message, type, duration) {
    type = type || 'success';
    duration = duration || 4000;
    window.dispatchEvent(new CustomEvent('toast', {
        detail: { message: message, type: type, duration: duration }
    }));
};

// Pick up Laravel flash session messages on page load
document.addEventListener('DOMContentLoaded', function() {
    @if(session('success'))
        window.showToast(@json(session('success')), 'success');
    @endif
    @if(session('error'))
        window.showToast(@json(session('error')), 'error');
    @endif
    @if(session('warning'))
        window.showToast(@json(session('warning')), 'warning');
    @endif
    @if(session('info'))
        window.showToast(@json(session('info')), 'info');
    @endif
    @if(session('status'))
        window.showToast(@json(session('status')), 'success');
    @endif
});
</script>
