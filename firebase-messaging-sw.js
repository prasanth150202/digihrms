importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyBZvX1GGbvo0Ofyaxk32U2f1mc0SPlwUmY",
  authDomain: "digifyce-internal.firebaseapp.com",
  projectId: "digifyce-internal",
  storageBucket: "digifyce-internal.firebasestorage.app",
  messagingSenderId: "802656832683",
  appId: "1:802656832683:web:2b31977bdc5e184723fbee"
});

const messaging = firebase.messaging();

const BASE = self.location.hostname === 'localhost' ? '/hrms/php_implementation' : '';

messaging.onBackgroundMessage(function(payload) {
  const { title, body, icon, click_action } = payload.notification || {};
  self.registration.showNotification(title || 'HRMS', {
    body:  body  || '',
    icon:  icon  || BASE + '/assets/icon-192.png',
    badge: BASE + '/assets/icon-192.png',
    data:  { url: click_action || BASE + '/' },
  });
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const url = event.notification.data?.url || BASE + '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
      for (const c of list) {
        if (c.url.includes(BASE) && 'focus' in c) return c.focus();
      }
      return clients.openWindow(url);
    })
  );
});
