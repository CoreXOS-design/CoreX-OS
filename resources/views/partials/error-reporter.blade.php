<script>
(function() {
    try {
        var ENDPOINT = '/api/fault-report';
        var reported = {};
        var DEDUP_MS = 60000;

        function isDuplicate(key) {
            var now = Date.now();
            if (reported[key] && (now - reported[key]) < DEDUP_MS) return true;
            reported[key] = now;
            return false;
        }

        function send(data) {
            try {
                data.user_agent = navigator.userAgent;
                fetch(ENDPOINT, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(data),
                    keepalive: true
                });
            } catch (e) { /* silent */ }
        }

        // 1. Global JS errors
        window.onerror = function(message, source, lineno, colno, error) {
            try {
                var title = String(message || 'Unknown JS error').substring(0, 500);
                if (isDuplicate('onerror:' + title)) return;
                send({
                    type: 'frontend',
                    severity: 'error',
                    title: title,
                    message: error ? String(error.stack || error.message || '').substring(0, 5000) : null,
                    file: source || null,
                    line: lineno || null,
                    url: window.location.href
                });
            } catch (e) { /* silent */ }
        };

        // 2. Unhandled promise rejections
        window.addEventListener('unhandledrejection', function(event) {
            try {
                var reason = event.reason;
                var title = 'Unhandled Promise Rejection';
                if (reason) {
                    title = String(reason.message || reason).substring(0, 500);
                }
                if (isDuplicate('promise:' + title)) return;
                send({
                    type: 'frontend',
                    severity: 'error',
                    title: title,
                    message: reason ? String(reason.stack || reason.message || reason).substring(0, 5000) : null,
                    url: window.location.href
                });
            } catch (e) { /* silent */ }
        });

        // 3. Wrap fetch to capture 500+ responses
        var originalFetch = window.fetch;
        window.fetch = function() {
            var args = arguments;
            var requestUrl = typeof args[0] === 'string' ? args[0] : (args[0] && args[0].url ? args[0].url : '');

            // Don't intercept our own fault reports
            if (requestUrl.indexOf('/api/fault-report') !== -1) {
                return originalFetch.apply(this, args);
            }

            return originalFetch.apply(this, args).then(function(response) {
                try {
                    if (response.status >= 500) {
                        var title = 'Fetch ' + response.status + ': ' + requestUrl.substring(0, 400);
                        if (!isDuplicate('fetch:' + response.status + ':' + requestUrl)) {
                            send({
                                type: 'frontend',
                                severity: 'error',
                                title: title,
                                message: 'HTTP ' + response.status + ' ' + response.statusText,
                                url: window.location.href,
                                method: (args[1] && args[1].method) ? args[1].method.toUpperCase() : 'GET'
                            });
                        }
                    }
                } catch (e) { /* silent */ }
                return response;
            });
        };
    } catch (e) { /* entire error reporter must never break the app */ }
})();
</script>
