---
title: "Platform presets | HELMET docs"
description: "A preset names a platform's trusted-proxy list, so an app does not hard-code an address that belongs to the platform, not the app."
url: https://helmet.apps.wayne.edu/docs/guides/platform-presets.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Platform presets[#](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html#platform-presets)

A preset names a platform's trusted-proxy list, so an app does not hard-code an address that belongs to the platform, not the app. Name a preset (or list your own proxies) and the same package runs on Citadel, plain Docker, OpenShift or Coolify without a code change; when you are done choosing a preset, see [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html) for the per-platform table.

## The presets[#](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html#the-presets)

The presets live in `Waynestate\Helmet\Platform\Presets`:

| Preset | Trusted proxies |
| --- | --- |
| `citadel` | the Docker bridge gateway to the HA-Proxy front (see [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html)) |
| `docker` | none: a plain Docker network with no front proxy |

`citadel` is the WSU container platform: the app container talks to the HA-Proxy front through the Docker bridge gateway `172.18.0.1`, the only address allowed to set `X-Forwarded-Proto`. `docker` is a plain Docker deployment with no front proxy in front of the app container: nothing is trusted, and HTTPS is detected from the direct connection only.

## Passing a preset[#](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html#passing-a-preset)

Raw PHP passes the preset name:

```php
$helmet = helmet_boot([
    'preset' => 'citadel',
]);
```

Laravel reads it from `HELMET_PRESET` (Playbook supplies it when the environment has none); an explicit `HELMET_PROXIES` always wins over the preset.

Off the WSU platform, skip the preset and pass your own list of the addresses your front proxy connects from:

```php
$helmet = helmet_boot([
    'trusted_proxies' => ['203.0.113.10'],
]);
```

An unknown preset name throws at boot, so a typo fails loudly instead of silently trusting nothing.

Note

With neither a preset nor a list, no address is trusted, so forwarded headers such as `X-Forwarded-Proto` are ignored and the request is read as it arrived.

## The API[#](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html#the-api)

`Presets` exposes three static methods:

| Method | What it returns |
| --- | --- |
| `Presets::proxies(string $name)` | The trusted proxies for a preset, as a list of addresses. Throws `UnknownPresetException` if the preset is not known. |
| `Presets::exists(string $name)` | Whether a preset is known. |
| `Presets::names()` | The known preset names. |

## Which headers a trusted proxy may set[#](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html#which-headers-a-trusted-proxy-may-set)

The headers a trusted front proxy may set are the same for every preset: `X-Forwarded-For` and `X-Forwarded-Proto` (`Presets::TRUSTED_HEADERS`). Two more are deliberately left out:

- `X-Forwarded-Host` and `X-Forwarded-Prefix`: a platform that rewrites the host (for example OpenShift, which appends the route host) would let a client pre-seed those headers.
- `X-Forwarded-Port`: Citadel's HA-Proxy never sends it, so a client-sent value would pass through untouched and change the port the app reports.

## Related[#](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html#related)

- [WSU values (Playbook)](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html)
- [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html)
- [HTTPS, and why the proxy list matters](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
