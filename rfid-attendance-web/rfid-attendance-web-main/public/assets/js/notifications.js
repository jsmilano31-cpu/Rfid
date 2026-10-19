(() => {
    'use strict';
    const root = document.querySelector('[data-notification-center]');
    if (!root) return;
    const get = name => root.querySelector(`[data-notification-${name}]`);
    const dialog = get('dialog'), list = get('list'), status = get('status');
    let items = [], next = null, unreadOnly = false, busy = false, stopped = false;
    let pushRegistration = null;
    const visible = () => items.filter(item => !unreadOnly || !item.read_at);
    function render() {
        list.replaceChildren();
        for (const item of visible()) {
            const card = document.createElement('article');
            card.className = 'notification-item' + (item.read_at ? '' : ' is-unread');
            const icon = document.createElement('span'); icon.className = 'notification-symbol'; icon.textContent = item.kind === 'attendance' ? '!' : '◷'; icon.setAttribute('aria-hidden', 'true');
            const copy = document.createElement('div'), title = document.createElement('h3'), body = document.createElement('p'), time = document.createElement('small');
            title.textContent = item.title; body.textContent = item.body; time.textContent = item.updated_at + ' · Campus time';
            copy.append(title, body, time);
            if (!item.read_at) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-ghost'; button.textContent = 'Mark as read';
                button.addEventListener('click', () => mark([item])); copy.append(button);
            }
            card.append(icon, copy); list.append(card);
        }
        if (!visible().length) {
            const empty = document.createElement('p'); empty.className = 'notification-empty'; empty.textContent = unreadOnly ? 'You’re all caught up. No unread notifications here.' : 'You’re all caught up. Attendance alerts and activity reminders will appear here.'; list.append(empty);
        }
        get('more').hidden = !next;
        get('read').disabled = !visible().some(item => !item.read_at) || busy;
    }
    async function request(url, options) {
        const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', ...options});
        if (response.status === 401) { stopped = true; throw new Error('Your session ended. Sign in again to view notifications.'); }
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || 'Could not load notifications.');
        return payload.data;
    }
    async function load(older = false) {
        if (busy || stopped) return;
        busy = true; list.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(root.dataset.endpoint, location.origin);
            if (older && next) url.searchParams.set('before', next);
            const data = await request(url);
            items = older ? [...items, ...data.items.filter(item => !items.some(old => old.id === item.id))] : data.items;
            next = data.next_before;
            get('count').textContent = data.unread > 99 ? '99+' : data.unread;
            get('count').hidden = !data.unread;
            get('open').setAttribute('aria-label', `Notifications, ${data.unread} unread`);
            status.textContent = ''; render();
        } catch (error) { status.textContent = error.message || 'Connection lost. Please retry.'; }
        finally { busy = false; list.setAttribute('aria-busy', 'false'); get('read').disabled = !visible().some(item => !item.read_at); }
    }
    async function mark(selected) {
        if (busy || stopped) return;
        busy = true;
        try {
            for (let offset = 0; offset < selected.length; offset += 50) {
                const body = new URLSearchParams({_token: root.dataset.token, items: JSON.stringify(selected.slice(offset, offset + 50).map(({id, revision}) => ({id, revision})))});
                await request(root.dataset.endpoint, {method: 'POST', body});
            }
            busy = false; await load();
        } catch (error) { status.textContent = error.message; } finally { busy = false; }
    }
    get('open').addEventListener('click', () => { dialog.showModal(); load(); });
    get('close').addEventListener('click', () => dialog.close());
    get('filter').addEventListener('click', () => { unreadOnly = !unreadOnly; get('filter').setAttribute('aria-pressed', String(unreadOnly)); render(); });
    get('read').addEventListener('click', () => mark(visible().filter(item => !item.read_at)));
    get('retry').addEventListener('click', () => load());
    get('more').addEventListener('click', () => load(true));
    function bindWorker(account) {
        return new Promise((resolve, reject) => {
            const worker = pushRegistration?.active;
            if (!worker) return reject(new Error('Push worker is not ready. Please retry.'));
            const channel = new MessageChannel();
            const timeout = setTimeout(() => reject(new Error('Push setup timed out. Please retry.')), 5000);
            channel.port1.onmessage = () => { clearTimeout(timeout); resolve(); };
            worker.postMessage({type: 'campus-bind', account}, [channel.port2]);
        });
    }
    async function enablePush(ask = false) {
        try {
            if (!('serviceWorker' in navigator) || !('Notification' in window)) throw new Error('This browser does not support push. Your inbox still works.');
            const url = new URL(root.dataset.endpoint, location.origin); url.searchParams.set('push_config', '1');
            const {config, account_id} = await request(url);
            if (!config.appId) { get('push-status').textContent = 'Browser push is awaiting school setup.'; get('push').disabled = true; return; }
            if (Notification.permission !== 'granted') {
                if (!ask) return;
                if (await Notification.requestPermission() !== 'granted') throw new Error('Push is off. Allow notifications in browser site settings to enable it.');
            }
            const [appSdk, messagingSdk] = await Promise.all([
                import('https://www.gstatic.com/firebasejs/12.3.0/firebase-app.js'),
                import('https://www.gstatic.com/firebasejs/12.3.0/firebase-messaging.js')
            ]);
            if (!await messagingSdk.isSupported()) throw new Error('Push is not supported by this browser.');
            const app = appSdk.getApps()[0] || appSdk.initializeApp(config);
            const messaging = messagingSdk.getMessaging(app);
            pushRegistration = await navigator.serviceWorker.register('/firebase-messaging-sw.php', {scope: '/'});
            await navigator.serviceWorker.ready;
            await bindWorker(account_id);
            const token = await messagingSdk.getToken(messaging, {vapidKey: config.vapidKey, serviceWorkerRegistration: pushRegistration});
            await request(root.dataset.endpoint, {method: 'POST', body: new URLSearchParams({_token: root.dataset.token, action: 'device', token})});
            messagingSdk.onMessage(messaging, payload => { if (payload.data?.account_id === account_id) load(); });
            get('push-status').textContent = 'Browser push enabled'; get('push').hidden = true;
        } catch (error) { get('push-status').textContent = error.message || 'Push could not be enabled. Please retry.'; }
    }
    get('push').addEventListener('click', () => enablePush(true));
    document.querySelector('.sidebar-logout')?.addEventListener('submit', async event => {
        if (!pushRegistration) return;
        event.preventDefault();
        try { await bindWorker(''); } finally { event.target.submit(); }
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    setInterval(() => { if (!document.hidden && !stopped) load(); }, 15000);
    load();
    enablePush();
    if (new URLSearchParams(location.search).has('notifications')) { dialog.showModal(); load(); }
})();
