<?php

namespace App\Demo;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 10-Nov-2026 (Ejaz): on a demo company's console page (/<slug>) a slim notice is added at the bottom of the page. It
 * says this is a demo with simulated sample data and, whenever no SmartEPT Agent is online, why live screens show Offline.
 * Every other page and every other company's console is returned untouched.
 */
class DemoNotice
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $slug = strtolower((string) $request->segment(1));
        if (! $request->isMethod('GET') || $request->segment(2) !== null || $slug === '' || ! $this->isDemoSlug($slug)
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }
        $html = (string) $response->getContent();
        $pos = strripos($html, '</body>');
        if ($pos !== false) {
            $response->setContent(substr($html, 0, $pos) . self::SNIPPET . substr($html, $pos));
        }

        return $response;
    }

    private function isDemoSlug(string $slug): bool
    {
        try {
            return DB::table('demo_accounts')->join('companies', 'companies.id', '=', 'demo_accounts.company_id')->where('companies.slug', $slug)->exists();
        } catch (\Throwable) {
            return false; // before the migration has run
        }
    }

    private const SNIPPET = <<<'HTML'
<div id="ept-demo-note" style="display:none;position:fixed;left:50%;bottom:14px;transform:translateX(-50%);z-index:9999;max-width:min(760px,calc(100% - 32px));background:#fff8e6;color:#5a4300;border:1px solid #e9c46a;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.12);padding:10px 38px 10px 14px;font:13px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif">
  <strong>Demo company:</strong> <span id="ept-demo-text"></span>
  <button type="button" aria-label="Close" onclick="this.parentNode.style.display='none';try{sessionStorage.setItem('ept_demo_note_x','1')}catch(e){}" style="position:absolute;top:6px;right:8px;border:0;background:none;font-size:18px;line-height:1;color:#5a4300;cursor:pointer">&times;</button>
</div>
<script>
(function () {
  var box = document.getElementById('ept-demo-note'), txt = document.getElementById('ept-demo-text');
  function tok() { try { return sessionStorage.getItem('ept_token') || (typeof TOKEN !== 'undefined' ? TOKEN : null); } catch (e) { return null; } }
  function check() {
    var t = tok();
    try { if (!t || sessionStorage.getItem('ept_demo_note_x') === '1') { box.style.display = 'none'; return; } } catch (e) {}
    fetch('/api/demo/status', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        if (!s || !s.demo) { box.style.display = 'none'; return; }
        var m = s.status === 'building'
          ? 'your sample data (10 employees, last two months) is being prepared — it fills in within a few minutes.'
          : 'the 10 sample employees and their last two months are simulated, so you can explore every report.';
        if (!s.online) {
          m += ' No SmartEPT Agent is online right now, so Live Dashboard, LiveView and live status show everyone as Offline. Install the Agent on a PC and sign in as an employee to see it live.';
        }
        m += ' EPT QR Attendance app: Server URL: ' + location.origin + '/' + s.company_code + ' · Login ID: EMP001 with your demo password (no earlier records: every punch is yours; EMP002 to EMP010 show two months of sample history) · activation code, asked once per phone: ' + s.activation_code + '.';
        txt.textContent = m;
        box.style.display = 'block';
      })
      .catch(function () {});
  }
  check();
  setInterval(check, 60000);
})();
</script>
HTML;
}
