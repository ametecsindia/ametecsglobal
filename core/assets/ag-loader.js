// AMETECS GLOBAL LOADER (owner, 21 Nov 2026: "loading time / refresh / logo animation ... add also admin and super admin
// page"): the same animated mark as the phone app. Leaving a page (a link, a form, a refresh) shows a gold bar sliding
// across the top at once and, if the next page takes longer than a moment, the mark turning in its orbit.
// One file for Ametecs Global (Admin, Organization Setup, Super Admin) and, through setup.php, every app page.
(function () {
  if (window.agLoader) return; window.agLoader = true;
  var dir = (document.currentScript && document.currentScript.src || '').replace(/[^/]*$/, '');
  var css = document.createElement('style');
  css.textContent =
    '.agl-bar{position:fixed;left:0;top:0;height:3px;width:100%;z-index:2147483646;overflow:hidden;pointer-events:none;opacity:0;transition:opacity .15s}' +
    '.agl-bar i{position:absolute;top:0;height:100%;width:40%;background:linear-gradient(90deg,#b08433,#e8c977,#c9a24c);border-radius:3px;animation:agl-slide .9s ease-in-out infinite}' +
    '.agl-wait{position:fixed;inset:0;z-index:2147483645;display:grid;place-items:center;background:rgba(10,9,6,.28);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .25s}' +
    '.agl-card{display:grid;justify-items:center;gap:12px;padding:26px 30px;border-radius:24px;background:linear-gradient(145deg,#0b0906,#14100a 55%,#2a2112);box-shadow:0 24px 60px -20px rgba(0,0,0,.6);color:#d9c9a3;font:600 13px/1.2 system-ui,Segoe UI,Roboto,sans-serif;letter-spacing:.3px}' +
    '.agl-mark{position:relative;width:84px;height:84px;display:grid;place-items:center}' +
    '.agl-mark img{width:58px;height:58px;border-radius:14px;animation:agl-pulse .75s ease-in-out infinite alternate}' +
    '.agl-mark:before{content:"";position:absolute;inset:0;border-radius:50%;border:3px solid transparent;border-top-color:#e8c977;border-right-color:#c9a24c;animation:agl-turn 1.1s linear infinite}' +
    '.agl-on .agl-bar{opacity:1}.agl-slow .agl-wait{opacity:1}' +
    '@keyframes agl-slide{0%{left:-40%}100%{left:100%}}@keyframes agl-turn{to{transform:rotate(360deg)}}@keyframes agl-pulse{from{transform:scale(.92)}to{transform:scale(1.04)}}' +
    '@media (prefers-reduced-motion:reduce){.agl-bar i,.agl-mark img,.agl-mark:before{animation:none}}';
  document.head.appendChild(css);
  var root = document.documentElement, slow = null;
  function el() {
    if (document.querySelector('.agl-bar')) return;
    var b = document.createElement('div'); b.className = 'agl-bar'; b.innerHTML = '<i></i>';
    var w = document.createElement('div'); w.className = 'agl-wait';
    w.innerHTML = '<div class="agl-card"><div class="agl-mark"><img alt="" src="' + dir + 'brand/ag-mark.svg"></div>Loading…</div>';
    document.body.appendChild(b); document.body.appendChild(w);
  }
  var guard = null;
  function start() {
    el(); root.classList.add('agl-on'); clearTimeout(slow); clearTimeout(guard);
    slow = setTimeout(function () { root.classList.add('agl-slow'); }, 450);
    guard = setTimeout(stop, 15000);   // a download or a cancelled leave never leaves the page: never stuck
  }
  function stop() { clearTimeout(slow); clearTimeout(guard); root.classList.remove('agl-on', 'agl-slow'); }
  window.agLoaderStart = start; window.agLoaderStop = stop;
  // a link to another page here: not a new tab, a download, an anchor on this page or a javascript: action
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]'); if (!a) return;
    var h = a.getAttribute('href') || '';
    if (a.target && a.target !== '_self' || a.hasAttribute('download') || h.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(h)) return;
    if (a.origin && a.origin !== location.origin) return;
    if (a.pathname === location.pathname && a.search === location.search && a.hash) return;
    start();
  });
  document.addEventListener('submit', function (e) {
    var f = e.target; if (e.defaultPrevented || (f.target && f.target !== '_self')) return;
    // a form that downloads (CSV, PDF) never leaves the page: stop the bar after a moment
    start(); if (/export|download|csv|pdf|xls/i.test((f.action || '') + ' ' + (f.querySelector('[name=action]') || {}).value)) setTimeout(stop, 2500);
  });
  window.addEventListener('beforeunload', start);
  window.addEventListener('pageshow', stop);   // back / forward from the browser's cache
})();
