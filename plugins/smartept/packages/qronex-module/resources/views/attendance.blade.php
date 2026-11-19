@extends('qronex::layout', ['title' => 'Attendance ledger'])
@section('actions')
{{-- 10-Nov-2026 (Ejaz): one tidy toolbar — export layout switch, then CSV / Excel / PDF. --}}
@php($exq = request()->only('from', 'to', 'office', 'q'))
<div x-data="{ layout: 'pairs' }" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <div style="display:flex;align-items:center;gap:8px">
    <span style="font-size:12px;font-weight:600;color:#475569">Export</span>
    <div role="radiogroup" aria-label="Export layout" style="display:inline-flex;gap:2px;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;padding:2px">
      @foreach(['pairs' => ['IN & OUT per row', 'Each IN with its OUT on one row, with the duration'], 'punches' => ['Every punch', 'Every punch on its own row']] as $k => [$lbl, $tip])
        <button type="button" role="radio" title="{{ $tip }}" :aria-checked="layout === '{{ $k }}'" @click="layout = '{{ $k }}'"
          style="border:0;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600;line-height:16px;cursor:pointer;white-space:nowrap"
          :style="layout === '{{ $k }}' ? {background: '#fff', color: '#0E2747', boxShadow: '0 1px 2px rgba(15,23,42,.15)'} : {background: 'transparent', color: '#64748B', boxShadow: 'none'}">{{ $lbl }}</button>
      @endforeach
    </div>
  </div>
  <div style="display:inline-flex;border:1px solid #CBD5E1;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.05)">
    @foreach(['csv' => ['CSV', '#006699', 'Download CSV'], 'xlsx' => ['Excel', '#1D6F42', 'Download Excel'], 'pdf' => ['PDF', '#C0392B', 'Save as PDF']] as $fmt => [$tag, $col, $tip])
      <a :href="'{{ route('qronex.attendance.export', $exq + ['format' => $fmt]) }}'.replace(/&amp;/g, '&') + '&layout=' + layout" @if($fmt === 'pdf') target="_blank" @endif
         title="{{ $tip }}" aria-label="{{ $tip }}"
         style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:12px;font-weight:700;line-height:16px;color:{{ $col }};text-decoration:none;{{ $loop->first ? '' : 'border-left:1px solid #E2E8F0;' }}"
         onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background=''">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>{{ $tag }}</a>
    @endforeach
  </div>
</div>
@endsection
@section('content')
<form class="card flex flex-wrap gap-3 items-end">
  <input type="hidden" name="tab" value="{{ $tab }}">
  <div><label class="label">Company</label>
    @if(count($companies) > 1)
      <select name="tenant" class="input" style="height:38px" onchange="this.form.office.value='';this.form.q.value='';this.form.submit()">@foreach($companies as $cid => $cname)<option value="{{ $cid }}" @selected($cid == $tenantId)>{{ $cname }}</option>@endforeach</select>
    @else
      <select class="input" disabled title="Your company" style="height:38px">@foreach($companies as $cname)<option>{{ $cname }}</option>@endforeach</select>
    @endif
  </div>
  <div><label class="label">From</label><input type="date" name="from" value="{{ request('from', today(\Ametecs\Qronex\Qronex::tz())->toDateString()) }}" class="input" style="height:38px"></div>
  <div><label class="label">To</label><input type="date" name="to" value="{{ request('to', today(\Ametecs\Qronex\Qronex::tz())->toDateString()) }}" class="input" style="height:38px"></div>
  <div><label class="label">Office</label><select name="office" class="input" style="height:38px"><option value="">All offices</option>@foreach($offices as $o)<option value="{{ $o->id }}" @selected(request('office') == $o->id)>{{ $o->name }}</option>@endforeach</select></div>
  <div><label class="label">Employee</label>
    {{-- type to search, or pick from the registered employees --}}
    <input name="q" value="{{ request('q') }}" class="input" placeholder="All employees — type or pick" list="qx-people" autocomplete="off" style="min-width:230px;height:38px">
    <datalist id="qx-people">@foreach($people as $p)<option value="{{ $p->name }} ({{ $p->employee_code }})"></option>@endforeach</datalist>
  </div>
  <button class="btn" style="height:38px">Apply</button>
</form>
<div class="flex gap-2">
  <a href="{{ route('qronex.attendance', ['tab' => 'verified'] + request()->except('tab', 'page')) }}" class="{{ $tab === 'verified' ? 'btn' : 'btn-ghost' }} btn-sm">Verified</a>
  <a href="{{ route('qronex.attendance', ['tab' => 'rejected'] + request()->except('tab', 'page')) }}" class="{{ $tab === 'rejected' ? 'btn' : 'btn-ghost' }} btn-sm">Rejected attempts</a>
</div>
<div class="card overflow-x-auto">
@if($tab === 'verified')
  <table class="tbl"><tr><th>Time</th><th>Company</th><th>Employee</th><th>Type</th><th>Office · gate</th><th>Distance</th><th>In {{ $host->name() }}</th><th>Ref</th></tr>
  @forelse($rows as $e)<tr>
    <td class="whitespace-nowrap">{{ $e->recorded_at->timezone(\Ametecs\Qronex\Qronex::tz())->format('d M h:i:s A') }}</td>
    <td class="whitespace-nowrap">{{ $companies[$e->tenant_id] ?? '' }}</td>
    <td class="whitespace-nowrap">{{ $e->employee->name }}<div class="text-xs text-slate-500">{{ $e->employee->employee_code }}</div></td>
    <td><span class="chip {{ $e->type === 'IN' ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800' }}">{{ $e->type }}</span></td>
    <td class="whitespace-nowrap">@if($e->gate){{ $e->gate->office?->name }} · {{ $e->gate->name }}@else<span class="text-xs text-slate-600">{{ ['AGENT_SIGNOUT' => \Ametecs\Qronex\Models\AttendanceEvent::AGENT_SIGNOUT_LABEL, 'WFH_APPROVED' => 'Remote · Work from home', 'FIELD_APPROVED' => 'Remote · Field duty', 'CLIENT_SITE_APPROVED' => 'Remote · Client site', 'REMOTE_APPROVED' => 'Remote · Temporary remote'][$e->mode] ?? ucfirst(strtolower(str_replace('_', ' ', $e->mode))) }}</span>@endif
      @if($e->adjusted)<span class="chip bg-amber-100 text-amber-800 ml-1" title="{{ $e->correction?->reason }}">adjusted</span>@endif</td>
    <td class="text-xs whitespace-nowrap">@if($e->distance_m !== null){{ $e->distance_m }} m (±{{ $e->accuracy_m }} m)@else — @endif</td>
    <td class="text-xs">@if($e->host_synced_at)<span class="chip bg-emerald-100 text-emerald-800">recorded</span>@else<span class="chip bg-amber-100 text-amber-800" title="{{ $e->host_sync_error }}">pending</span>@endif</td>
    <td class="text-xs font-mono whitespace-nowrap">{{ $e->shownRef() }}</td></tr>
  @empty<tr><td colspan="8" class="text-center text-slate-500 py-6">No verified punches in this period.</td></tr>@endforelse
  </table>
@else
  <table class="tbl"><tr><th>Time</th><th>Company</th><th>Employee</th><th>Reason</th><th>Gate</th><th>Ref</th></tr>
  @forelse($rows as $r)<tr x-data="{o:false}">
    <td class="whitespace-nowrap">{{ $r->created_at->timezone(\Ametecs\Qronex\Qronex::tz())->format('d M h:i:s A') }}</td>
    <td class="whitespace-nowrap">{{ $companies[$r->tenant_id] ?? '' }}</td>
    <td class="whitespace-nowrap">{{ $r->employee?->name ?? 'Unknown' }}<div class="text-xs text-slate-500">{{ $r->employee?->employee_code }}</div></td>
    <td><span class="chip bg-red-100 text-red-800">{{ $r->reason_code }}</span> <span class="text-xs">{{ $r->reason }}</span>
      <button class="text-xs text-teal ml-1" @click="o=!o" x-text="o?'Hide checks':'Checks'"></button>
      <ul x-show="o" x-cloak class="text-xs mt-1 space-y-0.5">@foreach($r->checks ?? [] as $c)<li>{{ ['ok' => '✓', 'bad' => '✕', 'skip' => '·', 'na' => 'i'][$c['status']] ?? '·' }} {{ $c['label'] }} — <span class="text-slate-500">{{ $c['detail'] }}</span></li>@endforeach
        @if($r->lat !== null)<li class="pt-1">📍 Phone location: <span class="font-mono" data-loc>{{ $r->lat }}, {{ $r->lng }}</span>{{ $r->accuracy_m ? ' ±'.$r->accuracy_m.' m' : '' }} · <a class="text-teal underline" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat={{ $r->lat }}&mlon={{ $r->lng }}#map=18/{{ $r->lat }}/{{ $r->lng }}">map</a></li>@endif</ul></td>
    <td>{{ $r->gate?->office?->name }} {{ $r->gate ? '· '.$r->gate->name : '—' }}</td>
    <td class="text-xs font-mono whitespace-nowrap">{{ $r->ref }}</td></tr>
  @empty<tr><td colspan="6" class="text-center text-slate-500 py-6">No rejected attempts in this period.</td></tr>@endforelse
  </table>
@endif
  <div class="mt-3">{{ $rows->links() }}</div>
</div>
@endsection
