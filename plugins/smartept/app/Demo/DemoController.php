<?php

namespace App\Demo;

use App\Http\Controllers\Api\ProvisionController;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EmployeeDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 10-Nov-2026 (Ejaz): Explore Demo — the product side of smartept.com's "Explore Demo". */
class DemoController extends Controller
{
    /**
     * POST /api/provision/demo — same secret and payload as /api/provision (Central's ProductProvisioner), plus city and the
     * visitor's chosen password. Stands the console up through the normal provisioning, signs the owner in with their own
     * password (no temp password, no forced change), and builds the demo data right after the response is sent.
     */
    public function provision(Request $request, ProvisionController $provision): JsonResponse
    {
        $request->validate([
            'city' => ['required', 'string', 'max:40'],
            'admin_password' => ['required', 'string', 'min:8', 'max:190'],
        ]);
        // 11-Nov-2026: an e-mail that already has a login here (active, relieved or deleted — logins are only disabled) is refused.
        // Provisioning would otherwise reuse that login in its real company and the demo would overwrite its password.
        $secret = (string) config('services.provision.secret'); // same check provision() does — only Central may ask
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Provision-Secret')), 401, 'Invalid provisioning secret.');
        if (User::where('email', $request->input('admin_email'))->exists()) {
            return response()->json(['error' => 'This e-mail is already used on SmartEPT. Please use another e-mail for the demo.'], 409);
        }
        $res = $provision->provision($request); // secret check + company + admin + licence, exactly as for every cloud client
        $body = $res->getData(true);

        $company = Company::findOrFail($body['company_id']);
        $user = User::where('email', $body['admin_email'])->firstOrFail();
        $user->forceFill(['password' => $request->input('admin_password'), 'must_change_password' => false])->save();

        $acc = DemoAccount::firstOrCreate(['company_id' => $company->id],
            ['city' => DemoCities::get($request->input('city'))['key'], 'activation_code' => DemoSeeder::activationCode($company->id), 'status' => 'building']);
        if (! $acc->employee_ids) {
            $city = $request->input('city');
            $password = $request->input('admin_password');
            dispatch(fn () => DemoSeeder::build($company->fresh(), $city, $password))->afterResponse();
        }

        return response()->json(['ok' => true, 'company_id' => $company->id, 'slug' => $company->slug, 'console_url' => $body['console_url'],
            'activation_code' => $acc->activation_code, 'company_code' => $company->slug], 201);
    }

    /** GET /api/demo/status — the console notice: is this a demo company, is its data ready, is any agent online right now? */
    public function status(Request $request): JsonResponse
    {
        $cid = (int) $request->user()?->company_id;
        $acc = $cid ? DemoAccount::where('company_id', $cid)->first() : null;
        if (! $acc) {
            return response()->json(['demo' => false]);
        }
        $online = EmployeeDevice::withoutGlobalScopes()->where('company_id', $cid)->whereNull('unbound_at')
            ->whereIn('current_status', ['ONLINE', 'IDLE', 'AWAY'])->where('last_heartbeat_at', '>=', now()->subMinutes(3))->count();

        return response()->json(['demo' => true, 'status' => $acc->status, 'online' => $online, 'activation_code' => $acc->activation_code,
            'company_code' => Company::whereKey($cid)->value('slug')]);
    }
}
