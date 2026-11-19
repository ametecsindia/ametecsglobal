@extends('qronex::layout', ['title' => 'Offices & gates', 'crumb' => 'Organisation'])
@section('actions')<a class="btn btn-sm" href="{{ route('qronex.offices.create') }}"><x-qx::icon name="plus" class="w-4 h-4" /> Add office</a>@endsection
@section('content')
@php($gates = $offices->flatMap->gates)
@php($liveN = $gates->filter(fn ($g) => $g->is_active && $g->display_last_seen_at?->gt(now()->subMinute()))->count())
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
  <x-qx::stat label="Offices" :value="$offices->where('is_active', true)->count()" icon="building" tone="teal" :sub="$offices->where('is_active', false)->count().' inactive'" />
  <x-qx::stat label="Gates" :value="$gates->where('is_active', true)->count()" icon="qr" sub="Entrances with a QR screen" />
  <x-qx::stat label="Screens live now" :value="$liveN.' / '.$gates->where('is_active', true)->count()" icon="window" :tone="$liveN < $gates->where('is_active', true)->count() ? 'amber' : 'teal'" sub="Heartbeat in the last minute" />
  <x-qx::stat label="Employees" :value="$offices->sum('employees_count')" icon="users" tone="sky" sub="Assigned to these offices" />
</div>
@if($offices->isEmpty())<div class="card"><x-qx::empty icon="building" title="No offices yet" text="Add an office with its location. A gate and its QR display link are created a gate and its QR display link for you."><a class="btn" href="{{ route('qronex.offices.create') }}">Add office</a></x-qx::empty></div>@endif
<div class="grid lg:grid-cols-2 gap-4">
@foreach($offices as $o)
  {{-- 08-Nov-2026 (Ejaz): tap an office to highlight it; the map below shows that office. --}}
  <div class="card space-y-3 min-w-0 qx-office" data-i="{{ $loop->index }}" onclick="qxPick({{ $loop->index }})" style="cursor:pointer;transition:box-shadow .15s,border-color .15s">
    <div class="flex items-start justify-between gap-2">
      <div><h2 class="font-bold text-navy">{{ $o->name }} <span class="text-xs text-slate-500">{{ $o->code }}</span> @unless($o->is_active)<span class="chip bg-slate-200">inactive</span>@endunless @if($o->skip_geofence)<span class="chip" style="background:#FDECC8;color:#8A5A00">Location check skipped · testing</span>@endif</h2>
        <div class="text-xs text-slate-500">{{ collect([$o->address, $o->city])->filter()->implode(', ') ?: 'No address' }} · {{ $o->lat }}, {{ $o->lng }} · radius {{ $o->geofence_radius_m >= 1000 ? number_format($o->geofence_radius_m / 1000).' km' : $o->geofence_radius_m.' m' }} · {{ $o->employees_count }} employees</div></div>
      <div class="flex gap-2"><button type="button" class="btn-ghost btn-sm" style="white-space:nowrap" onclick="event.stopPropagation();qxPick({{ $loop->index }},true)">Show on map</button>
        <a class="btn-ghost btn-sm" href="{{ route('qronex.offices.edit', $o) }}">Edit</a></div>
    </div>
    <div class="overflow-x-auto -mx-1 px-1"><table class="tbl"><tr><th>Gate</th><th>Entrance display</th><th></th></tr>
    @foreach($o->gates as $g)
      @php($url = route('qronex.display', [$g->id, 'key' => $g->display_secret]))
      <tr class="{{ $g->is_active ? '' : 'opacity-50' }}"><td>{{ $g->name }} <span class="text-xs text-slate-500">{{ $g->code }}</span>
        <div class="text-xs {{ $g->display_last_seen_at?->gt(now()->subMinute()) ? 'text-emerald-700' : 'text-slate-500' }}">{{ $g->display_last_seen_at ? 'Screen seen '.$g->display_last_seen_at->diffForHumans() : 'No screen yet' }}</div></td>
      <td class="text-xs" x-data="{s:false}"><a class="btn btn-sm" href="{{ $url }}" target="_blank" rel="noopener">Open display</a>
        <button type="button" class="btn-ghost btn-sm" @click="navigator.clipboard?.writeText(@js($url)); s=true; setTimeout(()=>s=false,1500)" x-text="s?'Copied':'Copy link'">Copy link</button>
        <div class="text-slate-500 mt-1">Anyone with this link can show live codes. Reset it if it leaks.</div></td>
      <td class="text-right whitespace-nowrap">
        <form method="post" action="{{ route('qronex.gates.reset', $g) }}" class="inline" onsubmit="return confirm('Reset this display link? Screens using the old link stop showing codes.')">@csrf<button class="btn-ghost btn-sm">Reset link</button></form>
        <form method="post" action="{{ route('qronex.gates.toggle', $g) }}" class="inline">@csrf<button class="btn-ghost btn-sm">{{ $g->is_active ? 'Disable' : 'Enable' }}</button></form></td></tr>
    @endforeach
    </table></div>
    <form method="post" action="{{ route('qronex.gates.store', $o) }}" class="flex flex-wrap gap-2 items-end">
      @csrf <input name="code" class="input max-w-[8rem]" placeholder="gate2" required><input name="name" class="input max-w-[14rem]" placeholder="Gate name" required><button class="btn-ghost btn-sm">Add gate</button>
    </form>
  </div>
@endforeach
</div>
@if($offices->isNotEmpty())
<div class="card p-0 overflow-hidden" id="map-card">
  <div style="padding:12px 16px;border-bottom:1px solid #EEF2F5;display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
    <span class="font-bold text-navy" id="map-title">Map</span><span class="text-xs text-slate-500" id="map-sub"></span>
  </div>
  <div id="map" style="height:380px"></div>
</div>
@endif
@endsection
@include('qronex::offices.leaflet')
@push('scripts')
<script>
// 08-Nov-2026 (Ejaz): the map sits below the offices and shows the highlighted office (its geofence).
const offices = @js($offices->map(fn ($o) => ['name' => $o->name, 'code' => $o->code, 'lat' => (float) $o->lat, 'lng' => (float) $o->lng, 'r' => (int) $o->geofence_radius_m, 'addr' => trim(($o->address ?? '').', '.($o->city ?? ''), ', ')])->values());
if (offices.length && document.getElementById('map')) {
  const map = L.map('map').setView([offices[0].lat, offices[0].lng], 16);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(map);
  const shapes = offices.map(o => ({
    circle: L.circle([o.lat, o.lng], {radius: o.r, color: '#94A3B8', weight: 1.5, fillOpacity: .06}).bindTooltip(o.name).addTo(map),
    pin: L.circleMarker([o.lat, o.lng], {radius: 6, color: '#fff', weight: 2, fillColor: '#94A3B8', fillOpacity: 1}).bindTooltip(o.name).addTo(map),
  }));
  shapes.forEach((s, i) => { s.circle.on('click', () => qxPick(i)); s.pin.on('click', () => qxPick(i)); });
  window.qxPick = function (i, scroll) {
    const o = offices[i]; if (!o) return;
    document.querySelectorAll('.qx-office').forEach(c => { c.style.boxShadow = +c.dataset.i === i ? '0 0 0 2px #006699' : ''; });
    shapes.forEach((s, k) => {
      s.circle.setStyle(k === i ? {color: '#006699', weight: 2.5, fillOpacity: .15} : {color: '#94A3B8', weight: 1.5, fillOpacity: .06});
      s.pin.setStyle({fillColor: k === i ? '#006699' : '#94A3B8', radius: k === i ? 8 : 6}); if (k === i) s.pin.bringToFront();
    });
    document.getElementById('map-title').textContent = 'Map: ' + o.name + (o.code ? ' (' + o.code + ')' : '');
    document.getElementById('map-sub').textContent = (o.addr ? o.addr + ' · ' : '') + o.lat + ', ' + o.lng + ' · radius ' + (o.r >= 1000 ? (o.r / 1000) + ' km' : o.r + ' m');
    map.fitBounds(shapes[i].circle.getBounds().pad(0.6), {maxZoom: 17, animate: true});
    if (scroll) document.getElementById('map-card').scrollIntoView({behavior: 'smooth', block: 'center'});
  };
  qxPick(0);
} else { window.qxPick = function () {}; }
</script>
@endpush
