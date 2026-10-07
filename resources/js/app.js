import './bootstrap';
import './nexus-charts';
import './corex-api';
import './property-share';
import './rental-inspection-signing';

// Alpine.js — synchronous import from local bundle.
// Eliminates the CDN race condition that caused "first click fails" globally.
import Alpine from 'alpinejs';
if (!window.Alpine) {
    window.Alpine = Alpine;
    Alpine.start();
}
