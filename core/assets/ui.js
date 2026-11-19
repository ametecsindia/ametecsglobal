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

// THEME CHOICE (owner, 19 Nov 2026): Golden (default), Navy, Light - user menu, remembered on this browser.
(function () {
  var root = document.documentElement;
  function cur() { return root.classList.contains('th-navy') ? 'navy' : (root.classList.contains('th-light') ? 'light' : (root.classList.contains('th-dark') ? 'dark' : 'gold')); }
  function mark() { document.querySelectorAll('[data-theme-set]').forEach(function (a) { a.classList.toggle('on', a.dataset.themeSet === cur()); }); }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-theme-set]'); if (!a) return;
    e.preventDefault();
    root.classList.remove('th-navy', 'th-light', 'th-dark');
    if (a.dataset.themeSet !== 'gold') root.classList.add('th-' + a.dataset.themeSet);
    try { localStorage.setItem('agTheme', a.dataset.themeSet); } catch (x) {}
    mark();
    document.querySelectorAll('iframe').forEach(function (f) { try { var h = f.contentDocument.documentElement; h.classList.remove('th-navy', 'th-light', 'th-dark'); a.dataset.themeSet !== 'gold' && h.classList.add('th-' + a.dataset.themeSet); } catch (x) {} });
  });
  mark();
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
// extra seats: seats after, price for the days left, GST, total
(function () {
  function inr(v) { return 'INR ' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function calc(f) {
    var n = Math.max(0, +f.add.value || 0), base = Math.round(n * (+f.dataset.rate) * (+f.dataset.factor) * 100) / 100, gst = base * (+f.dataset.gst) / 100, a = f.querySelector('[data-o=after]');
    a.textContent = (+a.dataset.now + n); f.querySelector('[data-o=base]').textContent = inr(base); f.querySelector('[data-o=gst]').textContent = inr(gst); f.querySelector('[data-o=tot]').textContent = inr(base + gst);
  }
  document.querySelectorAll('form[data-seats]').forEach(function (f) { calc(f); f.addEventListener('input', function () { calc(f); }); });
})();
// menu hide / show (client panel): folds the sidebar to an icon rail; remembered in this browser
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-sbtog]'); if (!b) return;
  var on = document.documentElement.classList.toggle('sb-mini');
  try { localStorage.setItem('agSb', on ? '1' : '0'); } catch (x) {}
  document.querySelectorAll('.cside details.grp[open]').forEach(function (d) { on && d.removeAttribute('open'); });
});
// timeline search (Activity history) and CSV download of the rows shown
document.addEventListener('input', function (e) {
  var i = e.target.closest('[data-live-tl]'); if (!i) return;
  var q = i.value.toLowerCase(), box = document.querySelector(i.dataset.liveTl);
  box.querySelectorAll('.tli').forEach(function (r) { r.hidden = q !== '' && r.textContent.toLowerCase().indexOf(q) < 0; });
  box.querySelectorAll('.tlday').forEach(function (d) { var n = d.nextElementSibling, any = false; while (n && !n.classList.contains('tlday')) { any = any || !n.hidden; n = n.nextElementSibling; } d.hidden = !any; });
});
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-csv]'); if (!b) return;
  var rows = [['When', 'Kind', 'What', 'Who', 'Detail']];
  document.querySelectorAll(b.dataset.csv + ' [data-csv-row]').forEach(function (r) { if (!r.hidden) rows.push(JSON.parse(r.dataset.csvRow)); });
  var csv = rows.map(function (r) { return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
  var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = b.dataset.name || 'export.csv'; a.click();
});

/* Dashboard tiles open their list (dialog.kdlg, core/dashboard.php), 19 Nov 2026 */
document.addEventListener('click', function (ev) {
  var t = ev.target.closest('[data-dlg]');
  if (t) { ev.preventDefault(); var d = document.getElementById(t.dataset.dlg); if (d) d.showModal(); return; }
  if (ev.target.closest('[data-close]') || ev.target.matches('dialog.kdlg')) { var o = ev.target.closest('dialog'); if (o) o.close(); }
});
document.addEventListener('input', function (ev) {
  if (!ev.target.matches('[data-kfind]')) return;
  var q = ev.target.value.toLowerCase();
  ev.target.closest('dialog').querySelectorAll('tbody tr').forEach(function (r) { r.hidden = q !== '' && r.textContent.toLowerCase().indexOf(q) < 0; });
});

/* Tile list rows open the person's full details in the side card; CSV of the visible rows (19 Nov 2026) */
(function () {
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function show(row) {
    var dlg = row.closest('dialog'), box = dlg.querySelector('.kdet'), d = JSON.parse(row.dataset.d);
    dlg.querySelectorAll('.krow.on').forEach(function (r) { r.classList.remove('on'); }); row.classList.add('on');
    var ini = d.name.split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
    var h = '<button type="button" class="kx" data-kx aria-label="Close details">&times;</button><div class="kpf"><span class="kav big">' + esc(ini) + '</span><div><b>' + esc(d.name) + '</b><small>' + esc(d.code) + ' &middot; <em class="kst">' + esc(d.status) + '</em></small></div></div><div class="katt">';
    Object.keys(d.att).forEach(function (k) { h += '<span class="' + (k === 'Absent' || k === 'Late' ? 'bad' : '') + '"><b>' + d.att[k] + '</b>' + esc(k) + '</span>'; });
    h += '</div><dl>';
    ['Staff type', 'Designation', 'Company', 'Branch', 'Department', 'Team', 'Gender', 'Date of joining', 'Date of birth', 'Probation ends', 'Mobile', 'Email'].forEach(function (k) { if (d[k]) h += '<dt>' + esc(k) + '</dt><dd>' + esc(d[k]) + '</dd>'; });
    h += '</dl><div class="kact">' + (d.Mobile ? '<a href="tel:' + esc(d.Mobile) + '"><i class="fas fa-phone"></i>Call</a>' : '') + (d.Email ? '<a href="mailto:' + esc(d.Email) + '"><i class="fas fa-envelope"></i>Email</a>' : '')
      + '<a class="pri" href="org.php?s=employees"><i class="fas fa-user-pen"></i>Employee directory</a></div>';
    box.innerHTML = h; box.hidden = false;
  }
  document.addEventListener('click', function (ev) {
    var r = ev.target.closest('.krow'); if (r) { show(r); return; }
    var x = ev.target.closest('[data-kx]'); if (x) { var b = x.closest('.kdet'); b.hidden = true; b.closest('dialog').querySelectorAll('.krow.on').forEach(function (r) { r.classList.remove('on'); }); return; }
    var c = ev.target.closest('[data-kcsv]'); if (!c) return;
    var dlg = c.closest('dialog'), rows = [];
    dlg.querySelectorAll('table tr').forEach(function (tr) { if (tr.hidden) return; var d = tr.dataset.d ? JSON.parse(tr.dataset.d) : null;
      rows.push(d ? [d.name, d.code, d['Staff type'], d.Designation, d.Company, d.Branch, d.Department, d.Team, d.Mobile, d.Email, d['Date of joining'], tr.lastElementChild.textContent.trim()]
        : (tr.parentNode.tagName === 'THEAD' && dlg.querySelector('.krow') ? ['Name', 'Code', 'Staff type', 'Designation', 'Company', 'Branch', 'Department', 'Team', 'Mobile', 'Email', 'Date of joining', 'Note'] : [].map.call(tr.children, function (t) { return t.textContent.trim(); }))); });
    var csv = rows.map(function (r) { return r.map(function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = c.dataset.kcsv; a.click();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && ev.target.classList && ev.target.classList.contains('krow')) show(ev.target); });
})();
