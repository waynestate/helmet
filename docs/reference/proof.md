---
title: Proof
description: Proof runs against real Wayne State applications are on a private site, proof.apps.wayne.edu/helmet, behind a Wayne State employee sign-in.
---

# Proof and tests

How HELMET is proven against real Wayne State applications, and how to run its test suite on a machine with no PHP. Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://nougat.snu.gs/enterprise-apps/helmet/blob/HEAD/MAINTAINING.md).

## Last run

| Check | Date | Commit | Result | Duration |
| --- | --- | --- | --- | --- |
| `composer ci` (validate, tests, Pint, PHPStan, agent-ready, docs, audit) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions; Pint 86 files; PHPStan 36 files clean; 0 vulnerabilities | 02:40 |
| Header contract suite (`tests/Contract`) | 2026-11-07 | 8a79b46 | pass: 3 tests, 196 assertions | 00:13 |
| Test suite on PHP 8.5 (`php:8.5-cli`) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions | 00:56 |
| Test suite on PHP 8.4 (`php:8.4-cli`) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions | 01:32 |
| Test suite on PHP 8.3 (`php:8.3-cli`) | 2026-11-07 | 8a79b46 | pass: 585 tests, 1537 assertions | 01:21 |

To refresh this table, run `composer ci` and the Docker commands below on the commit you want to record, then copy the summary lines into the rows. The proof runs against real Wayne State applications are not in the table: they live on the private site behind a Wayne State employee sign-in, so they cannot run on a developer machine.

## Proof

Proof runs against real Wayne State applications live on a private site, [proof.apps.wayne.edu/helmet](https://proof.apps.wayne.edu/helmet/), behind a Wayne State employee sign-in.

## Tests

The header contract suite (`vendor/bin/phpunit tests/Contract`) runs one request through raw PHP, PSR-15, Laravel HTML and Laravel JSON and checks that the security headers agree on every path.

No PHP on the machine? Fine. Docker runs the suite.

```bash tab="windows"
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

```bash tab="linux"
docker run --rm -v "$PWD:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "$PWD:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

Style and static analysis come from [Pinstripe](https://github.com/waynestate/pinstripe), the stack's coding standard: `phpstan.neon` includes `vendor/waynestate/pinstripe/phpstan/raw.neon`, and `pint.json` uses the same `per` preset Pinstripe ships.

## Related

- [API](api.md)
- [Environment variables](configuration.md)
- [Changelog](../changelog.md)
