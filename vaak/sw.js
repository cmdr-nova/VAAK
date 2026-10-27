/* VAAK Web Push service worker — /vaak/sw.js */
self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

function parsePushPayload(event) {
  try {
    if (!event.data) return {};
    const text = event.data.text();
    if (!text) return {};
    return JSON.parse(text);
  } catch (e) {
    return {};
  }
}

self.addEventListener('push', (event) => {
  const data = parsePushPayload(event);
  const title = String(data.title || 'VAAK');
  const body = String(data.body || '');
  const icon = String(data.icon || '/vaak/apple-touch-icon.png');
  const notifType = String(data.notification_type || '');
  const notifId = data.notification_id != null ? String(data.notification_id) : '';
  const targetView = notifType === 'dm' || /DM/i.test(title) ? 'dms' : 'mentions';
  event.waitUntil(
    self.registration
      .showNotification(title, {
        body,
        icon,
        badge: '/vaak/favicon-32x32.png',
        data: {
          url: '/vaak/?view=' + targetView,
          notification_id: notifId,
          notification_type: notifType,
        },
        renotify: true,
        tag: notifId ? ('vaak-' + notifId) : ('vaak-' + Date.now()),
      })
      .then(() => {
        return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
          clients.forEach((client) => {
            try {
              client.postMessage({
                type: 'vaak-push',
                notification_id: notifId,
                notification_type: notifType,
                at: Date.now(),
              });
            } catch (e) {}
          });
        });
      })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const raw = (event.notification && event.notification.data && event.notification.data.url) || '/vaak/?view=mentions';
  const url = typeof raw === 'string' && raw.startsWith('/') ? raw : '/vaak/?view=mentions';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.postMessage({ type: 'vaak-push-click', url });
          return client.focus().then((c) => {
            if (c && 'navigate' in c) {
              try { return c.navigate(url); } catch (e) {}
            }
            return c;
          });
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});
