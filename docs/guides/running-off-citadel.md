---
title: Running off Citadel
description: HELMET ships one package for every platform, so running off Citadel means changing configuration, not code.
---

# Running off Citadel

HELMET ships one package for every platform, so running off Citadel means changing configuration, not code. Pick the row for your platform below, set `HELMET_PROXIES` or a preset, and point logs at `php://stderr`; for what a preset is, see [Platform presets](platform-presets.md).

## The platforms

The only platform fact HELMET needs is the address of whatever stands in front of the app container:

| Platform | What sits in front | `HELMET_PROXIES` | Where logs go |
| --- | --- | --- | --- |
| Citadel | HA-Proxy | use the `citadel` preset (`172.18.0.1`) | `php://stderr` |
| OpenShift | the OpenShift router | the router's pod network CIDR, from your cluster admin | `php://stderr` |
| Coolify | Traefik or Caddy | the Docker network of the proxy | `php://stderr` |
| Raw Docker behind a compose proxy | your proxy service | the compose network's subnet | `php://stderr` |

A preset names trusted proxies only, never a log path, so on every platform the app points its log stream at `php://stderr` and the container runtime collects the lines.

## The citadel caveat

One caveat about the `citadel` row: `172.18.0.1` is the Docker bridge gateway, and the `citadel` preset is safe only when the front proxy reaches the app over that bridge. A published app port that is source-NATed to the gateway address would make a direct client look like the proxy, so the platform must not publish the app's port.

> [!WARNING]
> If the platform publishes the app's port, a direct client can appear to come from the gateway address and set `X-Forwarded-Proto`. Keep the app's port unpublished while you use the `citadel` preset.

## Overrides and the no-trust default

An explicit `HELMET_PROXIES` overrides the preset on every platform. With neither a preset nor a list, no address is trusted, so forwarded headers such as `X-Forwarded-Proto` are ignored and the request is read as it arrived. Off Citadel, log to `php://stderr` and let the container runtime collect the lines.

The helmet comes off the platform, not the app.

## Related

- [Platform presets](platform-presets.md)
- [WSU values (Playbook)](wsu-values.md)
- [HTTPS, and why the proxy list matters](https-and-proxies.md)
- [Environment variables](../reference/configuration.md)
