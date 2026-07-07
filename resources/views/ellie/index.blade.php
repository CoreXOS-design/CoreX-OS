{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
  $archived = (string)request()->get('archived') === '1';
  $conversation = $conversation ?? ($activeConversation ?? null);
  $conversation_id = $conversation_id ?? ($conversation?->id ?? (int)request()->get('conversation_id'));
@endphp

<style>
  /* ELLIE_CHATGPT_UI_2026 — design-system aligned */
  /* Fill the available page height via flexbox rather than a fixed viewport
     calc — the outer app shell (corex-app) is a definite-height flex column,
     so the pane adapts automatically to the env banner and any breakpoint. */
  .ellie-app { min-height: 400px; height: 78vh; display: flex; flex-direction: column; }
  .ellie-row { flex: 1 1 auto; min-height: 0; display: grid; grid-template-columns: 320px 1fr; gap: 1rem; overflow: hidden; }

  .ellie-pane { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;
                display:flex; flex-direction:column; min-height:0; }

  .ellie-pane-h { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border);
                  display:flex; align-items:center; justify-content:space-between; gap: 0.625rem; flex: 0 0 auto; background: var(--surface); }
  .ellie-pane-h .title { font-weight: 600; font-size: 1rem; color: var(--text-primary); }

  .ellie-scroll { flex: 1 1 auto; min-height:0; overflow:auto; background: var(--bg); }

  .ellie-list a { display:block; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); text-decoration:none;
                  color: var(--text-primary); transition: background 300ms; }
  .ellie-list a:hover { background: color-mix(in srgb, var(--brand-icon) 8%, transparent); }
  .ellie-list a.active { background: color-mix(in srgb, var(--brand-icon) 14%, transparent); }
  .ellie-list .conv-title { font-weight: 600; font-size: 0.875rem; }
  .ellie-list .meta { font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; }

  .ellie-messages { padding: 1.25rem; display:flex; flex-direction:column; gap: 0.75rem; }
  /* pre-line (not pre-wrap): keep the model's intentional line breaks (e.g.
     numbered steps) but collapse its stray leading/indent whitespace so the
     first line doesn't render with a ragged left margin. */
  .bubble { max-width: 72%; padding: 0.75rem 0.875rem; border-radius: 6px; line-height: 1.45; white-space: pre-line; word-wrap: break-word; overflow-wrap: anywhere; font-size: 0.875rem; }
  .bubble.user { margin-left: auto; background: var(--brand-button); color: #fff; }
  .bubble.assistant { margin-right: auto; background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); }
  .bubble .role { font-size: 0.6875rem; color: var(--text-muted); margin-bottom: 4px; font-weight: 600; }

  .ellie-compose { flex: 0 0 auto; padding: 0.875rem 1rem; border-top: 1px solid var(--border); background: var(--surface); }
  .ellie-form { display:flex; gap: 0.625rem; align-items: flex-end; }
  .ellie-input { flex: 1 1 auto; min-height: 44px; max-height: 140px; resize: vertical;
                 border: 1px solid var(--border); border-radius: 6px; padding: 0.625rem 0.75rem;
                 outline: none; font-size: 0.875rem; background: var(--surface-2); color: var(--text-primary);
                 transition: border-color 300ms, box-shadow 300ms; }
  .ellie-input:focus { border-color: var(--brand-button); box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent); }
  .ellie-input::placeholder { color: var(--text-muted); }
  .ellie-rename-input { min-width: 200px; min-height: 0; max-height: none; resize: none; padding: 6px 10px; }

  .ellie-empty { padding: 1.25rem; color: var(--text-secondary); font-size: 0.875rem; line-height: 1.5; }

  /* Desktop (lg+): the wrapper is full-height (lg:h-full), so let the app
     fill the space left by the branded header instead of a fixed vh. */
  @media (min-width: 1024px) {
    .ellie-app { flex: 1 1 auto; height: auto; min-height: 0; }
  }

  /* Below lg: sidebar/list stacks above the chat and the page scrolls. */
  @media (max-width: 1023px) {
    .ellie-row { grid-template-columns: 1fr; overflow: visible; }
    .ellie-row > .ellie-pane:first-child { max-height: 300px; }
  }
</style>

<div class="w-full flex flex-col gap-5 lg:h-full">

  {{-- Page header (Pattern A — branded) --}}
  <div class="rounded-md px-6 py-5 flex-shrink-0" style="background: var(--brand-default, #0b2a4a);">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div class="flex items-center gap-3">
        <img src="/images/ellie-32-circle.png" alt="Ellie" class="w-9 h-9 rounded-full">
        <div data-tour="ai-ellie-intro">
          <h1 class="text-xl font-bold text-white leading-tight">Ellie, Your AI Assistant</h1>
          <p class="text-sm text-white/60">Logged in as {{ auth()->user()->name }}</p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        @include('layouts.partials.tour-header-launcher')
        @if($archived)
          <a href="/ellie" class="corex-btn-outline">Hide Archived</a>
        @else
          <a href="/ellie?archived=1" class="corex-btn-outline">Show Archived</a>
        @endif
        <a href="/ellie?new=1{{ $archived ? '&archived=1' : '' }}" class="corex-btn-primary" data-tour="ai-ellie-new">+ New Conversation</a>
      </div>
    </div>
  </div>

  <div class="ellie-app">
    <div class="ellie-row">

      {{-- LEFT: Conversations --}}
      <div class="ellie-pane" data-tour="ai-ellie-conversations">
        <div class="ellie-pane-h">
          <div class="title">Conversations</div>
        </div>

        <div class="ellie-scroll ellie-list">
          @forelse($conversations as $c)
            <a href="/ellie?conversation_id={{ $c->id }}{{ $archived ? '&archived=1' : '' }}"
               class="{{ (int)$conversation_id === (int)$c->id ? 'active' : '' }}">
              <div class="conv-title">{{ $c->title ?? ('Conversation #'.$c->id) }}</div>
              <div class="meta">{{ optional($c->updated_at)->diffForHumans() }}</div>
            </a>
          @empty
            <div class="ellie-empty">No conversations yet.</div>
          @endforelse
        </div>
      </div>

      {{-- RIGHT: Chat --}}
      <div class="ellie-pane">
        <div class="ellie-pane-h">
          <div class="title">{{ $conversation->title ?? 'New Conversation' }}</div>
          <div class="flex items-center gap-2 flex-wrap">
            @if(!empty($conversation_id))
              <form method="POST" action="/ellie/rename" class="m-0 flex gap-2 items-center">
                @csrf
                <input type="hidden" name="conversation_id" value="{{ $conversation_id }}">
                <input type="hidden" name="return_archived" value="{{ $archived ? '1' : '' }}">
                <input type="text" name="title" value="{{ $conversation->title ?? '' }}" placeholder="Rename…" class="ellie-input ellie-rename-input">
                <button type="submit" class="corex-btn-outline">Rename</button>
              </form>

              @if(($conversation->status ?? '') === 'archived')
                <form method="POST" action="/ellie/unarchive" class="m-0">
                  @csrf
                  <input type="hidden" name="conversation_id" value="{{ $conversation_id }}">
                  <button type="submit" class="corex-btn-outline">Unarchive</button>
                </form>
              @else
                <form method="POST" action="/ellie/archive" class="m-0">
                  @csrf
                  <input type="hidden" name="conversation_id" value="{{ $conversation_id }}">
                  <input type="hidden" name="return_archived" value="{{ $archived ? '1' : '' }}">
                  <button type="submit" class="corex-btn-outline">Archive</button>
                </form>
              @endif
            @endif
          </div>
        </div>

        <div id="ellieScroll" class="ellie-scroll">
          <div class="ellie-messages" id="ellieMsgs">
            @forelse($messages as $m)
              @php $isUser = ($m->role ?? '') === 'user'; @endphp
              <div class="bubble {{ $isUser ? 'user' : 'assistant' }}">
                <div class="role">{{ $isUser ? 'You' : 'Ellie' }}</div>
                <span class="bubble-content">{{ $m->content }}</span>
              </div>
            @empty
              <div class="ellie-empty">Hi, I'm Ellie. Ask me anything about your performance, targets, listings, or next actions.</div>
            @endforelse
          </div>
        </div>

        <div class="ellie-compose" data-tour="ai-ellie-compose">
          <form method="POST" action="/ellie/send" class="ellie-form" id="ellieSendForm">
            @csrf
            <input type="hidden" name="conversation_id" value="{{ $conversation_id ?? '' }}">
            <textarea name="message" class="ellie-input" placeholder="Message Ellie..." autocomplete="off" data-tour="ai-ellie-input"></textarea>
            <button class="corex-btn-primary" type="submit" data-tour="ai-ellie-send">Send</button>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
  // ELLIE_SEND_REDIRECT_2026: send via fetch then redirect back to /ellie so the left list updates immediately
  (function(){
    const form = document.getElementById('ellieSendForm');
    if(!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const fd = new FormData(form);
      const msg = (fd.get('message') || '').toString().trim();
      if(!msg) return;

      try{
        const res = await fetch(form.action, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        });

        const data = await res.json();

        if(data && data.ok){
          const url = new URL('/ellie', window.location.origin);
          url.searchParams.set('conversation_id', data.conversation_id);
          if("{{ $archived ? '1' : '' }}" === "1") url.searchParams.set('archived','1');
          window.location.href = url.toString();
          return;
        }

        window.location.reload();
      }catch(err){
        window.location.reload();
      }
    });
  })();

  // ELLIE_LINKIFY_FOLLOW_2026: make bare internal paths in Ellie's replies
  // clickable, and when the user clicks one, hand off to the floating widget so
  // Ellie "follows" onto the destination page with the same steps.
  (function(){
    const FOLLOW_KEY = 'ellie_follow_v1';
    const OPEN_KEY = 'ellie_open_v1';
    function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function linkify(text){
      return escapeHtml(text).replace(/(^|[\s(])(\/[A-Za-z][\w\-\/]*)/g,
        (m, pre, path) => pre + '<a href="' + path + '" class="ellie-link" data-ellie-link="1">' + path + '</a>');
    }
    document.querySelectorAll('.bubble.assistant .bubble-content').forEach(el => {
      el.innerHTML = linkify(el.textContent);
    });
    document.addEventListener('click', (e) => {
      const a = e.target.closest('a[data-ellie-link]');
      if (!a) return;
      const bubble = a.closest('.bubble.assistant');
      const stepsText = bubble ? (bubble.querySelector('.bubble-content')?.textContent || '') : '';
      if (stepsText) { try { localStorage.setItem(FOLLOW_KEY, JSON.stringify({ text: stepsText, ts: Date.now() })); } catch(_){} }
      localStorage.setItem(OPEN_KEY, '1');
    });
  })();
</script>
<style>
  .bubble.assistant .ellie-link { color: var(--brand-button); font-weight: 600; text-decoration: underline; word-break: break-all; }
  .bubble.assistant .ellie-link:hover { opacity: 0.85; }
</style>
@endsection
