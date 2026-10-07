// Run: node --test tests/js/engagement-ranges.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    ENGAGEMENT_RANGES, ENGAGEMENT_DEFAULT_RANGE, engagementWindow, isEngagementRange,
} from '../../resources/js/engagement-ranges.js';

// 180 days, 2026-04-10 .. 2026-10-06 (yesterday = 6 Oct), like the server builds it.
function makeSeries() {
    const rows = [];
    const d = new Date(Date.UTC(2026, 3, 10));
    for (let i = 0; i < 180; i++) {
        rows.push({ date: d.toISOString().slice(0, 10), views: i, leads: 0 });
        d.setUTCDate(d.getUTCDate() + 1);
    }
    return rows;
}
const series = makeSeries();
const MONTH_START = '2026-10-01';

test('buttons are exactly: 7 days, Month to date, 30D, 90D, 6M — in that order', () => {
    assert.deepEqual(ENGAGEMENT_RANGES.map((r) => r.label), ['7 days', 'Month to date', '30D', '90D', '6M']);
});

test('default range is still 30D', () => {
    assert.equal(ENGAGEMENT_DEFAULT_RANGE, '30');
    assert.ok(isEngagementRange(ENGAGEMENT_DEFAULT_RANGE));
});

test('every button gives a different window (the original bug: the graph never changed)', () => {
    const sizes = ENGAGEMENT_RANGES.map((r) => engagementWindow(series, r.key, MONTH_START).length);
    assert.deepEqual(sizes, [7, 6, 30, 90, 180]);
    assert.equal(new Set(sizes).size, sizes.length);
});

test('7 days / 30 / 90 end on the most recent day', () => {
    for (const k of ['7', '30', '90']) {
        const w = engagementWindow(series, k, MONTH_START);
        assert.equal(w[w.length - 1].date, '2026-10-06');
    }
    assert.equal(engagementWindow(series, '7', MONTH_START)[0].date, '2026-09-30');
});

test('Month to date starts on the first of the month, server calendar', () => {
    const w = engagementWindow(series, 'mtd', MONTH_START);
    assert.equal(w[0].date, '2026-10-01');
    assert.equal(w[w.length - 1].date, '2026-10-06');
});

test('Month to date is empty (not an error) on the 1st, when the series ends yesterday', () => {
    assert.deepEqual(engagementWindow(series, 'mtd', '2026-10-07'), []);
});

test('6M returns the whole series; unknown key falls back to the default', () => {
    assert.equal(engagementWindow(series, 'all', MONTH_START).length, 180);
    assert.equal(engagementWindow(series, 'bogus', MONTH_START).length, 30);
    assert.deepEqual(engagementWindow(null, '30', MONTH_START), []);
});

test('a series shorter than the range returns what exists', () => {
    assert.equal(engagementWindow(series.slice(-3), '90', MONTH_START).length, 3);
});
