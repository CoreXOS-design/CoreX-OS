<div class="corex-header">
    {{-- Search --}}
    <div class="corex-search">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
        </svg>
        <input type="text" placeholder="Search transactions, documents..." />
    </div>

    {{-- Actions --}}
    <div class="corex-header-actions">
        {{-- Theme Toggle --}}
        <button type="button" class="corex-theme-toggle" id="corexThemeToggle" title="Toggle light/dark theme" onclick="(function(){var d=document.documentElement,dark=d.classList.toggle('dark');var t=dark?'dark':'light';localStorage.setItem('corex-theme',t);fetch('{{ route('profile.theme') }}',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},body:JSON.stringify({theme:t})});})()">
            {{-- Moon: shown in light mode --}}
            <svg class="corex-icon-moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
            </svg>
            {{-- Sun: shown in dark mode --}}
            <svg class="corex-icon-sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
            </svg>
        </button>

        {{-- Notifications --}}
        <div x-data="notificationBell()" x-init="load()" class="corex-notification-wrap" style="position:relative;">
            <button type="button" class="corex-btn-icon" title="Notifications" @click="toggle()">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                </svg>
                <span class="badge" x-show="unreadCount > 0" x-text="unreadCount > 9 ? '9+' : unreadCount"
                      style="position:absolute;top:-2px;right:-2px;min-width:16px;height:16px;padding:0 4px;background:#00d4aa;color:#0f172a;font-size:10px;font-weight:700;border-radius:8px;display:flex;align-items:center;justify-content:center;line-height:1;"></span>
            </button>
            <div x-show="open" x-cloak @click.outside="open=false"
                 style="position:absolute;top:100%;right:0;margin-top:8px;width:340px;max-height:400px;overflow-y:auto;background:#1e293b;border:1px solid #334155;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,0.4);z-index:100;">
                <div style="padding:10px 14px;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:12px;font-weight:600;color:#f1f5f9;">Notifications</span>
                    <button x-show="unreadCount > 0" @click.stop="markAllRead()" style="font-size:11px;color:#00d4aa;background:none;border:none;cursor:pointer;">Mark all read</button>
                </div>
                <template x-if="items.length === 0">
                    <div style="padding:24px 14px;text-align:center;font-size:12px;color:#64748b;">No notifications</div>
                </template>
                <template x-for="item in items" :key="item.id">
                    <a :href="item.data?.action_url || item.data?.url || '#'" @click="markRead(item.id)"
                       style="display:block;padding:10px 14px;border-bottom:1px solid #334155;text-decoration:none;transition:background 0.1s;"
                       :style="item.read_at ? '' : 'background:rgba(0,212,170,0.04);'"
                       onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background=this.dataset.bg"
                       :data-bg="item.read_at ? '' : 'rgba(0,212,170,0.04)'">
                        <div style="font-size:12px;color:#f1f5f9;line-height:1.4;font-weight:600;" x-text="item.data?.title || item.data?.message || 'Notification'"></div>
                        <div x-show="item.data?.body" style="font-size:11px;color:#cbd5e1;line-height:1.4;margin-top:2px;" x-text="item.data?.body"></div>
                        <div style="font-size:10px;color:#475569;margin-top:3px;" x-text="timeAgo(item.created_at)"></div>
                    </a>
                </template>
            </div>
        </div>
        <script>
        function notificationBell() {
            return {
                open: false,
                items: [],
                unreadCount: 0,
                toggle() { this.open = !this.open; if (this.open) this.load(); },
                load() {
                    fetch('/api/notifications', { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } })
                        .then(r => r.ok ? r.json() : { items: [], unread: 0 })
                        .then(d => { this.items = d.items || []; this.unreadCount = d.unread || 0; })
                        .catch(() => {});
                },
                markRead(id) {
                    fetch('/api/notifications/' + id + '/read', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } })
                        .then(() => { this.items = this.items.map(i => i.id === id ? { ...i, read_at: new Date().toISOString() } : i); this.unreadCount = Math.max(0, this.unreadCount - 1); });
                },
                markAllRead() {
                    fetch('/api/notifications/mark-all-read', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } })
                        .then(() => { this.items = this.items.map(i => ({ ...i, read_at: i.read_at || new Date().toISOString() })); this.unreadCount = 0; });
                },
                timeAgo(dt) {
                    if (!dt) return '';
                    const s = Math.floor((Date.now() - new Date(dt).getTime()) / 1000);
                    if (s < 60) return 'Just now';
                    if (s < 3600) return Math.floor(s/60) + 'm ago';
                    if (s < 86400) return Math.floor(s/3600) + 'h ago';
                    return Math.floor(s/86400) + 'd ago';
                },
            };
        }
        </script>

        {{-- Export Report --}}
        <button type="button" class="corex-btn-outline">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1rem;height:1rem">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            Export Report
        </button>

        {{-- New Transaction --}}
        @if(auth()->user()?->hasPermission('create_deals'))
        <a href="{{ route('admin.deals.create') }}" class="corex-btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:1rem;height:1rem">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            New Transaction
        </a>
        @endif
    </div>
</div>
