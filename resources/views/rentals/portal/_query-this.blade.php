{{-- section 22.7 - "Query this": the owner asks their agent a question about what was authorised or arranged on their behalf (a note, not a decision; nothing stops).
     Needs the Alpine `queryForm` / `sendQuery()` of the portal shell. $queries = the Alpine expression holding the questions asked so far. --}}
<template x-for="(q, qi) in ({{ $queries }} || [])" :key="qi"><p class="muted" style="margin:0 0 4px;" x-text="'You asked ' + fmtDay(q.at) + ': ' + q.note"></p></template>
<label style="margin-top:8px;">Query this</label>
<textarea rows="2" maxlength="1000" x-model="queryForm.note" placeholder="Ask your agent a question about this repair" data-query-note></textarea>
<p class="error" x-show="queryForm.error" x-text="queryForm.error"></p>
<p class="muted" x-show="queryForm.sent" data-query-sent>Sent &mdash; your agent has been told.</p>
<button class="btn btn-outline" :disabled="!!(queryForm.busy || queryForm.note.trim().length < 3)" @click="sendQuery()" data-query-send>Send my question</button>
