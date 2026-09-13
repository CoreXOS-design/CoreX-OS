# Native dialog sweep — confirm() / alert() / prompt() app-wide

**Date:** 2026-09-13. **Requested by:** conductor, after the Approve-path confirm() froze a real browser tab and the rental-applications Unlink control was found still carrying one after that fix. **Method:** static grep sweep of `resources/views/**/*.blade.php` and `resources/js/**/*.js` for bare `confirm(`, `alert(`, `prompt(` calls (word-boundary regex, SweetAlert2's `.isConfirmed`/`Swal.*` excluded, Blade/JS comment lines stripped on a best-effort basis). Find-only — nothing in this list has been modified except the two already reported separately (Approve/Decline, fixed previously; Unlink, fixed just now on view-readonly.blade.php).

**Why this matters, in one line:** every one of these blocks the tab's entire renderer until a human dismisses that specific dialog, and every one is invisible to this app's own click-through gate (`scripts/rental-click-through.mjs`) and to PHPUnit — a native dialog cannot be asserted on by either.

## Summary

| Category | Call sites | Files |
|---|---|---|
| `confirm()` gating what looks like a destructive/irreversible action (delete, archive, remove, decline, revoke, unlink, withdraw, deactivate, etc.) — **the urgent bucket** | 215 | 140 |
| `confirm()` gating something else (send, retry, publish, renew, navigate away, etc.) | 94 | 62 |
| `alert()` — informational, doesn't gate an action, but still a blocking native dialog | 174 | 53 |
| `prompt()` — text input via native dialog | 9 | 7 |

**Already fixed (not in the lists below):** `review.blade.php` Approve/Decline (two-stage in-page confirmation, this session, earlier); `view-readonly.blade.php` Unlink (two-stage in-page confirmation, this session, just now — `tenant-unlink-continue`/`tenant-unlink-confirm`).

**Known limitations of this sweep — read before triaging:** (1) regex-based, not an AST parse — a `confirm(` split across multiple lines, or built from a template string/variable rather than a literal, will be missed; (2) 'destructive' classification is a keyword match against the dialog's own message text, not a semantic read of what the code actually does — treat the split as a strong hint, not a verdict, and read the line before deciding; (3) comment-line stripping is a simple `{{--`/`//`/`* ` prefix check and may have let a small number of commented-out mentions through, or dropped a real one-liner that happened to start with `//` inside a `<script>` block; (4) does not cover PHP-side `confirm()`-equivalents (there are none in this codebase's stack) or any dialog logic inside `.vue`/`.tsx` files if any exist outside `resources/views`/`resources/js`.

---

### 1. CONFIRM — destructive-looking (urgent bucket) (215 call sites across 140 files)

**`resources/views/admin/agencies/create-edit.blade.php`**
- L740: `<form method="POST" action="{{ route('agencies.api-keys.revoke', [$agency, $key]) }}" onsubmit="return confirm('Revoke this key? The website will stop receiving data until you regenerate.');">`
- L745: `<form method="POST" action="{{ route('agencies.api-keys.destroy', [$agency, $key]) }}" onsubmit="return confirm('Delete this key? It is archived (soft-deleted) and recoverable by an admin.');">`
- L1093: `onclick="if(confirm('Delete branch &quot;{{ $branch->name }}&quot;? This cannot be undone.')) { document.getElementById('delete-branch-{{ $branch->id }}').submit(); }"`

**`resources/views/admin/agencies/index.blade.php`**
- L108: `onsubmit="return confirm('{{ $agency->is_active ? 'Disable' : 'Enable' }} agency &quot;{{ $agency->name }}&quot;? @if($agency->is_active)Users in this agency will not be able to sign in until it is re-enabled.@endif');"`
- L141: `if (!confirm('PERMANENTLY delete agency &quot;{{ $agency->name }}&quot;? This HARD-DELETES every user, branch, property, contact, deal, presentation and document in this agency. This cannot be undone.')) return false;`

**`resources/views/admin/assistants/show.blade.php`**
- L111: `onclick="return confirm('Unlink {{ $link->agent?->name }}? {{ $assistant?->name }} will immediately lose access to their records. This can be restored later.');">`
- L182: `onclick="return confirm('Move {{ $assistant?->name }} to a different agent? Their permissions will be reset to a copy of the new agent\'s.');">`
- L218: `onclick="return confirm('Revoke {{ $assistant?->name }}\'s assistant access? This can be undone.');">`

**`resources/views/admin/branch-assignments/index.blade.php`**
- L58: `onsubmit="return confirm('Delete this branch? This cannot be undone.');">`

**`resources/views/admin/company-settings/index.blade.php`**
- L651: `onsubmit="return confirm('Delete this branch? This cannot be undone.');">`

**`resources/views/admin/deal-distribution-rules/index.blade.php`**
- L160: `<form method="POST" action="{{ route('admin.settings.deal-distribution-rules.destroy', $rule) }}" onsubmit="return confirm('Remove this rule?');">`

**`resources/views/admin/demo-access/connection.blade.php`**
- L130: `onsubmit="return confirm('Revoke this connector?\n\nThe demo will immediately lose access to CoreX. Because the demo gate fails closed, NOBODY will be able to sign in to the demo until you issue a new token and paste it in.\n\nDo this if the token has leaked. Do not do it to “reset” anything.');">`
- L176: `onsubmit="return confirm('Issue a new token?\n\nThis REVOKES the current one immediately. The demo will stop working until you paste the new token into it.');"`
- L299: `onsubmit="return confirm('Revoke the website connector? Webinar registration on the CoreX website stops working immediately, and stays broken until a new token is issued and pasted in.');">`

**`resources/views/admin/demo-access/show.blade.php`**
- L64: `onsubmit="return confirm('Revoke access for {{ addslashes($grant->company_name) }}?\n\nThey will be locked out within {{ $cacheTtl }} seconds — not instantly. If they are mid-page right now, they may finish that page.');">`
- L77: `onsubmit="return confirm('Archive this grant?\n\nIt is hidden from the list but kept permanently as a record of who accepted which terms. Nothing is deleted.');">`

**`resources/views/admin/demo-access/tnc.blade.php`**
- L73: `onsubmit="return confirm('Publish a new version?\n\nEveryone currently in the demo will be asked to accept it before they can carry on. This cannot be undone — versions are permanent.');"`

**`resources/views/admin/deposit-trust-interest/index.blade.php`**
- L129: `onsubmit="return confirm('Delete this record ({{ $record->interest_date->format('d M Y') }})? It can be recovered by an admin.')">`

**`resources/views/admin/designations/index.blade.php`**
- L101: `onsubmit="return confirm('Delete this designation? This cannot be undone.');"`

**`resources/views/admin/ellie/reference-sources/index.blade.php`**
- L141: `<form action="{{ route('admin.ellie.reference-sources.destroy', $source) }}" method="POST" class="inline" x-data x-on:submit.prevent="if(confirm('Remove this reference source?')) $el.submit()">`

**`resources/views/admin/fault-reports/index.blade.php`**
- L21: `onsubmit="return confirm('Clear ALL fault reports? They will be soft-deleted and can be restored from the database.');">`

**`resources/views/admin/importer/preview.blade.php`**
- L16: `<form method="POST" action="{{ route('admin.importer.cancel', $run) }}" onsubmit="return confirm('Cancel this run?');">`

**`resources/views/admin/importer/review.blade.php`**
- L146: `onsubmit="return confirm('Revoke this portal? The agency will no longer be able to use the link.');">`

**`resources/views/admin/knowledge/category.blade.php`**
- L153: `<form action="{{ route('admin.knowledge.destroy', $doc->id) }}" method="POST" class="inline" x-data x-on:submit.prevent="if(confirm('Delete this document and all its chunks?')) $el.submit()">`

**`resources/views/admin/knowledge/index.blade.php`**
- L378: `<form action="{{ route('admin.knowledge.destroy', $doc->id) }}" method="POST" class="inline" x-data x-on:submit.prevent="if(confirm('Delete this document and all its chunks?')) $el.submit()">`

**`resources/views/admin/p24-suburbs.blade.php`**
- L261: `<form method="POST" action="{{ route('admin.p24-suburbs.destroy', $suburb) }}" class="inline" onsubmit="return confirm('Delete {{ $suburb->name }}?')">`

**`resources/views/admin/performance.blade.php`**
- L495: `onclick="return confirm('Revoke company TV code?')">`
- L545: `onclick="return confirm('Revoke this code?')">`

**`resources/views/admin/pp/agent-mapping.blade.php`**
- L233: `if (!confirm('Deactivate ' + this.userName + ' on Private Property? PP will refuse this if the agent still has active listings.')) return;`

**`resources/views/admin/pp/agents.blade.php`**
- L209: `if (!confirm('Deactivate PP profile ' + a.agent_id + ' (' + a.first_name + ' ' + a.last_name + ')? PP will refuse if this profile has active listings.')) return;`
- L237: `if (!confirm('Hard-delete listing #' + L.id + ' from CoreX? This is irreversible — the Property row is removed from the database (not soft-deleted) and PP is told to deactivate the listing.')) return;`

**`resources/views/admin/splitter/doc-types.blade.php`**
- L384: `if (!confirm('Delete \'' + label + '\'?')) return;`

**`resources/views/admin/system-updates/edit.blade.php`**
- L60: `onsubmit="return confirm('Unpublish this update? It becomes a draft and stops showing to everyone.');">`
- L79: `onsubmit="return confirm('Archive this update? It stops showing to users immediately and can be restored at any time.');">`

**`resources/views/admin/tv-messages/index.blade.php`**
- L286: `onsubmit="return confirm('Delete message?');">`

**`resources/views/admin/users/create-edit.blade.php`**
- L630: `onclick="if(confirm('Remove agent photo?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $user) }}';f.innerHTML='<input type=hidden name=_token value='+document.querySelector('meta[name=csrf-token]').getAttribute('content')+'><input name=field value=agent_photo><input type=hidden name=active_tab value=compliance>';document.body.appendChild(f);f.submit();}">Remove current photo</button>`
- L648: `onclick="if(confirm('Remove FFC certificate?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $user) }}';f.innerHTML=document.querySelector('meta[name=csrf-token]').content?'<input type=hidden name=_token value='+document.querySelector('meta[name=csrf-token]').getAttribute('content')+'><input name=field value=ffc_certificate><input type=hidden name=active_tab value=compliance>':'';;document.body.appendChild(f);f.submit();}">Remove</button>`

**`resources/views/admin/users/index.blade.php`**
- L386: `<form method="POST" action="{{ route('admin.users.remove-file', $u) }}" class="inline" onsubmit="return confirm('Remove agent photo?')">`
- L410: `<form method="POST" action="{{ route('admin.users.remove-file', $u) }}" class="inline" onsubmit="return confirm('Remove FFC certificate?')">`

**`resources/views/admin/webinars/show.blade.php`**
- L42: `onsubmit="return confirm('Archive this webinar? The registration link stops working immediately — nobody else can sign up or be given demo access. Everyone who already registered keeps theirs.');">`

**`resources/views/agency-setup/steps/branches.blade.php`**
- L49: `onsubmit="return confirm('Archive this branch? Agents assigned to it must be moved first.');">`

**`resources/views/agent/portal.blade.php`**
- L639: `<form method="POST" action="{{ route('agent.portal.articles.destroy', $article) }}" onsubmit="return confirm('Delete this article?');">`
- L1017: `async unlink() { if (!confirm('Unlink this WhatsApp device? Capture will stop.')) return; this.busy = true; try { this.apply(await this._post('{{ route('communications.wa-link.unlink') }}')); } catch (e) {} this.busy = false; },`

**`resources/views/bm/performance.blade.php`**
- L104: `onsubmit="return confirm('Revoke this code? TVs using it will stop working.')">`

**`resources/views/bm/tv-messages/index.blade.php`**
- L196: `onsubmit="return confirm('Delete message?');">`

**`resources/views/command-center/buyers/detail.blade.php`**
- L453: `onsubmit="return confirm('Archive this wishlist? It can be restored by an admin.');">`

**`resources/views/command-center/calendar/index.blade.php`**
- L2441: `onclick="return confirm('Regenerate the viewing pack from this appointment\'s current properties? The existing pack will be archived, not deleted.');">`

**`resources/views/command-center/settings/event-classes.blade.php`**
- L228: `onclick="if(confirm('Reset this class to global defaults?')) { document.getElementById('reset-{{ $cls }}').submit(); }"`

**`resources/views/command-center/settings/index.blade.php`**
- L100: `<button type="submit" class="text-xs px-2 py-1 rounded hover:bg-red-500/10" style="color:var(--ds-crimson);" onclick="return confirm('Remove this expectation?')">Remove</button>`

**`resources/views/command-center/tasks/index.blade.php`**
- L104: `onsubmit="return confirm('Archive all Done tasks? They can be restored from the Archived view.');"`
- L830: `if (!confirm('Delete this note?')) return;`

**`resources/views/command-center/viewing-packs/index.blade.php`**
- L90: `onsubmit="return confirm('Archive this viewing pack? You can recover it later.');">`

**`resources/views/command-center/viewing-packs/show.blade.php`**
- L68: `onsubmit="return confirm('Archive this viewing pack? You can recover it later.');">`
- L264: `@submit.prevent="confirm('Remove this property from the pack?') && vpAction($el)">`

**`resources/views/commercial-evaluations/index.blade.php`**
- L110: `<form method="POST" action="{{ route('commercial-evaluations.destroy', $eval) }}" class="inline" onsubmit="return confirm('Delete this evaluation?')">`

**`resources/views/commercial-evaluations/show.blade.php`**
- L197: `class="inline ml-3" onsubmit="return confirm('Remove financial year {{ $fin->financial_year }}? It can be recovered by an admin.')">`
- L337: `<form method="POST" action="{{ route('commercial-evaluations.units.destroy', [$evaluation, $unit]) }}" class="inline" onsubmit="return confirm('Remove this unit?')">`
- L453: `<form method="POST" action="{{ route('commercial-evaluations.assets.destroy', [$evaluation, $asset]) }}" class="inline" onsubmit="return confirm('Remove this asset?')">`
- L707: `<form method="POST" action="{{ route('commercial-evaluations.crops.destroy', [$evaluation, $crop]) }}" class="inline" onsubmit="return confirm('Remove this crop?')">`
- L1014: `<form method="POST" action="{{ route('commercial-evaluations.livestock.destroy', [$evaluation, $ls]) }}" class="inline" onsubmit="return confirm('Remove this livestock?')">`
- L1211: `<form method="POST" action="{{ route('commercial-evaluations.comparables.destroy', [$evaluation, $comp]) }}" class="inline" onsubmit="return confirm('Remove this comparable?')">`

**`resources/views/communications/wa-devices/index.blade.php`**
- L145: `<form method="POST" action="{{ route('communications.wa-devices.destroy', $d) }}" class="inline" onsubmit="return confirm('Revoke this device? The extension on it will stop capturing.');">`

**`resources/views/compliance/communication-archive/mailboxes/index.blade.php`**
- L36: `<form method="POST" action="{{ route('compliance.comm-mailboxes.reset-host-auth-lock') }}" class="inline" onsubmit="return confirm('Only clear this once you have confirmed the correct username and password are saved for every mailbox on {{ $host }}. Reset the login lock for {{ $host }}?');">`
- L229: `<form method="POST" action="{{ route('compliance.comm-mailboxes.destroy', $m) }}" class="inline ml-2" onsubmit="return confirm('Archive this mailbox?');">`

**`resources/views/compliance/document-types/index.blade.php`**
- L110: `<form method="POST" action="{{ route('compliance.document-types.archive', $type) }}" class="inline" onsubmit="return confirm('Archive this document type?')">`

**`resources/views/compliance/fica/compliance-review.blade.php`**
- L269: `<button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-crimson,#c41e3a); box-shadow:none;" onclick="return confirm('Are you sure?')">Reject</button>`

**`resources/views/compliance/fica/index.blade.php`**
- L307: `onsubmit="return confirm('Cancel this FICA request? The client link will be voided.')">@csrf`

**`resources/views/compliance/fica/partials/_linked-contact-documents.blade.php`**
- L25: `<form method="POST" action="{{ route('compliance.fica.linked-documents.unlink', [$submission, $ldoc]) }}" onsubmit="return confirm('Unlink this document from the FICA? The contact document itself is not deleted.');" style="display:inline;">`

**`resources/views/compliance/fica/show.blade.php`**
- L173: `<form method="POST" action="{{ route('compliance.fica.documents.remove', [$submission, $doc]) }}" onsubmit="return confirm('Remove this document? It will be archived — an admin can recover it.');" style="display:inline;">`
- L471: `<button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-crimson,#c41e3a); box-shadow:none;" onclick="return confirm('Are you sure you want to reject this FICA submission?')">`

**`resources/views/compliance/officer/index.blade.php`**
- L45: `<form method="POST" action="{{ route('compliance.officer.end', $currentOfficer) }}" onsubmit="return confirm('End this officer\'s appointment? This cannot be undone.');">`

**`resources/views/compliance/policy/edit.blade.php`**
- L46: `<form method="POST" action="{{ route('compliance.policy.section.delete', [$version, $section]) }}" onsubmit="return confirm('Remove this section?');">`

**`resources/views/components/tv-link.blade.php`**
- L32: `onclick="return confirm('Revoke this code? TVs using it will stop working.')">`

**`resources/views/corex/communications/access-inbox.blade.php`**
- L69: `if (decision === 'decline' && !confirm('Decline this access request?')) return;`

**`resources/views/corex/communications/comms-suspense.blade.php`**
- L96: `onsubmit="return confirm('Reject this email? It will not be filed to any deal.');">`

**`resources/views/corex/contacts/_communications-tab-body.blade.php`**
- L103: `if (!confirm('Revoke your access to this thread?')) return;`

**`resources/views/corex/contacts/_drive-row.blade.php`**
- L47: `onsubmit="return confirm('Delete {{ addslashes($doc->original_name) }}?');">`

**`resources/views/corex/contacts/_header-actions.blade.php`**
- L95: `onsubmit="return confirm('Permanently delete {{ addslashes($contact->full_name) }}?');"`

**`resources/views/corex/contacts/_linked-properties.blade.php`**
- L69: `onsubmit="return confirm('Unlink this property from {{ addslashes($contact->full_name) }}?')">`

**`resources/views/corex/contacts/_note-item.blade.php`**
- L19: `onsubmit="return confirm('Delete this note?');">`

**`resources/views/corex/contacts/index.blade.php`**
- L824: `onsubmit="return confirm('Delete {{ addslashes($contact->full_name) }}?');">`

**`resources/views/corex/contacts/partials/client-app-access.blade.php`**
- L125: `onclick="return confirm('Revoke all active client devices for this contact?')">`
- L136: `onclick="return confirm('Remove client app access? Activity logs are preserved.')">`

**`resources/views/corex/contacts/show.blade.php`**
- L789: `<form method="POST" action="{{ route('corex.contacts.representatives.unlink', [$contact, $rep]) }}" onsubmit="return confirm('Unlink {{ addslashes($rep->full_name) }} as a representative?');">`
- L924: `<form method="POST" action="{{ route('corex.contacts.representatives.unlink', [$entity, $contact]) }}" onsubmit="return confirm('Unlink from {{ addslashes($entity->full_name) }}?');">`
- L1117: `onclick="return confirm('Remove this captured property address?\n\nThis clears the address from {{ addslashes($contact->first_name ?: 'this contact') }} and turns OFF the address-only pitch. The contact\'s residential address and any property you already created from it are NOT affected.');"`
- L1353: `onsubmit="return confirm('Delete this testimonial?');">`
- L1626: `onsubmit="return confirm('Remove this match criteria?');"`

**`resources/views/corex/deeds-capture/_tva-capture.blade.php`**
- L157: `onsubmit="return confirm('Remove this TVA capture from the list? It will no longer show here, but nothing is permanently deleted.');"`

**`resources/views/corex/deeds-capture/index.blade.php`**
- L482: `onsubmit="return confirm('This will stop treating this deed as that property. It will get its own record instead — nothing is deleted.');">`
- L841: `onsubmit="return confirm('Remove this capture from the list? It will no longer show here, but nothing is permanently deleted.');">`
- L952: `onsubmit="return confirm('Confirm this is the same property? It stays exactly as it is, and this capture is removed from your queue.');">`

**`resources/views/corex/map/index.blade.php`**
- L4141: `if (!confirm('Delete "' + name + '"?')) return;`

**`resources/views/corex/market-intelligence/_comment-entry.blade.php`**
- L45: `@click="if (confirm('Remove this comment? This cannot be undone by other agents.')) removeComment({{ $tpId }}, {{ $comment->id }})"`

**`resources/views/corex/market-intelligence/reports/show.blade.php`**
- L157: `onsubmit="return confirm('Archive this report? It can be recovered from admin if needed.');">`

**`resources/views/corex/properties/_drive-row.blade.php`**
- L48: `onsubmit="return confirm('Delete this file?')">`

**`resources/views/corex/properties/ad-builder.blade.php`**
- L1731: `if (!confirm('Delete the custom design for "${type}"? It will use the Default design instead.')) return;`

**`resources/views/corex/properties/index.blade.php`**
- L946: `onsubmit="return confirm('Delete \'{{ addslashes($property->title) }}\'?')">`
- L1127: `onsubmit="return confirm('Delete \'{{ addslashes($property->title) }}\'?')">`

**`resources/views/corex/properties/partials/syndication-scripts.blade.php`**
- L408: `if (!confirm('Deactivate this listing on Private Property?')) return;`
- L700: `if (!confirm('Deactivate this listing on Property24?')) return;`

**`resources/views/corex/properties/show.blade.php`**
- L123: `onsubmit="return confirm('Break this link? The listing goes straight back into your prospecting list — nothing is deleted.');">`
- L777: `onsubmit="return confirm('Change this listing from {{ ucfirst($curType) }} to {{ ucfirst($otherType) }}?\n\nA new {{ ucfirst($otherType) }} draft opens with the matching details carried over. The current {{ ucfirst($curType) }} listing is archived and de-listed from the portals (recoverable by admin).')">`
- L788: `<form method="POST" action="{{ route('corex.properties.destroy', $property) }}" onsubmit="return confirm('Archive this property? It will be soft-deleted and recoverable by admin.')">`
- L1173: `cancel (nothing happens, same as the confirm() dialog this replaces). --}}`
- L1759: `onsubmit="return confirm('Change this listing from {{ ucfirst($property->listing_type) }} to {{ ucfirst($ctOther) }}?\n\nA new {{ ucfirst($ctOther) }} draft opens with the matching details carried over. The current {{ ucfirst($property->listing_type) }} listing is archived and de-listed from the portals (recoverable by admin).')">`
- L3204: `if (!confirm('Remove this showday?')) return;`
- L3414: `onsubmit="return confirm('Delete this property?')">`
- L4694: `if (!window.confirm('Delete this image?')) return;`
- L4720: `if (!window.confirm('Delete ${urls.length} photo${urls.length > 1 ? 's' : ''}? This permanently removes the file${urls.length > 1 ? 's' : ''} — it cannot be undone.')) return;`
- L5242: `onsubmit="return confirm('Delete this note?')">`
- L6702: `if (!confirm('Unlink ' + name + ' from this property?')) return;`
- L7319: `if (!confirm('Delete ${n} photo${n > 1 ? 's' : ''}? This permanently removes the photo file${n > 1 ? 's' : ''} — it cannot be undone.')) return;`
- L7439: `if (!confirm('Delete this image?')) return;`

**`resources/views/corex/properties/wizard.blade.php`**
- L116: `onclick="return confirm('Discard this draft? It will be archived.');"`

**`resources/views/corex/prospecting/property-take-requests.blade.php`**
- L60: `onsubmit="return confirm('Reject this request?');">`

**`resources/views/corex/rental-applications/decline-reason-templates/index.blade.php`**
- L114: `<form method="POST" action="{{ route('corex.settings.rental-applications.decline-reason-templates.archive', $template) }}" class="inline" onsubmit="return confirm('Archive this decline reason template?');">`

**`resources/views/corex/rental-applications/index.blade.php`**
- L417: `onsubmit="return confirm('Archive this rental application? It can be restored later.');" class="inline">`

**`resources/views/corex/rental-applications/partials/document-highlighter-script.blade.php`**
- L504: `if (!confirm('Remove this captured line? This also removes its mark from the document.')) return;`

**`resources/views/corex/rental-applications/show.blade.php`**
- L81: `onsubmit="return confirm('Archive this rental application? It can be recovered by an admin.');" class="inline">`

**`resources/views/corex/rental-applications/view-readonly.blade.php`**
- L72: `onsubmit="return confirm('Archive this rental application? It can be recovered by an admin.');" class="inline">`

**`resources/views/corex/settings.blade.php`**
- L843: `<button type="submit" class="text-xs px-2 py-1 rounded hover:bg-red-500/10" style="color:var(--ds-crimson);" onclick="return confirm('Remove this expectation?')">Remove</button>`
- L1447: `onsubmit="return confirm('Delete this designation?');" class="mt-2">`
- L1551: `onsubmit="return confirm('Delete this named field?');" class="mt-2">`
- L1867: `onsubmit="return confirm('Delete the selected sub-tags?');">`
- L1889: `<form method="POST" action="{{ route('corex.settings.contact-tags.destroy', $tag) }}" onsubmit="return confirm('Delete this sub-tag?');">`
- L2048: `onsubmit="return confirm('Delete this contact source?');">`
- L2168: `onsubmit="return confirm('Delete this contact label? Numbers/emails carrying it just lose the tag.');">`
- L2776: `@submit.prevent="if(confirm('Delete \'' + item.name + '\'?')) $el.submit()">`

**`resources/views/corex/settings/rental-applications.blade.php`**
- L609: `<form method="POST" action="{{ route('corex.settings.rental-applications.highlighters.archive', $highlighter) }}" style="display:inline;" onsubmit="return confirm('Archive this highlighter? Existing marks made with it keep their colour — it just won\'t be choosable for new marks.');">`

**`resources/views/deals-v2/index.blade.php`**
- L158: `<form method="POST" action="{{ route('deals-v2.destroy', $deal) }}" onsubmit="return confirm('Archive this deal?')" class="inline">`

**`resources/views/deals-v2/pipeline-setup/edit.blade.php`**
- L496: `if (!confirm('Delete step "' + step.name + '"?')) return;`

**`resources/views/deals-v2/pipeline-setup/index.blade.php`**
- L189: `<form method="POST" action="{{ route('deals-v2.pipeline.destroy', $tpl) }}" class="inline" onsubmit="return confirm('Archive this template?')">`

**`resources/views/deals-v2/pipeline-setup/master.blade.php`**
- L295: `if (window.confirm('Delete the step "' + s.name + '"?\n\nNew deals will no longer include it. Existing deals are unchanged. This cannot be undone from here (Save to persist).')) {`

**`resources/views/deals-v2/settings/service-types/index.blade.php`**
- L81: `onsubmit="return confirm('Archive “{{ $t->label }}”? It leaves the dropdown; steps already using it keep working.');">`

**`resources/views/deals-v2/show.blade.php`**
- L214: `<form method="POST" action="{{ route('deals-v2.distributions.revoke', $dist) }}" onsubmit="return confirm('Revoke this secure link?');">`
- L708: `<form method="POST" action="{{ route('deals-v2.remarks.destroy', $rem) }}" onsubmit="return confirm('Remove this remark?');">`

**`resources/views/deals-v2/suppliers/index.blade.php`**
- L184: `onsubmit="return confirm('Deactivate {{ $p->name }}? Historic deals keep resolving; only new pickers hide it.');">`
- L330: `onsubmit="return confirm('Remove this contact? Historic deals keep resolving.');">`

**`resources/views/deposit-interest-calculator/history.blade.php`**
- L97: `onsubmit="return confirm('Delete this calculation for {{ addslashes($calc->property_name) }}?')">`

**`resources/views/documents/library/index.blade.php`**
- L165: `onsubmit="return confirm('Delete type \'{{ $dt->label }}\'?')">`

**`resources/views/documents/shared-drive/drive.blade.php`**
- L112: `onsubmit="return confirm('Delete folder “{{ $f->name }}” and everything inside it? It can be recovered by an admin.');">`
- L192: `onsubmit="return confirm('Delete “{{ $file->original_name }}”? It can be recovered by an admin.');">`
- L349: `!confirm('Delete ' + this.selectedIds.length + ' selected file(s)? They can be recovered by an admin.')) {`

**`resources/views/documents/shared-drive/index.blade.php`**
- L57: `onsubmit="return confirm('Delete drive “{{ $d->name }}” and everything inside it? It can be recovered by an admin.');">`

**`resources/views/docuperfect/amendments/review.blade.php`**
- L180: `onclick="return confirm('Reject this change? The document continues with the original wording.');"`
- L199: `onclick="return confirm('Reject the entire document? This is terminal. All parties will be notified.');"`

**`resources/views/docuperfect/clauses/index.blade.php`**
- L173: `<form method="POST" action="{{ route('docuperfect.clauses.destroy', $clause->id) }}" class="inline" onsubmit="return confirm('Delete this clause?');">`

**`resources/views/docuperfect/compiler/index.blade.php`**
- L94: `<form method="POST" action="{{ route('docuperfect.compiler.archive', $d->id) }}" class="inline ml-2" onsubmit="return confirm('Archive this draft?');">`

**`resources/views/docuperfect/compiler/studio.blade.php`**
- L324: `removeBlock(i) { if(!confirm('Remove this block?')) return; this.structure.blocks.splice(i,1); this.saveStructure(); },`

**`resources/views/docuperfect/dashboard.blade.php`**
- L72: `<form method="POST" action="{{ route('docuperfect.documents.archive', $doc->id) }}" class="inline" onsubmit="return confirm('Archive this document?');">`

**`resources/views/docuperfect/documents/index.blade.php`**
- L112: `<form method="POST" action="{{ route('docuperfect.documents.destroy', $doc->id) }}" class="inline" onsubmit="return confirm('Permanently delete this document? This cannot be undone.');">`
- L129: `<form method="POST" action="{{ route('docuperfect.documents.archive', $doc->id) }}" class="inline" onsubmit="return confirm('Archive this document? It will be moved to the Archived tab.');">`
- L135: `<form method="POST" action="{{ route('docuperfect.documents.destroy', $doc->id) }}" class="inline" onsubmit="return confirm('Permanently delete this document? This cannot be undone.');">`

**`resources/views/docuperfect/esign/settings/recipient-presets/index.blade.php`**
- L51: `onsubmit="return confirm('Remove the preset &quot;{{ $preset->name }}&quot;?');">`

**`resources/views/docuperfect/field-groups/index.blade.php`**
- L298: `if (!confirm('Archive field group "' + group.name + '"?')) return;`

**`resources/views/docuperfect/importer/index.blade.php`**
- L70: `@click="if(confirm('Delete this draft?')) {`

**`resources/views/docuperfect/importer/review.blade.php`**
- L1324: `if (!confirm('Delete "' + party.name + '"?')) return;`

**`resources/views/docuperfect/packs/index.blade.php`**
- L113: `<form method="POST" action="{{ route('docuperfect.packs.destroy', $pack->id) }}" class="inline ml-auto" onsubmit="return confirm('Delete this pack?');">`

**`resources/views/docuperfect/recipient-templates/index.blade.php`**
- L142: `<form method="POST" action="{{ route('docuperfect.recipient-templates.destroy', $t) }}" onsubmit="return confirm('Remove this recipient template?');">`

**`resources/views/docuperfect/settings/named-fields.blade.php`**
- L154: `onsubmit="return confirm('Archive this named field? An admin can restore it later.');"`

**`resources/views/docuperfect/settings/types.blade.php`**
- L122: `onsubmit="return confirm('Archive this document type? An admin can restore it later.');"`

**`resources/views/docuperfect/signatures/external/sign.blade.php`**
- L2416: `if (!confirm('Remove this change and revert to the original text?')) return;`

**`resources/views/docuperfect/signatures/partials/_agent-amendments-panel.blade.php`**
- L113: `@click.prevent="if (outstanding>0 || rejectedCount>0) return; if (confirm('{{ $nextParty ? 'Approve and send to ' . ($amendNextName ?? 'the next recipient') . '?' : 'Approve the amendments?' }}')) { $el.closest('form').submit(); }">`
- L122: `@submit.prevent="if (!canSendBack) return; if (confirm('Send this document back to {{ $completedRequest?->signer_name ?? 'the recipient' }} to REMOVE the rejected change(s) and re-sign? They will get a fresh signing link by email.')) { $el.submit(); }">`

**`resources/views/docuperfect/signatures/partials/_selection-edit-tool.blade.php`**
- L119: `if (!confirm('Remove this rejected change? This reverts it to the original — required before you can sign again.')) return;`

**`resources/views/docuperfect/signatures/review.blade.php`**
- L666: `onclick="return confirm('Are you sure? This will void all signatures and cancel the signing flow.')">`

**`resources/views/docuperfect/templates/cds-builder.blade.php`**
- L1934: `if (!confirm('Delete "' + party.name + '"?')) return;`

**`resources/views/docuperfect/templates/index.blade.php`**
- L243: `<form method="POST" action="{{ route('docuperfect.templates.archive', $tpl->id) }}" class="inline" onsubmit="return confirm('Archive this template?');">`
- L248: `<form method="POST" action="{{ route('docuperfect.templates.destroy', $tpl->id) }}" class="inline ml-auto" onsubmit="return confirm('Permanently delete this template? This cannot be undone.');">`
- L335: `<form method="POST" action="{{ route('docuperfect.templates.destroy', $tpl->id) }}" class="inline" onsubmit="return confirm('Permanently delete?');">`

**`resources/views/docuperfect/web-packs/index.blade.php`**
- L101: `<form method="POST" action="{{ route('docuperfect.web-packs.destroy', $webPack->id) }}" class="inline" onsubmit="return confirm('Archive this web pack?');">`

**`resources/views/dr2/_communications.blade.php`**
- L56: `if(!confirm('Unlink this email from the deal? The reference history captured from it is kept.')) return;`

**`resources/views/dr2/_pipeline-context-tabs.blade.php`**
- L23: `onsubmit="return confirm('Decline this deal? Its pipeline locks (read-only) and it drops to Declined. It stays re-grantable from the register.');">`

**`resources/views/dr2/_pipeline-step-row.blade.php`**
- L61: `onsubmit="return confirm('Record the NEGATIVE outcome? This completes the step and applies its declined status, and does NOT start the next steps.');">@csrf`
- L89: `<form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf`

**`resources/views/dr2/_pipeline-step-tile.blade.php`**
- L107: `<form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf<input type="hidden" name="from" value="{{ $from ?? '' }}">`

**`resources/views/dr2/_pipeline-timeline-actions.blade.php`**
- L32: `@unless($locked)<form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf<input type="hidden" name="from" value="timeline"><button type="submit" class="b rm">Remove</button></form>@endunless`

**`resources/views/dr2/create.blade.php`**
- L989: `if (!confirm('This property record' + soldBit + '. Deals on it will NOT update property/portal statuses. Did you mean the active listing at this address? Click Cancel to keep searching, or OK to link this record anyway.')) {`
- L1523: `if (!confirm('Remove ' + form.dataset.address + ' from this deal?')) e.preventDefault();`

**`resources/views/dr2/pipeline-list.blade.php`**
- L232: `onsubmit="return confirm('Decline this deal? Its pipeline locks (read-only) and it drops to Declined. It stays re-grantable from the register.');">`

**`resources/views/filing-register/index.blade.php`**
- L370: `<form method="POST" action="{{ route('filing-register.destroy', $filing->id) }}" class="inline" onsubmit="return confirm('Delete this filing entry?')">`

**`resources/views/my-portal/leave/show.blade.php`**
- L76: `<button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--ds-crimson); border-radius:6px;" onclick="return confirm('Cancel this leave application?')">Confirm Cancel</button>`

**`resources/views/payroll/deduction-types/index.blade.php`**
- L149: `onsubmit="return confirm('Delete this deduction type? It will be archived and can be recovered by an admin.')">`

**`resources/views/payroll/earning-types/index.blade.php`**
- L178: `onsubmit="return confirm('Delete this earning type? It will be archived and can be recovered by an admin.')">`

**`resources/views/payroll/employees/index.blade.php`**
- L157: `onsubmit="return confirm('Deactivate this employee? They will be skipped in future runs.')">`

**`resources/views/payroll/employees/show.blade.php`**
- L20: `<button type="submit" class="px-3 py-2 text-xs font-semibold transition" style="color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); border-radius:6px; background:none; cursor:pointer;" onclick="return confirm('Deactivate this employee?')">Deactivate</button>`
- L191: `<button type="submit" class="text-xs font-semibold" style="color:var(--ds-crimson); background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this earning?')">Remove</button>`
- L313: `<button type="submit" class="text-xs font-semibold" style="color:var(--ds-crimson); background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this deduction?')">Remove</button>`

**`resources/views/payroll/leave/public-holidays/index.blade.php`**
- L100: `onsubmit="return confirm('Delete this holiday?')">`

**`resources/views/payroll/leave/types/index.blade.php`**
- L172: `onsubmit="return confirm('Delete this leave type?')">`

**`resources/views/payroll/runs/payslip-edit.blade.php`**
- L46: `<button type="submit" class="w-full px-3 py-2 text-xs font-semibold text-white transition" style="background:var(--ds-amber); border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'" onclick="return confirm('This will discard all manual edits and recalculate from the employee\'s current earnings/deductions template. Continue?')">`
- L99: `<button type="submit" class="text-[10px] font-semibold" style="color:var(--ds-crimson); background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this earning line?')">Remove</button>`
- L186: `<button type="submit" class="text-[10px] font-semibold" style="color:var(--ds-crimson); background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this deduction line?')">Remove</button>`

**`resources/views/payroll/runs/show.blade.php`**
- L192: `<button type="submit" class="corex-btn-primary text-sm" style="background: var(--ds-crimson, #c41e3a);" onclick="return confirm('This will cancel the run and soft-delete all draft payslips. Continue?')">Cancel Run</button>`

**`resources/views/presentations/pricing-simulator.blade.php`**
- L483: `if (!confirm('Reset scenarios to defaults? Unsaved changes will be lost.')) return;`

**`resources/views/presentations/review.blade.php`**
- L1266: `if (!confirm('Discard this presentation? Your overrides will be logged but the version will be archived.')) return;`

**`resources/views/presentations/show.blade.php`**
- L348: `onsubmit="return confirm('Revoke this link? The seller will no longer be able to view it.');">`
- L503: `onsubmit="return confirm('Delete this document? Extracted data will be removed.')">`
- L1820: `onsubmit="return confirm('Remove this article?');"`

**`resources/views/proforma/show.blade.php`**
- L51: `<form method="POST" action="{{ route('proforma.lines.remove', [$invoice, $line]) }}" onsubmit="return confirm('Remove this line?')" style="display:inline;">`
- L81: `<form method="POST" action="{{ route('proforma.void', $invoice) }}" onsubmit="return confirm('Void this proforma? The record is kept; the number is never reused.')" style="display:flex;gap:.4rem;">`

**`resources/views/rental-applications/public/show.blade.php`**
- L687: `if (!confirm('Remove ' + doc.name + '?')) return;`

**`resources/views/rental/settings/properties/index.blade.php`**
- L51: `onclick="return confirm('Deactivate this property?')">Deactivate</button>`

**`resources/views/seller-outreach/entry/prospecting-create-contact.blade.php`**
- L277: `if (!window.confirm('Unlink this deed? Its auto-linked sellers are removed and the address reverts. Manual sellers stay.')) return;`

**`resources/views/settings/email-setup/_user-mailbox.blade.php`**
- L68: `onsubmit="return confirm('Archive this capture mailbox?');">`

**`resources/views/settings/outreach-templates/_channel-panel.blade.php`**
- L94: `onclick="return confirm('Archive this template? Agents will no longer see it in the composer.');"`

**`resources/views/settings/prospecting/_panel.blade.php`**
- L333: `<form method="POST" action="{{ route('settings.prospecting.towns.archive', $town) }}" class="inline" onsubmit="return confirm('Archive {{ $town->name }}? Its suburbs remain mapped but the town is hidden.');">`
- L373: `<form method="POST" action="{{ route('settings.prospecting.suburbs.archive', $suburb) }}" class="inline" onsubmit="return confirm('Archive suburb {{ $suburb->suburb_name }}?');">`
- L458: `<form method="POST" action="{{ route('settings.prospecting.property-types.archive', $type) }}" class="inline" onsubmit="return confirm('Archive {{ $type->name }}?');">`
- L531: `<form method="POST" action="{{ route('settings.prospecting.bedroom-segments.archive', $segment) }}" class="inline" onsubmit="return confirm('Archive segment {{ $segment->name }}?');">`
- L609: `<form method="POST" action="{{ route('settings.prospecting.price-bands.archive', $band) }}" class="inline" onsubmit="return confirm('Archive band {{ $band->name }}?');">`

**`resources/views/tools/pdf_splitter_review.blade.php`**
- L429: `page loaded, confirm()/link() reject the mismatch instead of`

**`resources/views/tools/tools.blade.php`**
- L1203: `if (!confirm("Delete this history entry?")) return;`
- L1519: `if(confirm("Reset current form inputs?")) {`

---

### 2. CONFIRM — other/non-destructive (94 call sites across 62 files)

**`resources/views/admin/agencies/create-edit.blade.php`**
- L685: `onsubmit="return confirm('Show all active agents on the website? You can hide individual agents afterwards.');">`
- L735: `<form method="POST" action="{{ route('agencies.api-keys.regenerate', [$agency, $key]) }}" onsubmit="return confirm('Regenerate the secret? The old secret stops working immediately.');">`

**`resources/views/admin/agencies/index.blade.php`**
- L122: `return confirm('Take agency &quot;{{ $agency->name }}&quot; OUT of maintenance? Its users will be able to sign in and work normally again.');`
- L124: `if (!confirm('Put agency &quot;{{ $agency->name }}&quot; INTO maintenance? Only System Owners will be able to access it; all of its users will see the maintenance screen after login. Login stays up for every other agency.')) return false;`

**`resources/views/admin/backups/index.blade.php`**
- L202: `onsubmit="return confirm('Reveal the backup encryption password? Every reveal is recorded in the audit log.');">`

**`resources/views/admin/company-settings/index.blade.php`**
- L1189: `onsubmit="return confirm('Push all {{ $soldCount }} sold {{ \Illuminate\Support\Str::plural('property', $soldCount) }} to the website? You can hide individual listings afterwards.');">`

**`resources/views/admin/demo-access/connection.blade.php`**
- L313: `onsubmit="return confirm('{{ $siteConnector ? 'Issue a new website token? The current one stops working the moment this is created, so registration is down until the new token is pasted into the website.' : 'Issue the website token?' }}');">`

**`resources/views/admin/dev-settings/index.blade.php`**
- L500: `if (! window.confirm(`

**`resources/views/admin/finance/audit/index.blade.php`**
- L36: `onsubmit="return confirm('Run recalculation and audit for the selected period?')">`

**`resources/views/admin/finance/definitions.blade.php`**
- L34: `onclick="if(!confirm('This will recalculate ALL periods with deals. This may take a while. Continue?')){event.preventDefault();return;}document.getElementById('recalcMode').value='all'"`

**`resources/views/admin/importer/review.blade.php`**
- L174: `<form method="POST" action="{{ route('admin.importer.agency.invite-agents', $agency) }}" onsubmit="return confirm({{ Js::from($confirmMsg) }});">`
- L247: `<form method="POST" action="{{ route('admin.importer.agent.invite', $agent->id) }}" class="inline" onsubmit="return confirm({{ Js::from($agentConfirm) }});">`

**`resources/views/admin/marketing-suppressions/index.blade.php`**
- L104: `onsubmit="return confirm('Lift this suppression? This identifier will be able to receive marketing again.');">`

**`resources/views/admin/pp/agent-mapping.blade.php`**
- L196: `if (!confirm('This will update the External Ref for ' + this.userName + ' on Private Property. Are you sure?')) return;`

**`resources/views/admin/system-updates/bulk-email.blade.php`**
- L185: `const ok = confirm(`

**`resources/views/admin/system-updates/edit.blade.php`**
- L66: `onsubmit="return confirm('Show this update again to every CoreX user — including the people who already closed it?');">`

**`resources/views/admin/targets/index.blade.php`**
- L46: `<form method="POST" action="{{ route('admin.targets.carry-forward') }}" class="inline" onsubmit="return confirm('Copy last month\'s targets to this month? Existing entries will not be overwritten.')">`

**`resources/views/command-center/buyers/detail.blade.php`**
- L447: `onsubmit="return confirm('Make this the primary wishlist? The current primary will be demoted.');">`

**`resources/views/command-center/calendar/index.blade.php`**
- L4351: `if (!hasBuyer && !confirm('No buyer is ticked for this viewing. Save anyway?')) {`

**`resources/views/command-center/feedback/index.blade.php`**
- L199: `if (!confirm('Mark ' + this.selected.length + ' report(s) as Fixed (Done)? This can be changed again later.')) return;`

**`resources/views/commission/index.blade.php`**
- L197: `onsubmit="return confirm('Confirm this commission entry?')">`
- L209: `onsubmit="return confirm('Mark this entry as paid?')">`

**`resources/views/communications/wa-devices/index.blade.php`**
- L39: `onclick="return confirm('{{ $backfillEnabled ? 'Turn OFF body backfill? Only live messages will be captured.' : 'Turn ON read-only body backfill for this agency?' }}')">`

**`resources/views/compliance/fica/show.blade.php`**
- L115: `<button type="submit" class="corex-btn-primary text-sm" onclick="return confirm('Resubmit this FICA for compliance officer review?')">`

**`resources/views/compliance/whistleblow/show.blade.php`**
- L231: `<form method="POST" action="{{ route('compliance.whistleblow.approve', $complaint) }}" onsubmit="return confirm('Send this complaint to PPRA now?')">`

**`resources/views/corex/contacts/_header-actions.blade.php`**
- L5: `permission gate, form, Alpine binding and confirm() below is the original,`

**`resources/views/corex/deeds-capture/index.blade.php`**
- L738: `onsubmit="return confirm('Keep the owner already on your books, and leave the other name on file unused?');">`
- L761: `onsubmit="return confirm({{ Js::from('Update the owner to ' . trim((string) ($conflict->contact ? trim($conflict->contact->first_name . ' ' . (string) $conflict->contact->last_name) : $conflict->name)) . '?') }});">`
- L832: `onsubmit="return confirm('Add this as a new property and link the owner? Any ticked contact numbers below will be added too.');">`
- L968: `onsubmit="return confirm({{ Js::from('Update ' . $rowConfirmName . ' with these details and link the owner? Any ticked contact numbers below will be added too.') }});">`

**`resources/views/corex/market-intelligence/_slideover-header.blade.php`**
- L182: `onsubmit="return confirm('Promote this Tracked Property to agency stock?');"`

**`resources/views/corex/market-intelligence/opportunity-detail.blade.php`**
- L121: `onclick="return confirm('Promote this Tracked Property to Agency Stock?\n\nA Property record will be created and linked. The full source attribution is preserved here.');"`
- L441: `if (!confirm('Make this the primary address?\n\nThe current primary will move to history.')) return;`

**`resources/views/corex/market-intelligence/reports/show.blade.php`**
- L136: `onsubmit="return confirm('Re-parse this report? Existing data points, comp rows, and discrepancies for this report will be cleared and re-extracted from the original PDF.');">`

**`resources/views/corex/properties/partials/_third-party-sale.blade.php`**
- L100: `onsubmit="return confirm('Put this listing back on the market? The loss record is kept for reporting.');">`

**`resources/views/corex/properties/partials/readiness-panel.blade.php`**
- L48: `@click.stop="if(confirm('Record compliance snapshot and enable marketing?')) {`

**`resources/views/corex/properties/partials/syndication-scripts.blade.php`**
- L430: `if (!confirm('Reactivate this listing on Private Property?')) return;`
- L508: `if (!confirm('This will permanently claim PP ownership of this listing. Continue?')) return;`
- L710: `if (!confirm('Reactivate this listing on Property24?')) return;`

**`resources/views/corex/properties/show.blade.php`**
- L1039: `onsubmit="return confirm('Move this property to Draft — mandate won?');">`
- L6662: `if (!confirm('Possible duplicate contact(s) found:\n\n' + names + '\n\nCreate anyway?')) return;`

**`resources/views/corex/prospecting/property-take-requests.blade.php`**
- L55: `onsubmit="return confirm('Approve — {{ $r->requestedBy->name ?? 'this agent' }} takes this property?');">`

**`resources/views/corex/rental-applications/partials/document-highlighter-script.blade.php`**
- L688: `if (! confirm('You have unsaved highlights or notes on this document. Save them before switching?')) {`
- L967: `if (! confirm('You have unsaved highlights or notes on this document. Save them before closing?')) {`

**`resources/views/corex/rental-applications/review.blade.php`**
- L1784: `confirm()+unload-guard-suppress @submit pattern as`
- L1825: `old confirm() used, rendered on the page, with`
- L1858: `confirm() always carried a real figure to check against`
- L1870: `one-shot popup) and in the confirm() text (matching`
- L1938: `@submit="if (!confirm('Send this back to the agent for more information?')) { $event.preventDefault(); return; } window.__raSuppressUnloadGuard = true; $refs.moreInfoReasonField.value = moreInfoReason">`
- L3219: `if (!confirm('Clear the linked property? The affordability check will show "cannot be calculated" until a property is linked again.')) return;`

**`resources/views/corex/rental-applications/view-readonly.blade.php`**
- L218: `reintroduced confirm() here would have been silently`

**`resources/views/corex/tracked-properties/show.blade.php`**
- L176: `onclick="return confirm('Promote this Tracked Property to Agency Stock?\n\nA Property record will be created and linked to this TP. The full source attribution is preserved here.');"`
- L403: `if (!confirm('Make this the primary address?\n\nThe current primary will move to history.')) return;`

**`resources/views/deals-v2/settlement/index.blade.php`**
- L176: `onclick="return confirm('Mark this deal as Paid? This locks all financial fields.')">`

**`resources/views/docuperfect/amendments/review.blade.php`**
- L163: `onclick="return confirm('Approve this amendment? All parties will be requeued for initialing.');"`

**`resources/views/docuperfect/compiler/studio.blade.php`**
- L358: `if (!confirm('Publish this as an immutable, content-hashed version? This cannot be edited afterwards — only superseded by a new version.')) return;`

**`resources/views/docuperfect/esign/my-documents.blade.php`**
- L346: `onclick="return confirm('Retry finishing this document? Anyone who already received their signed copy will not be emailed again.')">`
- L627: `<button type="submit" class="text-xs font-semibold hover:underline transition-colors duration-150" style="color: var(--ds-amber);" onclick="return confirm('Send reminder to {{ $activeReq->signer_name }}?')">`
- L892: `onsubmit="return confirm('Mark {{ $batch->signer_name }}\'s {{ $batch->count }} document(s) as filed? They move to Filed additional docs.');">`

**`resources/views/docuperfect/rental/dashboard.blade.php`**
- L122: `<button type="submit" class="text-xs px-3 py-1 rounded-lg bg-[color:var(--brand-button)] text-white hover:opacity-90" onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">`
- L193: `<button type="submit" class="text-[color:var(--brand-icon)] hover:underline text-xs" onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">Renew</button>`
- L383: `<button type="submit" class="text-amber-600 hover:underline text-xs" onclick="return confirm('Send reminder to {{ $activeReq->signer_name }}?')">`

**`resources/views/docuperfect/signatures/partials/_recipient-resend.blade.php`**
- L24: `onclick="return confirm('Resend the {{ $rr->status === 'completed' ? 'signed document' : 'signing invitation' }} to {{ $rr->signer_name }}?')">`

**`resources/views/docuperfect/signatures/review.blade.php`**
- L551: `onclick="return confirm('{{ $nextParty ? 'Approve the amendment and send to ' . $amendNextName . '?' : 'Approve and finalise the document?' }}')">`
- L565: `onclick="return confirm('You will be taken to the signing view to authorise this document with your signature and initials.')">`
- L577: `onclick="return confirm('{{ $nextParty`

**`resources/views/docuperfect/signatures/sign.blade.php`**
- L62: `onsubmit="return confirm('Resubmit this document to the authoriser for review? Your changes and initials will be sent back to them.');">`

**`resources/views/dr2/_pipeline-step-row.blade.php`**
- L46: `<form method="POST" action="{{ route('deals-dr2.pipeline.step.reopen', [$deal, $s]) }}" onsubmit="return confirm('Reopen this step? It returns to Not started and downstream dates re-cascade.');">@csrf`

**`resources/views/dr2/_pipeline-step-tile.blade.php`**
- L71: `<form method="POST" action="{{ route('deals-dr2.pipeline.step.reopen', [$deal, $s]) }}" onsubmit="return confirm('Reopen this step? It returns to Not started and downstream dates re-cascade.');">@csrf<input type="hidden" name="from" value="{{ $from ?? '' }}">`

**`resources/views/dr2/_pipeline-timeline-actions.blade.php`**
- L17: `<form method="POST" action="{{ route('deals-dr2.pipeline.step.reopen', [$deal, $s]) }}" onsubmit="return confirm('Reopen this step? It returns to Not started and downstream dates re-cascade.');">@csrf<input type="hidden" name="from" value="timeline"><button type="submit" class="b">↺ Reopen</button></form>`

**`resources/views/dr2/create.blade.php`**
- L1177: `if (first && confirm('A matching contact already exists: ' + first.name + '. Link that existing contact instead?')) { addToken(first.id, first.name); newFormEl.style.display = 'none'; return; }`
- L1178: `if (body.duplicate_detected.can_override && confirm('Create a NEW contact anyway (override the duplicate)?')) { postContact(payload, true, show); return; }`

**`resources/views/dr2/pipeline-timeline.blade.php`**
- L625: `if(!window.confirm(msg)){revert();return;}`

**`resources/views/onboarding/portal/review.blade.php`**
- L477: `if (!confirm('Exclude this listing from going live?')) return;`
- L610: `if (!confirm('Import ${this.selected.length} selected listings?')) return;`
- L618: `if (!confirm('Exclude ${this.selected.length} listings?')) return;`
- L625: `if (!confirm('Import all pending listings for this agency? This runs in the background — you can leave this page and the images keep downloading.')) return;`

**`resources/views/onboarding/show.blade.php`**
- L238: `onsubmit="return confirm('Advance to {{ \App\Models\AgentApplication::STATUS_LABELS[$next] }}?')">`
- L251: `onsubmit="return confirm('Activate this agent? This will create their user account.')"`

**`resources/views/payroll/leave/applications/show.blade.php`**
- L115: `<button type="submit" class="corex-btn-primary text-sm" onclick="return confirm('Approve this leave application?')">Approve</button>`

**`resources/views/payroll/leave/balances/show.blade.php`**
- L17: `<button type="submit" class="corex-btn-outline text-xs" onclick="return confirm('Recalculate all balances from transaction ledger?')">Recalculate</button>`

**`resources/views/presentations/show.blade.php`**
- L838: `onclick="return confirm('Clear this override?')">`

**`resources/views/prospecting/index_legacy_body.blade.php`**
- L791: `onsubmit="return confirm('Release this claim? Another agent will be able to claim it.')">`

**`resources/views/rental/active-leases.blade.php`**
- L136: `onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">`

**`resources/views/rental/expired-leases.blade.php`**
- L91: `onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">`

**`resources/views/rental/signatures.blade.php`**
- L166: `<button type="submit" class="corex-btn-primary text-xs px-3 py-1.5" onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">`
- L240: `<button type="submit" class="text-xs hover:underline transition-all duration-300" style="color: var(--brand-icon);" onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">Renew</button>`
- L491: `<button type="submit" class="hover:underline text-xs transition-all duration-300" style="color: var(--ds-amber);" onclick="return confirm('Send reminder to {{ $activeReq->signer_name }}?')">`

**`resources/views/seller-outreach/compose.blade.php`**
- L263: `const ok = window.confirm(`

**`resources/views/seller-outreach/contact-timeline/_panel.blade.php`**
- L128: `onclick="return confirm('Record opt-out? This will block all future pitches to this contact.');"`
- L162: `onclick="return confirm('{{ $optedOut`

**`resources/views/seller-outreach/opt-out.blade.php`**
- L201: `onsubmit="return confirm('Stop ALL messages from {{ $agencyName }}?');">`

**`resources/views/settings/email-setup/_user-mailbox.blade.php`**
- L62: `onsubmit="return confirm('Reveal this mailbox password? Every reveal is recorded in the credential audit log.');">`

**`resources/views/training/show.blade.php`**
- L128: `onclick="return confirm('By clicking Acknowledge, you confirm you have read and understood all course material. This acknowledgement is valid for 12 months.')">`

---

### 3. ALERT (174 call sites across 53 files)

**`resources/views/admin/system-updates/bulk-email.blade.php`**
- L180: `alert('Choose an agency with at least one active user.');`

**`resources/views/command-center/buyers/_buyer-share-bar.blade.php`**
- L66: `if (!this.emailAddress) { alert('This contact has no email address.'); return; }`
- L73: `alert(this.outreachWindowMessage || 'Outreach sending is closed right now.');`

**`resources/views/command-center/calendar/index.blade.php`**
- L3905: `alert('Cannot reschedule to past dates.'); return;`
- L3916: `else { alert('Reschedule failed.'); }`
- L3917: `} catch (e) { alert('Network error.'); }`
- L3940: `else { const err = await r.json().catch(() => ({})); alert(err.error || 'Could not reschedule.'); }`
- L3941: `} catch (e) { alert('Network error during reschedule.'); }`

**`resources/views/command-center/feedback/index.blade.php`**
- L220: `alert('Could not update all reports. Please try again.');`

**`resources/views/communications/capture-consent/my-capture.blade.php`**
- L108: `if (r.status === 419) { alert('Your session refreshed — reloading the page; please choose again.'); window.location.reload(); return; }`
- L111: `else { alert(d.error || 'Could not save.'); }`
- L112: `} catch (e) { alert('Network error — please try again.'); }`

**`resources/views/communications/capture-consent/review.blade.php`**
- L83: `else { alert(d.error || 'Could not flag.'); }`
- L84: `} catch (e) { alert('Network error — please try again.'); }`

**`resources/views/compliance/fica/compliance-review.blade.php`**
- L409: `if (!has) { alert('Please provide your signature.'); return; }`

**`resources/views/compliance/seller-info/index.blade.php`**
- L48: `if (this.enabledRecipients.length === 0) { alert('Add at least one recipient.'); return; }`

**`resources/views/components/agent-photo-cropper.blade.php`**
- L157: `im.onerror = () => { URL.revokeObjectURL(url); alert('Could not read that image.'); };`
- L248: `if (!blob) { alert('Crop failed — please try again.'); return; }`

**`resources/views/components/duplicate-detection-modal.blade.php`**
- L114: `<button type="button" onclick="alert('Access request feature pending — will be available in M3.5.')"`

**`resources/views/corex/communications/access-inbox.blade.php`**
- L80: `else { alert(d.error || 'Could not action the request.'); }`
- L81: `} catch (e) { alert('Network error — please try again.'); }`

**`resources/views/corex/contacts/_communications-tab-body.blade.php`**
- L29: `if(r.status===419){ alert('Your session refreshed — reloading the page; please choose again.'); window.location.reload(); return; }`
- L30: `const d=await r.json(); if(r.ok&&d.ok){ this.status=d.status; } else { alert(d.error||'Could not save.'); }`
- L31: `}catch(e){ alert('Network error — try again.'); } finally{ this.busy=false; } } }">`
- L85: `else { alert(d.error || 'Could not update.'); }`
- L86: `} catch(e) { alert('Network error — please try again.'); }`
- L114: `else { alert(d.error || 'Could not revoke.'); }`
- L115: `} catch(e) { alert('Network error — please try again.'); }`

**`resources/views/corex/contacts/_match-action-bar.blade.php`**
- L90: `if (!this.emailAddress) { alert('This contact has no email address.'); return; }`
- L99: `alert(this.outreachWindowMessage || 'Outreach sending is closed right now.');`
- L126: `if (!res.ok || !data.ok) { alert(data.message || 'Could not queue.'); return; }`
- L127: `alert(data.message || 'Added to your outreach queue.');`
- L129: `} catch (e) { alert('Network error — try again.'); } finally { this.queuing = false; }`

**`resources/views/corex/contacts/show.blade.php`**
- L227: `if (!target) { alert('This contact has no phone number.'); return; }`
- L245: `if (!target) { alert('This contact has no email address.'); return; }`

**`resources/views/corex/market-intelligence/_comments-alpine.blade.php`**
- L67: `alert('Could not remove comment: ' + (e.message || 'error'));`

**`resources/views/corex/market-intelligence/opportunity-detail.blade.php`**
- L452: `else alert('Could not change primary address (HTTP ' + r.status + ').');`
- L453: `}).catch(err => alert('Network error: ' + (err?.message || 'unknown')));`

**`resources/views/corex/outreach-queue/index.blade.php`**
- L157: `alert(data.message || 'Could not dispatch.');`
- L164: `alert('Network error — try again.');`
- L176: `if (!res.ok) { alert(data.message || 'Could not cancel.'); return; }`
- L179: `alert('Network error — try again.');`

**`resources/views/corex/properties/ad.blade.php`**
- L1027: `alert('Export failed: ' + (err?.message || 'unknown'));`
- L1041: `alert('Download failed: ' + (err?.message || 'unknown error'));`

**`resources/views/corex/properties/show.blade.php`**
- L6493: `alert('First name, last name and phone are required.');`
- L6596: `if (res.status === 422) { const d = await res.json().catch(() => ({})); alert(Object.values(d.errors || {}).flat().join('\n') || 'Please pick a valid role.'); }`
- L6597: `else alert('Failed to link contact.');`
- L6603: `alert('Network error while linking contact.');`
- L6625: `if (res.status === 422) { const d = await res.json().catch(() => ({})); alert(Object.values(d.errors || {}).flat().join('\n') || 'Invalid role.'); }`
- L6626: `else alert('Failed to update role.');`
- L6631: `alert('Network error while updating role.');`
- L6658: `alert('A possible duplicate contact exists. Please ask an admin to review:\n\n' + names);`
- L6675: `alert('Duplicate contact exists:\n\n' + names);`
- L6683: `alert('Please fix the following:\n' + (msgs || 'Validation failed.'));`
- L6685: `alert('Failed to create contact.');`
- L6696: `alert('Network error while creating contact.');`
- L6713: `if (!res.ok) { alert('Failed to unlink contact.'); return; }`
- L6720: `alert('Network error while unlinking contact.');`
- L7211: `alert(e && e.message ? e.message : 'Could not rotate the image. Please try again.');`
- L7352: `alert('You do not have permission to delete these photos.');`
- L7355: `alert(data.message || 'Could not delete the photos. Please try again.');`
- L7359: `alert('Network error while deleting photos. Please try again.');`
- L7498: `alert(data.message || 'This page is showing an older version of the gallery. Reload the page and redo your changes.');`
- L7776: `alert('Could not create the property. Please try again.');`
- L7808: `alert(failed + ' of ' + files.length + ' photo(s) failed to upload. You can add the missing ones from the Gallery tab.');`

**`resources/views/corex/tracked-properties/show.blade.php`**
- L418: `alert('Could not change primary address (HTTP ' + r.status + '). Please refresh and try again.');`
- L421: `alert('Network error: ' + (err?.message || 'unknown'));`

**`resources/views/deals-v2/create-form.blade.php`**
- L416: `if (!this.selectedProperty) { e.preventDefault(); alert('Select a property first.'); return; }`
- L417: `if (!this.selectedTemplateId) { e.preventDefault(); alert('Pick a pipeline for this deal type (set one up in Pipeline Setup if none exist).'); return; }`

**`resources/views/deals-v2/pipeline-setup/master.blade.php`**
- L305: `alert('Exactly ONE grant-convergence marker is required (currently ' + this.grantCount + ').');`

**`resources/views/docuperfect/documents/edit.blade.php`**
- L203: `alert('Failed to save document. Please try again.');`

**`resources/views/docuperfect/documents/index.blade.php`**
- L201: `alert('PDF library not loaded. Please refresh and try again.');`
- L216: `alert('No documents to combine.');`
- L307: `alert('Combined PDF failed: ' + err.message);`

**`resources/views/docuperfect/importer/review.blade.php`**
- L1321: `alert('Cannot delete the last signing party.');`

**`resources/views/docuperfect/packs/create.blade.php`**
- L318: `alert('Please add at least one slot.');`

**`resources/views/docuperfect/signatures/_partials/add-condition-modal.blade.php`**
- L137: `alert(j.error || ('Could not initial (' + r.status + ')'));`
- L140: `alert('Network error: ' + e.message);`

**`resources/views/docuperfect/signatures/external/amendment-review.blade.php`**
- L202: `alert('Please draw your initial first.');`
- L224: `alert(data.error || 'Failed to accept amendment.');`
- L227: `alert('Network error. Please try again.');`
- L235: `alert('Please provide a reason for rejection.');`
- L256: `alert(data.error || 'Failed to reject amendment.');`
- L259: `alert('Network error. Please try again.');`

**`resources/views/docuperfect/signatures/external/sign.blade.php`**
- L2506: `alert('Failed to set signing method. Please try again.');`
- L3160: `alert(data.error || 'Failed to capture signature.');`
- L3164: `alert('Network error. Please try again.');`
- L4192: `alert('Failed to decline. Please try again.');`

**`resources/views/docuperfect/signatures/partials/_agent-amendments-panel.blade.php`**
- L192: `let url; if(mode==='draw'){ if(!hasInk){ alert('Please draw your initial, or switch to Type.'); return; } url=canvas.toDataURL('image/png'); }`
- L193: `else { const v=(document.getElementById('agentCiInput').value||'').trim(); if(!v){ alert('Enter your initials.'); return; } url=typedUrl(v); }`
- L198: `if(!ok){ alert('Could not save your initial — please try again.'); return; }`
- L228: `else { alert(d.error || 'Could not update the rejection — please try again.'); }`
- L229: `} catch(e){ alert('Could not update the rejection — please try again.'); }`
- L240: `alert('Highlight the text in the document to change, then click “✎ Amend” to strike or reword it. Your edit becomes a new mark everyone re-initials.');`

**`resources/views/docuperfect/signatures/partials/_selection-edit-tool.blade.php`**
- L129: `alert(d.error || 'Could not remove the change.');`
- L130: `} catch (e) { alert('Could not remove the change. Please try again.'); }`

**`resources/views/docuperfect/signatures/review.blade.php`**
- L317: `} catch (e) { alert('Failed to process amendment action.'); }`

**`resources/views/docuperfect/signatures/sign.blade.php`**
- L1800: `alert(data.error || 'Failed to save field values.');`
- L1806: `alert('Network error saving fields. Please try again.');`
- L2343: `alert(data.error || 'Failed to capture signature.');`
- L2347: `alert('Network error. Please try again.');`
- L2447: `alert('Please complete all fields: ${labels.join(', ')} (${incomplete.length} remaining)');`
- L2545: `alert(data.error || 'Failed to complete signing.');`
- L2552: `alert('Network error: ' + err.message + '. Please try again.');`
- L2564: `alert('Please ${typeLabel} here — ${unsignedMarkers.length} remaining');`

**`resources/views/docuperfect/templates/cds-builder.blade.php`**
- L1931: `alert('Cannot delete the last signing party.');`

**`resources/views/docuperfect/web-packs/form.blade.php`**
- L280: `alert('Please select at least one template.');`
- L292: `alert('Selectable group ' + ['','A','B','C'][g] + ' needs at least 2 templates (agent picks one).');`

**`resources/views/dr2/pipeline-list.blade.php`**
- L557: `.then(r=>r.json()).then(j=>{if(!j.ok)alert(j.error||'Could not save order.');window.location.reload();}).catch(()=>window.location.reload());}`

**`resources/views/evaluation/index.blade.php`**
- L124: `<button title="Street View" type="button" onclick="alert('Street View — requires Google Maps API key')" class="eval-tool-btn">`
- L178: `<button title="Measure" type="button" onclick="alert('Measure tool — next build')" class="eval-tool-btn">`

**`resources/views/fica/form.blade.php`**
- L894: `if (file.size > 10485760) { alert('File is too large. Maximum size is 10MB.'); return; }`
- L910: `if (!has) { alert('Please provide your signature before submitting.'); return; }`

**`resources/views/layouts/partials/help-widget.blade.php`**
- L357: `alert('Screenshot library not loaded yet. Please wait a moment and try again.');`
- L387: `alert('Screenshot capture failed: ' + msg.slice(0, 200) + '.\n\nYou can still submit feedback without a screenshot.');`
- L425: `alert('Failed to submit. Please try again.');`
- L428: `alert('Network error.');`

**`resources/views/marketing/hub.blade.php`**
- L87: `alert(data.error || 'Could not generate copy. Please try again.');`
- L90: `alert('Request failed: ' + e.message);`
- L96: `if (!platforms.length) { alert('Select at least one platform to publish to.'); return; }`
- L97: `if (!this.selectedImages.length) { alert('Select at least one photo to include.'); return; }`
- L99: `if (!copy.trim()) { alert('Ad copy cannot be empty.'); return; }`
- L115: `alert('Publish failed.');`
- L118: `alert('Request failed: ' + e.message);`
- L133: `else { alert('Sync failed: ' + (data.error || 'Unknown')); btn.disabled = false; btn.textContent = 'Sync'; }`
- L135: `alert('Sync failed: ' + e.message);`
- L524: `else { alert('Sync failed: ' + (data.error || 'Unknown')); btn.disabled = false; btn.textContent = 'Sync'; }`
- L526: `alert('Sync failed: ' + e.message);`

**`resources/views/onboarding/portal/review.blade.php`**
- L516: `if (!r.ok) alert('Could not reassign agent.');`
- L531: `if (!r.ok) { alert('Could not start the import. ' + (r.data?.message ?? '')); return; }`
- L532: `if (!r.data?.count) { alert('Nothing to import — no pending listings.'); return; }`
- L606: `alert('Select at least one listing first.');`
- L609: `if (this.progress.active) { alert('An import is already running.'); return; }`
- L615: `alert('Select at least one listing first.');`
- L624: `if (this.progress.active) { alert('An import is already running.'); return; }`

**`resources/views/presentations/partials/analysis-data-review.blade.php`**
- L845: `if (d) { cb.checked = !cb.checked; cb.disabled = false; alert((d && (d.message || d.error)) || 'Could not update the competition set.'); }`

**`resources/views/presentations/pricing-simulator.blade.php`**
- L392: `alert('Error computing scenarios: ' + e.message);`
- L440: `alert('Error saving: ' + e.message);`
- L454: `if (rows.length >= 8) { alert('Maximum 8 scenarios.'); return; }`
- L523: `if (rows.length <= 1) { alert('At least one scenario is required.'); return; }`

**`resources/views/presentations/show.blade.php`**
- L1113: `alert('Provide name + (email or phone).'); return;`
- L1146: `if (!recipients.length) { alert('Pick at least one recipient.'); return; }`
- L1422: `alert('Generation failed: ' + (j.error || 'unknown'));`
- L1425: `alert('Network error: ' + e.message);`
- L1447: `alert('Save failed: ' + (j.error || 'unknown'));`
- L1450: `alert('Network error: ' + e.message);`

**`resources/views/seller-outreach/compose.blade.php`**
- L148: `alert(this.windowMessage || 'Outreach sending is closed right now.');`
- L189: `alert(data.message || 'Send failed.');`
- L207: `alert('Network error — try again.');`
- L234: `if (!res.ok) { alert(data.message || 'Could not queue.'); return; }`
- L235: `alert(data.message || 'Added to your outreach queue.');`
- L238: `alert('Network error — try again.');`

**`resources/views/seller-outreach/entry/prospecting-create-contact.blade.php`**
- L314: `window.alert('This owner has no SA ID or registration number on the deed — cannot link as a distinct seller.');`
- L325: `window.alert(msg);`
- L328: `window.alert('Could not link this owner — a network error occurred. Please try again.');`

**`resources/views/tools/ad-manager.blade.php`**
- L439: `window.showToast ? window.showToast('Download failed: ' + (e?.message || 'unknown'), 'error') : alert('Download failed.');`
- L453: `} catch (e) { window.showToast ? window.showToast('Copy failed — select manually.', 'error') : alert('Copy failed.'); }`

**`resources/views/tools/evaluation-certificate/authorisations.blade.php`**
- L165: `if (!r.ok) { alert(j.message || 'Could not return the certificate.'); return; }`

**`resources/views/tools/evaluation-certificate/mine.blade.php`**
- L134: `if (!r.ok) { alert(j.message || 'Could not prepare the share.'); return; }`
- L135: `if (!j.wa_phone) { alert('This contact has no WhatsApp number.'); return; }`

**`resources/views/tools/pdf-suite/redact.blade.php`**
- L126: `alert('Could not read the PDF: ' + err.message);`

**`resources/views/tools/pdf-suite/reorder.blade.php`**
- L124: `alert('Could not read the PDF: ' + err.message);`

**`resources/views/tools/pdf-suite/rotate.blade.php`**
- L119: `alert('Could not read the PDF: ' + err.message);`

**`resources/views/tools/tools.blade.php`**
- L854: `else { alert('Could not add contact.'); return; }`
- L875: `if (!r.ok) { const j = await r.json().catch(() => ({})); alert(j.message || 'Could not save.'); return; }`
- L891: `if (!this.contactId) { alert('Link a contact before sharing.'); return; }`
- L894: `if (!r.ok) { alert(j.message || 'Could not prepare the share.'); return; }`
- L895: `if (!j.wa_phone) { alert('This contact has no WhatsApp number.'); return; }`
- L918: `if (this.dirty) { alert('Save your changes first.'); return; }`
- L946: `if (!r.ok) { alert(j.message || 'Could not return the certificate.'); return; }`
- L1198: `alert("Could not load history entry.");`
- L1208: `alert("Could not delete history entry.");`
- L1230: `alert("Could not save history");`
- L1322: `if (!w) return alert("Pop-up blocked. Please allow pop-ups for printing.");`

---

### 4. PROMPT (9 call sites across 7 files)

**`resources/views/admin/agencies/index.blade.php`**
- L125: `var msg = prompt('Optional message to show this agency\'s users (leave blank for the default):', '');`
- L142: `var pw = prompt('Type the delete password to confirm:');`

**`resources/views/communications/capture-consent/my-capture.blade.php`**
- L97: `if (status === 'opted_out') { reason = prompt('Optional: why are you not capturing this contact? (recorded for compliance)') || ''; }`

**`resources/views/communications/capture-consent/review.blade.php`**
- L73: `const note = prompt('Why is this a business chat the agent should capture? (sent to the agent)') || '';`

**`resources/views/corex/contacts/_communications-tab-body.blade.php`**
- L24: `if(s==='opted_out'){ reason = prompt('Optional: why not capture your WhatsApp with this contact? (recorded for compliance)') || ''; }`

**`resources/views/docuperfect/signatures/review.blade.php`**
- L304: `const reason = action === 'reject' ? prompt('Reason for rejection:') : null;`

**`resources/views/docuperfect/templates/_insertable-blocks-mixin.blade.php`**
- L29: `const label = (prompt('Block label (e.g. "Outstanding Repairs"):') || '').trim();`

**`resources/views/onboarding/show.blade.php`**
- L166: `x-data onsubmit="event.preventDefault(); let r = prompt('Rejection reason:'); if(r) { this.querySelector('[name=rejection_reason]').value = r; this.submit(); }">`
- L273: `x-data onsubmit="event.preventDefault(); let r = prompt('Reason for rejection:'); if(r) { this.querySelector('[name=status_notes]').value = r; this.submit(); }">`

