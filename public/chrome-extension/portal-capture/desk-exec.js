/**
 * CoreX Desk phase 2 (take-over) - the EXECUTOR: replays support's clicks, typing, keys, scrolling and choices on the agent's page,
 * inside the agent's own browser, once the agent has pressed Allow. Spec .ai/specs/corex-desk.md section 14.
 *
 * One source for both clients: the CoreX page loads it as a plain script from /chrome-extension/portal-capture/desk-exec.js, and the
 * extension injects it into a shared tab. No chrome.* and no globals beyond window.CoreXDeskExec.
 *
 * Rules this file enforces - they are the contract, not courtesy:
 *   1. Addressed by selector (the console resolves it on its mirror), with the click's position as a fallback only for a click.
 *   2. A field the recorder masks (password, [data-desk-mask], and on third-party tabs anything that looks like a card/ID/bank/secret
 *      field - the host passes isBlocked) is NEVER clicked into, typed into, or changed. The refusal is reported; the value is never read.
 *   3. Desk's own UI (.rr-block, #corex-desk-*) is untouchable - support cannot press "Take back control" on the agent's behalf.
 *   4. What comes back is only {ok, reason} and a report line (action, selector, a short label, ok, count) - never a typed value.
 */
(function (root) {
  'use strict';

  var KINDS = ['click', 'dblclick', 'type', 'key', 'scroll', 'select', 'focus'];
  var KEYS = ['Enter', 'Tab', 'Escape', 'Backspace', 'Delete', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'];
  var NON_TEXT_INPUTS = ['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'image', 'hidden', 'range', 'color', 'password'];
  var MAX_TEXT = 500;

  function create(opts) {
    opts = opts || {};
    var doc = opts.doc || root.document;
    var win = opts.win || root;
    var isBlocked = opts.isBlocked || function () { return false; };

    // ── finding the element ───────────────────────────────────────────────────────

    function find(a) {
      var el = null;
      if (a.s) { try { el = doc.querySelector(a.s); } catch (e) { el = null; } }
      if (!el && a.type === 'click' && typeof a.x === 'number' && typeof a.y === 'number' && doc.elementFromPoint) {
        el = doc.elementFromPoint(Math.round(a.x * win.innerWidth), Math.round(a.y * win.innerHeight));
      }
      return el;
    }

    function inDeskUi(el) {
      return !!(el.closest && el.closest('.rr-block, [id^="corex-desk"]'));
    }

    function visible(el) {
      return !!(el.getClientRects && el.getClientRects().length) || el === doc.body || el === doc.documentElement;
    }

    function isTextField(el) {
      var tag = (el.tagName || '').toLowerCase();
      if (tag === 'textarea') return true;
      if (tag === 'input') return NON_TEXT_INPUTS.indexOf(String(el.type || 'text').toLowerCase()) === -1;
      return !!el.isContentEditable;
    }

    /** A short human label for the audit: what the element IS (button text, link text, field label) - never a field's value. */
    function label(el) {
      if (!el || el.nodeType !== 1) return '';
      var tag = el.tagName.toLowerCase();
      var pick = function (v) { v = (v || '').replace(/\s+/g, ' ').trim(); return v ? v.slice(0, 60) : ''; };
      var l = pick(el.getAttribute('aria-label')) || pick(el.getAttribute('title'));
      if (!l && el.labels && el.labels.length) l = pick(el.labels[0].textContent);
      if (!l && (tag === 'input' || tag === 'textarea' || tag === 'select')) l = pick(el.getAttribute('placeholder')) || pick(el.getAttribute('name')) || pick(el.id);
      if (!l && tag !== 'input' && tag !== 'textarea' && tag !== 'select') l = pick(el.innerText || el.textContent);
      return l || tag;
    }

    function fire(el, type, init) {
      var C = type.indexOf('pointer') === 0 && win.PointerEvent ? win.PointerEvent : win.MouseEvent;
      el.dispatchEvent(new C(type, Object.assign({ bubbles: true, cancelable: true, view: win, button: 0 }, init || {})));
    }

    function setValue(el, value) {
      var proto = el.tagName.toLowerCase() === 'textarea' ? win.HTMLTextAreaElement.prototype : win.HTMLInputElement.prototype;
      var setter = Object.getOwnPropertyDescriptor(proto, 'value');
      if (setter && setter.set) setter.set.call(el, value); else el.value = value;
      el.dispatchEvent(new win.Event('input', { bubbles: true }));
    }

    function insert(el, text) {
      el.focus();
      var done = false;
      try { done = typeof doc.execCommand === 'function' && doc.execCommand('insertText', false, text); } catch (e) { done = false; }
      if (!done && !el.isContentEditable) setValue(el, String(el.value || '') + text);
      else if (!done) el.textContent = String(el.textContent || '') + text;
    }

    function remove(el, forward) {
      el.focus();
      var done = false;
      try { done = typeof doc.execCommand === 'function' && doc.execCommand(forward ? 'forwardDelete' : 'delete'); } catch (e) { done = false; }
      if (!done && !el.isContentEditable) setValue(el, forward ? String(el.value || '').slice(1) : String(el.value || '').slice(0, -1));
    }

    function tabbable() {
      var all = doc.querySelectorAll('a[href], button, input, select, textarea, [tabindex]');
      return Array.prototype.filter.call(all, function (e) { return !e.disabled && e.tabIndex >= 0 && visible(e) && !inDeskUi(e); });
    }

    // ── one action ────────────────────────────────────────────────────────────────

    /** @returns {{ok:boolean, reason?:string, report:{type:string, selector:string, label:string, ok:boolean, reason?:string, n:number}}} */
    function run(a) {
      a = a || {};
      var kind = String(a.type || '');
      var n = Math.max(1, Math.min(1000, parseInt(a.n, 10) || 1));
      var el = null;
      function out(ok, reason) {
        return { ok: ok, reason: reason, report: { type: kind, selector: String(a.s || '').slice(0, 500), label: el ? label(el) : '', ok: ok, reason: ok ? undefined : reason, n: n } };
      }
      if (KINDS.indexOf(kind) === -1) return out(false, 'unknown_action');

      // scrolling the page needs no element
      if (kind === 'scroll' && !a.s) {
        var se = doc.scrollingElement || doc.documentElement;
        se.scrollBy({ left: clamp(a.dx), top: clamp(a.dy), behavior: 'instant' });
        el = null;
        return out(true);
      }

      // a key with no addressed element goes to the focused one
      el = a.s || kind !== 'key' ? find(Object.assign({}, a, { type: kind })) : (doc.activeElement && doc.activeElement !== doc.body ? doc.activeElement : null);
      if (!el) return out(false, 'not_found');
      if (inDeskUi(el)) return out(false, 'desk_ui');
      if (!visible(el)) return out(false, 'hidden');
      if (el.disabled) return out(false, 'disabled');
      if (kind !== 'scroll' && isBlocked(el)) return out(false, 'private_field');

      try {
        switch (kind) {
          case 'scroll':
            el.scrollBy({ left: clamp(a.dx), top: clamp(a.dy), behavior: 'instant' });
            return out(true);

          case 'focus':
            el.focus();
            return out(true);

          case 'click':
          case 'dblclick': {
            if (el.tagName === 'SELECT') { el.focus(); return out(true); }     // a dropdown is chosen with the select action
            if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            if (el.focus && /^(input|textarea|button|a)$/i.test(el.tagName) || el.isContentEditable) el.focus();
            fire(el, 'pointerdown'); fire(el, 'mousedown'); fire(el, 'pointerup'); fire(el, 'mouseup');
            el.click();
            if (kind === 'dblclick') { fire(el, 'dblclick'); }
            return out(true);
          }

          case 'type': {
            var text = String(a.v == null ? '' : a.v).slice(0, MAX_TEXT);
            if (!isTextField(el)) return out(false, 'not_a_text_field');
            if (text) insert(el, text);
            return out(true);
          }

          case 'select': {
            if (el.tagName !== 'SELECT') return out(false, 'not_a_dropdown');
            var has = Array.prototype.some.call(el.options, function (o) { return o.value === String(a.v); });
            if (!has) return out(false, 'no_such_choice');
            el.value = String(a.v);
            el.dispatchEvent(new win.Event('input', { bubbles: true }));
            el.dispatchEvent(new win.Event('change', { bubbles: true }));
            return out(true);
          }

          case 'key': {
            var key = String(a.k || '');
            if (KEYS.indexOf(key) === -1) return out(false, 'key_not_allowed');
            var down = new win.KeyboardEvent('keydown', { key: key, bubbles: true, cancelable: true, shiftKey: !!a.sh });
            var notCancelled = el.dispatchEvent(down);
            if (notCancelled) {
              if (key === 'Backspace' && isTextField(el)) remove(el, false);
              else if (key === 'Delete' && isTextField(el)) remove(el, true);
              else if (key === 'Enter') {
                if (el.tagName === 'TEXTAREA' || el.isContentEditable) insert(el, '\n');
                else if (el.tagName === 'INPUT' && el.form) { if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit(); }
                else if (/^(button|a)$/i.test(el.tagName)) el.click();
              } else if (key === 'Tab') {
                var list = tabbable(); var i = list.indexOf(el);
                var next = list[(i + (a.sh ? -1 : 1) + list.length) % list.length];
                if (next) next.focus();
              }
            }
            el.dispatchEvent(new win.KeyboardEvent('keyup', { key: key, bubbles: true, cancelable: true, shiftKey: !!a.sh }));
            return out(true);
          }
        }
      } catch (e) {
        return out(false, 'error');
      }
      return out(false, 'unknown_action');
    }

    function clamp(v) { v = Number(v) || 0; return Math.max(-2000, Math.min(2000, v)); }

    return { run: run, label: label, isTextField: isTextField };
  }

  // ── what goes back to CoreX: batched, counted, never a value ───────────────────

  /**
   * Collects report lines and sends them in batches. Consecutive keystrokes / scrolls on the same element are merged into one line with a
   * count, so the audit reads "typed into Suburb (12 keys)" rather than twelve rows.
   */
  function createReporter(send, flushMs) {
    var buf = [];
    var timer = null;
    var MERGE = { type: 1, key: 1, scroll: 1 };
    function flush() {
      timer = null;
      if (!buf.length) return;
      var batch = buf; buf = [];
      send(batch);
    }
    return {
      add: function (r) {
        var last = buf[buf.length - 1];
        if (last && MERGE[r.type] && last.type === r.type && last.selector === r.selector && last.ok === r.ok && last.reason === r.reason) last.n += r.n;
        else buf.push(Object.assign({}, r));
        if (buf.length >= 40) { clearTimeout(timer); flush(); return; }
        if (!timer) timer = setTimeout(flush, flushMs || 1500);
      },
      flush: function () { clearTimeout(timer); flush(); },
    };
  }

  /** The agent-side idle clock: onIdle fires once if touch() is not called for `seconds`. */
  function createIdleTimer(seconds, onIdle) {
    var t = null;
    function arm() { clearTimeout(t); t = setTimeout(onIdle, seconds * 1000); }
    return { touch: arm, start: arm, stop: function () { clearTimeout(t); t = null; } };
  }

  var api = { KINDS: KINDS, KEYS: KEYS, create: create, createReporter: createReporter, createIdleTimer: createIdleTimer };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.CoreXDeskExec = api;
})(typeof window !== 'undefined' ? window : (typeof self !== 'undefined' ? self : this));
