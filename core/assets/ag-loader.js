// AMETECS GLOBAL LOADER (owner, 21 Nov 2026: "loading time / refresh / logo animation ... add also admin and super admin
// page"): the same animated mark as the phone app. Leaving a page (a link, a form, a refresh) shows a gold bar sliding
// across the top at once and, if the next page takes longer than a moment, the mark turning in its orbit.
// One file for Ametecs Global (Admin, Organization Setup, Super Admin) and, through setup.php, every app page.
(function () {
  if (window.agLoader) return; window.agLoader = true;
  var dir = (document.currentScript && document.currentScript.src || '').replace(/[^/]*$/, '');
  var css = document.createElement('style');
  // THE MARK BUILDS ITSELF (owner, 21 Nov 2026: "transparent logo, animation like build complete logo"): the A is drawn,
  // the orbit sweeps round it (behind, then in front), the satellite lands, it holds, fades, and builds again.
  css.textContent =
    '.agl-bar{position:fixed;left:0;top:0;height:3px;width:100%;z-index:2147483646;overflow:hidden;pointer-events:none;opacity:0;transition:opacity .15s}' +
    '.agl-bar i{position:absolute;top:0;height:100%;width:40%;background:linear-gradient(90deg,#b08433,#e8c977,#c9a24c);border-radius:3px;animation:agl-slide .9s ease-in-out infinite}' +
    '.agl-wait{position:fixed;inset:0;z-index:2147483645;display:grid;place-items:center;background:rgba(246,241,232,.55);backdrop-filter:blur(3px);opacity:0;pointer-events:none;transition:opacity .25s}' +
    '.agl-card{display:grid;justify-items:center;gap:6px;color:#8a6a25;font:600 13px/1.2 system-ui,Segoe UI,Roboto,sans-serif;letter-spacing:.4px}' +
    '.agl-card svg{width:110px;height:110px;filter:drop-shadow(0 6px 18px rgba(201,162,76,.45))}' +
    '.agl-card .a,.agl-card .o{fill:none;stroke-linecap:round;stroke-dasharray:1;stroke-dashoffset:1}' +
    '.agl-card .a{animation:agl-a 2.2s ease-in-out infinite}.agl-card .o{animation:agl-o 2.2s ease-in-out infinite}' +
    '.agl-card .ob{animation-name:agl-ob}.agl-card .of{animation-name:agl-of}' +
    '.agl-card .d{transform-origin:417px 254px;animation:agl-d 2.2s ease-out infinite}' +
    '.agl-card .all{animation:agl-fade 2.2s linear infinite}' +
    '.agl-on .agl-bar{opacity:1}.agl-slow .agl-wait{opacity:1}' +
    '@keyframes agl-slide{0%{left:-40%}100%{left:100%}}' +
    '@keyframes agl-a{0%{stroke-dashoffset:1}35%,100%{stroke-dashoffset:0}}' +
    '@keyframes agl-ob{0%,30%{stroke-dashoffset:1}50%,100%{stroke-dashoffset:0}}' +
    '@keyframes agl-of{0%,50%{stroke-dashoffset:1}70%,100%{stroke-dashoffset:0}}' +
    '@keyframes agl-d{0%,68%{transform:scale(0)}76%{transform:scale(1.5)}82%,100%{transform:scale(1)}}' +
    '@keyframes agl-fade{0%,84%{opacity:1}96%,100%{opacity:0}}' +
    '@media (prefers-reduced-motion:reduce){.agl-bar i,.agl-card *{animation:none!important;stroke-dashoffset:0!important;transform:none!important;opacity:1!important}}';
  // the transparent mark: orbit back half, the A, orbit front half (the weave), satellite
  var MARK = '<svg viewBox="60 90 400 340" aria-hidden="true"><defs><linearGradient id="aglg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#E7C77A"/><stop offset=".55" stop-color="#C9A24A"/><stop offset="1" stop-color="#9C7A2E"/></linearGradient>' +
    '<clipPath id="aglb"><rect x="0" y="0" width="512" height="300"/></clipPath><clipPath id="aglf"><rect x="0" y="300" width="512" height="212"/></clipPath></defs><g class="all">' +
    '<g transform="rotate(-16 256 300)" clip-path="url(#aglb)"><ellipse class="o ob" pathLength="1" cx="256" cy="300" rx="168" ry="52" stroke="url(#aglg)" stroke-width="18" transform="rotate(180 256 300)"/></g>' +
    '<path class="a" pathLength="1" d="M150 396 L256 118 L362 396" stroke="url(#aglg)" stroke-width="46" stroke-linejoin="round"/>' +
    '<g transform="rotate(-16 256 300)" clip-path="url(#aglf)"><ellipse class="o of" pathLength="1" cx="256" cy="300" rx="168" ry="52" stroke="url(#aglg)" stroke-width="18"/></g>' +
    '<circle class="d" cx="417" cy="254" r="17" fill="#E7C77A"/></g></svg>';
  document.head.appendChild(css);
  var root = document.documentElement, slow = null;
  function el() {
    if (document.querySelector('.agl-bar')) return;
    var b = document.createElement('div'); b.className = 'agl-bar'; b.innerHTML = '<i></i>';
    var w = document.createElement('div'); w.className = 'agl-wait';
    w.innerHTML = '<div class="agl-card">' + MARK + 'Loading…</div>';
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
