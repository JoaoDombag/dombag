// Service worker do aplicativo instalável (registrado por shared/app_icone.php).
// Páginas PHP nunca ficam em cache: mostram dados da sessão e do ERP.
// Arquivos fixos (/public/) vêm da rede e o cache só cobre a falta de conexão.
var CACHE = 'dombag-v1';

self.addEventListener('install', function () { self.skipWaiting(); });

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (nomes) {
    return Promise.all(nomes.filter(function (n) { return n !== CACHE; })
      .map(function (n) { return caches.delete(n); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () {
      return new Response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">' +
        '<title>Sem conexão | DOMBAG</title>' +
        '<body style="font-family:Segoe UI,sans-serif;background:#0b1730;color:#e6edf7;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center">' +
        '<div><h1 style="font-size:20px">Sem conexão</h1><p>Verifique a internet e tente de novo.</p>' +
        '<button onclick="location.reload()" style="padding:10px 18px;border:0;border-radius:8px;background:#0891b2;color:#fff;font-size:15px">Tentar de novo</button></div>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }));
    return;
  }

  if (url.pathname.indexOf('/public/') === 0) {
    e.respondWith(fetch(req).then(function (resp) {
      if (resp.ok) {
        var copia = resp.clone();
        caches.open(CACHE).then(function (c) { c.put(req, copia); });
      }
      return resp;
    }).catch(function () { return caches.match(req); }));
  }
});
