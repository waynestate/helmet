# contract

The header contract as data: what security headers HELMET must send, stated
per consumer path instead of only in PHP code.

`tests/Contract/HeaderContractTest.php` proves that the four consumer paths
agree with each other. This folder states what they must agree on, one JSON
file per consumer path the test serves:

| File | Consumer path |
| --- | --- |
| `raw-php.json` | raw PHP: `helmet_boot()` in a `php -S` child, probed over real HTTP |
| `psr15.json` | the PSR-15 `SecurityHeadersMiddleware` with nyholm/psr7 |
| `laravel-blade.json` | the Laravel `web` group answering an HTML page |
| `laravel-inertia.json` | the Laravel `web` group answering JSON, the Inertia-style XHR way |

Each file holds the exact headers for both transports:

```json
{
    "path": "raw-php",
    "https": { "Header-Name": "exact value" },
    "http": { "Header-Name": "exact value" }
}
```

Plain HTTP never carries `Strict-Transport-Security`, so the `http` map omits
it; every other header is identical between the two maps.

## The nonce

Every response carries a fresh per-request CSP nonce, quoted as `'nonce-...'`
inside `script-src`, `style-src` and `style-src-elem`. A contract file cannot
name a random value, so the nonce is written as `{nonce}`:

```
script-src 'self' https://wayneui.apps.wayne.edu 'nonce-{nonce}' 'strict-dynamic'
```

`tests/Contract/VuePermutationContractTest.php` reads every file in this
folder, serves that path the same way `HeaderContractTest` does (over HTTPS
and over plain HTTP), replaces the served nonce with `{nonce}`, and asserts
that every header in the file is present with exactly that value. A path that
drifts from the contract fails the suite, and the expected value in the
failure message is the one to put back in the file.

The values come from `SecurityHeaders::build()` with the default policy, not
from guesses. When the defaults change, regenerate the files from the builder
and update this page if the shape changes. Every head gets the same helmet.
