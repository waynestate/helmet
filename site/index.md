---
title: "HELMET: Put it on before you ship."
description: "HELMET is the Wayne State app stack's baseline runtime: security headers and CSP, session hardening, trusted proxies, a /up health check, structured logs and version warnings, in one small PHP core."
url: https://helmet.apps.wayne.edu/
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Play 0 · every app, every snap

# HELMET

HELMET stands for Headers, Errors, Logging, Monitoring, Environment, Trust.

Put it on before you ship.

The stack's baseline runtime. One small, framework-free PHP core sends the security headers, hardens the session, checks the proxy, answers `/up` and writes structured logs, before your app serves a single page. Raw PHP, Laravel and PSR-15 all wear the same one.

```
composer require waynestate/helmet
```

[Open the playbook](https://helmet.apps.wayne.edu/#playbook) [Pregame: three steps to kickoff](https://helmet.apps.wayne.edu/#quick-start)

Five rushers, five blockers, zero sacks. Point at a call to see who blocks whom.

## The playbook

Six plays, each one a protection that stops a named rusher. Laminated, because coffee happens on the sideline.

1. ### Play 22: Nonce Dive
   CSP
   Blocks Injected script (XSS)
   A fresh nonce on every response, plus `strict-dynamic`. A script without the nonce does not run, and there is no `'unsafe-inline'` or `'unsafe-eval'` to sneak through.
   `script-src 'self' 'nonce-…' 'strict-dynamic'`
   New nonce every snap.
2. ### Play 34: Frame Screen
   FRAMES
   Blocks Clickjacking
   `frame-ancestors 'self'` in the policy, and `X-Frame-Options` for older browsers. A stranger's page cannot frame yours and trick a click.
   `X-Frame-Options: SAMEORIGIN`
   No strangers in the frame.
3. ### Play 47: Long Snap
   HSTS
   Blocks A downgrade to plain HTTP
   One year, every subdomain, sent only when the request really was HTTPS. Roll it out in stages; `preload` is the domain owner's call, so HELMET never adds it.
   `Strict-Transport-Security: max-age=31536000; includeSubDomains`
   Start short, then go long.
4. ### Play 41: Session Lock
   SESSION
   Blocks Session fixation and cookie theft
   Strict mode, cookies only, `HttpOnly` and `SameSite=Lax`, set before the session starts. `Secure` follows the real scheme, and it refuses to run once a session is already active.
   `session.use_strict_mode=1 cookie_httponly=1 cookie_samesite=Lax`
   Strapped before kickoff.
5. ### Play 58: Proxy Trap
   PROXIES
   Blocks A forged X-Forwarded-Proto
   Only a trusted proxy's address may say a request was https. A spoofed header from the internet is ignored, and no HSTS goes out on a request that was not really secure. Trust the proxy's address, never a whole private range.
   `helmet_boot(['preset' => 'citadel'])`
   The Unsung hero of the line.
6. ### Play 00: Victory Formation
   /UP
   Blocks A slow app called dead
   `GET` or `HEAD /up` answers `200 ok` with `Cache-Control: no-store` before your code runs, so the load balancer sees a live container even while the app is busy.
   `GET /up → 200 ok`
   Take a knee. Still up.

### Equipment check: every response, the full kit

One shared function builds these seven, so raw PHP and Laravel send the same headers, name and value. A test pins it down. `Cross-Origin-Resource-Policy` suits up only when you call for it.

- `Content-Security-Policy`the WayneUI default policy, a fresh nonce and `strict-dynamic`
- `X-Content-Type-Options``nosniff`
- `X-Frame-Options``SAMEORIGIN`
- `Referrer-Policy``strict-origin-when-cross-origin`
- `Permissions-Policy``camera=(), geolocation=(), microphone=(), payment=(), usb=()`
- `Cross-Origin-Opener-Policy``same-origin`, configurable
- `Strict-Transport-Security`one year with `includeSubDomains`, on HTTPS only

**Even on a fumble.** The middleware sends the headers when the inner handler throws, and `X-Powered-By` is stripped from every response. Milquetoast is a Helmet song. It is not a Content-Security-Policy.

## Pregame: three steps to kickoff

Pick your app's shape on the board. The same commands work in PowerShell, bash and zsh: Composer and Artisan don't care which shell snapped the ball.

Scoreboard: pregame, three steps to kickoff. HELMET 7, rushers 0: seven security headers sent, zero sacks allowed.

**Raw PHP**

### Raw PHP

1. Step 1: Strap it on
   One package, no framework needed.
   ```
   composer require waynestate/helmet
   ```
2. Step 2: Call it once
   At the top of the front controller, before anything is echoed. Headers, session, proxy check and `/up` happen right here.
   ```
   use function Waynestate\Helmet\helmet_boot;
   
   $helmet = helmet_boot(['preset' => 'citadel']);
   ```
3. Step 3: Nonce your scripts
   `$helmet['nonce']` is this request's nonce. The meta tag lets Vite's runtime use it too.
   ```
   echo helmet_csp_nonce_meta($helmet['nonce']); // in <head>
   
   <script nonce="<?= $helmet['nonce'] ?>">…</script>
   ```

**Laravel**

### Laravel

1. Step 1: Strap it on
   Package discovery registers the provider and both middlewares. Nothing to add to `bootstrap/providers.php`.
   ```
   composer require waynestate/helmet
   ```
2. Step 2: Publish the config
   The best place to change the CSP: a PHP array, not JSON squeezed into `.env`.
   ```
   php artisan vendor:publish --tag=helmet-config
   ```
3. Step 3: Pick the platform
   In `.env`. Keep `health: '/up'` in `withRouting()`; `@vite` already carries the nonce.
   ```
   HELMET_PRESET=citadel
   ```

**PSR-15**

### PSR-15

1. Step 1: Strap it on
   The same core, mounted in a middleware pipeline.
   ```
   composer require waynestate/helmet
   ```
2. Step 2: Line up the middleware
   `/up` first, then the headers. Headers go out even when the inner handler throws.
   ```
   $factory = new Psr17Factory();
   $proxies = new TrustedProxies(Presets::proxies('citadel'));
   
   $middleware = [
       new HealthCheck($factory, $factory, '/up'),
       new SecurityHeadersMiddleware($proxies),
   ];
   ```
3. Step 3: Read the nonce
   Downstream handlers get the same nonce the header carries.
   ```
   $request->getAttribute('helmet.csp_nonce')
   ```

**Off the WSU platform?** Skip the preset and list the one address your front proxy connects from, for example `'trusted_proxies' => ['203.0.113.10']`. Never a whole private range: a broad range also trusts the other containers on the network. An unknown preset name throws at boot, so a typo fails loudly instead of trusting nothing.

## The huddle

Five play calls for the coding agent you already use. Copy one, or run it straight in OpenCode, Claude Code, Codex or ChatGPT. Each says which formation the app is in, what the agent may not touch, and to ask the coach before it guesses.

### Get started with HELMET

Which formation the app is in, which platform sits in front of it, and the smallest plan to put the helmet on before the first page is served.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer add HELMET to the PHP app in this folder. HELMET is the baseline runtime of WELD, the Wayne State application stack: one small PHP core that sends the security headers and a nonce-based CSP, hardens the session, decides whether a request really arrived over HTTPS from a trusted proxy, answers the /up health check, and writes JSON logs. Raw PHP, Laravel and PSR-15 all mount the same core, so they send the same headers.

Read first:
- https://helmet.apps.wayne.edu/llms.txt, the index of HELMET's pages, each one as Markdown. Fetch the pages you need; do not work from memory.
- This app's composer.json and composer.lock, its front controller or bootstrap/app.php, and every inline script, inline style and third-party origin its pages load.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for helmet, and list_stack_rules for the runtime chapter. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt

Task:
1. Say which formation this app is: raw PHP, Laravel with Blade, Laravel with Inertia and Vue, or a PSR-15 pipeline.
2. Say where it runs: on Citadel (the citadel preset) or elsewhere, and if elsewhere, which single proxy address HELMET_PROXIES should trust.
3. List what the default CSP would block in this app today (inline scripts without a nonce, eval, outside origins), and how to fix each one.
4. Write the plan as numbered steps, starting with `composer require waynestate/helmet`. Change nothing yet.

Constraints:
- Trust only the address the front proxy connects from, never a whole private range such as 10.0.0.0/8.
- Never loosen the CSP to make a page work: no unsafe-eval, and no unsafe-inline for scripts. Nonce the script, or move it into a file.
- Playbook is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access. HELMET runs without it, from the environment alone.
- Never put a secret, a token or a key in a committed file.
- Ask me before you guess, and before you touch anything that deploys.

Output: the formation, the platform and its proxy setting, a table of what the CSP blocks today and the fix for each, the plan, then your questions.
```

### Or call your formation

### HELMET in a raw PHP app

One call at the top of the front controller, the nonce on every inline script, and a health check that still answers when there is no front controller.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding HELMET to a raw PHP app (no framework) at Wayne State University. HELMET is the baseline runtime of WELD, the Wayne State application stack.

Read first:
- https://helmet.apps.wayne.edu/llms.txt, then the README's Raw PHP section, The headers and The session.
- This app's composer.json, its front controller or entry scripts, every page template, and where it calls session_start().
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for helmet, and list_stack_rules with permutation raw-php. If it is not connected, say so and work from the pages.

Task:
1. Install with `composer require waynestate/helmet`.
2. Call helmet_boot() once at the very top of the front controller, before anything is echoed, with the citadel preset on Citadel (or trusted_proxies set to the one proxy address elsewhere) and a log stream of php://stderr off Citadel.
3. Put the nonce helmet_boot() returns on every inline script tag, and print helmet_csp_nonce_meta() in the head when the pages use Vite.
4. If some entry points never reach the front controller, add the two-line public/up.php that calls helmet_up(), as the README shows.
5. Show me each file you would change before you write it.

Constraints:
- Do not start the session before helmet_boot(); HELMET hardens it.
- HELMET renames a session still called PHPSESSID, so existing sessions end once on the first deploy. Say so in your output.
- Never loosen the CSP to make a page work: no unsafe-eval, and no unsafe-inline for scripts.
- Trust only the address the front proxy connects from, never a whole private range.
- Ask me before you guess, and before you touch anything that deploys.

Output: the changes as a diff per file, then how to check the headers locally (for example with curl -I), then your questions.
```

### HELMET in a Laravel app with Blade

The adapter discovers itself; publish the config, pick the platform, keep Laravel's own /up, and nonce the inline scripts in Blade.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding HELMET to a Laravel app with Blade views at Wayne State University. HELMET is the baseline runtime of WELD, the Wayne State application stack; its Laravel adapter only wires the core, so Laravel and raw PHP send the same headers.

Read first:
- https://helmet.apps.wayne.edu/llms.txt, then the README's Laravel section: Install the adapter, Publish the config, Trust the proxy, Environment variables and The nonce in Blade.
- This app's composer.json, bootstrap/app.php, config/session.php, its layouts and every Blade view with an inline script.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for helmet, and list_stack_rules with permutation laravel-blade. If it is not connected, say so and work from the pages.

Task:
1. Install with `composer require waynestate/helmet`. Package discovery registers the provider; add nothing to bootstrap/providers.php.
2. Publish the config with `php artisan vendor:publish --tag=helmet-config` and make any CSP change there, as a PHP array.
3. Set HELMET_PRESET=citadel on Citadel, or HELMET_PROXIES to the one proxy address elsewhere, in .env.example (never in .env).
4. Keep health: '/up' in withRouting(). Give every inline script nonce set to Vite::cspNonce(); @vite needs nothing.
5. Set secure, http_only and same_site in config/session.php: the adapter leaves the session to Laravel.
6. Show me each file you would change before you write it.

Constraints:
- Do not add your own trustProxies call unless I ask; HELMET copies its proxy list to Laravel when the app has none.
- Never loosen the CSP to make a page work: no unsafe-eval, and no unsafe-inline for scripts.
- The request-forgery guard is opt-in. Leave HELMET_CSRF_ENABLED off unless I ask.
- Ask me before you guess, and before you touch anything that deploys.

Output: the changes as a diff per file, the environment variables in a table, then your questions.
```

### HELMET in a Laravel app with Inertia and Vue

The Laravel adapter plus the coach's rules: scripts in the layout, never in Inertia's Head, one nonce from one package, no shared caching of nonced HTML.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding HELMET to a Laravel app that renders its pages with Inertia and Vue at Wayne State University. HELMET is the baseline runtime of WELD, the Wayne State application stack.

Read first:
- https://helmet.apps.wayne.edu/llms.txt, then the README's Laravel section, especially Vite and Inertia.
- This app's composer.json, package.json, vite.config, bootstrap/app.php, the root Blade layout, and every Vue page that uses Inertia's Head.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for helmet, and list_stack_rules with permutation laravel-inertia-vue. If it is not connected, say so and work from the pages.

Task:
1. Install with `composer require waynestate/helmet`, publish the config with `php artisan vendor:publish --tag=helmet-config`, and set HELMET_PRESET or HELMET_PROXIES as for any Laravel app.
2. Find every script that will break under the CSP: inline scripts without a nonce, scripts placed through Inertia's Head, anything that needs eval. Move each into the layout or into the bundle.
3. Check that no other package calls Vite::useCspNonce(); if one does, tell me which.
4. Check for any layer that caches HTML across requests, and tell me where it is.
5. Show me each file you would change before you write it.

Constraints:
- Never loosen the CSP to make a page work: no unsafe-eval, no unsafe-inline for scripts, and no unsafe-inline in style-src (a nonce makes the browser ignore it).
- The Vite dev server is allowed only in local, and only while it runs. Do not add its origin by hand anywhere else.
- Ask me before you guess, and before you touch anything that deploys.

Output: what breaks today and the fix for each, the changes as a diff per file, then your questions.
```

### HELMET in a PSR-15 middleware pipeline

The same core as middleware: the health check first, then the headers, and the nonce read from the request attribute downstream.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding HELMET to a PHP app built on a PSR-15 middleware pipeline at Wayne State University. HELMET is the baseline runtime of WELD, the Wayne State application stack.

Read first:
- https://helmet.apps.wayne.edu/llms.txt, then the README's PSR-15 middleware section and The headers.
- This app's composer.json, where it builds its middleware pipeline, and the handlers that render HTML.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for helmet, and list_stack_rules with permutation raw-php. If it is not connected, say so and work from the pages.

Task:
1. Install with `composer require waynestate/helmet`.
2. Build TrustedProxies from the citadel preset on Citadel (or from the one proxy address elsewhere), and add HealthCheck for /up, then SecurityHeadersMiddleware, to the pipeline as the README shows.
3. Read the nonce in handlers from the helmet.csp_nonce request attribute and put it on every inline script tag.
4. Show me each file you would change before you write it.

Constraints:
- Keep the default rethrow, so the framework's error handler still runs, unless I ask for the middleware's own 500.
- The request-forgery guard is opt-in. Do not mount RequestForgeryGuard unless I ask.
- Never loosen the CSP to make a page work: no unsafe-eval, and no unsafe-inline for scripts.
- Ask me before you guess, and before you touch anything that deploys.

Output: the changes as a diff per file, then how to check the headers locally, then your questions.
```

The prompts are part of the page: an agent that reads [this page as Markdown](https://helmet.apps.wayne.edu/index.md) or [llms.txt](https://helmet.apps.wayne.edu/llms.txt) gets every call without the buttons. Nobody runs the ball without a helmet.

## The film room

What HELMET writes down while the game is on, so you can watch the tape afterwards.

1. ### Logs, one line each
   ```
   {"message":"login failed",
    "context":{"user":"student42",
     "password":"***"},
    "level_name":"WARNING",
    "channel":"app"}
   ```
   Monolog JSON, one event per line. Passwords, tokens and session IDs come out as `***`, and if the log file can't be written it falls back to stderr instead of taking the request down.
2. ### Version warnings
   ```
   Laravel 11 is end of life and
   no longer receives security
   updates. Upgrade to a
   supported version.
   ```
   Read from CREED's `policy.json`. A version in its security-only phase or past end of life gets a plain warning; an active or unknown one gets none. The date is injected, so the boundaries are testable.
3. ### Metrics, off by default
   ```
   # HELP helmet_info HELMET is running.
   # TYPE helmet_info gauge
   helmet_info{php_version="8.4",
    helmet_version="0.2.4"} 1
   ```
   A Prometheus endpoint you turn on with `HELMET_METRICS_ENABLED`, behind a bearer token or an IP allow list. With neither set it answers `404`, so nobody hands their metrics to the whole network.
4. ### The ribbon
   In local, testing and staging, Laravel apps get `<div class="helmet-ribbon">` right after `<body>`, so nobody mistakes the scrimmage for the game. Never in production, never on JSON. No Aftertaste.

## Coach's rules for Vite and Inertia

The CSP stays honest only if the bundle plays along.

1. **Nonce every inline script.** There is no `'unsafe-inline'` for scripts; `@vite` tags already carry the nonce.
2. **No `'unsafe-eval'`.** If your bundle needs `eval`, fix the bundle.
3. **No scripts in Inertia's `<Head>`.** They render after load without the nonce, and the browser blocks them. Put scripts in the layout.
4. **One nonce per request, from one package.** A second `Vite::useCspNonce()` splits the header from the tags; HELMET logs a warning when it sees one.
5. **No shared caching of nonced HTML.** A cached page carries yesterday's nonce. Repetition is a fine Helmet song and a terrible nonce.

## Practice squad

On the backlog in HELMET's best practices, not in 0.2. They suit up when they're ready.

- A readiness check, separate from `/up`
- A `Clear-Site-Data` helper for logout
- A global error handler: detail in the log, a generic page for the user

Wayne State app stack · 0.1 season

## WELD depth chart

HELMET plays on the line for every app in the stack. Here's who lines up beside it.

### On the field

What runs inside every app

- [WayneUI](https://wayneui.apps.wayne.edu/) Renders every page: the design system, tokens and components.
- [SESAME](https://sesame.apps.wayne.edu/) Snaps the sign-in: CAS, the gateway, bearer tokens and Entra, behind one config switch.
- HELMET you are here Protects every snap: headers, session, proxies, /up, logs.
- [WELD](https://weld.apps.wayne.edu/) Every part already joined, in one Laravel + Inertia/Vue app.

### Coaches and officials

What keeps the code honest

- [CREED](https://creed.apps.wayne.edu/) The written standards, and the policy.json HELMET reads.
- [PROCTOR](https://proctor.apps.wayne.edu/) One command that checks an app against the standards.
- [Pinstripe](https://pinstripe.apps.wayne.edu/) The coding standard: Pint, PHPStan, ESLint and Prettier.
- [Preflight](https://preflight.apps.wayne.edu/) Shared CI from GitLab CI/CD components.
- [ADVISOR](https://advisor.apps.wayne.edu/) The voice in your headset: an MCP server that knows the stack.

### Sideline and front office

How apps start, ship and grow

- [Sourdough](https://sourdough.apps.wayne.edu/) Starters you shape yourself: raw PHP, Blade or Inertia/Vue.
- [Squire](https://squire.apps.wayne.edu/) The equipment manager: release script and local parity for Citadel.
- [Drawbridge](https://drawbridge.apps.wayne.edu/) next The Citadel sign-in gateway, lowered on request.
- [Booster](https://booster.apps.wayne.edu/) The booster club: upgrade recipes, PHP 7 to 8 and Laravel N to N+1.
- [Rollcall](https://rollcall.apps.wayne.edu/) Every app answers “present”, with its PHP version.
