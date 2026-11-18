/* AmetecsGlobal UI helpers (Phase 4, 18 Nov 2026) - shared by the client panel, Organization Setup and Super Admin.
 *  - mobile menu: any [data-drawer] button toggles body.nav-open (the sidebar slides in; tap outside closes it)
 *  - busy buttons: a submitted form's button shows a spinner and cannot be pressed twice
 *  - pagination: tables with class "paged" (or "sortable") show 25 rows a page, with a pager under them;
 *    live search filters (input[data-live]) still search every row
 */
(function () {
  document.querySelectorAll('[data-drawer]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.stopPropagation(); document.body.classList.toggle('nav-open'); });
  });
  document.addEventListener('click', function (e) {
    if (document.body.classList.contains('nav-open') && !e.target.closest('.side')) { document.body.classList.remove('nav-open'); }
  });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || f.method.toLowerCase() !== 'post' || f.hasAttribute('data-nobusy')) { return; }
    var b = e.submitter || f.querySelector('button:not([type=button])');
    if (!b) { return; }
    // after this tick, so the button's own name/value is still sent
    setTimeout(function () { b.disabled = true; b.classList.add('busy'); }, 0);
    // a download (no page change) gives the button back
    setTimeout(function () { b.disabled = false; b.classList.remove('busy'); }, 15000);
  }, true);

  // live search: input[data-live="#table-id"] hides rows that do not contain the text (skipped where a page already wires it)
  document.querySelectorAll('input[data-live]').forEach(function (inp) {
    if (inp.dataset.wired) { return; }
    inp.dataset.wired = '1';
    var t = document.querySelector(inp.dataset.live);
    t && inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase().trim();
      t.querySelectorAll('tr').forEach(function (r) { if (!r.querySelector('th')) { r.style.display = !q || r.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none'; } });
    });
  });

  var PER = 25;
  document.querySelectorAll('table.paged, table.sortable').forEach(function (t) {
    var rows = function () { return [].slice.call(t.querySelectorAll('tr')).filter(function (r) { return !r.querySelector('th') && !r.querySelector('.empty'); }); };
    if (rows().length <= PER) { return; }
    var page = 0, pager = document.createElement('div');
    pager.className = 'pager';
    (t.closest('.box, .lic-t') || t).insertAdjacentElement('afterend', pager);
    function filtering() { var i = document.querySelector('input[data-live="#' + t.id + '"]'); return i && i.value.trim() !== ''; }
    function draw() {
      if (filtering()) { pager.hidden = true; return; }
      var all = rows(), pages = Math.ceil(all.length / PER);
      page = Math.min(page, pages - 1);
      all.forEach(function (r, i) { r.style.display = Math.floor(i / PER) === page ? '' : 'none'; });
      pager.hidden = false;
      pager.innerHTML = '<span>' + (page * PER + 1) + '–' + Math.min(all.length, (page + 1) * PER) + ' of ' + all.length + '</span>'
        + '<button type="button" data-p="-1"' + (page === 0 ? ' disabled' : '') + '>‹ Previous</button>'
        + '<button type="button" data-p="1"' + (page >= pages - 1 ? ' disabled' : '') + '>Next ›</button>';
    }
    pager.addEventListener('click', function (e) { var b = e.target.closest('button[data-p]'); if (b) { page += +b.dataset.p; draw(); } });
    t.addEventListener('click', function (e) { if (e.target.closest('th')) { setTimeout(draw, 0); } });   // after a sort
    var live = document.querySelector('input[data-live="#' + t.id + '"]');
    live && live.addEventListener('input', function () { setTimeout(draw, 0); });
    draw();
  });
})();

// RESPONSIVE TABLES (owner, 18 Nov 2026: "make full responsive"): every cell learns its column's name, so on a phone each
// row can show as a small card ("Status: Active") instead of a table wider than the screen (CSS in shell.css).
(function () {
  function label(t) {
    var head = t.querySelector('tr'); if (!head || !head.querySelector('th')) return;
    var names = [].map.call(head.children, function (c) { return (c.textContent || '').trim(); });
    t.classList.add('rt');
    [].forEach.call(t.querySelectorAll('tr'), function (r) {
      if (r === head) return;
      var i = 0;
      [].forEach.call(r.children, function (c) { if (c.tagName === 'TD' && !c.hasAttribute('data-label')) c.setAttribute('data-label', names[i] || ''); i += c.colSpan || 1; });
    });
  }
  document.querySelectorAll('main table').forEach(label);
  new MutationObserver(function () { document.querySelectorAll('main table:not(.rt)').forEach(label); }).observe(document.body, {childList: true, subtree: true});
})();

// THEME SWITCH (owner, 18 Nov 2026: gold by default; "Classic navy theme" in the user menu), remembered on this browser.
(function () {
  var root = document.documentElement;
  function label() { document.querySelectorAll('[data-theme-label]').forEach(function (l) { l.textContent = root.classList.contains('t-gold') ? 'Classic navy theme' : 'Gold theme'; }); }
  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-theme-toggle]')) return;
    e.preventDefault();
    var gold = root.classList.toggle('t-gold');
    try { localStorage.setItem('agTheme', gold ? 'gold' : 'classic'); } catch (x) {}
    label();
  });
  label();
})();
// self-service plan form: plan price, credit, GST and total as you change plan or seats
(function () {
  function inr(v) { return 'INR ' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function calc(f) {
    var o = f.plan.options[f.plan.selectedIndex], base = (+o.dataset.rate || 0) * (+f.seats.value || 0), net = Math.max(0, base - (+f.dataset.credit || 0)), gst = net * (+f.dataset.gst || 0) / 100;
    f.querySelector('[data-o=base]').textContent = inr(base); f.querySelector('[data-o=gst]').textContent = inr(gst); f.querySelector('[data-o=tot]').textContent = inr(net + gst);
  }
  document.querySelectorAll('form[data-plan]').forEach(function (f) { calc(f); f.addEventListener('input', function () { calc(f); }); });
})();
