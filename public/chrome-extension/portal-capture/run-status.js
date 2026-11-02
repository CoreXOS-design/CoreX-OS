/**
 * CoreX - which status belongs to the run THIS popup started (3.16.1).
 *
 * The background keeps ONE capture record in memory, and a finished run stays in it ("complete") until the next run replaces it. A popup that has just
 * clicked "Capture" polls that record; until the background has taken the new run (the popup first sends the queued batches), the record is still the
 * LAST run's - so the popup read "complete" from the previous search and showed ITS numbers (the P24 run) on the Private Property tab, and the new run
 * looked like it never started. Every run now carries an id the popup made; a status that has another id is somebody else's and is ignored.
 *
 *   decide(status, myRunId) -> 'ignore' | 'capturing' | 'complete' | 'error'
 * myRunId is null when the popup was opened onto a run that is already going (it follows whatever runs).
 */
(function (root) {
  function decide(status, myRunId) {
    if (!status) return 'ignore';
    if (myRunId && status.runId !== myRunId) return 'ignore';
    if (status.active) return 'capturing';
    if (status.complete) return 'complete';
    if (status.error) return 'error';
    return 'ignore';
  }
  root.CorexRunStatus = { decide };
})(typeof self !== 'undefined' ? self : globalThis);
