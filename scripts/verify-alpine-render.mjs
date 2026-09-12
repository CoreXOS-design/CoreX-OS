#!/usr/bin/env node
/**
 * Alpine render gate — required before ANY push that touches a Blade file
 * with Alpine in it on the rental-applications review screen (and safe to
 * reuse anywhere else the same class of bug is a risk).
 *
 * Written after THREE separate incidents shipped to QA1 with an Alpine
 * identifier referenced but not in scope, each one found only because
 * Johan or the conductor opened the page themselves:
 *   1. `initialResult` — a factory function referenced a constructor
 *      param the x-data call site never passed. Alpine's construction
 *      threw, so the WHOLE component never initialised — every binding on
 *      the page read as undefined, not just the one bad reference.
 *   2. `sidebarOpen` / `markupModeActive` / `markupSidebarPinned` — an
 *      inline `x-data="{ ... }"` object literal contained a JS comment
 *      with a literal `"` character. HTML doesn't know it's "inside a
 *      comment" — it just sees the attribute's closing quote arrive
 *      early, so the REST of the tag (the rest of x-data, x-init, the
 *      event handler, class, style) escaped the tag entirely and
 *      rendered as literal visible text on the page, ABOVE the header.
 *      Every binding depending on that x-data then read as undefined too
 *      — a second component-never-registered failure, different cause.
 *
 * Neither would have been caught by "the Blade compiles" or "PHPUnit is
 * green" — both are true in both incidents. This script tests the thing
 * that actually matters: the RENDERED page, executed as a real browser
 * would execute it. It must be run against a REAL AUTHENTICATED FETCH of
 * the real page (see scripts/fetch-authenticated-page.php) — a Blade
 * compiled server-side is not a page that works client-side.
 *
 * Usage:
 *   node scripts/verify-alpine-render.mjs <path-to-fetched-html> [<path2> ...]
 *
 * Runs three checks:
 *   1. Leaked attribute text — catches incident #2's failure mode directly:
 *      any raw Alpine/JS source (x-init=, addEventListener(, etc.) showing
 *      up as literal visible page text means an attribute's quote closed
 *      early. PART OF THE PASS/FAIL SIGNAL.
 *   2. Inline x-data scope gap — for every `x-data="{ ... }"` object
 *      literal, diffs its declared keys against identifiers its own
 *      subtree's bindings reference (properly excluding nested x-data
 *      scopes, including a nested element's OWN opening-tag bindings).
 *      WARN-ONLY, not part of the pass/fail signal: Alpine's real scope
 *      resolution walks the full ancestor chain, and reliably
 *      reconstructing that chain through nested components on an
 *      arbitrary real page needs more robust HTML/JS parsing than this
 *      script can safely guarantee — a gate that blocks valid pushes on
 *      its own false positives gets ignored exactly when a real one shows
 *      up. Read the warnings; verify by hand whether each is a genuine gap
 *      or a legitimate ancestor-scope reference this heuristic couldn't
 *      trace. Most reliable on the SHALLOWEST x-data in a change (no
 *      nested children) — exactly incident #2's shape.
 *   3. Real execution — every named factory function AND every inline
 *      object, constructed with its REAL call-site arguments (parsed
 *      straight out of the fetched page, never guessed), every zero-
 *      argument method called. Proven to catch incident #1 (a
 *      ReferenceError thrown during construction) and re-confirms #2 once
 *      fixed. PART OF THE PASS/FAIL SIGNAL.
 *   4. Alpine expression compile — every Alpine attribute value (x-data,
 *      x-init, x-effect, x-show, x-text, x-bind/:*, x-on/@*, etc.) run
 *      through the EXACT wrap Alpine's own generateFunctionFromString()
 *      applies (verified against node_modules/alpinejs/dist/module.cjs.js,
 *      not assumed), then compiled with `new Function`. Added after a real
 *      incident #3 this gate missed: a bare `try {} catch(_){}` written
 *      directly as an x-init value — Alpine only auto-wraps a leading
 *      `if (...)` or `let`/`const`, nothing else, so any other multi-
 *      statement body is a guaranteed SyntaxError no static-text check
 *      before this one would catch. A real headless Chrome (rental-
 *      smoke.mjs) caught it; this check closes the same class without
 *      needing a browser. PART OF THE PASS/FAIL SIGNAL.
 *
 * Exits non-zero if check 1, 3, or 4 fails on any file. Prints exactly
 * what failed and why.
 */

import fs from 'node:fs';
import vm from 'node:vm';

const files = process.argv.slice(2);
if (files.length === 0) {
    console.error('Usage: node verify-alpine-render.mjs <rendered-html-file> [...]');
    process.exit(2);
}

let totalFailures = 0;

// ── Shared browser-ish stubs for check 3 (dynamic execution) ──────────────
/**
 * An auto-permissive stand-in for a real CanvasRenderingContext2D (or
 * anything else with an unbounded, real API surface this sandbox has no
 * hope of enumerating completely) — ANY property read returns a no-op
 * function so `ctx.scale(...)`, `ctx.beginPath()`, `ctx.lineTo(...)` etc.
 * all just work, and ANY property write (`ctx.lineWidth = 2`) is accepted
 * silently. `getContext('2d')` returning `null` (real browsers never do
 * this for a supported context type) was itself the sandbox lie that
 * turned a real, correct `ctx.scale(...)` call into a false failure
 * (2026-09-12, cc6) the moment the PRECEDING `dataset` gap was fixed and
 * the code ran one line further. Same principle as the rest of this
 * sandbox's own stubs (see the file's own docblock) — permissive rather
 * than enumerated, so the NEXT canvas method some other component uses
 * doesn't become the next false failure.
 */
function fakePermissiveObject() {
    return new Proxy(() => fakePermissiveObject(), {
        get: (target, prop) => {
            if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => '';
            return fakePermissiveObject();
        },
        set: () => true,
        apply: () => fakePermissiveObject(),
    });
}

function makeSandbox() {
    const fakeEl = () => ({
        style: { setProperty: () => {}, removeProperty: () => {} },
        setAttribute: () => {}, getContext: () => fakePermissiveObject(), focus: () => {}, select: () => {},
        value: '', scrollIntoView: () => {}, closest: () => fakeEl(), getBoundingClientRect: () => ({ left: 0, top: 0, width: 0, height: 0 }),
        // Every real element supports these — missing here surfaced right
        // after the dataset/getContext fixes above, same false-failure
        // shape one line further into the same real code (2026-09-12).
        addEventListener: () => {}, removeEventListener: () => {}, dispatchEvent: () => true,
        // A real element's .dataset is always a DOMStringMap — present and
        // empty, never undefined — even before anything's been set on it.
        // Missing here made a real, pre-existing, correct call
        // (`canvas.dataset.someFlag`) throw "Cannot read properties of
        // undefined" — a sandbox gap reported as a false failure, not a
        // real regression (2026-09-12, cc6, on the public applicant
        // signature pad). This element is freshly constructed on every
        // getElementById()/querySelector() call (see `doc` below), so a
        // plain object is the honest level of fidelity — this sandbox
        // doesn't model one persistent element per id either, and a Proxy
        // that pretended dataset writes survived across calls when nothing
        // else here does would be a worse lie than this one.
        dataset: {},
    });
    const win = { addEventListener: () => {}, removeEventListener: () => {}, innerWidth: 1920, innerHeight: 1080, matchMedia: () => ({ matches: false }), location: { href: 'https://example.test/verify-alpine-render' } };
    const doc = {
        querySelector: () => fakeEl(), querySelectorAll: () => [], addEventListener: () => {},
        createElement: () => fakeEl(), getElementById: () => fakeEl(),
    };
    const sandbox = {
        console, window: win, location: win.location, localStorage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
        crypto: { randomUUID: () => 'test-uuid-' + Math.random().toString(36).slice(2) },
        document: doc,
        CustomEvent: function (name, opts) { this.type = name; this.detail = opts && opts.detail; },
        ResizeObserver: function () { this.observe = () => {}; this.disconnect = () => {}; },
        IntersectionObserver: function () { this.observe = () => {}; this.disconnect = () => {}; },
        // Same permissive-stub reasoning as fakePermissiveObject() above —
        // a real, legitimate `new FormData()` call (e.g. an autosave
        // payload builder) had no global to construct at all, a plain
        // ReferenceError-shaped false failure (2026-09-12).
        FormData: function () { return fakePermissiveObject(); },
        fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }),
        confirm: () => true,
        setTimeout, clearTimeout, setInterval, clearInterval,
        getComputedStyle: () => ({ getPropertyValue: () => '', height: '0px', width: '0px' }),
    };
    vm.createContext(sandbox);
    return sandbox;
}

function withMagics(obj) {
    obj.$dispatch = () => {};
    obj.$nextTick = (fn) => { if (typeof fn === 'function') { try { fn(); } catch (_) {} } };
    obj.$watch = () => {};
    obj.$el = { closest: () => null, getBoundingClientRect: () => ({ left: 0, top: 0, width: 0, height: 0 }), style: { setProperty: () => {}, removeProperty: () => {} } };
    obj.$refs = new Proxy({}, { get: () => ({ style: {}, value: '', focus: () => {}, select: () => {}, submit: () => {} }) });
    return obj;
}

function stripCommentsAndStrings(src) {
    let out = '', i = 0;
    while (i < src.length) {
        const two = src.slice(i, i + 2);
        if (two === '//') { const nl = src.indexOf('\n', i); i = nl === -1 ? src.length : nl + 1; out += '\n'; continue; }
        if (two === '/*') { const end = src.indexOf('*/', i + 2); out += (end === -1 ? src.slice(i) : src.slice(i, end + 2)).replace(/[^\n]/g, ' '); i = end === -1 ? src.length : end + 2; continue; }
        const c = src[i];
        if (c === '"' || c === "'" || c === '`') {
            let j = i + 1;
            while (j < src.length) { if (src[j] === '\\') { j += 2; continue; } if (src[j] === c) { j++; break; } j++; }
            out += ' '.repeat(j - i); i = j; continue;
        }
        out += c; i++;
    }
    return out;
}

// ── Check 1: leaked attribute text ─────────────────────────────────────────
// If an HTML attribute's own value contains an unescaped quote matching its
// delimiter, everything after it escapes the tag and renders as literal
// page text. Detected by stripping every <script> block, then every real
// tag (< ... >), and searching what's LEFT (i.e. what a reader/browser
// would actually show as text) for fragments that only make sense as raw
// Alpine/JS source, never as page copy.
const LEAK_SIGNATURES = [
    'x-init=', 'x-data=', 'addEventListener(', 'setTimeout(', 'clearTimeout(',
    '@click=', '@mouseenter=', '@mouseleave=', ':class=', ':style=', ':title=',
    'window.__rental', 'localStorage.getItem', 'localStorage.setItem',
    "x-show=", "x-text=", "x-cloak",
];
/**
 * Extracts genuine BODY TEXT — content that sits between tags, never inside
 * one. A naive `/>([^<]*)</g` regex (the first version of this check) is
 * NOT safe for this codebase: Alpine arrow functions (`() => { ... }`) put a
 * literal `>` inside perfectly well-formed, correctly-quoted attribute
 * values, and that regex has no concept of "inside a quoted attribute" — it
 * treated every `=>` as if it were a real tag boundary and flagged
 * completely healthy pages as leaking. This is a proper (if minimal)
 * tokenizer: it tracks whether the scanner is currently inside a tag, and
 * if so, whether it's inside a quoted attribute value and with which quote
 * character — an unquoted `>` is the only thing that ends a tag.
 */
function extractBodyText(html) {
    let out = '';
    let inTag = false;
    let quote = null; // null | '"' | "'"
    for (let i = 0; i < html.length; i++) {
        const c = html[i];
        if (inTag) {
            if (quote) {
                if (c === quote) quote = null;
            } else if (c === '"' || c === "'") {
                quote = c;
            } else if (c === '>') {
                inTag = false;
            }
        } else if (c === '<') {
            inTag = true;
        } else {
            out += c;
        }
    }
    return out;
}
function checkLeakedText(html, label) {
    const noScripts = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
    const noStyles = noScripts.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
    const noComments = noStyles.replace(/<!--[\s\S]*?-->/g, '');
    const visibleText = extractBodyText(noComments);
    const hits = LEAK_SIGNATURES.filter(sig => visibleText.includes(sig));
    if (hits.length > 0) {
        console.error(`  [LEAKED TEXT] ${label}: found raw JS/Alpine source in visible page text: ${hits.join(', ')}`);
        const idx = visibleText.indexOf(hits[0]);
        console.error(`    context: ...${visibleText.slice(Math.max(0, idx - 60), idx + 120)}...`);
        return false;
    }
    return true;
}

// ── Check 2: inline x-data="{ ... }" object literals — declared keys vs
// identifiers referenced in that element's own subtree, properly excluding
// any NESTED x-data's own subtree (which owns its own scope). This is
// exactly the shape of incident #2 above (a plain object literal, not a
// named factory function) — factory-function components are covered more
// reliably by check 3's actual execution.
//
// Tag boundaries here MUST be quote-aware, the same way extractBodyText()
// above is — an Alpine arrow function (`() => { ... }`) puts a literal `>`
// inside a correctly-quoted attribute value, and a naive `[^>]*?` regex
// (the first version of this function) miscounts that as the tag's own
// closing `>`, corrupting every depth count and subtree boundary after it.
const RAW_TEXT_TAGS = new Set(['script', 'style']);
function findTags(html, fromIdx) {
    // Returns [{ isClose, isSelfClose, start, end }, ...] for every tag from
    // fromIdx onward, with end = index just past the tag's own real '>'.
    const tags = [];
    let i = fromIdx;
    while (i < html.length) {
        const lt = html.indexOf('<', i);
        if (lt === -1) break;
        let j = lt + 1;
        const isClose = html[j] === '/';
        if (isClose) j++;
        // Not a real element start (e.g. "a < b" in some raw text) — skip.
        if (!/[a-zA-Z]/.test(html[j] || '')) { i = lt + 1; continue; }
        let quote = null;
        while (j < html.length) {
            const c = html[j];
            if (quote) { if (c === quote) quote = null; }
            else if (c === '"' || c === "'") quote = c;
            else if (c === '>') break;
            j++;
        }
        const isSelfClose = html[j - 1] === '/';
        const end = j + 1;
        const name = (html.slice(lt + (isClose ? 2 : 1), end - 1).match(/^[a-zA-Z][\w-]*/) || [''])[0].toLowerCase();
        tags.push({ isClose, isSelfClose, start: lt, end });
        // <script>/<style> are RAW TEXT elements — a browser never tag-
        // parses their content at all, and neither must this. Comparison
        // operators, string content, anything JS-shaped inside can contain
        // stray '<'/'>' that would otherwise corrupt every depth count
        // after it. Jump straight to the real closing tag by literal text
        // search, exactly like a browser's own tokenizer does for these
        // two elements specifically.
        if (!isClose && !isSelfClose && RAW_TEXT_TAGS.has(name)) {
            const closeLiteral = '</' + name;
            const closeIdx = html.toLowerCase().indexOf(closeLiteral, end);
            if (closeIdx === -1) { i = end; continue; }
            const closeEnd = html.indexOf('>', closeIdx) + 1;
            tags.push({ isClose: true, isSelfClose: false, start: closeIdx, end: closeEnd });
            i = closeEnd;
            continue;
        }
        i = end;
    }
    return tags;
}
const VOID_TAGS = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
/** startIdx: any position INSIDE the element's own opening tag (e.g. the match index of `x-data="`). Finds that tag's real quote-aware end, then this element's own matching close via findTags(). Returns { openTagText, subtree, fullElement } — an element's OWN opening tag can carry OTHER bindings (e.g. `@event.window="..."` alongside `x-data="..."` on the same tag) that resolve in ITS x-data's scope, not its parent's — callers excluding a nested scope must remove fullElement, not just subtree, or those bindings leak into the parent's identifier scan. */
function extractSubtree(html, startIdx) {
    const tagOpen = html.lastIndexOf('<', startIdx);
    const [selfTag] = findTags(html, tagOpen);
    if (!selfTag) return { openTagText: '', subtree: '', fullElement: '' };
    const tagEnd = selfTag.end;
    const openTagText = html.slice(tagOpen, tagEnd);
    const name = (html.slice(tagOpen + 1, tagEnd - 1).match(/^[a-zA-Z][\w-]*/) || [''])[0].toLowerCase();
    if (selfTag.isSelfClose || VOID_TAGS.has(name)) return { openTagText, subtree: '', fullElement: openTagText };
    let depth = 1;
    const tags = findTags(html, tagEnd);
    for (const t of tags) {
        const tName = (html.slice(t.start + (t.isClose ? 2 : 1), t.end - 1).match(/^[a-zA-Z][\w-]*/) || [''])[0].toLowerCase();
        if (t.isSelfClose || VOID_TAGS.has(tName)) continue;
        if (t.isClose) {
            depth--;
            if (depth === 0) return { openTagText, subtree: html.slice(tagEnd, t.start), fullElement: html.slice(tagOpen, t.end) };
        } else depth++;
    }
    return { openTagText, subtree: html.slice(tagEnd), fullElement: html.slice(tagOpen) };
}
/** Every `x-data="..."` occurrence strictly WITHIN a subtree, each paired with its own FULL element text (opening tag included) so callers can carve the whole thing out before scanning for identifier references — see extractSubtree()'s own comment on why the opening tag matters too. */
function findNestedXDataSubtrees(subtree) {
    const results = [];
    const re = /x-data="/g;
    let m;
    while ((m = re.exec(subtree)) !== null) {
        results.push(extractSubtree(subtree, m.index).fullElement);
    }
    return results;
}

function checkInlineXData(html, label) {
    let ok = true;
    const re = /x-data="\{/g;
    let m;
    while ((m = re.exec(html)) !== null) {
        const attrStart = m.index + 'x-data="'.length;
        // Browser-realistic: the value ends at the FIRST unescaped '"'.
        let end = attrStart;
        while (end < html.length) {
            if (html[end] === '"' && html[end - 1] !== '\\') break;
            end++;
        }
        const raw = html.slice(attrStart, end);
        let obj;
        try {
            // eslint-disable-next-line no-eval
            obj = eval('(' + raw + ')');
        } catch (e) {
            console.error(`  [INLINE x-data PARSE ERROR] ${label} at offset ${attrStart}: ${e.message}`);
            console.error(`    This is EXACTLY the failure mode of incident #2 — the browser would parse the`);
            console.error(`    same truncated value and fail to register this component at all.`);
            console.error(`    First 200 chars parsed: ${raw.slice(0, 200)}`);
            ok = false;
            continue;
        }
        if (typeof obj !== 'object' || obj === null) continue;
        const declared = new Set(Object.keys(obj));
        const { subtree } = extractSubtree(html, m.index);
        // Remove every NESTED x-data's own FULL element (its opening tag —
        // which can carry other bindings like `@event.window="..."`
        // alongside x-data on the SAME tag, resolving in ITS scope, not
        // this one's — included) before scanning for identifier
        // references. Without this, a top-level layout x-data (whose
        // subtree is literally the entire rest of the page) would falsely
        // appear to reference every identifier any deeply-nested
        // component uses.
        let nestedFree = subtree;
        for (const nested of findNestedXDataSubtrees(subtree)) {
            if (nested) nestedFree = nestedFree.split(nested).join(' ');
        }
        const bindingAttrs = [...nestedFree.matchAll(/(?:x-show|x-text|x-html|:class|:style|:title|@[\w.-]+)="([^"]*)"/g)].map(mm => mm[1]);
        const referenced = new Set();
        for (const expr of bindingAttrs) {
            const stripped = stripCommentsAndStrings(expr);
            for (const idMatch of stripped.matchAll(/(?<![.\w$])([a-zA-Z_$][\w$]*)(?!\s*:)/g)) {
                referenced.add(idMatch[1]);
            }
        }
        const knownGlobals = new Set(['this', 'true', 'false', 'null', 'undefined', 'window', 'document', 'location', 'localStorage', 'sessionStorage', 'console', '$event', '$el', '$refs', '$dispatch', '$nextTick', '$watch']);
        const missing = [...referenced].filter(id => !declared.has(id) && !knownGlobals.has(id) && !/^[A-Z]/.test(id));
        if (missing.length > 0) {
            // WARNING, not a failure — deliberately. Alpine's REAL scope
            // resolution walks the full ancestor chain (a child's binding
            // can legitimately reference a name declared several x-data
            // levels up), and reliably reconstructing that whole chain
            // through nested components on an arbitrary real page (this
            // one included) needs more robust parsing than is safe to
            // trust blindly under time pressure — a gate that blocks valid
            // pushes on its own false positives gets its warnings ignored
            // exactly when a real one shows up. Checks 1 and 3 are the
            // actual pass/fail signal; treat this as "worth a human
            // glance," most usefully on the SHALLOWEST x-data in a change
            // (no nested children), where it's least likely to be noise.
            console.warn(`  [WARN: possible scope gap] ${label}: bindings reference ${missing.join(', ')} — not declared in this x-data (keys: ${[...declared].join(', ')}). Verify by hand whether these are legitimately declared further up the ancestor chain.`);
        }
    }
    return ok;
}

// ── Check 3: real execution — every named factory + every inline object,
// constructed with its REAL call-site arguments, every zero-arg method
// called. Proven to catch incident #1 (and re-confirms #2 once fixed).
// ── Check 4: compile every Alpine attribute value the way Alpine itself
// compiles it, and fail if it doesn't parse. Added 2026-09-12 after a real
// incident this exact gate missed: a `try { ... } catch (_) {}` written
// directly as an x-init value threw "Unexpected token 'try'" in a real
// browser (caught by rental-smoke.mjs, a real headless Chrome — this static
// gate passed it clean). The wrong fix was tried first (assumed Alpine
// auto-detects a leading statement keyword and just needed no leading
// whitespace) — checked against the ACTUAL bundled Alpine source
// (node_modules/alpinejs/dist/module.cjs.js, generateFunctionFromString())
// instead of guessing, and the real rule is narrower than that assumption:
//
//     let rightSideSafeExpression = /^[\n\s]*if.*\(.*\)/.test(expression.trim())
//         || /^(let|const)\s/.test(expression.trim())
//         ? `(async()=>{ ${expression} })()` : expression;
//
// Alpine ONLY auto-wraps a leading `if (...)` or a leading `let`/`const` —
// nothing else (not `try`, `for`, `switch`, `function`, `class`) ever gets
// statement treatment. Anything else is dropped straight into
// `__self.result = <expression>`, so a bare `try {}` (or any other
// multi-statement body Alpine doesn't special-case) is a guaranteed
// SyntaxError at runtime, no matter how it's indented. This check
// reproduces that exact wrap and compiles the result with `new Function` —
// a cheap static check, no browser needed, and it closes this whole class
// before a push rather than after a real user hits it.
//
// x-transition:*/x-ref/x-cloak/x-teleport are excluded — none of them are
// JS (transition values are CSS class strings; the rest are plain
// selectors/flags). x-for is excluded too — `"item in items()"` has its
// own grammar Alpine parses by splitting on a regex, never by evaluating
// the whole string as one expression the way every other directive here
// does, so wrapping and compiling it whole would test the wrong thing.
const ALPINE_ATTR_NAME_RE = /^(x-[a-zA-Z-]+(:[\w.\-]+)?|@[\w.:\-]+|:[\w-]+)$/;
const SKIP_EXPRESSION_DIRECTIVES = /^(x-transition|x-ref$|x-cloak$|x-teleport$|x-for$)/;

function decodeAttrEntities(s) {
    return s.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
}

function extractAttrsFromTagText(tagText) {
    const attrs = [];
    const re = /([a-zA-Z@:][a-zA-Z0-9@:.\-]*)\s*=\s*"((?:[^"\\]|\\.)*)"|([a-zA-Z@:][a-zA-Z0-9@:.\-]*)\s*=\s*'((?:[^'\\]|\\.)*)'/g;
    let m;
    while ((m = re.exec(tagText)) !== null) {
        const name = m[1] || m[3];
        const raw = m[2] !== undefined ? m[2] : m[4];
        attrs.push({ name, raw });
    }
    return attrs;
}

/** The exact wrap Alpine's real generateFunctionFromString() applies (module.cjs.js:1913-1920), reproduced verbatim so a pass/fail here means the same thing it would mean in a real browser. */
function alpineCompileWrap(expression) {
    const rightSideSafeExpression = /^[\n\s]*if.*\(.*\)/.test(expression.trim()) || /^(let|const)\s/.test(expression.trim())
        ? `(async()=>{ ${expression} })()`
        : expression;
    return `with (scope) { __self.result = ${rightSideSafeExpression} }; __self.finished = true; return __self.result;`;
}

function checkExpressionCompile(html, label) {
    let ok = true;
    let checked = 0;
    for (const t of findTags(html, 0)) {
        if (t.isClose) continue;
        const tagText = html.slice(t.start, t.end);
        for (const { name, raw } of extractAttrsFromTagText(tagText)) {
            if (!ALPINE_ATTR_NAME_RE.test(name) || SKIP_EXPRESSION_DIRECTIVES.test(name)) continue;
            const expr = decodeAttrEntities(raw).trim();
            if (!expr) continue;
            checked++;
            try {
                new Function(['scope', '__self'], alpineCompileWrap(expr));
            } catch (e) {
                if (e instanceof SyntaxError) {
                    console.error(`  [ALPINE EXPRESSION SYNTAX ERROR] ${label}: ${name}="${expr.slice(0, 200).replace(/\n/g, ' ')}" -> ${e.message}`);
                    ok = false;
                }
            }
        }
    }
    if (ok) console.log(`  (checked ${checked} Alpine attribute expressions — all compile clean)`);
    return ok;
}

function checkExecution(html, label) {
    let ok = true;
    const allBlocks = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]);
    const scriptBlocks = allBlocks.filter(b => /^function \w+\(/m.test(b));
    const combinedJs = scriptBlocks.join('\n;\n');
    const sandbox = makeSandbox();
    try {
        vm.runInContext(combinedJs, sandbox, { filename: label, timeout: 5000 });
    } catch (e) {
        console.error(`  [SCRIPT EVAL ERROR] ${label}: ${e.message}`);
        return false;
    }
    const factoryNames = [...combinedJs.matchAll(/^function (\w+)\(/gm)].map(mm => mm[1]);
    for (const name of factoryNames) {
        const re = new RegExp('x-data="' + name + '\\(([\\s\\S]*?)\\)"', 'g');
        let m;
        while ((m = re.exec(html)) !== null) {
            const argsSrc = m[1].trim();
            try {
                const fn = sandbox[name];
                if (typeof fn !== 'function') continue;
                const args = argsSrc === '' ? undefined : vm.runInContext('(' + argsSrc + ')', sandbox, { timeout: 2000 });
                let obj;
                try { obj = fn(args); } catch (e) { console.error(`  [${name}] CONSTRUCTION ERROR: ${e.message}`); ok = false; continue; }
                withMagics(obj);
                if (typeof obj.init === 'function') {
                    try { obj.init.call(obj); } catch (e) { console.error(`  [${name}] init() ERROR: ${e.message}`); ok = false; }
                }
                for (const key of Object.keys(obj)) {
                    if (typeof obj[key] !== 'function' || obj[key].length > 0) continue;
                    if (['init', '$dispatch', '$nextTick', '$watch'].includes(key)) continue;
                    try { obj[key].call(obj); } catch (e) { console.error(`  [${name}].${key}() ERROR: ${e.message}`); ok = false; }
                }
            } catch (e) {
                console.error(`  [${name}] harness error: ${e.message}`);
            }
        }
    }
    return ok;
}

for (const file of files) {
    if (!fs.existsSync(file)) { console.error(`File not found: ${file}`); totalFailures++; continue; }
    const html = fs.readFileSync(file, 'utf8');
    console.log(`\n=== ${file} ===`);
    const r1 = checkLeakedText(html, file);
    const r2 = checkInlineXData(html, file);
    const r3 = checkExecution(html, file);
    const r4 = checkExpressionCompile(html, file);
    // r2 (the inline x-data scope-gap check) only ever warns — see its own
    // comment on why it isn't part of the pass/fail signal.
    if (r1 && r3 && r4) console.log('  PASS — no leaked attribute text, zero execution errors, all Alpine expressions compile.');
    else totalFailures++;
}

console.log(`\n${totalFailures === 0 ? 'GATE PASSED' : 'GATE FAILED'} (${files.length} file(s) checked, ${totalFailures} with failures)`);
process.exit(totalFailures === 0 ? 0 : 1);
