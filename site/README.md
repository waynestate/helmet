# HELMET front page

A sideline playbook: a chalkboard play in the hero, laminated play cards on turf, a stadium scoreboard for
the quick start, the film room, the coach's clipboard and the WELD depth chart. Static HTML, CSS and a
small vanilla script. No build step and no network calls, so it deploys as plain files on Citadel or
anywhere else.

- type: **Graduate** (SIL OFL 1.1, licence in `assets/fonts/OFL-graduate.txt`) for display, jersey
  lettering; **Permanent Marker** (Apache 2.0, `assets/fonts/LICENSE-permanent-marker.txt`) for the
  coach's notes; Lato from WayneUI for reading. Both are self-hosted in `assets/fonts/`.
- colours: WSU green `#0C5449` and gold `#FFCC33`, the mark's cream `#F4F1EA`, and berry `#8B2145` from the
  brand board, which marks the rushers. Slate and chalk are this page's own.
- built on WayneUI 2.0.8, copied into `assets/wayne-ui/` from its documented static path (tokens, Lato,
  container, buttons, skip link, focus ring). Other parts' marks are copies in `assets/marks/`; WayneUI uses
  its own mark on green, and HELMET's mark comes from `brand/` untouched.

## Files

| Path | What |
|---|---|
| `index.html` | The page |
| `assets/helmet.css` | Everything on top of WayneUI; motion lives at the end, under `.motion` only |
| `assets/helmet.js` | The hero play, play-card replays, scoreboard tabs, copy buttons, reveals |
| `brand/` | The mark, favicons and board (see `brand/README.md`) |
| `screenshots/` | Desktop, mobile, reduced motion, a motion GIF, and mobile crops per section |

## Run it locally

Any static server works:

```
php -S 127.0.0.1:8080 -t site
```

## The magic

The hero board runs the play on load: the helmet snaps into the pocket, five rushers (XSS, clickjacking,
forged https, session fixation, a false "down") chalk their routes at it, and five blockers (CSP, frame
headers, trusted proxies, session hardening, `/up`) step up and stop them. Zero sacks. Point at a call to
light one pair and read what blocks what; hit the eraser, or type `hike`, to run it again. Each play card
draws its own play when it scrolls in.

With `prefers-reduced-motion: reduce` the page arrives already drawn and nothing moves.

## Links between the stack sites

Links to the other parts are committed as production URLs (`https://<project>.apps.wayne.edu/`), so crawlers and
visitors without JavaScript always get production. `assets/stack-env.js` (the same file on every stack site) rewrites
them to `http://127.0.0.1:18432/<project>/site/` in development, for a static server whose root is the
folder that holds all the projects side by side. Dev is picked automatically on `localhost` or `127.0.0.1`; `?env=dev` or `?env=production` forces
one (remembered until `?env=auto`), `<meta name="stack-env">` sets the page default, and `?devBase=http://127.0.0.1:<port>`
points at a server on another port. WayneUI links stay on production.
