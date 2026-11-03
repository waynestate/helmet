---
title: WSU values (Playbook)
description: The Laravel config reads HELMET_PRESET from the environment first and from the private waynestate/playbook package (Playbook) second, when the environment has no value.
---

# WSU values (Playbook)

This page covers the one value HELMET reads from Playbook, `HELMET_PRESET`, and the three ways to get Playbook itself with your own GitLab token. Once the preset is set, see [Platform presets](platform-presets.md) for what it supplies.

## How the preset is read

The Laravel config reads `HELMET_PRESET` from the environment first and from the private `waynestate/playbook` package (Playbook) second, when the environment has no value. The raw PHP path takes the preset as an option to `helmet_boot()` and reads no environment at all.

| Setting | What it is |
| --- | --- |
| `HELMET_PRESET` | A platform preset name (`citadel` or `docker`) that supplies the trusted proxies. |

## Get Playbook with your own GitLab token

Three ways.

### Composer

Add the repository to your app's `composer.json`, put the token in `auth.json` (Composer's credential store, never in the repo), then require the package:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://git.wayne.edu/enterprise-apps/playbook.git"
        }
    ]
}
```

```
composer require waynestate/playbook
```

### Fetch the values file directly

```bash tab="curl"
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -o .env.playbook
```

```powershell tab="powershell"
Invoke-WebRequest -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -OutFile .env.playbook
```

A fetched `.env.playbook` is plain `KEY=value` lines: copy the ones you need into your app's `.env` (or your Citadel environment).

### Clone

```
git clone https://git.wayne.edu/enterprise-apps/playbook.git
```

> [!WARNING]
> The token is your own: `read_api` for curl, `read_repository` for clone and Composer. Keep it out of the repo; for Composer it lives in `auth.json`.

> [!TIP]
> Playbook is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access.

## Related

- [Platform presets](platform-presets.md)
- [Running off Citadel](running-off-citadel.md)
- [Environment variables](../reference/configuration.md)
