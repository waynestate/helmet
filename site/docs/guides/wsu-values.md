---
title: "WSU values (Playbook) | HELMET docs"
description: "The Laravel config reads HELMET_PRESET from the environment first and from the private waynestate/playbook package (Playbook) second, when the environment has no value."
url: https://helmet.apps.wayne.edu/docs/guides/wsu-values.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# WSU values (Playbook) [#](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html#wsu-values-playbook)

This page covers the one value HELMET reads from Playbook, `HELMET_PRESET`, and the three ways to get Playbook itself with your own GitLab token. Once the preset is set, see [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html) for what it supplies.

## How the preset is read [#](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html#how-the-preset-is-read)

The Laravel config reads `HELMET_PRESET` from the environment first and from the private `waynestate/playbook` package (Playbook) second, when the environment has no value. The raw PHP path takes the preset as an option to `helmet_boot()` and reads no environment at all.

| Setting | What it is |
| --- | --- |
| `HELMET_PRESET` | A platform preset name (`citadel` or `docker`) that supplies the trusted proxies. |

## Get Playbook with your own GitLab token [#](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html#get-playbook-with-your-own-gitlab-token)

Three ways.

### Composer [#](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html#composer)

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

### Fetch the values file directly [#](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html#fetch-the-values-file-directly)

curl

```bash
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -o .env.playbook
```

powershell

```powershell
Invoke-WebRequest -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -OutFile .env.playbook
```

A fetched `.env.playbook` is plain `KEY=value` lines: copy the ones you need into your app's `.env` (or your Citadel environment).

### Clone [#](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html#clone)

```
git clone https://git.wayne.edu/enterprise-apps/playbook.git
```

Warning

The token is your own: `read_api` for curl, `read_repository` for clone and Composer. Keep it out of the repo; for Composer it lives in `auth.json`.

Tip

Playbook is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access.

## Related [#](https://helmet.apps.wayne.edu/docs/guides/wsu-values.html#related)

- [Platform presets](https://helmet.apps.wayne.edu/docs/guides/platform-presets.html)
- [Running off Citadel](https://helmet.apps.wayne.edu/docs/guides/running-off-citadel.html)
- [Environment variables](https://helmet.apps.wayne.edu/docs/reference/configuration.html)
