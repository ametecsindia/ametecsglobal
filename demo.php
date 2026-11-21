<?php
// AMETECS GLOBAL LIVE DEMO (owner, 21 Nov 2026: "View Demo ... Mobile, Desktop, both ... how the navigation works along with
// clickable options entirely ... a leave is applied from mobile, admin gets the notification on the desktop, approves, the
// employee gets 'your leave approved' ... implement similar for all other features").
// A self-contained simulation: one sample company held in the browser, drawn twice - the Ametecs Global web console in a
// desktop frame and the Ametecs Global phone app in a phone frame - so an action on one shows up on the other. Nothing is
// sent to a server and no real company's data is used. ?view=mobile|desktop|both, ?story=<id>.
// ponytail: hand-drawn screens that mirror the real ones; re-cut them when the real screens change a lot.
declare(strict_types=1);
$view = in_array($_GET['view'] ?? '', ['mobile', 'desktop', 'both'], true) ? $_GET['view'] : 'both';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Live demo · Ametecs Global</title>
<meta name="theme-color" content="#0f0b06">
<link rel="icon" href="core/assets/brand/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--ink:#17130c;--mute:#6f6350;--line:#ece3d1;--ivory:#faf6ee;--cream:#f7f3ea;--gold:#c9a24c;--goldhi:#e8c977;--g:linear-gradient(135deg,#f3dc95 0%,#d6ae55 45%,#b08433 100%);--dark:linear-gradient(170deg,#1d170e 0%,#0f0b06 60%,#0a0704 100%);--ok:#15803d;--bad:#b91c1c;--warn:#b45309;--blue:#2563eb}
*{box-sizing:border-box}body{margin:0;font-family:Inter,"Segoe UI",system-ui,sans-serif;color:var(--ink);background:var(--dark);min-height:100vh;-webkit-font-smoothing:antialiased}
button{font-family:inherit;cursor:pointer}a{color:inherit;text-decoration:none}
/* top bar */
.top{display:flex;align-items:center;gap:16px;padding:14px 24px;color:#fff;border-bottom:1px solid rgba(232,201,119,.15);flex-wrap:wrap}
.top .logo{display:flex;align-items:center;gap:10px;font-weight:600;font-size:17px}.top .logo b{color:var(--goldhi)}
.top .logo img{width:32px;height:32px;border-radius:9px}
.seg{display:flex;padding:4px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(232,201,119,.2)}
.seg button{border:0;background:transparent;color:#d9c9a3;padding:8px 14px;border-radius:9px;font-weight:600;font-size:13px;display:flex;gap:7px;align-items:center}
.seg button.on{background:var(--g);color:#14100a}
.top .sp{flex:1}.top a.back{color:#d9c9a3;font-size:13px;font-weight:600}
/* layout */
.main{display:grid;grid-template-columns:290px minmax(0,1fr);gap:20px;padding:20px 24px 30px;align-items:start}
@media (max-width:1100px){.main{grid-template-columns:1fr}}
.rail{position:sticky;top:16px;display:flex;flex-direction:column;gap:12px}
.card{background:rgba(255,255,255,.05);border:1px solid rgba(232,201,119,.18);border-radius:18px;padding:14px;color:#efe6d2}
.card h3{margin:0 0 10px;font-size:12px;letter-spacing:1.6px;color:var(--goldhi);font-weight:700}
.story{display:flex;gap:10px;align-items:flex-start;width:100%;text-align:left;border:1px solid transparent;background:transparent;color:#efe6d2;padding:9px 10px;border-radius:12px;font-size:13.5px}
.story i{color:var(--goldhi);width:18px;margin-top:2px}.story small{display:block;color:#a8997a;font-size:11.5px;margin-top:1px}
.story:hover{background:rgba(255,255,255,.05)}.story.on{background:rgba(232,201,119,.12);border-color:rgba(232,201,119,.35)}
.story .done{margin-left:auto;color:#4ade80;display:none}.story.fin .done{display:inline}
.narr .step{font-size:11px;color:var(--goldhi);font-weight:700;letter-spacing:1px}
.narr p{margin:6px 0 12px;font-size:14px;line-height:1.5;color:#fff}
.narr .who{display:inline-flex;gap:6px;align-items:center;font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px;background:rgba(232,201,119,.15);color:var(--goldhi);margin-top:6px}
.ctl{display:flex;gap:8px;flex-wrap:wrap}.ctl button{border:1px solid rgba(232,201,119,.35);background:transparent;color:#f3e7c9;padding:8px 12px;border-radius:10px;font-weight:600;font-size:12.5px;display:flex;gap:6px;align-items:center}
.ctl button.pri{background:var(--g);color:#14100a;border:0}.ctl button:disabled{opacity:.35;cursor:default}
.bar{height:4px;border-radius:2px;background:rgba(255,255,255,.1);overflow:hidden;margin-top:12px}.bar i{display:block;height:100%;background:var(--g);transition:width .3s}
.stage{display:flex;gap:26px;align-items:flex-start;justify-content:center;flex-wrap:wrap}
body.v-mobile .desk,body.v-desktop .phone{display:none}
/* desktop frame */
.desk{flex:1 1 720px;max-width:1040px;min-width:0;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 40px 80px -30px rgba(0,0,0,.8);border:1px solid #2a2112}
.chrome{display:flex;align-items:center;gap:8px;background:#ece7dc;padding:9px 12px}.chrome i.d{width:11px;height:11px;border-radius:50%;display:inline-block}
.url{flex:1;background:#fff;border-radius:8px;padding:6px 12px;font-size:12px;color:#6f6350;display:flex;gap:8px;align-items:center}
.app{display:grid;grid-template-columns:200px minmax(0,1fr);height:620px}
.side{background:linear-gradient(180deg,#14100a,#1d170e);color:#d9c9a3;padding:14px 10px;display:flex;flex-direction:column;gap:3px;font-size:13px}
.side .brand{display:flex;gap:9px;align-items:center;color:#fff;font-weight:600;padding:2px 6px 14px}.side .brand b{color:var(--goldhi)}.side .brand img{width:28px;height:28px;border-radius:8px}
.side button{display:flex;gap:10px;align-items:center;border:0;background:transparent;color:inherit;padding:9px 10px;border-radius:10px;text-align:left;font-size:13px;position:relative}
.side button i{width:16px;color:#b08433}.side button:hover{background:rgba(255,255,255,.05)}.side button.on{background:rgba(232,201,119,.14);color:#fff}
.side .cnt{margin-left:auto;background:var(--bad);color:#fff;font-size:10px;font-weight:700;border-radius:99px;padding:1px 7px}
.side .grp{font-size:10px;letter-spacing:1.4px;color:#7d6f55;padding:10px 10px 4px;font-weight:700}
.dmain{display:flex;flex-direction:column;min-width:0;background:var(--cream);position:relative}
.dtop{display:flex;align-items:center;gap:12px;padding:10px 18px;background:#fff;border-bottom:1px solid var(--line)}
.dtop .crumb{font-size:13px;color:var(--mute)}.dtop .crumb b{color:var(--ink)}
.dtop .srch{flex:1;max-width:280px;margin-left:auto;background:var(--cream);border-radius:10px;padding:7px 12px;font-size:12px;color:var(--mute)}
.bell{position:relative;width:38px;height:38px;border-radius:50%;border:1px solid var(--line);background:#fff;display:grid;place-items:center;color:var(--ink)}
.bell .b{position:absolute;top:-3px;right:-3px;background:var(--bad);color:#fff;font-size:10px;font-weight:700;border-radius:99px;padding:1px 6px;border:2px solid #fff}
.av{width:36px;height:36px;border-radius:50%;background:var(--g);color:#14100a;display:grid;place-items:center;font-weight:800;font-size:13px}
.dbody{padding:18px;overflow:auto;flex:1}
.dbody h2{margin:0 0 4px;font-size:20px}.dbody .sub{color:var(--mute);font-size:13px;margin-bottom:16px}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 14px}.kpi b{display:block;font-size:24px}.kpi span{font-size:12px;color:var(--mute)}
.kpi.dark{background:linear-gradient(135deg,#0b0906,#2a2112);color:#fff;border:0}.kpi.dark b{color:var(--goldhi)}.kpi.dark span{color:#bfae86}
.tbl{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;font-size:13px}
.tbl th{text-align:left;font-size:11px;letter-spacing:.8px;color:var(--mute);padding:10px 12px;background:#fbf8f1;border-bottom:1px solid var(--line)}
.tbl td{padding:10px 12px;border-bottom:1px solid #f3eddf}.tbl tr.new td{animation:flash 1.6s ease}
@keyframes flash{0%{background:#fff3c4}100%{background:transparent}}
.pill{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px}
.p-ok{background:#dcfce7;color:var(--ok)}.p-bad{background:#fee2e2;color:var(--bad)}.p-warn{background:#fef3c7;color:var(--warn)}.p-mute{background:#f1eadb;color:var(--mute)}.p-blue{background:#dbeafe;color:var(--blue)}
.dbtn{border:0;border-radius:9px;padding:7px 12px;font-weight:700;font-size:12px}.dbtn.ok{background:var(--ok);color:#fff}.dbtn.bad{background:#fff;border:1px solid #fca5a5;color:var(--bad)}.dbtn.gold{background:var(--g);color:#14100a}.dbtn.dark{background:#17130c;color:var(--goldhi)}
.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:14px}
.feed div{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f3eddf;font-size:13px}.feed i{color:#b08433;width:16px;margin-top:2px}.feed small{color:var(--mute);margin-left:auto;white-space:nowrap}
.dd{position:absolute;right:60px;top:54px;width:330px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 20px 40px -16px rgba(0,0,0,.3);z-index:5;overflow:hidden}
.dd .it{display:flex;gap:10px;padding:12px 14px;border-bottom:1px solid #f3eddf;font-size:13px;text-align:left;width:100%;border-left:0;border-right:0;border-top:0;background:#fff}
.dd .it.un{background:#fffbf0}.dd .it i{color:#b08433;margin-top:2px}.dd .it small{display:block;color:var(--mute);font-size:11.5px}
.dtoast{position:absolute;right:16px;bottom:16px;background:#17130c;color:#fff;border-radius:14px;padding:12px 14px;display:flex;gap:10px;align-items:center;font-size:13px;box-shadow:0 20px 40px -14px rgba(0,0,0,.5);animation:up .35s ease;z-index:6;max-width:320px}
.dtoast i{color:var(--goldhi)}
@keyframes up{from{transform:translateY(20px);opacity:0}to{transform:none;opacity:1}}
.map{height:220px;border-radius:14px;background:repeating-linear-gradient(0deg,#efe8d8 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,#efe8d8 0 1px,transparent 1px 34px),#f8f4ea;position:relative;border:1px solid var(--line);margin-bottom:14px}
.pin{position:absolute;transform:translate(-50%,-100%);color:var(--bad);font-size:24px;text-align:center}.pin small{display:block;font-size:10px;color:var(--ink);background:#fff;border-radius:6px;padding:1px 5px;font-weight:700;white-space:nowrap}
.fld{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;font-size:12px;color:var(--mute)}.fld input,.fld textarea{font:inherit;font-size:13px;padding:9px 11px;border:1px solid var(--line);border-radius:10px;color:var(--ink)}
/* phone frame */
.phone{flex:0 0 360px;width:360px;height:740px;border-radius:46px;background:#0b0906;padding:12px;box-shadow:0 40px 80px -30px rgba(0,0,0,.9),inset 0 0 0 2px #3a2c12}
.scr{position:relative;width:100%;height:100%;border-radius:36px;overflow:hidden;background:var(--cream);display:flex;flex-direction:column;font-size:13px}
.notch{position:absolute;top:8px;left:50%;transform:translateX(-50%);width:96px;height:24px;border-radius:14px;background:#0b0906;z-index:9}
.sb{height:34px;display:flex;justify-content:space-between;align-items:center;padding:0 22px;color:#fff;font-size:11.5px;font-weight:600;background:#0b0906}
.ph{display:flex;align-items:center;gap:9px;padding:8px 12px 10px;background:linear-gradient(90deg,#0b0906,#14100a,#2a2112);color:#fff}
.ph .av{width:34px;height:34px;font-size:12px}.ph .nm{flex:1;min-width:0}.ph .nm small{display:block;color:#bfae86;font-size:10px}.ph .nm b{font-size:14px}
.ph .tm{text-align:right;color:var(--goldhi);font-weight:700;font-size:13px}.ph .tm small{display:block;color:#bfae86;font-size:9px;font-weight:500}
.ph .pbell{position:relative;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.08);border:0;color:var(--goldhi);display:grid;place-items:center}
.ph .pbell .b{position:absolute;top:-2px;right:-2px;background:var(--bad);color:#fff;font-size:9px;font-weight:700;border-radius:99px;padding:1px 5px}
.pt{display:flex;align-items:center;gap:6px;padding:6px 8px;background:#2a2112;color:#fff;font-weight:600;font-size:14px}.pt button{border:0;background:transparent;color:var(--goldhi);width:30px;height:30px;border-radius:50%}
.pbody{flex:1;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:10px}
.pc{background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px}
.pdark{background:linear-gradient(135deg,#0b0906,#14100a,#2a2112);color:#fff;border-radius:18px;padding:14px}
.pbtn{border:0;border-radius:14px;padding:13px;font-weight:800;font-size:13.5px;background:var(--g);color:#14100a;display:flex;align-items:center;justify-content:center;gap:9px;width:100%}
.pbtn.dark{background:#17130c;color:var(--goldhi)}.pbtn.red{background:var(--bad);color:#fff}.pbtn.line{background:#fff;border:1px solid var(--line);color:var(--ink)}
.qa{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px}.qa button{border:0;background:transparent;display:flex;flex-direction:column;align-items:center;gap:5px;font-size:10.5px;font-weight:600;color:var(--ink)}
.qa button span{width:44px;height:44px;border-radius:14px;background:#fff;border:1px solid var(--line);display:grid;place-items:center;color:#b08433;font-size:16px}
.row{display:flex;align-items:center;gap:10px}.row .gr{flex:1;min-width:0}.muted{color:var(--mute);font-size:11.5px}
.chips{display:flex;gap:6px;flex-wrap:wrap}.chip{border:1px solid var(--line);background:#fff;border-radius:99px;padding:6px 11px;font-size:12px;font-weight:600}.chip.on{background:var(--gold);border-color:var(--gold);color:#14100a}
.pf{background:#fff;border-top:1px solid var(--line)}.pf .nav{display:grid;grid-template-columns:repeat(5,1fr);height:58px;align-items:center}
.pf .nav button{border:0;background:transparent;display:flex;flex-direction:column;align-items:center;gap:2px;font-size:10px;color:var(--mute);font-weight:600}.pf .nav button i{font-size:17px}
.pf .nav button.on{color:var(--ink)}.pf .nav button.on i{color:#b08433}
.pf .nav .punch span{width:52px;height:52px;margin-top:-26px;border-radius:50%;background:var(--g);border:4px solid #fff;display:grid;place-items:center;color:#14100a;font-size:20px;box-shadow:0 8px 18px -8px rgba(176,132,51,.9)}
.pf .pw{border-top:1px solid var(--line);text-align:center;font-size:10px;color:var(--mute);padding:4px 0 8px}.pf .pw b{color:var(--ink)}
.push{position:absolute;left:10px;right:10px;top:40px;background:rgba(23,19,12,.96);color:#fff;border-radius:16px;padding:10px 12px;display:flex;gap:10px;align-items:center;z-index:8;animation:down .35s ease;box-shadow:0 14px 30px -10px rgba(0,0,0,.6)}
.push img{width:30px;height:30px;border-radius:8px}.push small{display:block;color:#d9c9a3;font-size:11px}
@keyframes down{from{transform:translateY(-30px);opacity:0}to{transform:none;opacity:1}}
.modal{position:absolute;inset:0;background:rgba(10,8,5,.55);display:grid;place-items:center;z-index:7;padding:20px}
.modal .box{background:#fff;border-radius:20px;padding:18px;width:100%;text-align:center}
.scan{position:absolute;inset:0;background:#0b0906;z-index:7;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;gap:14px}
.scan .vf{width:200px;height:200px;border:3px solid var(--goldhi);border-radius:24px;position:relative;display:grid;place-items:center;font-size:90px;color:#fff;background:#1d170e}
.scan .vf:after{content:"";position:absolute;left:10px;right:10px;height:3px;background:var(--goldhi);top:20px;animation:sweep 1.4s ease-in-out infinite alternate;box-shadow:0 0 12px var(--goldhi)}
@keyframes sweep{to{top:176px}}
/* guide */
.hint{outline:3px solid var(--gold)!important;outline-offset:3px;animation:pulse 1.2s ease-in-out infinite;position:relative;z-index:4}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(201,162,76,.7)}50%{box-shadow:0 0 0 10px rgba(201,162,76,0)}}
.lbl{display:block;text-align:center;color:#d9c9a3;font-size:12px;margin:8px 0 0;font-weight:600}
.mute-note{color:#a8997a;font-size:12px;margin-top:8px}
</style></head>
<body class="v-<?= $view ?>">
<div class="top">
  <a class="logo" href="./"><img src="core/assets/brand/ag-mark.svg" alt=""><span>Ametecs <b>Global</b> · Live demo</span></a>
  <div class="seg" role="group" aria-label="What to show">
    <button data-view="mobile"><i class="fas fa-mobile-screen"></i>Mobile</button>
    <button data-view="desktop"><i class="fas fa-desktop"></i>Desktop</button>
    <button data-view="both"><i class="fas fa-table-columns"></i>Both</button>
  </div>
  <span class="sp"></span>
  <a class="back" href="./#contact"><i class="fas fa-calendar-check"></i> Book a real demo</a>
  <a class="back" href="./"><i class="fas fa-arrow-left"></i> Back to site</a>
</div>
<div class="main">
  <aside class="rail">
    <div class="card"><h3>PICK A STORY</h3><div id="stories"></div></div>
    <div class="card narr" id="narr"></div>
    <div class="card"><h3>SAMPLE COMPANY</h3><div style="font-size:12.5px;line-height:1.6;color:#d9c9a3">Everything here is a sample: <b style="color:#fff">Ametecs India</b>, employee <b style="color:#fff">Aarav Khan</b> on the phone and HR manager <b style="color:#fff">Karthik Rao</b> on the desktop. Click anything; nothing is saved.</div>
      <div class="ctl" style="margin-top:10px"><button id="reset"><i class="fas fa-rotate-left"></i>Reset sample data</button></div></div>
  </aside>
  <div class="stage">
    <div><div class="desk" id="desk"></div><span class="lbl desk-l"><i class="fas fa-desktop"></i> HR manager · Ametecs Global web</span></div>
    <div><div class="phone"><div class="scr" id="phone"></div></div><span class="lbl"><i class="fas fa-mobile-screen"></i> Employee · Ametecs Global app</span></div>
  </div>
</div>
<script>
'use strict';
const MARK = 'core/assets/brand/ag-mark.svg';
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
const pad = n => String(n).padStart(2, '0');
const fmt = m => { const h = Math.floor(m / 60), mm = m % 60, ap = h >= 12 ? 'PM' : 'AM'; return pad(((h + 11) % 12) + 1) + ':' + pad(mm) + ' ' + ap; };
const hm = m => pad(Math.floor(m / 60)) + ':' + pad(m % 60);

// ---------- the sample company (one state, drawn on both screens) ----------
let S;
function fresh() {
  return {
    clock: 9 * 60 + 24, day: 'Mon, 24-11-2026',
    me: {name: 'Aarav Khan', code: 'SMP041', role: 'Collections Executive', lv: {CL: 6, SL: 4, EL: 12}},
    hr: {name: 'Karthik Rao', role: 'HR Manager'},
    punch: {in: null, out: null},
    team: [
      {name: 'Aarav Khan', code: 'SMP041', dept: 'Collections', st: 'absent', in: null},
      {name: 'Priya Sharma', code: 'SMP012', dept: 'Collections', st: 'in', in: '09:02'},
      {name: 'Rahul Verma', code: 'SMP018', dept: 'Sales', st: 'in', in: '09:41', late: true},
      {name: 'Sneha Reddy', code: 'SMP023', dept: 'Accounts', st: 'leave', in: null},
      {name: 'Imran Shaikh', code: 'SMP030', dept: 'Field', st: 'in', in: '08:55'},
      {name: 'Divya Nair', code: 'SMP036', dept: 'HR', st: 'absent', in: null},
    ],
    leaves: [], corr: [], notices: [], visits: [], resign: null, nov: 'draft',
    slips: [{p: 'October 2026', net: '₹38,450', days: '31 / 31'}],
    pn: [], dn: [{t: 'Payroll for November is ready to review', i: 'fa-money-check-dollar', at: '09:10', un: false}],
    activity: [{t: 'Priya Sharma punched in (QR gate)', at: '09:02'}, {t: 'Imran Shaikh started field day', at: '08:55'}],
    phone: 'home', pdlg: null, ppush: null, desk: 'dash', dd: false, dtoast: null, newRow: null,
    form: {type: 'CL', reason: 'Family function', corr: 'Forgot to punch out on Friday', notice: 'Office closed on 28-11 for maintenance', resign: 'Moving to another city'},
  };
}
function tick(n = 1) { S.clock += n; }
function pnote(t, i, page) { S.pn.unshift({t, i, at: hm(S.clock), un: true, page}); S.ppush = {t, i}; }
function dnote(t, i, page) { S.dn.unshift({t, i, at: hm(S.clock), un: true, page}); S.dtoast = {t, i}; S.activity.unshift({t, at: hm(S.clock)}); }

// ---------- every action (both screens call these; the guide checks which ran) ----------
const A = {
  'p:go': p => { S.phone = p; S.pdlg = null; },
  'p:bell': () => { S.phone = 'alerts'; S.pn.forEach(n => n.un = false); },
  'p:punch': () => { S.pdlg = 'scan'; },
  'p:scanned': () => {
    tick(2); S.pdlg = 'punched';
    if (!S.punch.in) { S.punch.in = hm(S.clock); const t = S.team[0]; t.st = 'in'; t.in = S.punch.in; S.newRow = 'SMP041';
      dnote('Aarav Khan punched in at ' + fmt(S.clock) + ' · QR · Head office gate', 'fa-qrcode', 'att'); }
    else { S.punch.out = hm(S.clock); S.team[0].st = 'out'; dnote('Aarav Khan punched out at ' + fmt(S.clock), 'fa-qrcode', 'att'); }
  },
  'p:close': () => { S.pdlg = null; },
  'p:leavetype': t => { S.form.type = t; },
  'p:leavesubmit': () => {
    tick(3); const id = 'LV' + (S.leaves.length + 101);
    S.leaves.unshift({id, who: 'Aarav Khan', type: S.form.type, from: '27-11-2026', to: '28-11-2026', days: 2, reason: S.form.reason, st: 'pending'});
    S.phone = 'leave'; S.newRow = id;
    dnote('Leave request from Aarav Khan · ' + S.form.type + ' · 27–28 Nov', 'fa-umbrella-beach', 'leave');
  },
  'd:go': p => { S.desk = p; S.dd = false; },
  'd:bell': () => { S.dd = !S.dd; },
  'd:open': i => { const n = S.dn[i]; n.un = false; S.dd = false; if (n.page) S.desk = n.page; },
  'd:leave': ([id, ok]) => {
    tick(2); const l = S.leaves.find(x => x.id === id); if (!l || l.st !== 'pending') return;
    l.st = ok ? 'approved' : 'rejected'; if (ok) S.me.lv[l.type] -= l.days;
    S.activity.unshift({t: (ok ? 'Approved' : 'Rejected') + ' leave of Aarav Khan', at: hm(S.clock)});
    pnote(ok ? 'Your leave for 27–28 Nov is approved by Karthik Rao' : 'Your leave for 27–28 Nov was not approved', ok ? 'fa-circle-check' : 'fa-circle-xmark', 'leave');
  },
  'p:corrsubmit': () => {
    tick(2); const id = 'RG' + (S.corr.length + 51);
    S.corr.unshift({id, who: 'Aarav Khan', date: '21-11-2026', dir: 'OUT', at: '18:42', reason: S.form.corr, st: 'pending'});
    S.phone = 'att'; S.newRow = id;
    dnote('Correction request from Aarav Khan · OUT 18:42 on 21-11', 'fa-calendar-pen', 'corr');
  },
  'd:corr': ([id, ok]) => {
    tick(2); const c = S.corr.find(x => x.id === id); if (!c || c.st !== 'pending') return; c.st = ok ? 'approved' : 'rejected';
    S.activity.unshift({t: (ok ? 'Approved' : 'Rejected') + ' correction of Aarav Khan', at: hm(S.clock)});
    pnote(ok ? 'Your correction for 21-11 is approved: out 18:42 added' : 'Your correction for 21-11 was not approved', ok ? 'fa-circle-check' : 'fa-circle-xmark', 'att');
  },
  'd:publish': () => {
    tick(3); S.nov = 'published'; S.slips.unshift({p: 'November 2026', net: '₹39,120', days: '30 / 30', fresh: true});
    S.activity.unshift({t: 'November payroll approved and payslips published (42 employees)', at: hm(S.clock)});
    pnote('Your payslip for November 2026 is ready', 'fa-money-check-dollar', 'slips');
  },
  'd:notice': () => {
    tick(2); S.notices.unshift({t: S.form.notice, at: S.day}); S.activity.unshift({t: 'Notice posted: ' + S.form.notice, at: hm(S.clock)});
    pnote('Notice from HR: ' + S.form.notice, 'fa-bullhorn', 'alerts');
  },
  'p:resignask': () => { S.pdlg = 'resign'; },
  'p:resignno': () => { S.pdlg = null; },
  'p:resignyes': () => {
    tick(2); S.pdlg = null; S.resign = {st: 'notice', ends: '23-01-2027', note: S.form.resign};
    dnote('Resignation from Aarav Khan · notice ends 23-01-2027', 'fa-door-open', 'resign');
  },
  'd:resign': ok => {
    tick(2); if (!S.resign) return; S.resign.st = ok ? 'accepted' : 'notice';
    S.activity.unshift({t: 'Resignation of Aarav Khan accepted; exit clearance started', at: hm(S.clock)});
    pnote('Your resignation is accepted. Last day 23-01-2027. Exit clearance has started.', 'fa-file-signature', 'profile');
  },
  'p:visit': () => {
    tick(4); const v = {who: 'Aarav Khan', cust: 'Sri Lakshmi Traders', area: 'Madhapur', at: hm(S.clock), out: 'Promise to pay ₹25,000 on 30-11'};
    S.visits.unshift(v); S.newRow = 'visit';
    dnote('Aarav Khan visited Sri Lakshmi Traders · Promise to pay ₹25,000', 'fa-location-dot', 'field');
  },
};

// ---------- the guided stories ----------
const STORIES = [
  {id: 'leave', i: 'fa-umbrella-beach', t: 'Leave: apply, approve, notified', s: [
    ['p', 'The employee opens <b>Leave</b> from the footer of the Ametecs Global app.', 'p:go', 'leave'],
    ['p', 'Balances are live. Tap <b>Apply for leave</b>.', 'p:go', 'leaveapply'],
    ['p', 'Pick the type, the dates and the reason are filled in for the demo. Tap <b>Submit request</b>.', 'p:leavesubmit'],
    ['d', 'On the HR manager\'s desktop the bell lights up at once. Click the <b>bell</b>.', 'd:bell'],
    ['d', 'Click the <b>leave request</b> to open Leave approvals.', 'd:open', 0],
    ['d', 'Review the request and click <b>Approve</b>. The leave ledger is debited.', 'd:leave', ['LV101', true]],
    ['p', 'Back on the phone, the employee gets <b>“Your leave is approved”</b>. Tap the bell to see it.', 'p:bell'],
    ['p', 'Open <b>Leave</b> again: the request shows Approved and the CL balance has dropped by 2.', 'p:go', 'leave'],
  ]},
  {id: 'punch', i: 'fa-qrcode', t: 'QR punch-in to live attendance', s: [
    ['p', 'At the office gate the employee taps the gold <b>Punch</b> button.', 'p:punch'],
    ['p', 'The camera reads the gate\'s live QR code, checks location and the phone. Tap <b>Scan</b> to simulate it.', 'p:scanned'],
    ['p', 'Recorded. Tap <b>Done</b>.', 'p:close'],
    ['d', 'The punch reaches every app at once. Open <b>Live attendance</b> on the desktop.', 'd:go', 'att'],
    ['d', 'Aarav now shows <b>In</b> with the time and source, and the dashboard counts update. Open <b>Dashboard</b>.', 'd:go', 'dash'],
  ]},
  {id: 'corr', i: 'fa-calendar-pen', t: 'Missed punch: correction approved', s: [
    ['p', 'The employee forgot to punch out on Friday. Open <b>Attendance</b>.', 'p:go', 'att'],
    ['p', 'Tap <b>Request a correction</b>.', 'p:go', 'corrform'],
    ['p', 'Time and reason are filled in. Tap <b>Send for approval</b>.', 'p:corrsubmit'],
    ['d', 'The manager is notified. Click the <b>bell</b>.', 'd:bell'],
    ['d', 'Open the <b>correction request</b>.', 'd:open', 0],
    ['d', 'Click <b>Approve</b>: the punch is added to the muster and payroll reads it.', 'd:corr', ['RG51', true]],
    ['p', 'The employee is told it is approved. Tap the <b>bell</b>.', 'p:bell'],
  ]},
  {id: 'pay', i: 'fa-money-check-dollar', t: 'Payroll run to payslip on phone', s: [
    ['d', 'HR opens <b>Payroll</b> on the desktop.', 'd:go', 'pay'],
    ['d', 'November is checked. Click <b>Approve &amp; publish payslips</b>.', 'd:publish'],
    ['p', 'Every employee gets <b>“Your payslip is ready”</b>. Open <b>Payslips</b> from Home.', 'p:go', 'slips'],
    ['p', 'Tap <b>November 2026</b> to see earnings, deductions and net pay.', 'p:go', 'slip'],
  ]},
  {id: 'notice', i: 'fa-bullhorn', t: 'Company notice to every phone', s: [
    ['d', 'HR opens <b>Notices</b>.', 'd:go', 'notice'],
    ['d', 'The notice is typed in. Click <b>Post to everyone</b>.', 'd:notice'],
    ['p', 'It lands on every employee\'s phone. Tap the <b>bell</b> to read it.', 'p:bell'],
  ]},
  {id: 'resign', i: 'fa-door-open', t: 'Resignation with confirmation', s: [
    ['p', 'Open the employee\'s <b>profile</b> (tap the name at the top).', 'p:go', 'profile'],
    ['p', 'At the bottom, tap <b>Initiate resignation</b>.', 'p:resignask'],
    ['p', 'The app asks first. Tap <b>Yes</b> (No would change nothing).', 'p:resignyes'],
    ['d', 'HR is notified. Click the <b>bell</b>.', 'd:bell'],
    ['d', 'Open the <b>resignation</b>.', 'd:open', 0],
    ['d', 'Click <b>Accept &amp; start clearance</b>.', 'd:resign', true],
    ['p', 'The employee sees the decision and the last day. Tap the <b>bell</b>.', 'p:bell'],
  ]},
  {id: 'field', i: 'fa-location-dot', t: 'Field visit on the live map', s: [
    ['p', 'Out in the field, open <b>Field</b> from the footer.', 'p:go', 'field'],
    ['p', 'At the customer, tap <b>Check in &amp; log visit</b>. Location and outcome are recorded.', 'p:visit'],
    ['d', 'The office sees it live. Open <b>Field force</b>.', 'd:go', 'field'],
  ]},
];

let cur = null, stepI = 0, auto = null, done = new Set();
function startStory(id) {
  S = fresh(); cur = STORIES.find(s => s.id === id); stepI = 0; stopAuto(); render();
}
function stepNow() { return cur && cur.s[stepI]; }
function sideShown(side) { const v = document.body.className.replace('v-', ''); return v === 'both' || (side === 'p' ? v === 'mobile' : v === 'desktop'); }
function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
function run(name, arg, fromGuide) {
  const f = A[name]; if (!f) return;
  const st = stepNow();
  f(arg);
  if (st && st[2] === name && (st.length < 4 || same(st[3], arg))) { stepI++; if (stepI >= cur.s.length) { done.add(cur.id); } }
  render();
  if (S.ppush) setTimeout(() => { S.ppush = null; drawPhone(); hint(); }, 3200);
  if (S.dtoast) setTimeout(() => { S.dtoast = null; drawDesk(); hint(); }, 3600);
}
function doStep() { const st = stepNow(); if (st) run(st[2], st[3], true); }
function stopAuto() { if (auto) clearInterval(auto); auto = null; }
function back() { if (!cur || stepI === 0) return; const k = stepI - 1; S = fresh(); stepI = 0; for (let i = 0; i < k; i++) { const st = cur.s[i]; A[st[2]](st[3]); stepI++; } S.ppush = S.dtoast = null; render(); }

// ---------- the phone (Ametecs Global app) ----------
function drawPhone() {
  const p = S.phone, me = S.me, un = S.pn.filter(n => n.un).length;
  const top = {leave: 'Leave', leaveapply: 'Apply for leave', att: 'Attendance', corrform: 'Request a correction', alerts: 'Notifications', slips: 'Payslips', slip: 'Payslip', profile: 'My profile', field: 'Field work'}[p];
  let b = '';
  if (p === 'home') {
    const isIn = S.punch.in && !S.punch.out;
    b = `<div class="pdark row"><div style="width:72px;height:72px;border-radius:50%;background:conic-gradient(#e8c977 0 84%,rgba(255,255,255,.1) 84%);display:grid;place-items:center"><div style="width:58px;height:58px;border-radius:50%;background:#14100a;display:grid;place-items:center;font-weight:800;font-size:20px;color:#e8c977">84</div></div>
      <div class="gr"><div style="font-size:10px;letter-spacing:1px;color:#bfae86">ATTENDANCE SCORE</div><b style="font-size:13px">On time, every day, moves this up.</b><div style="font-size:11px;color:#d9c9a3">14 present · 2 late · 0 absent</div></div></div>
      <button class="pbtn${isIn ? ' dark' : ''}" data-a="p:punch"><i class="fas fa-qrcode"></i>${isIn ? 'Punch out' : 'Punch in'}</button>
      <div class="pc row" style="justify-content:space-around;text-align:center"><div><div class="muted">Check in</div><b style="font-size:16px">${S.punch.in || '—'}</b></div><div><div class="muted">Check out</div><b style="font-size:16px">${S.punch.out || '—'}</b></div><div><div class="muted">Streak</div><b style="font-size:16px">🔥 4</b></div></div>
      <div class="qa"><button data-a="p:go" data-v="leaveapply"><span><i class="fas fa-calendar-plus"></i></span>Apply leave</button><button data-a="p:go" data-v="corrform"><span><i class="fas fa-calendar-pen"></i></span>Correction</button><button data-a="p:go" data-v="slips"><span><i class="fas fa-money-check-dollar"></i></span>Payslips</button><button data-a="p:go" data-v="profile"><span><i class="fas fa-user"></i></span>Profile</button></div>
      <div class="pc"><b>Badges this month</b><div class="row" style="justify-content:space-around;margin-top:8px;text-align:center;font-size:10px">${[['fa-star', 'Perfect week', 1], ['fa-sun', 'Early bird', 1], ['fa-bolt', 'Zero late', 0], ['fa-trophy', 'Full month', 0]].map(([i, n, on]) => `<div><div style="width:42px;height:42px;border-radius:50%;margin:0 auto 4px;display:grid;place-items:center;${on ? 'background:var(--g);color:#14100a' : 'background:#f1eadb;color:#b8aa8a'}"><i class="fas ${on ? i : 'fa-lock'}"></i></div>${n}</div>`).join('')}</div></div>`;
  } else if (p === 'leave') {
    b = `<div class="row" style="gap:8px">${Object.entries(me.lv).map(([k, v]) => `<div class="pc" style="flex:1;text-align:center;padding:10px"><b style="font-size:22px">${v}</b><div class="muted">${{CL: 'Casual', SL: 'Sick', EL: 'Earned'}[k]}</div></div>`).join('')}</div>
      <button class="pbtn" data-a="p:go" data-v="leaveapply"><i class="fas fa-calendar-plus"></i>Apply for leave</button>
      <b style="font-size:12px;color:var(--mute)">MY REQUESTS</b>
      ${S.leaves.length ? S.leaves.map(l => `<div class="pc"><div class="row"><b class="gr">${l.type === 'CL' ? 'Casual leave' : l.type === 'SL' ? 'Sick leave' : 'Earned leave'}</b>${pill(l.st)}</div><div>${l.from} → ${l.to} · ${l.days} days</div><div class="muted">${esc(l.reason)}${l.st === 'approved' ? ' · by Karthik Rao' : ''}</div></div>`).join('') : '<div class="muted">No requests yet.</div>'}`;
  } else if (p === 'leaveapply') {
    b = `<b style="font-size:12px;color:var(--mute)">LEAVE TYPE</b><div class="chips">${['CL', 'SL', 'EL'].map(t => `<button class="chip${S.form.type === t ? ' on' : ''}" data-a="p:leavetype" data-v="${t}">${{CL: 'Casual', SL: 'Sick', EL: 'Earned'}[t]} (${me.lv[t]})</button>`).join('')}</div>
      <div class="row" style="gap:8px"><div class="pc gr"><div class="muted">From</div><b>27-11-2026</b></div><div class="pc gr"><div class="muted">To</div><b>28-11-2026</b></div></div>
      <div class="pc"><div class="muted">Reason</div><b>${esc(S.form.reason)}</b></div>
      <button class="pbtn" data-a="p:leavesubmit"><i class="fas fa-paper-plane"></i>Submit request</button>`;
  } else if (p === 'att') {
    const rows = [['24', 'Mon', S.punch.in ? (S.punch.out ? 'Present' : 'In') : 'Today', S.punch.in, S.punch.out], ['21', 'Fri', S.corr.some(c => c.st === 'approved') ? 'Present' : 'Needs fix', '09:18', S.corr.some(c => c.st === 'approved') ? '18:42' : null], ['20', 'Thu', 'Present', '09:12', '18:35'], ['19', 'Wed', 'Late', '09:47', '18:50']];
    b = `<div class="pdark"><div style="font-size:11px;color:#bfae86">Worked this month</div><b style="font-size:24px;color:#e8c977">118h 40m</b><div style="font-size:11px;color:#d9c9a3">14 present · 2 late · shift 09:30 – 18:30</div></div>
      <button class="pbtn line" data-a="p:go" data-v="corrform"><i class="fas fa-calendar-pen" style="color:#b45309"></i>Request a correction</button>
      ${rows.map(([d, w, st, i, o]) => `<div class="pc row"><div style="width:40px;height:40px;border-radius:12px;background:#f6edd8;display:grid;place-items:center;text-align:center;line-height:1"><b>${d}</b><small style="font-size:9px">${w}</small></div><div class="gr">In ${i || '—'} · Out ${o || '—'}</div>${pill(st === 'Needs fix' ? 'fix' : st === 'Late' ? 'late' : st === 'Today' ? 'mute' : 'approved', st)}</div>`).join('')}`;
  } else if (p === 'corrform') {
    b = `<div class="pc"><div class="muted">Date</div><b>21-11-2026 (Friday)</b></div><div class="row" style="gap:8px"><div class="pc gr"><div class="muted">Check in</div><b>—</b></div><div class="pc gr"><div class="muted">Check out</div><b>18:42</b></div></div>
      <div class="pc"><div class="muted">Reason</div><b>${esc(S.form.corr)}</b></div><button class="pbtn" data-a="p:corrsubmit"><i class="fas fa-paper-plane"></i>Send for approval</button>`;
  } else if (p === 'alerts') {
    b = S.pn.length ? S.pn.map(n => `<div class="pc row" style="align-items:flex-start;${n.un ? 'background:#fffbf0' : ''}"><i class="fas ${n.i}" style="color:#b08433;margin-top:3px"></i><div class="gr"><b>${esc(n.t)}</b><div class="muted">Today ${n.at}</div></div></div>`).join('') : '<div class="muted" style="text-align:center;margin-top:30px">You\'re all caught up.</div>';
  } else if (p === 'slips') {
    b = S.slips.map((s, k) => `<button class="pc row" style="text-align:left;width:100%;${s.fresh ? 'border-color:#c9a24c' : ''}" data-a="p:go" data-v="slip"><div class="gr"><b style="font-size:14px">${s.p}</b><div class="muted">${s.days} days paid${s.fresh ? ' · NEW' : ''}</div></div><b style="font-size:16px">${s.net}</b></button>`).join('');
  } else if (p === 'slip') {
    const s = S.slips[0];
    b = `<div class="pdark"><div style="color:#d9c9a3">${s.p}</div><b style="font-size:28px;color:#e8c977">${s.net}</b><div style="font-size:11px;color:#d9c9a3">Net pay · ${s.days} days</div></div>
      <div class="pc"><div class="row"><b class="gr">Earnings</b><b>₹44,000</b></div>${[['Basic', '22,000'], ['HRA', '11,000'], ['Special allowance', '11,000']].map(([k, v]) => `<div class="row muted" style="padding:3px 0"><span class="gr">${k}</span>₹${v}</div>`).join('')}</div>
      <div class="pc"><div class="row"><b class="gr">Deductions</b><b>₹${s.p.startsWith('Nov') ? '4,880' : '5,550'}</b></div>${[['PF', '2,640'], ['Professional tax', '200'], ['TDS', s.p.startsWith('Nov') ? '2,040' : '2,710']].map(([k, v]) => `<div class="row muted" style="padding:3px 0"><span class="gr">${k}</span>₹${v}</div>`).join('')}</div>`;
  } else if (p === 'profile') {
    const r = S.resign;
    b = `<div class="pdark" style="text-align:center"><div style="width:70px;height:70px;border-radius:50%;margin:0 auto;background:var(--g);display:grid;place-items:center;font-size:24px;font-weight:800;color:#14100a">AK</div><b style="display:block;font-size:17px;margin-top:8px">${me.name}</b><div style="font-size:11px;color:#d9c9a3">${me.role} · ${me.code} · 3 yr 2 mo here</div></div>
      <div class="pc">${[['Department', 'Collections'], ['Branch', 'Hyderabad - Kondapur'], ['Reports to', 'Karthik Rao'], ['Blood group <span class="pill p-warn" style="font-size:9px">Custom</span>', 'B+']].map(([k, v]) => `<div style="padding:5px 0;border-bottom:1px solid #f3eddf"><div class="muted">${k}</div><b>${v}</b></div>`).join('')}</div>
      <div class="pc"><b>Resignation</b><div class="muted" style="margin:4px 0 10px">${r ? (r.st === 'accepted' ? 'Accepted · last day ' + r.ends : 'Submitted · notice ends ' + r.ends) : 'No resignation on record. Notice period 60 days.'}</div>
      <div class="row"><button class="pbtn${r ? ' line' : ' red'}" style="padding:10px;font-size:12px" data-a="p:resignask" ${r ? 'disabled' : ''}>Initiate resignation</button><button class="pbtn line" style="padding:10px;font-size:12px" disabled>Revoke resignation</button></div></div>`;
  } else if (p === 'field') {
    b = `<div class="pdark"><div style="font-size:11px;color:#bfae86">Today's route</div><b style="font-size:18px">3 visits · 2 calls due</b></div>
      <div class="pc row"><i class="fas fa-store" style="color:#b08433"></i><div class="gr"><b>Sri Lakshmi Traders</b><div class="muted">Madhapur · ₹62,400 due</div></div>${S.visits.length ? pill('approved', 'Visited') : ''}</div>
      ${S.visits.length ? `<div class="pc"><b>Visit logged ${S.visits[0].at}</b><div class="muted">${S.visits[0].out} · location verified</div></div>` : '<button class="pbtn" data-a="p:visit"><i class="fas fa-location-dot"></i>Check in &amp; log visit</button>'}
      <div class="pc row"><i class="fas fa-store" style="color:#b08433"></i><div class="gr"><b>Venkat Agencies</b><div class="muted">Kukatpally · follow-up 4 PM</div></div></div>`;
  }
  const sheet = S.pdlg === 'scan' ? `<div class="scan"><b>Point at the QR code on the gate</b><div class="vf"><i class="fas fa-qrcode"></i></div><button class="pbtn" style="width:200px" data-a="p:scanned"><i class="fas fa-expand"></i>Scan</button><button class="chip" data-a="p:close" style="background:transparent;color:#d9c9a3;border-color:#5a4a2a">Cancel</button></div>`
    : S.pdlg === 'punched' ? `<div class="modal"><div class="box"><div style="width:64px;height:64px;border-radius:50%;margin:0 auto;background:#dcfce7;display:grid;place-items:center;color:var(--ok);font-size:30px"><i class="fas fa-check"></i></div><b style="display:block;font-size:18px;margin-top:10px">${S.punch.out ? 'Punched out' : 'Punched in'}</b><b style="font-size:30px">${S.punch.out || S.punch.in}</b><div class="muted">Head office · Main gate · location verified</div><button class="pbtn" style="margin-top:14px" data-a="p:close">Done</button></div></div>`
    : S.pdlg === 'resign' ? `<div class="modal"><div class="box" style="text-align:left"><b style="font-size:17px">Initiate resignation?</b><p class="muted" style="font-size:12.5px">Your notice of 60 days ends on 23-01-2027. Your reporting line will be told.</p><div class="pc" style="margin-bottom:12px"><div class="muted">Reason (required)</div><b>${esc(S.form.resign)}</b></div><div class="row" style="justify-content:flex-end;gap:16px"><button class="chip" data-a="p:resignno">No</button><button class="chip" style="background:var(--bad);color:#fff;border-color:var(--bad)" data-a="p:resignyes">Yes</button></div></div></div>` : '';
  const push = S.ppush ? `<div class="push"><img src="${MARK}" alt=""><div><b>Ametecs Global</b><small>${esc(S.ppush.t)}</small></div></div>` : '';
  const nav = [['Attendance', 'fa-clock-rotate-left', 'att'], ['Leave', 'fa-umbrella-beach', 'leave'], null, ['Field', 'fa-route', 'field'], ['Home', 'fa-house', 'home']];
  $('#phone').innerHTML = `<div class="notch"></div><div class="sb"><span>${fmt(S.clock).replace(' ', '')}</span><span><i class="fas fa-wifi"></i> <i class="fas fa-signal"></i> <i class="fas fa-battery-three-quarters"></i></span></div>
    <div class="ph"><button style="border:0;background:transparent;display:flex;gap:9px;align-items:center;color:#fff;text-align:left;flex:1;min-width:0" data-a="p:go" data-v="profile"><span class="av">AK</span><span class="nm"><small>Good morning</small><b>${me.name}</b></span></button>
      <div class="tm">${fmt(S.clock)}<small>${S.day}</small></div><button class="pbell" data-a="p:bell" aria-label="Notifications"><i class="fas fa-bell"></i>${un ? `<span class="b">${un}</span>` : ''}</button></div>
    ${top ? `<div class="pt"><button data-a="p:go" data-v="home" aria-label="Back"><i class="fas fa-arrow-left"></i></button>${top}</div>` : ''}
    <div class="pbody">${b}</div>
    <div class="pf"><div class="nav">${nav.map(n => n ? `<button class="${S.phone === n[2] ? 'on' : ''}" data-a="p:go" data-v="${n[2]}"><i class="fas ${n[1]}"></i>${n[0]}</button>` : `<button class="punch" data-a="p:punch"><span><i class="fas fa-qrcode"></i></span>Punch</button>`).join('')}</div><div class="pw"><img src="${MARK}" alt="" style="width:11px;vertical-align:-1px"> Powered by <b>Ametecs</b></div></div>
    ${sheet}${push}`;
}
function pill(st, label) {
  const m = {pending: ['p-warn', 'Pending'], approved: ['p-ok', 'Approved'], rejected: ['p-bad', 'Rejected'], fix: ['p-bad', 'Needs fix'], late: ['p-warn', 'Late'], mute: ['p-mute', 'Today'], in: ['p-ok', 'In'], out: ['p-blue', 'Out'], absent: ['p-bad', 'Not in'], leave: ['p-blue', 'On leave'], notice: ['p-warn', 'Under notice'], accepted: ['p-ok', 'Accepted']}[st] || ['p-mute', st];
  return `<span class="pill ${m[0]}">${label || m[1]}</span>`;
}

// ---------- the desktop (Ametecs Global web console) ----------
function drawDesk() {
  const un = S.dn.filter(n => n.un).length, p = S.desk;
  const pendL = S.leaves.filter(l => l.st === 'pending').length, pendC = S.corr.filter(c => c.st === 'pending').length;
  const inN = S.team.filter(t => t.st === 'in' || t.st === 'out').length;
  const nav = [['MAIN'], ['dash', 'fa-gauge-high', 'Dashboard'], ['att', 'fa-user-clock', 'Live attendance'], ['APPROVALS'], ['leave', 'fa-umbrella-beach', 'Leave approvals', pendL], ['corr', 'fa-calendar-pen', 'Corrections', pendC],
    ['resign', 'fa-door-open', 'Resignations', S.resign && S.resign.st === 'notice' ? 1 : 0], ['PAYROLL & PEOPLE'], ['pay', 'fa-money-check-dollar', 'Payroll'], ['notice', 'fa-bullhorn', 'Notices'], ['FIELD'], ['field', 'fa-route', 'Field force']];
  const title = {dash: 'Dashboard', att: 'Live attendance', leave: 'Leave approvals', corr: 'Attendance corrections', resign: 'Resignations', pay: 'Payroll', notice: 'Notices', field: 'Field force'}[p];
  let b = '';
  if (p === 'dash') {
    b = `<h2>Good morning, Karthik</h2><div class="sub">Ametecs India · ${S.day}</div>
      <div class="kpis"><div class="kpi dark"><b>${inN} / ${S.team.length}</b><span>In today</span></div><div class="kpi"><b>${S.team.filter(t => t.late).length}</b><span>Late</span></div><div class="kpi"><b>${S.team.filter(t => t.st === 'leave').length}</b><span>On leave</span></div><div class="kpi"><b>${pendL + pendC}</b><span>Waiting on you</span></div></div>
      <div class="panel feed"><b style="display:block;margin-bottom:6px">Latest activity</b>${S.activity.slice(0, 7).map(a => `<div><i class="fas fa-circle-dot"></i>${esc(a.t)}<small>${a.at}</small></div>`).join('')}</div>`;
  } else if (p === 'att') {
    b = `<h2>Live attendance</h2><div class="sub">Every punch from QR gates, biometric devices and the app, as it happens</div>
      <table class="tbl"><tr><th>EMPLOYEE</th><th>DEPARTMENT</th><th>STATUS</th><th>IN</th><th>SOURCE</th></tr>${S.team.map(t => `<tr class="${S.newRow === t.code ? 'new' : ''}"><td><b>${t.name}</b><br><small style="color:var(--mute)">${t.code}</small></td><td>${t.dept}</td><td>${pill(t.st)}${t.late ? ' ' + pill('late') : ''}</td><td>${t.in || '—'}</td><td>${t.in ? (t.code === 'SMP041' ? 'QR · Main gate' : t.dept === 'Field' ? 'App · field' : 'Biometric') : '—'}</td></tr>`).join('')}</table>`;
  } else if (p === 'leave') {
    b = `<h2>Leave approvals</h2><div class="sub">Requests from people who report to you</div>${S.leaves.length ? `<table class="tbl"><tr><th>EMPLOYEE</th><th>TYPE</th><th>DATES</th><th>REASON</th><th>STATUS</th><th></th></tr>${S.leaves.map(l => `<tr class="${S.newRow === l.id ? 'new' : ''}"><td><b>${l.who}</b></td><td>${l.type}</td><td>${l.from} → ${l.to}<br><small style="color:var(--mute)">${l.days} days · balance ${S.me.lv[l.type]}</small></td><td>${esc(l.reason)}</td><td>${pill(l.st)}</td><td>${l.st === 'pending' ? `<button class="dbtn ok" data-a="d:leave" data-v='["${l.id}",true]'>Approve</button> <button class="dbtn bad" data-a="d:leave" data-v='["${l.id}",false]'>Reject</button>` : ''}</td></tr>`).join('')}</table>` : '<div class="panel">Nothing waiting. New requests appear here and on your bell.</div>'}`;
  } else if (p === 'corr') {
    b = `<h2>Attendance corrections</h2><div class="sub">Missed or wrong punches to approve</div>${S.corr.length ? `<table class="tbl"><tr><th>EMPLOYEE</th><th>DATE</th><th>PUNCH</th><th>REASON</th><th>STATUS</th><th></th></tr>${S.corr.map(c => `<tr class="${S.newRow === c.id ? 'new' : ''}"><td><b>${c.who}</b></td><td>${c.date}</td><td>${c.dir} ${c.at}</td><td>${esc(c.reason)}</td><td>${pill(c.st)}</td><td>${c.st === 'pending' ? `<button class="dbtn ok" data-a="d:corr" data-v='["${c.id}",true]'>Approve</button> <button class="dbtn bad" data-a="d:corr" data-v='["${c.id}",false]'>Reject</button>` : ''}</td></tr>`).join('')}</table>` : '<div class="panel">Nothing waiting.</div>'}`;
  } else if (p === 'resign') {
    const r = S.resign;
    b = `<h2>Resignations</h2><div class="sub">People serving notice and their exit clearance</div>${r ? `<table class="tbl"><tr><th>EMPLOYEE</th><th>SUBMITTED</th><th>NOTICE ENDS</th><th>REASON</th><th>STATUS</th><th></th></tr><tr class="new"><td><b>Aarav Khan</b><br><small style="color:var(--mute)">SMP041</small></td><td>24-11-2026</td><td>${r.ends}</td><td>${esc(r.note)}</td><td>${pill(r.st)}</td><td>${r.st === 'notice' ? '<button class="dbtn ok" data-a="d:resign" data-v="true">Accept &amp; start clearance</button>' : ''}</td></tr></table>` : '<div class="panel">No one is serving notice.</div>'}`;
  } else if (p === 'pay') {
    b = `<h2>Payroll</h2><div class="sub">November 2026 · 42 employees</div>
      <div class="kpis"><div class="kpi dark"><b>₹14,82,300</b><span>Net pay</span></div><div class="kpi"><b>₹1,10,880</b><span>PF</span></div><div class="kpi"><b>₹8,400</b><span>PT</span></div><div class="kpi"><b>₹62,150</b><span>TDS</span></div></div>
      <div class="panel row"><div class="gr"><b>November 2026 run</b><div class="sub" style="margin:2px 0 0">Attendance locked · corrections applied · checks passed</div></div>${S.nov === 'draft' ? '<button class="dbtn gold" data-a="d:publish">Approve &amp; publish payslips</button>' : pill('approved', 'Published')}</div>`;
  } else if (p === 'notice') {
    b = `<h2>Notices</h2><div class="sub">Post once; it reaches every employee's app and the notice board</div>
      <div class="panel"><div class="fld">Title<input value="${esc(S.form.notice)}" readonly></div><div class="fld">Show until<input value="28-11-2026" readonly></div><button class="dbtn gold" data-a="d:notice">Post to everyone</button></div>
      ${S.notices.map(n => `<div class="panel row"><i class="fas fa-bullhorn" style="color:#b45309"></i><div class="gr"><b>${esc(n.t)}</b><div class="sub" style="margin:0">Posted ${n.at} · 42 employees</div></div>${pill('approved', 'Live')}</div>`).join('')}`;
  } else if (p === 'field') {
    b = `<h2>Field force</h2><div class="sub">Live visits, calls and locations of your field team</div>
      <div class="map"><div class="pin" style="left:30%;top:60%"><i class="fas fa-location-dot" style="color:#2563eb"></i><small>Imran · Gachibowli</small></div>${S.visits.length ? '<div class="pin" style="left:62%;top:42%"><i class="fas fa-location-dot"></i><small>Aarav · Madhapur</small></div>' : ''}</div>
      <table class="tbl"><tr><th>EMPLOYEE</th><th>CUSTOMER</th><th>AREA</th><th>TIME</th><th>OUTCOME</th></tr>${S.visits.map(v => `<tr class="new"><td><b>${v.who}</b></td><td>${v.cust}</td><td>${v.area}</td><td>${v.at}</td><td>${v.out}</td></tr>`).join('')}<tr><td><b>Imran Shaikh</b></td><td>Balaji Stores</td><td>Gachibowli</td><td>09:40</td><td>Paid ₹18,000</td></tr></table>`;
  }
  const dd = S.dd ? `<div class="dd">${S.dn.length ? S.dn.map((n, k) => `<button class="it${n.un ? ' un' : ''}" data-a="d:open" data-v="${k}"><i class="fas ${n.i}"></i><span>${esc(n.t)}<small>Today ${n.at}</small></span></button>`).join('') : ''}</div>` : '';
  const toast = S.dtoast ? `<div class="dtoast"><i class="fas ${S.dtoast.i}"></i><span>${esc(S.dtoast.t)}</span></div>` : '';
  $('#desk').innerHTML = `<div class="chrome"><i class="d" style="background:#ef4444"></i><i class="d" style="background:#f59e0b"></i><i class="d" style="background:#22c55e"></i><div class="url"><i class="fas fa-lock"></i>app.ametecs.global/c/ametecs-india/${p}</div></div>
    <div class="app"><nav class="side"><div class="brand"><img src="${MARK}" alt=""><span>Ametecs <b>Global</b></span></div>
      ${nav.map(n => n.length === 1 ? `<div class="grp">${n[0]}</div>` : `<button class="${p === n[0] ? 'on' : ''}" data-a="d:go" data-v="${n[0]}"><i class="fas ${n[1]}"></i>${n[2]}${n[3] ? `<span class="cnt">${n[3]}</span>` : ''}</button>`).join('')}</nav>
      <div class="dmain"><div class="dtop"><span class="crumb">Ametecs India › <b>${title}</b></span><span class="srch"><i class="fas fa-magnifying-glass"></i> Search people, pages…</span>
        <button class="bell" data-a="d:bell" aria-label="Notifications"><i class="fas fa-bell"></i>${un ? `<span class="b">${un}</span>` : ''}</button><span class="av">KR</span><span style="font-size:12px;line-height:1.2"><b>Karthik Rao</b><br><span style="color:var(--mute)">HR Manager</span></span></div>
        <div class="dbody">${b}</div>${dd}${toast}</div></div>`;
}

// ---------- the guide ----------
function drawRail() {
  $('#stories').innerHTML = STORIES.map(s => `<button class="story${cur && cur.id === s.id ? ' on' : ''}${done.has(s.id) ? ' fin' : ''}" data-story="${s.id}"><i class="fas ${s.i}"></i><span>${s.t}<small>${s.s.length} steps · ${[...new Set(s.s.map(x => x[0]))].map(x => x === 'p' ? 'phone' : 'desktop').join(' + ')}</small></span><i class="fas fa-circle-check done"></i></button>`).join('')
    + `<button class="story${!cur ? ' on' : ''}" data-story=""><i class="fas fa-hand-pointer"></i><span>Explore freely<small>Click anything on either screen</small></span></button>`;
  const st = stepNow();
  let html;
  if (!cur) html = `<h3>EXPLORE FREELY</h3><p>Both screens work. Apply leave on the phone, approve it on the desktop, punch in, post a notice, run payroll… every action shows up on the other screen.</p>`;
  else if (!st) html = `<h3>${esc(cur.t.toUpperCase())}</h3><p><i class="fas fa-circle-check" style="color:#4ade80"></i> Done. That is the whole flow, end to end, on both screens.</p><div class="ctl"><button class="pri" data-next-story><i class="fas fa-forward"></i>Next story</button><button data-replay><i class="fas fa-rotate-left"></i>Replay</button></div>`;
  else {
    const hidden = !sideShown(st[0]);
    html = `<div class="step">STEP ${stepI + 1} OF ${cur.s.length}</div><p>${st[1]}</p>
      <span class="who"><i class="fas ${st[0] === 'p' ? 'fa-mobile-screen' : 'fa-desktop'}"></i>${st[0] === 'p' ? 'Employee on the phone' : 'HR manager on the desktop'}${hidden ? ' · not shown, press Next' : ''}</span>
      <div class="ctl" style="margin-top:12px"><button data-back ${stepI === 0 ? 'disabled' : ''}><i class="fas fa-arrow-left"></i>Back</button><button class="pri" data-step><i class="fas fa-hand-pointer"></i>${hidden ? 'Next' : 'Do it for me'}</button><button data-auto><i class="fas ${auto ? 'fa-pause' : 'fa-play'}"></i>${auto ? 'Pause' : 'Auto-play'}</button></div>`;
  }
  $('#narr').innerHTML = html + (cur ? `<div class="bar"><i style="width:${Math.round(100 * stepI / cur.s.length)}%"></i></div>` : '');
}
function hint() {
  document.querySelectorAll('.hint').forEach(e => e.classList.remove('hint'));
  const st = stepNow(); if (!st || !sideShown(st[0])) return;
  const root = st[0] === 'p' ? $('#phone') : $('#desk');
  const cands = [...root.querySelectorAll(`[data-a="${st[2]}"]`)];
  const el = cands.find(e => st.length < 4 || same(parseV(e.dataset.v), st[3])) || cands[0];
  if (el) el.classList.add('hint');
}
function parseV(v) { if (v === undefined) return undefined; try { return JSON.parse(v); } catch (e) { return v; } }
function render() { drawPhone(); drawDesk(); drawRail(); hint(); }

// ---------- clicks ----------
document.addEventListener('click', e => {
  const a = e.target.closest('[data-a]');
  if (a && !a.disabled) { run(a.dataset.a, parseV(a.dataset.v)); return; }
  const s = e.target.closest('[data-story]');
  if (s) { s.dataset.story ? startStory(s.dataset.story) : (cur = null, S = fresh(), stopAuto(), render()); history.replaceState(null, '', '?view=' + document.body.className.replace('v-', '') + (s.dataset.story ? '&story=' + s.dataset.story : '')); return; }
  if (e.target.closest('[data-step]')) { doStep(); return; }
  if (e.target.closest('[data-back]')) { stopAuto(); back(); return; }
  if (e.target.closest('[data-auto]')) { if (auto) stopAuto(); else { auto = setInterval(() => { if (!stepNow()) { stopAuto(); drawRail(); return; } doStep(); }, 2600); doStep(); } drawRail(); return; }
  if (e.target.closest('[data-replay]')) { startStory(cur.id); return; }
  if (e.target.closest('[data-next-story]')) { const k = STORIES.indexOf(cur); startStory(STORIES[(k + 1) % STORIES.length].id); return; }
  const v = e.target.closest('[data-view]');
  if (v) { document.body.className = 'v-' + v.dataset.view; document.querySelectorAll('.seg button').forEach(b => b.classList.toggle('on', b === v)); history.replaceState(null, '', '?view=' + v.dataset.view + (cur ? '&story=' + cur.id : '')); render(); return; }
  if (e.target.closest('#reset')) { cur ? startStory(cur.id) : (S = fresh(), render()); }
});
const q = new URLSearchParams(location.search);
document.querySelectorAll('.seg button').forEach(b => b.classList.toggle('on', 'v-' + b.dataset.view === document.body.className));
S = fresh();
startStory(STORIES.some(s => s.id === q.get('story')) ? q.get('story') : 'leave');
</script>
</body></html>
