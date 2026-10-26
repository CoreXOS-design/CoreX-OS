{{--
    Shown before every Facebook "Connect" — Facebook only lets CoreX post to a Page the person
    administers, and agents kept hitting a dead end connecting a personal profile. Uses the shared
    corexConfirm dialog (partials/corex-confirm, loaded by the layout).
      if (!(await window.corexFacebookConnectExplainer())) return;
--}}
<script>
(function () {
    if (window.corexFacebookConnectExplainer) { return; }
    window.corexFacebookConnectExplainer = function () {
        return window.corexConfirm({
            title: 'Before you connect Facebook',
            message: 'CoreX posts to a Facebook Page, not to a personal profile. To connect you need:\n\n'
                + '• A Facebook Page (a business page) — a personal profile on its own will not work.\n'
                + '• To be an admin of that Page (full control). If you are not, ask the Page owner to add you under Page settings → Page access.\n'
                + '• To log in to Facebook with the personal account that is an admin of the Page.\n\n'
                + 'On the Facebook screens that follow:\n'
                + '1. Choose \"Opt in to current Pages only\" (the second option). Do not choose \"all current and future Pages\" — Facebook then hides some of your Pages from CoreX.\n'
                + '2. Tick the Page(s) you want CoreX to post to.\n'
                + '3. Leave all the permissions switched on — otherwise CoreX will not be able to post.',
            confirmLabel: 'Continue to Facebook',
            cancelLabel: 'Not now',
        });
    };
})();
</script>
