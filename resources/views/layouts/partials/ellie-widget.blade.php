{{-- ELLIE_WIDGET_2026 — design-system aligned + right-dock on desktop --}}

{{-- Floating trigger button (always visible) --}}
<button id="ellie-btn" type="button" aria-label="Open Ellie" class="ellie-trigger"
        style="position:fixed; bottom:24px; right:24px; z-index:9998;">
    <img src="/images/ellie-128-circle.png" alt="Ellie">
    <span id="ellie-badge" style="display:none; position:absolute; top:2px; right:2px; width:18px; height:18px; border-radius:9999px; background:var(--ds-crimson, #ef4444); color:#fff; font-size:10px; font-weight:700; line-height:18px; text-align:center;">1</span>
</button>

{{-- Right-docked panel (desktop: pushes content; mobile: overlay) --}}
<div id="ellie-panel" class="ellie-dock-panel" style="display:none;">
    <div class="ellie-widget-header">
        <div class="flex items-center gap-2.5">
            <img src="/images/ellie-32-circle.png" alt="" class="w-8 h-8 rounded-full">
            <div>
                <div class="text-sm font-semibold leading-tight" style="color: var(--text-primary);">Ellie</div>
                <div class="text-xs font-medium" style="color: var(--text-muted);">HF Coastal Companion</div>
            </div>
        </div>
        <button id="ellie-close" type="button" aria-label="Close Ellie" class="ellie-widget-close">&times;</button>
    </div>

    <div id="ellie-messages" class="ellie-widget-messages">
        <div class="text-sm" style="color: var(--text-secondary);">
            Hi, I'm Ellie. Ask me anything about your performance, targets, listings, or next actions.
        </div>
    </div>

    <form id="ellie-form" class="ellie-widget-form">
        <input id="ellie-input" type="text" autocomplete="off" placeholder="Message Ellie…" class="ellie-widget-input">
        <button id="ellie-send" type="submit" class="corex-btn-primary">Send</button>
    </form>
</div>

<style>
    @keyframes ellie-breathe { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-3px) } }

    .ellie-trigger {
        width: 64px; height: 64px; border-radius: 9999px;
        border: 0; padding: 0; background: transparent;
        box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        overflow: hidden; cursor: pointer; transform: translateZ(0);
        animation: ellie-breathe 3.6s ease-in-out infinite;
        transition: opacity 0.2s;
    }
    .ellie-trigger img { width: 64px; height: 64px; border-radius: 9999px; display:block; object-fit: cover; object-position: center; }
    body.ellie-docked .ellie-trigger { opacity: 0; pointer-events: none; }

    /* Desktop: right-docked panel that pushes content */
    .ellie-dock-panel {
        position: fixed; top: 0; right: 0; bottom: 0;
        width: 400px;
        background: var(--surface);
        border-left: 1px solid var(--border);
        box-shadow: -4px 0 24px rgba(0,0,0,0.15);
        display: flex; flex-direction: column;
        z-index: 40;
    }

    /* When Ellie is docked, push main content left */
    body.ellie-docked .corex-main-content,
    body.ellie-docked .corex-content-area,
    body.ellie-docked [class*="corex-content"],
    body.ellie-docked main {
        margin-right: 400px;
        transition: margin-right 0.2s ease;
    }

    /* Mobile: overlay instead of dock */
    @media (max-width: 768px) {
        .ellie-dock-panel {
            width: 100%; max-width: 100%;
            top: 0; left: 0; right: 0; bottom: 0;
            border-left: none;
        }
        body.ellie-docked .corex-main-content,
        body.ellie-docked .corex-content-area,
        body.ellie-docked [class*="corex-content"],
        body.ellie-docked main {
            margin-right: 0;
        }
        .ellie-trigger { width: 56px; height: 56px; }
        .ellie-trigger img { width: 56px; height: 56px; }
    }

    .ellie-widget-header {
        display:flex; align-items:center; justify-content:space-between;
        padding: 0.75rem 0.875rem;
        background: var(--surface-2);
        border-bottom: 1px solid var(--border);
        flex: 0 0 auto;
    }

    .ellie-widget-close {
        border: 0; background: transparent;
        color: var(--text-muted);
        font-size: 1.25rem; line-height: 1; cursor: pointer; padding: 6px 8px;
        transition: color 150ms;
    }
    .ellie-widget-close:hover { color: var(--text-primary); }

    .ellie-widget-messages {
        flex: 1 1 auto; min-height: 0;
        padding: 0.75rem; overflow: auto;
        background: var(--bg);
    }

    .ellie-widget-form {
        display: flex; gap: 0.625rem;
        padding: 0.75rem;
        border-top: 1px solid var(--border);
        background: var(--surface);
        flex: 0 0 auto;
    }

    .ellie-widget-input {
        flex: 1;
        border-radius: 6px;
        border: 1px solid var(--border);
        background: var(--surface-2);
        color: var(--text-primary);
        padding: 0.625rem 0.75rem;
        outline: none;
        font-size: 0.875rem;
        transition: border-color 300ms, box-shadow 300ms;
    }
    .ellie-widget-input::placeholder { color: var(--text-muted); }
    .ellie-widget-input:focus {
        border-color: var(--brand-button);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent);
    }

    .ellie-widget-bubble {
        max-width: 85%;
        white-space: pre-wrap;
        padding: 0.625rem 0.75rem;
        border-radius: 6px;
        font-size: 0.8125rem;
        line-height: 1.45;
    }
    .ellie-widget-bubble.me {
        background: var(--brand-button);
        color: #fff;
    }
    .ellie-widget-bubble.ellie {
        background: var(--surface);
        border: 1px solid var(--border);
        color: var(--text-primary);
    }
</style>

<script>
(function(){
    const btn = document.getElementById('ellie-btn');
    const panel = document.getElementById('ellie-panel');
    const closeBtn = document.getElementById('ellie-close');
    const form = document.getElementById('ellie-form');
    const input = document.getElementById('ellie-input');
    const messages = document.getElementById('ellie-messages');

    if(!btn || !panel || !closeBtn || !form || !input || !messages) return;

    const KEY = 'ellie_open_v1';
    const CONVO_KEY = 'ELLIE_CONVO_ID';
    const csrf = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')) || '';
    const open = () => { panel.style.display = 'flex'; document.body.classList.add('ellie-docked'); localStorage.setItem(KEY,'1'); setTimeout(()=>input.focus(), 50); };
    const close = () => { panel.style.display = 'none'; document.body.classList.remove('ellie-docked'); localStorage.setItem(KEY,'0'); };

    btn.addEventListener('click', () => {
        if (panel.style.display === 'flex') close(); else open();
    });
    closeBtn.addEventListener('click', close);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close();
    });

    if (localStorage.getItem(KEY) === '1') open();

    function addBubble(text, who){
        const wrap = document.createElement('div');
        wrap.style.margin = '8px 0';
        wrap.style.display = 'flex';
        wrap.style.justifyContent = (who === 'me') ? 'flex-end' : 'flex-start';

        const b = document.createElement('div');
        b.textContent = text;
        b.className = 'ellie-widget-bubble ' + (who === 'me' ? 'me' : 'ellie');
        wrap.appendChild(b);

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }

    let busy = false;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = (input.value || '').trim();
        if(!msg || busy) return;

        addBubble(msg, 'me');
        input.value = '';
        busy = true;

        const typing = document.createElement('div');
        typing.style.fontSize = '0.75rem';
        typing.style.marginTop = '6px';
        typing.style.color = 'var(--text-muted)';
        typing.textContent = 'Ellie is typing…';
        messages.appendChild(typing);
        messages.scrollTop = messages.scrollHeight;

        try{
            let convoId = localStorage.getItem(CONVO_KEY);

                async function postEllie(convoIdValue){
                    const fd = new FormData();
                    if (csrf) fd.append('_token', csrf);
                    if (convoIdValue) fd.append('conversation_id', String(parseInt(convoIdValue, 10)));
                    fd.append('message', msg);

                    const r = await fetch("/ellie/send", {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            ...(csrf ? {'X-CSRF-TOKEN': csrf} : {})
                        },
                        body: fd
                    });

                    const ct = (r.headers.get('content-type') || '').toLowerCase();
                    const raw = await r.text();

                    let data = null;
                    if (ct.includes('application/json')) {
                        try { data = JSON.parse(raw || '{}'); } catch (e) { data = null; }
                    }

                    return { r, raw, data };
                }

                let out = await postEllie(convoId);

                if (!out.r.ok && out.r.status === 404 && (out.raw || '').includes('AiConversation')) {
                    localStorage.removeItem(CONVO_KEY);
                    convoId = null;
                    out = await postEllie(null);
                }

                if (!out.r.ok) {
                    const msg = (out.data && (out.data.message || out.data.error)) ? (out.data.message || out.data.error) : (out.raw || '').slice(0, 220);
                    throw new Error('HTTP ' + out.r.status + (msg ? (': ' + msg) : ''));
                }

                const data = out.data || {};
                if (data && data.conversation_id) localStorage.setItem(CONVO_KEY, String(data.conversation_id));
            typing.remove();
            addBubble((data && data.reply) ? data.reply : 'Sorry, I got no reply.', 'ellie');
        }catch(err){
            typing.remove();
            addBubble('Sorry — I hit an error (' + (err && err.message ? err.message : 'unknown') + ').', 'ellie');
        }finally{
            busy = false;
        }
    });
})();
</script>
{{-- /ELLIE_WIDGET_2026 --}}
