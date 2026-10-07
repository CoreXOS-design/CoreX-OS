// The range buttons for the portal engagement chart and the rule for slicing
// the daily series by a button — pure functions, no DOM, no Chart.js, so the
// rule is unit-testable (tests/js/engagement-ranges.test.mjs) and so the agent
// Intelligence tab and the seller live link read ONE definition. Attached to
// window.NexusCharts by nexus-charts.js.

// Order is the display order. `short` is the label the "P24 Views (…)" stat
// card uses. No range is longer than 6 months: the series only holds 180 days.
export const ENGAGEMENT_RANGES = [
    { key: '7',   label: '7 days',        short: '7d' },
    { key: 'mtd', label: 'Month to date', short: 'month to date' },
    { key: '30',  label: '30D',           short: '30d' },
    { key: '90',  label: '90D',           short: '90d' },
    { key: 'all', label: '6M',            short: '6mo' },
];

export const ENGAGEMENT_DEFAULT_RANGE = '30';

export function isEngagementRange(key) {
    return ENGAGEMENT_RANGES.some((r) => r.key === key);
}

// `series` is oldest → newest, one row per day ({date: 'YYYY-MM-DD', ...}).
// `monthStart` is the first day of the current month as the SERVER sees it
// ('YYYY-MM-DD'), so "Month to date" follows the server's calendar, not the
// viewer's clock. An unknown key falls back to the default range.
export function engagementWindow(series, key, monthStart) {
    const rows = series || [];
    if (key === 'all') return rows;
    if (key === 'mtd') return rows.filter((r) => r.date >= monthStart);
    let n = parseInt(key, 10);
    if (!(n > 0)) n = parseInt(ENGAGEMENT_DEFAULT_RANGE, 10);
    return rows.slice(-n);
}
