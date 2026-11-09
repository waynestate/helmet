---
title: "Secret scanning | HELMET docs"
description: "Keep committed secrets out of the repository with Gitleaks, in CI through Preflight's component and on your machine before you push."
url: https://helmet.apps.wayne.edu/docs/guides/secret-scanning.html
llms: https://helmet.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Secret scanning[#](https://helmet.apps.wayne.edu/docs/guides/secret-scanning.html#secret-scanning)

HELMET hardens what an app sends. It cannot help once a database password or an API key is in the repository: every clone, fork and CI cache has it, and deleting the line later does not take it out of history. So the stack scans for secrets with a maintained tool, [Gitleaks](https://github.com/gitleaks/gitleaks), instead of writing a scanner of its own. Gitleaks ships a few hundred rules for real token formats (cloud keys, Git hosting tokens, private keys, JWTs, database URLs with a password) and is kept current by people who do only that.

The pipeline runs it on every push, and a finding fails the pipeline. You can run the same scan before you push.

## In CI: Preflight's component[#](https://helmet.apps.wayne.edu/docs/guides/secret-scanning.html#in-ci-preflight-s-component)

Add one include to the app's `.gitlab-ci.yml`:

```yaml
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/secret-detection@0.4.0
    inputs:
      image: "zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f"
```

That is the whole job. It runs `gitleaks dir` over the checked-out tree with Gitleaks' built-in rules plus a path allowlist for `vendor/` and `node_modules/` (a CI cache can restore them, and they are not your code), redacts every match so the log and the `gitleaks.json` artifact show the rule, file and line but never the value, and fails the pipeline on a finding. The image is pinned by digest and comes through the GitLab Dependency Proxy (from Preflight 0.3.0 the component adds the proxy prefix itself, through its `image_prefix` input; on a GitLab without the proxy, set `image_prefix: docker.io`), so the Docker Hub rate limit never fails your pipeline; the digest is a multi-arch list, so it runs on an arm64 runner too.

HELMET's own pipeline runs exactly this include. Every input (stage, job name, your own `.gitleaks.toml`, `allow_failure`) is in [Preflight's secret-detection guide](https://git.wayne.edu/enterprise-apps/preflight/-/blob/main/docs/guides/component-secret-detection.md).

Note

The job scans the tree, not history: it answers "is a secret in what we are about to ship?". To check the history once, run the history scan below on your machine.

## On your machine[#](https://helmet.apps.wayne.edu/docs/guides/secret-scanning.html#on-your-machine)

The same image and the same rules, with Docker. Run from the project root.

Linux and macOS:

```sh
cat > /tmp/gitleaks.toml <<'TOML'
[extend]
useDefault = true

[allowlist]
paths = ['''(^|/)vendor/''', '''(^|/)node_modules/''']
TOML

docker run --rm -v "$PWD:/repo:ro" -v /tmp/gitleaks.toml:/gitleaks.toml:ro \
  zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f \
  dir --redact --no-banner --config=/gitleaks.toml /repo
```

Windows (PowerShell):

```powershell
$config = Join-Path $env:TEMP 'gitleaks.toml'
@'
[extend]
useDefault = true

[allowlist]
paths = ['''(^|/)vendor/''', '''(^|/)node_modules/''']
'@ | Set-Content -Path $config -Encoding ascii

docker run --rm -v "${PWD}:/repo:ro" -v "${config}:/gitleaks.toml:ro" `
  zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f `
  dir --redact --no-banner --config=/gitleaks.toml /repo
```

Exit code 0 is clean; 1 means a rule matched, and the output names the file and line. The mount is read-only, so the scan cannot change your tree.

To scan every commit instead of the tree, swap `dir` for `git` and let Git read the repository (the safe-directory line is needed because the mount belongs to another user inside the container):

```sh
docker run --rm -v "$PWD:/repo:ro" --entrypoint sh \
  zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f \
  -c 'git config --global --add safe.directory "*" && gitleaks git --redact --no-banner --log-opts="--all" /repo'
```

## When it finds something[#](https://helmet.apps.wayne.edu/docs/guides/secret-scanning.html#when-it-finds-something)

1. **Treat the secret as leaked.** Rotate it first, at the service that issued it. Removing the line does not un-leak it: the value is in history, in every clone and in CI caches.
2. Move the value out of the code, into the environment (`.env`, which stays out of Git, or the platform's secret store), and read it with `getenv()` or `env()` in config.
3. Only then decide whether history needs rewriting. That is a coordinated, destructive change for every clone; ask the repository owner.

A false positive (a test fixture, an example key in docs) is allowlisted in review, like code: add its fingerprint (`file:rule:line`, from the job log or the artifact) to a `.gitleaksignore` at the project root, or commit your own `.gitleaks.toml` and set the component's `config_path`. Prefer a fixture that does not look like a secret at all.

## What HELMET's own scan found[#](https://helmet.apps.wayne.edu/docs/guides/secret-scanning.html#what-helmet-s-own-scan-found)

The first run of this job's rules over HELMET's tree and its full history (359 commits) found nothing, so HELMET carries no `.gitleaksignore`. A planted fake cloud key in a scratch copy of the tree was caught (exit 1), so the job does fail when it should. Both runs are recorded on the [Proof](https://helmet.apps.wayne.edu/docs/reference/proof.html) page.

Helmet the band had a song called "Unsung". A committed secret should stay that way.
