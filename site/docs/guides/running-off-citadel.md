---
title: "Running off Citadel | HELMET docs"
description: "HELMET ships one package for every platform, so running off Citadel means changing configuration, not code."
url: https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Running off Citadel[#](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html#running-off-citadel)

HELMET ships one package for every platform, so running off Citadel means changing configuration, not code. Pick the row for your platform below, set `HELMET_PROXIES` or a preset, and point logs at `php://stderr`; for what a preset is, see [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html).

## The platforms[#](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html#the-platforms)

The only platform fact HELMET needs is the address of whatever stands in front of the app container:

| Platform | What sits in front | `HELMET_PROXIES` | Where logs go |
| --- | --- | --- | --- |
| Citadel | HA-Proxy | use the `citadel` preset (`172.18.0.1`) | `php://stderr` |
| OpenShift | the OpenShift router | the router's pod network CIDR, from your cluster admin | `php://stderr` |
| Coolify | Traefik or Caddy | the Docker network of the proxy | `php://stderr` |
| Raw Docker behind a compose proxy | your proxy service | the compose network's subnet | `php://stderr` |

A preset names trusted proxies only, never a log path, so on every platform the app points its log stream at `php://stderr` and the container runtime collects the lines.

## The citadel caveat[#](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html#the-citadel-caveat)

One caveat about the `citadel` row: `172.18.0.1` is the Docker bridge gateway, and the `citadel` preset is safe only when the front proxy reaches the app over that bridge. A published app port that is source-NATed to the gateway address would make a direct client look like the proxy, so the platform must not publish the app's port.

Warning

If the platform publishes the app's port, a direct client can appear to come from the gateway address and set `X-Forwarded-Proto`. Keep the app's port unpublished while you use the `citadel` preset.

## Overrides and the no-trust default[#](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html#overrides-and-the-no-trust-default)

An explicit `HELMET_PROXIES` overrides the preset on every platform. With neither a preset nor a list, no address is trusted, so forwarded headers such as `X-Forwarded-Proto` are ignored and the request is read as it arrived. Off Citadel, log to `php://stderr` and let the container runtime collect the lines.

The helmet comes off the platform, not the app.

## Related[#](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html#related)

- [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html)
- [WSU values (Playbook)](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html)
- [HTTPS, and why the proxy list matters](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
