<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
session_write_close();
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
$config=\App\Services\FcmService::webConfig();
?>
'use strict';
const campusConfig = <?= json_encode($config, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?>;
function binding(value) {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('campus-push', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('binding');
        request.onerror = () => reject(request.error);
        request.onsuccess = () => {
            const db = request.result, tx = db.transaction('binding', value === undefined ? 'readonly' : 'readwrite');
            const store = tx.objectStore('binding');
            const operation = value === undefined ? store.get('account') : store.put(value, 'account');
            let result;
            operation.onsuccess = () => { result = operation.result; };
            tx.oncomplete = () => { db.close(); resolve(result); };
            tx.onerror = () => { db.close(); reject(tx.error); };
        };
    });
}
self.addEventListener('message', event => {
    if (event.data?.type === 'campus-bind') event.waitUntil(binding(String(event.data.account || '')).then(() => event.ports[0]?.postMessage({ok: true})));
});
// Register click handling before Firebase installs its handlers.
self.addEventListener('notificationclick', event => {
    if (event.notification.data?.campus !== true) return;
    event.stopImmediatePropagation(); event.notification.close();
    event.waitUntil(clients.openWindow('/index/?notifications=1'));
});
if (campusConfig.appId) {
    importScripts('https://www.gstatic.com/firebasejs/12.3.0/firebase-app-compat.js');
    importScripts('https://www.gstatic.com/firebasejs/12.3.0/firebase-messaging-compat.js');
    firebase.initializeApp(campusConfig);
    firebase.messaging().onBackgroundMessage(async payload => {
        const account = await binding();
        if (!account || account !== payload.data?.account_id) return;
        await self.registration.showNotification('Campus notification', {
            body: 'You have a new campus alert. Open your inbox to view it securely.',
            tag: 'campus-' + payload.data.notification_id,
            data: {campus: true}, renotify: false
        });
    });
}
