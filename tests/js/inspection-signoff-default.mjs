// AT-463 — the signing drawer on the inspection panel is closed until "Ready to sign".
// Loads the ACTUAL x-data object out of the shipped Blade (no copy of the logic here) and drives it with
// a fake sessionStorage: draft stays closed, awaiting-on-load opens, pressing Ready to sign opens,
// the agent's own choice is remembered per inspection, and blocked storage never breaks the rule.
//
// Run:  node tests/js/inspection-signoff-default.mjs      (exit 0 = pass, 1 = fail)

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const blade = fs.readFileSync(path.join(root, 'resources/views/corex/properties/partials/rental-inspection-recording.blade.php'), 'utf8');

let fails = 0;
const ok = (cond, msg) => { console.log((cond ? 'PASS ' : 'FAIL ') + msg); if (!cond) fails++; };

const m = blade.match(/<div x-data="\{\s*(signOpen: false[\s\S]*?)\}"\s*x-effect="syncSign\(/);
if (!m) { console.log('FAIL could not find the sign-off x-data in the Blade'); process.exit(1); }

function make(store) {
  globalThis.sessionStorage = store;
  const obj = new Function('return {' + m[1] + '}')();
  return obj;
}
const fakeStore = () => { const d = {}; return { getItem: k => (k in d ? d[k] : null), setItem: (k, v) => { d[k] = String(v); }, d }; };
const blocked = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } };

// 1. a draft inspection opens CLOSED
let s = fakeStore(); let a = make(s);
a.syncSign({ id: 160, status: 'draft' });
ok(a.signOpen === false, 'draft: drawer closed');
a.syncSign({ id: 160, status: 'in_progress' });
ok(a.signOpen === false, 'in progress: still closed');

// 2. pressing Ready to sign (status flips in-session) opens it, and remembers that
a.syncSign({ id: 160, status: 'awaiting_signature' });
ok(a.signOpen === true, 'Ready to sign pressed: drawer opens');
ok(s.d['corex.signoff.160'] === '1', 'the open state is remembered for this inspection');

// 3. the agent closes it; a reload (fresh component, same session) keeps it closed even though awaiting signature
a.setSignOpen(false);
let b = make(s);
b.syncSign({ id: 160, status: 'awaiting_signature' });
ok(b.signOpen === false, 'reload after the agent closed it: stays closed');

// 4. an agent's open choice on a draft is remembered across a reload too
s = fakeStore(); a = make(s);
a.syncSign({ id: 7, status: 'draft' }); a.setSignOpen(true);
b = make(s); b.syncSign({ id: 7, status: 'draft' });
ok(b.signOpen === true, 'agent opened it on a draft: reload keeps it open');

// 5. an inspection already awaiting signature, never touched by the agent, loads OPEN
s = fakeStore(); a = make(s);
a.syncSign({ id: 9, status: 'awaiting_signature' });
ok(a.signOpen === true, 'already awaiting signature on load: open');

// 6. choices are per inspection
a.setSignOpen(false);
a.syncSign({ id: 10, status: 'draft' });
ok(a.signOpen === false && s.d['corex.signoff.9'] === '0', 'a different inspection does not inherit the choice');

// 7. Ready to sign opens it even if the agent had closed it earlier on the draft
s = fakeStore(); a = make(s);
a.syncSign({ id: 11, status: 'draft' }); a.setSignOpen(false);
a.syncSign({ id: 11, status: 'awaiting_signature' });
ok(a.signOpen === true, 'closed earlier, then Ready to sign: opens');

// 8. storage blocked: nothing throws, rule still holds
a = make(blocked);
let threw = false;
try { a.syncSign({ id: 1, status: 'draft' }); a.setSignOpen(true); a.syncSign({ id: 1, status: 'awaiting_signature' }); } catch (e) { threw = true; }
ok(!threw, 'blocked storage never throws');
a = make(blocked); a.syncSign({ id: 2, status: 'draft' });
ok(a.signOpen === false, 'blocked storage: draft still closed');

// 9. no inspection yet: no change
a = make(fakeStore()); a.syncSign(null);
ok(a.signOpen === false, 'no inspection: closed');

process.exit(fails ? 1 : 0);
