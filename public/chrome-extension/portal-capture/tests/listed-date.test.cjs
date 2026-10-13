/**
 * CoreX — listed-date tile reader (AT-454). Plain node, no jsdom:
 *   node public/chrome-extension/portal-capture/tests/listed-date.test.cjs
 */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
// The file is a plain browser script (it sets CoreXListedDate on `self`); run it the way a browser would.
const sandbox = {};
sandbox.self = sandbox;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'listed-date.js'), 'utf8'), sandbox);
const fromText = sandbox.CoreXListedDate.fromText;

const NOW = new Date(2026, 9, 13, 14, 30); // 13 Oct 2026

const cases = [
  ['Listed 17 July 2026',                         '2026-07-17'],
  ['Listing date: 17 Jul 2026',                   '2026-07-17'],
  ['Date listed - 3 March 2025',                  '2025-03-03'],
  ['Listed on 2026-07-17',                        '2026-07-17'],
  ['Listed today',                                '2026-10-13'],
  ['Listed yesterday',                            '2026-10-12'],
  ['Listed 3 days ago',                           '2026-10-10'],
  ['Listed 2 weeks ago',                          '2026-09-29'],
  ['Listed 1 month ago',                          '2026-09-13'],
  ['R 1 250 000 3 Bedroom House 17 July 2026',    null],   // no "listed" lead -> never guess
  ['Listed 31 Feb 2026',                          null],   // impossible date
  ['Listed 20 December 2026',                     null],   // future
  ['Listed 5 July 1999',                          null],   // implausibly old
  ['Listed soon',                                 null],
  ['',                                            null],
  [null,                                          null],
  ['Available from 17 July 2026',                 null],
];

for (const [text, want] of cases) {
  assert.strictEqual(fromText(text, NOW), want, JSON.stringify(text));
}
console.log('listed-date: ' + cases.length + ' cases OK');
