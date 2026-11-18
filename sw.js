/* Loch - service worker : réception des Web Push et clic sur les notifications */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  const scope = self.registration.scope;
  e.waitUntil(self.registration.showNotification(d.title || 'Loch', {
    body: d.body || 'Pense à remplir ton journal.',
    icon: new URL('icon.svg', scope).href,
    badge: new URL('icon.svg', scope).href,
    tag: d.tag || 'jdb-reminder',
    renotify: true,
    requireInteraction: true,
    data: { url: new URL(d.url || './#journal', scope).href },
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const target = (e.notification.data && e.notification.data.url) || self.registration.scope;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of wins) {
      if (c.url.startsWith(self.registration.scope)) {
        await c.focus();
        c.postMessage({ type: 'focus-entry' });
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});

/* Le navigateur renouvelle parfois l'abonnement : on prévient la page au prochain chargement */
self.addEventListener('pushsubscriptionchange', e => {
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of wins) c.postMessage({ type: 'resubscribe' });
  })());
});
