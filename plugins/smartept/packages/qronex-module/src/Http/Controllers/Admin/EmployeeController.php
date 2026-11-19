<?php

namespace Ametecs\Qronex\Http\Controllers\Admin;

use Ametecs\Qronex\Models\AuditLog;
use Ametecs\Qronex\Models\Employee;
use Ametecs\Qronex\Models\Office;
use Ametecs\Qronex\Qronex;
use Ametecs\Qronex\Services\EmployeeSync;
use Ametecs\Qronex\Services\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/** Employees come from the host app (synced). Here: office/gate binding, scan scope, phone exception, activation codes. */
class EmployeeController extends Base
{
    public function index(Request $request)
    {
        $s = Qronex::settings($this->tid());
        if (! $s->employees_synced_at || $s->employees_synced_at->lt(now()->subMinutes(10))) {
            EmployeeSync::run($this->tid());
        }

        $employees = Employee::with('office', 'gate')->withCount(['devices as approved_devices' => fn ($q) => $q->where('status', 'approved')])
                ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('employee_code', 'like', "%$t%")))
                ->when($request->office === 'none', fn ($q) => $q->whereNull('office_id'), fn ($q) => $q->when($request->office, fn ($x, $o) => $x->where(fn ($w) => $w->where('office_id', $o)->orWhereJsonContains('office_ids', (int) $o))))
                ->when($request->status !== 'all', fn ($q) => $q->where('status', 'active'))
                ->orderBy('name')->paginate(50)->withQueryString();
        $host = Qronex::host();

        return view('qronex::employees.index', [
            'employees' => $employees,
            // Optional host feature: the photo each employee set in the phone app.
            'photos' => method_exists($host, 'photoUrls') ? $host->photoUrls($employees->pluck('host_employee_id')->filter()->all()) : [],
            'offices' => Office::orderBy('name')->get(),
            'syncedAt' => Qronex::settings($this->tid())->employees_synced_at,
        ]);
    }

    public function sync()
    {
        $n = EmployeeSync::run($this->tid());
        AuditLog::record('employees.synced', null, $n);

        return back()->with('ok', "Synced from ".Qronex::host()->name().": {$n['added']} added, {$n['updated']} updated, {$n['deactivated']} deactivated.");
    }

    public function edit(int $employee)
    {
        return view('qronex::employees.form', ['employee' => Employee::findOrFail($employee),
            'offices' => Office::with(['gates' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->where('is_active', true)->orderBy('name')->get()]);
    }

    public function update(Request $request, int $employee)
    {
        $e = Employee::findOrFail($employee);
        // 08-Nov-2026 (Ejaz): offices are ticked (one or more). The main office is the one chosen as "Main", else the first ticked.
        $valid = Office::pluck('id')->map(fn ($i) => (int) $i)->all();
        $ids = array_values(array_unique(array_map('intval', (array) $request->input('office_ids', $request->filled('office_id') ? [$request->input('office_id')] : []))));
        $request->merge(['office_ids' => $ids]); // older callers send a single office_id
        $request->validate([
            'office_ids' => ['required', 'array', 'min:1'], 'office_ids.*' => [Rule::in($valid)],
            'scan_scope' => 'required|in:'.implode(',', array_keys(Employee::SCAN_SCOPES)),
        ], ['office_ids.required' => 'Tick at least one office.', 'office_ids.min' => 'Tick at least one office.', 'office_ids.*.in' => 'Pick offices from the list.']);
        $main = in_array((int) $request->input('office_id'), $ids, true) ? (int) $request->input('office_id') : $ids[0];
        $d = ['office_id' => $main, 'office_ids' => $ids, 'scan_scope' => $request->input('scan_scope'), 'gate_id' => $request->input('gate_id') ?: null];
        if ($d['gate_id'] && ! \Ametecs\Qronex\Models\Gate::whereKey($d['gate_id'])->whereIn('office_id', $ids)->exists()) {
            return back()->withErrors(['gate_id' => 'Pick a gate of one of the ticked offices.'])->withInput();
        }
        if ($d['scan_scope'] === 'gate' && empty($d['gate_id'])) {
            return back()->withErrors(['gate_id' => 'Pick the gate this employee is bound to.'])->withInput();
        }
        // Extra phones are an exception only an admin with the configure ability can grant.
        if (Qronex::can($request->user(), 'configure') && $request->has('max_devices')) {
            $d['max_devices'] = (int) $request->validate(['max_devices' => 'required|integer|between:1,5'])['max_devices'];
        }
        $e->update($d);
        AuditLog::record('employee.updated', $e->employee_code, array_keys($e->getChanges()));

        return redirect()->route('qronex.employees')->with('ok', "{$e->name} saved.");
    }

    /** Single-use code (72 h) the employee types into the app. Shown once on screen and emailed. */
    public function activation(Request $request, int $employee)
    {
        // 07-Nov-2026 (Ejaz): always back to the Employees list (same search / filter / page) — back() could land on
        // another page (e.g. an employee's Set office screen) when the browser sent no referrer.
        parse_str((string) $request->input('list'), $list);
        $toList = redirect()->route('qronex.employees', array_intersect_key($list, array_flip(['q', 'office', 'status', 'page'])));
        $e = Employee::findOrFail($employee);
        abort_if($e->status !== 'active', 422, 'Employee is inactive.');
        if (! $e->office_id) {
            return $toList->withErrors(['office_id' => "Assign {$e->name} to an office first."]);
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $hours = config('qronex.activation_hours', 72);
        $e->forceFill(['activation_code_hash' => Hash::make($code), 'activation_expires_at' => now()->addHours($hours)])->save();
        $t = Qronex::host()->tenant($this->tid());
        $pretty = substr($code, 0, 4).'-'.substr($code, 4);
        $server = rtrim(url('/'), '/');
        $app = Qronex::appName(); $h = Qronex::host();
        // 07-Nov-2026 (Ejaz): the EPT app asks for ONE "Server URL" (like the SmartEPT Agent); other hosts' apps still say Company.
        $urlLabel = method_exists($h, 'appName') ? 'Server URL' : 'Company';
        Notify::send($e->email, "Activate the {$app} app", "Hi {$e->name},\n\nInstall the {$app} app and enter:\n\n{$urlLabel}: {$server}/{$t['slug']}\nEmployee code: {$e->employee_code}\nActivation code: {$pretty}\n\nThe code works once and expires in {$hours} hours.", $this->tid());
        AuditLog::record('employee.activation_sent', $e->employee_code);

        // One item per line, so the admin can read it out or copy it to the employee.
        // 09-Nov-2026 (Ejaz): shown right under this employee's row (the page comes back scrolled to it), not as a toast
        // at the bottom of the screen.
        return $toList->withFragment('emp-'.$e->id)->with('activation', ['id' => $e->id, 'text' => implode("\n", array_filter([
            "Activation code for {$e->name}",
            $urlLabel === 'Server URL' ? "Server URL (type this in the app): {$server}/{$t['slug']}" : "Company: {$server}/{$t['slug']}",
            $urlLabel === 'Server URL' ? "Server address: {$server}" : null,
            $urlLabel === 'Server URL' ? "Company: {$t['slug']}" : null,
            "Employee code: {$e->employee_code}",
            "Activation code: {$pretty}",
            "Valid for {$hours} hours, single use. ".($e->email ? "Also emailed to {$e->email}." : 'No email on file: give these details to the employee.'),
        ]))]);
    }
}
