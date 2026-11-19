{{-- 07-Nov-2026 (Ejaz): Attendance ledger → PDF. Print-ready page; the browser saves it as a PDF. --}}
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} · {{ $company }} · {{ $period }}</title>
<style>
  @page{size:A4 landscape;margin:12mm}
  *{box-sizing:border-box}
  body{margin:0;font:11px/1.45 "Segoe UI",Roboto,Arial,sans-serif;color:#14212E;background:#fff}
  .band{background:#0B1F33;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:flex-end;border-radius:6px}
  .band h1{margin:0;font-size:16px;font-weight:600}
  .band .s{font-size:11px;color:#C9D6E2;margin-top:2px}
  .meta{display:flex;gap:18px;margin:10px 2px 12px;color:#34414D}
  .meta b{color:#14212E}
  table{width:100%;border-collapse:collapse}
  thead{display:table-header-group}
  th{background:#E8F2F7;color:#00557F;text-align:left;font-weight:600;padding:6px 7px;border-bottom:1px solid #C9DCE7;white-space:nowrap}
  td{padding:5px 7px;border-bottom:1px solid #E6EBF0;vertical-align:top}
  tr:nth-child(even) td{background:#F8FAFB}
  tr{page-break-inside:avoid}
  .foot{margin-top:10px;color:#5B6875;font-size:10px;display:flex;justify-content:space-between}
  .np{position:fixed;top:12px;right:12px}
  .np button{padding:8px 14px;background:#006699;color:#fff;border:0;border-radius:7px;cursor:pointer;font:inherit}
  @media print{.np{display:none}}
</style></head>
<body>
  <div class="np"><button onclick="window.print()">Print / Save PDF</button></div>
  <div class="band"><div><h1>{{ $title }}</h1><div class="s">{{ $company }} · QR Attendance</div></div><div class="s">Generated: {{ now('Asia/Kolkata')->format('d M Y, h:i A') }}</div></div>
  <div class="meta"><span>Period: <b>{{ $period }}</b></span><span>Office: <b>{{ $office ?? 'All offices' }}</b></span><span>Rows: <b>{{ count($rows) }}</b></span></div>
  <table><thead><tr>@foreach($head as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
  <tbody>@forelse($rows as $r)<tr>@foreach($r as $c)<td>{{ $c }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($head) }}">No punches in this period.</td></tr>@endforelse</tbody></table>
  <div class="foot"><span>{{ $company }} · Times in IST (12-hour)</span><span>Powered by SmartEPT · Ametecs</span></div>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body></html>
