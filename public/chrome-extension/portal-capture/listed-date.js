/**
 * CoreX — listed-date reader for portal search tiles (AT-454, Road to Sold).
 *
 * Turns the text of ONE search-result tile into 'YYYY-MM-DD' — the day the advert went live — or
 * null. It only ever reads text that is introduced by "Listed" / "Listing date" / "Date listed",
 * so a price, a size or a street number can never be mistaken for a date. Anything it does not
 * recognise returns null, and the server then falls back to the day CoreX first saw the advert.
 *
 * Understood: "Listed 17 July 2026", "Listing date: 17 Jul 2026", "Listed on 2026-07-17",
 * "Listed today", "Listed yesterday", "Listed 3 days/weeks/months ago".
 * A future date is rejected (null). Pure function: no DOM, no network — tested in
 * tests/listed-date.test.cjs.
 */
(function (root) {
  'use strict';

  var MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };
  var LEAD = '(?:listed|listing\\s+date|date\\s+listed)\\s*(?:on|:|-)?\\s*';

  function iso(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }

  function fromText(text, now) {
    try {
      var t = String(text || '').replace(/\s+/g, ' ');
      var today = startOfDay(now || new Date());
      var m, d;

      m = t.match(new RegExp(LEAD + '(\\d{4})-(\\d{2})-(\\d{2})', 'i'));
      if (m) { d = new Date(+m[1], +m[2] - 1, +m[3]); return check(d, today, +m[1], +m[2] - 1, +m[3]); }

      m = t.match(new RegExp(LEAD + '(\\d{1,2})\\s+([A-Za-z]{3,9})\\.?,?\\s+(\\d{4})', 'i'));
      if (m) {
        var mon = MONTHS[m[2].slice(0, 3).toLowerCase()];
        if (mon === undefined) return null;
        d = new Date(+m[3], mon, +m[1]);
        return check(d, today, +m[3], mon, +m[1]);
      }

      m = t.match(new RegExp('listed\\s+(today|yesterday)', 'i'));
      if (m) { d = new Date(today); if (m[1].toLowerCase() === 'yesterday') d.setDate(d.getDate() - 1); return iso(d); }

      m = t.match(/listed\s+(\d{1,3})\s+(day|week|month)s?\s+ago/i);
      if (m) {
        d = new Date(today);
        var n = +m[1], unit = m[2].toLowerCase();
        if (unit === 'day') d.setDate(d.getDate() - n);
        else if (unit === 'week') d.setDate(d.getDate() - 7 * n);
        else d.setMonth(d.getMonth() - n);
        return iso(d);
      }
    } catch (e) { /* fall through */ }
    return null;
  }

  // Reject impossible calendar dates (31 Feb rolls over in Date) and anything in the future.
  function check(d, today, y, mo, day) {
    if (d.getFullYear() !== y || d.getMonth() !== mo || d.getDate() !== day) return null;
    if (d.getTime() > today.getTime()) return null;
    if (y < 2000) return null;
    return iso(d);
  }

  root.CoreXListedDate = { fromText: fromText };
})(typeof self !== 'undefined' ? self : globalThis);
