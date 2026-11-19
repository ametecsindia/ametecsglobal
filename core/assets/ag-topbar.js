// ONE TOP BAR (19 Nov 2026, owner: "show everywhere same"): draws the AmetecsGlobal top bar - company > page, bell, clock,
// signed-in user with Account settings, Theme, Back to AmetecsGlobal and Sign out - inside ROVEXY, QRONEX and SmartEPT.
// A file (not inline) so ROVEXY's CSP ('self' scripts only) allows it. The app's own page heading and buttons stay.
(function () {
  var m = location.pathname.match(/^(.*)\/plugins\/([a-z0-9]+)\//); if (!m) return;
  var core = m[1] + '/core/', app = m[2];
  // where the bar goes in each app; hide = the app's own top bar it replaces (ROVEXY's carries the same things)
  var at = {rovexy: {before: '.topbar', hide: true, title: '.topbar .crumb > :last-child'},
            qronex: {before: 'main header.sticky, header.sticky', title: 'header.sticky h1'},
            smartept: {before: '#app .main > .top', title: '#page-title'},
            smartprs3: {before: '.main-wrap > .topbar', title: '.topbar .page-title'}}[app]; if (!at) return;
  var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = core + 'assets/ag-topbar.css?v=3'; document.head.appendChild(css);
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
    bar.innerHTML = '<a class="hm" href="' + core + '" title="Back to AmetecsGlobal"><span>AG</span>AmetecsGlobal</a><div class="c"><a href="' + core + '">' + esc(u.company || 'AmetecsGlobal') + '</a>' + I.chev + '<b class="pg"></b></div>'
      + '<a class="bl" href="' + core + '?v=notifications" title="Notifications">' + I.bell + (u.notes ? '<em>' + u.notes + '</em>' : '') + '</a>'
      + '<div class="ck">' + I.clock + '<span class="tm">' + time() + '</span></div>'
      + '<details><summary><span class="av">' + esc(u.initials) + '</span><span class="nm">' + esc(u.name) + '<small>' + esc(u.role) + '</small></span>' + I.down + '</summary>'
      + '<div class="mn"><div class="h"><b>' + esc(u.name) + '</b><span>' + esc(u.email) + '</span></div>'
      + '<a href="' + core + '?v=account">Account settings</a>'
      + '<div class="th"><span>Theme</span>' + Object.keys(sw).map(function (k) { return '<i data-t="' + k + '" title="' + k + '" style="background:' + sw[k] + '"></i>'; }).join('') + '</div>'
      + '<a href="' + core + '">Back to AmetecsGlobal</a>'
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
    bar.querySelector('.tm').textContent = time();
  }
  fetch(core + 'topbar.php', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (u) {
    if (!u.ok) return; build(u); keep(); setInterval(keep, 700); addEventListener('storage', mark);
  }).catch(function () {});
})();
