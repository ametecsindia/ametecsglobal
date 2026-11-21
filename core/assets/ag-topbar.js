// ONE TOP BAR (19 Nov 2026, owner: "show everywhere same"): draws the Ametecs Global top bar - company > page, bell, clock,
// signed-in user with Account settings, Theme, Back to Ametecs Global and Sign out - inside ROVEXY, QRONEX and SmartEPT.
// A file (not inline) so ROVEXY's CSP ('self' scripts only) allows it. The app's own page heading and buttons stay.
(function () {
  var m = location.pathname.match(/^(.*)\/plugins\/([a-z0-9]+)\//); if (!m) return;
  var core = m[1] + '/core/', app = m[2];
  document.documentElement.classList.add('ag-' + app);   // ONE MENU: ag-topbar.css styles this app's left menu like Ametecs Global's
  // where the bar goes in each app; hide = the app's own top bar it replaces (ROVEXY's carries the same things)
  var at = {rovexy: {before: '.topbar', hide: true, title: '.topbar .crumb > :last-child'},
            qronex: {before: 'main header.sticky, header.sticky', title: 'header.sticky h1'},
            smartept: {before: '#app .main > .top', title: '#page-title'},
            smartprs3: {before: '.main-wrap > .topbar', title: '.topbar .page-title'}}[app]; if (!at) return;
  var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = core + 'assets/ag-topbar.css?v=8'; document.head.appendChild(css);
  var I = {chev: '<svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>', bell: '<svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
    clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>', down: '<svg viewBox="0 0 24 24" style="width:12px;height:12px"><path d="m6 9 6 6 6-6"/></svg>'};
  var sw = {gold: 'linear-gradient(135deg,#f3dc95,#d6ae55 45%,#b08433)', navy: 'linear-gradient(145deg,#132b50,#0a1a33)', light: '#fff', dark: '#0d0b08'};
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function cur() { try { return localStorage.getItem('agTheme') || 'gold'; } catch (e) { return 'gold'; } }
  function time() { return new Date().toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit', hour12: true}).toUpperCase(); }
  function page() { var t = document.querySelector(at.title); return (t && t.textContent.trim()) || document.title.split(/[·|]/)[0].trim(); }
  var bar;
  function build(u) {
    bar = document.createElement('div'); bar.className = 'agtb';
    bar.innerHTML = '<a class="hm" href="' + core + '" title="Back to Ametecs Global"><span>AG</span>Ametecs Global</a><div class="c"><a href="' + core + '">' + esc(u.company || 'Ametecs Global') + '</a>' + I.chev + '<b class="pg"></b></div>'
      + '<a class="bl" href="' + core + '?v=notifications" title="Notifications">' + I.bell + (u.notes ? '<em>' + u.notes + '</em>' : '') + '</a>'
      + '<div class="ck">' + I.clock + '<span class="tm">' + time() + '</span></div>'
      + '<details><summary><span class="av">' + esc(u.initials) + '</span><span class="nm">' + esc(u.name) + '<small>' + esc(u.role) + '</small></span>' + I.down + '</summary>'
      + '<div class="mn"><div class="h"><b>' + esc(u.name) + '</b><span>' + esc(u.email) + '</span></div>'
      + '<a href="' + core + '?v=account">Account settings</a>'
      + '<div class="th"><span>Theme</span>' + Object.keys(sw).map(function (k) { return '<i data-t="' + k + '" title="' + k + '" style="background:' + sw[k] + '"></i>'; }).join('') + '</div>'
      + '<a href="' + core + '">Back to Ametecs Global</a>'
      + '<form method="post" action="' + core + '"><input type="hidden" name="csrf" value="' + esc(u.csrf) + '"><button name="do" value="signout">Sign out</button></form></div></details>';
    bar.addEventListener('click', function (e) {
      var t = e.target.closest('[data-t]'); if (!t) return;
      try { localStorage.setItem('agTheme', t.dataset.t); } catch (x) {}
      dispatchEvent(new StorageEvent('storage', {key: 'agTheme'})); mark();
    });
    document.addEventListener('click', function (e) { var d = bar.querySelector('details'); if (d.open && !bar.contains(e.target)) d.open = false; });
    mark();
  }
  function mark() { bar && bar.querySelectorAll('[data-t]').forEach(function (i) { i.classList.toggle('on', i.dataset.t === cur()); }); }
  // apps re-render their pages (ROVEXY is React), so keep the bar in place, the page name current and the clock ticking
  function keep() {
    var t = document.querySelector(at.before); if (!t || !bar) return;
    if (bar.nextElementSibling !== t) t.parentNode.insertBefore(bar, t);
    if (at.hide) t.style.display = 'none';
    var p = page(); var pg = bar.querySelector('.pg'); if (pg.textContent !== p) pg.textContent = p;
    var tt = p + ' · Ametecs Global'; if (p && document.title !== tt) document.title = tt;   // one product in the browser tab too
    bar.querySelector('.tm').textContent = time();
  }
  // ── ONE MENU (owner, 20 Nov 2026: Ametecs Global is one product) ─────────────────────────────────────────────────
  // The app's own menu and logo make way for Ametecs Global's menu (core/menu.php): every task of every app, grouped by
  // work area, only what this person's role, App access and licence allow. Inside the same app a click goes straight to
  // the page (SmartEPT switches view in place); another app's page opens through core/open.php (one sign-in hand-over).
  var sd = {smartprs3: {nav: 'aside.sidebar .sidebar-nav', hide: 'aside.sidebar .sidebar-header, aside.sidebar .sidebar-footer'},
            qronex: {nav: 'aside.sidebar nav.nav', hide: 'aside.sidebar > div:first-child'},
            smartept: {nav: '.side .navwrap', hide: '.side .lock'},
            rovexy: {nav: '.sidebar nav.nav', hide: '.sidebar .brand'}}[app];
  var fa = document.createElement('link'); fa.rel = 'stylesheet'; fa.href = core + 'assets/fa/css/all.min.css'; document.head.appendChild(fa);
  var menuEl, items = [];
  function toOf(h) { var m = /[?&]to=([^&]*)/.exec(h); return m ? decodeURIComponent(m[1]) : null; }
  function appOf(h) { var m = /open\.php\?app=([a-z0-9]+)/.exec(h); return m ? m[1] : null; }
  function base() {   // this app's own address for its pages
    var p = location.pathname, m;
    if (app === 'rovexy' && (m = /^(.*\/w\/[^/]+\/admin\/)/.exec(p))) return m[1];
    if (app === 'qronex' && (m = /^(.*\/plugins\/qronex\/public\/[a-z0-9-]+)/.exec(p))) return m[1];
    return null;
  }
  function direct(h) {   // same app: straight to the page, no hand-over
    if (appOf(h) !== app) return h;
    var to = toOf(h) || '', b = base();
    if (app === 'smartept') return '#v=' + to;
    return b === null ? h : (app === 'qronex' ? b + (to ? '/' + to : '') : b + to);
  }
  function eptGo(v) {   // SmartEPT: click its own (hidden) menu item, so its own rules and loading run
    var pr = v.split(':'), sel = '.nav[data-view="' + pr[0] + '"]' + (pr[1] ? '[data-rsec="' + pr[1] + '"]' : '');
    var el = document.querySelector(sel); if (el && document.querySelector('#app') && getComputedStyle(document.querySelector('#app')).display !== 'none') { el.click(); return true; }
    return false;
  }
  function activeHref() {
    var best = null, bl = -1, p = location.pathname + location.search;
    items.forEach(function (it) {
      var h = it.d, l = -1;
      if (app === 'smartept') { var a = document.querySelector('.side .nav.active'); if (a && it.app === 'smartept') { var v = a.dataset.view + (a.dataset.rsec ? ':' + a.dataset.rsec : ''); if (toOf(it.h) === v || (toOf(it.h) === a.dataset.view && !a.dataset.rsec)) l = 99; } }
      else if (app === 'smartprs3') { var r = /[?&]r=([^&]*)/.exec(location.search), ir = /[?&]r=([^&]*)/.exec(h); if (r && ir && r[1] === ir[1] && h.indexOf('/plugins/smartprs3/') >= 0) l = 99; }
      else if (it.app === app && h.charAt(0) === '/') { if (p === h || p === h + '/') l = 99; else if (p.indexOf(h.replace(/\/$/, '') + '/') === 0) l = h.length; }
      if (l > bl) { bl = l; best = it; }
    });
    return bl >= 0 ? best : null;
  }
  function drawMenu(m) {
    menuEl = document.createElement('div'); menuEl.className = 'agm';
    var h = '<a class="agm-b" href="' + core + '"><span>AG</span>Ametecs <b>Global</b></a>'
      + '<div class="agm-f"><i class="fas fa-magnifying-glass"></i><input type="search" placeholder="Find a page…" aria-label="Find a page"></div>';
    items = [];
    m.groups.forEach(function (g) {
      h += '<details class="agm-g" data-k="' + g.key + '"><summary><i class="fas ' + esc(g.icon) + '"></i><span>' + esc(g.label) + '</span><i class="fas fa-chevron-down car"></i></summary>';
      g.items.forEach(function (it) {
        var d = direct(it.href); items.push({h: it.href, d: d, app: it.app, g: g.key});
        h += '<a class="agm-i" href="' + esc(d) + '" data-h="' + esc(it.href) + '"><i class="fas ' + esc(it.icon) + '"></i><span>' + esc(it.label) + '</span>' + (it.count ? '<em>' + it.count + '</em>' : '') + '</a>';
      });
      h += '</details>';
    });
    menuEl.innerHTML = h;
    menuEl.addEventListener('click', function (e) {
      var a = e.target.closest('a.agm-i'); if (!a) return;
      if (app === 'smartept' && appOf(a.dataset.h) === 'smartept') { e.preventDefault(); var v = toOf(a.dataset.h) || 'dashboard'; if (eptGo(v)) history.replaceState(null, '', '#v=' + v); }
    });
    // find a page: filter every task in every work area
    menuEl.querySelector('.agm-f input').addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      menuEl.querySelectorAll('details').forEach(function (d) {
        var any = false;
        d.querySelectorAll('a.agm-i').forEach(function (a) { var m = !q || a.textContent.toLowerCase().indexOf(q) >= 0; a.hidden = !m; any = any || m; });
        d.hidden = !any; if (q) d.open = any;
      });
    });
    // only one work area open at a time, like Ametecs Global's own menu
    menuEl.querySelectorAll('details').forEach(function (d) { d.addEventListener('toggle', function () { if (d.open) menuEl.querySelectorAll('details[open]').forEach(function (o) { if (o !== d) o.open = false; }); }); });
    if (m.prs_pending) fetch(m.prs_nav, {credentials: 'same-origin'}).then(function () { return fetch(core + 'topbar.php', {credentials: 'same-origin'}); })
      .then(function (r) { return r.json(); }).then(function (u) { if (u.ok && !u.menu.prs_pending) { var old = menuEl; drawMenu(u.menu); old.replaceWith(menuEl); } }).catch(function () {});
  }
  var lastOn = null;
  function keepMenu() {
    if (!sd || !menuEl) return;
    var nav = document.querySelector(sd.nav); if (!nav) return;
    if (menuEl.nextElementSibling !== nav) nav.parentNode.insertBefore(menuEl, nav);
    nav.style.display = 'none';
    document.querySelectorAll(sd.hide).forEach(function (x) { x.style.display = 'none'; });
    var on = activeHref(), key = on ? on.h : '';
    if (key !== lastOn) {
      lastOn = key;
      menuEl.querySelectorAll('a.agm-i').forEach(function (a) { a.classList.toggle('on', a.dataset.h === key); });
      var oa = menuEl.querySelector('a.agm-i.on'); if (oa) oa.closest('details').open = true;
    }
  }
  // SmartEPT opened at #v=<view> (from the menu in another app): show that view once the console is ready
  if (app === 'smartept' && /^#v=/.test(location.hash)) {
    var want = decodeURIComponent(location.hash.slice(3)), tries = 0;
    var ti = setInterval(function () { if (eptGo(want) || ++tries > 60) clearInterval(ti); }, 500);
  }
  fetch(core + 'topbar.php', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (u) {
    if (!u.ok) return; build(u); if (u.menu) drawMenu(u.menu);
    keep(); keepMenu(); setInterval(function () { keep(); keepMenu(); }, 700); addEventListener('storage', mark);
  }).catch(function () {});
})();
