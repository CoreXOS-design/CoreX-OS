/**
 * CoreX — minimal, dependency-free mock DOM for testing the portal-capture
 * content scripts (content-cmainfo.js today; written generically enough to
 * extend to the other content-*.js sources later — same spirit as the
 * jsdom-based DOM-sample harness used for wa-capture/content.js).
 *
 * Supports exactly the DOM surface these content scripts actually use:
 * querySelector(All) with simple "tag.class1.class2[, tag2...]" selectors,
 * nextElementSibling, closest(tag), classList.contains, textContent,
 * click()/addEventListener, and a getComputedStyle() shim reading .style /
 * a mock computed color. No real HTML parsing — pages are built
 * programmatically (see cmainfo-fixtures.js).
 */
'use strict';

class MockElement {
  constructor(tagName) {
    this.tagName = String(tagName || 'div').toUpperCase();
    this.children = [];
    this.parentNode = null;
    this._classes = new Set();
    this._text = null;
    this.style = {};
    this._color = 'rgb(51, 122, 183)'; // default non-red, non-opted-out
    this._listeners = {};
    this._attrs = {};
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  // Real DOM reflects the `id` IDL property onto the `id` content attribute
  // both ways (element.id = 'x' <-> getAttribute('id') / getElementById) —
  // content scripts commonly set `.id =` directly (content-cmainfo.js's own
  // injectButton() does), so the mock must reflect it the same way or
  // getElementById() silently never finds an element set up that way.
  get id() {
    return this._attrs.id || '';
  }

  set id(v) {
    this._attrs.id = v;
  }

  get classList() {
    const self = this;
    return {
      contains: (c) => self._classes.has(c),
      add: (c) => self._classes.add(c),
      remove: (c) => self._classes.delete(c),
    };
  }

  addClass(str) {
    String(str).split(/\s+/).filter(Boolean).forEach((c) => this._classes.add(c));
    return this;
  }

  get nextElementSibling() {
    if (!this.parentNode) return null;
    const idx = this.parentNode.children.indexOf(this);
    return this.parentNode.children[idx + 1] || null;
  }

  closest(tag) {
    const target = String(tag).toLowerCase();
    let node = this;
    while (node) {
      if (node.tagName && node.tagName.toLowerCase() === target) return node;
      node = node.parentNode;
    }
    return null;
  }

  get textContent() {
    if (this._text != null) return this._text;
    return this.children.map((c) => c.textContent).join('');
  }

  set textContent(v) {
    this._text = v;
    this.children = [];
  }

  setAttribute(k, v) {
    this._attrs[k] = v;
    if (k === 'id') this.id = v;
  }

  getAttribute(k) {
    return this._attrs[k];
  }

  addEventListener(type, fn) {
    (this._listeners[type] = this._listeners[type] || []).push(fn);
  }

  click() {
    (this._listeners.click || []).forEach((fn) => fn({ type: 'click' }));
  }

  dispatchEvent(ev) {
    (this._listeners[ev.type] || []).forEach((fn) => fn(ev));
    return true;
  }

  remove() {
    if (!this.parentNode) return;
    const idx = this.parentNode.children.indexOf(this);
    if (idx >= 0) this.parentNode.children.splice(idx, 1);
    this.parentNode = null;
  }

  querySelectorAll(selector) {
    const out = [];
    walk(this, (e) => { if (matchesSelector(e, selector)) out.push(e); });
    return out;
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
  }
}

function walk(root, visit) {
  for (const child of root.children) {
    visit(child);
    walk(child, visit);
  }
}

// Simple selector grammar: "tag.class1.class2" with tag optional, joined by commas.
function matchesSimple(elm, simple) {
  simple = simple.trim();
  if (!simple) return false;
  const m = simple.match(/^([a-zA-Z0-9]*)((?:\.[a-zA-Z0-9_-]+)*)$/);
  if (!m) return false;
  const tag = m[1];
  const classes = (m[2].match(/\.[a-zA-Z0-9_-]+/g) || []).map((c) => c.slice(1));
  if (tag && elm.tagName.toLowerCase() !== tag.toLowerCase()) return false;
  for (const c of classes) { if (!elm.classList.contains(c)) return false; }
  return true;
}

function matchesSelector(elm, selector) {
  return selector.split(',').some((part) => matchesSimple(elm, part));
}

function el(tag, opts) {
  const e = new MockElement(tag);
  opts = opts || {};
  if (opts.class) e.addClass(opts.class);
  if (opts.id) e.setAttribute('id', opts.id);
  if (opts.display !== undefined) e.style.display = opts.display;
  if (opts.color) e._color = opts.color;
  if (opts.text !== undefined) e.textContent = opts.text;
  return e;
}

function row(label, value) {
  const tr = el('tr');
  tr.appendChild(el('td', { text: label }));
  tr.appendChild(el('td', { text: value == null ? '' : value }));
  return tr;
}

/** Find a field row by its label text (as rendered) and overwrite its value cell — used to mutate a panel in place between two captures within one script instance. */
function setFieldValue(panel, label, value) {
  const target = String(label).trim().toLowerCase();
  const rows = panel.querySelectorAll('tr');
  for (const tr of rows) {
    const cells = tr.children;
    if (cells.length < 2) continue;
    if (String(cells[0].textContent).trim().toLowerCase() === target) {
      cells[1].textContent = value == null ? '' : value;
      return true;
    }
  }
  return false;
}

function buildPanel(fields, panelClass) {
  const panel = el('div', { class: panelClass, display: 'block' });
  const table = el('table');
  fields.forEach(([label, value]) => table.appendChild(row(label, value)));
  panel.appendChild(table);
  return panel;
}

/**
 * Builds a mock cmainfo page: Property Information + Sale Information
 * accordion sections, both already expanded/populated (so
 * ensureSectionExpanded() resolves via its synchronous fast path — no
 * polling/timers needed in the harness).
 */
function buildCmaInfoDocument(propertyFields, saleFields) {
  const documentEl = el('html');
  const head = el('head');
  const body = el('body');
  documentEl.appendChild(head);
  documentEl.appendChild(body);

  const propPanel = buildPanel(propertyFields, 'property-info panel');
  const propBtn = el('button', { class: 'accordion', text: 'Property Information' });
  body.appendChild(propBtn);
  body.appendChild(propPanel);

  const salePanel = buildPanel(saleFields, 'sale-info panel');
  const saleBtn = el('button', { class: 'accordion', text: 'Sale Information' });
  body.appendChild(saleBtn);
  body.appendChild(salePanel);

  const doc = {
    head,
    body,
    querySelectorAll: (sel) => documentEl.querySelectorAll(sel),
    querySelector: (sel) => documentEl.querySelector(sel),
    getElementById: (id) => {
      let found = null;
      walk(documentEl, (e) => { if (!found && e._attrs && e._attrs.id === id) found = e; });
      return found;
    },
    createElement: (tag) => el(tag),
    _propPanel: propPanel,
    _salePanel: salePanel,
  };
  return doc;
}

module.exports = { MockElement, el, row, setFieldValue, buildPanel, buildCmaInfoDocument, walk };
