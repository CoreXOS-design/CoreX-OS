import './bootstrap';
import './nexus-charts';
import './corex-api';

// AT-165 offline draft persistence + resilience complements. Self-initialising,
// degradation-safe; each scans the DOM for its opt-in data-attributes on load.
import './draft-persistence';
import './resilient-submit';
import './session-keepalive';

// Alpine.js — synchronous import from local bundle.
// Eliminates the CDN race condition that caused "first click fails" globally.
import Alpine from 'alpinejs';
if (!window.Alpine) {
    window.Alpine = Alpine;
    Alpine.start();
}
