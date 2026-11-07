---
title: "Proof | HELMET docs"
description: "Proof runs against real Wayne State applications are on a private site, proof.apps.wayne.edu/helmet, behind a Wayne State employee sign-in."
url: https://helmet.apps.wayne.edu/docs/reference/proof.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof and tests[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#proof-and-tests)

How HELMET is proven against real Wayne State applications, and how to run its test suite on a machine with no PHP. Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://github.com/waynestate/helmet/blob/HEAD/MAINTAINING.md).

## Last run[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#last-run)

| Check | Date | Commit | Result | Duration |
| --- | --- | --- | --- | --- |
| `composer ci` (validate, tests, Pint, PHPStan, agent-ready, docs, audit) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions; Pint 86 files; PHPStan 36 files clean; 0 vulnerabilities | 02:40 |
| Header contract suite (`tests/Contract`) | 2026-11-07 | 8a79b46 | pass: 3 tests, 196 assertions | 00:13 |
| Test suite on PHP 8.5 (`php:8.5-cli`) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions | 00:56 |
| Test suite on PHP 8.4 (`php:8.4-cli`) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions | 01:32 |
| Test suite on PHP 8.3 (`php:8.3-cli`) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions | 01:21 |

To refresh this table, run `composer ci` and the Docker commands below on the commit you want to record, then copy the summary lines into the rows. The proof runs against real Wayne State applications are not in the table: they live on the private site behind a Wayne State employee sign-in, so they cannot run on a developer machine.

## Proof[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#proof)

Proof runs against real Wayne State applications live on a private site, [proof.apps.wayne.edu/helmet](https://proof.apps.wayne.edu/helmet/), behind a Wayne State employee sign-in.

## Tests[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#tests)

The header contract suite (`vendor/bin/phpunit tests/Contract`) runs one request through raw PHP, PSR-15, Laravel HTML and Laravel JSON and checks that the security headers agree on every path.

No PHP on the machine? Fine. Docker runs the suite.

windows

```bash
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

linux

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "$PWD:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

Style and static analysis come from [Pinstripe](https://github.com/waynestate/pinstripe), the stack's coding standard: `phpstan.neon` includes `vendor/waynestate/pinstripe/phpstan/raw.neon`, and `pint.json` uses the same `per` preset Pinstripe ships.

## Related[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#related)

- [API](https://helmet.apps.wayne.edu/docs/reference/api.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
- [Changelog](https://helmet.apps.wayne.edu/docs/changelog.html)
