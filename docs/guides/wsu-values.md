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

<!-- playbook:wsu-values:begin -->
## Get Playbook with your own GitLab token

The repository is private, so every path below authenticates with a personal
access token of your own. Pick the one that fits:

### Composer

Point Composer at the instance in your `composer.json`:

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

Then require the package:

```sh
composer require waynestate/playbook
```

The credential goes in `auth.json` (a `gitlab-token` entry for git.wayne.edu),
never in the repository.

### Fetch the values file directly

```sh
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" \
    "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" \
    -o .env.playbook
```

```powershell
Invoke-WebRequest `
    -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } `
    -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" `
    -OutFile .env.playbook
```

A fetched `.env.playbook` is plain `KEY=value` lines to copy into the app's
`.env`.

### Clone

```sh
git clone https://git.wayne.edu/enterprise-apps/playbook.git
```

The token is your own: `read_api` for curl, `read_repository` for clone and
Composer; keep it out of the repo. Playbook is private, so on a 404 ask the
Enterprise Applications team for read access.
<!-- playbook:wsu-values:end -->

## Related

- [Platform presets](platform-presets.md)
- [Running off Citadel](running-off-citadel.md)
- [Environment variables](../reference/configuration.md)
