---
title: "Proof | HELMET docs"
description: "Proof runs against real Wayne State applications are on a private site, proof.apps.wayne.edu/helmet, behind a Wayne State employee sign-in."
url: https://helmet.apps.wayne.edu/docs/reference/proof.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof and tests[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#proof-and-tests)

How HELMET is proven against real Wayne State applications, and how to run its test suite on a machine with no PHP. Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://nougat.snu.gs/enterprise-apps/helmet/blob/HEAD/MAINTAINING.md).

## Proof[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#proof)

Proof runs against real Wayne State applications live on a private site, [proof.apps.wayne.edu/helmet](https://proof.apps.wayne.edu/helmet/), behind a Wayne State employee sign-in.

## Tests[#](https://helmet.apps.wayne.edu/docs/reference/proof.html#tests)

The header contract suite (`vendor/bin/phpunit tests/Contract`) runs one request through raw PHP, PSR-15, Laravel HTML and Laravel JSON and checks that the security headers agree on every path.

No PHP on the machine? Fine. Docker runs the suite.

```bash
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

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
