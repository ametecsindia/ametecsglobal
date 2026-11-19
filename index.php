<?php
// AMETECSGLOBAL LANDING PAGE (owner, 19 Nov 2026: "now design landing page"; "complete landing page, add all required
// sections, elements, content and everything"). Public front door at http://<server>/AmetecsGlobal/, royal black and gold.
// The demo form is saved to the platform activity log (core/data/activity.jsonl), so it shows in Super Admin ->
// Activity log and the dashboard's Latest changes. ponytail: no email yet - send one when email settings are configured.
declare(strict_types=1);

$sent = false;
$err = [];
$v = static fn (string $k): string => trim((string) ($_POST[$k] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $v('do') === 'demo') {
    if ($v('website') === '') {   // honeypot: bots fill every field
        if (mb_strlen($v('name')) < 2) { $err['name'] = 'Enter your name.'; }
        if (mb_strlen($v('company')) < 2) { $err['company'] = 'Enter your company name.'; }
        if (!filter_var($v('email'), FILTER_VALIDATE_EMAIL)) { $err['email'] = 'Enter a valid work email, like name@company.com.'; }
        if (!preg_match('/^(\+?91[\s-]?)?[6-9]\d{9}$/', preg_replace('/[\s-]/', '', $v('phone')))) { $err['phone'] = 'Enter a 10-digit mobile number.'; }
        if ($err === []) {
            $apps = implode(', ', array_map('strval', array_slice((array) ($_POST['apps'] ?? []), 0, 6)));
            @mkdir(__DIR__ . '/core/data', 0775, true);
            file_put_contents(__DIR__ . '/core/data/activity.jsonl', json_encode([
                'at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s'),
                'who' => mb_substr($v('email'), 0, 120), 'what' => 'Demo request: ' . mb_substr($v('company'), 0, 120), 'tenant' => '',
                'detail' => mb_substr(implode(' · ', array_filter([$v('name'), $v('phone'), $v('size') !== '' ? $v('size') . ' employees' : '', $apps !== '' ? 'Apps: ' . $apps : '', $v('message')])), 0, 1500),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    $sent = $err === [];
}
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// ponytail: static catalogue; mirrors AmetecsAccess::plugins() - read it from there if the catalogue starts changing often.
$apps = [
    ['SmartPRS 3', 'HR, payroll and compliance', 'fa-people-group', 'Live', ['Employee directory, onboarding and exit', 'Attendance, leave and shifts', 'Payroll with PF, ESI, PT and TDS', 'Payslips, Form 16 and ID cards']],
    ['SmartEPT', 'Employee productivity tracking', 'fa-chart-line', 'Arriving soon', ['Work time and activity', 'Office and remote teams', 'Daily and weekly reports', 'Team and person views']],
    ['SmartDCM', 'Debt collection management', 'fa-hand-holding-dollar', 'Arriving soon', ['Allocations to agents', 'Calling and follow-ups', 'Payments and promises to pay', 'Agency and portfolio reports']],
    ['LeadGER', 'Lead management', 'fa-bullseye', 'Arriving soon', ['Capture leads from every source', 'Assign and follow up', 'Pipeline and stages', 'Conversion reports']],
    ['ROVEXY', 'Field force and visits', 'fa-route', 'Arriving soon', ['Field visits and routes', 'Live location of field staff', 'Visit proof and notes', 'Daily field reports']],
    ['QRONEX', 'QR attendance', 'fa-qrcode', 'Arriving soon', ['QR codes at gates and offices', 'Geo-fenced punches', 'Works with the mobile app', 'Feeds attendance everywhere']],
];
$features = [
    ['fa-sitemap', 'One organisation, every app', 'Companies, branches, departments, designations, teams and shifts are set up once. Every application reads the same records.'],
    ['fa-user-shield', 'One login per employee', 'Add an employee and they can sign in straight away. Switch any app on or off by company, branch, department, team or person.'],
    ['fa-fingerprint', 'Attendance devices built in', 'Biometric machines, QR gates and mobile punches feed attendance into every app at once. Register a device once.'],
    ['fa-key', 'Licences and seats you control', 'See seats used, renewal dates and plans for each app. Add users or change plan online, paying only for the days left.'],
    ['fa-cloud-arrow-up', 'Updates from one hub', 'New versions and add-on modules arrive in one place, for cloud and on-premise servers alike.'],
    ['fa-chart-pie', 'A live dashboard', 'Attendance, workforce, birthdays, plan usage and recent activity across all your apps, refreshed every minute.'],
];
$faq = [
    ['What is AmetecsGlobal?', 'It is the one platform that runs every Ametecs application. Your organisation, employees, logins, licences and updates live in one place, and each application plugs into it.'],
    ['Do I have to take every application?', 'No. Choose only the applications you need. You can add another one later from Licences and plans, and it works with everything you already set up.'],
    ['Can we run it on our own server?', 'Yes. Every application can run in the Ametecs cloud or on your own server with a licence key. Updates for on-premise servers come from the same hub and install when you choose.'],
    ['How are employees and logins managed?', 'Employees are added once in Organization Setup, one by one or by import. Each employee gets a login straight away, and you choose which applications they can use.'],
    ['Which attendance devices work with it?', 'Biometric machines, QR codes at gates and offices, and the mobile app with approved phones. Every punch reaches every application that needs it.'],
    ['How is pricing worked out?', 'Pricing is per user, billed monthly or yearly, for each application you use. You can add users at any time and pay only for the days left in your plan.'],
    ['Is our data kept separate from other companies?', 'Yes. Each client company has its own workspace and its own sign-in link. Administrators see only their own company, applications and invoices.'],
    ['How do we get started?', 'Request a demo below. Ametecs sets up your workspace and sign-in link, switches on your applications and helps you load your organisation.'],
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AmetecsGlobal · Every Ametecs application on one platform</title>
<meta name="description" content="AmetecsGlobal brings SmartPRS, SmartEPT, SmartDCM, LeadGER, ROVEXY and QRONEX together on one platform: one organisation, one login per employee, one place for licences and updates. Cloud or on-premise.">
<meta property="og:title" content="AmetecsGlobal · Every Ametecs application on one platform">
<meta property="og:description" content="HR and payroll, productivity, collections, leads, field force and QR attendance on one platform, by Ametecs India.">
<meta name="theme-color" content="#0f0b06">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect rx='16' width='64' height='64' fill='%23c9a24c'/%3E%3Ctext x='32' y='42' font-family='Arial' font-weight='900' font-size='26' text-anchor='middle' fill='%2314100a'%3EAG%3C/text%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--ink:#17130c;--mute:#6f6350;--line:#ece3d1;--ivory:#faf6ee;--gold:#c9a24c;--g:linear-gradient(135deg,#f3dc95 0%,#d6ae55 45%,#b08433 100%);--navy:#1f3a5f;--red:#8b1e2d;--dark:linear-gradient(170deg,#1d170e 0%,#0f0b06 60%,#0a0704 100%)}
*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:76px}
body{margin:0;font-family:Inter,"Segoe UI",system-ui,-apple-system,Roboto,sans-serif;color:var(--ink);background:var(--ivory);line-height:1.55;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}img{max-width:100%}
.wrap{max-width:1180px;margin:0 auto;padding:0 28px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:12px 22px;border-radius:12px;font-weight:700;font-size:14.5px;transition:transform .15s,box-shadow .15s,background .15s;border:0;cursor:pointer;font-family:inherit}
.btn:hover{transform:translateY(-2px)}
.btn.gold{background:var(--g);color:#14100a;box-shadow:0 12px 26px -12px rgba(176,132,51,.9)}
.btn.line{border:1px solid rgba(232,201,119,.4);color:#f3e7c9;background:transparent}.btn.line:hover{background:rgba(232,201,119,.08)}
.btn.dark{background:#14100a;color:#f3dc95}
.k{font-size:12.5px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:#b08433}
.sh{text-align:center;max-width:740px;margin:0 auto 46px}
.sh h2{font-size:36px;letter-spacing:-.8px;line-height:1.15;margin:10px 0 12px;color:var(--navy)}
.sh p{color:var(--mute);font-size:16.5px;margin:0}
section{padding:92px 0}
.reveal{opacity:0;transform:translateY(18px);transition:opacity .6s,transform .6s}.reveal.in{opacity:1;transform:none}
@media (prefers-reduced-motion:reduce){.reveal{opacity:1;transform:none;transition:none}html{scroll-behavior:auto}}

/* top bar */
.nav{position:sticky;top:0;z-index:30;background:rgba(15,11,6,.9);backdrop-filter:blur(10px);border-bottom:1px solid rgba(232,201,119,.14)}
.nav .wrap{display:flex;align-items:center;gap:26px;height:68px}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:19px;color:#fff7e6;letter-spacing:-.2px}
.logo .lm{width:34px;height:34px;border-radius:10px;background:var(--g);color:#14100a;display:grid;place-items:center;font-size:13px;font-weight:900}
.logo b{background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent}
.nav nav{display:flex;gap:22px;margin-left:auto;font-size:14px;color:#d8cbb2}.nav nav a{padding:6px 0;border-bottom:2px solid transparent}.nav nav a:hover,.nav nav a.on{color:#f3dc95;border-bottom-color:#d6ae55}
.nav .acts{display:flex;gap:10px}.nav .btn{padding:9px 18px;font-size:13.5px}
.burger{display:none;margin-left:auto;background:none;border:1px solid rgba(232,201,119,.3);color:#f3dc95;width:40px;height:40px;border-radius:10px;font-size:16px;cursor:pointer}
.mnav{display:none}

/* hero */
.hero{background:radial-gradient(900px 500px at 85% -10%,rgba(214,174,85,.28),transparent 60%),radial-gradient(700px 500px at -10% 110%,rgba(214,174,85,.14),transparent 60%),var(--dark);color:#f3e7c9;padding:80px 0 120px;position:relative;overflow:hidden}
.hero:after{content:"";position:absolute;right:-160px;bottom:-200px;width:520px;height:520px;border-radius:50%;border:1px solid rgba(214,174,85,.16);box-shadow:0 0 0 70px rgba(214,174,85,.03),0 0 0 140px rgba(214,174,85,.02)}
.hero .wrap{display:grid;grid-template-columns:1.05fr 1fr;gap:56px;align-items:center;position:relative;z-index:1}
.eyebrow{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:99px;border:1px solid rgba(232,201,119,.3);background:rgba(232,201,119,.08);font-size:12.5px;font-weight:600;color:#f3dc95;letter-spacing:.3px}
.hero h1{font-size:54px;line-height:1.06;letter-spacing:-1.5px;margin:20px 0 18px;color:#fff7e6}
.hero h1 em{font-style:normal;background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p.lead{font-size:18px;color:#cbbd9f;max-width:540px;margin:0 0 30px}
.cta{display:flex;gap:12px;flex-wrap:wrap}
.trust{display:flex;gap:22px;margin-top:34px;font-size:13px;color:#a89b82;flex-wrap:wrap}.trust i{color:#d6ae55;margin-right:6px}
.shot{border-radius:18px;background:#faf6ee;box-shadow:0 40px 80px -30px rgba(0,0,0,.8),0 0 0 1px rgba(232,201,119,.25);overflow:hidden;transform:perspective(1400px) rotateY(-8deg) rotateX(3deg);transition:transform .5s}
.shot:hover{transform:perspective(1400px) rotateY(-2deg) rotateX(1deg)}
.bar3{height:30px;background:#14100a;display:flex;align-items:center;gap:6px;padding:0 12px}.bar3 i{width:9px;height:9px;border-radius:50%;background:#3a3120}.bar3 span{margin-left:10px;font-size:10px;color:#8c7f68}
.shot .in{display:grid;grid-template-columns:96px 1fr}
.msb{background:linear-gradient(170deg,#1d170e,#0a0704);padding:12px 9px;display:grid;gap:7px;align-content:start}
.msb span{height:9px;border-radius:5px;background:rgba(232,201,119,.16)}.msb span.on{background:var(--g)}
.mmn{padding:14px;display:grid;gap:10px}
.tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.t{background:#fff;border:1px solid var(--line);border-top:3px solid var(--gold);border-radius:8px;padding:8px}
.t small{display:block;font-size:9px;color:var(--mute)}.t b{font-size:17px;color:var(--navy)}.t.r{border-top-color:var(--red)}.t.r b{color:var(--red)}
.mch{background:#fff;border:1px solid var(--line);border-radius:8px;padding:10px;display:flex;align-items:flex-end;gap:7px;height:110px}
.mch i{flex:1;border-radius:4px 4px 0 0;background:linear-gradient(180deg,#d6ae55,#b08433)}.mch i.n{background:var(--navy)}
.float{position:absolute;background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 14px;box-shadow:0 18px 40px -18px rgba(0,0,0,.6);font-size:12px;color:var(--ink);display:flex;gap:10px;align-items:center;z-index:2}
.float i{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:#14100a;color:#e8c977}
.float b{display:block;font-size:13px;color:var(--navy)}
.f1{left:-24px;bottom:-22px}.f2{right:-14px;top:-18px}
.herov{position:relative}

/* strip of industries */
.strip{background:#14100a;color:#cbbd9f;padding:22px 0;border-top:1px solid rgba(232,201,119,.14)}
.strip .wrap{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center;font-size:13.5px}
.strip b{color:#e8c977;font-weight:700;letter-spacing:.6px;text-transform:uppercase;font-size:11.5px;margin-right:6px}
.strip span{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:99px;border:1px solid rgba(232,201,119,.2)}.strip span i{color:#d6ae55}

/* numbers */
.nums{margin-top:-58px;position:relative;z-index:3}
.nums .row{display:grid;grid-template-columns:repeat(4,1fr);background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 24px 50px -30px rgba(60,40,10,.4);overflow:hidden}
.nums .row>div{padding:24px 26px;border-right:1px solid var(--line)}.nums .row>div:last-child{border-right:0}
.nums b{display:block;font-size:34px;letter-spacing:-1px;color:var(--navy);line-height:1.1}.nums b em{font-style:normal;background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent}
.nums span{font-size:13.5px;color:var(--mute)}

/* before / after */
.ba{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.bx{border-radius:18px;padding:30px;border:1px solid var(--line);background:#fff}
.bx h3{margin:0 0 16px;font-size:19px;display:flex;align-items:center;gap:10px}
.bx ul{list-style:none;margin:0;padding:0;display:grid;gap:12px}
.bx li{display:grid;grid-template-columns:24px 1fr;gap:10px;font-size:15px;color:#3d3426}
.bx li i{margin-top:3px}
.bx.no h3{color:#8b1e2d}.bx.no li i{color:#b85a66}
.bx.yes{background:var(--dark);border-color:rgba(232,201,119,.25);color:#e9dcc1}.bx.yes h3{color:#f3dc95}.bx.yes li{color:#e0d3b8}.bx.yes li i{color:#d6ae55}

/* applications */
.apps{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.app{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;display:flex;flex-direction:column}
.app:before{content:"";position:absolute;inset:0 0 auto;height:3px;background:var(--g)}
.app:hover{transform:translateY(-4px);box-shadow:0 22px 44px -26px rgba(60,40,10,.45)}
.app .ic{width:48px;height:48px;border-radius:13px;background:#14100a;color:#e8c977;display:grid;place-items:center;font-size:19px;margin-bottom:14px}
.app h3{margin:0;font-size:19px}.app .what{color:#b08433;font-size:13.5px;font-weight:600;margin:2px 0 12px}
.app ul{list-style:none;margin:0;padding:0;display:grid;gap:7px;font-size:14px;color:var(--mute)}.app li i{color:#c9a24c;margin-right:8px;font-size:11px}
.app .st{position:absolute;top:22px;right:20px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;background:#f3ece0;color:var(--mute);text-transform:uppercase;letter-spacing:.5px}
.app .st.live{background:#14100a;color:#e8c977}

/* product tour */
.tour{background:var(--dark);color:#e9dcc1}
.tour .sh h2{color:#fff7e6}.tour .sh p{color:#b8ab90}
.tabs{display:flex;justify-content:center;gap:8px;margin:0 0 26px;flex-wrap:wrap}
.tabs button{background:rgba(232,201,119,.06);border:1px solid rgba(232,201,119,.25);color:#e0d3b8;padding:10px 18px;border-radius:99px;font:600 14px Inter,"Segoe UI",sans-serif;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.tabs button.on{background:var(--g);color:#14100a;border-color:transparent}
.pane{display:none;grid-template-columns:1fr 1.25fr;gap:40px;align-items:center}.pane.on{display:grid}
.pane h3{font-size:26px;color:#fff7e6;margin:0 0 10px;letter-spacing:-.4px}.pane p{color:#b8ab90;margin:0 0 18px}
.pane ul{list-style:none;padding:0;margin:0;display:grid;gap:10px}.pane li{display:flex;gap:10px;color:#e0d3b8}.pane li i{color:#d6ae55;margin-top:4px}
.mock{border-radius:16px;background:#faf6ee;overflow:hidden;box-shadow:0 30px 70px -30px rgba(0,0,0,.9),0 0 0 1px rgba(232,201,119,.25)}
.mock .body{padding:16px;display:grid;gap:10px;color:var(--ink)}
.mrow{display:grid;grid-template-columns:28px 1.4fr 1fr 1fr .7fr;gap:10px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:11.5px}
.mrow .av{width:26px;height:26px;border-radius:50%;background:#14100a;color:#e8c977;display:grid;place-items:center;font-size:9.5px;font-weight:800}
.mrow b{font-size:12px}.mrow small{color:var(--mute)}.mrow .tag{justify-self:end;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;background:#fbf1d8;color:#8a6420}
.mhead{display:grid;grid-template-columns:28px 1.4fr 1fr 1fr .7fr;gap:10px;font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;padding:0 10px;color:#9c8f78;font-weight:700}
.lic{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.lc{background:#fff;border:1px solid var(--line);border-top:3px solid var(--gold);border-radius:10px;padding:12px;font-size:11.5px}
.lc b{font-size:13px;display:block;margin-bottom:8px}.lc .lb{height:6px;border-radius:9px;background:#f3ece0;margin:4px 0 10px;overflow:hidden}.lc .lb i{display:block;height:100%;background:var(--g)}.lc .lb.n i{background:var(--navy)}
.lc .r{display:flex;justify-content:space-between;color:var(--mute)}.lc .r em{font-style:normal;color:var(--navy);font-weight:700}

/* features */
.feat{background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.fgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:34px 40px}
.f{display:grid;grid-template-columns:46px 1fr;gap:16px}
.f .ic{width:46px;height:46px;border-radius:12px;background:var(--g);color:#14100a;display:grid;place-items:center;font-size:17px;box-shadow:0 10px 22px -12px rgba(176,132,51,.9)}
.f h3{margin:2px 0 6px;font-size:16.5px;color:var(--navy)}.f p{margin:0;color:var(--mute);font-size:14.5px}

/* attendance */
.att{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.ac{background:#fff;border:1px solid var(--line);border-radius:16px;padding:26px;text-align:center}
.ac .big{width:64px;height:64px;border-radius:18px;margin:0 auto 14px;display:grid;place-items:center;font-size:26px;background:#14100a;color:#e8c977}
.ac h3{margin:0 0 6px;color:var(--navy);font-size:17px}.ac p{margin:0;color:var(--mute);font-size:14.5px}
.flow{display:flex;align-items:center;justify-content:center;gap:14px;margin-top:28px;flex-wrap:wrap;font-size:13.5px;color:var(--mute)}
.flow span{background:#fff;border:1px solid var(--line);border-radius:99px;padding:8px 16px;font-weight:600;color:var(--ink)}.flow span.g{background:#14100a;color:#f3dc95;border-color:#14100a}.flow i{color:#c9a24c}

/* security */
.sec{display:grid;grid-template-columns:1fr 1.2fr;gap:50px;align-items:center}
.sec .sh{text-align:left;margin:0}
.sgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.sg{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px}
.sg i{color:#b08433;font-size:18px}.sg h4{margin:10px 0 4px;color:var(--navy);font-size:15px}.sg p{margin:0;color:var(--mute);font-size:13.5px}

/* steps + deploy */
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;counter-reset:s}
.step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px 24px;position:relative}
.step:before{counter-increment:s;content:counter(s);position:absolute;top:-18px;left:24px;width:38px;height:38px;border-radius:50%;background:#14100a;color:#e8c977;display:grid;place-items:center;font-weight:800;box-shadow:0 0 0 5px var(--ivory)}
.step h3{margin:10px 0 6px;color:var(--navy);font-size:17px}.step p{margin:0;color:var(--mute);font-size:14.5px}
.deploy{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:46px}
.dp{border-radius:16px;padding:26px;background:var(--dark);color:#e9dcc1;border:1px solid rgba(232,201,119,.2)}
.dp h3{margin:0 0 8px;color:#f3dc95;font-size:18px}.dp h3 i{margin-right:8px}.dp p{margin:0 0 12px;color:#b8ab90;font-size:14.5px}
.dp ul{margin:0;padding-left:18px;color:#d8cbb2;font-size:14px;display:grid;gap:4px}

/* pricing */
.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;align-items:stretch}
.pl{background:#fff;border:1px solid var(--line);border-radius:18px;padding:30px 26px;display:flex;flex-direction:column;position:relative}
.pl.best{background:var(--dark);color:#e9dcc1;border-color:rgba(232,201,119,.35);box-shadow:0 30px 60px -30px rgba(0,0,0,.7);transform:translateY(-8px)}
.pl .tag{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--g);color:#14100a;font-size:11.5px;font-weight:800;padding:4px 14px;border-radius:99px;text-transform:uppercase;letter-spacing:.6px}
.pl h3{margin:0;font-size:20px;color:var(--navy)}.pl.best h3{color:#f3dc95}
.pl .pr{font-size:30px;font-weight:800;letter-spacing:-.6px;margin:14px 0 2px;color:var(--ink)}.pl.best .pr{color:#fff7e6}
.pl .pr small{font-size:14px;font-weight:600;color:var(--mute)}.pl.best .pr small{color:#b8ab90}
.pl>p{color:var(--mute);font-size:14px;margin:0 0 18px}.pl.best>p{color:#b8ab90}
.pl ul{list-style:none;margin:0 0 24px;padding:0;display:grid;gap:10px;font-size:14.5px;flex:1}.pl li i{color:#c9a24c;margin-right:9px}
.pnote{text-align:center;color:var(--mute);font-size:13.5px;margin-top:22px}

/* about */
.about{display:grid;grid-template-columns:1.1fr 1fr;gap:50px;align-items:center}
.about .sh{text-align:left;margin:0}
.about p{color:var(--mute);font-size:15.5px}
.vals{display:grid;gap:14px}
.vl{display:grid;grid-template-columns:44px 1fr;gap:14px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
.vl i{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#14100a;color:#e8c977}
.vl b{color:var(--navy)}.vl span{display:block;color:var(--mute);font-size:14px}

/* faq */
.faq{max-width:860px;margin:0 auto;display:grid;gap:10px}
.faq details{background:#fff;border:1px solid var(--line);border-radius:14px;padding:0 20px;transition:box-shadow .2s}
.faq details[open]{box-shadow:0 16px 34px -24px rgba(60,40,10,.5);border-color:#e2cfa6}
.faq summary{list-style:none;cursor:pointer;padding:18px 0;font-weight:700;color:var(--navy);display:flex;justify-content:space-between;gap:16px;font-size:15.5px}
.faq summary::-webkit-details-marker{display:none}
.faq summary:after{content:"\f067";font-family:"Font Awesome 6 Free";font-weight:900;color:#b08433;transition:transform .2s}
.faq details[open] summary:after{transform:rotate(45deg)}
.faq p{margin:0 0 18px;color:var(--mute);font-size:15px}

/* contact + form */
.contact{background:var(--dark);color:#e9dcc1}
.cgrid{display:grid;grid-template-columns:1fr 1.15fr;gap:50px;align-items:start}
.contact h2{font-size:36px;letter-spacing:-.8px;line-height:1.15;margin:10px 0 12px;color:#fff7e6}
.contact .lead{color:#b8ab90;font-size:16px;margin:0 0 26px}
.ci{display:grid;gap:14px}
.ci>div{display:grid;grid-template-columns:42px 1fr;gap:12px;align-items:center}
.ci i{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:rgba(232,201,119,.1);color:#e8c977;border:1px solid rgba(232,201,119,.25)}
.ci b{color:#f3dc95;display:block;font-size:13px}.ci span{color:#d8cbb2;font-size:14.5px}
.form{background:#fffdf8;color:var(--ink);border-radius:20px;padding:30px;box-shadow:0 30px 70px -30px rgba(0,0,0,.9);border-top:4px solid var(--gold)}
.form h3{margin:0 0 4px;color:var(--navy);font-size:21px}.form>p{margin:0 0 18px;color:var(--mute);font-size:14px}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fi{display:grid;gap:6px}.fi.w{grid-column:1/-1}
.fi label{font-size:12.5px;font-weight:700;color:#4a3f2e}.fi label em{color:#8b1e2d;font-style:normal}
.fi input,.fi select,.fi textarea{font:inherit;font-size:14.5px;padding:11px 13px;border:1px solid #e2d6bd;border-radius:10px;background:#fff;color:var(--ink);width:100%}
.fi input:focus,.fi select:focus,.fi textarea:focus{outline:0;border-color:#c9a24c;box-shadow:0 0 0 4px rgba(201,162,76,.18)}
.fi .er{color:#8b1e2d;font-size:12.5px;font-weight:600}.fi.bad input{border-color:#c46b77}
.chips{display:flex;flex-wrap:wrap;gap:8px}
.chips label{white-space:nowrap;display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border:1px solid #e2d6bd;border-radius:99px;font-weight:600;font-size:13px;color:#3d3426;cursor:pointer;background:#fff}
.chips input{accent-color:#b08433}
.chips label:has(input:checked){background:#14100a;color:#f3dc95;border-color:#14100a}
.hp{position:absolute;left:-9999px}
.form .btn{width:100%;margin-top:18px;padding:14px}
.ok{background:#ecfdf3;border:1px solid #b7ebc8;color:#166534;border-radius:14px;padding:18px;display:flex;gap:12px;align-items:flex-start}
.ok i{font-size:20px;margin-top:2px}
.small{font-size:12px;color:var(--mute);margin-top:10px;text-align:center}

/* footer */
footer{background:#0a0704;color:#a89b82;padding:60px 0 26px;font-size:14px}
.fcols{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:30px}
footer h4{color:#f3dc95;font-size:12.5px;text-transform:uppercase;letter-spacing:1.2px;margin:0 0 14px}
footer ul{list-style:none;margin:0;padding:0;display:grid;gap:9px}footer a:hover{color:#f3dc95}
footer p{margin:14px 0 0;max-width:320px}
.fbot{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-top:1px solid rgba(232,201,119,.12);margin-top:40px;padding-top:20px;font-size:13px}
.top{position:fixed;right:22px;bottom:22px;width:44px;height:44px;border-radius:12px;background:var(--g);color:#14100a;display:grid;place-items:center;box-shadow:0 12px 26px -12px rgba(176,132,51,.9);opacity:0;pointer-events:none;transition:opacity .2s;z-index:40}
.top.show{opacity:1;pointer-events:auto}

@media (max-width:1020px){
  .hero .wrap,.pane,.sec,.about,.cgrid{grid-template-columns:1fr}.shot{transform:none}.hero h1{font-size:42px}
  .apps,.fgrid,.steps,.att,.plans{grid-template-columns:1fr 1fr}.pl.best{transform:none}
  .nums .row{grid-template-columns:1fr 1fr}.nums .row>div:nth-child(2){border-right:0}.nums .row>div:nth-child(-n+2){border-bottom:1px solid var(--line)}
  .nav nav,.nav .acts{display:none}.burger{display:grid;place-items:center}
  .mnav.open{display:grid;gap:4px;padding:10px 28px 18px;background:#0f0b06;border-bottom:1px solid rgba(232,201,119,.14)}
  .mnav a{color:#e0d3b8;padding:10px 0;border-bottom:1px solid rgba(232,201,119,.08)}.mnav .btn{margin-top:10px;color:#14100a}
  .fcols{grid-template-columns:1fr 1fr}.float{display:none}
}
@media (max-width:640px){
  .apps,.fgrid,.steps,.deploy,.att,.plans,.ba,.sgrid,.fg,.lic{grid-template-columns:1fr}.hero h1{font-size:34px}section{padding:66px 0}
  .sh h2,.contact h2{font-size:28px}.nums .row{grid-template-columns:1fr}.nums .row>div{border-right:0;border-bottom:1px solid var(--line)}.fcols{grid-template-columns:1fr}
  .mrow,.mhead{grid-template-columns:28px 1fr .8fr}.mrow>:nth-child(4),.mrow>:nth-child(5),.mhead>:nth-child(4),.mhead>:nth-child(5){display:none}
}

/* ---------- PREMIUM LAYER (owner, 19 Nov 2026: "make more premium and use real screenshots") ---------- */
:root{--serif:Fraunces,"Playfair Display",Georgia,serif}
.hero h1,.sh h2,.contact h2,.pane h3,.pl .pr,.nums b,.cta2 h2,.thm h3{font-family:var(--serif);font-weight:600;letter-spacing:-.5px}
.hero h1{font-size:64px;line-height:1.04}
.sh h2{font-size:42px}
body:before{content:"";position:fixed;inset:0;pointer-events:none;z-index:100;opacity:.035;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}
.k{display:inline-flex;align-items:center;gap:10px}.k:before,.k:after{content:"";width:22px;height:1px;background:linear-gradient(90deg,transparent,#c9a24c)}.k:after{transform:scaleX(-1)}
/* centred hero with the real product below it */
.hero.p2{padding:86px 0 0;text-align:center}
.hero.p2 .wrap{display:block}
.hero.p2 p.lead{margin:0 auto 32px;max-width:680px;font-size:19px}
.hero.p2 .cta,.hero.p2 .trust{justify-content:center}
.hero.p2 .eyebrow{background:rgba(232,201,119,.06)}
.stage{position:relative;max-width:1120px;margin:64px auto 0;padding:0 28px}
.stage:before{content:"";position:absolute;left:8%;right:8%;top:-30px;height:70%;background:radial-gradient(closest-side,rgba(214,174,85,.45),transparent);filter:blur(30px);z-index:0}
.frame{position:relative;z-index:1;border-radius:16px 16px 0 0;overflow:hidden;background:#14100a;box-shadow:0 -1px 0 rgba(232,201,119,.35),0 0 0 1px rgba(232,201,119,.22),0 50px 120px -30px rgba(0,0,0,.9)}
.frame .bar3{height:34px;background:linear-gradient(180deg,#1d170e,#14100a);border-bottom:1px solid rgba(232,201,119,.15)}
.frame .bar3 .url{margin:0 auto;padding:4px 18px;border-radius:8px;background:rgba(232,201,119,.08);color:#b8ab90;font-size:11px;display:flex;align-items:center;gap:6px}
.frame img{display:block;width:100%;height:auto}
.chip{position:absolute;z-index:2;background:rgba(255,253,248,.96);backdrop-filter:blur(6px);border:1px solid #ecdcb6;border-radius:14px;padding:12px 16px;box-shadow:0 24px 50px -20px rgba(0,0,0,.6);display:flex;gap:12px;align-items:center;text-align:left;font-size:12.5px;color:#4a3f2e;animation:bob 6s ease-in-out infinite}
.chip i{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#14100a;color:#e8c977;font-size:14px}
.chip b{display:block;font-size:14px;color:#1f3a5f}
.chip.c1{left:-6px;top:120px}.chip.c2{right:-6px;top:60px;animation-delay:-2s}.chip.c3{right:40px;bottom:40px;animation-delay:-4s}
@keyframes bob{50%{transform:translateY(-8px)}}
.hero.p2+.wrap.nums{margin-top:34px}
/* real screenshot tour */
.shotf{border-radius:14px;overflow:hidden;background:#14100a;box-shadow:0 0 0 1px rgba(232,201,119,.28),0 40px 90px -35px rgba(0,0,0,.95)}
.shotf .bar3{height:28px}.shotf img{display:block;width:100%;height:auto}.frame img,.gal img{height:auto}
.pane{grid-template-columns:.85fr 1.35fr}
.pane .cap{display:inline-flex;gap:8px;align-items:center;font-size:12px;color:#d6ae55;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px}
/* themes */
.thm{display:grid;grid-template-columns:1fr 1.6fr;gap:50px;align-items:center}
.thm .sh{text-align:left;margin:0}
.tsw{display:flex;gap:12px;margin-top:22px;flex-wrap:wrap}
.tsw button{display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:12px;border:1px solid var(--line);background:#fff;font:600 14px Inter,sans-serif;cursor:pointer;color:var(--ink)}
.tsw button i{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:10px}
.tsw button.on{border-color:#c9a24c;box-shadow:0 0 0 3px rgba(201,162,76,.2)}
.tview{position:relative;aspect-ratio:1366/585}
.tview img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .45s}.tview img.on{opacity:1}
/* gallery strip */
.gal{display:grid;grid-template-columns:1.3fr 1fr;gap:20px}
.gal .shotf:first-child{grid-row:span 2}
.gal figure{margin:0}.gal figcaption{font-size:13px;color:var(--mute);margin-top:10px;display:flex;gap:8px;align-items:center}.gal figcaption i{color:#b08433}
/* finer cards */
.app,.f,.ac,.sg,.pl,.step,.vl,.bx{transition:transform .25s,box-shadow .25s,border-color .25s}
.app:hover,.ac:hover,.sg:hover,.step:hover{border-color:#e2cfa6}
.pl.best{background:radial-gradient(400px 200px at 50% 0%,rgba(214,174,85,.25),transparent 70%),var(--dark)}
@media (max-width:1020px){.hero h1{font-size:46px}.thm,.gal{grid-template-columns:1fr}.gal .shotf:first-child{grid-row:auto}.chip{display:none}}
@media (max-width:640px){.hero h1{font-size:36px}.sh h2{font-size:30px}}
</style></head><body>

<header class="nav"><div class="wrap">
  <a class="logo" href="#top"><span class="lm">AG</span><span>Ametecs<b>Global</b></span></a>
  <nav id="mainnav"><a href="#apps">Applications</a><a href="#tour">Product tour</a><a href="#themes">Themes</a><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a><a href="#contact">Contact</a></nav>
  <div class="acts"><a class="btn line" href="#contact">Book a demo</a><a class="btn gold" href="core/"><i class="fas fa-right-to-bracket"></i>Sign in</a></div>
  <button class="burger" type="button" aria-label="Open menu" aria-expanded="false" data-burger><i class="fas fa-bars"></i></button>
</div>
<div class="mnav" id="mnav"><a href="#apps">Applications</a><a href="#tour">Product tour</a><a href="#themes">Themes</a><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a><a href="#contact">Contact</a><a class="btn gold" href="core/"><i class="fas fa-right-to-bracket"></i>Sign in</a></div>
</header>

<main id="top">
<div class="hero p2"><div class="wrap">
  <span class="eyebrow"><i class="fas fa-crown"></i>By Ametecs India · Hyderabad</span>
  <h1>Every Ametecs application,<br><em>one platform.</em></h1>
  <p class="lead">HR and payroll, productivity, collections, leads, field force and QR attendance on one foundation. One organisation, one login per employee, one place for licences and updates.</p>
  <div class="cta"><a class="btn gold" href="#contact"><i class="fas fa-calendar-check"></i>Book a free demo</a><a class="btn line" href="#tour"><i class="fas fa-play"></i>Take the tour</a></div>
  <div class="trust"><span><i class="fas fa-cloud"></i>Cloud or on-premise</span><span><i class="fas fa-shield-halved"></i>Separate workspace per company</span><span><i class="fas fa-indian-rupee-sign"></i>Pay per user</span></div>
</div>
  <div class="stage">
    <div class="frame"><div class="bar3"><i></i><i></i><i></i><span class="url"><i class="fas fa-lock" style="width:auto;height:auto;background:none;font-size:9px;color:#d6ae55"></i>your-company.ametecsglobal</span></div>
      <img src="assets/landing/dashboard.jpg" width="1366" height="585" alt="The AmetecsGlobal dashboard: employees, attendance, absences and late arrivals across 20 companies" fetchpriority="high"></div>
    <div class="chip c1" aria-hidden="true"><i class="fas fa-fingerprint"></i><div><b>Punch received</b>Biometric · Kondapur gate</div></div>
    <div class="chip c2" aria-hidden="true"><i class="fas fa-user-plus"></i><div><b>Login created</b>New employee, every app</div></div>
    <div class="chip c3" aria-hidden="true"><i class="fas fa-file-invoice"></i><div><b>Invoice paid</b>Seats added instantly</div></div>
  </div>
</div>

<div class="wrap nums reveal"><div class="row">
  <div><b><em>6</em></b><span>applications on one platform</span></div>
  <div><b>1</b><span>organisation shared by every app</span></div>
  <div><b>3</b><span>ways to punch: biometric, QR and mobile</span></div>
  <div><b>2</b><span>ways to run: cloud or your own server</span></div>
</div></div>

<section style="padding-top:70px;padding-bottom:40px"><div class="wrap">
  <div class="strip" style="background:none;border:0;padding:0;color:var(--mute)"><div class="wrap" style="padding:0">
    <b style="color:#b08433">Built for</b>
    <span style="border-color:var(--line);background:#fff"><i class="fas fa-landmark"></i>Banks and NBFCs</span>
    <span style="border-color:var(--line);background:#fff"><i class="fas fa-hand-holding-dollar"></i>Collection agencies</span>
    <span style="border-color:var(--line);background:#fff"><i class="fas fa-seedling"></i>Microfinance</span>
    <span style="border-color:var(--line);background:#fff"><i class="fas fa-headset"></i>BPO and telecalling</span>
    <span style="border-color:var(--line);background:#fff"><i class="fas fa-truck-fast"></i>Field services</span>
    <span style="border-color:var(--line);background:#fff"><i class="fas fa-building"></i>Growing businesses</span>
  </div></div>
</div></section>

<section style="padding-top:40px"><div class="wrap">
  <div class="sh reveal"><span class="k">The problem</span><h2>Many apps, the same employee five times</h2><p>Each tool keeps its own staff list, its own logins and its own renewal date. AmetecsGlobal puts them on one foundation.</p></div>
  <div class="ba reveal">
    <div class="bx no"><h3><i class="fas fa-circle-xmark"></i>Without one platform</h3><ul>
      <li><i class="fas fa-xmark"></i>Employees typed again in every application</li>
      <li><i class="fas fa-xmark"></i>A separate login and password for each tool</li>
      <li><i class="fas fa-xmark"></i>Attendance machines connected app by app</li>
      <li><i class="fas fa-xmark"></i>Renewals and invoices scattered across vendors</li>
      <li><i class="fas fa-xmark"></i>No single view of the whole workforce</li></ul></div>
    <div class="bx yes"><h3><i class="fas fa-circle-check"></i>With AmetecsGlobal</h3><ul>
      <li><i class="fas fa-check"></i>Your organisation set up once, read by every app</li>
      <li><i class="fas fa-check"></i>One login per employee, apps switched on as needed</li>
      <li><i class="fas fa-check"></i>Every punch reaches every application</li>
      <li><i class="fas fa-check"></i>Licences, seats, invoices and updates in one place</li>
      <li><i class="fas fa-check"></i>One live dashboard across all your apps</li></ul></div>
  </div>
</div></section>

<section id="apps" style="padding-top:20px"><div class="wrap">
  <div class="sh reveal"><span class="k">The applications</span><h2>Switch on what your business needs</h2><p>Each application is a plugin on the same platform. Turn one on for a company, a branch or a team, and it works with everything you already set up.</p></div>
  <div class="apps"><?php foreach ($apps as [$n, $what, $ic, $st, $pts]): ?>
    <div class="app reveal"><span class="st<?= $st === 'Live' ? ' live' : '' ?>"><?= $h($st) ?></span><div class="ic"><i class="fas <?= $ic ?>"></i></div><h3><?= $h($n) ?></h3><div class="what"><?= $h($what) ?></div>
      <ul><?php foreach ($pts as $p): ?><li><i class="fas fa-check"></i><?= $h($p) ?></li><?php endforeach; ?></ul></div>
  <?php endforeach; ?></div>
</div></section>

<section id="tour" class="tour"><div class="wrap">
  <div class="sh reveal"><span class="k">Product tour</span><h2>See how it works</h2><p>The same calm, black-and-gold workspace for your administrators, your managers and Ametecs.</p></div>
  <div class="tabs" role="tablist"><button class="on" type="button" data-tab="t1"><i class="fas fa-gauge-high"></i>Dashboard</button><button type="button" data-tab="t2"><i class="fas fa-hand-pointer"></i>People</button><button type="button" data-tab="t3"><i class="fas fa-users"></i>Directory</button><button type="button" data-tab="t4"><i class="fas fa-key"></i>Licences</button></div>
  <div class="pane on" id="t1"><div><span class="cap"><i class="fas fa-gauge-high"></i>Dashboard</span><h3>Your whole company at a glance</h3><p>One dashboard for every application, filtered by date, company, branch, department, team or person.</p>
      <ul><li><i class="fas fa-check"></i>Present, absent, on leave, late and early, live</li><li><i class="fas fa-check"></i>Attendance ring, seven-day trend and company comparison</li><li><i class="fas fa-check"></i>Birthdays, anniversaries, probation and expiring documents</li><li><i class="fas fa-check"></i>Refreshes itself every minute</li></ul></div>
    <div class="shotf"><div class="bar3"><i></i><i></i><i></i><span>Dashboard · charts</span></div><img loading="lazy" src="assets/landing/charts.jpg" width="1366" height="490" alt="Attendance ring, last seven days and by-company attendance charts"></div></div>
  <div class="pane" id="t2"><div><span class="cap"><i class="fas fa-hand-pointer"></i>Click any number</span><h3>The people behind every figure</h3><p>Open any tile to see exactly who is present, absent or late, then open a person for their full record.</p>
      <ul><li><i class="fas fa-check"></i>Search across name, code, company and department</li><li><i class="fas fa-check"></i>Full details with this period's attendance</li><li><i class="fas fa-check"></i>Call or email in one click</li><li><i class="fas fa-check"></i>Download the list as CSV</li></ul></div>
    <div class="shotf"><div class="bar3"><i></i><i></i><i></i><span>Employees · details</span></div><img loading="lazy" src="assets/landing/people.jpg" width="1366" height="585" alt="Employee list pop-up with a detail card showing attendance and contact details"></div></div>
  <div class="pane" id="t3"><div><span class="cap"><i class="fas fa-users"></i>Organisation</span><h3>One employee directory</h3><p>Add, import and edit employees once. Every application reads the same record.</p>
      <ul><li><i class="fas fa-check"></i>Totals by company, branch and department</li><li><i class="fas fa-check"></i>Field and format checks on every save</li><li><i class="fas fa-check"></i>Logins created the moment an employee is added</li><li><i class="fas fa-check"></i>Export and bulk import</li></ul></div>
    <div class="shotf"><div class="bar3"><i></i><i></i><i></i><span>Employee directory</span></div><img loading="lazy" src="assets/landing/directory.jpg" width="1366" height="470" alt="Employee directory with totals, company filters and the employee table"></div></div>
  <div class="pane" id="t4"><div><span class="cap"><i class="fas fa-key"></i>Licences</span><h3>Licences you can see and change</h3><p>Seats, validity and plan for every application, with online payment.</p>
      <ul><li><i class="fas fa-check"></i>Seats used against seats bought</li><li><i class="fas fa-check"></i>Add users and pay only for the days left</li><li><i class="fas fa-check"></i>Change plan with unused time credited</li><li><i class="fas fa-check"></i>Tax invoices ready to print or save as PDF</li></ul></div>
    <div class="shotf"><div class="bar3"><i></i><i></i><i></i><span>Licences and plans</span></div><img loading="lazy" src="assets/landing/licences.jpg" width="1366" height="585" alt="Licences and plans with seats and validity for six applications"></div></div>
</div></section>

<section id="themes"><div class="wrap thm">
  <div class="sh reveal"><span class="k">Make it yours</span><h2>Four premium themes</h2><p>Every person picks the look they like from their own menu: Golden, Navy, Light or Dark. The choice follows them across the whole panel.</p>
    <div class="tsw" role="tablist">
      <button class="on" type="button" data-th="dashboard"><i style="background:linear-gradient(135deg,#f3dc95,#b08433);color:#14100a" class="fas fa-crown"></i>Golden</button>
      <button type="button" data-th="navy"><i style="background:#0f2342;color:#e8c977" class="fas fa-gem"></i>Navy</button>
      <button type="button" data-th="light"><i style="background:#fff;color:#d6ae55;box-shadow:inset 0 0 0 1px #ddd" class="fas fa-sun"></i>Light</button>
      <button type="button" data-th="dark"><i style="background:#0d0b08;color:#e8c977" class="fas fa-moon"></i>Dark</button></div></div>
  <div class="shotf reveal"><div class="bar3"><i></i><i></i><i></i><span>Dashboard</span></div><div class="tview">
    <img class="on" data-tv="dashboard" src="assets/landing/dashboard.jpg" alt="Golden theme" loading="lazy"><img data-tv="navy" src="assets/landing/navy.jpg" alt="Navy theme" loading="lazy"><img data-tv="light" src="assets/landing/light.jpg" alt="Light theme" loading="lazy"><img data-tv="dark" src="assets/landing/dark.jpg" alt="Dark theme" loading="lazy"></div></div>
</div></section>

<section style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)"><div class="wrap">
  <div class="sh reveal"><span class="k">Run by Ametecs</span><h2>A command centre behind every client</h2><p>Ametecs manages clients, licences, releases and billing from one Super Admin console, so your renewals, updates and invoices are always in order.</p></div>
  <div class="gal">
    <figure class="shotf reveal" style="background:#14100a"><div class="bar3"><i></i><i></i><i></i><span>Super Admin · dashboard</span></div><img loading="lazy" src="assets/landing/super.jpg" width="1366" height="585" alt="Super Admin dashboard with revenue, renewals and client overview"></figure>
    <figure class="reveal" style="margin:0;display:grid;gap:14px;align-content:start">
      <div class="sg"><i class="fas fa-calendar-check"></i><h4>Renewals never missed</h4><p>Every licence ending in the next 60 days, with one-click renew and invoice.</p></div>
      <div class="sg"><i class="fas fa-cloud-arrow-up"></i><h4>Releases to chosen clients</h4><p>Publish an update, pick who gets it, schedule it and roll back if needed.</p></div>
      <div class="sg"><i class="fas fa-file-invoice-dollar"></i><h4>Billing in one place</h4><p>Invoices, online payments, GST and receipts for every client.</p></div>
    </figure>
  </div>
</div></section>

<section id="features" class="feat"><div class="wrap">
  <div class="sh reveal"><span class="k">Why one platform</span><h2>Set it up once. Every app benefits.</h2><p>No more keeping the same employee in five places. AmetecsGlobal is the single home for your organisation.</p></div>
  <div class="fgrid"><?php foreach ($features as [$ic, $t, $p]): ?>
    <div class="f reveal"><div class="ic"><i class="fas <?= $ic ?>"></i></div><div><h3><?= $h($t) ?></h3><p><?= $h($p) ?></p></div></div>
  <?php endforeach; ?></div>
</div></section>

<section id="attendance"><div class="wrap">
  <div class="sh reveal"><span class="k">Attendance</span><h2>Every punch, everywhere it is needed</h2><p>Connect your devices once in AmetecsGlobal. Attendance flows into payroll, productivity, collections and every other app.</p></div>
  <div class="att">
    <div class="ac reveal"><div class="big"><i class="fas fa-fingerprint"></i></div><h3>Biometric machines</h3><p>Connect your existing machines, map device IDs to employees and sync punches automatically.</p></div>
    <div class="ac reveal"><div class="big"><i class="fas fa-qrcode"></i></div><h3>QR at gates and offices</h3><p>Printed QR codes per office or gate, with geo-fencing so punches count only on site.</p></div>
    <div class="ac reveal"><div class="big"><i class="fas fa-mobile-screen"></i></div><h3>Mobile app</h3><p>Employees punch from approved phones only. Approve a device once, revoke a lost one instantly.</p></div>
  </div>
  <div class="flow reveal"><span>Device punch</span><i class="fas fa-arrow-right"></i><span class="g">AmetecsGlobal</span><i class="fas fa-arrow-right"></i><span>Payroll</span><span>Productivity</span><span>Collections</span><span>Reports</span></div>
</div></section>

<section style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)"><div class="wrap sec">
  <div class="sh reveal"><span class="k">Security and control</span><h2>Your company, your rules</h2><p>Each client works in its own workspace. Administrators decide who sees what, and every change is recorded.</p>
    <div class="cta" style="margin-top:24px"><a class="btn dark" href="#contact"><i class="fas fa-shield-halved"></i>Ask about security</a></div></div>
  <div class="sgrid">
    <div class="sg reveal"><i class="fas fa-building-lock"></i><h4>Separate workspaces</h4><p>Every client has its own sign-in link and sees only its own data.</p></div>
    <div class="sg reveal"><i class="fas fa-user-lock"></i><h4>Roles and permissions</h4><p>Give each person only the screens and actions they need.</p></div>
    <div class="sg reveal"><i class="fas fa-mobile-retro"></i><h4>Approved devices</h4><p>Phones must be approved before they can sign in or punch.</p></div>
    <div class="sg reveal"><i class="fas fa-clock-rotate-left"></i><h4>Full activity history</h4><p>Who changed what and when, searchable and exportable.</p></div>
    <div class="sg reveal"><i class="fas fa-file-signature"></i><h4>Signed updates</h4><p>On-premise updates are signed, backed up before install and can be rolled back.</p></div>
    <div class="sg reveal"><i class="fas fa-lock"></i><h4>Verified payments</h4><p>Online payments are verified on the server before anything changes.</p></div>
  </div>
</div></section>

<section id="how"><div class="wrap">
  <div class="sh reveal"><span class="k">How it works</span><h2>Live in three steps</h2></div>
  <div class="steps">
    <div class="step reveal"><h3>We set up your company</h3><p>Ametecs creates your workspace and your sign-in link, and switches on the applications in your plan.</p></div>
    <div class="step reveal"><h3>You add your organisation</h3><p>Add companies, branches, departments and employees, or import them in one go. Logins are created automatically.</p></div>
    <div class="step reveal"><h3>Your teams start working</h3><p>Employees sign in and use the apps they are switched on for. You watch everything from one live dashboard.</p></div>
  </div>
  <div class="deploy">
    <div class="dp reveal"><h3><i class="fas fa-cloud"></i>Cloud</h3><p>Hosted and updated by Ametecs. Nothing to install.</p><ul><li>Open your sign-in link and start</li><li>Updates applied for you</li><li>Pay monthly or yearly</li></ul></div>
    <div class="dp reveal"><h3><i class="fas fa-server"></i>On-premise</h3><p>Runs on your own server with a licence key.</p><ul><li>Your data stays on your server</li><li>Signed updates you install when ready</li><li>Backup and roll-back built in</li></ul></div>
  </div>
</div></section>

<section id="pricing" style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)"><div class="wrap">
  <div class="sh reveal"><span class="k">Pricing</span><h2>Pay per user, for the apps you use</h2><p>Every application is priced per user. Start with one, add more when you are ready.</p></div>
  <div class="plans">
    <div class="pl reveal"><h3>Monthly</h3><div class="pr">Per user <small>/ month</small></div><p>For teams that want to start small and stay flexible.</p>
      <ul><li><i class="fas fa-check"></i>Any application, any number of users</li><li><i class="fas fa-check"></i>Add users any time</li><li><i class="fas fa-check"></i>Cloud hosting and updates</li><li><i class="fas fa-check"></i>Email and phone support</li></ul><a class="btn dark" href="#contact">Get a quote</a></div>
    <div class="pl best reveal"><span class="tag">Best value</span><h3>Yearly</h3><div class="pr">Per user <small>/ year</small></div><p>The same platform, billed once a year at a lower rate.</p>
      <ul><li><i class="fas fa-check"></i>Everything in Monthly</li><li><i class="fas fa-check"></i>Lower price per user</li><li><i class="fas fa-check"></i>Change plan with unused time credited</li><li><i class="fas fa-check"></i>Onboarding help from Ametecs</li></ul><a class="btn gold" href="#contact">Get a quote</a></div>
    <div class="pl reveal"><h3>On-premise</h3><div class="pr">Licence <small>/ server</small></div><p>For organisations that keep everything on their own server.</p>
      <ul><li><i class="fas fa-check"></i>Licence key for your server</li><li><i class="fas fa-check"></i>Signed updates and roll-back</li><li><i class="fas fa-check"></i>Installation support</li><li><i class="fas fa-check"></i>Seats as your team grows</li></ul><a class="btn dark" href="#contact">Talk to us</a></div>
  </div>
  <p class="pnote">Prices depend on the applications and number of users. Prices exclude GST. Request a demo for a quote for your company.</p>
</div></section>

<section id="about"><div class="wrap about">
  <div class="sh reveal"><span class="k">About Ametecs</span><h2>Built in Hyderabad, from years in the field</h2>
    <p>Ametecs India Private Limited is a product technology company based in Kondapur, Hyderabad. Our software grew out of years of working inside debt collection and operations teams, where we saw how many tools one company needs and how much time is lost keeping them in step.</p>
    <p>AmetecsGlobal is our answer: one platform where every Ametecs application shares the same organisation, logins, licences and updates, so our clients can focus on their work.</p></div>
  <div class="vals">
    <div class="vl reveal"><i class="fas fa-eye"></i><div><b>Observation first</b><span>We study the work from the user's side before we build.</span></div></div>
    <div class="vl reveal"><i class="fas fa-handshake"></i><div><b>Close to customers</b><span>Our team helps you set up and stays with you after go-live.</span></div></div>
    <div class="vl reveal"><i class="fas fa-arrows-rotate"></i><div><b>Always improving</b><span>Regular updates for every application, delivered from one hub.</span></div></div>
  </div>
</div></section>

<section id="faq" style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)"><div class="wrap">
  <div class="sh reveal"><span class="k">Questions</span><h2>Frequently asked questions</h2></div>
  <div class="faq"><?php foreach ($faq as $i => [$q, $a]): ?><details class="reveal"<?= $i === 0 ? ' open' : '' ?>><summary><?= $h($q) ?></summary><p><?= $h($a) ?></p></details><?php endforeach; ?></div>
</div></section>

<section id="contact" class="contact"><div class="wrap cgrid">
  <div class="reveal"><span class="k" style="color:#d6ae55">Contact</span><h2>See AmetecsGlobal with your own data</h2>
    <p class="lead">Tell us a little about your company and the applications you are interested in. The Ametecs team will set up a walkthrough for you.</p>
    <div class="ci">
      <div><i class="fas fa-location-dot"></i><div><b>Office</b><span>Ametecs India Private Limited, Kondapur, Hyderabad, Telangana</span></div></div>
      <div><i class="fas fa-envelope"></i><div><b>Email</b><span><a href="mailto:info@ametecsindia.com">info@ametecsindia.com</a></span></div></div>
      <div><i class="fas fa-right-to-bracket"></i><div><b>Already a client?</b><span><a href="core/">Sign in to your company</a></span></div></div>
    </div></div>
  <div class="form reveal" id="demo">
    <?php if ($sent): ?>
      <div class="ok"><i class="fas fa-circle-check"></i><div><b>Thank you, <?= $h($v('name')) ?>.</b><br>Your request has reached Ametecs. We will contact you at <?= $h($v('email')) ?> to set up your demo.</div></div>
    <?php else: ?>
    <h3>Request a demo</h3><p>Fields marked <span style="color:#8b1e2d">*</span> are required.</p>
    <form method="post" action="#demo" novalidate>
      <input type="hidden" name="do" value="demo"><input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="fg">
        <?php foreach (['name' => ['Your name', 'text', 'name', 'Rahul Rao'], 'company' => ['Company', 'text', 'organization', 'Your company name'], 'email' => ['Work email', 'email', 'email', 'name@company.com'], 'phone' => ['Mobile number', 'tel', 'tel', '98765 43210']] as $k => [$lab, $type, $ac, $ph]): ?>
        <div class="fi<?= isset($err[$k]) ? ' bad' : '' ?>"><label for="f-<?= $k ?>"><?= $lab ?> <em>*</em></label><input id="f-<?= $k ?>" name="<?= $k ?>" type="<?= $type ?>" autocomplete="<?= $ac ?>" placeholder="<?= $ph ?>" value="<?= $h($v($k)) ?>" required><?php if (isset($err[$k])): ?><span class="er"><?= $h($err[$k]) ?></span><?php endif; ?></div>
        <?php endforeach; ?>
        <div class="fi w"><label for="f-size">Number of employees</label><select id="f-size" name="size"><option value="">Choose…</option><?php foreach (['1-50', '51-200', '201-500', '501-2000', '2000+'] as $s): ?><option<?= $v('size') === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
        <div class="fi w"><label>Applications you are interested in</label><div class="chips"><?php foreach ($apps as [$n]): ?><label><input type="checkbox" name="apps[]" value="<?= $h($n) ?>"<?= in_array($n, (array) ($_POST['apps'] ?? []), true) ? ' checked' : '' ?>><?= $h($n) ?></label><?php endforeach; ?></div></div>
        <div class="fi w"><label for="f-msg">Anything we should know?</label><textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Your current tools, number of branches, timelines…"><?= $h($v('message')) ?></textarea></div>
      </div>
      <button class="btn gold" type="submit"><i class="fas fa-paper-plane"></i>Request my demo</button>
      <p class="small">We use these details only to contact you about AmetecsGlobal.</p>
    </form>
    <?php endif; ?>
  </div>
</div></section>
</main>

<footer><div class="wrap">
  <div class="fcols">
    <div><a class="logo" href="#top"><span class="lm">AG</span><span>Ametecs<b>Global</b></span></a><p>Every Ametecs application on one platform: one organisation, one login per employee, one place for licences and updates.</p></div>
    <div><h4>Product</h4><ul><li><a href="#tour">Product tour</a></li><li><a href="#features">Features</a></li><li><a href="#attendance">Attendance</a></li><li><a href="#pricing">Pricing</a></li><li><a href="#faq">FAQ</a></li></ul></div>
    <div><h4>Applications</h4><ul><?php foreach ($apps as [$n]): ?><li><a href="#apps"><?= $h($n) ?></a></li><?php endforeach; ?></ul></div>
    <div><h4>Company</h4><ul><li><a href="#about">About Ametecs</a></li><li><a href="#contact">Contact</a></li><li><a href="#demo">Request a demo</a></li><li><a href="core/">Client sign in</a></li></ul></div>
  </div>
  <div class="fbot"><span>&copy; <?= date('Y') ?> Ametecs India Private Limited. All rights reserved.</span><span>Kondapur, Hyderabad, Telangana, India</span></div>
</div></footer>

<a class="top" href="#top" aria-label="Back to top"><i class="fas fa-arrow-up"></i></a>
<script>
(function () {
  var b = document.querySelector('[data-burger]'), m = document.getElementById('mnav');
  b.addEventListener('click', function () { var o = m.classList.toggle('open'); b.setAttribute('aria-expanded', o); });
  m.addEventListener('click', function (e) { if (e.target.closest('a')) m.classList.remove('open'); });
  document.querySelectorAll('[data-tab]').forEach(function (t) { t.addEventListener('click', function () {
    document.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === t); });
    document.querySelectorAll('.pane').forEach(function (p) { p.classList.toggle('on', p.id === t.dataset.tab); });
  }); });
  document.querySelectorAll('[data-th]').forEach(function (b) { b.addEventListener('click', function () {
    document.querySelectorAll('[data-th]').forEach(function (x) { x.classList.toggle('on', x === b); });
    document.querySelectorAll('[data-tv]').forEach(function (im) { im.classList.toggle('on', im.dataset.tv === b.dataset.th); });
  }); });
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { threshold: .12 }) : null;
  document.querySelectorAll('.reveal').forEach(function (el) { io ? io.observe(el) : el.classList.add('in'); });
  var links = document.querySelectorAll('#mainnav a'), top = document.querySelector('.top');
  window.addEventListener('scroll', function () {
    top.classList.toggle('show', scrollY > 700);
    var cur = ''; links.forEach(function (a) { var s = document.querySelector(a.getAttribute('href')); if (s && s.getBoundingClientRect().top < 120) cur = a.getAttribute('href'); });
    links.forEach(function (a) { a.classList.toggle('on', a.getAttribute('href') === cur); });
  }, { passive: true });
})();
</script>
</body></html>
