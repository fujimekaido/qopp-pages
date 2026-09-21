/* QOPP: постоянный кеш тяжёлых файлов. GitHub Pages разрешает браузеру держать файлы 10 минут и отдаёт
   видео медленно, поэтому фильм главной и картинки кладём в Cache Storage сами.
   - видео (assets/*.mp4): из кеша навсегда; новый фильм = новое имя файла
   - картинки и шрифты (assets/*): сразу из кеша, в фоне обновляем (заменённая картинка придёт со второго визита)
   - страницы: всегда из сети (выкладка видна сразу), кеш только как запас без связи
   Поднять версию кеша = сменить V: старые кеши удалятся при активации. */
const V = 'qopp-v1';
const MEDIA = V + '-media', PAGES = V + '-pages';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(
  caches.keys()
    .then(keys => Promise.all(keys.filter(k => !k.startsWith(V)).map(k => caches.delete(k))))
    .then(() => self.clients.claim())
));

const ok = r => r && r.status === 200 && r.type === 'basic';

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET' || req.headers.has('range')) return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  if (url.pathname.includes('/assets/')) {
    const video = url.pathname.endsWith('.mp4');
    e.respondWith(caches.open(MEDIA).then(async cache => {
      const hit = await cache.match(req, { ignoreSearch: true });
      const refresh = () => fetch(req).then(r => { if (ok(r)) cache.put(req, r.clone()); return r; });
      if (hit) { if (!video) e.waitUntil(refresh().catch(() => {})); return hit; }
      return refresh();
    }));
    return;
  }

  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req)
        .then(r => { if (ok(r)) { const c = r.clone(); e.waitUntil(caches.open(PAGES).then(x => x.put(req, c))); } return r; })
        .catch(() => caches.match(req, { ignoreSearch: true }).then(r => r || Response.error()))
    );
  }
});
