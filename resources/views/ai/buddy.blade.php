@extends('layouts.corex')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold" style="color:var(--text-primary)">HF AI Buddy</h1>
            <div class="text-sm mt-1" style="color:var(--text-secondary)">
                Chat to your assistant. (This is private to your login.)
            </div>
        </div>
        <div class="text-xs" style="color:var(--text-muted)">
            Powered by HF AI service
        </div>
    </div>

    <div class="rounded-2xl border shadow-sm overflow-hidden" style="border-color:var(--border); background:var(--surface)">
        <div id="chatLog" class="p-4 space-y-3 h-[480px] overflow-auto">
            <div class="text-sm" style="color:var(--text-muted)">
                Hi {{ $user?->name ?? 'there' }} — ask me anything about what to do next.
            </div>
        </div>

        <div class="border-t p-3" style="border-color:var(--border)">
            <form id="chatForm" class="flex gap-2">
                <input id="chatInput" type="text" autocomplete="off"
                       class="flex-1 rounded-xl border px-3 py-2 text-sm"
                       style="border-color:var(--border); background:var(--surface-2); color:var(--text-primary)"
                       placeholder="Type your message…"/>
                <button id="sendBtn" type="submit"
                        class="rounded-xl px-4 py-2 text-sm font-medium"
                        style="background:var(--brand-button); color:#fff">
                    Send
                </button>
            </form>
            <div id="chatHint" class="mt-2 text-xs" style="color:var(--text-muted)"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const log = document.getElementById('chatLog');
    const form = document.getElementById('chatForm');
    const input = document.getElementById('chatInput');
    const btn = document.getElementById('sendBtn');
    const hint = document.getElementById('chatHint');

    function addBubble(text, who) {
        const wrap = document.createElement('div');
        wrap.className = 'flex ' + (who === 'me' ? 'justify-end' : 'justify-start');

        const b = document.createElement('div');
        b.className = 'max-w-[85%] rounded-2xl px-4 py-2 text-sm';
        if (who === 'me') {
            b.style.background = 'var(--brand-button)';
            b.style.color = '#fff';
        } else {
            b.style.background = 'var(--surface-2)';
            b.style.color = 'var(--text-primary)';
        }

        b.textContent = text;
        wrap.appendChild(b);
        log.appendChild(wrap);
        log.scrollTop = log.scrollHeight;
    }

    async function send(message) {
        hint.textContent = '';
        btn.disabled = true;
        btn.classList.add('opacity-70');

        try {
            const res = await fetch('/ai/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });

            if (!res.ok) {
                const t = await res.text();
                throw new Error('HTTP ' + res.status + ' ' + t);
            }

            const data = await res.json();
            addBubble(data.reply || '(no reply)', 'bot');
        } catch (e) {
            hint.textContent = 'Error: ' + (e && e.message ? e.message : e);
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-70');
        }
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const msg = (input.value || '').trim();
        if (!msg) return;
        input.value = '';
        addBubble(msg, 'me');
        send(msg);
    });

    setTimeout(() => input && input.focus(), 150);
})();
</script>
@endsection
