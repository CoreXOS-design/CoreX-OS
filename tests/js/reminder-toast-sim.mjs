// AT-178 — Event-reminder global popup toast simulation.
//
// The repo has no live-browser JS runner (see redaction-draw-sim.mjs); this is the
// closest headless proof the stack supports. It extracts the ACTUAL reminderToast()
// Alpine factory body from the shipped blade (so it can never drift from the shipped
// code), mocks the due-reminders endpoint, and drives poll()/dismiss()/snooze() to
// prove:
//   1. a due reminder renders as a toast (populates `toasts`), and
//   2. click-through targets the EVENT deep link (view_url = /command-center/calendar/{id}), and
//   3. dismiss → POST .../read, snooze → POST .../snooze (self-scoped actions).
// It also asserts the component is @include'd in BOTH layout shells and that a
// NON-calendar page (a property page) extends the shell that carries it — i.e. the
// toast reaches the agent while they are loading a property, which is the whole point.
//
// Run:  node tests/js/reminder-toast-sim.mjs   (exit 0 = pass, 1 = fail)

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');

let failures = 0;
const ok = (cond, msg) => { if (cond) { console.log('  ✓ ' + msg); } else { console.error('  ✗ ' + msg); failures++; } };

// ── 1. Extract the real reminderToast() factory from the shipped blade ──
const blade = read('resources/views/components/reminder-toast.blade.php');
const scriptMatch = blade.match(/window\.reminderToast\s*=\s*function\s*\(\)\s*\{[\s\S]*?\n};/);
if (!scriptMatch) { console.error('could not extract reminderToast() factory from blade'); process.exit(1); }

// Substitute the Blade-interpolated config with test stand-ins (the only {{ }} in the script).
let src = scriptMatch[0]
    .replace("'{{ route('v1.command-center.reminders.due') }}'", "'/api/v1/command-center/reminders/due'")
    .replace("'{{ url('/api/v1/command-center/reminders') }}/__ID__'", "'/api/v1/command-center/reminders/__ID__'")
    .replace(/\{\{[^}]*\}\}/g, '60');

// ── 2. Sandbox: stub the browser globals the factory touches ──
const posts = [];
const fetchImpl = async (url, opts = {}) => {
    if ((opts.method || 'GET').toUpperCase() === 'POST') { posts.push(url); return { ok: true, json: async () => ({ success: true }) }; }
    // GET due feed → one due reminder for a NON-calendar context.
    return { ok: true, json: async () => ({ reminders: [{
        id: 7, event_id: 42, title: 'Client viewing — 12 Beach Rd',
        when_h: 'Mon, 06 Jul 14:00', lead_label: 'in 1 hour', starts_label: 'in 1 hour',
        property: '12 Beach Road, Ballito', occurrence_date: null,
        view_url: '/corex/command-center/calendar/42',
    }] }) };
};

const sandbox = {
    window: {
        reminderToast: null,
        CoreX: { api: { fetch: async (url, opts) => (await fetchImpl(url, opts)).json() } },
        addEventListener: () => {},
        AudioContext: undefined, webkitAudioContext: undefined,
    },
    document: { hidden: false, addEventListener: () => {}, querySelector: () => ({ content: 'csrf-test' }) },
    fetch: fetchImpl,
    setInterval: () => 0,
    console,
};

// Define the factory in the sandbox.
const define = new Function('window', 'document', 'fetch', 'setInterval', 'console', src + '\nreturn window.reminderToast;');
const factory = define(sandbox.window, sandbox.document, sandbox.fetch, sandbox.setInterval, sandbox.console);
ok(typeof factory === 'function', 'reminderToast() factory extracted + evaluated from shipped blade');

// ── 3. Drive it ──
const t = factory();

await t.poll();
ok(t.toasts.length === 1, 'a due reminder renders as a toast (poll populated toasts)');
ok(t.toasts[0].event_id === 42, 'the toast carries the event id');
ok(t.toasts[0].view_url === '/corex/command-center/calendar/42',
    'click-through view_url deep-links to the EVENT (not a generic page)');

// Dismiss → self-scoped mark-read POST + removed from view.
t.dismiss(t.toasts[0]);
await new Promise(r => setTimeout(r, 0));
ok(t.toasts.length === 0, 'dismiss removes the toast');
ok(posts.includes('/api/v1/command-center/reminders/7/read'), 'dismiss POSTs to the read endpoint');

// Snooze → snooze POST.
await t.poll();                      // re-surface the reminder
t.snooze(t.toasts[0]);
await new Promise(r => setTimeout(r, 0));
ok(posts.includes('/api/v1/command-center/reminders/7/snooze'), 'snooze POSTs to the snooze endpoint');

// ── 4. Prove it reaches NON-calendar pages: wired into both shells + property page extends one ──
const corex = read('resources/views/layouts/corex.blade.php');
const corexApp = read('resources/views/layouts/corex-app.blade.php');
ok(corex.includes("@include('components.reminder-toast')"), 'layouts/corex.blade.php includes the reminder toast');
ok(corexApp.includes("@include('components.reminder-toast')"), 'layouts/corex-app.blade.php includes the reminder toast');

const propertyShow = read('resources/views/corex/properties/show.blade.php');
ok(/@extends\(['"]layouts\.corex['"]\)/.test(propertyShow),
    'a property page (non-calendar) extends layouts.corex → the toast renders there too');

console.log(failures === 0 ? '\nPASS — reminder toast headless proof' : `\nFAIL — ${failures} assertion(s)`);
process.exit(failures === 0 ? 0 : 1);
