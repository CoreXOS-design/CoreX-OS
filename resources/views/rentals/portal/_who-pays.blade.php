{{-- .ai/specs/rentals-faults-work-orders.md section 22.13.4 - the "Who pays" ruling in plain words, for the owner and the tenant. $p = the Alpine expression of the ruling object
     (RentalWhoPaysService::payload()). A record only: nothing has been charged. --}}
<template x-if="{{ $p }}">
    <div data-who-pays style="margin-top:10px; padding-top:8px; border-top:1px solid var(--border);">
        <h3 style="margin:0 0 6px; font-size:15px;">Who pays</h3>
        <p style="margin:0 0 4px;"><strong>What was found:</strong> <span x-text="{{ $p }}.found"></span></p>
        <p style="margin:0 0 4px;"><strong>Cause:</strong> <span x-text="{{ $p }}.cause"></span></p>
        <p style="margin:0 0 4px;" data-who-pays-headline><strong x-text="{{ $p }}.headline"></strong></p>
        <p style="margin:0 0 4px;"><strong>Reason:</strong> <span x-text="{{ $p }}.reason"></span></p>
        <p class="muted" style="margin:0;" x-text="{{ $p }}.closing"></p>
    </div>
</template>
