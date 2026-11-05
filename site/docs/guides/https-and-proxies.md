---
title: "HTTPS, and why the proxy list matters | HELMET docs"
description: "HELMET honors X-Forwarded-Proto only when the connection came from a trusted proxy, so a spoofed header is ignored and no HSTS goes out for a request that was not really secure."
url: https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# HTTPS, and why the proxy list matters[#](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html#https-and-why-the-proxy-list-matters)

This page explains how HELMET decides whether a request really arrived over HTTPS, and why the trusted proxy list is the load-bearing part of that decision. When you understand it, pick your list on [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html) or [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html).

Citadel sits behind HA-Proxy, so the PHP process sees the proxy's address, not the client's, and the real scheme arrives in `X-Forwarded-Proto`. HELMET only honors that header when the connection came from a trusted proxy. A spoofed `X-Forwarded-Proto: https` from the public internet is ignored, and no HSTS header is sent for a request that was not really secure.

The proxy check is the Unsung hero of the headers. Nobody notices it until a forged `X-Forwarded-Proto` walks in and gets nowhere.

## How the scheme is decided[#](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html#how-the-scheme-is-decided)

`HttpsDetector` makes the decision from the raw `$_SERVER` array, so it works identically in the framework-free boot path and in any adapter:

1. If the SAPI reports TLS (the `HTTPS` key is set and not `off`), the request is HTTPS. This is the only signal trusted unconditionally.
2. Otherwise, the request is HTTPS only when the peer (`REMOTE_ADDR`) is a trusted proxy and the forwarded `X-Forwarded-Proto` is `https`.

A comma-separated `X-Forwarded-Proto` list keeps one rule everywhere: the last non-empty value wins, because it is the one the closest proxy appended. Values are trimmed and lower-cased, and empty tokens are ignored, so a trailing comma cannot hide the real value.

## What the proxy list accepts[#](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html#what-the-proxy-list-accepts)

`TrustedProxies` takes exact IPv4 or IPv6 addresses and IPv4 or IPv6 CIDR ranges. Every entry is validated at construction time, so bad config fails loudly at boot instead of looking like working config while trusting nothing, or the wrong thing:

- An entry that is not a valid address or CIDR throws.
- A `/0` prefix (which would trust every address of its family) is refused.
- The wildcard `*` is refused.

The matching itself is delegated to Symfony's `IpUtils`, maintained security code; the class only validates the config. An IPv4-mapped IPv6 address (`::ffff:a.b.c.d`) is unmapped to `a.b.c.d` before matching, so a dual-stack peer presenting its IPv4 address in mapped form still matches an IPv4 entry in the list. An empty list trusts nothing.

## What happens with no list[#](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html#what-happens-with-no-list)

With neither a preset nor a list, no address is trusted, so forwarded headers such as `X-Forwarded-Proto` are ignored and the request is read as it arrived. An explicitly empty `HELMET_PROXIES=` means trust nobody, even when a preset is set; only an unset variable falls back to the preset. An unknown preset name throws at boot, so a typo fails loudly instead of silently trusting nothing.

Warning

List only the address the front proxy connects from, never a whole private range. A broad range such as `10.0.0.0/8` also trusts other containers on the same network, and any of them could then set `X-Forwarded-Proto`.

When the list does hold a broad entry, an IPv4 range shorter than `/16` or an IPv6 range shorter than `/48`, HELMET writes one `helmet.broad_trusted_proxy` warning at boot so the range is hard to miss in the logs. Set `HELMET_WARN_BROAD_PROXIES=false` (or pass `warn_broad_proxies: false` to `helmet_boot()`) once you have reviewed the range and accepted it.

## Resolving the client IP[#](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html#resolving-the-client-ip)

The same trust list drives `ClientIp::resolve()`. When the peer is a trusted proxy, the client IP is read from `X-Forwarded-For` right to left (closest proxy first), and the walk stops at the first untrusted hop, so a client-chosen entry behind that hop can never be returned. Two failure rules keep it honest:

- **Fails closed.** An unparseable entry (for example `203.0.113.5:443`) ends the walk: the peer is returned instead of walking on to an earlier, client-chosen entry.
- **Reports without quoting the attacker.** Each rejection logs one `helmet.forwarded_for_rejected` warning with `event`, `reason` (`unparseable_entry`) and `peer` in the context. The `X-Forwarded-For` header and the bad entry are never logged.

IPv4-mapped IPv6 addresses are unmapped for both the peer and the forwarded entries, so a dual-stack proxy still matches its IPv4 entry instead of slipping past the trust check. The metrics endpoint uses the same list through `IpAllowList`, which applies the same validation rules to `HELMET_METRICS_ALLOW`.

## Where to set it[#](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html#where-to-set-it)

Raw PHP passes the list as `trusted_proxies` (or a `preset` name) to `helmet_boot()`; Laravel reads `HELMET_PROXIES` (comma-separated), which wins over `HELMET_PRESET`. Per-platform values live in [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html), and the presets themselves in [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html).

## Related[#](https://helmet.apps.wayne.edu/docs/guides/https-and-proxies.html#related)

- [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html)
- [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html)
- [Configuring HSTS](https://helmet.apps.wayne.edu/docs/guides/hsts.html)
- [The session](https://helmet.apps.wayne.edu/docs/guides/session.html)
- [Metrics](https://helmet.apps.wayne.edu/docs/guides/metrics.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
