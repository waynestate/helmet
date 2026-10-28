# HELMET README art kit

Cuts the README images under `docs/readme/` from this repo's own front page
(`site/`), brand files and source. Nothing is drawn freehand: the hero and the
gallery are screenshots of the real page, the cast is the marks resized, the
badges read their facts from `composer.json`, and the play is drawn from the
headers HELMET actually sends.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL, aborts any
request that tries to leave the machine, and is deterministic: same inputs,
same outputs.

## What it makes

- `docs/readme/hero.jpg`: the hero, with the site header hidden, at 1280 px.
- `docs/readme/play.svg`: the chalkboard play. Seven O's on the line, one
  route per default header, written in the order the code sets them. Every
  name and value is parsed out of `src/Headers/SecurityHeaders.php` and
  checked against `config/helmet.php`; if the two disagree, or the parse
  finds nothing, the build fails. `Cross-Origin-Resource-Policy` sits on the
  bench because it is opt-in. Text is measured in the real fonts (Graduate and
  Permanent Marker, embedded), so the layout fits the chalk. Under
  `prefers-reduced-motion` it is the finished play, standing still.
- `docs/readme/gallery.jpg`: three phone crops from `site/screenshots/sections/`.
- `docs/readme/board.jpg`: the brand board, re-encoded at 1400 px.
- `docs/readme/cast/*.png`: one 96×96 mark per stack part, WayneUI on its
  green tile first.
- `docs/readme/badges/*.svg`: `php`, `licence` and `psr-15`, made locally by
  `badge-maker`.

Every file must stay at or under 300 KB, and no captured text may name an
internal host; the script prints each size and exits 1 if either rule breaks.

If the board ever shows the wrong play, change the playbook (the source), not
the chalk. No PHP was run to draw it: the source is read, not executed.
