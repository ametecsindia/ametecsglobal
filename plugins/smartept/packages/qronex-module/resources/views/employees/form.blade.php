@extends('qronex::layout', ['title' => $employee->name, 'crumb' => '<a class="hover:text-teal" href="'.route('qronex.employees').'">Employees</a> · '.e($employee->employee_code)])
@section('content')
<form method="post" action="{{ route('qronex.employees.update', $employee) }}" class="card space-y-4 max-w-xl">
  @csrf @method('PUT')
  <p class="text-sm text-slate-600">Name, code and contact details come from {{ $host->name() }}. Change them there.</p>
  {{-- 08-Nov-2026 (Ejaz): tick every office this employee may punch at; "Main" is their home office. --}}
  @php($ticked = array_map('strval', old('office_ids', $employee->officeIds() ?: [$offices->first()?->id])))
  <div x-data="{ ticked: {{ Js::from($ticked) }}, main: '{{ old('office_id', $employee->office_id ?? ($ticked[0] ?? '')) }}', gate: '{{ old('gate_id', $employee->gate_id) }}',
        offices: {{ Js::from($offices->map(fn ($o) => ['id' => (string) $o->id, 'name' => $o->name, 'gates' => $o->gates->map(fn ($g) => ['id' => (string) $g->id, 'name' => $g->name])])) }},
        fix() { if (!this.ticked.includes(this.main)) this.main = this.ticked[0] || '';
                if (this.gate && !this.offices.some(o => this.ticked.includes(o.id) && o.gates.some(g => g.id === String(this.gate)))) this.gate = ''; } }" class="space-y-4">
    <div>
      <label class="label">Offices</label>
      <div class="rounded-lg" style="border:1px solid #DCE3EA;max-height:240px;overflow:auto">
        @foreach($offices as $o)
          <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #EEF2F5">
            <label style="display:flex;align-items:center;gap:10px;flex:1;cursor:pointer;margin:0">
              <input type="checkbox" name="office_ids[]" value="{{ $o->id }}" x-model="ticked" @change="fix()" style="width:16px;height:16px">
              <span class="text-sm text-navy">{{ $o->name }}</span>
              <span class="text-xs text-slate-500">{{ $o->gates->count() }} gate{{ $o->gates->count() === 1 ? '' : 's' }}</span>
            </label>
            <label class="text-xs" style="display:flex;align-items:center;gap:5px;margin:0;cursor:pointer" x-show="ticked.includes('{{ $o->id }}') && ticked.length > 1">
              <input type="radio" name="office_id" value="{{ $o->id }}" x-model="main" style="margin:0"> Main
            </label>
          </div>
        @endforeach
      </div>
      <input type="hidden" name="office_id" :value="main" x-show="false" :disabled="ticked.length > 1">
      <p class="text-xs text-slate-500 mt-1">Tick one or more offices. With more than one, choose the <b>Main</b> office (shown in lists and reports).</p>
      @error('office_ids')<p class="text-xs" style="color:#C0392B">{{ $message }}</p>@enderror
    </div>
    <div><label class="label" for="f-gate">Bound gate</label><select id="f-gate" name="gate_id" class="input" x-model="gate"><option value="">Any gate</option>
      <template x-for="o in offices.filter(x => ticked.includes(x.id))" :key="o.id"><optgroup :label="o.name"><template x-for="g in o.gates" :key="g.id"><option :value="g.id" x-text="g.name" :selected="g.id === String(gate)"></option></template></optgroup></template></select>
      @error('gate_id')<p class="text-xs" style="color:#C0392B">{{ $message }}</p>@enderror</div>
  </div>
  <div><label class="label" for="f-scope">Allow scans at</label><select id="f-scope" name="scan_scope" class="input">@foreach(\Ametecs\Qronex\Models\Employee::SCAN_SCOPES as $k => $l)<option value="{{ $k }}" @selected(old('scan_scope', $employee->scan_scope ?: 'office') === $k)>{{ $l }}</option>@endforeach</select>
    <p class="text-xs text-slate-500 mt-1">“Any gate of the assigned offices” covers every ticked office. “Any office and gate” lets this employee punch at all offices.</p></div>
  @if(\Ametecs\Qronex\Qronex::can(auth()->user(), 'configure'))<div><label class="label" for="f-maxdev">Phones allowed</label><input id="f-maxdev" name="max_devices" type="number" min="1" max="5" class="input w-32" value="{{ old('max_devices', $employee->max_devices ?? 1) }}">
    <p class="text-xs text-slate-500 mt-1">One phone per employee. Set more only as an exception.</p></div>@endif
  <div class="flex gap-2"><button class="btn">Save</button><a class="btn-ghost" href="{{ route('qronex.employees') }}">Cancel</a></div>
</form>
@endsection
