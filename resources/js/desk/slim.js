// CoreX Desk - slimming what the agent's tab sends (spec .ai/specs/corex-desk.md section 4e). Everything dropped here is something the MIRROR cannot
// show anyway: the mirror is a static picture of the page (no scripts run in it), so the page's own script plumbing - Alpine's x-data="{...}" (some are 6 KB
// each), @click / :class bindings, onclick handlers, data-* and aria-* attributes no stylesheet selects on - is dead weight on the wire.
//
//   · an attribute is dropped only when it cannot change how the page LOOKS: the names above, and a data-/aria- name is KEPT whenever any stylesheet
//     of the page mentions it in a selector ([data-state=open], [aria-expanded=true], [x-cloak]) or the console itself uses it (data-tour, data-desk-*);
//   · an attribute change that sets the value the mirror already has (hover class flicker, a script re-setting the same value) is not sent again;
//   · pictures are marked loading="lazy", so the mirror fetches only the ones in its window.
//
// Nothing is guessed about the page's content: text, structure, classes, styles, form values all pass through untouched.

const FULL = 2;
const INCREMENTAL = 3;
const MUTATION = 0;

const SCRIPT_ATTR = /^(x-|@|:|wire:|on(click|dblclick|change|submit|input|keyup|keydown|keypress|load|error|mouse[a-z]+|pointer[a-z]+|focus|blur|touch[a-z]+)$)/;
const ALWAYS_KEEP = /^(data-tour|data-desk|aria-label$)/;   // (the console names what support pointed at from aria-label, so that stays)

let refs = null;     // attribute names some stylesheet selects on
let refsAt = 0;
const sent = new Map();   // node id -> Map(attribute name -> the value the mirror has)

export function resetSlim() { sent.clear(); refs = null; }

function selectorRefs() {
    if (refs && Date.now() - refsAt < 15000) return refs;
    const set = new Set();
    const visit = (rules) => {
        for (const r of rules) {
            if (r.selectorText) { for (const m of r.selectorText.matchAll(/\[\s*([a-zA-Z_@:][-\w:@.]*)/g)) set.add(m[1].toLowerCase()); }
            if (r.cssRules) visit(r.cssRules);
        }
    };
    for (const sheet of Array.from(document.styleSheets)) {
        try { visit(sheet.cssRules); } catch (e) { /* a sheet from another site cannot be read - its attribute rules, if any, are not known */ }
    }
    refs = set;
    refsAt = Date.now();
    return set;
}

/** Can this attribute change how the page looks (or does the console use it)? */
function keep(name) {
    const n = name.toLowerCase();
    if (ALWAYS_KEEP.test(n)) return true;
    if (SCRIPT_ATTR.test(n)) return selectorRefs().has(n);
    if (n.startsWith('data-') || n.startsWith('aria-')) return selectorRefs().has(n);
    return true;
}

function slimAttrs(id, attrs, isImg, svg) {
    if (!attrs) return attrs;
    const out = isImg ? { loading: 'lazy' } : {};   // (first, so the picture knows before its src is set)
    let seen = id !== undefined ? sent.get(id) : null;
    if (id !== undefined && !seen) { seen = new Map(); sent.set(id, seen); }
    for (const [k, v] of Object.entries(attrs)) {
        if (!svg && !keep(k)) continue;
        out[k] = v;
        if (seen && (typeof v === 'string' || v === null)) seen.set(k, v);
    }
    return out;
}

function slimNode(n) {
    if (!n || typeof n !== 'object') return n;
    if (n.type === 2 && n.attributes) {
        const isImg = String(n.tagName).toLowerCase() === 'img';
        n.attributes = slimAttrs(n.id, n.attributes, isImg, !!n.isSVG);
    }
    if (n.childNodes) for (const c of n.childNodes) slimNode(c);
    return n;
}

/** Returns the event to send (slimmed in place), or null when nothing is left in it to send. */
export function slimEvent(e) {
    if (!e) return null;
    if (e.type === FULL) {
        sent.clear();
        refs = null;
        slimNode(e.data.node);
        return e;
    }
    if (e.type !== INCREMENTAL || !e.data || e.data.source !== MUTATION) return e;
    const d = e.data;
    if (d.adds) for (const a of d.adds) slimNode(a.node);
    if (d.removes) for (const r of d.removes) sent.delete(r.id);
    if (d.attributes && d.attributes.length) {
        const kept = [];
        for (const a of d.attributes) {
            const seen = sent.get(a.id) || (sent.set(a.id, new Map()), sent.get(a.id));
            const next = {};
            let any = false;
            for (const [k, v] of Object.entries(a.attributes || {})) {
                if (!keep(k)) continue;
                if ((typeof v === 'string' || v === null) && seen.has(k) && seen.get(k) === v) continue;   // the mirror already has exactly this
                if (typeof v === 'string' || v === null) seen.set(k, v);
                next[k] = v;
                any = true;
            }
            if (any) kept.push({ id: a.id, attributes: next });
        }
        d.attributes = kept;
    }
    // A batch that had only dropped attributes in it has nothing left to say.
    if (!(d.adds && d.adds.length) && !(d.removes && d.removes.length) && !(d.texts && d.texts.length) && !(d.attributes && d.attributes.length)) return null;
    return e;
}
