---
title: "Use HELMET from your coding agent (ADVISOR) | HELMET docs"
description: "Install the ADVISOR module so a coding agent can ask HELMET which headers it sends and why, and how long the app's PHP floor stays supported, with the two read-only tools helmet_headers and helmet_php_support."
url: https://helmet.apps.wayne.edu/docs/guides/advisor.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Use HELMET from your coding agent (ADVISOR) [#](https://helmet.apps.wayne.edu/docs/guides/advisor.html#use-helmet-from-your-coding-agent-advisor)

This page is for developers whose coding agent runs [ADVISOR](https://github.com/waynestate/advisor), the stack's read-only MCP server. HELMET ships an ADVISOR module, so the agent can ask HELMET two questions without leaving its sandbox: "which headers does HELMET send, and why?" and "how long is this app's PHP floor supported?" Both answers come from HELMET's own code. Next, see [The headers](https://helmet.apps.wayne.edu/docs/guides/headers.html) for the same ground in prose and [Customizing the CSP](https://helmet.apps.wayne.edu/docs/guides/csp.html) for changing it.

## Install ADVISOR [#](https://helmet.apps.wayne.edu/docs/guides/advisor.html#install-advisor)

In the app, install ADVISOR as a dev dependency:

```bash
composer require --dev waynestate/advisor
```

Then add one line to the app's `modules.json` (or point `ADVISOR_MODULES_FILE` at a file that holds it):

```json
{
    "modules": [
        "Waynestate\\Helmet\\Advisor\\HelmetModule"
    ]
}
```

HELMET does not require ADVISOR, and ADVISOR does not require HELMET: the module only loads when both are installed and `modules.json` names it.

## The two read-only tools [#](https://helmet.apps.wayne.edu/docs/guides/advisor.html#the-two-read-only-tools)

Both tools take exactly one argument, `path`: the app folder, inside the project root (default: the root itself). Both run in-process: no subprocess, no network, nothing written. Neither reads `.env`, the environment or any secret.

### helmet_headers [#](https://helmet.apps.wayne.edu/docs/guides/advisor.html#helmet-headers)

`helmet_headers` builds the headers HELMET sends with its defaults, through the same `SecurityHeaders::build()` every consumer path uses, and returns:

- `headers`: each header with its exact value, when it is sent (Strict-Transport-Security goes on HTTPS responses only) and the reason it is there.
- `csp.directives`: each Content-Security-Policy directive with its sources and its reason. The per-request nonce shows as `PerRequestNonce`, because a real one only exists inside a request.
- `removed` and `optional`: `X-Powered-By`, which `helmet_boot()` strips, and the headers that are off until you turn them on (Cross-Origin-Resource-Policy, Reporting-Endpoints, `Cache-Control: no-store`, report-only mode).
- `wiring`: what the app folder says about HELMET. The version `composer.lock` pins, whether it is a Laravel app, whether `config/helmet.php` is published and still matches the copy HELMET ships, and which front controller calls `helmet_boot()`. Each note names the file it came from.
- `settings`: every `HELMET_` variable (and the matching `helmet_boot()` key) that changes the answer. The tool lists them; it never reads their values.

One directive, as it comes back:

```json
{
    "directive": "base-uri",
    "sources": ["'self'"],
    "reason": "Stops an injected <base> tag from re-pointing every relative URL on the page; default-src does not cover it."
}
```

The reasons live in `src/Headers/HeaderReasons.php`, next to the policy they explain, and a test fails when a header or directive is added without one.

### helmet_php_support [#](https://helmet.apps.wayne.edu/docs/guides/advisor.html#helmet-php-support)

`helmet_php_support` reads `require.php` and `config.platform.php` from the app's `composer.json`, takes the lowest PHP version the constraint allows (the floor), and looks it up in the CREED `policy.json` that ADVISOR bundles. It reads the policy with HELMET's own `JsonFilePolicySource` and `VersionStatus`, the code behind the environment ribbon and the PHP support metrics, so all three agree. It returns:

- `floor`: the version, its status (`active`, `security`, `eol` or `unknown`), its active and security dates, the days of security support left, and the ribbon's own warning text.
- `platform_pin`: the same for `config.platform.php`, when the app sets one.
- `allowed`: every PHP version in the policy that the constraint allows, each with its status.
- `summary`: one sentence to repeat to a person, for example "The floor, PHP 8.3 (from ^8.3), is security-only: fixes stop on 2027-12-31 (419 days)."
- `source`: the policy file and the date CREED refreshed it, the date ADVISOR bundled it, and the date the answer is for.

A missing constraint, one with no lower bound (`*`) and one Composer cannot parse are each explained, never guessed. A folder with no `composer.json` is refused.

Note

HELMET reports; PROCTOR decides. Every answer names its source and says plainly what ran, and PROCTOR's check against the same CREED policy is what passes or fails a build. A helmet tells you where the hits will land; it does not referee the game.
