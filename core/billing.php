<?php
/**
 * AMETECSGLOBAL BILLING (owner, 18 Nov 2026 - Phase 3): invoices and Razorpay payments for every app.
 * Tables ag_invoices + ag_payments (migration 2026_11_18_000300). Razorpay keys: the payment_gateways row SmartPRS3's
 * console also uses (gateway = razorpay), secret encrypted with the server's APP_KEY. Seller details and GST %:
 * platform_settings key "ag_billing".
 *
 *   purpose upgrade  -> a paid release; when paid, the release's "paid" event unlocks it for that client
 *   purpose renewal  -> a licence's next period; when paid, the licence is extended by one plan period
 *   purpose other    -> anything Super Admin bills by hand
 *
 * A payment counts ONLY after the server has checked it: the Razorpay signature (HMAC of order|payment with the
 * secret), or Razorpay's own API asked with the secret ("Check with Razorpay"). Completing is idempotent: an invoice
 * is paid once, however many times the browser or Super Admin repeats the call.
 */
declare(strict_types=1);

final class AmetecsBilling
{
    public const STATUSES = ['pending' => ['Pending', 'amber'], 'paid' => ['Paid', 'green'], 'cancelled' => ['Cancelled', 'grey'], 'refunded' => ['Refunded', 'red']];
    public const PURPOSES = ['upgrade' => 'Upgrade', 'renewal' => 'Licence renewal', 'plan' => 'Plan change', 'seats' => 'Extra seats', 'other' => 'Other'];
    private const API = 'https://api.razorpay.com/v1';

    /** Test hook: replaces the HTTPS call to Razorpay. fn(string $method, string $path, array $body): array{code: int, json: ?array} */
    public static ?\Closure $http = null;
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = ($p = AmetecsLicences::pdo()) !== null && $p->query("SHOW TABLES LIKE 'ag_payments'")->fetchColumn() !== false;
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    private static function pdo(): \PDO
    {
        return AmetecsLicences::pdo();
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------ settings and the gateway

    /** Seller details on invoices + GST %. */
    public static function settings(): array
    {
        $d = ['seller' => 'Ametecs India Private Limited', 'gstin' => '', 'address' => 'Kondapur, Hyderabad, Telangana', 'email' => '', 'gst_percent' => 18.0, 'due_days' => 7];
        try {
            $q = self::pdo()->prepare('SELECT value FROM platform_settings WHERE ckey = ? LIMIT 1');
            $q->execute(['ag_billing']);
            $v = json_decode((string) $q->fetchColumn(), true);
        } catch (\Throwable) {
            $v = null;
        }
        return array_merge($d, is_array($v) ? $v : []);
    }

    public static function saveSettings(array $s): void
    {
        $pdo = self::pdo();
        $now = self::now();
        $json = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $id = $pdo->prepare('SELECT id FROM platform_settings WHERE ckey = ? LIMIT 1');
        $id->execute(['ag_billing']);
        ($row = $id->fetchColumn()) !== false
            ? $pdo->prepare('UPDATE platform_settings SET value = ?, updated_at = ? WHERE id = ?')->execute([$json, $now, $row])
            : $pdo->prepare('INSERT INTO platform_settings (ckey, value, created_at, updated_at) VALUES (?, ?, ?, ?)')->execute(['ag_billing', $json, $now, $now]);
    }

    /** The Razorpay row as saved (secret NOT decrypted), or null. */
    public static function gatewayRow(): ?array
    {
        try {
            $q = self::pdo()->query("SELECT * FROM payment_gateways WHERE LOWER(gateway) = 'razorpay' ORDER BY id LIMIT 1");
            return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Usable keys, or null when Razorpay is not switched on. @return array{key: string, secret: string, mode: string}|null */
    public static function gateway(): ?array
    {
        $g = self::gatewayRow();
        if ($g === null || !in_array(strtolower((string) $g['status']), ['configured', 'live', 'active'], true) || trim((string) $g['key_id']) === '' || trim((string) $g['secret']) === '') {
            return null;
        }
        $raw = trim((string) $g['secret']);
        return ['key' => trim((string) $g['key_id']), 'secret' => \SmartPRS\Surface\Secret::decrypt($raw, AG_BASE . '/.env') ?? $raw, 'mode' => strtolower((string) ($g['mode'] ?? 'test'))];
    }

    public static function saveGateway(string $keyId, string $secret, string $mode, bool $on): void
    {
        $pdo = self::pdo();
        $now = self::now();
        $g = self::gatewayRow();
        $set = ['gateway' => 'razorpay', 'key_id' => trim($keyId), 'mode' => $mode === 'live' ? 'live' : 'test', 'status' => $on ? 'configured' : 'disabled', 'updated_at' => $now];
        trim($secret) !== '' && $set['secret'] = \SmartPRS\Surface\Secret::encrypt(trim($secret), AG_BASE . '/.env');
        if ($g === null) {
            $set['created_at'] = $now;
            $pdo->prepare('INSERT INTO payment_gateways (' . implode(', ', array_keys($set)) . ') VALUES (' . implode(', ', array_fill(0, count($set), '?')) . ')')->execute(array_values($set));
        } else {
            $pdo->prepare('UPDATE payment_gateways SET ' . implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($set))) . ' WHERE id = ?')->execute(array_merge(array_values($set), [$g['id']]));
        }
    }

    /** @return array{code: int, json: ?array} */
    private static function call(string $method, string $path, array $body, array $gw): array
    {
        if (self::$http !== null) {
            return (self::$http)($method, $path, $body);
        }
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [CURLOPT_USERPWD => $gw['key'] . ':' . $gw['secret'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']] + ($method === 'POST' ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) json_encode($body ?: new \stdClass())] : []));
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $j = is_string($resp) ? json_decode($resp, true) : null;
        return ['code' => $resp === false ? 0 : $code, 'json' => is_array($j) ? $j : ($err !== '' ? ['error' => ['description' => $err]] : null)];
    }

    // ------------------------------------------------------------------ invoices

    public static function byId(string $id): ?array
    {
        if (!self::ready() || !ctype_digit($id)) {
            return null;
        }
        $q = self::pdo()->prepare('SELECT * FROM ag_invoices WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function invoices(string $tenant = '', string $status = ''): array
    {
        if (!self::ready()) {
            return [];
        }
        $w = [];
        $a = [];
        $tenant !== '' && ($w[] = 'tenant_id = ?') && ($a[] = $tenant);
        $status !== '' && ($w[] = 'status = ?') && ($a[] = $status);
        $q = self::pdo()->prepare('SELECT * FROM ag_invoices' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC');
        $q->execute($a);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function payments(string $invoiceId): array
    {
        $q = self::pdo()->prepare('SELECT * FROM ag_payments WHERE invoice_id = ? ORDER BY id');
        $q->execute([$invoiceId]);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Money received minus refunds, in INR (other currencies are listed separately by the caller). */
    public static function revenue(string $since = ''): float
    {
        if (!self::ready()) {
            return 0.0;
        }
        $q = self::pdo()->prepare("SELECT COALESCE(SUM(amount), 0) FROM ag_payments WHERE status = 'succeeded' AND currency = 'INR'" . ($since !== '' ? ' AND created_at >= ?' : ''));
        $q->execute($since !== '' ? [$since] : []);
        return (float) $q->fetchColumn();
    }

    /** One pending invoice per client per release / licence period: reused if it already exists. */
    public static function create(string $tenant, string $purpose, string $description, float $amount, string $currency = 'INR', ?string $licenceId = null, ?string $releaseId = null, string $notes = ''): array
    {
        if (!self::ready()) {
            throw new \RuntimeException('The billing tables are not on this PC yet (the next sync adds them).');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('An invoice needs an amount above zero.');
        }
        if (!in_array($purpose, ['other', 'plan', 'seats'], true)) {
            $q = self::pdo()->prepare("SELECT * FROM ag_invoices WHERE tenant_id = ? AND purpose = ? AND status = 'pending' AND COALESCE(licence_id, 0) = ? AND COALESCE(release_id, 0) = ? ORDER BY id DESC LIMIT 1");
            $q->execute([$tenant, $purpose, (int) $licenceId, (int) $releaseId]);
            if (($ex = $q->fetch(\PDO::FETCH_ASSOC)) !== false) {
                return $ex;
            }
        }
        $s = self::settings();
        $pct = $currency === 'INR' ? max(0.0, (float) $s['gst_percent']) : 0.0;
        $tax = round($amount * $pct / 100, 2);
        $pdo = self::pdo();
        $now = self::now();
        $year = substr($now, 0, 4);
        $n = $pdo->prepare('SELECT COUNT(*) FROM ag_invoices WHERE number LIKE ?');
        $n->execute(['AG-' . $year . '-%']);
        $seq = (int) $n->fetchColumn() + 1;
        do {
            $number = 'AG-' . $year . '-' . str_pad((string) $seq++, 5, '0', STR_PAD_LEFT);
            $dup = $pdo->prepare('SELECT COUNT(*) FROM ag_invoices WHERE number = ?');
            $dup->execute([$number]);
        } while ((int) $dup->fetchColumn() > 0);
        $pdo->prepare('INSERT INTO ag_invoices (number, tenant_id, purpose, licence_id, release_id, description, amount, tax_percent, tax, total, currency, status, issued_on, due_on, notes, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?, ?, ?)')
            ->execute([$number, $tenant, $purpose, $licenceId, $releaseId, mb_substr($description, 0, 250), number_format($amount, 2, '.', ''), $pct, number_format($tax, 2, '.', ''),
                number_format($amount + $tax, 2, '.', ''), $currency, substr($now, 0, 10), (new DateTimeImmutable(substr($now, 0, 10)))->modify('+' . max(0, (int) $s['due_days']) . ' days')->format('Y-m-d'),
                $notes ?: null, AmetecsLicences::$actor, $now, $now]);
        $inv = (array) self::byId((string) $pdo->lastInsertId());
        AmetecsData::log(AmetecsLicences::$actor, 'Raised invoice ' . $number, $tenant, $description . ' · ' . $currency . ' ' . $inv['total']);
        return $inv;
    }

    /** An invoice for a paid release, for this client. */
    public static function forUpgrade(array $release, string $tenant): array
    {
        $name = AmetecsAccess::plugins(true)[$release['app']]['name'] ?? $release['app'];
        return self::create($tenant, 'upgrade', 'Upgrade: ' . $name . ' ' . $release['version'] . ($release['title'] ? ' - ' . $release['title'] : ''), (float) $release['price'],
            (string) $release['currency'], (string) (AmetecsLicences::row($tenant, (string) $release['app'])['id'] ?? '') ?: null, (string) $release['id']);
    }

    /** An invoice for a licence's next period. */
    public static function forRenewal(array $licence): array
    {
        $name = AmetecsAccess::plugins(true)[$licence['app']]['name'] ?? $licence['app'];
        $next = AmetecsLicences::nextUntil($licence);
        return self::create((string) $licence['tenant_id'], 'renewal', 'Renewal: ' . $name . ' (' . (AmetecsLicences::PLANS[$licence['plan']] ?? $licence['plan']) . ', ' . (int) $licence['seats'] . ' seats) till ' . ($next ? date('d M Y', (int) strtotime($next)) : '-'),
            (float) $licence['price'], (string) $licence['currency'], (string) $licence['id']);
    }

    // ------------------------------------------------------------------ self-service plans (owner, 18 Nov 2026: "self buy and update plan")

    /** Super Admin's price list: [app => ['monthly' => per seat, 'yearly' => per seat]] in INR, before GST. Kept apart from settings(). */
    public static function prices(): array
    {
        try {
            $q = self::pdo()->prepare('SELECT value FROM platform_settings WHERE ckey = ? LIMIT 1');
            $q->execute(['ag_prices']);
            $v = json_decode((string) $q->fetchColumn(), true);
        } catch (\Throwable) {
            $v = null;
        }
        return is_array($v) ? $v : [];
    }

    public static function savePrices(array $p): void
    {
        $pdo = self::pdo();
        $now = self::now();
        $json = json_encode($p, JSON_UNESCAPED_SLASHES);
        $id = $pdo->prepare('SELECT id FROM platform_settings WHERE ckey = ? LIMIT 1');
        $id->execute(['ag_prices']);
        ($row = $id->fetchColumn()) !== false
            ? $pdo->prepare('UPDATE platform_settings SET value = ?, updated_at = ? WHERE id = ?')->execute([$json, $now, $row])
            : $pdo->prepare('INSERT INTO platform_settings (ckey, value, created_at, updated_at) VALUES (?, ?, ?, ?)')->execute(['ag_prices', $json, $now, $now]);
    }

    /**
     * What a plan costs the client now: the new period starts on payment; the unused part of what they already paid
     * for is taken off. @return array{base: float, credit: float, amount: float, until: string}
     */
    public static function quotePlan(string $tenant, string $app, string $plan, int $seats): array
    {
        $rate = (float) (self::prices()[$app][$plan] ?? 0);
        if ($rate <= 0 || $seats < 1 || !in_array($plan, ['monthly', 'yearly'], true)) {
            throw new \RuntimeException('This plan is not offered online. Contact Ametecs.');
        }
        $today = AmetecsAccess::today();
        $base = round($rate * $seats, 2);
        $credit = self::creditFor($tenant, $app);
        if ($base - $credit < 1) {
            throw new \RuntimeException('A smaller plan than the one you have starts at your next renewal. Contact Ametecs to arrange it.');
        }
        return ['base' => $base, 'credit' => $credit, 'amount' => round($base - $credit, 2), 'until' => (new DateTimeImmutable($today))->modify($plan === 'yearly' ? '+1 year' : '+1 month')->format('Y-m-d')];
    }

    /** The unused part of the client's current paid plan for this app, in INR (taken off a new plan). */
    public static function creditFor(string $tenant, string $app): float
    {
        $l = AmetecsLicences::row($tenant, $app);
        $today = AmetecsAccess::today();
        if ($l === null || AmetecsLicences::stateOf($l) !== 'on' || (float) ($l['price'] ?? 0) <= 0 || $l['currency'] !== 'INR' || (string) ($l['valid_until'] ?? '') <= $today
            || !in_array($l['plan'], ['monthly', 'yearly'], true)) {
            return 0.0;
        }
        $left = (strtotime((string) $l['valid_until']) - strtotime($today)) / 86400;
        return round((float) $l['price'] * min(1, $left / ($l['plan'] === 'yearly' ? 365 : 30)), 2);   // ponytail: a month counted as 30 days
    }

    /**
     * EXTRA SEATS ON THE CURRENT PLAN (owner, 18 Nov 2026: "self buy extra user and pay"): the per-seat rate (Super
     * Admin's price list, else the licence's own price / seats), charged only for the days left of the current period;
     * the end date stays. @return array{rate: float, factor: float, amount: float, licence: array}|null  null = not sold online
     */
    public static function seatOffer(string $tenant, string $app): ?array
    {
        $l = AmetecsLicences::row($tenant, $app);
        $today = AmetecsAccess::today();
        if ($l === null || AmetecsLicences::stateOf($l) !== 'on' || !in_array($l['plan'], ['monthly', 'yearly'], true) || (string) ($l['valid_until'] ?? '') <= $today
            || ($l['currency'] !== 'INR' && (float) ($l['price'] ?? 0) > 0)) {
            return null;
        }
        $rate = (float) (self::prices()[$app][$l['plan']] ?? 0);
        if ($rate <= 0 && (float) ($l['price'] ?? 0) > 0 && (int) $l['seats'] > 0) {
            $rate = round((float) $l['price'] / (int) $l['seats'], 2);
        }
        if ($rate <= 0) {
            return null;
        }
        $len = $l['plan'] === 'yearly' ? 365 : 30;   // ponytail: a month counted as 30 days
        $factor = min(1.0, max(0.0, (strtotime((string) $l['valid_until']) - strtotime($today)) / 86400 / $len));
        return ['rate' => $rate, 'factor' => round($factor, 4), 'licence' => $l];
    }

    public static function forSeats(string $tenant, string $app, int $add): array
    {
        $o = self::seatOffer($tenant, $app);
        if ($o === null || $add < 1 || $add > 100000) {
            throw new \RuntimeException('Extra seats for this application are not sold online. Contact Ametecs.');
        }
        $amount = round($o['rate'] * $add * $o['factor'], 2);
        if ($amount < 1) {
            throw new \RuntimeException('Your plan ends in a few days: renew it first, then add seats.');
        }
        $l = $o['licence'];
        foreach (self::invoices($tenant, 'pending') as $old) {   // one open seat invoice per app
            if ($old['purpose'] === 'seats' && (json_decode((string) $old['notes'], true)['app'] ?? '') === $app) {
                self::cancel($old, 'replaced by a new seat choice');
            }
        }
        $name = AmetecsAccess::plugins(true)[$app]['name'] ?? $app;
        return self::create($tenant, 'seats', 'Extra seats: ' . $name . ' +' . $add . ' (' . (int) $l['seats'] . ' to ' . ((int) $l['seats'] + $add) . ') till ' . date('d M Y', (int) strtotime((string) $l['valid_until']))
            . ' - INR ' . number_format($o['rate'], 2) . ' per seat per ' . ($l['plan'] === 'yearly' ? 'year' : 'month') . ', for the time left', $amount, 'INR', (string) $l['id'], null,
            (string) json_encode(['app' => $app, 'add' => $add, 'rate' => $o['rate']]));
    }

    /** An invoice for a new or changed plan; paying it applies the plan (apply()). */
    public static function forPlan(string $tenant, string $app, string $plan, int $seats): array
    {
        $q = self::quotePlan($tenant, $app, $plan, $seats);
        foreach (self::invoices($tenant, 'pending') as $old) {   // one open plan invoice per app: the latest choice wins
            if ($old['purpose'] === 'plan' && (json_decode((string) $old['notes'], true)['app'] ?? '') === $app) {
                self::cancel($old, 'replaced by a new plan choice');
            }
        }
        $name = AmetecsAccess::plugins(true)[$app]['name'] ?? $app;
        $l = AmetecsLicences::row($tenant, $app);
        return self::create($tenant, 'plan', ($l === null ? 'New: ' : 'Plan change: ') . $name . ' (' . AmetecsLicences::PLANS[$plan] . ', ' . $seats . ' seats) till ' . date('d M Y', (int) strtotime($q['until']))
            . ($q['credit'] > 0 ? ' - less INR ' . number_format($q['credit'], 2) . ' unused on the current plan' : ''), $q['amount'], 'INR', $l !== null ? (string) $l['id'] : null, null,
            (string) json_encode(['app' => $app, 'plan' => $plan, 'seats' => $seats, 'price' => $q['base']]));
    }

    public static function cancel(array $inv, string $why): void
    {
        if ($inv['status'] !== 'pending') {
            throw new \RuntimeException('Only a pending invoice can be cancelled.');
        }
        self::pdo()->prepare("UPDATE ag_invoices SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), ?), updated_at = ? WHERE id = ? AND status = 'pending'")
            ->execute(["\nCancelled: " . $why, self::now(), $inv['id']]);
        AmetecsData::log(AmetecsLicences::$actor, 'Cancelled invoice ' . $inv['number'], (string) $inv['tenant_id'], $why);
    }

    // ------------------------------------------------------------------ paying

    /** A Razorpay order for a pending INR invoice (one per invoice, reused). @return array{ok: bool, why?: string, keyId?: string, orderId?: string, amountPaise?: int} */
    public static function order(array $inv): array
    {
        $gw = self::gateway();
        if ($gw === null) {
            return ['ok' => false, 'why' => 'Online payment is not switched on yet. Contact Ametecs to pay by bank transfer.'];
        }
        if ($inv['status'] !== 'pending') {
            return ['ok' => false, 'why' => 'This invoice is ' . $inv['status'] . '.'];
        }
        if ($inv['currency'] !== 'INR') {
            return ['ok' => false, 'why' => 'Online payment is in rupees only. Contact Ametecs for ' . $inv['currency'] . ' invoices.'];
        }
        $paise = (int) round((float) $inv['total'] * 100);
        if (trim((string) $inv['gateway_order_id']) === '') {
            $r = self::call('POST', '/orders', ['amount' => $paise, 'currency' => 'INR', 'receipt' => (string) $inv['number'], 'notes' => ['invoice' => $inv['number'], 'tenant_id' => (string) $inv['tenant_id']]], $gw);
            $id = (string) ($r['json']['id'] ?? '');
            if ($r['code'] < 200 || $r['code'] >= 300 || $id === '') {
                return ['ok' => false, 'why' => 'Razorpay did not create the order: ' . ($r['json']['error']['description'] ?? 'HTTP ' . $r['code'])];
            }
            self::pdo()->prepare('UPDATE ag_invoices SET gateway_order_id = ?, updated_at = ? WHERE id = ? AND gateway_order_id IS NULL')->execute([$id, self::now(), $inv['id']]);
            $inv = (array) self::byId((string) $inv['id']);
        }
        return ['ok' => true, 'keyId' => $gw['key'], 'orderId' => (string) $inv['gateway_order_id'], 'amountPaise' => $paise];
    }

    /** The browser's Razorpay handler result, checked by signature. @return array{ok: bool, why: string} */
    public static function completeSigned(array $inv, string $orderId, string $paymentId, string $signature): array
    {
        $gw = self::gateway();
        if ($gw === null || $orderId === '' || $paymentId === '' || !hash_equals((string) $inv['gateway_order_id'], $orderId)
            || $signature === '' || !hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $gw['secret']), $signature)) {
            AmetecsData::log(AmetecsLicences::$actor, 'Payment verification FAILED for invoice ' . $inv['number'], (string) $inv['tenant_id'], 'payment ' . $paymentId);
            return ['ok' => false, 'why' => 'The payment could not be verified. If money was taken, contact Ametecs with reference ' . ($paymentId ?: 'none') . ' - it is checked with Razorpay and applied.'];
        }
        return self::markPaid($inv, (float) $inv['total'], 'razorpay', $paymentId, 'signature verified');
    }

    /** Ask Razorpay whether the invoice's order was paid (no browser involved). @return array{ok: bool, why: string} */
    public static function reconcile(array $inv): array
    {
        $gw = self::gateway();
        if ($gw === null || trim((string) $inv['gateway_order_id']) === '') {
            return ['ok' => false, 'why' => $gw === null ? 'Razorpay is not set up.' : 'This invoice has no Razorpay order yet - the client has not started paying.'];
        }
        $r = self::call('GET', '/orders/' . rawurlencode((string) $inv['gateway_order_id']) . '/payments', [], $gw);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'why' => 'Razorpay did not answer: ' . ($r['json']['error']['description'] ?? 'HTTP ' . $r['code'])];
        }
        foreach ((array) ($r['json']['items'] ?? []) as $p) {
            if (in_array((string) ($p['status'] ?? ''), ['captured', 'authorized'], true) && (int) ($p['amount'] ?? 0) >= (int) round((float) $inv['total'] * 100)) {
                return self::markPaid($inv, (int) $p['amount'] / 100, 'razorpay', (string) $p['id'], 'confirmed by Razorpay API (' . $p['status'] . ', ' . ($p['method'] ?? '?') . ')', (string) ($p['method'] ?? ''));
            }
        }
        return ['ok' => false, 'why' => 'Razorpay has no successful payment for this invoice.'];
    }

    /**
     * Record a payment and apply what was bought. Idempotent: the invoice row is locked and must still be pending;
     * a Razorpay payment id is recorded once (unique key).
     *
     * @return array{ok: bool, why: string}
     */
    public static function markPaid(array $inv, float $amount, string $gateway, string $reference, string $detail, string $method = ''): array
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM ag_invoices WHERE id = ? FOR UPDATE');
            $q->execute([$inv['id']]);
            $cur = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$cur || $cur['status'] !== 'pending') {
                $pdo->rollBack();
                return ['ok' => $cur && $cur['status'] === 'paid', 'why' => $cur && $cur['status'] === 'paid' ? 'Already paid.' : 'This invoice is ' . ($cur['status'] ?? 'missing') . '.'];
            }
            $now = self::now();
            $pdo->prepare('INSERT INTO ag_payments (invoice_id, tenant_id, amount, currency, gateway, method, gateway_payment_id, status, detail, by_who, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, \'succeeded\', ?, ?, ?)')
                ->execute([$cur['id'], $cur['tenant_id'], number_format($amount, 2, '.', ''), $cur['currency'], $gateway, $method ?: null, $reference !== '' ? $reference : null, $detail, AmetecsLicences::$actor, $now]);
            $pdo->prepare("UPDATE ag_invoices SET status = 'paid', paid_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $cur['id']]);
            $pdo->commit();
        } catch (\Throwable $x) {
            $pdo->inTransaction() && $pdo->rollBack();
            return ['ok' => false, 'why' => 'The payment was not recorded: ' . $x->getMessage()];
        }
        self::apply($cur, 'paid');
        AmetecsData::log(AmetecsLicences::$actor, 'Payment received for invoice ' . $cur['number'], (string) $cur['tenant_id'], $cur['currency'] . ' ' . number_format($amount, 2) . ' · ' . $gateway . ' ' . $reference);
        return ['ok' => true, 'why' => 'Payment received for ' . $cur['number'] . '. ' . ($cur['purpose'] === 'upgrade' ? 'The upgrade is unlocked.' : ($cur['purpose'] === 'renewal' ? 'The licence is renewed.' : ($cur['purpose'] === 'plan' ? 'Your new plan is active.' : ($cur['purpose'] === 'seats' ? 'The extra seats are added.' : ''))))];
    }

    /** Full refund of one Razorpay payment (or record an offline refund). Takes back what was bought. @return array{ok: bool, why: string} */
    public static function refund(array $payment, string $why): array
    {
        if ($payment['status'] !== 'succeeded') {
            return ['ok' => false, 'why' => 'Only a received payment can be refunded.'];
        }
        $inv = (array) self::byId((string) $payment['invoice_id']);
        $refundId = 'offline';
        if ($payment['gateway'] === 'razorpay') {
            $gw = self::gateway();
            if ($gw === null) {
                return ['ok' => false, 'why' => 'Razorpay is not set up, so the refund cannot be sent.'];
            }
            $r = self::call('POST', '/payments/' . rawurlencode((string) $payment['gateway_payment_id']) . '/refund', ['amount' => (int) round((float) $payment['amount'] * 100), 'notes' => ['reason' => mb_substr($why, 0, 200)]], $gw);
            $refundId = (string) ($r['json']['id'] ?? '');
            if ($r['code'] < 200 || $r['code'] >= 300 || $refundId === '') {
                return ['ok' => false, 'why' => 'Razorpay refused the refund: ' . ($r['json']['error']['description'] ?? 'HTTP ' . $r['code'])];
            }
        }
        $now = self::now();
        self::pdo()->prepare("UPDATE ag_payments SET status = 'refunded', refund_id = ?, refunded_at = ?, detail = CONCAT(COALESCE(detail, ''), ?) WHERE id = ? AND status = 'succeeded'")
            ->execute([$refundId, $now, "\nRefunded: " . $why, $payment['id']]);
        self::pdo()->prepare("UPDATE ag_invoices SET status = 'refunded', updated_at = ? WHERE id = ?")->execute([$now, $inv['id']]);
        self::apply($inv, 'refunded');
        AmetecsData::log(AmetecsLicences::$actor, 'Refunded invoice ' . $inv['number'], (string) $inv['tenant_id'], $why . ' · ' . $refundId);
        return ['ok' => true, 'why' => 'Refunded ' . $payment['currency'] . ' ' . number_format((float) $payment['amount'], 2) . ($refundId !== 'offline' ? ' through Razorpay (' . $refundId . ')' : ' (recorded as an offline refund)') . '.'];
    }

    /** A printable invoice page (navy + gold), for Super Admin and the client's administrator alike. */
    public static function printHtml(array $inv): string
    {
        $s = self::settings();
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $q = self::pdo()->prepare('SELECT t.name, t.code, t.state FROM tenants t WHERE t.id = ?');
        $q->execute([$inv['tenant_id']]);
        $t = $q->fetch(\PDO::FETCH_ASSOC) ?: ['name' => '#' . $inv['tenant_id'], 'code' => '', 'state' => ''];
        $m = static fn ($v): string => $inv['currency'] . ' ' . number_format((float) $v, 2);
        [$sl, $sc] = self::STATUSES[$inv['status']] ?? [$inv['status'], 'grey'];
        $pays = '';
        foreach (self::payments((string) $inv['id']) as $p) {
            $pays .= '<tr><td>' . $e(date('d M Y H:i', (int) strtotime((string) $p['created_at']))) . '</td><td>' . $e(ucfirst((string) $p['gateway'])) . ($p['method'] ? ' · ' . $e($p['method']) : '') . '</td><td>' . $e((string) $p['gateway_payment_id']) . '</td><td>'
                . $e($p['status'] === 'refunded' ? 'Refunded ' . $p['refunded_at'] : 'Received') . '</td><td class="r">' . $e($m($p['amount'])) . '</td></tr>';
        }
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Invoice ' . $e($inv['number']) . '</title><style>
@page{size:A4;margin:12mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{font:13px/1.5 "Segoe UI",Arial,sans-serif;color:#17130c;margin:0;background:#faf6ee}
/* ROYAL BLACK AND GOLD (owner, 19 Nov 2026: "invoice also update according to my theme") */
.pg{max-width:820px;margin:20px auto;background:#fff;box-shadow:0 6px 28px rgba(60,40,10,.12);border:1px solid #ece3d1}
.band{background:linear-gradient(160deg,#1d170e,#0a0704);color:#fff;padding:24px 30px;display:flex;justify-content:space-between;align-items:center;border-bottom:4px solid #c9a24c}
.band b{font-size:23px;color:#fff7e6}.band b span{color:#e8c977}.band .t{text-align:right;font-size:12px;color:#d8cbb2}.band .t strong{display:block;font-size:20px;color:#e8c977;letter-spacing:1.5px}
.bd{padding:26px 30px}.cards{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px}.card{border:1px solid #ece3d1;border-top:3px solid #c9a24c;border-radius:8px;padding:12px 14px;background:#fffdf8}
.card h4{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#b08433}.card b,.card strong{color:#1f3a5f}
table{width:100%;border-collapse:collapse;margin-bottom:18px}th{background:#14100a;color:#e8c977;text-align:left;font-size:12px;padding:9px 10px;letter-spacing:.3px}td{padding:8px 10px;border-bottom:1px solid #f1e9d8}tr:nth-child(even) td{background:#fdfaf3}
.r{text-align:right}.tot td{font-weight:700;color:#1f3a5f;background:#fbf3e2!important;border-top:2px solid #c9a24c;font-size:14px}.st{display:inline-block;padding:3px 12px;border-radius:99px;font-weight:700;font-size:12px}
.st.green{background:#ecfdf3;color:#15803d}.st.amber{background:#fdf3dc;color:#8a6420}.st.red{background:#fbecee;color:#8b1e2d}.st.grey{background:#f3ece0;color:#7a6e5b}
.ft{border-top:1px solid #ece3d1;padding:12px 30px;color:#9c8f78;font-size:11px;display:flex;justify-content:space-between;background:#fffdf8}.np{text-align:center;margin:14px}@media print{body{background:#fff}.pg{box-shadow:none;margin:0;border:0}.np{display:none}}
</style></head><body><div class="np"><button onclick="print()" style="padding:9px 20px;border:0;border-radius:9px;background:linear-gradient(135deg,#f3dc95,#b08433);color:#14100a;font-weight:700;cursor:pointer">Print / save as PDF</button></div><div class="pg">
<div class="band"><b>Ametecs<span>Global</span></b><div class="t"><strong>TAX INVOICE</strong>' . $e($inv['number']) . ' &middot; ' . $e(date('d M Y', (int) strtotime((string) $inv['issued_on']))) . '</div></div><div class="bd">
<div class="cards"><div class="card"><h4>From</h4><b>' . $e($s['seller']) . '</b><br>' . nl2br($e($s['address'])) . ($s['gstin'] !== '' ? '<br>GSTIN ' . $e($s['gstin']) : '') . ($s['email'] !== '' ? '<br>' . $e($s['email']) : '') . '</div>
<div class="card"><h4>Billed to</h4><b>' . $e($t['name']) . '</b><br>Client code ' . $e($t['code']) . ($t['state'] ? '<br>' . $e($t['state']) : '') . '<br><br><span class="st ' . $sc . '">' . $e($sl) . '</span>' . ($inv['status'] === 'pending' ? ' &nbsp;due ' . $e(date('d M Y', (int) strtotime((string) $inv['due_on']))) : '') . '</div></div>
<table><tr><th>Description</th><th class="r">Amount</th></tr><tr><td>' . $e($inv['description']) . '</td><td class="r">' . $e($m($inv['amount'])) . '</td></tr>'
            . ((float) $inv['tax'] > 0 ? '<tr><td>GST ' . $e(rtrim(rtrim((string) $inv['tax_percent'], '0'), '.')) . '%</td><td class="r">' . $e($m($inv['tax'])) . '</td></tr>' : '')
            . '<tr class="tot"><td>Total</td><td class="r">' . $e($m($inv['total'])) . '</td></tr></table>'
            . ($pays !== '' ? '<table><tr><th>Paid on</th><th>Via</th><th>Reference</th><th>Status</th><th class="r">Amount</th></tr>' . $pays . '</table>' : '')
            . '</div><div class="ft"><span>Confidential &middot; ' . $e($s['seller']) . '</span><span>Page 1 of 1</span></div></div></body></html>';
    }

    /** What a paid / refunded invoice does to the release or licence it is for. */
    private static function apply(array $inv, string $what): void
    {
        $tid = (string) $inv['tenant_id'];
        if ($inv['purpose'] === 'upgrade' && ($r = AmetecsReleases::byId((string) $inv['release_id'])) !== null) {
            AmetecsReleases::event($r, (string) $r['app'], $tid, $what, '', (string) $r['version'], 'invoice ' . $inv['number'], $inv['licence_id'] !== null ? (string) $inv['licence_id'] : null);
        }
        if ($inv['purpose'] === 'renewal' && ($l = AmetecsLicences::byId((string) $inv['licence_id'])) !== null) {
            if ($what === 'paid' && ($next = AmetecsLicences::nextUntil($l)) !== null) {
                AmetecsLicences::put($tid, (string) $l['app'], ['valid_until' => $next, 'status' => $l['status'] === 'cancelled' ? 'active' : $l['status']], 'Renewed (paid ' . $inv['number'] . ')');
            } else {
                AmetecsLicences::event($l, 'Renewal payment refunded', 'invoice ' . $inv['number'] . ' - adjust the valid-till date by hand if needed');
            }
        }
        if ($inv['purpose'] === 'seats' && is_array($n = json_decode((string) $inv['notes'], true)) && isset($n['app'], $n['add']) && ($l = AmetecsLicences::row($tid, (string) $n['app'])) !== null) {
            if ($what === 'paid') {
                // the yearly / monthly price grows with the seats only where the licence has a price; "as agreed" stays as agreed
                AmetecsLicences::put($tid, (string) $n['app'], ['seats' => (int) $l['seats'] + (int) $n['add']] + ((float) ($l['price'] ?? 0) > 0 ? ['price' => number_format((float) $l['price'] + (float) $n['rate'] * (int) $n['add'], 2, '.', '')] : []),
                    'Seats bought online (' . $inv['number'] . ')');
            } else {
                AmetecsLicences::event($l, 'Seat payment refunded', 'invoice ' . $inv['number'] . ' - take the seats back by hand if needed');
            }
        }
        if ($inv['purpose'] === 'plan' && is_array($n = json_decode((string) $inv['notes'], true)) && isset($n['app'], $n['plan'], $n['seats'])) {
            if ($what === 'paid') {
                AmetecsLicences::put($tid, (string) $n['app'], ['plan' => $n['plan'], 'seats' => (int) $n['seats'], 'price' => number_format((float) $n['price'], 2, '.', ''), 'currency' => 'INR',
                    'status' => 'active', 'valid_until' => (new DateTimeImmutable(AmetecsAccess::today()))->modify($n['plan'] === 'yearly' ? '+1 year' : '+1 month')->format('Y-m-d')], 'Plan bought online (' . $inv['number'] . ')');
            } elseif (($l = AmetecsLicences::row($tid, (string) $n['app'])) !== null) {
                AmetecsLicences::event($l, 'Plan payment refunded', 'invoice ' . $inv['number'] . ' - set the plan, seats and date back by hand if needed');
            }
        }
    }
}
