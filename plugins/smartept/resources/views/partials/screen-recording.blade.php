{{--
  15-Oct-2026 (Ejaz) — Screen Recording (Commander only, licensed per employee like LiveView).
  Own file, own IIFE: exposes only window.initScreenRec / window.applyScreenRecNav.
  Uses the console helpers at call time: api, apiBlob, esc, toast, today.
  Menu visibility comes from GET /screen-recording/access (plan + permission, server decides).
--}}
<div class="view" id="v-screcord">
  <div class="card" id="sr-player-card">
    <h3>Recordings <span class="hint">pick an employee and a day — click anything on the timeline or in the list to jump straight there</span></h3>
    <div class="filters" style="margin-bottom:10px">
      <label>Employee</label><select id="sr-emp" style="min-width:220px"></select>
      <label>Day</label><input type="date" id="sr-date">
      <label>Screen</label><select id="sr-mon" style="min-width:0"><option value="0">Desktop 1</option></select>
      <button class="btn solid" id="sr-load">Show</button>
      <span style="margin-left:auto"></span>
      <span class="mut" id="sr-daysum" style="padding:0"></span>
    </div>
    <div class="sr-legend">
      <span><i style="background:#2563EB"></i>Continuous</span><span><i style="background:#7C3AED"></i>App-based</span>
      <span><i style="background:#DC2626"></i>Dashcam (event)</span><span><i style="background:#059669"></i>Record now</span>
    </div>
    <div id="sr-periods" class="sr-periods"></div>
    <div class="sr-main">
      <div class="sr-stage">
        <video id="sr-video" playsinline muted></video>
        <div id="sr-wm"></div>
        <div id="sr-empty" class="mut">Choose an employee and a day, then press Show.</div>
        <!-- 20-Oct-2026 (Ejaz): right-click the seek bar → set this moment as Download From / To -->
        <div id="sr-tl-menu" class="sr-menu" hidden>
          <div class="hd">Marked time: <b id="sr-mk-at"></b></div>
          <button type="button" data-m="from">Set as Download From<small id="sr-mk-from"></small></button>
          <button type="button" data-m="to">Set as Download To<small id="sr-mk-to"></small></button>
        </div>
        <div id="sr-tl"><div id="sr-tl-sel"></div><div id="sr-tl-segs"></div><div id="sr-tl-marks"></div><div id="sr-tl-now"></div><div id="sr-tl-tip"></div><div id="sr-tl-hours"></div></div>
        <!-- 20-Oct-2026 (Ejaz): player bar redesigned — icon buttons on one line, download on its own line. -->
        <div class="sr-ctrls">
          <div class="sr-grp">
            <button type="button" class="sr-ib" id="sr-prev" title="Previous minute" aria-label="Previous minute"><svg viewBox="0 0 24 24"><path d="M6 5h2v14H6zM20 5v14L9 12z"/></svg></button>
            <button type="button" class="sr-ib sr-main-btn" id="sr-play" title="Play" aria-label="Play"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></button>
            <button type="button" class="sr-ib" id="sr-next" title="Next minute" aria-label="Next minute"><svg viewBox="0 0 24 24"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg></button>
            <button type="button" class="sr-ib" id="sr-stop" title="Stop and close the video" aria-label="Stop"><svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1.5"/></svg></button>
          </div>
          <span id="sr-clock" title="When this moment was recorded on the employee's PC">--:--:--</span>
          <span class="sr-spacer"></span>
          <!-- 20-Oct-2026 (Ejaz, DPDP Act): customer data hidden on playback; "Show original" needs its own permission + a reason -->
          <span class="sr-mb" id="sr-maskbadge" hidden></span>
          <button type="button" class="sr-tb" id="sr-unmask" hidden>Show original</button>
          <label class="sr-lbl" for="sr-speed">Speed</label>
          <select id="sr-speed" class="sr-sel" title="Playback speed"><option value="1">1×</option><option value="2" selected>2×</option><option value="4">4×</option><option value="8">8×</option><option value="16">16×</option></select>
          <button type="button" class="sr-ib" id="sr-fs" title="Full screen (Esc to exit)" aria-label="Full screen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button>
        </div>
        <div class="sr-dlbar">
          <span class="sr-lbl">Download</span>
          <label class="sr-lbl" for="sr-dl-from">From:</label><input type="text" id="sr-dl-from" class="sr-t" placeholder="9:00 AM" autocomplete="off">
          <label class="sr-nd" title="This time is after midnight (the next calendar day)"><input type="checkbox" id="sr-dl-fromnext"> +1 day</label>
          <label class="sr-lbl" for="sr-dl-to">To:</label><input type="text" id="sr-dl-to" class="sr-t" placeholder="6:00 PM" autocomplete="off">
          <label class="sr-nd" title="This time is after midnight (the next calendar day)"><input type="checkbox" id="sr-dl-tonext"> +1 day</label>
          <button type="button" class="sr-dlbtn" id="sr-dl" title="Download the recording from–to (file length as set in Download Criteria)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></svg><span>Download</span></button>
          <button type="button" class="sr-dlbtn sr-evb" id="sr-ev" hidden title="Evidence pack for audits and disputes: watermarked video, fingerprint of every minute, chain of custody and an audit-ready PDF"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg><span>Evidence pack</span></button>
        </div>
        <div class="sr-rbar" id="sr-ubar" hidden>
          <span class="sr-lbl">Show original</span>
          <input id="sr-ureason" class="sr-in" maxlength="200" placeholder="Reason (logged with your name), e.g. dispute #123 — verify account number">
          <button type="button" class="sr-dlbtn" id="sr-ugo">Show original</button>
          <button type="button" class="sr-tb" id="sr-ucancel">Cancel</button>
        </div>
        <div class="sr-rbar" id="sr-evbar" hidden>
          <span class="sr-lbl">Evidence pack for the From–To above</span>
          <input id="sr-evreason" class="sr-in" maxlength="255" placeholder="Reason / case reference, e.g. Audit 2026-Q3 or customer complaint #4521">
          <label class="sr-nd sr-show" id="sr-evorig-l" hidden title="Only for people allowed to show hidden customer data — logged"><input type="checkbox" id="sr-evorig"> Original (customer data not hidden)</label>
          <button type="button" class="sr-dlbtn sr-evb" id="sr-evgo">Create</button>
          <button type="button" class="sr-tb" id="sr-evcancel">Cancel</button>
        </div>
      </div>
      <div class="sr-side">
        <div style="display:flex;align-items:center;gap:6px"><div class="sr-chips" id="sr-chips" style="flex:1"></div><button type="button" class="sr-info" id="sr-info-btn" title="What is this list and how to use it">i</button></div>
        <div class="sr-help" id="sr-help" hidden>
          <p style="margin:0 0 6px"><b>What this is</b> — everything this employee did on the day, in time order, taken from the activity logs. It is your index into the recording.</p>
          <p style="margin:0 0 6px"><b>How to read a row</b> — time · what · detail. Example: <i>01:38 PM · Website · WhatsApp Business – Google Chrome</i> = WhatsApp Web was opened in Chrome at 1:38 PM.</p>
          <ul>
            <li><b>Apps</b> (grey) — a program came to the front (chrome.exe, excel.exe…)</li>
            <li><b>Sites</b> (blue) — a website was opened in the browser</li>
            <li><b>Violations</b> (red) — a blocked app/site or a rule was broken</li>
            <li><b>Idle</b> (amber) — no mouse/keyboard; the detail is how long</li>
            <li><b>Breaks</b> (green) — the employee started a break</li>
          </ul>
          <p style="margin:0 0 6px"><b>How to use</b> — click any row and the video jumps to that exact moment. The same colours show as small ticks under the player's seek bar. Type in the search box (e.g. <i>youtube</i>, <i>excel</i>, <i>whatsapp</i>) to find when something was used.</p>
          <p style="margin:0 0 6px"><b>What to select</b> — the buttons switch a type on/off in the list and on the seek bar; they change only your view, never what is recorded.</p>
          <ul>
            <li>Checking for misuse → keep only <b>Violations</b> (and <b>Sites</b>).</li>
            <li>Checking what work was done → <b>Apps</b> + <b>Sites</b>.</li>
            <li>Checking time away → <b>Idle</b> + <b>Breaks</b>.</li>
            <li>Leave all on to see the full day. Rows with no recording behind them (e.g. while idle or locked) play the next recorded minute.</li>
          </ul>
        </div>
        <input id="sr-q" placeholder="Search apps, sites, violations…">
        <div id="sr-marks" class="sr-list"></div>
      </div>
    </div>
  </div>

  <div class="card" id="sr-ev-card" hidden>
    <h3>Evidence packs <span class="hint">for audits and disputes — watermarked video, tamper-proof fingerprint of every minute, chain of custody, audit-ready PDF</span></h3>
    <div style="overflow:auto"><table><thead><tr><th>Reference</th><th>Period</th><th style="width:80px">Minutes</th><th>Customer data</th><th>Status</th><th>Created</th><th style="width:250px"></th></tr></thead>
      <tbody id="sr-ev-rows"><tr><td colspan="7" class="mut">Choose an employee and press Show.</td></tr></tbody></table></div>
  </div>

  <div class="card sr-manage" id="sr-seats-card">
    <h3>Who is recorded <span class="hint" id="sr-seat-sum"></span></h3>
    <div class="filters" style="margin-bottom:10px"><input id="sr-seat-q" placeholder="Search employee" style="min-width:220px">
      <label class="mut" style="padding:0"><input type="checkbox" id="sr-seat-only"> Only recorded employees</label></div>
    <div style="max-height:360px;overflow:auto"><table><thead><tr><th>Employee</th><th>Code</th><th style="width:110px">Recorded</th><th style="width:330px">Record now</th></tr></thead>
      <tbody id="sr-seat-rows"><tr><td colspan="4" class="mut">Loading…</td></tr></tbody></table></div>
  </div>

  <div class="card sr-manage" id="sr-set-card">
    <h3>Recording settings <span class="hint">apply to every recorded employee in this company</span></h3>
    <div class="sr-grid">
      <div class="sr-box">
        <div class="sr-k">When to record</div>
        <label class="sr-opt"><input type="checkbox" name="sr-mode" value="continuous"> <b>Continuous</b><span>The whole shift while the employee is signed in.</span></label>
        <label class="sr-opt"><input type="checkbox" name="sr-mode" value="app_based"> <b>App-based</b><span>Only while one of the apps / sites below is in front.</span></label>
        <label class="sr-opt"><input type="checkbox" name="sr-mode" value="dashcam"> <b>Dashcam (on events)</b><span>Keeps the last few minutes on the PC; saves them only when a violation happens, plus the minutes after.</span></label>
        <label class="sr-opt"><input type="checkbox" name="sr-mode" value="on_demand"> <b>Record now (on demand)</b><span>An admin starts it for a set time from "Who is recorded".</span></label>
        <div class="sr-row"><label>Before an event</label><select id="sr-pre"><option value="30">30 sec</option><option value="60">1 min</option><option value="120">2 min</option><option value="300">5 min</option><option value="600">10 min</option></select>
          <label>After an event</label><select id="sr-post"><option value="60">1 min</option><option value="120">2 min</option><option value="300">5 min</option><option value="600">10 min</option><option value="1800">30 min</option></select></div>
      </div>
      <div class="sr-box">
        <div class="sr-k">Apps &amp; sites</div>
        <label class="sr-lbl">Record when these are in front <span>(App-based — one per line: app name, process or website, e.g. <i>chrome</i>, <i>excel</i>, <i>web.whatsapp.com</i>)</span></label>
        <textarea id="sr-watch" rows="4"></textarea>
        <label class="sr-lbl">Never record — hide these windows <span>(e.g. a password manager, personal banking). While one is in front the video shows only "Not captured as per the organization policy"; every other window and browser tab is still recorded, and these names are blurred wherever they show, such as in a browser's tab strip.</span></label>
        <textarea id="sr-excl" rows="3"></textarea>
      </div>
      <div class="sr-box">
        <div class="sr-k">Quality &amp; pauses</div>
        <div class="sr-row"><label>Quality</label><select id="sr-qual">
          <option value="economy">Economy — 1 fps, 720p (~215 MB / 8-hour day)</option>
          <option value="standard">Standard — 2 fps, 900p (~540 MB / day)</option>
          <option value="high">High — 5 fps, 1080p (~1.4 GB / day)</option></select></div>
        <div class="sr-row"><label>Screens</label><select id="sr-mons"><option value="primary">Main screen only</option><option value="all">All screens</option></select></div>
        <label class="sr-chk"><input type="checkbox" id="sr-pidle"> Pause while the employee is idle</label>
        <label class="sr-chk"><input type="checkbox" id="sr-pbrk"> Pause during breaks</label>
        <label class="sr-chk"><input type="checkbox" id="sr-ind"> Show a "● Recording" sign on the employee's screen</label>
        <div class="mut" style="padding:4px 0 0">Recording always stops when the PC is locked or the employee signs out. No audio is ever recorded.</div>
      </div>
      <div class="sr-box">
        <div class="sr-k">Upload &amp; PC storage</div>
        <div class="sr-row"><label>Upload speed limit per PC</label><select id="sr-kbps"><option value="0">No limit</option><option value="128">128 kbps</option><option value="256">256 kbps</option><option value="512">512 kbps</option><option value="1024">1 Mbps</option><option value="2048">2 Mbps</option></select></div>
        <div class="sr-row"><label>Space on each PC while waiting to upload</label><select id="sr-cap"><option value="512">512 MB</option><option value="1024">1 GB</option><option value="2048">2 GB</option><option value="5120">5 GB</option><option value="10240">10 GB</option></select></div>
        <div class="mut" style="padding:4px 0 0">If the server can't be reached, recordings wait on the PC (encrypted) and upload automatically later. When this space is full the oldest waiting minutes are dropped.</div>
      </div>
      <div class="sr-box">
        <div class="sr-k">Keep &amp; clean up</div>
        <div class="sr-row"><label>Keep recordings for</label><input type="number" id="sr-ret" min="1" max="3650" style="width:90px"> <span class="mut" style="padding:0">days</span></div>
        <div class="sr-row"><label>Clean up</label><select id="sr-freq"><option value="daily">Daily</option><option value="every_n_days">Every N days</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select>
          <label>at</label><input type="text" id="sr-ctime" class="sr-t12" placeholder="2:00 AM" autocomplete="off" style="width:110px"></div>
        <div class="sr-row" id="sr-row-n"><label>Every</label><input type="number" id="sr-cn" min="1" max="365" style="width:80px"> <span class="mut" style="padding:0">days</span></div>
        <div class="sr-row" id="sr-row-wd"><label>On</label><select id="sr-cwd"><option value="0">Sunday</option><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option></select></div>
        <div class="sr-row" id="sr-row-md"><label>On day</label><input type="number" id="sr-cmd" min="1" max="31" style="width:80px"> <span class="mut" style="padding:0">of the month (29–31 run on the last day in shorter months)</span></div>
        <div class="mut" id="sr-cleansum" style="padding:6px 0 0"></div>
        <div style="margin-top:8px"><button class="btn" id="sr-clean-now">Run cleanup now</button></div>
      </div>
      <div class="sr-box">
        <div class="sr-k">Hide customer data</div>
        <div id="sr-maskinfo" style="font-size:13px;line-height:1.5"></div>
        <div class="mut" style="padding:6px 0 0">Set in one place: Organisation → Privacy — hide customer data. Applies to LiveView, Live Wall and recordings.</div>
      </div>
      <div class="sr-box">
        <div class="sr-k">Download Criteria</div>
        <div class="sr-row"><label>Each downloaded video file</label><select id="sr-split">
          <option value="1">1 minute each (as recorded)</option><option value="5">5 minutes each</option><option value="10">10 minutes each</option>
          <option value="15">15 minutes each</option><option value="20">20 minutes each</option><option value="30">30 minutes each</option>
          <option value="45">45 minutes each</option><option value="60">1 hour each</option><option value="0">Entire video in a single file</option></select></div>
        <div class="mut" style="padding:4px 0 0">Applies to every From–To download. The minutes are joined in time order; time with no recording (break, idle, PC locked) is skipped. Files are named by their start and end time.</div>
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:14px">
      <button class="btn solid" id="sr-save">Save settings</button><span class="mut" id="sr-save-msg" style="padding:0"></span>
    </div>
  </div>
</div>

<style>
  /* 19-Oct-2026: movie-style seek bar under the video. Spans only the recorded time (gaps squeezed out, shown as thin dividers). */
  #v-screcord #sr-tl { position:relative; height:44px; margin:0; background:#121a22; border-top:1px solid #22303c; cursor:pointer; user-select:none; touch-action:none; }
  #v-screcord #sr-tl-segs { position:absolute; left:12px; right:12px; top:9px; height:8px; background:#2a3744; border-radius:4px; overflow:hidden; }
  #v-screcord #sr-tl-segs .s { position:absolute; top:0; height:8px; opacity:.9; }
  /* 20-Oct-2026: the From–To download range on the seek bar + the right-click menu */
  #v-screcord #sr-tl-sel { position:absolute; top:5px; height:16px; background:rgba(184,137,59,.28); border-left:2px solid #d9a441; border-right:2px solid #d9a441; border-radius:3px; display:none; pointer-events:none; }
  #v-screcord .sr-menu { position:absolute; z-index:5; transform:translateX(-50%); display:flex; flex-direction:column; min-width:240px; padding:4px; border-radius:9px; background:#1c2731; border:1px solid #2c3a46; box-shadow:0 8px 24px rgba(0,0,0,.45); }
  #v-screcord .sr-menu[hidden] { display:none; }
  #v-screcord .sr-menu button { text-align:left; border:0; background:transparent; color:#e6edf3; font-size:12.5px; padding:7px 10px; border-radius:6px; cursor:pointer; white-space:nowrap; }
  #v-screcord .sr-menu button:hover { background:#2a3a48; }
  #v-screcord .sr-menu b { font-variant-numeric:tabular-nums; color:#fbbf24; }
  #v-screcord .sr-menu .hd { padding:6px 10px 6px; font-size:12px; color:#9fb0bf; border-bottom:1px solid #2c3a46; margin-bottom:3px; white-space:nowrap; }
  #v-screcord .sr-menu small { display:block; font-size:11px; color:#8fa1b1; margin-top:1px; font-variant-numeric:tabular-nums; }
  #v-screcord #sr-tl-segs .g { position:absolute; top:-3px; width:2px; height:14px; background:#0b1015; }
  #v-screcord #sr-tl-marks { position:absolute; left:12px; right:12px; top:20px; height:5px; }
  #v-screcord #sr-tl-marks .m { position:absolute; top:0; width:2px; height:5px; opacity:.8; }
  #v-screcord #sr-tl-now { position:absolute; top:5px; width:4px; height:16px; margin-left:10px; background:#fff; border-radius:2px; box-shadow:0 0 0 2px rgba(0,0,0,.35); display:none; pointer-events:none; }
  #v-screcord #sr-tl-tip { position:absolute; bottom:40px; transform:translateX(-50%); background:#000; color:#fff; font-size:11px; font-weight:700; padding:2px 6px; border-radius:4px; white-space:nowrap; display:none; pointer-events:none; font-variant-numeric:tabular-nums; }
  /* 20-Oct-2026: start / end times on their own line under the marks, never on top of them */
  #v-screcord #sr-tl-hours span { position:absolute; bottom:4px; font-size:11px; font-weight:600; line-height:1; color:#8fa1b1; font-variant-numeric:tabular-nums; letter-spacing:.2px; }
  #v-screcord .sr-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:11.5px; color:var(--ink-2); margin:6px 0 12px; }
  #v-screcord .sr-legend i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:5px; vertical-align:-1px; }
  #v-screcord .sr-main { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:14px; }
  @media (max-width:1100px) { #v-screcord .sr-main { grid-template-columns:1fr; } }
  #v-screcord .sr-stage { position:relative; background:#0b1015; border-radius:10px; overflow:hidden; min-height:300px; display:flex; flex-direction:column; }
  #v-screcord #sr-video { width:100%; flex:1; max-height:62vh; background:#000; display:block; }
  #v-screcord #sr-wm { position:absolute; top:10px; right:12px; color:rgba(255,255,255,.55); font-size:12px; font-weight:700; pointer-events:none; text-shadow:0 1px 2px rgba(0,0,0,.7); }
  #v-screcord #sr-empty { position:absolute; inset:0 0 90px 0; display:flex; align-items:center; justify-content:center; color:#9fb0bf; }
  #v-screcord .sr-stage:fullscreen { border-radius:0; }
  #v-screcord .sr-stage:fullscreen #sr-video { max-height:none; }
  #v-screcord .sr-ctrls { display:flex; gap:10px; align-items:center; padding:8px 12px; background:#121a22; }
  #v-screcord .sr-grp { display:flex; gap:4px; align-items:center; }
  #v-screcord .sr-ib { width:34px; height:34px; flex:none; display:inline-flex; align-items:center; justify-content:center; padding:0;
    border:0; border-radius:8px; background:transparent; color:#d6e0e8; cursor:pointer; transition:background .15s,color .15s; }
  #v-screcord .sr-ib svg { width:18px; height:18px; }
  #v-screcord .sr-ib svg:not([fill]) { fill:currentColor; }
  #v-screcord .sr-ib:hover { background:rgba(255,255,255,.1); color:#fff; }
  #v-screcord .sr-ib:focus-visible, #v-screcord .sr-dlbtn:focus-visible { outline:2px solid #5aa9e6; outline-offset:1px; }
  #v-screcord .sr-ib.sr-main-btn { width:40px; height:40px; border-radius:50%; background:#1f7ac2; color:#fff; margin:0 2px; }
  #v-screcord .sr-ib.sr-main-btn:hover { background:#2a8ad6; }
  #v-screcord .sr-ib.sr-main-btn svg { width:20px; height:20px; }
  #v-screcord #sr-clock { color:#e6edf3; font:600 13px/1 ui-monospace,SFMono-Regular,Consolas,monospace; white-space:nowrap; padding:0 4px; }
  #v-screcord .sr-spacer { flex:1; }
  #v-screcord .sr-lbl { color:#9fb0bf; font-size:12px; font-weight:600; white-space:nowrap; padding:0; margin:0; }
  #v-screcord .sr-sel { min-width:0; width:auto; height:30px; padding:2px 24px 2px 8px; border-radius:7px; background-color:#1c2731; color:#e6edf3; border:1px solid #2c3a46; font-size:12.5px; box-sizing:border-box; }
  #v-screcord .sr-dlbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; padding:8px 12px 10px; background:#121a22; border-top:1px solid #1f2b35; }
  #v-screcord .sr-dlbar .sr-lbl:first-child { color:#e6edf3; margin-right:4px; }
  #v-screcord .sr-dlbar .sr-t { height:30px; border-radius:7px; background:#1c2731; color:#e6edf3; border:1px solid #2c3a46; color-scheme:dark; font-size:12.5px; box-sizing:border-box; padding:2px 8px; width:116px !important; }
  #v-screcord .sr-nd { display:none; align-items:center; gap:4px; color:#9fb0bf; font-size:11.5px; font-weight:600; white-space:nowrap; cursor:pointer; margin:0 4px 0 -2px; }
  #v-screcord.sr-overnight .sr-nd { display:inline-flex; }
  #v-screcord .sr-nd input { margin:0; accent-color:#1f7ac2; }
  #v-screcord .sr-plus { font-size:.82em; font-weight:600; color:#d9a441; margin-left:3px; white-space:nowrap; }
  #v-screcord .sr-dlbtn { margin-left:auto; display:inline-flex; align-items:center; gap:6px; height:30px; padding:0 14px; border-radius:7px; border:0;
    background:#1f7ac2; color:#fff; font-size:12.5px; font-weight:600; cursor:pointer; }
  #v-screcord .sr-dlbtn:hover { background:#2a8ad6; }
  #v-screcord .sr-dlbtn svg { width:15px; height:15px; }
  #v-screcord .sr-dlbtn:disabled { opacity:.6; cursor:wait; }
  #v-screcord .sr-info { width:20px; height:20px; flex:none; border-radius:50%; border:1.5px solid var(--accent); background:transparent; color:var(--accent); font:700 11px/1 Georgia,serif; cursor:pointer; padding:0; }
  #v-screcord .sr-info.on, #v-screcord .sr-info:hover { background:var(--accent); color:#fff; }
  #v-screcord .sr-help { border:1px solid var(--border); border-radius:9px; background:var(--card-2, #f6f9fb); padding:10px 12px; font-size:12px; line-height:1.55; color:var(--ink-2); max-height:340px; overflow:auto; }
  #v-screcord .sr-help b { color:var(--ink); }
  #v-screcord .sr-help ul { margin:4px 0 8px; padding-left:18px; }
  #v-screcord .sr-periods { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin:0 0 10px; font-size:12px; }
  #v-screcord .sr-periods:empty { display:none; }
  #v-screcord .sr-per { display:inline-flex; align-items:center; gap:4px; border:1px solid var(--border); border-radius:8px; padding:3px 3px 3px 10px; background:var(--card); cursor:pointer; font-variant-numeric:tabular-nums; }
  #v-screcord .sr-per.on { border-color:var(--accent); background:var(--accent-weak); color:var(--accent-ink); font-weight:700; }
  #v-screcord .sr-per .mut { padding:0; }
  #v-screcord .sr-per .dl { border:0; background:transparent; cursor:pointer; padding:3px 5px; border-radius:6px; line-height:0; color:inherit; }
  #v-screcord .sr-per .dl:hover { background:var(--border); }
  #v-screcord .sr-t { width:118px !important; min-width:0; padding:4px 6px; }
  #v-screcord .sr-t.sr-bad, #v-screcord .sr-t12.sr-bad { border-color:#C0392B !important; } /* 20-Oct-2026: unreadable time */
  #v-screcord #sr-speed { width:auto !important; flex:0 0 auto; }
  #v-screcord #sr-clock { color:#fff; font-weight:700; font-variant-numeric:tabular-nums; margin-left:6px; }
  #v-screcord .sr-side { display:flex; flex-direction:column; gap:8px; min-width:0; }
  #v-screcord .sr-chips { display:flex; gap:6px; flex-wrap:wrap; }
  #v-screcord .sr-chips button { font-size:11.5px; padding:3px 9px; border-radius:20px; border:1px solid var(--border); background:var(--card); cursor:pointer; }
  #v-screcord .sr-chips button.on { background:var(--accent-weak); border-color:var(--accent); color:var(--accent-ink); font-weight:700; }
  #v-screcord .sr-list { flex:1; max-height:56vh; overflow:auto; border:1px solid var(--border); border-radius:9px; }
  #v-screcord .sr-list .r { display:flex; gap:8px; padding:6px 9px; border-bottom:1px solid var(--border); cursor:pointer; font-size:12.5px; }
  #v-screcord .sr-list .r:hover { background:var(--accent-weak); }
  #v-screcord .sr-list .r .t { font-variant-numeric:tabular-nums; color:var(--ink-3); flex:none; width:62px; }
  #v-screcord .sr-list .r .l { font-weight:700; color:var(--ink); }
  #v-screcord .sr-list .r .d { color:var(--ink-2); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  #v-screcord .sr-list .r.violation .l { color:#DC2626; }
  #v-screcord .sr-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:12px; }
  #v-screcord .sr-box { border:1px solid var(--border); border-radius:10px; padding:12px 14px; background:var(--card-2, #fafcfd); }
  #v-screcord .sr-k { font-size:12px; font-weight:800; color:var(--accent); text-transform:uppercase; letter-spacing:.06em; margin-bottom:8px; }
  #v-screcord .sr-opt { display:block; padding:5px 0; font-size:13px; }
  #v-screcord .sr-opt span { display:block; margin-left:22px; font-size:12px; color:var(--ink-3); }
  #v-screcord .sr-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:7px 0; font-size:13px; }
  #v-screcord .sr-row > label { font-weight:600; color:var(--ink-2); }
  #v-screcord .sr-lbl { display:block; font-size:12.5px; font-weight:600; margin:6px 0 4px; }
  #v-screcord .sr-lbl span { font-weight:400; color:var(--ink-3); }
  #v-screcord textarea { width:100%; box-sizing:border-box; font-family:inherit; font-size:12.5px; }
  #v-screcord .sr-chk { display:block; font-size:13px; padding:3px 0; }
  #v-screcord .sr-rec-on { color:#059669; font-weight:700; font-size:12px; }
  /* 20-Oct-2026: evidence pack + hide customer data */
  #v-screcord .sr-mb { display:inline-flex; align-items:center; gap:5px; height:24px; padding:0 9px; border-radius:12px; font-size:11.5px; font-weight:700; white-space:nowrap; background:#123a2b; color:#6ee7b7; }
  #v-screcord .sr-mb.orig { background:#3b2a10; color:#fbbf24; }
  #v-screcord .sr-mb[hidden], #v-screcord .sr-tb[hidden], #v-screcord .sr-dlbtn[hidden], #v-screcord .sr-rbar[hidden], #v-screcord .sr-show[hidden] { display:none !important; }
  #v-screcord .sr-tb { height:30px; padding:0 12px; border-radius:7px; border:1px solid #2c3a46; background:#1c2731; color:#e6edf3; font-size:12.5px; font-weight:600; cursor:pointer; white-space:nowrap; }
  #v-screcord .sr-tb:hover { background:#24323e; }
  #v-screcord .sr-dlbtn.sr-evb { margin-left:0; background:#B8893B; }
  #v-screcord .sr-dlbtn.sr-evb:hover { background:#c99a4c; }
  #v-screcord #sr-dl + .sr-evb { margin-left:8px; }
  #v-screcord .sr-rbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; padding:8px 12px 10px; background:#16212b; border-top:1px solid #1f2b35; }
  #v-screcord .sr-rbar .sr-lbl { color:#e6edf3; }
  #v-screcord .sr-in { flex:1; min-width:220px; height:30px; border-radius:7px; background:#1c2731; color:#e6edf3; border:1px solid #2c3a46; padding:2px 10px; font-size:12.5px; box-sizing:border-box; }
  #v-screcord .sr-show { display:inline-flex !important; }
  #v-screcord #sr-tl-segs .s.hid { opacity:.3; }
  #v-screcord .sr-pill { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; white-space:nowrap; }
  #v-screcord .sr-pill.ok { background:#E3F6EE; color:#0A9464; } #v-screcord .sr-pill.run { background:#E6F1FB; color:#0B72C9; }
  #v-screcord .sr-pill.bad { background:#FBE9ED; color:#D22A4C; } #v-screcord .sr-pill.hid { background:#F3EEE3; color:#8A6420; }
  #v-screcord .sr-acts { display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
  #v-screcord .sr-acts .btn { padding:4px 10px; font-size:12px; }
  #v-screcord #sr-ev-card th, #v-screcord #sr-ev-card .nw { white-space:nowrap; }
</style>

<script>
(function () {
  var COLORS = { continuous: '#2563EB', app_based: '#7C3AED', dashcam: '#DC2626', on_demand: '#059669' };
  var MCOL = { app: '#64748B', site: '#0EA5E9', violation: '#DC2626', idle: '#F59E0B', break: '#10B981' };
  var MNAME = { app: 'Apps', site: 'Sites', violation: 'Violations', idle: 'Idle', break: 'Breaks' };
  var ACCESS = null, DATA = null, SEGS = [], CUR = -1, URLS = {}, VIEWER = '', SEATS = null, SET = null;
  var UNMASK = false, EVS = [], EVPOLL = null; // 20-Oct-2026: original shown (logged) · evidence packs
  var MON = { app: true, site: true, violation: true, idle: true, break: true };
  function el(s) { return document.querySelector(s); }
  function dayStart() { return new Date(el('#sr-date').value + 'T00:00:00').getTime(); }
  // 20-Oct-2026 (Ejaz): Chrome shows a time box in 24-hour form on some PCs, so "09:46" meant 9:46 AM while
  // the player showed 9:46 PM → "No recordings". These boxes show and accept 12-hour time ("9:46 PM"; "21:46"
  // also works); .value still reads/writes "HH:MM" (24 h), so nothing else on the page changes.
  function t12(v) { var p = String(v || '').split(':'); if (p.length < 2) return v || ''; var h = +p[0]; return ((h % 12) || 12) + ':' + p[1].slice(0, 2) + ' ' + (h < 12 ? 'AM' : 'PM'); }
  function time12(inp) {
    var raw = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    function parse(s) {
      var m = String(s || '').trim().match(/^(\d{1,2})(?:[:.](\d{2}))?\s*(?:([ap])\.?\s*m?\.?)?$/i);
      if (!m) return '';
      var h = +m[1], mi = +(m[2] || 0);
      if (m[3]) { if (h < 1 || h > 12) return ''; h = h % 12 + (/p/i.test(m[3]) ? 12 : 0); }
      if (h > 23 || mi > 59) return '';
      return String(h).padStart(2, '0') + ':' + String(mi).padStart(2, '0');
    }
    Object.defineProperty(inp, 'value', { configurable: true,
      get: function () { return parse(raw.get.call(inp)); },
      set: function (v) { raw.set.call(inp, v ? t12(v) : ''); } });
    inp.addEventListener('blur', function () { // show how it was read, e.g. "9:46" → "9:46 AM"
      var t = raw.get.call(inp).trim(), p = parse(t);
      if (p) raw.set.call(inp, t12(p));
      inp.classList.toggle('sr-bad', !!t && !p);
      inp.title = t && !p ? 'Type a time like 9:46 PM' : '';
    });
  }
  ['#sr-dl-from', '#sr-dl-to', '#sr-ctime'].forEach(function (id) { time12(el(id)); });
  function pct(ms) { return Math.max(0, Math.min(100, (ms - dayStart()) / 864000)); }
  // Seek bar = the recorded minutes laid end to end (like one movie). TOTAL ms; each SEGS[i].c = its start on that line.
  var TOTAL = 0, PER = [], PSEL = -1;
  var DL_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></svg>';
  function mins(ms) { var m = Math.round(ms / 60000); return m >= 60 ? Math.floor(m / 60) + 'h ' + (m % 60) + 'm' : Math.max(1, m) + 'm'; }
  function renderPeriods() {
    el('#sr-periods').innerHTML = PER.length ? '<span class="mut" style="padding:0">Recorded periods (' + PER.length + '):</span>' + PER.map(function (p, k) {
      return '<span class="sr-per' + (k === PSEL ? ' on' : '') + '" data-k="' + k + '" title="Play this period">' + hmTag(p.a) + ' – ' + hmTag(p.b)
        + ' <span class="mut">' + mins(p.b - p.a) + '</span><button class="dl" data-dl="' + k + '" title="Download this period">' + DL_ICON + '</button></span>';
    }).join('') : '';
  }
  function markPeriod(i) { var k = PER.findIndex(function (p) { return i >= p.i0 && i <= p.i1; }); if (k !== PSEL) { PSEL = k; renderPeriods(); } }
  function pos(ms) { // wall-clock ms -> % along the bar (null if not inside a recording)
    for (var i = 0; i < SEGS.length; i++) if (ms >= SEGS[i].a && ms < SEGS[i].b) return (SEGS[i].c + ms - SEGS[i].a) / TOTAL * 100;
    return null;
  }
  function atPos(f) { // 0..1 along the bar -> wall-clock ms
    var t = Math.max(0, Math.min(TOTAL - 1, f * TOTAL));
    for (var i = 0; i < SEGS.length; i++) if (t < SEGS[i].c + SEGS[i].b - SEGS[i].a) return SEGS[i].a + t - SEGS[i].c;
    return SEGS.length ? SEGS[SEGS.length - 1].b - 1 : 0;
  }
  function barFrac(e) { var b = el('#sr-tl-segs').getBoundingClientRect(); return Math.max(0, Math.min(1, (e.clientX - b.left) / b.width)); }
  function hms(ms) { var d = new Date(ms); return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }); }
  function hm(iso) { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  // 20-Oct-2026: times after midnight of the chosen day (a shift that runs overnight) carry "(+1 day)".
  function nextDay(ms) { return new Date(ms).getTime() >= dayStart() + 86400000; }
  function hmD(t) { return hm(t) + (nextDay(t) ? ' (+1 day)' : ''); }
  function hmsD(t) { return hms(new Date(t).getTime()) + (nextDay(t) ? ' (+1 day)' : ''); }
  function hmTag(t) { return esc(hm(t)) + (nextDay(t) ? '<span class="sr-plus">+1 day</span>' : ''); }
  function mb(b) { return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(1) + ' MB'; }

  window.applyScreenRecNav = async function () {
    var nav = el('.nav[data-view="screcord"]');
    if (!nav) return;
    try {
      ACCESS = (await api('/screen-recording/access')).data;
      nav.style.display = ACCESS && (ACCESS.can_view || ACCESS.can_manage) ? '' : 'none';
    } catch (e) { nav.style.display = 'none'; }
  };

  window.initScreenRec = async function () {
    if (!el('#sr-date').value) el('#sr-date').value = today();
    try { ACCESS = (await api('/screen-recording/access')).data; } catch (e) { ACCESS = { can_view: false, can_manage: false, employees: [] }; }
    el('#sr-player-card').style.display = ACCESS.can_view ? '' : 'none';
    document.querySelectorAll('#v-screcord .sr-manage').forEach(function (c) { c.style.display = ACCESS.can_manage ? '' : 'none'; });
    var cur = el('#sr-emp').value;
    el('#sr-emp').innerHTML = (ACCESS.employees || []).length
      ? ACCESS.employees.map(function (e) { return '<option value="' + e.id + '">' + esc(e.name) + (e.code ? ' (' + esc(e.code) + ')' : '') + '</option>'; }).join('')
      : '<option value="">No one is recorded yet</option>';
    if (cur) el('#sr-emp').value = cur;
    renderChips();
    el('#sr-ev').hidden = !ACCESS.can_evidence;
    el('#sr-ev-card').hidden = !ACCESS.can_evidence;
    if (ACCESS.can_manage) loadSettings();
  };

  // ------------------------------------------------------------ player
  async function loadDay() {
    var emp = el('#sr-emp').value;
    if (!emp) { toast('Choose an employee first'); return; }
    stopVideo();
    el('#sr-empty').textContent = 'Loading…'; el('#sr-empty').style.display = '';
    try {
      DATA = (await api('/screen-recording/recordings?employee_id=' + encodeURIComponent(emp) + '&date=' + encodeURIComponent(el('#sr-date').value))).data;
    } catch (e) { el('#sr-empty').textContent = e.message; return; }
    VIEWER = DATA.viewer || '';
    UNMASK = false; renderMaskUi(); el('#sr-ubar').hidden = true; el('#sr-evbar').hidden = true;
    if (ACCESS && ACCESS.can_evidence) loadEvidence();
    var mons = {}; DATA.segments.forEach(function (s) { mons[s.monitor] = 1; });
    var keys = Object.keys(mons).sort();
    var curMon = el('#sr-mon').value;
    el('#sr-mon').innerHTML = (keys.length ? keys : ['0']).map(function (k) { return '<option value="' + k + '">Desktop ' + (+k + 1) + '</option>'; }).join('');
    if (keys.indexOf(curMon) !== -1) el('#sr-mon').value = curMon;
    pickMonitor();
    var secs = DATA.segments.reduce(function (a, s) { return a + (s.seconds || 0); }, 0);
    var bytes = DATA.segments.reduce(function (a, s) { return a + (s.bytes || 0); }, 0);
    el('#sr-daysum').textContent = DATA.segments.length ? (secH(secs) + ' recorded · ' + mb(bytes)) : (DATA.days && DATA.days.length ? 'Days with recordings: ' + DATA.days.slice(-6).join(', ') : '');
  }
  function pickMonitor() {
    var mon = +el('#sr-mon').value || 0;
    SEGS = (DATA ? DATA.segments : []).filter(function (s) { return +s.monitor === mon; })
      .map(function (s) { return Object.assign({}, s, { a: new Date(s.start).getTime(), b: new Date(s.end).getTime() }); });
    TOTAL = 0; SEGS.forEach(function (s) { s.c = TOTAL; TOTAL += Math.max(1000, s.b - s.a); s.b = s.a + Math.max(1000, s.b - s.a); });
    CUR = -1;
    drawTimeline(); renderMarks();
    el('#sr-empty').textContent = SEGS.length ? 'Press Play, or drag the bar under the video / click a list entry.' : 'No recordings for this employee on this day.';
    el('#sr-empty').style.display = ''; fitEmpty();
  }
  function drawTimeline() {
    el('#sr-tl-segs').innerHTML = SEGS.map(function (s, i) {
      var l = s.c / TOTAL * 100, w = (s.b - s.a) / TOTAL * 100;
      var gap = i > 0 && s.a - SEGS[i - 1].b > 5000 ? '<div class="g" style="left:' + l + '%" title="Not recorded ' + hmD(SEGS[i - 1].end) + ' – ' + hmD(s.start) + '"></div>' : '';
      var hid = !UNMASK && (s.mask === 'pending' || s.mask === 'failed'); // 20-Oct-2026: not viewable yet (customer-data check)
      return '<div class="s' + (hid ? ' hid' : '') + '" style="left:' + l + '%;width:' + w + '%;background:' + (COLORS[s.trigger] || '#2563EB') + '"'
        + (hid ? ' title="' + (s.mask === 'failed' ? 'Could not be checked for customer data — hidden' : 'Being checked for customer data') + '"' : '') + '></div>' + gap;
    }).join('');
    el('#sr-tl-marks').innerHTML = (DATA ? DATA.markers : []).filter(function (m) { return MON[m.type]; }).map(function (m) {
      var p = pos(new Date(m.at).getTime()); if (p === null) return '';
      return '<div class="m" style="left:' + p + '%;background:' + (MCOL[m.type] || '#64748B') + '"></div>';
    }).join('');
    PER = []; SEGS.forEach(function (s, i) {
      var p = PER[PER.length - 1];
      if (p && s.a - p.b <= 90000) { p.b = s.b; p.i1 = i; } else PER.push({ a: s.a, b: s.b, i0: i, i1: i });
    });
    PSEL = -1; renderPeriods();
    var lastMs = SEGS.length ? SEGS[SEGS.length - 1].b - 1 : 0;
    el('#v-screcord').classList.toggle('sr-overnight', SEGS.length > 0 && nextDay(lastMs));
    if (SEGS.length) {
      el('#sr-dl-from').value = hhmm(SEGS[0].a); el('#sr-dl-fromnext').checked = nextDay(SEGS[0].a);
      el('#sr-dl-to').value = hhmm(lastMs); el('#sr-dl-tonext').checked = nextDay(lastMs);
    }
    drawSel();
    el('#sr-tl-hours').innerHTML = SEGS.length
      ? '<span style="left:12px">Start: ' + esc(hmsD(SEGS[0].a)) + '</span><span style="right:12px">End: ' + esc(hmsD(SEGS[SEGS.length - 1].b)) + '</span>' : '';
  }
  function renderChips() {
    el('#sr-chips').innerHTML = Object.keys(MNAME).map(function (k) {
      return '<button data-k="' + k + '" class="' + (MON[k] ? 'on' : '') + '">' + MNAME[k] + '</button>';
    }).join('');
  }
  function renderMarks() {
    var q = (el('#sr-q').value || '').toLowerCase();
    var rows = (DATA ? DATA.markers : []).filter(function (m) {
      return MON[m.type] && (!q || ((m.label || '') + ' ' + (m.detail || '')).toLowerCase().indexOf(q) !== -1);
    });
    el('#sr-marks').innerHTML = rows.length ? rows.slice(0, 2000).map(function (m) {
      return '<div class="r ' + m.type + '" data-at="' + esc(m.at) + '"><span class="t">' + hmTag(m.at) + '</span><span style="min-width:0"><span class="l">' + esc(m.label) + '</span> <span class="d">' + esc(m.detail || '') + '</span></span></div>';
    }).join('') : '<div class="mut" style="padding:10px">Nothing to show.</div>';
  }

  // 20-Oct-2026: like apiBlob, but keeps the server's message (e.g. "still being checked for customer data").
  async function getBlob(path) {
    var r = await fetch(API + path, { headers: { Authorization: 'Bearer ' + TOKEN, Accept: '*/*' } });
    if (!r.ok) {
      var j = await r.json().catch(function () { return {}; });
      var e = new Error((j.error && j.error.message) || j.message || ('HTTP ' + r.status)); e.status = r.status; e.code = j.error && j.error.code; throw e;
    }
    return r.blob();
  }
  async function blobUrl(i) {
    var s = SEGS[i]; if (!s) return null;
    if (URLS[s.id]) return URLS[s.id];
    var b = await getBlob('/screen-recording/recordings/' + s.id + '/file' + (UNMASK ? '?original=1' : ''));
    URLS[s.id] = URL.createObjectURL(b);
    return URLS[s.id];
  }
  function stopVideo() {
    var v = el('#sr-video'); try { v.pause(); } catch (e) {}
    v.removeAttribute('src'); v.load();
    Object.keys(URLS).forEach(function (k) { URL.revokeObjectURL(URLS[k]); }); URLS = {};
    el('#sr-tl-now').style.display = 'none'; el('#sr-wm').textContent = '';
    setPlayBtn(false);
  }
  var IC_PLAY = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>', IC_PAUSE = '<svg viewBox="0 0 24 24"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>';
  function setPlayBtn(on) { var b = el('#sr-play'); b.innerHTML = on ? IC_PAUSE : IC_PLAY; b.title = on ? 'Pause' : 'Play'; b.setAttribute('aria-label', b.title); }
  async function playAt(i, offsetSec, autoplay) {
    if (i < 0 || i >= SEGS.length) { setPlayBtn(false); return; }
    var v = el('#sr-video');
    try {
      var url = await blobUrl(i);
      CUR = i;
      v.src = url;
      el('#sr-empty').style.display = 'none';
      el('#sr-wm').textContent = 'Viewed by ' + VIEWER + ' · ' + new Date().toLocaleString() + (UNMASK ? ' · ORIGINAL (logged)' : '');
      await new Promise(function (res) { v.onloadedmetadata = res; });
      // MediaRecorder WebM carries no duration — ask the browser to work it out once so seeking works.
      if (!isFinite(v.duration)) {
        await new Promise(function (res) { v.ontimeupdate = function () { v.ontimeupdate = null; res(); }; v.currentTime = 1e101; });
      }
      v.currentTime = Math.max(0, offsetSec || 0);
      v.playbackRate = +el('#sr-speed').value || 1;
      if (autoplay !== false) { await v.play().catch(function () {}); setPlayBtn(true); }
      blobUrl(i + 1).catch(function () {}); // fetch the next minute ahead
      Object.keys(URLS).forEach(function (k) { // keep memory small: only nearby minutes
        var idx = SEGS.findIndex(function (s) { return String(s.id) === k; });
        if (idx !== -1 && (idx < i - 1 || idx > i + 2)) { URL.revokeObjectURL(URLS[k]); delete URLS[k]; }
      });
    } catch (e) {
      if (e.status === 423) { // 20-Oct-2026: not viewable while customer data is hidden — say why, don't skip silently
        CUR = i; setPlayBtn(false); v.removeAttribute('src'); v.load();
        el('#sr-empty').textContent = hmsD(SEGS[i].a) + ' — ' + e.message + ' Use ▶▶ for the next minute.'; el('#sr-empty').style.display = '';
        return;
      }
      toast('Could not load this minute: ' + e.message); playAt(i + 1, 0, autoplay);
    }
  }
  function jumpTo(ms) {
    if (!SEGS.length) return;
    var i = SEGS.findIndex(function (s) { return ms >= s.a && ms < s.b; });
    if (i !== -1) return playAt(i, (ms - SEGS[i].a) / 1000);
    i = SEGS.findIndex(function (s) { return s.a > ms; });
    if (i === -1) { toast('No recording after that time'); return; }
    toast('Not recorded at that moment — showing the next recording (' + hmD(SEGS[i].start) + ')');
    playAt(i, 0);
  }

  // ------------------------------------------------------------ seats
  async function loadSettings() {
    try {
      var d = (await api('/screen-recording/settings')).data;
      SET = d.settings; SEATS = d.seats;
      fillSettings(SET);
      renderSeats();
      el('#sr-cleansum').textContent = 'Stored now: ' + d.storage.files + ' minute(s), ' + mb(d.storage.bytes)
        + (d.storage.oldest ? ' · oldest ' + new Date(d.storage.oldest).toLocaleDateString() : '')
        + ' · last cleanup: ' + (d.last_cleanup_at ? new Date(d.last_cleanup_at).toLocaleString() : 'never');
    } catch (e) {
      el('#sr-seat-rows').innerHTML = '<tr><td colspan="4" class="mut">' + esc(e.message) + '</td></tr>';
    }
  }
  function renderSeats() {
    if (!SEATS) return;
    el('#sr-seat-sum').textContent = SEATS.used + ' of ' + SEATS.limit + ' Screen Recording users in use (from your licence)';
    var q = (el('#sr-seat-q').value || '').toLowerCase(), only = el('#sr-seat-only').checked;
    var rows = SEATS.employees.filter(function (e) {
      return (!only || e.granted) && (!q || (e.name + ' ' + (e.code || '')).toLowerCase().indexOf(q) !== -1);
    });
    el('#sr-seat-rows').innerHTML = rows.length ? rows.map(function (e) {
      var live = e.record_now_until && new Date(e.record_now_until).getTime() > Date.now();
      return '<tr><td>' + esc(e.name) + '</td><td>' + esc(e.code || '') + '</td>'
        + '<td><input type="checkbox" class="sr-seat" data-id="' + e.id + '"' + (e.granted ? ' checked' : '') + '></td>'
        + '<td>' + (e.granted
          ? (live ? '<span class="sr-rec-on">● Recording until ' + hm(e.record_now_until) + '</span> <button class="btn danger sr-stop" data-id="' + e.id + '">Stop</button>'
            : '<select class="sr-min" data-id="' + e.id + '" style="min-width:0"><option value="15">15 min</option><option value="30" selected>30 min</option><option value="60">1 hour</option><option value="120">2 hours</option><option value="240">4 hours</option><option value="480">8 hours</option></select> <button class="btn sr-go" data-id="' + e.id + '">Record now</button>')
          : '<span class="mut" style="padding:0">Tick "Recorded" first</span>') + '</td></tr>';
    }).join('') : '<tr><td colspan="4" class="mut">No employees match.</td></tr>';
  }

  // ------------------------------------------------------------ settings
  function lines(v) { return (v || '').split(/[\n,]+/).map(function (x) { return x.trim(); }).filter(Boolean); }
  function setSel(id, v) { var s = el(id); if (![].some.call(s.options, function (o) { return o.value === String(v); })) s.insertAdjacentHTML('beforeend', '<option value="' + esc(v) + '">' + esc(v) + '</option>'); s.value = String(v); }
  function fillSettings(s) {
    document.querySelectorAll('input[name="sr-mode"]').forEach(function (c) { c.checked = (s.modes || []).indexOf(c.value) !== -1; });
    setSel('#sr-pre', s.pre_seconds); setSel('#sr-post', s.post_seconds);
    el('#sr-watch').value = (s.watched_apps || []).join('\n'); el('#sr-excl').value = (s.excluded_apps || []).join('\n');
    el('#sr-qual').value = s.quality; el('#sr-mons').value = s.monitors;
    el('#sr-pidle').checked = !!s.pause_on_idle; el('#sr-pbrk').checked = !!s.pause_on_break; el('#sr-ind').checked = !!s.show_indicator;
    setSel('#sr-kbps', s.upload_kbps); setSel('#sr-cap', s.local_cap_mb);
    el('#sr-ret').value = s.retention_days; el('#sr-freq').value = s.cleanup_frequency; el('#sr-ctime').value = s.cleanup_time;
    el('#sr-cn').value = s.cleanup_every_days; el('#sr-cwd').value = String(s.cleanup_weekday); el('#sr-cmd').value = s.cleanup_month_day;
    el('#sr-split').value = String(s.download_split_minutes != null ? s.download_split_minutes : 1);
    var mk = (ACCESS && ACCESS.masking) || {}, tl = mk.tools || {}, names = { card: 'card', account: 'bank account', upi: 'UPI ID', pan: 'PAN', aadhaar: 'Aadhaar', passport: 'passport', voter: 'voter ID', dl: 'driving licence', ssn: 'SSN', abha: 'ABHA', phone: 'phone / WhatsApp', email: 'email' };
    el('#sr-maskinfo').innerHTML = mk.on
      ? '<b style="color:#059669">On</b> — ' + esc((mk.types || []).map(function (k) { return names[k] || k; }).join(', ')) + ' are blurred.'
        + '<br>Each employee\u2019s PC reads its own screen (Windows\u2019 built-in text reader) and uploads a blurred copy with the original, which stays sealed. Minutes from agents older than 20-Oct-2026 are kept hidden.'
      : '<b>Off</b> — recordings play exactly as recorded.';
    freqUi();
  }
  function freqUi() {
    var f = el('#sr-freq').value;
    el('#sr-row-n').style.display = f === 'every_n_days' ? '' : 'none';
    el('#sr-row-wd').style.display = f === 'weekly' ? '' : 'none';
    el('#sr-row-md').style.display = f === 'monthly' ? '' : 'none';
  }
  async function saveSettings() {
    var modes = [].filter.call(document.querySelectorAll('input[name="sr-mode"]'), function (c) { return c.checked; }).map(function (c) { return c.value; });
    if (!modes.length) { el('#sr-save-msg').textContent = 'Tick at least one "When to record" option.'; return; }
    if (modes.indexOf('app_based') !== -1 && !lines(el('#sr-watch').value).length) { el('#sr-save-msg').textContent = 'App-based needs at least one app or site to watch.'; return; }
    var body = {
      modes: modes, quality: el('#sr-qual').value, watched_apps: lines(el('#sr-watch').value), excluded_apps: lines(el('#sr-excl').value),
      pre_seconds: +el('#sr-pre').value, post_seconds: +el('#sr-post').value,
      pause_on_idle: el('#sr-pidle').checked, pause_on_break: el('#sr-pbrk').checked, monitors: el('#sr-mons').value,
      show_indicator: el('#sr-ind').checked, upload_kbps: +el('#sr-kbps').value, local_cap_mb: +el('#sr-cap').value,
      retention_days: +el('#sr-ret').value || 30, cleanup_frequency: el('#sr-freq').value, cleanup_time: el('#sr-ctime').value || '02:00',
      cleanup_every_days: +el('#sr-cn').value || 1, cleanup_weekday: +el('#sr-cwd').value || 0, cleanup_month_day: +el('#sr-cmd').value || 1,
      download_split_minutes: +el('#sr-split').value,
    };
    el('#sr-save-msg').textContent = 'Saving…';
    try {
      SET = (await api('/screen-recording/settings', { method: 'PUT', body: JSON.stringify(body) })).data;
      el('#sr-save-msg').textContent = 'Saved. PCs pick this up within a minute.';
      toast('Screen Recording settings saved');
    } catch (e) { el('#sr-save-msg').textContent = e.message; }
  }

  function fitEmpty() { // 20-Oct-2026: the message sits over the picture, never behind the bars under it
    var st = el('#v-screcord .sr-stage'), tl = el('#sr-tl');
    if (st && tl && st.offsetHeight) el('#sr-empty').style.bottom = (st.offsetHeight - tl.offsetTop) + 'px';
  }
  if (window.ResizeObserver) new ResizeObserver(fitEmpty).observe(el('#v-screcord .sr-stage')); else window.addEventListener('resize', fitEmpty);
  // ------------------------------------------------------------ hide customer data (20-Oct-2026, DPDP Act)
  function renderMaskUi() {
    var on = !!(DATA && DATA.masking), b = el('#sr-maskbadge'), u = el('#sr-unmask');
    b.hidden = !on; u.hidden = !(on && ACCESS && ACCESS.can_unmask);
    b.className = 'sr-mb' + (UNMASK ? ' orig' : '');
    b.textContent = UNMASK ? '● Original shown · logged' : '● Customer data hidden';
    b.title = UNMASK ? 'You are seeing the original recording. This was logged with your reason.' : 'Personal data — the kinds ticked in Organisation → Privacy (cards, accounts, IDs, phone numbers, emails…) — is blurred (DPDP Act). The original stays sealed.';
    u.textContent = UNMASK ? 'Hide again' : 'Show original';
  }
  function reloadView() { // same moment, other file (blurred copy ↔ original)
    var v = el('#sr-video'), i = CUR, at = isFinite(v.currentTime) ? v.currentTime : 0, playing = !v.paused;
    Object.keys(URLS).forEach(function (k) { URL.revokeObjectURL(URLS[k]); }); URLS = {};
    renderMaskUi(); drawTimeline();
    if (i !== -1) playAt(i, at, playing);
  }
  async function doUnmask() {
    var r = (el('#sr-ureason').value || '').trim();
    if (r.length < 5) { toast('Write a reason (at least 5 characters) — it is logged'); return; }
    try {
      await api('/screen-recording/unmask', { method: 'POST', body: JSON.stringify({ employee_id: +el('#sr-emp').value, date: el('#sr-date').value, reason: r }) });
      DATA.unmasked = true; UNMASK = true; el('#sr-ubar').hidden = true; el('#sr-ureason').value = '';
      toast('Original shown — logged with your reason'); reloadView();
    } catch (e) { toast(e.message); }
  }

  // ------------------------------------------------------------ evidence packs (20-Oct-2026)
  function dtl(iso) { return new Date(iso).toLocaleString([], { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }); }
  function per(e) { var a = new Date(e.from), b = new Date(e.to); return dtl(e.from) + ' – ' + (a.toDateString() === b.toDateString() ? b.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : dtl(e.to)); }
  async function loadEvidence() {
    var emp = el('#sr-emp').value; if (!emp) return;
    try { EVS = (await api('/screen-recording/evidence?employee_id=' + encodeURIComponent(emp))).data || []; }
    catch (e) { el('#sr-ev-rows').innerHTML = '<tr><td colspan="7" class="mut">' + esc(e.message) + '</td></tr>'; return; }
    renderEvidence();
    clearTimeout(EVPOLL);
    if (EVS.some(function (x) { return x.status === 'queued' || x.status === 'running'; })) EVPOLL = setTimeout(loadEvidence, 4000);
  }
  function renderEvidence() {
    var st = { queued: ['run', 'Queued'], running: ['run', 'Building…'], done: ['ok', 'Ready'], failed: ['bad', 'Failed'] };
    el('#sr-ev-rows').innerHTML = EVS.length ? EVS.map(function (x) {
      var s = st[x.status] || ['run', x.status];
      return '<tr><td><b>' + esc(x.code) + '</b>' + (x.reason ? '<div class="mut" style="padding:0;font-size:11.5px">Reason: ' + esc(x.reason) + '</div>' : '') + '</td>'
        + '<td class="nw">' + esc(per(x)) + '<div class="mut" style="padding:0;font-size:11.5px">Screen: ' + (x.monitor + 1) + '</div></td>'
        + '<td>' + (x.minutes || '—') + '</td>'
        + '<td><span class="sr-pill ' + (x.masked ? 'hid' : 'run') + '">' + (x.masked ? 'Hidden' : 'Original') + '</span></td>'
        + '<td><span class="sr-pill ' + s[0] + '">' + s[1] + '</span>' + (x.error ? '<div style="color:#B42318;font-size:11.5px;max-width:320px">' + esc(x.error) + '</div>' : '') + '</td>'
        + '<td class="nw">' + esc(x.created_by || '—') + '<div class="mut" style="padding:0;font-size:11.5px">' + esc(x.created_at ? dtl(x.created_at) : '') + '</div></td>'
        + '<td><div class="sr-acts">' + (x.status === 'done'
          ? '<button class="btn solid" data-evzip="' + x.id + '">Download ZIP</button><button class="btn" data-evpdf="' + x.id + '">PDF</button><button class="btn" data-evver="' + x.id + '">Verify</button>'
          : '') + '</div></td></tr>';
    }).join('') : '<tr><td colspan="7" class="mut">No evidence packs for this employee yet. Pick From–To under the player and press Evidence pack.</td></tr>';
  }
  async function createEvidence() {
    var f = el('#sr-dl-from').value, t = el('#sr-dl-to').value, r = (el('#sr-evreason').value || '').trim();
    if (!f || !t) { toast('Pick From and To first'); return; }
    if (r.length < 5) { toast('Write a reason or case reference (at least 5 characters)'); return; }
    var fN = el('#sr-dl-fromnext').checked, tN = el('#sr-dl-tonext').checked, btn = el('#sr-evgo');
    btn.disabled = true;
    try {
      await api('/screen-recording/evidence', { method: 'POST', body: JSON.stringify({
        employee_id: +el('#sr-emp').value, date: el('#sr-date').value, from: f, to: t, monitor: +el('#sr-mon').value || 0, reason: r,
        from_date: fN ? nextDate() : null, to_date: tN ? nextDate() : null, original: !el('#sr-evorig-l').hidden && el('#sr-evorig').checked }) });
      el('#sr-evbar').hidden = true; el('#sr-evreason').value = ''; el('#sr-evorig').checked = false;
      toast('Evidence pack is being built — it appears in Evidence packs below'); loadEvidence();
    } catch (e) { toast(e.message); }
    btn.disabled = false;
  }
  async function evidencePdf(id) {
    if (!window.eptAuditPdf) { toast('PDF export is not available on this screen'); return; }
    var x;
    try { x = (await api('/screen-recording/evidence/' + id)).data; } catch (e) { toast(e.message); return; }
    var m = x.manifest || {}, mins = m.minutes || [], vids = m.videos || {};
    var tb = function (head, rows) { return '<table><thead><tr>' + head.map(function (h) { return '<th' + (h[1] ? ' style="width:' + h[1] + '"' : '') + '>' + esc(h[0]) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>'; };
    var td = function (v, c) { return '<td' + (c ? ' class="' + c + '"' : '') + '>' + v + '</td>'; };
    var hm = function (s) { return new Date(String(s).replace(' ', 'T')).toLocaleString([], { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', second: '2-digit' }); };
    var mono = function (h) { return '<span style="font:9px ui-monospace,Consolas,monospace;word-break:break-all">' + esc(h || '—') + '</span>'; };
    var body = '<h2>1. Integrity</h2>' + tb([['File'], ['SHA-256', '62%']],
        ['<tr>' + td('<b>' + esc(x.code) + '.zip</b> (the whole evidence pack)') + td(mono(x.zip_sha256)) + '</tr>']
          .concat(Object.keys(vids).map(function (n) { return '<tr>' + td(esc(n) + ' (watermarked video)') + td(mono(vids[n])) + '</tr>'; })))
      + (m.note ? '<p class="mut">' + esc(m.note) + '</p>' : '')
      + '<p>All ' + mins.length + ' recorded minutes were checked against the fingerprint taken when each reached the server, before this pack was made. None had changed.</p>'
      + '<h2>2. Recorded minutes (' + mins.length + ')</h2>' + tb([['#', '5%'], ['Start', '17%'], ['End', '17%'], ['Mode', '11%'], ['SHA-256 (taken on arrival)'], ['Fingerprinted', '13%']],
        mins.map(function (r, i) { return '<tr>' + td(i + 1) + td(esc(hm(r.start))) + td(esc(hm(r.end))) + td(esc(String(r.trigger).replace('_', ' '))) + td(mono(r.sha256))
          + td(r.fingerprinted_at_upload ? 'On arrival' : 'Later: ' + esc(r.fingerprinted ? hm(r.fingerprinted) : '')) + '</tr>'; }))
      + '<h2>3. Apps, sites and violations during the period (' + (m.activity || []).length + ')</h2>'
      + ((m.activity || []).length ? tb([['Time', '18%'], ['Type', '11%'], ['What', '24%'], ['Detail']], m.activity.slice(0, 600).map(function (a) {
          return '<tr' + (a.type === 'violation' ? ' class="bad"' : '') + '>' + td(esc(new Date(a.at).toLocaleString([], { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', second: '2-digit' })))
            + td(esc({ app: 'App', site: 'Website', violation: 'Violation' }[a.type] || a.type)) + td('<b>' + esc(a.label) + '</b>') + td(esc(a.detail || '')) + '</tr>'; }))
        : '<p class="mut">No app, website or violation records in this period.</p>')
      + '<h2>4. Chain of custody — who watched, downloaded or exported it</h2>' + tb([['When', '18%'], ['Who', '17%'], ['Action', '22%'], ['Detail'], ['IP', '12%']],
        (m.custody || []).map(function (c) { return '<tr>' + td(esc(hm(c.at))) + td(esc(c.who)) + td(esc(c.action)) + td(esc(c.detail || '')) + td(esc(c.ip || '')) + '</tr>'; }))
      + '<h2>5. How to verify</h2><p>1. Check the SHA-256 of ' + esc(x.code) + '.zip against section 1 (Windows: <i>certutil -hashfile ' + esc(x.code) + '.zip SHA256</i>; Linux / Mac: <i>sha256sum</i>).<br>'
      + (x.masked ? '2. Customer data is hidden in this pack. The original minutes stay sealed on the SmartEPT server; the organisation can re-check them at any time with Screen Recording → Evidence packs → Verify.'
        : '2. Every file in the ZIP’s originals folder must give exactly the SHA-256 listed in section 2.') + '<br>3. The video files are made from those minutes; their own SHA-256 is in section 1.</p>';
    window.eptAuditPdf({
      file: 'SmartEPT-Evidence-' + x.code, ref: x.code, type: 'Screen Recording evidence pack',
      title: 'Screen Recording Evidence — ' + (m.employee || ''), subtitle: 'Period: ' + per(x) + ' · Screen: ' + (x.monitor + 1),
      meta: [['Evidence reference', x.code], ['Employee', (m.employee || '—') + (m.employee_code ? ' (' + m.employee_code + ')' : '')], ['Period', per(x)],
        ['Recorded time', secH(60 * mins.length)], ['Customer data', x.masked ? 'Hidden (DPDP Act) — personal data blurred' : 'Original — not hidden'],
        ['Reason / case', x.reason || '—'], ['Pack created by', x.created_by || '—'], ['Pack created on', x.created_at ? dtl(x.created_at) : '—'],
        ['Evidence ZIP SHA-256', (x.zip_sha256 || '').slice(0, 16) + '…']],
      body: body,
      declaration: 'Each recorded minute was fingerprinted (SHA-256 and a server seal) when it reached the server and re-checked before the pack was made'
        + (x.masked ? '; customer identifiers are blurred in the video and the original stays sealed on the server' : ''),
    });
  }
  async function evidenceAction(t) {
    var z = t.getAttribute('data-evzip'), p = t.getAttribute('data-evpdf'), v = t.getAttribute('data-evver');
    if (p) return evidencePdf(p);
    if (z) {
      var x = EVS.find(function (e) { return String(e.id) === z; }); t.disabled = true;
      try { var b = await getBlob('/screen-recording/evidence/' + z + '/download'); var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = (x ? x.code : 'evidence') + '.zip'; a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000); }
      catch (e) { toast(e.message); }
      t.disabled = false; return;
    }
    if (v) {
      t.disabled = true;
      try {
        var r = (await api('/screen-recording/evidence/' + v + '/verify', { method: 'POST' })).data;
        toast(r.ok ? '✓ Verified: the ZIP and all ' + r.minutes + ' original minutes match their fingerprints'
          : '✕ Verification FAILED' + (r.zip_ok ? '' : ' — the ZIP changed') + (r.missing ? ' — ' + r.missing + ' minute(s) no longer on the server' : '') + (r.problems.length ? ' — ' + r.problems.slice(0, 3).join('; ') : ''));
      } catch (e) { toast(e.message); }
      t.disabled = false;
    }
  }

  // ------------------------------------------------------------ wiring (once)
  el('#sr-load').onclick = loadDay;
  el('#sr-mon').onchange = function () { stopVideo(); pickMonitor(); };
  el('#sr-q').oninput = renderMarks;
  el('#sr-chips').onclick = function (e) { var k = e.target.getAttribute && e.target.getAttribute('data-k'); if (!k) return; MON[k] = !MON[k]; renderChips(); renderMarks(); drawTimeline(); };
  el('#sr-marks').onclick = function (e) { var r = e.target.closest('.r'); if (r) jumpTo(new Date(r.getAttribute('data-at')).getTime()); };
  // drag (or click) the seek bar like a video player; the tooltip shows the real time under the pointer
  var DRAG = false;
  function showTip(e) {
    if (!SEGS.length) return;
    var f = barFrac(e), tip = el('#sr-tl-tip'), b = el('#sr-tl-segs').getBoundingClientRect(), o = el('#sr-tl').getBoundingClientRect();
    tip.textContent = hmsD(atPos(f)) + (DRAG ? '' : '  ·  right-click to mark From / To'); tip.style.display = 'block';
    var half = tip.offsetWidth / 2 + 4; tip.style.left = Math.max(half, Math.min(o.width - half, b.left - o.left + f * b.width)) + 'px';
    if (DRAG) { var n = el('#sr-tl-now'); n.style.display = 'block'; n.style.left = 'calc(' + f + ' * (100% - 24px))'; }
  }
  el('#sr-tl').addEventListener('pointerdown', function (e) { if (!SEGS.length || e.button !== 0) return; /* right button = mark menu */ DRAG = true; this.setPointerCapture(e.pointerId); showTip(e); });
  el('#sr-tl').addEventListener('pointermove', showTip);
  el('#sr-tl').addEventListener('pointerup', function (e) { if (!DRAG) return; DRAG = false; jumpTo(atPos(barFrac(e))); });
  el('#sr-tl').addEventListener('pointerleave', function () { if (!DRAG) el('#sr-tl-tip').style.display = 'none'; });
  el('#sr-play').onclick = function () {
    var v = el('#sr-video');
    if (CUR === -1) { if (SEGS.length) playAt(0, 0); return; }
    if (v.paused) { v.play(); setPlayBtn(true); } else { v.pause(); setPlayBtn(false); }
  };
  el('#sr-prev').onclick = function () { if (CUR > 0) playAt(CUR - 1, 0); };
  el('#sr-next').onclick = function () { if (CUR < SEGS.length - 1) playAt(CUR + 1, 0); };
  // 20-Oct-2026 (Ejaz): Stop / close the playing video, and Full screen (the whole player: video,
  // timeline and controls — so the scrub bar and watermark stay visible).
  el('#sr-stop').onclick = function () {
    var v = el('#sr-video');
    v.pause(); v.removeAttribute('src'); v.load();
    CUR = -1; setPlayBtn(false);
    el('#sr-clock').textContent = '--:--:--'; el('#sr-tl-now').style.display = 'none';
    el('#sr-empty').textContent = SEGS.length ? 'Stopped. Press Play, or drag the bar under the video / click a list entry.' : 'Choose an employee and a day, then press Show.';
    el('#sr-empty').style.display = '';
    if (document.fullscreenElement) document.exitFullscreen().catch(function () {});
  };
  el('#sr-fs').onclick = function () {
    var st = el('#v-screcord .sr-stage');
    if (document.fullscreenElement) { document.exitFullscreen().catch(function () {}); return; }
    (st.requestFullscreen ? st.requestFullscreen() : Promise.reject()).catch(function () { toast('Full screen is not available in this browser'); });
  };
  document.addEventListener('fullscreenchange', function () {
    var b = el('#sr-fs'); if (!b) return;
    b.innerHTML = document.fullscreenElement
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';
    b.title = document.fullscreenElement ? 'Exit full screen (Esc)' : 'Full screen (Esc to exit)';
  });
  el('#sr-speed').onchange = function () { el('#sr-video').playbackRate = +this.value || 1; };
  el('#sr-video').addEventListener('ended', function () { if (CUR < SEGS.length - 1) playAt(CUR + 1, 0); else setPlayBtn(false); });
  el('#sr-video').addEventListener('timeupdate', function () {
    var s = SEGS[CUR]; if (!s || !isFinite(this.currentTime) || this.currentTime > 86400) return;
    var ms = s.a + this.currentTime * 1000;
    el('#sr-clock').textContent = hmsD(ms);
    markPeriod(CUR);
    var p = pos(ms); if (p === null || DRAG) return;
    var n = el('#sr-tl-now'); n.style.display = 'block'; n.style.left = 'calc(' + (p / 100) + ' * (100% - 24px))';
  });
  // 20-Oct-2026 (Ejaz): mark Download From / To straight from the seek bar.
  function barAt(ms) { // wall-clock ms -> 0..1 on the bar; times between recordings snap to the next recording
    if (!SEGS.length) return 0;
    for (var i = 0; i < SEGS.length; i++) {
      if (ms < SEGS[i].a) return SEGS[i].c / TOTAL;
      if (ms < SEGS[i].b) return (SEGS[i].c + ms - SEGS[i].a) / TOTAL;
    }
    return 1;
  }
  function boxMs(t, next) { var p = (t || '').split(':'); return p.length < 2 ? null : dayStart() + (next ? 86400000 : 0) + (+p[0] * 60 + +p[1]) * 60000; }
  function boxLabel(t, n) { var ms = boxMs(el(t).value, el(n).checked); return ms === null ? '—' : hmD(new Date(ms).toISOString()); }
  function drawSel() {
    var sel = el('#sr-tl-sel'), a = boxMs(el('#sr-dl-from').value, el('#sr-dl-fromnext').checked), b = boxMs(el('#sr-dl-to').value, el('#sr-dl-tonext').checked);
    if (!SEGS.length || a === null || b === null || b < a) { sel.style.display = 'none'; return; }
    var fa = barAt(a), fb = barAt(b + 59999);
    sel.style.display = 'block';
    sel.style.left = 'calc(12px + ' + fa + ' * (100% - 24px))'; sel.style.width = 'calc(' + Math.max(0, fb - fa) + ' * (100% - 24px))';
  }
  var MARK = 0;
  el('#sr-tl').addEventListener('contextmenu', function (e) {
    if (!SEGS.length) return;
    e.preventDefault();
    MARK = atPos(barFrac(e));
    var m = el('#sr-tl-menu'), st = el('#v-screcord .sr-stage').getBoundingClientRect();
    // 20-Oct-2026: the clicked moment once (with seconds), and what From / To are now
    el('#sr-mk-at').textContent = hmsD(MARK);
    el('#sr-mk-from').textContent = 'Current From: ' + boxLabel('#sr-dl-from', '#sr-dl-fromnext');
    el('#sr-mk-to').textContent = 'Current To: ' + boxLabel('#sr-dl-to', '#sr-dl-tonext');
    m.hidden = false;
    var half = m.offsetWidth / 2 + 6;
    m.style.left = Math.max(half, Math.min(st.width - half, e.clientX - st.left)) + 'px';
    m.style.top = (el('#sr-tl').getBoundingClientRect().top - st.top - m.offsetHeight - 8) + 'px'; // just above the bar
    el('#sr-tl-tip').style.display = 'none';
  });
  el('#sr-tl-menu').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var k = b.getAttribute('data-m');
    el(k === 'from' ? '#sr-dl-from' : '#sr-dl-to').value = hhmm(MARK);
    el(k === 'from' ? '#sr-dl-fromnext' : '#sr-dl-tonext').checked = nextDay(MARK);
    var a = boxMs(el('#sr-dl-from').value, el('#sr-dl-fromnext').checked), z = boxMs(el('#sr-dl-to').value, el('#sr-dl-tonext').checked), sw = '';
    if (a !== null && z !== null && z < a) { // To before From → swap, so the range is always valid
      var f = el('#sr-dl-from').value, fn = el('#sr-dl-fromnext').checked;
      el('#sr-dl-from').value = el('#sr-dl-to').value; el('#sr-dl-fromnext').checked = el('#sr-dl-tonext').checked;
      el('#sr-dl-to').value = f; el('#sr-dl-tonext').checked = fn; sw = ' (From and To swapped so From is earlier)';
    }
    this.hidden = true; drawSel();
    toast('Download ' + (k === 'from' ? 'From' : 'To') + ' set to ' + hmD(new Date(MARK).toISOString()) + sw);
  });
  document.addEventListener('pointerdown', function (e) { var m = el('#sr-tl-menu'); if (m && !m.hidden && !m.contains(e.target)) m.hidden = true; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { var m = el('#sr-tl-menu'); if (m) m.hidden = true; } });
  ['#sr-dl-from', '#sr-dl-to', '#sr-dl-fromnext', '#sr-dl-tonext'].forEach(function (id) { el(id).addEventListener('change', drawSel); });
  function hhmm(ms) { var x = new Date(ms); return String(x.getHours()).padStart(2, '0') + ':' + String(x.getMinutes()).padStart(2, '0'); }
  function nextDate() { var x = new Date(dayStart() + 86400000 + 3600000); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); }
  async function downloadRange(f, t, btn, fNext, tNext) {
    if ((tNext ? 1 : 0) * 10000 + +t.replace(':', '') < (fNext ? 1 : 0) * 10000 + +f.replace(':', '')) { toast('"To" must be after "From"'); return; }
    var old = btn.innerHTML; btn.disabled = true; btn.textContent = '…';
    try {
      var b = await getBlob('/screen-recording/download?employee_id=' + el('#sr-emp').value + '&date=' + el('#sr-date').value + (UNMASK ? '&original=1' : '')
        + '&from=' + f + '&to=' + t + '&monitor=' + (+el('#sr-mon').value || 0)
        + (fNext ? '&from_date=' + nextDate() : '') + (tNext ? '&to_date=' + nextDate() : '')); // 20-Oct-2026: overnight
      var a = document.createElement('a'); a.href = URL.createObjectURL(b);
      a.download = 'recording-' + el('#sr-emp').value + '-' + el('#sr-date').value + '-' + f.replace(':', '') + '-' + t.replace(':', '')
        + (/webm/.test(b.type) ? '.webm' : '.zip'); // 20-Oct-2026: one joined file comes as the video itself
      a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 60000);
    } catch (e) { toast(e.status === 404 ? 'No recordings between ' + t12(f) + ' and ' + t12(t) : e.status === 423 ? e.message : 'Download failed: ' + e.message); }
    btn.disabled = false; btn.innerHTML = old;
  }
  el('#sr-dl').onclick = function () {
    var f = el('#sr-dl-from').value, t = el('#sr-dl-to').value;
    if (!f || !t) { toast('Pick From and To'); return; }
    downloadRange(f, t, this, el('#sr-dl-fromnext').checked, el('#sr-dl-tonext').checked);
  };
  el('#sr-unmask').onclick = function () { // 20-Oct-2026
    if (UNMASK) { UNMASK = false; toast('Customer data hidden again'); reloadView(); return; }
    if (DATA && DATA.unmasked) { UNMASK = true; reloadView(); return; } // already logged with a reason (last 4 hours)
    var b = el('#sr-ubar'); b.hidden = !b.hidden; if (!b.hidden) el('#sr-ureason').focus();
  };
  el('#sr-ugo').onclick = doUnmask;
  el('#sr-ureason').onkeydown = function (e) { if (e.key === 'Enter') doUnmask(); };
  el('#sr-ucancel').onclick = function () { el('#sr-ubar').hidden = true; };
  el('#sr-ev').onclick = function () {
    var b = el('#sr-evbar'); b.hidden = !b.hidden;
    el('#sr-evorig-l').hidden = !(DATA && DATA.masking && ACCESS && ACCESS.can_unmask);
    if (!b.hidden) el('#sr-evreason').focus();
  };
  el('#sr-evgo').onclick = createEvidence;
  el('#sr-evcancel').onclick = function () { el('#sr-evbar').hidden = true; };
  el('#sr-ev-rows').onclick = function (e) { var t = e.target.closest('button'); if (t) evidenceAction(t); };
  el('#sr-info-btn').onclick = function () { var h = el('#sr-help'); h.hidden = !h.hidden; this.classList.toggle('on', !h.hidden); };
  el('#sr-periods').onclick = function (e) {
    var d = e.target.closest('[data-dl]'), c = e.target.closest('.sr-per'); if (!c) return;
    var p = PER[+(d || c).getAttribute(d ? 'data-dl' : 'data-k')]; if (!p) return;
    // 20-Oct-2026 (Ejaz): playing a period no longer overwrites the From–To boxes (they stay on the
    // whole day); only the period's own download icon downloads just that period.
    if (d) { downloadRange(hhmm(p.a), hhmm(p.b - 1), d, nextDay(p.a), nextDay(p.b - 1)); return; }
    playAt(p.i0, 0);
  };
  el('#sr-seat-q').oninput = renderSeats;
  el('#sr-seat-only').onchange = renderSeats;
  el('#sr-seat-rows').onclick = async function (e) {
    var t = e.target, id = t.getAttribute('data-id'); if (!id) return;
    try {
      if (t.classList.contains('sr-seat')) {
        SEATS = (await api('/screen-recording/seat', { method: 'POST', body: JSON.stringify({ employee_id: +id, enabled: t.checked }) })).data;
        toast(t.checked ? 'Screen Recording turned on for this employee' : 'Screen Recording turned off for this employee');
        renderSeats(); window.initScreenRec && api('/screen-recording/access').then(function (r) { ACCESS = r.data; });
      } else if (t.classList.contains('sr-go') || t.classList.contains('sr-stop')) {
        var min = t.classList.contains('sr-stop') ? 0 : +(el('.sr-min[data-id="' + id + '"]') || {}).value || 30;
        await api('/screen-recording/record-now', { method: 'POST', body: JSON.stringify({ employee_id: +id, minutes: min }) });
        toast(min ? 'Recording starts within a minute (if the employee is signed in)' : 'Record now stopped');
        loadSettings();
      }
    } catch (err) { toast(err.message); if (t.classList.contains('sr-seat')) t.checked = !t.checked; }
  };
  el('#sr-freq').onchange = freqUi;
  el('#sr-save').onclick = saveSettings;
  el('#sr-clean-now').onclick = async function () {
    try { var r = await api('/screen-recording/cleanup-now', { method: 'POST' }); toast(r.output || 'Cleanup done'); loadSettings(); }
    catch (e) { toast(e.message); }
  };
})();
</script>
